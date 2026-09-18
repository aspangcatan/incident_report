# Phase 8: Analytics & Organizational Learning Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the "Learn" dashboard (`/analytics`, `Analytics/Index.vue`) — a single read-only executive overview page showing real, query-backed metrics over existing incident/investigation/CAPA/approval data: five headline KPIs, a root-cause distribution breakdown, a per-department safety/CAPA-compliance table, an incident-volume-by-hour chart split by shift, and a "recurring pattern" alert list. Visible to QSO/Administrator/Management hospital-wide and to Supervisor/DepartmentHead scoped to their own department (reusing `Incident::scopeVisibleTo()`), hidden from Staff/Investigator.

**Architecture — a deliberately leaner slice of Phases 5–7's layered pattern, confirmed with the user before writing this plan (2026-09-18).** This phase is 100% read-only: no migration, no new Eloquent model, no DTOs, no Repository, no Actions — those layers exist specifically for writes, and there are none here. What *does* apply: a `Service` (`AnalyticsService`, all aggregation logic), reusing the existing `IncidentPolicy` (one new `viewAnalytics()` ability, no new Policy class needed since this isn't gated on a specific model instance), and a Controller. No Resource either — the Service returns plain arrays (there's no Eloquent model instance to shape), passed directly as Inertia props.

**Scope decisions confirmed with the user before writing this plan (2026-09-18):**
1. **Build only what's real; drop the fabricated bits.** The Stitch mockup (`stitch/.../management_learning_healthcare_analytics/`) includes an "AI Heuristics" pattern-clustering panel with invented confidence percentages, DOH/Philhealth regulatory compliance-certification badges, a live "428 admissions evaluated" hospital census, an "Export DOH CQI Report" button, and an "Institutional Lessons Learned" digest. None of these are derivable from this system's actual data (no ML pipeline, no census/admissions table, no externally-issued certification, no "lessons learned" text field anywhere in the schema) and rendering them would misrepresent the app's real capabilities. **All of these are dropped, not approximated.** What ships instead: five KPI stat tiles, a root-cause distribution (horizontal stacked bar, not the mockup's donut — see decision 4), a department safety/CAPA-compliance table, an hour-of-day incident volume chart split by shift, and a plain, transparently-labeled "recurring pattern" list (grouped by department + incident type, no invented confidence score, explicitly not called "AI").
2. **One page only.** The sidebar's "Analytics & Learning" group lists four items (Executive Overview / Trends & Sentinels / Unit & Severity Heatmap / Resolution Times); only "Executive Overview" is built and wired to `/analytics` this phase, matching every prior phase's one-tab/page-per-phase precedent. The other three stay `href: '#'`.
3. **Access:** QSO/Administrator/Management see hospital-wide data; Supervisor/DepartmentHead see only their own department's data (reusing `Incident::scopeVisibleTo()` exactly as `IncidentController::index()` already does); Staff/Investigator get a 403.
4. **Chart-form choices follow the `dataviz` skill's guidance over the mockup's literal look**, specifically: the mockup's root-cause *donut* becomes a horizontal **stacked bar** ("part-to-whole" → stacked bar is the skill's documented default; a pie/donut is generally discouraged for exactly this job); the mockup's dual-line "vulnerability matrix" becomes a single 24-bar bar chart (hour-of-day 0–23, one bar per hour) colored by shift bucket — a cleaner, more honest read of the same real data (incident count per hour, categorized day/night) than the mockup's ambiguous "spike" line chart. The validated default categorical/status palette from the `dataviz` skill (`references/palette.md`) is used as-is (unmodified, so no re-validation needed) rather than modifying this project's own Tailwind design tokens, which don't currently define a categorical or status color set — new chart-specific colors are scoped to `Analytics/Index.vue` alone via inline CSS custom properties, not added to the shared `tailwind.config.js`.
5. **All rolling-window metrics use a 90-day window** (`ANALYTICS_WINDOW_DAYS`), except sentinel recurrence, which uses 180 days to match the mockup's own stated window and because sentinel events are rare enough that 90 days would often show zero. Both are named constants on `AnalyticsService`, not magic numbers.
6. **Metric definitions are simple, transparent, and documented — not validated clinical instruments.** "Safety Index" and "recurring pattern" in particular are this project's own invented, documented heuristics (see Task 3/4), not industry-standard scores. Flagged for the same reason Phase 1's config-file decisions were: worth confirming with a real domain expert before anyone treats the exact numbers as authoritative, but reasonable defaults to ship with.

**Tech Stack:** Laravel 9 (Eloquent aggregate queries, Policies), Vue 3 + Inertia — unchanged. No new npm packages (charts are hand-built inline SVG per the `dataviz` skill's guidance, not a charting library).

**Deliberately out of scope:** the other three Analytics & Learning sidebar pages (Trends & Sentinels, Unit & Severity Heatmap, Resolution Times); sidebar nav-count badges elsewhere in the app (still deferred per `docs/architecture.md` §9b); anything from decision 1's dropped list; a real ML/clustering pipeline; sidebar active-link highlighting (doesn't exist anywhere in this app yet, not introduced here either).

---

## File Structure

**Backend — new files:**
- `app/Services/AnalyticsService.php`
- `app/Http/Controllers/AnalyticsController.php`
- `tests/Feature/Analytics/AnalyticsTest.php`

**Backend — modified files:**
- `app/Policies/IncidentPolicy.php` (add `viewAnalytics()`)
- `routes/web.php` (analytics route)

**Frontend — new files:**
- `resources/js/Pages/Analytics/Index.vue`
- `resources/js/Components/Analytics/KpiStatTile.vue`
- `resources/js/Components/Analytics/StackedBarChart.vue`
- `resources/js/Components/Analytics/HourlyVolumeChart.vue`

**Frontend — modified files:**
- `resources/js/Layouts/AuthenticatedLayout.vue` (wire "Executive Overview" href to `/analytics`)

---

### Task 1: `IncidentPolicy::viewAnalytics()`

**Files:**
- Modify: `app/Policies/IncidentPolicy.php`
- Create: `tests/Feature/Analytics/AnalyticsTest.php`

- [ ] **Step 1: Write the failing test file**

```php
<?php

namespace Tests\Feature\Analytics;

use App\Enums\Role;
use App\Models\Incident;
use App\Models\User;
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
}
```

- [ ] **Step 2: Run to confirm they fail**

```bash
cd C:\wamp64\projects\incident-report
php artisan test --filter=AnalyticsTest
```

Expected: FAIL — `viewAnalytics` isn't a registered ability yet, so `can()` returns `false` for every case, including the ones expected to be `true`.

- [ ] **Step 3: Add `viewAnalytics()` to `IncidentPolicy`**

In `app/Policies/IncidentPolicy.php`, add (near `viewAny()`):

```php
    public function viewAnalytics(User $user): bool
    {
        return in_array($user->role, [
            Role::QualitySafetyOfficer,
            Role::Administrator,
            Role::Management,
            Role::Supervisor,
            Role::DepartmentHead,
        ], true);
    }
```

This is a `viewAny()`-style ability — it doesn't gate on any specific `Incident` instance, just the viewer's role, so it's always called with the class-string rather than a model instance: `$user->can('viewAnalytics', Incident::class)` in a test, or `$this->authorize('viewAnalytics', Incident::class)` in a controller — Laravel resolves the policy from that class-string via the `$policies` mapping in `AuthServiceProvider`, the same convention `IncidentController::index()` already uses for `$this->authorize('viewAny', Incident::class)`. **Do not call `$user->can('viewAnalytics')` with no second argument at all** — Laravel then has no class or model to resolve a policy from, so it can't find `IncidentPolicy::viewAnalytics()` and silently denies. Don't work around that by registering a manual `Gate::define('viewAnalytics', ...)` closure in `AuthServiceProvider` either — that duplicates the policy resolution Laravel already does correctly once the class-string is passed, and this app has no other ability that needs one.

Which *department* a Supervisor/DepartmentHead actually sees is a query-scoping concern handled by `AnalyticsService` (via the existing `Incident::scopeVisibleTo()`), not by this ability — this ability only decides "can this role open the page at all."

- [ ] **Step 4: Run tests, then the full suite**

```bash
php artisan test --filter=AnalyticsTest
```

Expected: `3 passed`.

```bash
php artisan test
```

Expected: all green (161 prior + these 3).

- [ ] **Step 5: Commit**

```bash
git add app/Policies/IncidentPolicy.php tests/Feature/Analytics/AnalyticsTest.php
git commit -m "feat: add IncidentPolicy::viewAnalytics ability"
```

---

### Task 2: `AnalyticsService` — headline KPIs

**Files:**
- Create: `app/Services/AnalyticsService.php`
- Modify: `tests/Feature/Analytics/AnalyticsTest.php`

- [ ] **Step 1: Write the failing tests**

Append to `AnalyticsTest` (add these `use` imports at the top of the file first):

```php
use App\DataTransferObjects\CorrectiveActions\CompleteCorrectiveActionData;
use App\DataTransferObjects\CorrectiveActions\CorrectiveActionData;
use App\DataTransferObjects\CorrectiveActions\VerifyCorrectiveActionData;
use App\DataTransferObjects\Investigations\CompleteInvestigationData;
use App\DataTransferObjects\Investigations\FindingData;
use App\DataTransferObjects\Investigations\StartInvestigationData;
use App\Enums\InvestigationMethodology;
use App\Enums\Severity;
use App\Models\Department;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Services\AnalyticsService;
use App\Services\CorrectiveActionService;
use App\Services\IncidentService;
use App\Services\InvestigationService;
```

Add these test methods to the class:

```php
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
     * Regression test for a truncation bug caught in this task's own
     * code-quality review: diffInHours()/24 floors to a whole hour before
     * dividing, so a 25h36m span would wrongly read as 25/24 = 1.0 day
     * (rounded) instead of the real 25.6/24 = 1.1 days.
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
```

- [ ] **Step 2: Run to confirm they fail**

```bash
php artisan test --filter=AnalyticsTest
```

Expected: FAIL — `App\Services\AnalyticsService` doesn't exist yet.

- [ ] **Step 3: Write `AnalyticsService`**

```php
<?php

namespace App\Services;

use App\Enums\CorrectiveActionStatus;
use App\Enums\IncidentStatus;
use App\Enums\Severity;
use App\Models\Incident;
use App\Models\Investigation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class AnalyticsService
{
    /** Rolling window for every metric below except sentinel recurrence. */
    private const WINDOW_DAYS = 90;

    /** Sentinel events are rare; a shorter window would too often read zero. */
    private const SENTINEL_WINDOW_DAYS = 180;

    public function overview(User $user): array
    {
        return [
            'kpis' => [
                'meanHoursToReview' => $this->meanHoursToReview($user),
                'meanDaysToInvestigate' => $this->meanDaysToInvestigate($user),
                'capaAdoption' => $this->capaAdoptionRate($user),
                'sentinelRecurrence' => $this->sentinelRecurrenceRate($user),
                'nearMissVelocityPercent' => $this->nearMissVelocity($user),
            ],
        ];
    }

    /**
     * visibleTo() alone does not exclude drafts - every other caller in
     * this codebase (e.g. IncidentController::index()) chains an explicit
     * draft exclusion alongside it, since a draft is an unsubmitted
     * personal in-progress form, not yet a real reported incident. Analytics
     * must never count one: occurred_at/severity/department_id are all
     * captured before submission, so a draft can otherwise slip past
     * several of the metrics below (hourlyVolume in particular) even
     * though it was never actually reported.
     */
    private function baseQuery(User $user): Builder
    {
        return Incident::query()->where('status', '!=', IncidentStatus::Draft)->visibleTo($user);
    }

    private function meanHoursToReview(User $user): ?float
    {
        $rows = $this->baseQuery($user)
            ->whereNotNull('supervisor_reviewed_at')
            ->where('reported_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->get(['reported_at', 'supervisor_reviewed_at']);

        if ($rows->isEmpty()) {
            return null;
        }

        return round($rows->avg(fn (Incident $i) => $i->reported_at->diffInMinutes($i->supervisor_reviewed_at) / 60), 1);
    }

    private function meanDaysToInvestigate(User $user): ?float
    {
        $investigations = Investigation::query()
            ->whereHas('incident', fn (Builder $q) => $q->visibleTo($user))
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->get(['started_at', 'completed_at']);

        if ($investigations->isEmpty()) {
            return null;
        }

        // diffInMinutes()/60/24, not diffInHours()/24: diffInHours() truncates
        // to a whole hour before the division, which would systematically
        // undercount every investigation whose span isn't an exact multiple
        // of 24 hours (e.g. 25h36m -> 25h -> 1.041... days instead of 1.066...).
        return round($investigations->avg(fn (Investigation $i) => $i->started_at->diffInMinutes($i->completed_at) / 60 / 24), 1);
    }

    private function capaAdoptionRate(User $user): array
    {
        $visibleIncidentIds = $this->baseQuery($user)->pluck('id');

        $total = DB::table('corrective_actions')
            ->whereIn('incident_id', $visibleIncidentIds)
            ->where('created_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->count();

        $verified = DB::table('corrective_actions')
            ->whereIn('incident_id', $visibleIncidentIds)
            ->where('created_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->where('status', CorrectiveActionStatus::Verified->value)
            ->count();

        return [
            'verified' => $verified,
            'total' => $total,
            'rate' => $total > 0 ? round($verified / $total * 100, 1) : null,
        ];
    }

    private function sentinelRecurrenceRate(User $user): array
    {
        $sentinels = $this->baseQuery($user)
            ->where('is_sentinel_event', true)
            ->where('reported_at', '>=', now()->subDays(self::SENTINEL_WINDOW_DAYS))
            ->get(['id', 'incident_type_id', 'department_id', 'reported_at']);

        if ($sentinels->isEmpty()) {
            return ['recurrences' => 0, 'total' => 0, 'rate' => 0.0];
        }

        // Deliberately not re-scoped by visibleTo($user): safe only because
        // every $sentinel here already passed the outer baseQuery($user)
        // scope, so its own department_id is always one this caller can
        // already see in full (either they're unrestricted, or visibleTo()
        // already grants them full visibility into that exact department -
        // never a partial-visibility case, since IncidentPolicy::viewAnalytics()
        // never lets a partial-visibility role reach this Service at all).
        // If that policy gate is ever loosened, this inner query would need
        // its own explicit department/visibility check.
        $recurrences = $sentinels->filter(function (Incident $sentinel) {
            return Incident::query()
                ->where('is_sentinel_event', true)
                ->where('incident_type_id', $sentinel->incident_type_id)
                ->where('department_id', $sentinel->department_id)
                ->where('id', '!=', $sentinel->id)
                ->where('reported_at', '<', $sentinel->reported_at)
                ->exists();
        })->count();

        return [
            'recurrences' => $recurrences,
            'total' => $sentinels->count(),
            'rate' => round($recurrences / $sentinels->count() * 100, 1),
        ];
    }

    /**
     * % change in near-miss (Severity::Level1Low, per docs/architecture.md
     * §3.1's own "Level I near-miss" language) volume between the last 30
     * days and the 30 days before that. A zero-in-the-prior-period case is
     * treated as a full 100% surge if the current period has any at all
     * (not a division-by-zero error, and not a misleading infinite %).
     */
    private function nearMissVelocity(User $user): float
    {
        $current = $this->baseQuery($user)
            ->where('severity', Severity::Level1Low->value)
            ->where('reported_at', '>=', now()->subDays(30))
            ->count();

        $previous = $this->baseQuery($user)
            ->where('severity', Severity::Level1Low->value)
            ->whereBetween('reported_at', [now()->subDays(60), now()->subDays(30)])
            ->count();

        if ($previous === 0) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }
}
```

`Incident::scopeVisibleTo()` already exists (Phase 3) and is called here exactly the way `IncidentController::index()` already calls it — QSO/Administrator/Management get an unscoped query, Supervisor/DepartmentHead get `where('department_id', $user->department_id)` (or a guaranteed-empty query if their `department_id` is null), everyone else gets scoped to their own reported/assigned incidents. Since `viewAnalytics()` (Task 1) already excludes Staff/Investigator from reaching this Service at all, the "everyone else" branch of `scopeVisibleTo()` never actually executes for an analytics request — included here only because it's the same shared scope, not because this phase relies on it.

- [ ] **Step 4: Run tests to see them pass**

```bash
php artisan test --filter=AnalyticsTest
```

Expected: `8 passed` (3 from Task 1 + 5 new).

- [ ] **Step 5: Run the full suite**

```bash
php artisan test
```

Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add app/Services/AnalyticsService.php tests/Feature/Analytics/AnalyticsTest.php
git commit -m "feat: add AnalyticsService with headline KPI metrics"
```

---

### Task 3: `AnalyticsService` — root cause distribution & department safety table

**Files:**
- Modify: `app/Services/AnalyticsService.php`
- Modify: `tests/Feature/Analytics/AnalyticsTest.php`

- [ ] **Step 1: Write the failing tests**

Add this import to `AnalyticsTest.php`:

```php
use App\DataTransferObjects\Approvals\DecideApprovalData;
use App\Services\ApprovalService;
```

Append these test methods:

```php
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
```

- [ ] **Step 2: Run to confirm they fail**

```bash
php artisan test --filter=AnalyticsTest
```

Expected: FAIL — `rootCauseDistribution`/`departmentSafety` keys don't exist in `overview()`'s return array yet.

- [ ] **Step 3: Add the two methods to `AnalyticsService`**

Add these imports to the top of `AnalyticsService.php`:

```php
use App\Models\Approval;
use App\Models\CorrectiveAction;
use App\Models\Department;
```

Add `'rootCauseDistribution' => $this->rootCauseDistribution($user),` and `'departmentSafety' => $this->departmentSafety($user),` to the array `overview()` returns (alongside `'kpis'`).

Add the two private methods:

```php
    /**
     * Grouped by contributing_factors.category (captured on the report
     * form at submission time, Phase 3) rather than investigation finding
     * category - every incident has contributing factors regardless of
     * which RCA methodology its investigation used, so this source is
     * complete where finding-category data would have gaps (five_whys
     * findings never set a category at all).
     */
    private function rootCauseDistribution(User $user): array
    {
        $visibleIncidentIds = $this->baseQuery($user)
            ->where('reported_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->pluck('id');

        return DB::table('incident_contributing_factor')
            ->join('contributing_factors', 'contributing_factors.id', '=', 'incident_contributing_factor.contributing_factor_id')
            ->whereIn('incident_contributing_factor.incident_id', $visibleIncidentIds)
            ->whereNotNull('contributing_factors.category')
            ->groupBy('contributing_factors.category')
            ->selectRaw('contributing_factors.category as category, COUNT(DISTINCT incident_contributing_factor.incident_id) as incidentCount')
            ->orderByDesc('incidentCount')
            ->get()
            ->map(fn ($row) => ['category' => $row->category, 'incidentCount' => (int) $row->incidentCount])
            ->all();
    }

    /**
     * "Safety Index" here is this project's own simple, documented
     * heuristic - not a validated clinical instrument: 100 minus the
     * percentage of the department's ever-created CorrectiveActions and
     * Approvals that are currently overdue. A department with zero
     * CorrectiveActions/Approvals ever is trivially "Exemplary" (nothing
     * to be overdue on). Status labels: >=95 Exemplary, >=85 Optimal,
     * >=70 Compliant, else "Needs Attention" - thresholds are a reasonable
     * starting point, worth confirming with a real patient-safety officer
     * before anyone treats the exact numbers as authoritative.
     */
    private function departmentSafety(User $user): array
    {
        $departmentIds = $this->baseQuery($user)->whereNotNull('department_id')->distinct()->pluck('department_id');

        $departments = Department::query()->whereIn('id', $departmentIds)->orderBy('name')->get();

        if ($departments->isEmpty()) {
            return [];
        }

        // Not routed through baseQuery()/visibleTo() here: $departmentIds
        // was itself derived from baseQuery($user) above, so this can never
        // reach a department the caller isn't allowed to see. Draft incidents
        // are also safe to leave in this particular id set (unlike
        // hourlyVolume()) since a draft can never have a
        // CorrectiveAction/Approval row pointing at it - the counts below
        // would be identical either way.
        //
        // Fetched as one incident_id -> department_id lookup plus one query
        // each for CorrectiveAction/Approval, then aggregated in PHP using
        // the models' own isOverdue() - rather than N queries per department
        // (which scaled linearly with department count) or a raw SQL
        // CASE/NOW() aggregation (which would duplicate each model's overdue
        // rule as a second, driver-specific copy: this project's tests run
        // against SQLite while production runs MySQL, and NOW() isn't
        // portable between them).
        $incidentDepartmentIds = Incident::query()->whereIn('department_id', $departmentIds)->pluck('department_id', 'id');

        $stats = $departments->mapWithKeys(fn (Department $d) => [$d->id => [
            'capasTotal' => 0, 'capasVerified' => 0, 'capasOverdue' => 0,
            'approvalsTotal' => 0, 'approvalsOverdue' => 0,
        ]])->all();

        CorrectiveAction::query()
            ->whereIn('incident_id', $incidentDepartmentIds->keys())
            ->get(['incident_id', 'status', 'due_date'])
            ->each(function (CorrectiveAction $capa) use (&$stats, $incidentDepartmentIds) {
                $departmentId = $incidentDepartmentIds[$capa->incident_id];
                $stats[$departmentId]['capasTotal']++;
                if ($capa->status === CorrectiveActionStatus::Verified) {
                    $stats[$departmentId]['capasVerified']++;
                }
                if ($capa->isOverdue()) {
                    $stats[$departmentId]['capasOverdue']++;
                }
            });

        Approval::query()
            ->whereIn('incident_id', $incidentDepartmentIds->keys())
            ->get(['incident_id', 'status', 'due_at'])
            ->each(function (Approval $approval) use (&$stats, $incidentDepartmentIds) {
                $departmentId = $incidentDepartmentIds[$approval->incident_id];
                $stats[$departmentId]['approvalsTotal']++;
                if ($approval->isOverdue()) {
                    $stats[$departmentId]['approvalsOverdue']++;
                }
            });

        return $departments->map(function (Department $department) use ($stats) {
            $s = $stats[$department->id];
            $everCreated = $s['capasTotal'] + $s['approvalsTotal'];
            $everOverdue = $s['capasOverdue'] + $s['approvalsOverdue'];
            $safetyIndex = $everCreated > 0 ? (int) round(100 * (1 - $everOverdue / $everCreated)) : 100;

            return [
                'departmentId' => $department->id,
                'departmentName' => $department->name,
                'capasVerified' => $s['capasVerified'],
                'capasTotal' => $s['capasTotal'],
                'safetyIndex' => $safetyIndex,
                'statusLabel' => match (true) {
                    $safetyIndex >= 95 => 'Exemplary',
                    $safetyIndex >= 85 => 'Optimal',
                    $safetyIndex >= 70 => 'Compliant',
                    default => 'Needs Attention',
                },
            ];
        })->all();
    }
```

**Fixed during this task's own code-quality review**: the first draft of this method ran a per-department loop issuing 6 queries per department (unbounded by any time window), which would scale linearly with department count. Rewritten above to fetch everything in a small constant number of queries (one incident-to-department lookup, one CorrectiveAction fetch, one Approval fetch) and aggregate in PHP using each model's own `isOverdue()` — this also avoids duplicating the overdue business rule as a second, database-driver-specific copy (a raw SQL `CASE ... NOW() ...` aggregation would need to differ between this project's SQLite test environment and its MySQL production environment).

- [ ] **Step 4: Run tests, then the full suite**

```bash
php artisan test --filter=AnalyticsTest
```

Expected: `11 passed` (8 from Task 2 + 3 new).

```bash
php artisan test
```

Expected: all green.

- [ ] **Step 5: Commit**

```bash
git add app/Services/AnalyticsService.php tests/Feature/Analytics/AnalyticsTest.php
git commit -m "feat: add root-cause distribution and department safety table to AnalyticsService"
```

---

### Task 4: `AnalyticsService` — hourly incident volume & recurring patterns

**Files:**
- Modify: `app/Services/AnalyticsService.php`
- Modify: `tests/Feature/Analytics/AnalyticsTest.php`

- [ ] **Step 1: Write the failing tests**

Append to `AnalyticsTest`:

```php
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
```

- [ ] **Step 2: Run to confirm they fail**

```bash
php artisan test --filter=AnalyticsTest
```

Expected: FAIL — `hourlyVolume`/`recurringPatterns` keys don't exist yet.

- [ ] **Step 3: Add the two methods to `AnalyticsService`**

Add this import:

```php
use App\Models\IncidentType;
```

Add `'hourlyVolume' => $this->hourlyVolume($user),` and `'recurringPatterns' => $this->recurringPatterns($user),` to `overview()`'s returned array.

Add the two private methods plus one private constant:

```php
    /**
     * Same value as WINDOW_DAYS today, but kept as its own named constant
     * (not a reuse of WINDOW_DAYS) since a "how far back counts as a
     * recurring pattern" window is a distinct business question from "how
     * far back for a trailing KPI" and may need to move independently.
     */
    private const REPEAT_PATTERN_WINDOW_DAYS = 90;
    private const REPEAT_PATTERN_MIN_COUNT = 3;

    /**
     * Day shift 07:00-18:59, night shift 19:00-06:59 - a fixed convention
     * documented here since no shift-schedule table exists in this app.
     *
     * Fetched as one query (occurred_at only) and bucketed in PHP rather
     * than a SQL GROUP BY HOUR(occurred_at) - that function isn't portable
     * between this project's SQLite test driver and MySQL production
     * (SQLite needs strftime('%H', ...) instead), and a grouped query would
     * still need this same PHP-side zero-fill afterward anyway, since SQL
     * GROUP BY only returns hours that actually have rows.
     */
    private function hourlyVolume(User $user): array
    {
        $counts = array_fill(0, 24, 0);

        $this->baseQuery($user)
            ->where('occurred_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->get(['occurred_at'])
            ->each(function (Incident $incident) use (&$counts) {
                $counts[(int) $incident->occurred_at->format('G')]++;
            });

        return array_map(
            fn (int $hour, int $count) => [
                'hour' => $hour,
                'count' => $count,
                'shift' => ($hour >= 7 && $hour < 19) ? 'day' : 'night',
            ],
            range(0, 23),
            $counts,
        );
    }

    /**
     * Grouped by (department, incident type) rather than a specific
     * contributing factor - an incident can carry several factors, which
     * would make "the" factor for a cluster ambiguous, while department +
     * type is unambiguous and still a meaningful, real recurring-pattern
     * signal. Deliberately NOT labeled "AI" and carries no invented
     * confidence score - see this plan's scope decision 1.
     */
    private function recurringPatterns(User $user): array
    {
        $rows = $this->baseQuery($user)
            ->where('reported_at', '>=', now()->subDays(self::REPEAT_PATTERN_WINDOW_DAYS))
            ->whereNotNull('department_id')
            ->whereNotNull('incident_type_id')
            ->groupBy('department_id', 'incident_type_id')
            ->havingRaw('COUNT(*) >= ?', [self::REPEAT_PATTERN_MIN_COUNT])
            ->orderByDesc('incidentCount')
            ->selectRaw('department_id, incident_type_id, COUNT(*) as incidentCount')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $departments = Department::whereIn('id', $rows->pluck('department_id'))->pluck('name', 'id');
        $incidentTypes = IncidentType::whereIn('id', $rows->pluck('incident_type_id'))->pluck('name', 'id');

        return $rows->map(fn ($row) => [
            'departmentName' => $departments[$row->department_id] ?? 'Unknown',
            'incidentTypeName' => $incidentTypes[$row->incident_type_id] ?? 'Unknown',
            'incidentCount' => (int) $row->incidentCount,
        ])->all();
    }
```

- [ ] **Step 4: Run tests, then the full suite**

```bash
php artisan test --filter=AnalyticsTest
```

Expected: `14 passed` (11 from Task 3 + 3 new).

```bash
php artisan test
```

Expected: all green.

- [ ] **Step 5: Commit**

```bash
git add app/Services/AnalyticsService.php tests/Feature/Analytics/AnalyticsTest.php
git commit -m "feat: add hourly incident volume and recurring-pattern grouping to AnalyticsService"
```

---

### Task 5: `AnalyticsController`, route, and HTTP-level access tests

**Files:**
- Create: `app/Http/Controllers/AnalyticsController.php`
- Modify: `routes/web.php`
- Modify: `tests/Feature/Analytics/AnalyticsTest.php`

- [ ] **Step 1: Add failing HTTP-level tests**

Append to `AnalyticsTest`:

```php
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
            );
    }

    public function test_staff_cannot_load_the_analytics_page_via_http(): void
    {
        $staff = User::factory()->create(['role' => Role::Staff]);

        $this->actingAs($staff)
            ->get('/analytics')
            ->assertForbidden();
    }
```

- [ ] **Step 2: Run to confirm they fail**

```bash
php artisan test --filter=AnalyticsTest
```

Expected: FAIL — `/analytics` doesn't exist yet (404).

- [ ] **Step 3: Write `AnalyticsController`**

```php
<?php

namespace App\Http\Controllers;

use App\Models\Incident;
use App\Services\AnalyticsService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AnalyticsController extends Controller
{
    public function index(Request $request, AnalyticsService $analytics): Response
    {
        $this->authorize('viewAnalytics', Incident::class);

        return Inertia::render('Analytics/Index', $analytics->overview($request->user()));
    }
}
```

- [ ] **Step 4: Add the route**

In `routes/web.php`, add the import:

```php
use App\Http\Controllers\AnalyticsController;
```

Inside the `auth` middleware group, after the notifications routes:

```php
    Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics.index');
```

- [ ] **Step 5: Run tests**

```bash
php artisan test --filter=AnalyticsTest
```

Expected: `16 passed` (14 from Task 4 + 2 new).

```bash
php artisan test
```

Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/AnalyticsController.php routes/web.php tests/Feature/Analytics/AnalyticsTest.php
git commit -m "feat: add /analytics route and controller"
```

---

### Task 6: KPI row and root-cause distribution UI

**Files:**
- Create: `resources/js/Components/Analytics/KpiStatTile.vue`
- Create: `resources/js/Components/Analytics/StackedBarChart.vue`
- Create: `resources/js/Pages/Analytics/Index.vue` (partial — KPI row + root-cause section only; the rest is Task 7)

This task establishes the page's color tokens and the two simplest sections. Per the `dataviz` skill: color comes last, and the categorical/status palettes below are the skill's own validated default (`references/palette.md`), used unmodified — scoped to this page only via CSS custom properties, not added to the shared `tailwind.config.js` (which has no categorical/status color set of its own to extend).

- [ ] **Step 1: Write `KpiStatTile.vue`**

A stat tile per the skill's figure contract: `label` (sentence case, no trailing colon), a semibold proportional-figure `value`, and an optional signed `delta` colored by direction × whether up is good.

```vue
<script setup>
defineProps({
    label: { type: String, required: true },
    value: { type: String, required: true },
    delta: { type: String, default: null },
    deltaIsGood: { type: Boolean, default: null },
});
</script>

<template>
    <div class="p-space-md rounded-xl bg-surface-container-lowest shadow-sm flex flex-col gap-1">
        <span class="font-label-sm text-body-sm text-outline">{{ label }}</span>
        <span class="font-headline-sm text-headline-sm text-on-surface font-semibold">{{ value }}</span>
        <span
            v-if="delta"
            class="font-body-sm text-body-sm font-semibold"
            :class="deltaIsGood === null ? 'text-outline' : (deltaIsGood ? 'text-[#006300]' : 'text-error')"
        >
            {{ delta }}
        </span>
    </div>
</template>
```

`text-[#006300]` is the `dataviz` skill's own "delta up good" ink token (`references/palette.md`, Chart chrome & ink table) — used here as a one-off arbitrary Tailwind value rather than a new shared token, since this is the only place in the app this specific shade is needed.

- [ ] **Step 2: Write `StackedBarChart.vue`**

A single horizontal stacked bar for part-to-whole data (root-cause distribution), per the skill's `references/marks-and-anatomy.md` mark specs: 24px thick, 2px surface-color gaps between segments, a legend (always present for 2+ series), and direct labels only where they fit.

```vue
<script setup>
const props = defineProps({
    // [{ category: string, incidentCount: number }], pre-sorted descending by the caller.
    segments: { type: Array, required: true },
});

const palette = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#4a3aa7', '#e34948', '#008300'];

const total = props.segments.reduce((sum, s) => sum + s.incidentCount, 0);

function widthPercent(count) {
    return total > 0 ? (count / total) * 100 : 0;
}
</script>

<template>
    <div class="flex flex-col gap-3">
        <div v-if="!segments.length" class="text-center font-body-sm text-body-sm text-outline p-space-md">
            No contributing-factor data recorded in this window.
        </div>
        <template v-else>
            <div class="flex w-full h-6 rounded-full overflow-hidden bg-surface-container">
                <div
                    v-for="(segment, index) in segments"
                    :key="segment.category"
                    class="h-full flex items-center justify-center"
                    :style="{
                        width: widthPercent(segment.incidentCount) + '%',
                        backgroundColor: palette[index % palette.length],
                        marginRight: index < segments.length - 1 ? '2px' : '0',
                    }"
                    :title="`${segment.category}: ${segment.incidentCount}`"
                >
                    <span v-if="widthPercent(segment.incidentCount) >= 12" class="font-label-sm text-body-sm text-white font-semibold px-1 truncate">
                        {{ Math.round(widthPercent(segment.incidentCount)) }}%
                    </span>
                </div>
            </div>
            <div class="flex flex-wrap gap-3">
                <div v-for="(segment, index) in segments" :key="segment.category" class="flex items-center gap-1.5">
                    <span class="w-3 h-3 rounded-full inline-block" :style="{ backgroundColor: palette[index % palette.length] }" />
                    <span class="font-body-sm text-body-sm text-on-surface-variant">{{ segment.category }} ({{ segment.incidentCount }})</span>
                </div>
            </div>
        </template>
    </div>
</template>
```

An inline label is shown only when a segment's own width comfortably fits a short "NN%" string (>=12% of the bar) — per the skill's "a label that won't fit doesn't get clipped" rule, narrower segments skip the inline label and rely on the legend (which always carries the exact count) instead of truncating or overflowing text inside a thin sliver. A native `title` attribute gives every segment a hover tooltip regardless of width, without building a custom tooltip component for what is otherwise a fairly simple, low-interaction chart.

- [ ] **Step 3: Write `Analytics/Index.vue` (KPI row + root-cause section)**

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import KpiStatTile from '@/Components/Analytics/KpiStatTile.vue';
import StackedBarChart from '@/Components/Analytics/StackedBarChart.vue';

const props = defineProps({
    kpis: { type: Object, required: true },
    rootCauseDistribution: { type: Array, required: true },
    departmentSafety: { type: Array, required: true },
    hourlyVolume: { type: Array, required: true },
    recurringPatterns: { type: Array, required: true },
});

function formatHours(hours) {
    return hours === null ? 'No data yet' : `${hours} hrs`;
}

function formatDays(days) {
    return days === null ? 'No data yet' : `${days} days`;
}

function formatPercent(rate) {
    return rate === null ? 'No data yet' : `${rate}%`;
}
</script>

<template>
    <AuthenticatedLayout>
        <div class="flex flex-col gap-space-lg">
            <div class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm">
                <h1 class="font-headline-md text-headline-md text-primary tracking-tight">Executive Incident Intelligence &amp; Organizational Learning</h1>
                <p class="font-body-sm text-body-sm text-outline mt-1">Trailing 90-day window, scoped to the departments you have access to.</p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
                <KpiStatTile label="Mean time to review" :value="formatHours(kpis.meanHoursToReview)" />
                <KpiStatTile label="Mean time to investigate" :value="formatDays(kpis.meanDaysToInvestigate)" />
                <KpiStatTile
                    label="CAPA adoption rate"
                    :value="formatPercent(kpis.capaAdoption.rate)"
                    :delta="kpis.capaAdoption.total ? `${kpis.capaAdoption.verified}/${kpis.capaAdoption.total} verified` : null"
                />
                <KpiStatTile
                    label="Sentinel recurrence"
                    :value="formatPercent(kpis.sentinelRecurrence.rate)"
                    :delta="kpis.sentinelRecurrence.total ? `${kpis.sentinelRecurrence.recurrences}/${kpis.sentinelRecurrence.total} repeat` : 'No sentinel events'"
                    :delta-is-good="kpis.sentinelRecurrence.rate === 0"
                />
                <KpiStatTile
                    label="Near-miss reporting velocity"
                    :value="`${kpis.nearMissVelocityPercent > 0 ? '+' : ''}${kpis.nearMissVelocityPercent}%`"
                    delta="vs. prior 30 days"
                    :delta-is-good="true"
                />
            </div>

            <div class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-space-md">
                <div>
                    <h2 class="font-title-lg text-title-lg text-primary font-bold">Root Cause Distribution</h2>
                    <p class="font-body-sm text-body-sm text-outline">Contributing factors recorded on incidents in the last 90 days, by category.</p>
                </div>
                <StackedBarChart :segments="rootCauseDistribution" />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
```

This intentionally omits `departmentSafety`/`hourlyVolume`/`recurringPatterns` rendering for now — they're accepted as props (so the component doesn't warn about missing required props) but not yet used in the template; Task 7 adds those three sections.

- [ ] **Step 4: Build frontend assets**

```bash
cd C:\wamp64\projects\incident-report
npm run build
```

Expected: no errors (a Vue "declared but not used" warning for the three not-yet-rendered props is expected and harmless at this point — Task 7 resolves it).

- [ ] **Step 5: Commit**

```bash
git add resources/js/Components/Analytics resources/js/Pages/Analytics/Index.vue
git commit -m "feat: add Analytics/Index.vue KPI row and root-cause distribution chart"
```

---

### Task 7: Department table, hourly volume chart, and recurring patterns UI

**Files:**
- Create: `resources/js/Components/Analytics/HourlyVolumeChart.vue`
- Modify: `resources/js/Pages/Analytics/Index.vue`

- [ ] **Step 1: Write `HourlyVolumeChart.vue`**

A 24-bar column chart, one bar per hour, colored by shift bucket (day/night) — a categorical 2-series job per the `dataviz` skill, using categorical slots 1 (blue) and 2 (orange) from the skill's validated default palette, both already confirmed as an adjacent-safe pair. Bars follow the mark spec: <=24px thick, 4px rounded data-end, square at the baseline, with a legend since there are 2 series.

```vue
<script setup>
const props = defineProps({
    // [{ hour: number, count: number, shift: 'day'|'night' }] x24, hour-ascending.
    hours: { type: Array, required: true },
});

const dayColor = '#2a78d6';
const nightColor = '#eb6834';

const maxCount = Math.max(1, ...props.hours.map((h) => h.count));

function barHeightPercent(count) {
    return (count / maxCount) * 100;
}

function formatHourLabel(hour) {
    if (hour === 0) return '12a';
    if (hour === 12) return '12p';
    return hour < 12 ? `${hour}a` : `${hour - 12}p`;
}
</script>

<template>
    <div class="flex flex-col gap-3">
        <div class="flex items-end gap-1 h-40">
            <div v-for="entry in hours" :key="entry.hour" class="flex-1 flex flex-col items-center justify-end h-full gap-1" :title="`${formatHourLabel(entry.hour)}: ${entry.count} incident(s), ${entry.shift} shift`">
                <div
                    class="w-full rounded-t-[4px]"
                    :style="{
                        height: Math.max(barHeightPercent(entry.count), entry.count > 0 ? 4 : 0) + '%',
                        backgroundColor: entry.shift === 'day' ? dayColor : nightColor,
                    }"
                />
            </div>
        </div>
        <div class="flex gap-1">
            <span v-for="entry in hours" :key="entry.hour" class="flex-1 text-center font-code-tabular text-[10px] text-outline">
                {{ entry.hour % 3 === 0 ? formatHourLabel(entry.hour) : '' }}
            </span>
        </div>
        <div class="flex gap-3">
            <div class="flex items-center gap-1.5">
                <span class="w-3 h-3 rounded-full inline-block" :style="{ backgroundColor: dayColor }" />
                <span class="font-body-sm text-body-sm text-on-surface-variant">Day shift (07:00-18:59)</span>
            </div>
            <div class="flex items-center gap-1.5">
                <span class="w-3 h-3 rounded-full inline-block" :style="{ backgroundColor: nightColor }" />
                <span class="font-body-sm text-body-sm text-on-surface-variant">Night shift (19:00-06:59)</span>
            </div>
        </div>
    </div>
</template>
```

Only every third hour label is printed (`0/3/6/9/...`) to avoid 24 overlapping labels on a narrow axis — the full value is still available per-bar via the native `title` tooltip, consistent with how `StackedBarChart.vue` handles the same "label doesn't fit" case.

- [ ] **Step 2: Add the department table, hourly chart, and recurring-patterns sections to `Analytics/Index.vue`**

Insert after the Root Cause Distribution `</div>` block, before the closing `</div>` of the outer `flex flex-col gap-space-lg`:

```html
            <div class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-space-md">
                <div>
                    <h2 class="font-title-lg text-title-lg text-primary font-bold">Incident Volume by Hour of Day</h2>
                    <p class="font-body-sm text-body-sm text-outline">Last 90 days, by the hour the incident occurred.</p>
                </div>
                <HourlyVolumeChart :hours="hourlyVolume" />
            </div>

            <div class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-space-md">
                <div>
                    <h2 class="font-title-lg text-title-lg text-primary font-bold">Departmental Safety Index &amp; CAPA Compliance</h2>
                    <p class="font-body-sm text-body-sm text-outline">A simple, transparent internal heuristic - not a validated clinical index.</p>
                </div>
                <div v-if="!departmentSafety.length" class="text-center font-body-sm text-body-sm text-outline p-space-md">
                    No department data available yet.
                </div>
                <table v-else class="w-full text-left">
                    <thead>
                        <tr class="font-label-sm text-body-sm uppercase text-outline">
                            <th class="pb-2">Department</th>
                            <th class="pb-2">CAPA Resolution</th>
                            <th class="pb-2">Safety Index</th>
                            <th class="pb-2">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in departmentSafety" :key="row.departmentId" class="border-t border-outline-variant">
                            <td class="py-2 font-body-md text-body-md text-on-surface">{{ row.departmentName }}</td>
                            <td class="py-2 font-code-tabular text-body-sm text-on-surface-variant">{{ row.capasVerified }} / {{ row.capasTotal }}</td>
                            <td class="py-2">
                                <div class="flex items-center gap-2">
                                    <div class="w-24 h-2 rounded-full bg-surface-container overflow-hidden">
                                        <div class="h-full rounded-full bg-primary" :style="{ width: row.safetyIndex + '%' }" />
                                    </div>
                                    <span class="font-code-tabular text-body-sm text-on-surface-variant">{{ row.safetyIndex }}/100</span>
                                </div>
                            </td>
                            <td class="py-2">
                                <span
                                    class="px-2.5 py-0.5 rounded-full font-label-sm text-body-sm font-semibold"
                                    :class="{
                                        'bg-secondary-container text-on-secondary-container': row.statusLabel === 'Exemplary' || row.statusLabel === 'Optimal',
                                        'bg-surface-container text-on-surface': row.statusLabel === 'Compliant',
                                        'bg-error-container text-on-error-container': row.statusLabel === 'Needs Attention',
                                    }"
                                >
                                    {{ row.statusLabel }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-space-md">
                <div>
                    <h2 class="font-title-lg text-title-lg text-primary font-bold">Recurring Pattern Alerts</h2>
                    <p class="font-body-sm text-body-sm text-outline">Same department + incident type, {{ 3 }}+ times in the last 90 days. A grouped count, not an AI-generated inference.</p>
                </div>
                <div v-if="!recurringPatterns.length" class="text-center font-body-sm text-body-sm text-outline p-space-md">
                    No recurring patterns detected in this window.
                </div>
                <div v-for="pattern in recurringPatterns" :key="`${pattern.departmentName}-${pattern.incidentTypeName}`" class="p-3 rounded-lg bg-surface-container-low flex items-center justify-between">
                    <div class="flex flex-col">
                        <span class="font-title-sm text-title-sm text-on-surface font-semibold">{{ pattern.incidentTypeName }}</span>
                        <span class="font-body-sm text-body-sm text-on-surface-variant">{{ pattern.departmentName }}</span>
                    </div>
                    <span class="px-3 py-1 rounded-full bg-error-container text-on-error-container font-label-sm text-body-sm font-semibold">
                        {{ pattern.incidentCount }} incidents
                    </span>
                </div>
            </div>
```

Add the import at the top of the `<script setup>` block:

```js
import HourlyVolumeChart from '@/Components/Analytics/HourlyVolumeChart.vue';
```

- [ ] **Step 3: Build frontend assets**

```bash
cd C:\wamp64\projects\incident-report
npm run build
```

Expected: no errors, no unused-prop warnings now that all five props are rendered.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Components/Analytics/HourlyVolumeChart.vue resources/js/Pages/Analytics/Index.vue
git commit -m "feat: add department safety table, hourly volume chart, and recurring patterns to Analytics/Index.vue"
```

---

### Task 8: Wire the sidebar link

**Files:**
- Modify: `resources/js/Layouts/AuthenticatedLayout.vue`

- [ ] **Step 1: Point "Executive Overview" at the real route**

In `resources/js/Layouts/AuthenticatedLayout.vue`'s `navGroups` array, change:

```js
            { label: 'Executive Overview', icon: 'chart-line', href: '#', count: null },
```

to:

```js
            { label: 'Executive Overview', icon: 'chart-line', href: '/analytics', count: null },
```

The other three "Analytics & Learning" items (`Trends & Sentinels`, `Unit & Severity Heatmap`, `Resolution Times`) stay `href: '#'` per this plan's scope decision 2. This app's sidebar has no active-link highlighting for any item (confirmed by reading the rest of the file - every nav item, wired or not, renders via a plain `<a>` with no current-route class binding), so nothing else needs to change for this link to behave consistently with its already-wired siblings (`All Incidents`, `My Reports`, `Draft Reports`).

- [ ] **Step 2: Build frontend assets**

```bash
cd C:\wamp64\projects\incident-report
npm run build
```

Expected: no errors.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Layouts/AuthenticatedLayout.vue
git commit -m "feat: link the Executive Overview sidebar item to /analytics"
```

---

### Task 9: Final holistic review and verification

**Files:** none (verification-only task).

- [ ] **Step 1: Run the full backend test suite**

```bash
php artisan test
```

Expected: all green — every prior phase's tests plus all new Phase 8 tests.

- [ ] **Step 2: Holistic cross-task code review**

Read across the full diff for this phase (`git log --oneline <first-Phase-8-commit>..HEAD`), not just each task's own delta. Specifically check:

1. **Department scoping correctness end-to-end** — confirm a Supervisor/DepartmentHead with `department_id === null` gets an empty (not hospital-wide) result set from every `AnalyticsService` method, the same way `Incident::scopeVisibleTo()`'s own null-department guard already prevents a null-department Supervisor from matching null-department incidents (the exact bug Phase 3's own holistic review caught in `scopeVisibleTo()` itself - confirm this phase doesn't reintroduce an equivalent gap anywhere a raw `department_id` is used directly, e.g. in `departmentSafety()`'s `whereNotNull('department_id')` chain).
2. **Division-by-zero / null-data guards** — re-verify every ratio (`capaAdoptionRate`, `sentinelRecurrenceRate`, `departmentSafety`'s per-department index, `nearMissVelocity`) degrades to a sensible value (`null` or `0`/`100` as documented) rather than throwing or returning `NAN`/`INF` when its denominator is zero, across all four. Add a test for any case not already covered.
3. **`recurringPatterns()`'s `havingRaw`/`orderByDesc` on an aliased aggregate column** — MySQL allows ordering by a `SELECT`-aliased column in the same query; confirm this actually works against this project's real MySQL connection (not just SQLite, if the test suite's `.env.testing` uses a different driver - check `phpunit.xml`/`.env.testing` for the configured `DB_CONNECTION`) by re-running `test_recurring_patterns_only_lists_groups_at_or_above_the_threshold` and confirming its exact SQL via `DB::listen()` or `->toSql()` if there's any doubt.
4. **Root-cause distribution double-counting** — an incident with 2 contributing factors in the *same* category should count once for that category, not twice; confirm `COUNT(DISTINCT incident_contributing_factor.incident_id)` in `rootCauseDistribution()` actually prevents this with a test if one doesn't already cover it (the existing test uses 2 *different*-category factors, which doesn't exercise the same-category case).
5. Re-run `php artisan test` and `npm run build` yourself — don't just trust individual task reports.

- [ ] **Step 3: Browser verification**

A headless Chromium (Playwright) has been available and used for Phases 6 & 7's own final verification. Seed a handful of incidents across 2+ departments and severities via `php artisan tinker` (through review/investigation/CAPA to a mix of statuses - a full walkthrough isn't necessary, just enough real data for each KPI/chart/table to render something other than "No data yet"), then drive the browser: log in as a QSO/Administrator/Management user and confirm all five sections render with real numbers and zero console errors; log in as a Supervisor/DepartmentHead scoped to one of the seeded departments and confirm the KPIs/table only reflect that department; log in as Staff and confirm `/analytics` returns a 403 (both via a direct visit and by confirming the sidebar link, while still visible, correctly leads to the same 403 rather than a client-side-only hidden state). Screenshot the fully-rendered dashboard as evidence.

- [ ] **Step 4: Update `docs/architecture.md`**

Add a `§9i` entry (following the `§9e`-`§9h` pattern) documenting: what shipped, the confirmed scope decisions from this plan's header (dropped fabricated elements, one-page scope, access model, chart-form choices, the Safety Index/recurring-pattern heuristics being this project's own invented definitions), any bugs the holistic review caught, and the browser verification outcome.

- [ ] **Step 5: Update memory**

Per the standing instruction, update `project_state.md` and `MEMORY.md` in `C:\Users\DOH\.claude\projects\c--wamp64-projects-incident-report\memory\` with a "Phase 8 (Analytics) complete" entry. Note in `feedback_architecture.md` that this phase used a deliberately *leaner* slice of the layered pattern (Service + reused Policy only, no DTO/Repository/Action/Resource, since the phase is 100% read-only) - worth flagging to the user as an observation, not necessarily a question, since the scope note in this plan's own header already explains why.

- [ ] **Step 6: Final commit**

```bash
git add docs/architecture.md
git commit -m "docs: mark Phase 8 (Analytics) plan complete, document in architecture.md"
```
