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

class IncidentReportingTest extends TestCase
{
    use RefreshDatabase;

    private function makeReporter(): User
    {
        return User::factory()->create();
    }

    public function test_create_draft_creates_an_incident_owned_by_the_reporter(): void
    {
        $reporter = $this->makeReporter();
        $service = app(IncidentService::class);

        $incident = $service->createDraft($reporter, [
            'location' => 'Emergency Department, Bay 3',
        ]);

        $this->assertDatabaseHas('incidents', [
            'id' => $incident->id,
            'reporter_id' => $reporter->id,
            'status' => IncidentStatus::Draft->value,
            'location' => 'Emergency Department, Bay 3',
        ]);
        $this->assertNull($incident->incident_number);
    }

    public function test_submit_assigns_a_sequential_incident_number_and_changes_status(): void
    {
        $reporter = $this->makeReporter();
        $department = Department::factory()->create();
        $incidentType = IncidentType::factory()->create();
        $service = app(IncidentService::class);

        $first = $service->createDraft($reporter, [
            'department_id' => $department->id,
            'incident_type_id' => $incidentType->id,
            'severity' => Severity::Level2Moderate->value,
            'occurred_at' => now(),
            'location' => 'Ward 3',
            'summary' => 'Patient fall near the nurses station.',
        ]);
        $service->submit($first);

        $second = $service->createDraft($reporter, [
            'department_id' => $department->id,
            'incident_type_id' => $incidentType->id,
            'severity' => Severity::Level1Low->value,
            'occurred_at' => now(),
            'location' => 'Ward 4',
            'summary' => 'Near miss with medication cart.',
        ]);
        $service->submit($second);

        $year = now()->year;
        $this->assertSame("IR-{$year}-000001", $first->fresh()->incident_number);
        $this->assertSame("IR-{$year}-000002", $second->fresh()->incident_number);
        $this->assertSame(IncidentStatus::Submitted, $second->fresh()->status);
        $this->assertNotNull($second->fresh()->reported_at);
    }

    public function test_submit_marks_level_4_incidents_as_sentinel_events(): void
    {
        $reporter = $this->makeReporter();
        $service = app(IncidentService::class);

        $incident = $service->createDraft($reporter, [
            'severity' => Severity::Level4CriticalSentinel->value,
            'occurred_at' => now(),
            'location' => 'ICU Bed 2',
            'summary' => 'Sentinel event.',
        ]);
        $service->submit($incident);

        $this->assertTrue($incident->fresh()->is_sentinel_event);
    }
}
