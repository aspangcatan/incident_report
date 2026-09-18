<?php

namespace Tests\Feature\Analytics;

use App\DataTransferObjects\CorrectiveActions\CompleteCorrectiveActionData;
use App\DataTransferObjects\CorrectiveActions\CorrectiveActionData;
use App\DataTransferObjects\CorrectiveActions\VerifyCorrectiveActionData;
use App\DataTransferObjects\Investigations\CompleteInvestigationData;
use App\DataTransferObjects\Investigations\FindingData;
use App\DataTransferObjects\Investigations\StartInvestigationData;
use App\Enums\InvestigationMethodology;
use App\Enums\Role;
use App\Enums\Severity;
use App\Models\Department;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\User;
use App\Services\AnalyticsService;
use App\Services\CorrectiveActionService;
use App\Services\IncidentService;
use App\Services\InvestigationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_qso_administrator_and_management_can_view_analytics(): void
    {
        $this->assertTrue(User::factory()->create(['role' => Role::QualitySafetyOfficer])->can('viewAnalytics', Incident::class));
        $this->assertTrue(User::factory()->create(['role' => Role::Administrator])->can('viewAnalytics', Incident::class));
        $this->assertTrue(User::factory()->create(['role' => Role::Management])->can('viewAnalytics', Incident::class));
    }

    public function test_supervisor_and_department_head_can_view_analytics(): void
    {
        $this->assertTrue(User::factory()->create(['role' => Role::Supervisor])->can('viewAnalytics', Incident::class));
        $this->assertTrue(User::factory()->create(['role' => Role::DepartmentHead])->can('viewAnalytics', Incident::class));
    }

    public function test_staff_and_investigator_cannot_view_analytics(): void
    {
        $this->assertFalse(User::factory()->create(['role' => Role::Staff])->can('viewAnalytics', Incident::class));
        $this->assertFalse(User::factory()->create(['role' => Role::Investigator])->can('viewAnalytics', Incident::class));
    }

    private function incidentThroughReview(Department $department, ?IncidentType $incidentType = null, Severity $severity = Severity::Level2Moderate): Incident
    {
        $incidentType ??= IncidentType::factory()->create();
        $reporter = User::factory()->create();
        $supervisor = User::factory()->create(['role' => Role::Supervisor, 'department_id' => $department->id]);

        $incident = app(IncidentService::class)->createDraft($reporter, [
            'department_id' => $department->id,
            'incident_type_id' => $incidentType->id,
            'severity' => $severity->value,
            'occurred_at' => now(),
            'location' => 'Ward 3',
            'summary' => 'Test incident.',
        ]);
        app(IncidentService::class)->submit($incident);
        app(IncidentService::class)->markReviewed($incident->fresh(), $supervisor, null);

        return $incident->fresh();
    }

    public function test_mean_hours_to_review_averages_reported_to_reviewed_gap(): void
    {
        $department = Department::factory()->create();
        $incidentA = $this->incidentThroughReview($department);
        $incidentA->forceFill(['reported_at' => now()->subHours(10)])->save();
        $incidentA->forceFill(['supervisor_reviewed_at' => now()])->save();
        $incidentB = $this->incidentThroughReview($department);
        $incidentB->forceFill(['reported_at' => now()->subHours(20)])->save();
        $incidentB->forceFill(['supervisor_reviewed_at' => now()])->save();

        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $kpis = app(AnalyticsService::class)->overview($qso)['kpis'];

        $this->assertSame(15.0, $kpis['meanHoursToReview']);
    }

    public function test_capa_adoption_rate_is_verified_over_total(): void
    {
        $department = Department::factory()->create();
        $incident = $this->incidentThroughReview($department);
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        app(IncidentService::class)->assignInvestigator($incident->fresh(), $investigator);
        $investigation = app(InvestigationService::class)->start($incident->fresh(), $investigator, StartInvestigationData::fromArray([
            'objective' => 'x', 'methodology' => InvestigationMethodology::FiveWhys->value,
        ]));
        app(InvestigationService::class)->addFinding($investigation, FindingData::fromArray(['question' => 'Q', 'finding' => 'F', 'is_root_cause' => true]));
        app(InvestigationService::class)->complete($investigation->fresh(), CompleteInvestigationData::fromArray(['conclusion' => 'Done.']));

        $capaOne = app(CorrectiveActionService::class)->create($incident->fresh(), CorrectiveActionData::fromArray([
            'description' => 'Fix one.', 'action_type' => 'corrective', 'priority' => 'high', 'due_date' => now()->addDays(7)->toDateString(),
        ]));
        $capaTwo = app(CorrectiveActionService::class)->create($incident->fresh(), CorrectiveActionData::fromArray([
            'description' => 'Fix two.', 'action_type' => 'corrective', 'priority' => 'high', 'due_date' => now()->addDays(7)->toDateString(),
        ]));
        $this->actingAs(User::factory()->create());
        app(CorrectiveActionService::class)->complete($capaOne, CompleteCorrectiveActionData::fromArray(['completion_notes' => 'Done.']));
        app(CorrectiveActionService::class)->verify($capaOne->fresh(), User::factory()->create(['role' => Role::QualitySafetyOfficer]), VerifyCorrectiveActionData::fromArray(['verification_comments' => 'Confirmed.']));
        // $capaTwo stays Open (unverified).

        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $kpis = app(AnalyticsService::class)->overview($qso)['kpis'];

        $this->assertSame(1, $kpis['capaAdoption']['verified']);
        $this->assertSame(2, $kpis['capaAdoption']['total']);
        $this->assertSame(50.0, $kpis['capaAdoption']['rate']);
    }

    public function test_near_miss_velocity_is_null_safe_with_no_prior_period_data(): void
    {
        $department = Department::factory()->create();
        $this->incidentThroughReview($department, null, Severity::Level1Low);

        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $kpis = app(AnalyticsService::class)->overview($qso)['kpis'];

        // One near-miss this period, zero in the prior period: treated as a
        // full "surge" (100%) rather than a division-by-zero error.
        $this->assertSame(100.0, $kpis['nearMissVelocityPercent']);
    }

    public function test_a_department_head_only_sees_their_own_departments_kpis(): void
    {
        $deptA = Department::factory()->create();
        $deptB = Department::factory()->create();
        $incidentA = $this->incidentThroughReview($deptA);
        $incidentA->forceFill(['reported_at' => now()->subHours(4), 'supervisor_reviewed_at' => now()])->save();
        $incidentB = $this->incidentThroughReview($deptB);
        $incidentB->forceFill(['reported_at' => now()->subHours(40), 'supervisor_reviewed_at' => now()])->save();

        $deptHeadA = User::factory()->create(['role' => Role::DepartmentHead, 'department_id' => $deptA->id]);
        $kpis = app(AnalyticsService::class)->overview($deptHeadA)['kpis'];

        $this->assertSame(4.0, $kpis['meanHoursToReview']);
    }
}
