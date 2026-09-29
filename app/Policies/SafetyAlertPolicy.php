<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\SafetyAlert;
use App\Models\User;

class SafetyAlertPolicy
{
    /** Only the Patient Safety/CQI Office issues alerts. */
    public function create(User $user): bool
    {
        return $user->role === Role::QualitySafetyOfficer;
    }

    public function view(User $user, SafetyAlert $alert): bool
    {
        return $this->create($user) || $alert->isAddressedTo($user);
    }

    public function acknowledge(User $user, SafetyAlert $alert): bool
    {
        return $alert->isAddressedTo($user) && ! $alert->isAcknowledgedBy($user);
    }

    /** Who has and hasn't read it. */
    public function viewAcknowledgements(User $user, SafetyAlert $alert): bool
    {
        return $this->create($user);
    }
}
