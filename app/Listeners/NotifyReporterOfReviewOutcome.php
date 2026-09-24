<?php

namespace App\Listeners;

use App\Events\IncidentReviewed;
use App\Notifications\IncidentReviewedNotification;
use Illuminate\Support\Facades\Notification;

class NotifyReporterOfReviewOutcome
{
    public function handle(IncidentReviewed $event): void
    {
        // tdh_user hard-deletes users; a stale id resolves to null.
        $reporter = $event->incident->reporter;

        if ($reporter !== null) {
            Notification::send($reporter, new IncidentReviewedNotification($event->incident));
        }
    }
}
