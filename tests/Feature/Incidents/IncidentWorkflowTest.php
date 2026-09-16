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
}
