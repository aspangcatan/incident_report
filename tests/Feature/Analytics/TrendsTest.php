<?php

namespace Tests\Feature\Analytics;

use App\Enums\IncidentStatus;
use App\Enums\Role;
use App\Enums\Severity;
use App\Models\Department;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\User;
use App\Services\IncidentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrendsTest extends TestCase
{
    use RefreshDatabase;

    private function incident(Department $department, IncidentType $type, ?Severity $severity, string $reportedAt): Incident
    {
        $incident = app(IncidentService::class)->createDraft(User::factory()->create(), [
            'department_id' => $department->id,
            'incident_type_ids' => [$type->id],
            'occurred_at' => $reportedAt,
            'location' => 'Ward 3',
            'summary' => 'Test.',
        ]);
        app(IncidentService::class)->submit($incident);
        $incident->forceFill(['reported_at' => $reportedAt, 'severity' => $severity])->saveQuietly();

        return $incident->fresh();
    }

    public function test_monthly_counts_are_split_by_severity(): void
    {
        $this->travelTo('2026-09-15');
        $dept = Department::factory()->create();
        $type = IncidentType::factory()->create();
        $this->incident($dept, $type, Severity::Level1Low, '2026-08-03');
        $this->incident($dept, $type, Severity::Level3High, '2026-08-20');
        $this->incident($dept, $type, null, '2026-09-01');

        $this->actingAs(User::factory()->create(['role' => Role::QualitySafetyOfficer]))
            ->get('/analytics/trends?from=2026-08&to=2026-09')
            ->assertInertia(fn ($page) => $page
                ->component('Analytics/Trends')
                ->has('monthly', 2)
                ->where('monthly.0.month', '2026-08')
                ->where('monthly.0.total', 2)
                ->where('monthly.0.counts.level_1_low', 1)
                ->where('monthly.0.counts.level_3_high', 1)
                ->where('monthly.1.counts.unassessed', 1)
                ->where('total', 3));
    }

    public function test_types_and_departments_are_compared_with_the_previous_period(): void
    {
        $this->travelTo('2026-09-15');
        $icu = Department::factory()->create();
        $falls = IncidentType::factory()->create(['name' => 'Falls']);
        $this->incident($icu, $falls, Severity::Level2Moderate, '2026-09-02');
        $this->incident($icu, $falls, Severity::Level2Moderate, '2026-09-05');
        $this->incident($icu, $falls, Severity::Level2Moderate, '2026-08-10'); // previous period

        $this->actingAs(User::factory()->create(['role' => Role::QualitySafetyOfficer]))
            ->get('/analytics/trends?from=2026-09&to=2026-09')
            ->assertInertia(fn ($page) => $page
                ->where('byType.0.name', 'Falls')
                ->where('byType.0.count', 2)
                ->where('byType.0.previous', 1)
                ->where('byDepartment.0.count', 2)
                ->where('previousTotal', 1)
                ->where('heatmap.rows.0.cells.level_2_moderate', 2));
    }

    public function test_filters_narrow_the_data(): void
    {
        $this->travelTo('2026-09-15');
        $icu = Department::factory()->create();
        $er = Department::factory()->create();
        $falls = IncidentType::factory()->create();
        $theft = IncidentType::factory()->create();
        $this->incident($icu, $falls, Severity::Level1Low, '2026-09-02');
        $this->incident($er, $falls, Severity::Level1Low, '2026-09-02');
        $this->incident($icu, $theft, Severity::Level1Low, '2026-09-02');

        $cqi = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $this->actingAs($cqi)->get("/analytics/trends?from=2026-09&to=2026-09&department_id={$icu->id}")
            ->assertInertia(fn ($page) => $page->where('total', 2));
        $this->actingAs($cqi)->get("/analytics/trends?from=2026-09&to=2026-09&incident_type_id={$falls->id}")
            ->assertInertia(fn ($page) => $page->where('total', 2));
    }

    public function test_a_department_head_only_counts_their_department(): void
    {
        $this->travelTo('2026-09-15');
        $mine = Department::factory()->create();
        $type = IncidentType::factory()->create();
        $this->incident($mine, $type, Severity::Level1Low, '2026-09-02');
        $this->incident(Department::factory()->create(), $type, Severity::Level1Low, '2026-09-02');

        $this->actingAs(User::factory()->create(['role' => Role::DepartmentHead, 'department_id' => $mine->id]))
            ->get('/analytics/trends?from=2026-09&to=2026-09')
            ->assertInertia(fn ($page) => $page->where('total', 1));
    }

    public function test_time_to_closure_averages_closed_incidents(): void
    {
        $this->travelTo('2026-09-30');
        $dept = Department::factory()->create();
        $type = IncidentType::factory()->create();
        $a = $this->incident($dept, $type, Severity::Level2Moderate, '2026-09-01 08:00');
        $b = $this->incident($dept, $type, Severity::Level2Moderate, '2026-09-01 08:00');
        $a->forceFill(['status' => IncidentStatus::Closed, 'closed_at' => '2026-09-05 08:00'])->saveQuietly();
        $b->forceFill(['status' => IncidentStatus::Closed, 'closed_at' => '2026-09-11 08:00'])->saveQuietly();

        $this->actingAs(User::factory()->create(['role' => Role::QualitySafetyOfficer]))
            ->get('/analytics/trends?from=2026-09&to=2026-09')
            ->assertInertia(fn ($page) => $page
                ->where('resolution.closedCount', 2)
                ->where('resolution.averageDays', 7) // 4 and 10 days
                ->where('resolution.bySeverity.1.averageDays', 7));
    }

    public function test_staff_and_it_admin_cannot_open_trends(): void
    {
        foreach ([Role::Staff, Role::Administrator] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->get('/analytics/trends')->assertForbidden();
        }
    }
}
