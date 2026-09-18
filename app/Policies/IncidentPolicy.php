<?php

namespace App\Policies;

use App\Enums\ApprovalStatus;
use App\Enums\IncidentStatus;
use App\Enums\Role;
use App\Models\Approval;
use App\Models\Incident;
use App\Models\User;

class IncidentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role !== Role::Staff;
    }

    public function viewAnalytics(User $user): bool
    {
        return in_array($user->role, [
            Role::QualitySafetyOfficer,
            Role::Administrator,
            Role::Management,
            Role::Supervisor,
            Role::DepartmentHead,
        ], true);
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

        if ($this->isQualityStaff($user)) {
            return true;
        }

        return $incident->assigned_investigator_id === $user->id;
    }

    private function hasReviewOrAssignAccess(User $user, Incident $incident): bool
    {
        if ($this->isQualityStaff($user)) {
            return true;
        }

        if (in_array($user->role, [Role::Supervisor, Role::DepartmentHead], true)) {
            return $incident->department_id !== null && $incident->department_id === $user->department_id;
        }

        return false;
    }

    public function requestApproval(User $user, Incident $incident): bool
    {
        if ($incident->status !== IncidentStatus::Verified) {
            return false;
        }

        return $this->isQualityStaff($user);
    }

    public function markNoCorrectiveActionNeeded(User $user, Incident $incident): bool
    {
        if ($incident->status !== IncidentStatus::CorrectiveAction) {
            return false;
        }

        if ($incident->correctiveActions()->exists()) {
            return false;
        }

        return $this->isQualityStaff($user);
    }

    /**
     * Takes the specific Approval row, not just the incident, precisely so
     * this can't be satisfied by an incident that's currently ForApproval
     * but via a *different*, already-decided Approval row (e.g. a
     * previously-Returned row from an earlier resubmission cycle) - only
     * that row's own Pending status makes it the one actually up for
     * decision. Called as $user->can('approveClosure', [$incident, $approval]).
     */
    public function approveClosure(User $user, Incident $incident, Approval $approval): bool
    {
        if ($incident->status !== IncidentStatus::ForApproval) {
            return false;
        }

        if ($approval->status !== ApprovalStatus::Pending) {
            return false;
        }

        return $this->hasApprovalAuthority($user, $incident, $approval);
    }

    public function returnFromApproval(User $user, Incident $incident, Approval $approval): bool
    {
        if ($incident->status !== IncidentStatus::ForApproval) {
            return false;
        }

        if ($approval->status !== ApprovalStatus::Pending) {
            return false;
        }

        return $this->hasApprovalAuthority($user, $incident, $approval);
    }

    private function isQualityStaff(User $user): bool
    {
        return in_array($user->role, [Role::QualitySafetyOfficer, Role::Administrator], true);
    }

    /**
     * Management/Administrator approve hospital-wide; a DepartmentHead is
     * scoped to their own department, same convention as
     * hasReviewOrAssignAccess() above. Either way, the specific user who
     * requested *this* Approval row is excluded, even if their role would
     * otherwise qualify - mirrors CorrectiveActionPolicy::verify()'s
     * never-self-verification check, checked by user id, not just role,
     * for the same reason (an Administrator can both request and
     * ordinarily approve, so role alone isn't a strong enough guard).
     * Checking $approval->requested_by directly (rather than re-querying
     * "the" pending approval on the incident) is both correct and cheap -
     * $approval is already the exact row callers are deciding on.
     */
    private function hasApprovalAuthority(User $user, Incident $incident, Approval $approval): bool
    {
        if (in_array($user->role, [Role::Management, Role::Administrator], true)) {
            return $approval->requested_by !== $user->id;
        }

        if ($user->role === Role::DepartmentHead) {
            if ($incident->department_id === null || $incident->department_id !== $user->department_id) {
                return false;
            }

            return $approval->requested_by !== $user->id;
        }

        return false;
    }
}
