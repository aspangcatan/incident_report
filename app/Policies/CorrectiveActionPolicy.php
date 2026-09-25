<?php

namespace App\Policies;

use App\Enums\CorrectiveActionStatus;
use App\Enums\IncidentStatus;
use App\Enums\Role;
use App\Models\CorrectiveAction;
use App\Models\Incident;
use App\Models\User;

/**
 * The incident's department owns its CAPAs (its Department Head creates and
 * edits them, its staff do the work); the Quality office (QSO/Admin) keeps
 * independent oversight and can act on any CAPA. Verification is by QSO/Admin
 * or a Supervisor/Department Head of the incident's department, never by the
 * person who completed it.
 */
class CorrectiveActionPolicy
{
    public function create(User $user, Incident $incident): bool
    {
        if ($incident->status !== IncidentStatus::CorrectiveAction) {
            return false;
        }

        return $this->isQualityStaff($user) || $this->isHeadOfIncidentDepartment($user, $incident);
    }

    public function update(User $user, CorrectiveAction $correctiveAction): bool
    {
        if (in_array($correctiveAction->status, [CorrectiveActionStatus::ForVerification, CorrectiveActionStatus::Verified], true)) {
            return false;
        }

        return $this->isQualityStaff($user) || $this->isHeadOfIncidentDepartment($user, $correctiveAction->incident);
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

        if ($this->isQualityStaff($user)) {
            return true;
        }

        return in_array($user->role, [Role::Supervisor, Role::DepartmentHead], true)
            && $this->belongsToIncidentDepartment($user, $correctiveAction->incident);
    }

    private function isQualityStaff(User $user): bool
    {
        return in_array($user->role, [Role::QualitySafetyOfficer, Role::Administrator], true);
    }

    private function isHeadOfIncidentDepartment(User $user, Incident $incident): bool
    {
        return $user->role === Role::DepartmentHead && $this->belongsToIncidentDepartment($user, $incident);
    }

    private function belongsToIncidentDepartment(User $user, Incident $incident): bool
    {
        return $incident->department_id !== null && $user->department_id === $incident->department_id;
    }

    private function hasResponsibleAccess(User $user, CorrectiveAction $correctiveAction): bool
    {
        if ($this->isQualityStaff($user)) {
            return true;
        }

        return $correctiveAction->responsible_user_id === $user->id;
    }
}
