<?php

namespace Tests\Feature\Analytics;

use App\DataTransferObjects\Approvals\DecideApprovalData;
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
use App\Services\ApprovalService;
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

    /**
     * Regression test for a truncation bug: diffInHours()/24 floors to a
     * whole hour before dividing, so a 25h36m span would wrongly read as
     * 25/24 = 1.0 day (rounded) instead of the real 25.6/24 = 1.1 days.
     */
    public function test_mean_days_to_investigate_does_not_truncate_sub_hour_precision(): void
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
        $investigation->fresh()->forceFill([
            'started_at' => now()->subHours(25)->subMinutes(36),
            'completed_at' => now(),
        ])->save();

        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $kpis = app(AnalyticsService::class)->overview($qso)['kpis'];

        $this->assertSame(1.1, $kpis['meanDaysToInvestigate']);
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

    public function test_root_cause_distribution_groups_by_contributing_factor_category(): void
    {
        $department = Department::factory()->create();
        $incident = $this->incidentThroughReview($department);
        $humanFactors = \App\Models\ContributingFactor::create(['label' => 'Fatigue', 'category' => 'Human Factors']);
        $equipment = \App\Models\ContributingFactor::create(['label' => 'Device malfunction', 'category' => 'Equipment']);
        $incident->contributingFactors()->sync([$humanFactors->id, $equipment->id]);

        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $distribution = app(AnalyticsService::class)->overview($qso)['rootCauseDistribution'];

        $categories = collect($distribution)->pluck('category')->all();
        $this->assertContains('Human Factors', $categories);
        $this->assertContains('Equipment', $categories);
    }

    public function test_department_safety_table_reports_capa_resolution_per_department(): void
    {
        $department = Department::factory()->create(['name' => 'Emergency Medicine']);
        $incident = $this->incidentThroughReview($department);
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        app(IncidentService::class)->assignInvestigator($incident->fresh(), $investigator);
        $investigation = app(InvestigationService::class)->start($incident->fresh(), $investigator, StartInvestigationData::fromArray([
            'objective' => 'x', 'methodology' => InvestigationMethodology::FiveWhys->value,
        ]));
        app(InvestigationService::class)->addFinding($investigation, FindingData::fromArray(['question' => 'Q', 'finding' => 'F', 'is_root_cause' => true]));
        app(InvestigationService::class)->complete($investigation->fresh(), CompleteInvestigationData::fromArray(['conclusion' => 'Done.']));
        $capa = app(CorrectiveActionService::class)->create($incident->fresh(), CorrectiveActionData::fromArray([
            'description' => 'Fix.', 'action_type' => 'corrective', 'priority' => 'high', 'due_date' => now()->addDays(7)->toDateString(),
        ]));
        $this->actingAs(User::factory()->create());
        app(CorrectiveActionService::class)->complete($capa, CompleteCorrectiveActionData::fromArray(['completion_notes' => 'Done.']));
        app(CorrectiveActionService::class)->verify($capa->fresh(), User::factory()->create(['role' => Role::QualitySafetyOfficer]), VerifyCorrectiveActionData::fromArray(['verification_comments' => 'Confirmed.']));

        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $table = app(AnalyticsService::class)->overview($qso)['departmentSafety'];

        $row = collect($table)->firstWhere('departmentName', 'Emergency Medicine');
        $this->assertNotNull($row);
        $this->assertSame(1, $row['capasVerified']);
        $this->assertSame(1, $row['capasTotal']);
        $this->assertSame(100, $row['safetyIndex']);
        $this->assertSame('Exemplary', $row['statusLabel']);
    }

    /**
     * departmentSafety()'s inner aggregation deliberately skips
     * baseQuery()/visibleTo() (see its own code comment) on the theory that
     * $departmentIds was already scoped upstream - this proves that holds
     * for a real Supervisor/DepartmentHead-scoped caller, not just the
     * hospital-wide QSO case every other test in this file uses. Same
     * scoping property applies to rootCauseDistribution(), checked here too.
     */
    public function test_root_cause_and_department_safety_are_scoped_to_a_department_heads_own_department(): void
    {
        $deptA = Department::factory()->create(['name' => 'Emergency Medicine']);
        $deptB = Department::factory()->create(['name' => 'Surgery']);

        $incidentA = $this->incidentThroughReview($deptA);
        $humanFactors = \App\Models\ContributingFactor::create(['label' => 'Fatigue', 'category' => 'Human Factors']);
        $incidentA->contributingFactors()->sync([$humanFactors->id]);

        $incidentB = $this->incidentThroughReview($deptB);
        $equipment = \App\Models\ContributingFactor::create(['label' => 'Device malfunction', 'category' => 'Equipment']);
        $incidentB->contributingFactors()->sync([$equipment->id]);
        $investigatorB = User::factory()->create(['role' => Role::Investigator]);
        app(IncidentService::class)->assignInvestigator($incidentB->fresh(), $investigatorB);
        $investigationB = app(InvestigationService::class)->start($incidentB->fresh(), $investigatorB, StartInvestigationData::fromArray([
            'objective' => 'x', 'methodology' => InvestigationMethodology::FiveWhys->value,
        ]));
        app(InvestigationService::class)->addFinding($investigationB, FindingData::fromArray(['question' => 'Q', 'finding' => 'F', 'is_root_cause' => true]));
        app(InvestigationService::class)->complete($investigationB->fresh(), CompleteInvestigationData::fromArray(['conclusion' => 'Done.']));
        app(CorrectiveActionService::class)->create($incidentB->fresh(), CorrectiveActionData::fromArray([
            'description' => 'Fix.', 'action_type' => 'corrective', 'priority' => 'high', 'due_date' => now()->addDays(7)->toDateString(),
        ]));

        $deptHeadA = User::factory()->create(['role' => Role::DepartmentHead, 'department_id' => $deptA->id]);
        $overview = app(AnalyticsService::class)->overview($deptHeadA);

        $categories = collect($overview['rootCauseDistribution'])->pluck('category')->all();
        $this->assertContains('Human Factors', $categories);
        $this->assertNotContains('Equipment', $categories);

        $departmentNames = collect($overview['departmentSafety'])->pluck('departmentName')->all();
        $this->assertContains('Emergency Medicine', $departmentNames);
        $this->assertNotContains('Surgery', $departmentNames);
    }

    public function test_hourly_volume_buckets_incidents_by_hour_and_shift(): void
    {
        $department = Department::factory()->create();
        $morning = $this->incidentThroughReview($department);
        $morning->forceFill(['occurred_at' => now()->setTime(9, 0)])->save();
        $night = $this->incidentThroughReview($department);
        $night->forceFill(['occurred_at' => now()->setTime(23, 0)])->save();

        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $volume = app(AnalyticsService::class)->overview($qso)['hourlyVolume'];

        $this->assertSame(24, count($volume));
        $nineAm = collect($volume)->firstWhere('hour', 9);
        $elevenPm = collect($volume)->firstWhere('hour', 23);
        $this->assertSame(1, $nineAm['count']);
        $this->assertSame('day', $nineAm['shift']);
        $this->assertSame(1, $elevenPm['count']);
        $this->assertSame('night', $elevenPm['shift']);
    }

    public function test_recurring_patterns_only_lists_groups_at_or_above_the_threshold(): void
    {
        $department = Department::factory()->create();
        $incidentType = IncidentType::factory()->create();
        $this->incidentThroughReview($department, $incidentType);
        $this->incidentThroughReview($department, $incidentType);
        $this->incidentThroughReview($department, $incidentType);
        $otherType = IncidentType::factory()->create();
        $this->incidentThroughReview($department, $otherType);
        $this->incidentThroughReview($department, $otherType);

        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $patterns = app(AnalyticsService::class)->overview($qso)['recurringPatterns'];

        $this->assertCount(1, $patterns);
        $this->assertSame(3, $patterns[0]['incidentCount']);
        $this->assertSame($incidentType->name, $patterns[0]['incidentTypeName']);
    }

    /**
     * The single-qualifying-group test above can't catch a regression in
     * orderByDesc('incidentCount') - with only one row there's nothing to
     * order. This uses two qualifying groups with different counts to
     * prove descending order is real, not incidental.
     */
    public function test_recurring_patterns_are_ordered_by_incident_count_descending(): void
    {
        $department = Department::factory()->create();
        $smallerType = IncidentType::factory()->create();
        $this->incidentThroughReview($department, $smallerType);
        $this->incidentThroughReview($department, $smallerType);
        $this->incidentThroughReview($department, $smallerType);
        $largerType = IncidentType::factory()->create();
        $this->incidentThroughReview($department, $largerType);
        $this->incidentThroughReview($department, $largerType);
        $this->incidentThroughReview($department, $largerType);
        $this->incidentThroughReview($department, $largerType);
        $this->incidentThroughReview($department, $largerType);

        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $patterns = app(AnalyticsService::class)->overview($qso)['recurringPatterns'];

        $this->assertCount(2, $patterns);
        $this->assertSame($largerType->name, $patterns[0]['incidentTypeName']);
        $this->assertSame(5, $patterns[0]['incidentCount']);
        $this->assertSame($smallerType->name, $patterns[1]['incidentTypeName']);
        $this->assertSame(3, $patterns[1]['incidentCount']);
    }
}
