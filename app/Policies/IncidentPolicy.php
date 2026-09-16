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

        return in_array($user->role, [
            Role::Supervisor,
            Role::DepartmentHead,
            Role::QualitySafetyOfficer,
            Role::Administrator,
            Role::Management,
        ], true);
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
}
