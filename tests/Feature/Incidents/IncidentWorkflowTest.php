<?php

namespace Tests\Feature\Incidents;

use App\Enums\IncidentStatus;
use App\Enums\Severity;
use App\Models\Department;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\User;
use App\Notifications\IncidentAssignedNotification;
use App\Notifications\IncidentReturnedForRevisionNotification;
use App\Notifications\IncidentReviewedNotification;
use App\Notifications\IncidentSubmittedNotification;
use App\Services\IncidentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class IncidentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function makeReporter(): User
    {
        return User::factory()->create();
    }

    private function submittedIncident(User $reporter, ?Department $department = null): Incident
    {
        $department ??= Department::factory()->create();
        $incidentType = IncidentType::factory()->create();

        $incident = app(IncidentService::class)->createDraft($reporter, [
            'department_id' => $department->id,
            'incident_type_id' => $incidentType->id,
            'severity' => Severity::Level2Moderate->value,
            'occurred_at' => now(),
            'location' => 'Ward 3',
            'summary' => 'Test incident.',
        ]);

        app(IncidentService::class)->submit($incident);

        return $incident->fresh();
    }

    public function test_creating_a_draft_writes_a_created_audit_log_entry(): void
    {
        $reporter = $this->makeReporter();
        $incident = app(IncidentService::class)->createDraft($reporter, ['location' => 'ER']);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Incident::class,
            'auditable_id' => $incident->id,
            'action' => 'created',
        ]);
    }

    public function test_submitting_writes_a_status_changed_audit_log_entry(): void
    {
        $reporter = $this->makeReporter();
        $incident = $this->submittedIncident($reporter);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Incident::class,
            'auditable_id' => $incident->id,
            'action' => 'status_changed',
        ]);

        $log = $incident->auditLogs()->where('action', 'status_changed')->first();
        $this->assertSame('draft', $log->old_values['status']);
        $this->assertSame(IncidentStatus::Submitted->value, $log->new_values['status']);
    }

    public function test_returning_for_revision_sends_the_incident_back_to_draft_and_keeps_the_incident_number(): void
    {
        $reporter = $this->makeReporter();
        $reviewer = User::factory()->create(['role' => \App\Enums\Role::Supervisor]);
        $incident = $this->submittedIncident($reporter);
        $originalNumber = $incident->incident_number;

        app(IncidentService::class)->returnForRevision($incident, $reviewer, 'Missing witness details.');

        $incident->refresh();
        $this->assertTrue($incident->status->isDraft());
        $this->assertSame($originalNumber, $incident->incident_number);
        $this->assertSame($reviewer->id, $incident->supervisor_reviewed_by);
        $this->assertSame('Missing witness details.', $incident->supervisor_comments);
    }

    public function test_resubmitting_a_returned_incident_keeps_the_same_incident_number(): void
    {
        $reporter = $this->makeReporter();
        $reviewer = User::factory()->create(['role' => \App\Enums\Role::Supervisor]);
        $incident = $this->submittedIncident($reporter);
        $originalNumber = $incident->incident_number;

        app(IncidentService::class)->returnForRevision($incident, $reviewer, 'Please add more detail.');
        app(IncidentService::class)->submit($incident->fresh());

        $this->assertSame($originalNumber, $incident->fresh()->incident_number);
        $this->assertSame(IncidentStatus::Submitted, $incident->fresh()->status);
    }

    public function test_marking_reviewed_sets_status_and_reviewer_fields(): void
    {
        $reporter = $this->makeReporter();
        $reviewer = User::factory()->create(['role' => \App\Enums\Role::Supervisor]);
        $incident = $this->submittedIncident($reporter);

        app(IncidentService::class)->markReviewed($incident, $reviewer, 'Looks complete.');

        $incident->refresh();
        $this->assertSame(IncidentStatus::Reviewed, $incident->status);
        $this->assertSame($reviewer->id, $incident->supervisor_reviewed_by);
        $this->assertSame('Looks complete.', $incident->supervisor_comments);
        $this->assertNotNull($incident->supervisor_reviewed_at);
    }

    public function test_assigning_an_investigator_sets_status_and_computes_target_closure_date(): void
    {
        $reporter = $this->makeReporter();
        $reviewer = User::factory()->create(['role' => \App\Enums\Role::Supervisor]);
        $investigator = User::factory()->create(['role' => \App\Enums\Role::Investigator]);
        $incident = $this->submittedIncident($reporter);
        app(IncidentService::class)->markReviewed($incident, $reviewer, null);

        app(IncidentService::class)->assignInvestigator($incident, $investigator);

        $incident->refresh();
        $this->assertSame(IncidentStatus::Assigned, $incident->status);
        $this->assertSame($investigator->id, $incident->assigned_investigator_id);
        $this->assertNotNull($incident->target_closure_date);
        // Level 2 Moderate -> 168 hours per config/incident_workflow.php.
        // target_closure_date is stored as a date-only column/cast (see the
        // incidents migration and Incident::$casts), so compare dates rather
        // than exact timestamps -- an hour-precision comparison would only
        // pass when the test happens to run at exact midnight.
        $this->assertSame(
            now()->addHours(168)->toDateString(),
            $incident->target_closure_date->toDateString()
        );
    }

    public function test_assigning_an_investigator_writes_both_an_assigned_and_status_changed_audit_entry(): void
    {
        $reporter = $this->makeReporter();
        $reviewer = User::factory()->create(['role' => \App\Enums\Role::Supervisor]);
        $investigator = User::factory()->create(['role' => \App\Enums\Role::Investigator]);
        $incident = $this->submittedIncident($reporter);
        app(IncidentService::class)->markReviewed($incident, $reviewer, null);

        app(IncidentService::class)->assignInvestigator($incident, $investigator);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Incident::class,
            'auditable_id' => $incident->id,
            'action' => 'assigned',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Incident::class,
            'auditable_id' => $incident->id,
            'action' => 'status_changed',
        ]);
    }

    public function test_supervisor_can_review_a_submitted_incident_in_their_own_department(): void
    {
        $reporter = $this->makeReporter();
        $department = Department::factory()->create();
        $supervisor = User::factory()->create(['role' => \App\Enums\Role::Supervisor, 'department_id' => $department->id]);
        $incident = $this->submittedIncident($reporter, $department);

        $this->assertTrue($supervisor->can('review', $incident));
    }

    public function test_supervisor_cannot_review_a_submitted_incident_from_a_different_department(): void
    {
        $reporter = $this->makeReporter();
        $incidentDepartment = Department::factory()->create();
        $supervisorDepartment = Department::factory()->create();
        $supervisor = User::factory()->create(['role' => \App\Enums\Role::Supervisor, 'department_id' => $supervisorDepartment->id]);
        $incident = $this->submittedIncident($reporter, $incidentDepartment);

        $this->assertFalse($supervisor->can('review', $incident));
    }

    public function test_nobody_can_review_a_draft_or_already_reviewed_incident(): void
    {
        $reporter = $this->makeReporter();
        $department = Department::factory()->create();
        $supervisor = User::factory()->create(['role' => \App\Enums\Role::Supervisor, 'department_id' => $department->id]);

        $draft = app(IncidentService::class)->createDraft($reporter, ['department_id' => $department->id]);
        $this->assertFalse($supervisor->can('review', $draft));

        $submitted = $this->submittedIncident($reporter, $department);
        app(IncidentService::class)->markReviewed($submitted, $supervisor, null);
        $this->assertFalse($supervisor->can('review', $submitted->fresh()));
    }

    public function test_supervisor_can_assign_only_a_reviewed_incident_in_their_department(): void
    {
        $reporter = $this->makeReporter();
        $department = Department::factory()->create();
        $supervisor = User::factory()->create(['role' => \App\Enums\Role::Supervisor, 'department_id' => $department->id]);
        $incident = $this->submittedIncident($reporter, $department);

        $this->assertFalse($supervisor->can('assign', $incident));

        app(IncidentService::class)->markReviewed($incident, $supervisor, null);
        $this->assertTrue($supervisor->can('assign', $incident->fresh()));
    }

    public function test_management_cannot_review_or_assign(): void
    {
        $reporter = $this->makeReporter();
        $department = Department::factory()->create();
        $management = User::factory()->create(['role' => \App\Enums\Role::Management, 'department_id' => $department->id]);
        $incident = $this->submittedIncident($reporter, $department);

        $this->assertFalse($management->can('review', $incident));
        $this->assertFalse($management->can('assign', $incident));
    }

    public function test_submitting_notifies_department_supervisors_and_qso(): void
    {
        \Illuminate\Support\Facades\Notification::fake();

        $reporter = $this->makeReporter();
        $department = Department::factory()->create();
        $supervisor = User::factory()->create(['role' => \App\Enums\Role::Supervisor, 'department_id' => $department->id]);
        $qso = User::factory()->create(['role' => \App\Enums\Role::QualitySafetyOfficer]);
        $otherDeptSupervisor = User::factory()->create(['role' => \App\Enums\Role::Supervisor, 'department_id' => Department::factory()->create()->id]);

        $this->submittedIncident($reporter, $department);

        \Illuminate\Support\Facades\Notification::assertSentTo($supervisor, \App\Notifications\IncidentSubmittedNotification::class);
        \Illuminate\Support\Facades\Notification::assertSentTo($qso, \App\Notifications\IncidentSubmittedNotification::class);
        \Illuminate\Support\Facades\Notification::assertNotSentTo($otherDeptSupervisor, \App\Notifications\IncidentSubmittedNotification::class);
    }

    public function test_marking_reviewed_notifies_the_reporter(): void
    {
        \Illuminate\Support\Facades\Notification::fake();

        $reporter = $this->makeReporter();
        $reviewer = User::factory()->create(['role' => \App\Enums\Role::Supervisor]);
        $incident = $this->submittedIncident($reporter);

        app(IncidentService::class)->markReviewed($incident, $reviewer, null);

        \Illuminate\Support\Facades\Notification::assertSentTo($reporter, \App\Notifications\IncidentReviewedNotification::class);
    }

    public function test_returning_for_revision_notifies_the_reporter(): void
    {
        \Illuminate\Support\Facades\Notification::fake();

        $reporter = $this->makeReporter();
        $reviewer = User::factory()->create(['role' => \App\Enums\Role::Supervisor]);
        $incident = $this->submittedIncident($reporter);

        app(IncidentService::class)->returnForRevision($incident, $reviewer, 'Please add detail.');

        \Illuminate\Support\Facades\Notification::assertSentTo($reporter, \App\Notifications\IncidentReturnedForRevisionNotification::class);
    }

    public function test_assigning_an_investigator_notifies_them(): void
    {
        \Illuminate\Support\Facades\Notification::fake();

        $reporter = $this->makeReporter();
        $reviewer = User::factory()->create(['role' => \App\Enums\Role::Supervisor]);
        $investigator = User::factory()->create(['role' => \App\Enums\Role::Investigator]);
        $incident = $this->submittedIncident($reporter);
        app(IncidentService::class)->markReviewed($incident, $reviewer, null);

        app(IncidentService::class)->assignInvestigator($incident, $investigator);

        \Illuminate\Support\Facades\Notification::assertSentTo($investigator, \App\Notifications\IncidentAssignedNotification::class);
    }

    public function test_submitting_a_null_department_incident_does_not_notify_unscoped_supervisors(): void
    {
        \Illuminate\Support\Facades\Notification::fake();

        $reporter = $this->makeReporter();
        $supervisor = User::factory()->create(['role' => \App\Enums\Role::Supervisor]);
        $this->assertNull($supervisor->department_id);

        $incident = app(IncidentService::class)->createDraft($reporter, [
            'occurred_at' => now(),
            'location' => 'ER',
            'summary' => 'Test incident with no department.',
        ]);
        app(IncidentService::class)->submit($incident);

        $this->assertNull($incident->fresh()->department_id);
        \Illuminate\Support\Facades\Notification::assertNotSentTo($supervisor, \App\Notifications\IncidentSubmittedNotification::class);
    }

    public function test_a_supervisor_can_mark_a_submitted_incident_reviewed_via_http(): void
    {
        $reporter = $this->makeReporter();
        $department = Department::factory()->create();
        $supervisor = User::factory()->create(['role' => \App\Enums\Role::Supervisor, 'department_id' => $department->id]);
        $incident = $this->submittedIncident($reporter, $department);

        $response = $this->actingAs($supervisor)->post("/incidents/{$incident->id}/review", [
            'comments' => 'Looks good.',
        ]);

        $response->assertRedirect();
        $this->assertSame(IncidentStatus::Reviewed, $incident->fresh()->status);
    }

    public function test_returning_requires_comments_via_http(): void
    {
        $reporter = $this->makeReporter();
        $department = Department::factory()->create();
        $supervisor = User::factory()->create(['role' => \App\Enums\Role::Supervisor, 'department_id' => $department->id]);
        $incident = $this->submittedIncident($reporter, $department);

        $this->actingAs($supervisor)
            ->post("/incidents/{$incident->id}/return", [])
            ->assertSessionHasErrors(['comments']);

        $this->assertSame(IncidentStatus::Submitted, $incident->fresh()->status);
    }

    public function test_a_supervisor_outside_the_department_cannot_review_via_http(): void
    {
        $reporter = $this->makeReporter();
        $incidentDepartment = Department::factory()->create();
        $supervisor = User::factory()->create(['role' => \App\Enums\Role::Supervisor, 'department_id' => Department::factory()->create()->id]);
        $incident = $this->submittedIncident($reporter, $incidentDepartment);

        $this->actingAs($supervisor)
            ->post("/incidents/{$incident->id}/review", ['comments' => 'x'])
            ->assertForbidden();
    }

    public function test_assigning_requires_a_user_with_the_investigator_role(): void
    {
        $reporter = $this->makeReporter();
        $department = Department::factory()->create();
        $supervisor = User::factory()->create(['role' => \App\Enums\Role::Supervisor, 'department_id' => $department->id]);
        $notAnInvestigator = User::factory()->create(['role' => \App\Enums\Role::Staff]);
        $incident = $this->submittedIncident($reporter, $department);
        app(IncidentService::class)->markReviewed($incident, $supervisor, null);

        $this->actingAs($supervisor)
            ->post("/incidents/{$incident->id}/assign", ['assigned_investigator_id' => $notAnInvestigator->id])
            ->assertSessionHasErrors(['assigned_investigator_id']);
    }

    public function test_a_supervisor_can_assign_a_reviewed_incident_via_http(): void
    {
        $reporter = $this->makeReporter();
        $department = Department::factory()->create();
        $supervisor = User::factory()->create(['role' => \App\Enums\Role::Supervisor, 'department_id' => $department->id]);
        $investigator = User::factory()->create(['role' => \App\Enums\Role::Investigator]);
        $incident = $this->submittedIncident($reporter, $department);
        app(IncidentService::class)->markReviewed($incident, $supervisor, null);

        $response = $this->actingAs($supervisor)->post("/incidents/{$incident->id}/assign", [
            'assigned_investigator_id' => $investigator->id,
        ]);

        $response->assertRedirect();
        $this->assertSame($investigator->id, $incident->fresh()->assigned_investigator_id);
        $this->assertSame(IncidentStatus::Assigned, $incident->fresh()->status);
    }

    public function test_show_exposes_workflow_can_flags_and_audit_logs(): void
    {
        $reporter = $this->makeReporter();
        $department = Department::factory()->create();
        $supervisor = User::factory()->create(['role' => \App\Enums\Role::Supervisor, 'department_id' => $department->id]);
        $incident = $this->submittedIncident($reporter, $department);

        $response = $this->actingAs($supervisor)->get("/incidents/{$incident->id}");

        $response->assertInertia(fn ($page) => $page
            ->component('Incidents/Show')
            ->where('can.review', true)
            ->where('can.assign', false)
            ->has('auditLogs')
        );
    }
}
