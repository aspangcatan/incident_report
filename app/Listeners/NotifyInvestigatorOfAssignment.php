<?php

namespace App\Listeners;

use App\Events\IncidentAssigned;
use App\Notifications\IncidentAssignedNotification;
use Illuminate\Support\Facades\Notification;

class NotifyInvestigatorOfAssignment
{
    public function handle(IncidentAssigned $event): void
    {
        // tdh_user hard-deletes users; a stale id resolves to null.
        $investigator = $event->incident->assignedInvestigator;

        if ($investigator !== null) {
            Notification::send($investigator, new IncidentAssignedNotification($event->incident));
        }
    }
}
