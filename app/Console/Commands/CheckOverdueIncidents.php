<?php

namespace App\Console\Commands;

use App\Enums\IncidentStatus;
use App\Enums\Role;
use App\Models\Approval;
use App\Models\CorrectiveAction;
use App\Models\Incident;
use App\Models\Investigation;
use App\Models\User;
use App\Notifications\DeadlineReminderNotification;
use App\Notifications\EffectivenessCheckDueNotification;
use App\Notifications\IncidentEscalationNotification;
use App\Queries\DueSoonCorrectiveActionsQuery;
use App\Queries\DueSoonInvestigationsQuery;
use App\Queries\OverdueApprovalsQuery;
use App\Queries\OverdueCorrectiveActionsQuery;
use App\Queries\OverdueInvestigationsQuery;
use App\Repositories\ApprovalRepository;
use App\Repositories\CorrectiveActionRepository;
use App\Repositories\InvestigationRepository;
use App\Support\EscalationRecipients;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Notification;

class CheckOverdueIncidents extends Command
{
    protected $signature = 'incidents:check-overdue';

    protected $description = 'Send deadline reminders and escalate incidents that have breached an SLA';

    public function __construct(
        private OverdueInvestigationsQuery $overdueInvestigations,
        private DueSoonInvestigationsQuery $dueSoonInvestigations,
        private InvestigationRepository $investigations,
        private OverdueCorrectiveActionsQuery $overdueCorrectiveActions,
        private DueSoonCorrectiveActionsQuery $dueSoonCorrectiveActions,
        private CorrectiveActionRepository $correctiveActions,
        private OverdueApprovalsQuery $overdueApprovals,
        private ApprovalRepository $approvals,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->notifyDueEffectivenessChecks();

        // RCA and CAPA follow the client's matrix and don't depend on a CQI user existing.
        $this->remindDueSoonInvestigations();
        $this->remindDueSoonCorrectiveActions();
        $this->escalateOverdueInvestigations();
        $this->escalateOverdueCorrectiveActions();

        $recipients = User::active()->withRole(config('incident_workflow.escalation_recipient_roles'))->get();

        if ($recipients->isEmpty()) {
            $this->warn('No escalation recipients configured/found; skipping review, assignment and approval escalations.');

            return self::SUCCESS;
        }

        $this->escalateOverdueAssessments($recipients);
        $this->escalateOverdueReviews($recipients);
        $this->escalateOverdueAssignments($recipients);
        $this->escalateOverdueApprovals($recipients);

        return self::SUCCESS;
    }

    /** Tell the CQI Committee once when an effectiveness check becomes due. */
    private function notifyDueEffectivenessChecks(): void
    {
        Incident::where('status', IncidentStatus::Verified)
            ->whereNull('effectiveness_result')
            ->whereNull('effectiveness_notified_at')
            ->where('effectiveness_due_at', '<=', now())
            ->get()
            ->each(function (Incident $incident) {
                $committee = User::active()->withRole(Role::CqiCommittee)->get();
                if ($committee->isNotEmpty()) {
                    Notification::send($committee, new EffectivenessCheckDueNotification($incident));
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

    private function remindDueSoonInvestigations(): void
    {
        $this->dueSoonInvestigations->get()->each(function (Investigation $investigation) {
            $lead = $investigation->leadInvestigator;
            if ($lead !== null && $lead->is_active) {
                $due = $investigation->target_completion_at->format('M j, Y g:i A');
                Notification::send($lead, new DeadlineReminderNotification($investigation->incident, "your investigation is due by {$due}"));
            }
            $this->investigations->markReminded($investigation);
        });
    }

    private function remindDueSoonCorrectiveActions(): void
    {
        $this->dueSoonCorrectiveActions->get()->each(function (CorrectiveAction $correctiveAction) {
            $owner = $correctiveAction->responsibleUser;
            if ($owner !== null && $owner->is_active) {
                Notification::send($owner, new DeadlineReminderNotification($correctiveAction->incident, "corrective action {$correctiveAction->capa_number} is due tomorrow"));
            }
            $this->correctiveActions->markReminded($correctiveAction);
        });
    }

    private function escalateOverdueInvestigations(): void
    {
        $this->overdueInvestigations->get()->each(function (Investigation $investigation) {
            $recipients = EscalationRecipients::forOverdueInvestigation($investigation);
            if ($recipients->isNotEmpty()) {
                Notification::send($recipients, new IncidentEscalationNotification($investigation->incident, 'Investigation SLA breached'));
            }
            $this->investigations->markEscalated($investigation);
        });
    }

    private function escalateOverdueCorrectiveActions(): void
    {
        $this->overdueCorrectiveActions->get()->each(function (CorrectiveAction $correctiveAction) {
            $recipients = EscalationRecipients::forOverdueCorrectiveAction($correctiveAction);
            if ($recipients->isNotEmpty()) {
                Notification::send(
                    $recipients,
                    new IncidentEscalationNotification($correctiveAction->incident, "Corrective action {$correctiveAction->capa_number} SLA breached")
                );
            }
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
