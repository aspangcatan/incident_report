<?php

namespace Tests\Feature\Dashboard;

use App\Enums\IncidentStatus;
use App\Enums\Role;
use App\Enums\Severity;
use App\Models\Department;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\User;
use App\Services\IncidentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private function submittedIncident(Department $department, ?User $reporter = null): Incident
    {
        $incident = app(IncidentService::class)->createDraft($reporter ?? User::factory()->create(), [
            'department_id' => $department->id,
            'incident_type_id' => IncidentType::factory()->create()->id,
            'severity' => Severity::Level2Moderate->value,
            'occurred_at' => now(),
            'location' => 'Ward 3',
            'summary' => 'Test incident.',
        ]);
        app(IncidentService::class)->submit($incident);

        return $incident->fresh();
    }

    public function test_dashboard_counts_visible_incidents_by_stage(): void
    {
        $department = Department::factory()->create();
        $this->submittedIncident($department);
        $this->submittedIncident($department)->forceFill(['status' => IncidentStatus::ForReview])->save();
        $this->submittedIncident($department)->forceFill(['status' => IncidentStatus::UnderInvestigation])->save();
        $this->submittedIncident($department)->forceFill(['status' => IncidentStatus::Closed, 'is_sentinel_event' => true])->save();
        app(IncidentService::class)->createDraft(User::factory()->create(), ['department_id' => $department->id]);

        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);

        $this->actingAs($qso)->get('/')->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Index')
            ->where('stages.reported', 1)
            ->where('stages.forReview', 1)
            ->where('stages.investigation', 1)
            ->where('stages.capa', 0)
            ->where('stages.verification', 0)
            ->where('stages.closed', 1)
            ->where('kpis.totalThisYear', 4)
            ->where('kpis.pendingReview', 1)
            ->where('kpis.activeInvestigations', 1)
            ->where('kpis.overdueCapa', 0)
            ->where('kpis.sentinelThisYear', 1)
            ->where('kpis.closedAndVerified', 1));
    }

    public function test_dashboard_counts_only_what_the_user_can_see(): void
    {
        $mine = Department::factory()->create();
        $other = Department::factory()->create();
        $this->submittedIncident($mine);
        $this->submittedIncident($other);

        $head = User::factory()->create(['role' => Role::DepartmentHead, 'department_id' => $mine->id]);

        $this->actingAs($head)->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('stages.reported', 1)
            ->where('kpis.totalThisYear', 1));
    }
}
