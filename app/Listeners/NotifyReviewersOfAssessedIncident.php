<?php

namespace App\Listeners;

use App\Events\IncidentAssessed;
use App\Notifications\IncidentReadyForReviewNotification;
use App\Support\IncidentReviewers;
use Illuminate\Support\Facades\Notification;

class NotifyReviewersOfAssessedIncident
{
    public function handle(IncidentAssessed $event): void
    {
        $recipients = IncidentReviewers::for($event->incident);

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new IncidentReadyForReviewNotification($event->incident));
        }
    }
}
