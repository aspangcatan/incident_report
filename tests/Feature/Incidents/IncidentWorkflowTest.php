<?php

namespace Tests\Feature\Incidents;

use App\Enums\IncidentStatus;
use App\Enums\Severity;
use App\Models\Department;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\User;
use App\Services\IncidentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
