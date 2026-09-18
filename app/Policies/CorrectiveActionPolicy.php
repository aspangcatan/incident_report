<?php

namespace App\Policies;

use App\Enums\CorrectiveActionStatus;
use App\Enums\IncidentStatus;
use App\Enums\Role;
use App\Models\CorrectiveAction;
use App\Models\Incident;
use App\Models\User;

class CorrectiveActionPolicy
{
    public function create(User $user, Incident $incident): bool
    {
        if ($incident->status !== IncidentStatus::CorrectiveAction) {
            return false;
        }

        return $this->isQualityStaff($user);
    }

    public function update(User $user, CorrectiveAction $correctiveAction): bool
    {
        if (in_array($correctiveAction->status, [CorrectiveActionStatus::ForVerification, CorrectiveActionStatus::Verified], true)) {
            return false;
        }

        return $this->isQualityStaff($user);
    }

    public function progress(User $user, CorrectiveAction $correctiveAction): bool
    {
        if ($correctiveAction->status !== CorrectiveActionStatus::Open) {
            return false;
        }

        return $this->hasResponsibleAccess($user, $correctiveAction);
    }

    public function complete(User $user, CorrectiveAction $correctiveAction): bool
    {
        if (! in_array($correctiveAction->status, [CorrectiveActionStatus::Open, CorrectiveActionStatus::InProgress], true)) {
            return false;
        }

        return $this->hasResponsibleAccess($user, $correctiveAction);
    }

    public function verify(User $user, CorrectiveAction $correctiveAction): bool
    {
        if ($correctiveAction->status !== CorrectiveActionStatus::ForVerification) {
            return false;
        }

        if ($correctiveAction->completed_by !== null && $user->id === $correctiveAction->completed_by) {
            return false;
        }

        return in_array($user->role, [Role::Supervisor, Role::DepartmentHead, Role::QualitySafetyOfficer, Role::Administrator], true);
    }

    private function isQualityStaff(User $user): bool
    {
        return in_array($user->role, [Role::QualitySafetyOfficer, Role::Administrator], true);
    }

    private function hasResponsibleAccess(User $user, CorrectiveAction $correctiveAction): bool
    {
        if ($this->isQualityStaff($user)) {
            return true;
        }

        return $correctiveAction->responsible_user_id === $user->id;
    }
}
