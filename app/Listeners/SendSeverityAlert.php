<?php

namespace App\Listeners;

use App\Events\IncidentAssessed;
use App\Events\IncidentReviewed;
use App\Models\User;
use App\Notifications\SeverityAlertNotification;
use App\Support\EscalationRecipients;
use Illuminate\Support\Facades\Notification;

/** Alerts the people the severity level requires, as soon as it is set (assessment) or changed (CQI triage). */
class SendSeverityAlert
{
    public function handle(IncidentAssessed|IncidentReviewed $event): void
    {
        $incident = $event->incident;

        if ($incident->severity === null) {
            return;
        }

        if ($event instanceof IncidentReviewed && $event->previousSeverity === $incident->severity) {
            return;
        }

        $recipients = EscalationRecipients::forSeverity($incident)
            ->reject(fn (User $user) => $event->actor !== null && $user->id === $event->actor->id);

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new SeverityAlertNotification($incident));
        }
    }
}
