<?php

namespace App\Console\Commands;

use App\Enums\IncidentStatus;
use App\Models\Approval;
use App\Models\CorrectiveAction;
use App\Models\Incident;
use App\Models\Investigation;
use App\Models\User;
use App\Notifications\EffectivenessCheckDueNotification;
use App\Notifications\IncidentEscalationNotification;
use App\Queries\OverdueApprovalsQuery;
use App\Queries\OverdueCorrectiveActionsQuery;
use App\Queries\OverdueInvestigationsQuery;
use App\Repositories\ApprovalRepository;
use App\Repositories\CorrectiveActionRepository;
use App\Repositories\InvestigationRepository;
use App\Support\IncidentReviewers;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Notification;

class CheckOverdueIncidents extends Command
{
    protected $signature = 'incidents:check-overdue';

    protected $description = 'Escalate incidents that have breached their review or assignment SLA';

    public function __construct(
        private OverdueInvestigationsQuery $overdueInvestigations,
        private InvestigationRepository $investigations,
        private OverdueCorrectiveActionsQuery $overdueCorrectiveActions,
        private CorrectiveActionRepository $correctiveActions,
        private OverdueApprovalsQuery $overdueApprovals,
        private ApprovalRepository $approvals,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->notifyDueEffectivenessChecks();

        $recipients = User::active()->withRole(config('incident_workflow.escalation_recipient_roles'))->get();

        if ($recipients->isEmpty()) {
            $this->warn('No escalation recipients configured/found; skipping.');

            return self::SUCCESS;
        }

        $this->escalateOverdueAssessments($recipients);
        $this->escalateOverdueReviews($recipients);
        $this->escalateOverdueAssignments($recipients);
        $this->escalateOverdueInvestigations($recipients);
        $this->escalateOverdueCorrectiveActions($recipients);
        $this->escalateOverdueApprovals($recipients);

        return self::SUCCESS;
    }

    /** Tell the Department Head once when an effectiveness check becomes due. */
    private function notifyDueEffectivenessChecks(): void
    {
        Incident::where('status', IncidentStatus::Verified)
            ->whereNull('effectiveness_result')
            ->whereNull('effectiveness_notified_at')
            ->where('effectiveness_due_at', '<=', now())
            ->get()
            ->each(function (Incident $incident) {
                $heads = IncidentReviewers::departmentHeads($incident);
                if ($heads->isNotEmpty()) {
                    Notification::send($heads, new EffectivenessCheckDueNotification($incident));
                }
                $incident->forceFill(['effectiveness_notified_at' => now()])->saveQuietly();
            });
    }

    private function escalateOverdueAssessments(Collection $recipients): void
    {
        $hours = (int) config('incident_workflow.assessment_sla_hours', 72);

        Incident::whereNull('assessment_escalated_at')
            ->where('status', IncidentStatus::Submitted)
            ->whereNotNull('reported_at')
            ->where('reported_at', '<=', now()->subHours($hours))
            ->get()
            ->each(function (Incident $incident) use ($recipients) {
                Notification::send($recipients, new IncidentEscalationNotification($incident, 'Department assessment SLA breached'));
                $incident->forceFill(['assessment_escalated_at' => now()])->save();
            });
    }

    private function escalateOverdueReviews(Collection $recipients): void
    {
        Incident::whereNull('review_escalated_at')
            ->where('status', IncidentStatus::ForReview)
            ->whereNotNull('assessed_at')
            ->get()
            ->each(function (Incident $incident) use ($recipients) {
                $slaHours = config('incident_workflow.review_sla_hours.' . $incident->severity?->value);

                if ($slaHours === null || $incident->assessed_at->addHours($slaHours)->isFuture()) {
                    return;
                }

                Notification::send($recipients, new IncidentEscalationNotification($incident, 'Review SLA breached'));
                $incident->forceFill(['review_escalated_at' => now()])->save();
            });
    }

    private function escalateOverdueAssignments(Collection $recipients): void
    {
        Incident::whereNull('assignment_escalated_at')
            ->where('status', IncidentStatus::Assigned)
            ->whereNotNull('target_closure_date')
            ->where('target_closure_date', '<', now())
            ->get()
            ->each(function (Incident $incident) use ($recipients) {
                Notification::send($recipients, new IncidentEscalationNotification($incident, 'Assignment SLA breached'));
                $incident->forceFill(['assignment_escalated_at' => now()])->save();
            });
    }

    private function escalateOverdueInvestigations(Collection $recipients): void
    {
        $this->overdueInvestigations->get()->each(function (Investigation $investigation) use ($recipients) {
            Notification::send($recipients, new IncidentEscalationNotification($investigation->incident, 'Investigation SLA breached'));
            $this->investigations->markEscalated($investigation);
        });
    }

    private function escalateOverdueCorrectiveActions(Collection $recipients): void
    {
        $this->overdueCorrectiveActions->get()->each(function (CorrectiveAction $correctiveAction) use ($recipients) {
            Notification::send(
                $recipients,
                new IncidentEscalationNotification($correctiveAction->incident, "Corrective action {$correctiveAction->capa_number} SLA breached")
            );
            $this->correctiveActions->markEscalated($correctiveAction);
        });
    }

    private function escalateOverdueApprovals(Collection $recipients): void
    {
        $this->overdueApprovals->get()->each(function (Approval $approval) use ($recipients) {
            Notification::send(
                $recipients,
                new IncidentEscalationNotification($approval->incident, 'Closure approval SLA breached')
            );
            $this->approvals->markEscalated($approval);
        });
    }
}
