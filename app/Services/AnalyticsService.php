<?php

namespace App\Services;

use App\Enums\CorrectiveActionStatus;
use App\Enums\IncidentStatus;
use App\Enums\Severity;
use App\Models\Approval;
use App\Models\CorrectiveAction;
use App\Models\Department;
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
            'rootCauseDistribution' => $this->rootCauseDistribution($user),
            'departmentSafety' => $this->departmentSafety($user),
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

        return Department::query()
            ->whereIn('id', $departmentIds)
            ->orderBy('name')
            ->get()
            ->map(function (Department $department) {
                // Not routed through baseQuery()/visibleTo() here: $department
                // is already one of $departmentIds, which was itself derived
                // from baseQuery($user) above, so this can never reach a
                // department the caller isn't allowed to see. Draft incidents
                // are also safe to leave in this particular id set (unlike
                // hourlyVolume()) since a draft can never have a
                // CorrectiveAction/Approval row pointing at it - the counts
                // below would be identical either way.
                $incidentIds = Incident::where('department_id', $department->id)->pluck('id');

                $capasTotal = CorrectiveAction::whereIn('incident_id', $incidentIds)->count();
                $capasVerified = CorrectiveAction::whereIn('incident_id', $incidentIds)
                    ->where('status', CorrectiveActionStatus::Verified->value)
                    ->count();
                $capasOverdue = CorrectiveAction::whereIn('incident_id', $incidentIds)->overdue()->count();

                $approvalsTotal = Approval::whereIn('incident_id', $incidentIds)->count();
                $approvalsOverdue = Approval::whereIn('incident_id', $incidentIds)->overdue()->count();

                $everCreated = $capasTotal + $approvalsTotal;
                $everOverdue = $capasOverdue + $approvalsOverdue;
                $safetyIndex = $everCreated > 0 ? (int) round(100 * (1 - $everOverdue / $everCreated)) : 100;

                return [
                    'departmentId' => $department->id,
                    'departmentName' => $department->name,
                    'capasVerified' => $capasVerified,
                    'capasTotal' => $capasTotal,
                    'safetyIndex' => $safetyIndex,
                    'statusLabel' => match (true) {
                        $safetyIndex >= 95 => 'Exemplary',
                        $safetyIndex >= 85 => 'Optimal',
                        $safetyIndex >= 70 => 'Compliant',
                        default => 'Needs Attention',
                    },
                ];
            })
            ->all();
    }
}
