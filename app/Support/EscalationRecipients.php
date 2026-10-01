<?php

namespace App\Support;

use App\Enums\CorrectiveActionPriority;
use App\Enums\Role;
use App\Enums\Severity;
use App\Models\CorrectiveAction;
use App\Models\Incident;
use App\Models\Investigation;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The client's Escalation & Notification Matrix (2026-10-01): who is told for
 * each severity and for overdue RCA/CAPA. Callers exclude the actor themselves.
 */
final class EscalationRecipients
{
    public static function forSeverity(Incident $incident): Collection
    {
        return self::merge(match ($incident->severity) {
            Severity::Level2Moderate => [IncidentReviewers::departmentHeads($incident), self::cqiOffice()],
            Severity::Level3High => [IncidentReviewers::departmentHeads($incident), self::cqiOffice(), self::leadership($incident)],
            Severity::Level4Critical, Severity::Level5Sentinel => [self::cqiOffice(), self::leadership($incident), self::executives()],
            default => [],
        });
    }

    public static function forOverdueInvestigation(Investigation $investigation): Collection
    {
        return self::merge([
            self::only($investigation->leadInvestigator),
            IncidentReviewers::departmentHeads($investigation->incident),
            self::cqiOffice(),
        ]);
    }

    public static function forOverdueCorrectiveAction(CorrectiveAction $correctiveAction): Collection
    {
        $groups = [
            self::only($correctiveAction->responsibleUser),
            IncidentReviewers::departmentHeads($correctiveAction->incident),
            self::cqiOffice(),
        ];

        if ($correctiveAction->priority === CorrectiveActionPriority::Critical) {
            $groups[] = self::executives();
        }

        return self::merge($groups);
    }

    private static function cqiOffice(): Collection
    {
        return User::active()->withRole(Role::QualitySafetyOfficer)->get();
    }

    private static function executives(): Collection
    {
        return User::active()->withRole(Role::Management)->get();
    }

    /** Medical/Nursing/Ancillary Leadership mapped to the incident's department (Admin → Leadership). */
    private static function leadership(Incident $incident): Collection
    {
        if ($incident->department_id === null) {
            return new Collection();
        }

        $ids = DB::table('leadership_departments')->where('department_id', $incident->department_id)->pluck('user_id');

        return User::active()->withRole(Role::Leadership)->whereIn('id', $ids)->get();
    }

    /** tdh_user hard-deletes users, so a stale id resolves to null. */
    private static function only(?User $user): Collection
    {
        return $user !== null && $user->is_active ? new Collection([$user]) : new Collection();
    }

    /** @param  array<Collection>  $groups */
    private static function merge(array $groups): Collection
    {
        return (new Collection(array_merge(...array_map(fn (Collection $group) => $group->all(), [new Collection(), ...$groups]))))
            ->unique('id')
            ->values();
    }
}
