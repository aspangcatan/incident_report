<?php

namespace App\Listeners;

use App\Events\IncidentReturnedForRevision;
use App\Notifications\IncidentReturnedForRevisionNotification;
use Illuminate\Support\Facades\Notification;

class NotifyReporterOfReturnForRevision
{
    public function handle(IncidentReturnedForRevision $event): void
    {
        // tdh_user hard-deletes users; a stale id resolves to null.
        $reporter = $event->incident->reporter;

        if ($reporter !== null) {
            Notification::send($reporter, new IncidentReturnedForRevisionNotification($event->incident, $event->comments));
        }
    }
}
