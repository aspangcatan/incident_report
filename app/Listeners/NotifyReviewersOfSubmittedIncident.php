<?php

namespace App\Listeners;

use App\Events\IncidentSubmitted;
use App\Notifications\IncidentSubmittedNotification;
use App\Support\IncidentReviewers;
use Illuminate\Support\Facades\Notification;

class NotifyReviewersOfSubmittedIncident
{
    public function handle(IncidentSubmitted $event): void
    {
        $incident = $event->incident;

        $recipients = IncidentReviewers::for($incident);

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new IncidentSubmittedNotification($incident));
        }
    }
}
