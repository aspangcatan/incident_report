<?php

namespace Tests\Feature;

use App\Enums\IncidentStatus;
use App\Enums\Role;
use App\Enums\Severity;
use App\Models\Department;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\User;
use App\Notifications\IncidentEscalationNotification;
use App\Services\IncidentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class EscalationCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_escalates_a_for_review_incident_past_its_review_sla(): void
    {
        Notification::fake();

        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $reporter = User::factory()->create();
        $department = Department::factory()->create();
        $incidentType = IncidentType::factory()->create();

        $incident = app(IncidentService::class)->createDraft($reporter, [
            'department_id' => $department->id,
            'incident_type_id' => $incidentType->id,
            'severity' => Severity::Level4CriticalSentinel->value,
            'occurred_at' => now(),
            'location' => 'ICU',
            'summary' => 'Overdue test incident.',
        ]);
        app(IncidentService::class)->submit($incident);
        app(IncidentService::class)->completeAssessment($incident->fresh(), $qso);

        // Level 4 review SLA is 24 hours (config/incident_workflow.php) - backdate assessed_at past it.
        $incident->forceFill(['assessed_at' => now()->subHours(30)])->save();

        // Re-arm the fake: submit()/completeAssessment() above dispatch events whose
        // listeners notify reviewers synchronously. Assertions below must only see
        // notifications from the command itself.
        Notification::fake();

        $this->artisan('incidents:check-overdue')->assertExitCode(0);

        Notification::assertSentTo($qso, IncidentEscalationNotification::class);
        $this->assertNotNull($incident->fresh()->review_escalated_at);
    }

    public function test_it_does_not_re_escalate_an_already_escalated_incident(): void
    {
        Notification::fake();

        User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $reporter = User::factory()->create();
        $department = Department::factory()->create();
        $incidentType = IncidentType::factory()->create();

        $incident = app(IncidentService::class)->createDraft($reporter, [
            'department_id' => $department->id,
            'incident_type_id' => $incidentType->id,
            'severity' => Severity::Level1Low->value,
            'occurred_at' => now(),
            'location' => 'ER',
            'summary' => 'Already escalated.',
        ]);
        app(IncidentService::class)->submit($incident);
        app(IncidentService::class)->completeAssessment($incident->fresh(), User::factory()->create(['role' => Role::QualitySafetyOfficer]));
        $incident->fresh()->forceFill(['assessed_at' => now()->subDays(10), 'review_escalated_at' => now()->subDay()])->save();

        // Re-arm the fake: submit()/completeAssessment() above dispatch events whose
        // listeners notify reviewers synchronously. Assertions below must only see
        // notifications from the command itself.
        Notification::fake();

        $this->artisan('incidents:check-overdue')->assertExitCode(0);

        Notification::assertNothingSent();
    }

    public function test_it_does_not_escalate_an_incident_still_within_its_sla(): void
    {
        Notification::fake();

        User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $reporter = User::factory()->create();
        $department = Department::factory()->create();
        $incidentType = IncidentType::factory()->create();

        $incident = app(IncidentService::class)->createDraft($reporter, [
            'department_id' => $department->id,
            'incident_type_id' => $incidentType->id,
            'severity' => Severity::Level1Low->value,
            'occurred_at' => now(),
            'location' => 'ER',
            'summary' => 'Within SLA.',
        ]);
        app(IncidentService::class)->submit($incident);
        app(IncidentService::class)->completeAssessment($incident->fresh(), User::factory()->create(['role' => Role::QualitySafetyOfficer]));

        // Re-arm the fake: submit()/completeAssessment() above dispatch events whose
        // listeners notify reviewers synchronously. Assertions below must only see
        // notifications from the command itself.
        Notification::fake();

        $this->artisan('incidents:check-overdue')->assertExitCode(0);

        Notification::assertNothingSent();
        $this->assertNull($incident->fresh()->review_escalated_at);
    }

    public function test_it_escalates_an_assigned_incident_past_its_target_closure_date(): void
    {
        Notification::fake();

        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $incident = $this->assignedIncident();
        $incident->forceFill(['target_closure_date' => now()->subDay()])->save();

        Notification::fake();

        $this->artisan('incidents:check-overdue')->assertExitCode(0);

        Notification::assertSentTo($qso, IncidentEscalationNotification::class);
        $this->assertNotNull($incident->fresh()->assignment_escalated_at);
    }

    public function test_it_does_not_escalate_an_assigned_incident_with_a_future_target_closure_date(): void
    {
        Notification::fake();

        User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $incident = $this->assignedIncident();
        $incident->forceFill(['target_closure_date' => now()->addDays(30)])->save();

        Notification::fake();

        $this->artisan('incidents:check-overdue')->assertExitCode(0);

        Notification::assertNothingSent();
        $this->assertNull($incident->fresh()->assignment_escalated_at);
    }

    public function test_it_does_not_re_escalate_an_already_assignment_escalated_incident(): void
    {
        Notification::fake();

        User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $incident = $this->assignedIncident();
        $incident->forceFill([
            'target_closure_date' => now()->subDay(),
            'assignment_escalated_at' => now()->subHours(2),
        ])->save();

        Notification::fake();

        $this->artisan('incidents:check-overdue')->assertExitCode(0);

        Notification::assertNothingSent();
    }

    public function test_a_review_stage_escalation_does_not_block_a_later_independent_assignment_stage_escalation(): void
    {
        Notification::fake();

        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $reporter = User::factory()->create();
        $reviewer = User::factory()->create(['role' => Role::Supervisor]);
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $department = Department::factory()->create();
        $incidentType = IncidentType::factory()->create();

        $incident = app(IncidentService::class)->createDraft($reporter, [
            'department_id' => $department->id,
            'incident_type_id' => $incidentType->id,
            'severity' => Severity::Level4CriticalSentinel->value,
            'occurred_at' => now(),
            'location' => 'ICU',
            'summary' => 'Escalated at review, then again at assignment.',
        ]);
        app(IncidentService::class)->submit($incident);
        app(IncidentService::class)->completeAssessment($incident->fresh(), $qso);

        // Breach the review SLA (24 hours for Level 4) and escalate it once.
        $incident->fresh()->forceFill(['assessed_at' => now()->subHours(30)])->save();
        Notification::fake();
        $this->artisan('incidents:check-overdue')->assertExitCode(0);
        $this->assertNotNull($incident->fresh()->review_escalated_at);

        // Move the incident through review and assignment, then breach the
        // (independent) assignment SLA too.
        app(IncidentService::class)->markReviewed($incident->fresh(), $reviewer, null);
        app(IncidentService::class)->assignInvestigator($incident->fresh(), $investigator);
        $incident->fresh()->forceFill(['target_closure_date' => now()->subDay()])->save();

        // Before the fix, whereNull('escalated_at') would have skipped this
        // incident forever because the review-stage escalation already set the
        // shared flag. With independent columns, the assignment sweep must
        // still pick it up and notify again.
        Notification::fake();
        $this->artisan('incidents:check-overdue')->assertExitCode(0);

        Notification::assertSentTo($qso, IncidentEscalationNotification::class);
        $fresh = $incident->fresh();
        $this->assertNotNull($fresh->review_escalated_at);
        $this->assertNotNull($fresh->assignment_escalated_at);
    }

    public function test_reassessing_a_returned_incident_resets_review_escalation_and_allows_re_escalation(): void
    {
        Notification::fake();

        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $reporter = User::factory()->create();
        $reviewer = User::factory()->create(['role' => Role::Supervisor]);
        $department = Department::factory()->create();
        $incidentType = IncidentType::factory()->create();

        $incident = app(IncidentService::class)->createDraft($reporter, [
            'department_id' => $department->id,
            'incident_type_id' => $incidentType->id,
            'severity' => Severity::Level4CriticalSentinel->value,
            'occurred_at' => now(),
            'location' => 'ICU',
            'summary' => 'Escalated, returned, reassessed, escalated again.',
        ]);
        app(IncidentService::class)->submit($incident);
        app(IncidentService::class)->completeAssessment($incident->fresh(), $qso);

        // First SLA cycle: breach and escalate.
        $incident->fresh()->forceFill(['assessed_at' => now()->subHours(30)])->save();
        Notification::fake();
        $this->artisan('incidents:check-overdue')->assertExitCode(0);
        $this->assertNotNull($incident->fresh()->review_escalated_at);

        // Sent back to the department for another look, then reassessed -- a fresh
        // review-SLA cycle starts, so the previous escalation flag must not carry over.
        app(IncidentService::class)->returnToDepartment($incident->fresh(), $reviewer, 'Please double-check the severity.');
        app(IncidentService::class)->completeAssessment($incident->fresh(), $qso);
        $this->assertNull($incident->fresh()->review_escalated_at);

        // Breach the new SLA cycle too.
        $incident->fresh()->forceFill(['assessed_at' => now()->subHours(30)])->save();

        // Before the fix, escalated_at was never cleared on reassessment, so
        // whereNull('escalated_at') would have skipped this incident forever.
        Notification::fake();
        $this->artisan('incidents:check-overdue')->assertExitCode(0);

        Notification::assertSentTo($qso, IncidentEscalationNotification::class);
        $this->assertNotNull($incident->fresh()->review_escalated_at);
    }

    public function test_an_overdue_department_assessment_is_escalated_once(): void
    {
        Notification::fake();

        $recipient = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $reporter = User::factory()->create();
        $department = Department::factory()->create();
        $incidentType = IncidentType::factory()->create();

        $incident = app(IncidentService::class)->createDraft($reporter, [
            'department_id' => $department->id,
            'incident_type_id' => $incidentType->id,
            'occurred_at' => now(),
            'location' => 'ER',
            'summary' => 'Overdue department assessment test incident.',
        ]);
        app(IncidentService::class)->submit($incident);

        // Assessment SLA is 72 hours (config/incident_workflow.php) - backdate reported_at past it.
        $incident->forceFill(['reported_at' => now()->subHours(73)])->save();

        // Re-arm the fake: submit() above dispatches IncidentSubmitted, which
        // NotifyReviewersOfSubmittedIncident already sent a notification for
        // synchronously. Assertions below must only see notifications from the command itself.
        Notification::fake();

        $this->artisan('incidents:check-overdue')->assertExitCode(0);

        Notification::assertSentTo($recipient, IncidentEscalationNotification::class);
        $this->assertNotNull($incident->fresh()->assessment_escalated_at);

        // Running again must not re-escalate the same breach.
        Notification::fake();
        $this->artisan('incidents:check-overdue')->assertExitCode(0);
        Notification::assertNothingSent();
    }

    public function test_the_review_sla_counts_from_assessment_completion(): void
    {
        Notification::fake();

        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $reporter = User::factory()->create();
        $department = Department::factory()->create();
        $incidentType = IncidentType::factory()->create();

        $incident = app(IncidentService::class)->createDraft($reporter, [
            'department_id' => $department->id,
            'incident_type_id' => $incidentType->id,
            'severity' => Severity::Level4CriticalSentinel->value, // review SLA 24h
            'occurred_at' => now(),
            'location' => 'ER',
            'summary' => 'Review SLA counted from assessment completion.',
        ]);
        app(IncidentService::class)->submit($incident);
        app(IncidentService::class)->completeAssessment($incident->fresh(), User::factory()->create(['role' => Role::QualitySafetyOfficer]));
        $incident->fresh()->forceFill(['reported_at' => now()->subDays(10), 'assessed_at' => now()->subHours(2)])->save();

        // Re-arm the fake: submit()/completeAssessment() above dispatch events whose
        // listeners notify reviewers synchronously. Assertions below must only see
        // notifications from the command itself.
        Notification::fake();

        $this->artisan('incidents:check-overdue')->assertExitCode(0);
        Notification::assertNothingSent();
        $this->assertNull($incident->fresh()->review_escalated_at);

        $incident->fresh()->forceFill(['assessed_at' => now()->subHours(25)])->save();

        Notification::fake();
        $this->artisan('incidents:check-overdue')->assertExitCode(0);

        Notification::assertSentTo($qso, IncidentEscalationNotification::class);
        $this->assertNotNull($incident->fresh()->review_escalated_at);
    }

    private function assignedIncident(): Incident
    {
        $reporter = User::factory()->create();
        $reviewer = User::factory()->create(['role' => Role::Supervisor]);
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $department = Department::factory()->create();
        $incidentType = IncidentType::factory()->create();

        $incident = app(IncidentService::class)->createDraft($reporter, [
            'department_id' => $department->id,
            'incident_type_id' => $incidentType->id,
            'severity' => Severity::Level1Low->value,
            'occurred_at' => now(),
            'location' => 'ER',
            'summary' => 'Assigned incident for escalation testing.',
        ]);
        app(IncidentService::class)->submit($incident);
        app(IncidentService::class)->markReviewed($incident->fresh(), $reviewer, null);
        app(IncidentService::class)->assignInvestigator($incident->fresh(), $investigator);

        return $incident->fresh();
    }
}
