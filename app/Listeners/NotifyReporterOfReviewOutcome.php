<?php

namespace App\Listeners;

use App\Events\IncidentReviewed;
use App\Notifications\IncidentReviewedNotification;
use Illuminate\Support\Facades\Notification;

class NotifyReporterOfReviewOutcome
{
    public function handle(IncidentReviewed $event): void
    {
        Notification::send($event->incident->reporter, new IncidentReviewedNotification($event->incident));
    }
}
