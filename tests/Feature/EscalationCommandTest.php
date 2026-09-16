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

    public function test_it_escalates_a_submitted_incident_past_its_review_sla(): void
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

        // Level 4 review SLA is 24 hours (config/incident_workflow.php) - backdate reported_at past it.
        $incident->forceFill(['reported_at' => now()->subHours(30)])->save();

        // Re-arm the fake: submit() above dispatches IncidentSubmitted, which
        // NotifyReviewersOfSubmittedIncident (Task 7) already sent a notification for
        // synchronously. Assertions below must only see notifications from the command itself.
        Notification::fake();

        $this->artisan('incidents:check-overdue')->assertExitCode(0);

        Notification::assertSentTo($qso, IncidentEscalationNotification::class);
        $this->assertNotNull($incident->fresh()->escalated_at);
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
        $incident->forceFill(['reported_at' => now()->subDays(10), 'escalated_at' => now()->subDay()])->save();

        // Re-arm the fake: submit() above dispatches IncidentSubmitted, which
        // NotifyReviewersOfSubmittedIncident (Task 7) already sent a notification for
        // synchronously. Assertions below must only see notifications from the command itself.
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

        // Re-arm the fake: submit() above dispatches IncidentSubmitted, which
        // NotifyReviewersOfSubmittedIncident (Task 7) already sent a notification for
        // synchronously. Assertions below must only see notifications from the command itself.
        Notification::fake();

        $this->artisan('incidents:check-overdue')->assertExitCode(0);

        Notification::assertNothingSent();
        $this->assertNull($incident->fresh()->escalated_at);
    }
}
