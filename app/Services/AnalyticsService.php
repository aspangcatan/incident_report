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

        return round($investigations->avg(fn (Investigation $i) => $i->started_at->diffInHours($i->completed_at) / 24), 1);
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
