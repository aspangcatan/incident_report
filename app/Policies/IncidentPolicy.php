<?php

namespace App\Policies;

use App\Enums\IncidentStatus;
use App\Enums\Role;
use App\Models\Incident;
use App\Models\User;

class IncidentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role !== Role::Staff;
    }

    public function view(User $user, Incident $incident): bool
    {
        if ($incident->reporter_id === $user->id) {
            return true;
        }

        if ($incident->status === IncidentStatus::Draft) {
            return false;
        }

        if ($incident->assigned_investigator_id === $user->id) {
            return true;
        }

        if (in_array($user->role, [Role::QualitySafetyOfficer, Role::Administrator, Role::Management], true)) {
            return true;
        }

        if (in_array($user->role, [Role::Supervisor, Role::DepartmentHead], true)) {
            return $incident->department_id !== null && $incident->department_id === $user->department_id;
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $user->is_active;
    }

    public function update(User $user, Incident $incident): bool
    {
        return $incident->reporter_id === $user->id && $incident->status === IncidentStatus::Draft;
    }

    public function delete(User $user, Incident $incident): bool
    {
        return $this->update($user, $incident);
    }

    public function review(User $user, Incident $incident): bool
    {
        if (! in_array($incident->status, [IncidentStatus::Submitted, IncidentStatus::ForReview], true)) {
            return false;
        }

        return $this->hasReviewOrAssignAccess($user, $incident);
    }

    public function assign(User $user, Incident $incident): bool
    {
        if ($incident->status !== IncidentStatus::Reviewed) {
            return false;
        }

        return $this->hasReviewOrAssignAccess($user, $incident);
    }

    public function start(User $user, Incident $incident): bool
    {
        if ($incident->status !== IncidentStatus::Assigned) {
            return false;
        }

        if (in_array($user->role, [Role::QualitySafetyOfficer, Role::Administrator], true)) {
            return true;
        }

        return $incident->assigned_investigator_id === $user->id;
    }

    private function hasReviewOrAssignAccess(User $user, Incident $incident): bool
    {
        if (in_array($user->role, [Role::QualitySafetyOfficer, Role::Administrator], true)) {
            return true;
        }

        if (in_array($user->role, [Role::Supervisor, Role::DepartmentHead], true)) {
            return $incident->department_id !== null && $incident->department_id === $user->department_id;
        }

        return false;
    }
}
