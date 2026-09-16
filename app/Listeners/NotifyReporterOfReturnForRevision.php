<?php

namespace App\Listeners;

use App\Events\IncidentReturnedForRevision;
use App\Notifications\IncidentReturnedForRevisionNotification;
use Illuminate\Support\Facades\Notification;

class NotifyReporterOfReturnForRevision
{
    public function handle(IncidentReturnedForRevision $event): void
    {
        Notification::send(
            $event->incident->reporter,
            new IncidentReturnedForRevisionNotification($event->incident, $event->comments)
        );
    }
}
