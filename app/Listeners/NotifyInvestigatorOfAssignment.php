<?php

namespace App\Listeners;

use App\Events\IncidentAssigned;
use App\Notifications\IncidentAssignedNotification;
use Illuminate\Support\Facades\Notification;

class NotifyInvestigatorOfAssignment
{
    public function handle(IncidentAssigned $event): void
    {
        Notification::send($event->incident->assignedInvestigator, new IncidentAssignedNotification($event->incident));
    }
}
