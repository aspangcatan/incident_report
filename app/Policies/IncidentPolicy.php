<?php

namespace App\Policies;

use App\Enums\ApprovalStatus;
use App\Enums\EvidenceStage;
use App\Enums\IncidentStatus;
use App\Enums\Role;
use App\Enums\Severity;
use App\Models\Approval;
use App\Models\Incident;
use App\Models\User;

class IncidentPolicy
{
    /** The IT/System Administrator is technical only: no incident lists beyond their own reports, unless they head a section. */
    public function viewAny(User $user): bool
    {
        return ! in_array($user->role, [Role::Staff, Role::Administrator], true) || $user->isDepartmentHead();
    }

    public function viewAnalytics(User $user): bool
    {
        return in_array($user->role, [
            Role::QualitySafetyOfficer,
            Role::Management,
            Role::CqiCommittee,
            Role::Leadership,
            Role::Supervisor,
        ], true) || $user->isDepartmentHead();
    }

    public function view(User $user, Incident $incident): bool
    {
        if ($incident->reporter_id === $user->id) {
            return true;
        }

        if ($incident->status === IncidentStatus::Draft) {
            return false;
        }

        // Department Assessment: anyone in the incident's department can open it to fill in actions/recommendations.
        if ($incident->status === IncidentStatus::Submitted
            && $user->department_id !== null
            && $incident->department_id === $user->department_id) {
            return true;
        }

        if ($incident->assigned_investigator_id === $user->id) {
            return true;
        }

        // People doing the work elsewhere in the lifecycle can open the incident.
        if ($incident->investigation?->teamMembers()->where('user_id', $user->id)->exists()) {
            return true;
        }

        if ($incident->correctiveActions()->where('responsible_user_id', $user->id)->exists()) {
            return true;
        }

        if ($user->role->seesAllIncidents()) {
            return true;
        }

        if ($user->isHeadOf($incident->department_id)) {
            return true;
        }

        if ($user->role === Role::Supervisor) {
            return $incident->department_id !== null && $incident->department_id === $user->department_id;
        }

        if ($user->role === Role::Leadership) {
            return in_array($incident->department_id, $user->leadershipDepartmentIds(), true);
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

    /**
     * Evidence after reporting: the department during its assessment, the
     * investigation team during the investigation. (CAPA proof is added when
     * completing a CAPA; the reporter adds files on the report form.)
     */
    public function evidenceStage(User $user, Incident $incident): ?EvidenceStage
    {
        if ($incident->status === IncidentStatus::Submitted && $this->assess($user, $incident)) {
            return EvidenceStage::Assessment;
        }

        // Queried, not $incident->investigation: that would cache the relation and leak it
        // into the incident page's `incident` prop (see IncidentController::show()).
        $investigation = $incident->status === IncidentStatus::UnderInvestigation ? $incident->investigation()->first() : null;
        if ($investigation && $user->can('recordFindings', $investigation)) {
            return EvidenceStage::Investigation;
        }

        return null;
    }

    public function addEvidence(User $user, Incident $incident): bool
    {
        return $this->evidenceStage($user, $incident) !== null;
    }

    /** CQI triage: confirm or change the severity, or return to the department. */
    public function review(User $user, Incident $incident): bool
    {
        return $incident->status === IncidentStatus::ForReview && $this->isQualityStaff($user);
    }

    /** The department's suggestion for who should investigate, made during assessment. */
    public function recommendInvestigator(User $user, Incident $incident): bool
    {
        if ($incident->status !== IncidentStatus::Submitted) {
            return false;
        }

        if ($this->isQualityStaff($user)) {
            return true;
        }

        return ($user->role === Role::Supervisor && $user->department_id !== null && $incident->department_id === $user->department_id)
            || $user->isHeadOf($incident->department_id);
    }

    /** Only the CQI Office, and never for High, Critical or Sentinel incidents. */
    public function skipInvestigation(User $user, Incident $incident): bool
    {
        return $incident->status === IncidentStatus::Reviewed
            && $this->isQualityStaff($user)
            && in_array($incident->severity, [Severity::Level1Low, Severity::Level2Moderate], true);
    }

    public function assess(User $user, Incident $incident): bool
    {
        if ($incident->status !== IncidentStatus::Submitted) {
            return false;
        }

        if ($this->isQualityStaff($user)) {
            return true;
        }

        return ($user->department_id !== null && $incident->department_id === $user->department_id) || $user->isHeadOf($incident->department_id);
    }

    /** Set severity, complete the assessment, or return the report to the reporter. */
    public function completeAssessment(User $user, Incident $incident): bool
    {
        if ($incident->status !== IncidentStatus::Submitted) {
            return false;
        }

        if ($this->isQualityStaff($user)) {
            return true;
        }

        return $user->isHeadOf($incident->department_id);
    }

    /** Guests have no account, so their reports can't go back to them as a draft. */
    public function returnToReporter(User $user, Incident $incident): bool
    {
        return $incident->reporter_id !== null && $this->completeAssessment($user, $incident);
    }

    public function changeDepartment(User $user, Incident $incident): bool
    {
        return $incident->status === IncidentStatus::Submitted && $this->isQualityStaff($user);
    }

    public function assign(User $user, Incident $incident): bool
    {
        return $incident->status === IncidentStatus::Reviewed && $this->isQualityStaff($user);
    }

    /** Only the assigned investigator starts the investigation. */
    public function start(User $user, Incident $incident): bool
    {
        return $incident->status === IncidentStatus::Assigned
            && $incident->assigned_investigator_id === $user->id;
    }

    /** The incident's department runs the CAPA stage, so its Department Head asks for closure - once the actions proved effective. */
    public function requestApproval(User $user, Incident $incident): bool
    {
        if ($incident->status !== IncidentStatus::Verified || $incident->effectiveness_result !== 'effective') {
            return false;
        }

        return $this->isHeadOfIncidentDepartment($user, $incident);
    }

    /** After the waiting period, the CQI Committee confirms whether the actions worked. */
    public function checkEffectiveness(User $user, Incident $incident): bool
    {
        return $incident->status === IncidentStatus::Verified
            && $incident->effectiveness_result !== 'effective'
            && $incident->effectiveness_due_at !== null
            && $incident->effectiveness_due_at->isPast()
            && $user->role === Role::CqiCommittee;
    }

    /** Sentinel Event Pathway: the department's Head or Focal Person confirms records/equipment/evidence were preserved. */
    public function confirmEvidencePreserved(User $user, Incident $incident): bool
    {
        return $incident->is_sentinel_event
            && $incident->evidence_preserved_at === null
            && $incident->status !== IncidentStatus::Closed
            && (($user->role === Role::Supervisor && $incident->department_id !== null && $incident->department_id === $user->department_id) || $user->isHeadOf($incident->department_id));
    }

    public function markNoCorrectiveActionNeeded(User $user, Incident $incident): bool
    {
        if ($incident->status !== IncidentStatus::CorrectiveAction) {
            return false;
        }

        if ($incident->correctiveActions()->exists()) {
            return false;
        }

        return $this->isHeadOfIncidentDepartment($user, $incident);
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

    /** The Patient Safety/CQI Office. */
    private function isQualityStaff(User $user): bool
    {
        return $user->role === Role::QualitySafetyOfficer;
    }

    private function isHeadOfIncidentDepartment(User $user, Incident $incident): bool
    {
        return $user->isHeadOf($incident->department_id);
    }

    /**
     * Closure approval is the independent check on the department's CAPA
     * work: the CQI Office decides the first stage, the CQI Committee the
     * second (High, Critical, Sentinel only) - never the incident's own department.
     * The specific user who requested *this* Approval row is excluded even
     * if their role would otherwise qualify, mirroring
     * CorrectiveActionPolicy::verify()'s never-self-verification check.
     * Checking $approval->requested_by directly (rather than re-querying
     * "the" pending approval on the incident) is both correct and cheap -
     * $approval is already the exact row callers are deciding on.
     */
    private function hasApprovalAuthority(User $user, Incident $incident, Approval $approval): bool
    {
        if ($user->role !== $approval->stage->deciderRole()) {
            return false;
        }

        return $approval->requested_by !== $user->id;
    }
}
