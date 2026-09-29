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
        app(IncidentService::class)->completeAssessment($incident->fresh(), $supervisor);
        app(IncidentService::class)->markReviewed($incident->fresh(), $supervisor, null);

        return $incident->fresh();
    }

    public function test_mean_hours_to_review_averages_the_assessed_to_reviewed_gap(): void
    {
        // Review time only: the (long) department assessment before it must not count.
        $department = Department::factory()->create();
        $incidentA = $this->incidentThroughReview($department);
        $incidentA->forceFill(['reported_at' => now()->subHours(100), 'assessed_at' => now()->subHours(10), 'supervisor_reviewed_at' => now()])->save();
        $incidentB = $this->incidentThroughReview($department);
        $incidentB->forceFill(['reported_at' => now()->subHours(100), 'assessed_at' => now()->subHours(20), 'supervisor_reviewed_at' => now()])->save();

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
        $incidentA->forceFill(['assessed_at' => now()->subHours(4), 'supervisor_reviewed_at' => now()])->save();
        $incidentB = $this->incidentThroughReview($deptB);
        $incidentB->forceFill(['assessed_at' => now()->subHours(40), 'supervisor_reviewed_at' => now()])->save();

        $deptHeadA = User::factory()->create(['role' => Role::DepartmentHead, 'department_id' => $deptA->id]);
        $kpis = app(AnalyticsService::class)->overview($deptHeadA)['kpis'];

        $this->assertSame(4.0, $kpis['meanHoursToReview']);
    }

    public function test_root_cause_distribution_counts_incidents_by_root_cause_type(): void
    {
        $department = Department::factory()->create();
        $investigation = $this->investigationFor($this->incidentThroughReview($department));
        $service = app(InvestigationService::class);
        $service->addFinding($investigation, FindingData::fromArray(['finding' => 'Short-staffed.', 'is_root_cause' => true, 'category' => 'staffing']));
        $service->addFinding($investigation, FindingData::fromArray(['finding' => 'Also short-staffed.', 'is_root_cause' => true, 'category' => 'staffing']));
        $service->addFinding($investigation, FindingData::fromArray(['finding' => 'Pump alarm muted.', 'is_root_cause' => true, 'category' => 'equipment']));
        $service->addFinding($investigation, FindingData::fromArray(['finding' => 'Not a cause.', 'is_root_cause' => false, 'category' => 'environment']));

        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $distribution = collect(app(AnalyticsService::class)->overview($qso)['rootCauseDistribution']);

        $this->assertSame(1, $distribution->firstWhere('category', 'Staffing')['incidentCount']);
        $this->assertSame(1, $distribution->firstWhere('category', 'Equipment')['incidentCount']);
        $this->assertNull($distribution->firstWhere('category', 'Environment'));
    }

    private function investigationFor(Incident $incident): \App\Models\Investigation
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        app(IncidentService::class)->assignInvestigator($incident->fresh(), $investigator);

        return app(InvestigationService::class)->start($incident->fresh(), $investigator, StartInvestigationData::fromArray(['objective' => 'x']));
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
        app(InvestigationService::class)->addFinding($this->investigationFor($incidentA), FindingData::fromArray(['finding' => 'Short-staffed.', 'is_root_cause' => true, 'category' => 'staffing']));

        $incidentB = $this->incidentThroughReview($deptB);
        $investigatorB = User::factory()->create(['role' => Role::Investigator]);
        app(IncidentService::class)->assignInvestigator($incidentB->fresh(), $investigatorB);
        $investigationB = app(InvestigationService::class)->start($incidentB->fresh(), $investigatorB, StartInvestigationData::fromArray([
            'objective' => 'x', 'methodology' => InvestigationMethodology::FiveWhys->value,
        ]));
        app(InvestigationService::class)->addFinding($investigationB, FindingData::fromArray(['question' => 'Q', 'finding' => 'F', 'is_root_cause' => true, 'category' => 'equipment']));
        app(InvestigationService::class)->complete($investigationB->fresh(), CompleteInvestigationData::fromArray(['conclusion' => 'Done.']));
        app(CorrectiveActionService::class)->create($incidentB->fresh(), CorrectiveActionData::fromArray([
            'description' => 'Fix.', 'action_type' => 'corrective', 'priority' => 'high', 'due_date' => now()->addDays(7)->toDateString(),
        ]));

        $deptHeadA = User::factory()->create(['role' => Role::DepartmentHead, 'department_id' => $deptA->id]);
        $overview = app(AnalyticsService::class)->overview($deptHeadA);

        $categories = collect($overview['rootCauseDistribution'])->pluck('category')->all();
        $this->assertContains('Staffing', $categories);
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

    /**
     * Task 9 holistic-review regression (item 1): scopeVisibleTo() treats a
     * null department_id Supervisor/DepartmentHead as seeing nothing at all
     * (Incident::scopeVisibleTo() has its own explicit whereRaw('1 = 0')
     * guard for this - the exact bug Phase 3's holistic review once caught).
     * This proves every AnalyticsService method inherits that guard
     * end-to-end, rather than any of them falling back to a hospital-wide
     * result when department_id is null. Seeds real, varied data (a
     * qualifying recurring pattern, a sentinel recurrence, contributing
     * factors, a verified CAPA) and proves a hospital-wide QSO really does
     * see it, so the null-department emptiness below is scoping, not just
     * "there's no data".
     */
    public function test_department_head_with_null_department_id_gets_empty_analytics_not_hospital_wide(): void
    {
        $department = Department::factory()->create();
        $incidentType = IncidentType::factory()->create();

        $incidentA = $this->incidentThroughReview($department, $incidentType);
        $this->incidentThroughReview($department, $incidentType);
        $this->incidentThroughReview($department, $incidentType);

        $incidentA->forceFill(['is_sentinel_event' => true, 'reported_at' => now()->subDays(10)])->save();

        $investigator = User::factory()->create(['role' => Role::Investigator]);
        app(IncidentService::class)->assignInvestigator($incidentA->fresh(), $investigator);
        $investigation = app(InvestigationService::class)->start($incidentA->fresh(), $investigator, StartInvestigationData::fromArray([
            'objective' => 'x', 'methodology' => InvestigationMethodology::FiveWhys->value,
        ]));
        app(InvestigationService::class)->addFinding($investigation, FindingData::fromArray(['question' => 'Q', 'finding' => 'F', 'is_root_cause' => true, 'category' => 'staffing']));
        app(InvestigationService::class)->complete($investigation->fresh(), CompleteInvestigationData::fromArray(['conclusion' => 'Done.']));
        $capa = app(CorrectiveActionService::class)->create($incidentA->fresh(), CorrectiveActionData::fromArray([
            'description' => 'Fix.', 'action_type' => 'corrective', 'priority' => 'high', 'due_date' => now()->addDays(7)->toDateString(),
        ]));
        $this->actingAs(User::factory()->create());
        app(CorrectiveActionService::class)->complete($capa, CompleteCorrectiveActionData::fromArray(['completion_notes' => 'Done.']));
        app(CorrectiveActionService::class)->verify($capa->fresh(), User::factory()->create(['role' => Role::QualitySafetyOfficer]), VerifyCorrectiveActionData::fromArray(['verification_comments' => 'Confirmed.']));

        // Sanity: a hospital-wide QSO really does see this data.
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $qsoOverview = app(AnalyticsService::class)->overview($qso);
        $this->assertNotEmpty($qsoOverview['recurringPatterns']);
        $this->assertNotEmpty($qsoOverview['departmentSafety']);
        $this->assertNotEmpty($qsoOverview['rootCauseDistribution']);

        $deptHeadNoDept = User::factory()->create(['role' => Role::DepartmentHead, 'department_id' => null]);
        $overview = app(AnalyticsService::class)->overview($deptHeadNoDept);

        $this->assertNull($overview['kpis']['meanHoursToReview']);
        $this->assertNull($overview['kpis']['meanDaysToInvestigate']);
        $this->assertSame(['verified' => 0, 'total' => 0, 'rate' => null], $overview['kpis']['capaAdoption']);
        $this->assertSame(['recurrences' => 0, 'total' => 0, 'rate' => 0.0], $overview['kpis']['sentinelRecurrence']);
        $this->assertSame(0.0, $overview['kpis']['nearMissVelocityPercent']);
        $this->assertSame([], $overview['rootCauseDistribution']);
        $this->assertSame([], $overview['departmentSafety']);
        $this->assertSame([], $overview['recurringPatterns']);
        $this->assertCount(24, $overview['hourlyVolume']);
        $this->assertSame(0, array_sum(array_column($overview['hourlyVolume'], 'count')));
    }

    /**
     * Task 9 holistic-review regression (item 2): departmentSafety()'s
     * $everCreated > 0 guard means a department with zero CorrectiveActions
     * and zero Approvals ever created is trivially "Exemplary" rather than
     * a division-by-zero. The existing department-safety test always seeds
     * one verified CAPA, so it never actually exercises this branch.
     */
    public function test_department_safety_index_defaults_to_exemplary_with_no_capas_or_approvals_ever_created(): void
    {
        $department = Department::factory()->create(['name' => 'Radiology']);
        $this->incidentThroughReview($department);

        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $table = app(AnalyticsService::class)->overview($qso)['departmentSafety'];

        $row = collect($table)->firstWhere('departmentName', 'Radiology');
        $this->assertNotNull($row);
        $this->assertSame(0, $row['capasTotal']);
        $this->assertSame(0, $row['capasVerified']);
        $this->assertSame(100, $row['safetyIndex']);
        $this->assertSame('Exemplary', $row['statusLabel']);
    }

    /**
     * Task 9 holistic-review regression (item 2): with no CAPAs and no
     * sentinel events at all, capaAdoptionRate()'s rate stays null (not a
     * division-by-zero error), sentinelRecurrenceRate() short-circuits via
     * its isEmpty() guard, and nearMissVelocity()'s both-zero branch (unlike
     * the already-covered current>0/previous=0 "surge" branch) returns 0.0.
     */
    public function test_capa_adoption_and_sentinel_recurrence_default_to_documented_zero_state_with_no_data(): void
    {
        $department = Department::factory()->create();
        $this->incidentThroughReview($department);

        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $kpis = app(AnalyticsService::class)->overview($qso)['kpis'];

        $this->assertSame(['verified' => 0, 'total' => 0, 'rate' => null], $kpis['capaAdoption']);
        $this->assertSame(['recurrences' => 0, 'total' => 0, 'rate' => 0.0], $kpis['sentinelRecurrence']);
        $this->assertSame(0.0, $kpis['nearMissVelocityPercent']);
    }

    /**
     * Task 9 holistic-review decision (item 5): AnalyticsService's
     * WINDOW_DAYS/REPEAT_PATTERN_WINDOW_DAYS/REPEAT_PATTERN_MIN_COUNT
     * constants are now surfaced through overview() so Analytics/Index.vue
     * interpolates them into its "90 days"/"3+" copy instead of hardcoding
     * a second, driftable copy of the same numbers.
     */
    public function test_overview_surfaces_the_window_and_recurring_pattern_constants_for_frontend_copy(): void
    {
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $overview = app(AnalyticsService::class)->overview($qso);

        $this->assertSame(90, $overview['windowDays']);
        $this->assertSame(90, $overview['recurringPatternWindowDays']);
        $this->assertSame(3, $overview['recurringPatternMinCount']);
    }

    public function test_qso_can_load_the_analytics_page_via_http(): void
    {
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);

        $this->actingAs($qso)
            ->get('/analytics')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Analytics/Index')
                ->has('kpis')
                ->has('rootCauseDistribution')
                ->has('departmentSafety')
                ->has('hourlyVolume')
                ->has('recurringPatterns')
                ->has('windowDays')
                ->has('recurringPatternWindowDays')
                ->has('recurringPatternMinCount')
            );
    }

    public function test_staff_cannot_load_the_analytics_page_via_http(): void
    {
        $staff = User::factory()->create(['role' => Role::Staff]);

        $this->actingAs($staff)
            ->get('/analytics')
            ->assertForbidden();
    }
}
