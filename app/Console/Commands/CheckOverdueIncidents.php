<?php

namespace App\Console\Commands;

use App\Enums\IncidentStatus;
use App\Models\CorrectiveAction;
use App\Models\Incident;
use App\Models\Investigation;
use App\Models\User;
use App\Notifications\IncidentEscalationNotification;
use App\Queries\OverdueCorrectiveActionsQuery;
use App\Queries\OverdueInvestigationsQuery;
use App\Repositories\CorrectiveActionRepository;
use App\Repositories\InvestigationRepository;
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
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $recipients = User::whereIn('role', config('incident_workflow.escalation_recipient_roles'))->get();

        if ($recipients->isEmpty()) {
            $this->warn('No escalation recipients configured/found; skipping.');

            return self::SUCCESS;
        }

        $this->escalateOverdueReviews($recipients);
        $this->escalateOverdueAssignments($recipients);
        $this->escalateOverdueInvestigations($recipients);
        $this->escalateOverdueCorrectiveActions($recipients);

        return self::SUCCESS;
    }

    private function escalateOverdueReviews(Collection $recipients): void
    {
        Incident::whereNull('review_escalated_at')
            ->where('status', IncidentStatus::Submitted)
            ->whereNotNull('reported_at')
            ->get()
            ->each(function (Incident $incident) use ($recipients) {
                $slaHours = config('incident_workflow.review_sla_hours.' . $incident->severity->value);

                if ($slaHours === null || $incident->reported_at->addHours($slaHours)->isFuture()) {
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
}
