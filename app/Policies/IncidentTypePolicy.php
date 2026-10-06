<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\IncidentType;
use App\Models\User;

/** Incident type settings are technical configuration: IT/System Admin only. */
class IncidentTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === Role::Administrator;
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, IncidentType $type): bool
    {
        return $this->viewAny($user);
    }

    /** A type that reports already use can only be switched off, never deleted. */
    public function delete(User $user, IncidentType $type): bool
    {
        return $this->viewAny($user) && ! $type->isInUse();
    }
}
