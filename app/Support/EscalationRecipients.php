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
 * each severity (editable, see SeverityAlertSettings) and for overdue RCA/CAPA.
 * Callers exclude the actor themselves.
 */
final class EscalationRecipients
{
    /** The groups the IT Admin set for this level (Admin → Severity Alerts). */
    public static function forSeverity(Incident $incident, ?Severity $level = null): Collection
    {
        // $level: the system-suggested level, before the department has set one.
        $level ??= $incident->severity;

        if ($level === null) {
            return new Collection();
        }

        return self::merge(array_map(
            fn (string $recipient) => self::severityGroup($recipient, $incident),
            SeverityAlertSettings::recipientsFor($level)
        ));
    }

    /** One SeverityAlertSettings::options() key as people; department groups use the incident's department. */
    private static function severityGroup(string $recipient, Incident $incident): Collection
    {
        return match ($recipient) {
            SeverityAlertSettings::DEPARTMENT_HEAD => IncidentReviewers::departmentHeads($incident),
            Role::Leadership->value => self::leadership($incident),
            Role::Supervisor->value, Role::Staff->value => self::departmentMembers(Role::from($recipient), $incident),
            default => User::active()->withRole(Role::from($recipient))->get(),
        };
    }

    private static function departmentMembers(Role $role, Incident $incident): Collection
    {
        if ($incident->department_id === null) {
            return new Collection();
        }

        return User::active()->withRole($role)->where('section', $incident->department_id)->get();
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
        return new Collection(collect($groups)->flatten(1)->unique('id')->values()->all());
    }
}
