<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\Department;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/** People who assess/triage an incident: the CQI Office, plus the Focal Persons and the Head of its department. */
final class IncidentReviewers
{
    public static function for(Incident $incident): Collection
    {
        $reviewers = User::active()->where(function ($query) use ($incident) {
            $query->withRole(Role::QualitySafetyOfficer);

            if ($incident->department_id !== null) {
                $query->orWhere(fn ($query) => $query->withRole(Role::Supervisor)->where('section', $incident->department_id));
            }
        })->get();

        return $reviewers->merge(self::departmentHeads($incident))->unique('id')->values();
    }

    /** The head (tdh_user.section.head) of the incident's department, if active. */
    public static function departmentHeads(Incident $incident): Collection
    {
        $headId = $incident->department_id !== null ? Department::whereKey($incident->department_id)->value('head') : null;

        return $headId ? User::active()->whereKey($headId)->get() : new Collection();
    }
}
