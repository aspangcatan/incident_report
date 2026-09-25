<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/** People who assess/review an incident: QSO/Admin, plus Supervisors and Department Heads of its department. */
final class IncidentReviewers
{
    public static function for(Incident $incident): Collection
    {
        return User::active()->where(function ($query) use ($incident) {
            $query->withRole([Role::QualitySafetyOfficer, Role::Administrator]);

            if ($incident->department_id !== null) {
                $query->orWhere(function ($query) use ($incident) {
                    $query->withRole([Role::Supervisor, Role::DepartmentHead])
                        ->where('section', $incident->department_id);
                });
            }
        })->get();
    }

    public static function departmentHeads(Incident $incident): Collection
    {
        if ($incident->department_id === null) {
            return new Collection();
        }

        return User::active()->withRole(Role::DepartmentHead)->where('section', $incident->department_id)->get();
    }
}
