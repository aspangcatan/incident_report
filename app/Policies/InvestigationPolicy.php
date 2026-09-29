<?php

namespace App\Policies;

use App\Enums\InvestigationStatus;
use App\Enums\Role;
use App\Models\Investigation;
use App\Models\User;

class InvestigationPolicy
{
    public function manageTeam(User $user, Investigation $investigation): bool
    {
        return $this->hasLeadAccess($user, $investigation);
    }

    public function recordFindings(User $user, Investigation $investigation): bool
    {
        if ($investigation->status !== InvestigationStatus::InProgress) {
            return false;
        }

        if ($this->hasLeadAccess($user, $investigation)) {
            return true;
        }

        return $investigation->teamMembers()->where('user_id', $user->id)->exists();
    }

    public function complete(User $user, Investigation $investigation): bool
    {
        if ($investigation->status !== InvestigationStatus::InProgress) {
            return false;
        }

        return $this->hasLeadAccess($user, $investigation);
    }

    private function hasLeadAccess(User $user, Investigation $investigation): bool
    {
        if ($user->role === Role::QualitySafetyOfficer) {
            return true;
        }

        return $investigation->lead_investigator_id === $user->id;
    }
}
