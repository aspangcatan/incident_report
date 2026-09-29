<?php

namespace App\Services;

use App\DataTransferObjects\Approvals\DecideApprovalData;
use App\DataTransferObjects\Approvals\MarkNoCorrectiveActionNeededData;
use App\Enums\ApprovalStage;
use App\Enums\ApprovalStatus;
use App\Enums\IncidentStatus;
use App\Enums\Role;
use App\Enums\Severity;
use App\Models\Approval;
use App\Models\Incident;
use App\Models\User;
use App\Notifications\CommitteeSignOffNeededNotification;
use App\Repositories\ApprovalRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class ApprovalService
{
    public function __construct(private ApprovalRepository $approvals)
    {
    }

    public function requestApproval(Incident $incident, User $requester, ?string $lessonsLearned = null): Approval
    {
        return $this->createPendingApproval($incident, $requester, null, $lessonsLearned);
    }

    public function markNoCorrectiveActionNeeded(Incident $incident, User $requester, MarkNoCorrectiveActionNeededData $data): Approval
    {
        return $this->createPendingApproval($incident, $requester, $data->justification, $data->lessonsLearned);
    }

    public function approve(Approval $approval, User $approver, DecideApprovalData $data): Approval
    {
        return DB::transaction(function () use ($approval, $approver, $data) {
            $approval = $this->approvals->update($approval, [
                'status' => ApprovalStatus::Approved,
                'approver_id' => $approver->id,
                'decision_comments' => $data->comments,
                'decided_at' => now(),
            ]);

            $incident = $approval->incident;

            if ($approval->stage === ApprovalStage::CqiOffice && filled($data->lessonsLearned)) {
                $incident->lessons_learned = $data->lessonsLearned;
            }

            // High/Sentinel: the CQI Office's approval hands over to the Committee.
            if ($approval->stage === ApprovalStage::CqiOffice && $this->needsCommitteeSignOff($incident)) {
                $this->approvals->create([
                    'incident_id' => $incident->id,
                    'stage' => ApprovalStage::Committee,
                    'requested_by' => $approver->id,
                    'status' => ApprovalStatus::Pending,
                    'due_at' => now()->addHours(config('incident_workflow.approval_sla_hours.' . $incident->severity->value, 72)),
                ]);
                $incident->auditComment = "Approved by the CQI Office: {$data->comments}. Awaiting CQI Committee sign-off.";
                $incident->save();

                $committee = User::active()->withRole(Role::CqiCommittee)->get();
                if ($committee->isNotEmpty()) {
                    Notification::send($committee, new CommitteeSignOffNeededNotification($incident));
                }

                return $approval;
            }

            $incident->auditComment = "Approved for closure: {$data->comments}";
            $incident->status = IncidentStatus::Closed;
            $incident->lessons_published_at = $incident->lessons_learned ? now() : null;
            $incident->closed_by = $approver->id;
            $incident->closed_at = now();
            $incident->save();

            return $approval;
        });
    }

    public function returnForRevision(Approval $approval, User $approver, DecideApprovalData $data): Approval
    {
        return DB::transaction(function () use ($approval, $approver, $data) {
            $approval = $this->approvals->update($approval, [
                'status' => ApprovalStatus::Returned,
                'approver_id' => $approver->id,
                'decision_comments' => $data->comments,
                'decided_at' => now(),
            ]);

            $incident = $approval->incident;
            $incident->auditComment = "Returned for revision: {$data->comments}";
            $incident->status = IncidentStatus::CorrectiveAction;
            $incident->save();

            return $approval;
        });
    }

    private function needsCommitteeSignOff(Incident $incident): bool
    {
        return in_array($incident->severity, [Severity::Level3High, Severity::Level4CriticalSentinel], true);
    }

    /**
     * Both public "request" methods funnel here — the DB operations are
     * identical either way (create a pending Approval row, move the
     * incident to ForApproval); only the human-readable audit description
     * differs. Which source status is actually allowed (Verified vs
     * CorrectiveAction-with-zero-CAPAs) is IncidentPolicy's job, not this
     * Service's — the same division of responsibility every prior phase's
     * Service/Policy pair already uses.
     */
    private function createPendingApproval(Incident $incident, User $requester, ?string $justification, ?string $lessonsLearned = null): Approval
    {
        return DB::transaction(function () use ($incident, $requester, $justification, $lessonsLearned) {
            if ($lessonsLearned !== null) {
                $incident->lessons_learned = $lessonsLearned;
            }

            $approval = $this->approvals->create([
                'incident_id' => $incident->id,
                'stage' => ApprovalStage::CqiOffice,
                'requested_by' => $requester->id,
                'request_comments' => $justification,
                'status' => ApprovalStatus::Pending,
                'due_at' => now()->addHours(
                    config('incident_workflow.approval_sla_hours.' . $incident->severity->value, 72)
                ),
            ]);

            $incident->auditComment = $justification
                ? "No corrective action required: {$justification}"
                : 'Corrective action(s) verified; requesting closure approval.';
            $incident->status = IncidentStatus::ForApproval;
            $incident->save();

            return $approval;
        });
    }
}
