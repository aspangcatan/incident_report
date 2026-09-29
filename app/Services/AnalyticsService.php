<?php

namespace App\Services;

use App\Enums\CorrectiveActionStatus;
use App\Enums\IncidentStatus;
use App\Enums\RootCauseType;
use App\Enums\Severity;
use App\Models\Approval;
use App\Models\CorrectiveAction;
use App\Models\Department;
use App\Models\Incident;
use App\Models\IncidentType;
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

    /**
     * Same value as WINDOW_DAYS today, but kept as its own named constant
     * (not a reuse of WINDOW_DAYS) since a "how far back counts as a
     * recurring pattern" window is a distinct business question from "how
     * far back for a trailing KPI" and may need to move independently.
     */
    private const REPEAT_PATTERN_WINDOW_DAYS = 90;
    private const REPEAT_PATTERN_MIN_COUNT = 3;

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
            'rootCauseDistribution' => $this->rootCauseDistribution($user),
            'departmentSafety' => $this->departmentSafety($user),
            'hourlyVolume' => $this->hourlyVolume($user),
            'recurringPatterns' => $this->recurringPatterns($user),
            // Surfaced so the frontend's copy ("90 days", "3+") is
            // interpolated from these constants rather than a second,
            // hardcoded copy that would silently drift if either constant
            // ever changes (raised in Task 7's review, closed here).
            'windowDays' => self::WINDOW_DAYS,
            'recurringPatternWindowDays' => self::REPEAT_PATTERN_WINDOW_DAYS,
            'recurringPatternMinCount' => self::REPEAT_PATTERN_MIN_COUNT,
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
        // The review step only: from the department completing its assessment
        // to the review decision (the assessment itself has its own SLA).
        $rows = $this->baseQuery($user)
            ->whereNotNull('assessed_at')
            ->whereNotNull('supervisor_reviewed_at')
            ->where('reported_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->get(['assessed_at', 'supervisor_reviewed_at']);

        if ($rows->isEmpty()) {
            return null;
        }

        return round($rows->avg(fn (Incident $i) => $i->assessed_at->diffInMinutes($i->supervisor_reviewed_at) / 60), 1);
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
            ->with('incidentTypes')
            ->get(['id', 'department_id', 'reported_at']);

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
                // An incident can have several types; sharing any one counts as a repeat.
                ->whereHas('incidentTypes', fn (Builder $q) => $q->whereIn('incident_types.id', $sentinel->incidentTypes->pluck('id')))
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

    /**
     * Incidents per root-cause type: counts findings the investigator marked
     * "root cause", grouped by the cause type chosen with them (RootCauseType).
     * An incident with two Staffing root causes counts once for Staffing.
     */
    private function rootCauseDistribution(User $user): array
    {
        $visibleIncidentIds = $this->baseQuery($user)
            ->where('reported_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->pluck('id');

        return DB::table('investigation_findings')
            ->join('investigations', 'investigations.id', '=', 'investigation_findings.investigation_id')
            ->whereIn('investigations.incident_id', $visibleIncidentIds)
            ->where('investigation_findings.is_root_cause', true)
            ->whereNotNull('investigation_findings.category')
            ->groupBy('investigation_findings.category')
            ->selectRaw('investigation_findings.category as category, COUNT(DISTINCT investigations.incident_id) as incidentCount')
            ->orderByDesc('incidentCount')
            ->get()
            ->map(fn ($row) => [
                'category' => RootCauseType::tryFrom($row->category)?->label() ?? $row->category,
                'incidentCount' => (int) $row->incidentCount,
            ])
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

        $departments = Department::query()->whereIn('id', $departmentIds)->orderBy('description')->get();

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
        // An incident with several types counts once toward each of its types.
        $rows = DB::table('incident_incident_type')
            ->join('incidents', 'incidents.id', '=', 'incident_incident_type.incident_id')
            ->whereIn('incidents.id', $this->baseQuery($user)
                ->where('reported_at', '>=', now()->subDays(self::REPEAT_PATTERN_WINDOW_DAYS))
                ->whereNotNull('department_id')
                ->select('incidents.id'))
            ->groupBy('incidents.department_id', 'incident_incident_type.incident_type_id')
            ->havingRaw('COUNT(*) >= ?', [self::REPEAT_PATTERN_MIN_COUNT])
            ->orderByDesc('incidentCount')
            ->selectRaw('incidents.department_id as department_id, incident_incident_type.incident_type_id as incident_type_id, COUNT(*) as incidentCount')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $departments = Department::whereIn('id', $rows->pluck('department_id'))->pluck('description', 'id');
        $incidentTypes = IncidentType::whereIn('id', $rows->pluck('incident_type_id'))->pluck('name', 'id');

        return $rows->map(fn ($row) => [
            'departmentName' => $departments[$row->department_id] ?? 'Unknown',
            'incidentTypeName' => $incidentTypes[$row->incident_type_id] ?? 'Unknown',
            'incidentCount' => (int) $row->incidentCount,
        ])->all();
    }
}
