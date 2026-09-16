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

    public function test_submit_retries_incident_number_generation_on_unique_constraint_collision(): void
    {
        $reporter = $this->makeReporter();
        $year = now()->year;

        // Bypass the service to plant an incident that already holds the
        // number the service's first (colliding) attempt will try to reuse.
        $existing = new Incident(['location' => 'Ward 1']);
        $existing->reporter_id = $reporter->id;
        $existing->status = IncidentStatus::Submitted;
        $existing->incident_number = "IR-{$year}-000001";
        $existing->save();

        $service = $this->partialMock(IncidentService::class, function ($mock) use ($year) {
            $mock->shouldAllowMockingProtectedMethods();
            $mock->shouldReceive('generateIncidentNumber')
                ->twice()
                ->andReturn("IR-{$year}-000001", "IR-{$year}-000002");
        });

        $draft = $service->createDraft($reporter, [
            'severity' => Severity::Level1Low->value,
            'occurred_at' => now(),
            'location' => 'Ward 2',
            'summary' => 'Near miss with medication cart.',
        ]);

        $service->submit($draft);

        $this->assertSame("IR-{$year}-000002", $draft->fresh()->incident_number);
        $this->assertSame(IncidentStatus::Submitted, $draft->fresh()->status);
    }

    public function test_owner_can_view_and_update_their_own_draft(): void
    {
        $reporter = $this->makeReporter();
        $incident = app(IncidentService::class)->createDraft($reporter, ['location' => 'ER']);

        $this->assertTrue($reporter->can('view', $incident));
        $this->assertTrue($reporter->can('update', $incident));
    }

    public function test_other_staff_cannot_view_or_update_someone_elses_draft(): void
    {
        $reporter = $this->makeReporter();
        $other = $this->makeReporter();
        $incident = app(IncidentService::class)->createDraft($reporter, ['location' => 'ER']);

        $this->assertFalse($other->can('view', $incident));
        $this->assertFalse($other->can('update', $incident));
    }

    public function test_owner_cannot_update_after_submission(): void
    {
        $reporter = $this->makeReporter();
        $incident = app(IncidentService::class)->createDraft($reporter, [
            'occurred_at' => now(), 'location' => 'ER', 'summary' => 'x',
        ]);
        app(IncidentService::class)->submit($incident);

        $this->assertFalse($reporter->can('update', $incident->fresh()));
        $this->assertTrue($reporter->can('view', $incident->fresh()));
    }

    public function test_supervisor_can_view_submitted_incidents_from_any_department_but_not_others_drafts(): void
    {
        $reporter = $this->makeReporter();
        $supervisor = User::factory()->create(['role' => \App\Enums\Role::Supervisor]);

        $draft = app(IncidentService::class)->createDraft($reporter, ['location' => 'ER']);
        $this->assertFalse($supervisor->can('view', $draft));

        $submitted = app(IncidentService::class)->createDraft($reporter, [
            'occurred_at' => now(), 'location' => 'ER', 'summary' => 'x',
        ]);
        app(IncidentService::class)->submit($submitted);
        $this->assertTrue($supervisor->can('view', $submitted->fresh()));
    }
}
