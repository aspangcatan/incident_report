<?php

namespace App\Listeners;

use App\Events\IncidentSubmitted;
use App\Models\User;
use App\Notifications\ProvisionalSeverityAlertNotification;
use App\Support\EscalationRecipients;
use Illuminate\Support\Facades\Notification;

/**
 * "Immediate" escalation for High and above: on submit, the level the incident type
 * suggests alerts that level's recipients straight away. The reporter never sets a level;
 * the department's assessment confirms or changes it (SendSeverityAlert).
 */
class SendProvisionalSeverityAlert
{
    public function handle(IncidentSubmitted $event): void
    {
        $incident = $event->incident->loadMissing(['incidentTypes', 'department']);
        $suggestion = $incident->suggestedSeverity();

        if ($suggestion === null || ! $suggestion['severity']->isHighOrAbove()) {
            return;
        }

        // A resubmitted report (after a return for revision) is not re-alerted at the same level.
        if ($incident->provisional_alert_severity === $suggestion['severity']->value) {
            return;
        }

        $recipients = EscalationRecipients::forSeverity($incident, $suggestion['severity'])
            ->reject(fn (User $user) => $user->id === $incident->reporter_id);

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new ProvisionalSeverityAlertNotification($incident, $suggestion['severity'], $suggestion['type']));
        }

        $incident->forceFill(['provisional_alert_severity' => $suggestion['severity']->value])->saveQuietly();
    }
}
