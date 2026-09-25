<?php

namespace App\Policies;

use App\Enums\CorrectiveActionStatus;
use App\Enums\IncidentStatus;
use App\Enums\Role;
use App\Models\CorrectiveAction;
use App\Models\Incident;
use App\Models\User;

/**
 * The incident's department runs the CAPA stage: its Department Head creates
 * and edits CAPAs (assigning each to a staff member), the assigned
 * responsible person does the work, and a Supervisor/Department Head of the
 * incident's department verifies it - never the person who completed it.
 * The Quality office (QSO/Admin) does not act here; it comes in at closure
 * approval (IncidentPolicy::approveClosure()).
 */
class CorrectiveActionPolicy
{
    public function create(User $user, Incident $incident): bool
    {
        if ($incident->status !== IncidentStatus::CorrectiveAction) {
            return false;
        }

        return $this->isHeadOfIncidentDepartment($user, $incident);
    }

    public function update(User $user, CorrectiveAction $correctiveAction): bool
    {
        if (in_array($correctiveAction->status, [CorrectiveActionStatus::ForVerification, CorrectiveActionStatus::Verified], true)) {
            return false;
        }

        return $this->isHeadOfIncidentDepartment($user, $correctiveAction->incident);
    }

    public function progress(User $user, CorrectiveAction $correctiveAction): bool
    {
        if ($correctiveAction->status !== CorrectiveActionStatus::Open) {
            return false;
        }

        return $correctiveAction->responsible_user_id === $user->id;
    }

    public function complete(User $user, CorrectiveAction $correctiveAction): bool
    {
        if (! in_array($correctiveAction->status, [CorrectiveActionStatus::Open, CorrectiveActionStatus::InProgress], true)) {
            return false;
        }

        return $correctiveAction->responsible_user_id === $user->id;
    }

    public function verify(User $user, CorrectiveAction $correctiveAction): bool
    {
        if ($correctiveAction->status !== CorrectiveActionStatus::ForVerification) {
            return false;
        }

        if ($correctiveAction->completed_by !== null && $user->id === $correctiveAction->completed_by) {
            return false;
        }

        return in_array($user->role, [Role::Supervisor, Role::DepartmentHead], true)
            && $this->belongsToIncidentDepartment($user, $correctiveAction->incident);
    }

    private function isHeadOfIncidentDepartment(User $user, Incident $incident): bool
    {
        return $user->role === Role::DepartmentHead && $this->belongsToIncidentDepartment($user, $incident);
    }

    private function belongsToIncidentDepartment(User $user, Incident $incident): bool
    {
        return $incident->department_id !== null && $user->department_id === $incident->department_id;
    }
}
