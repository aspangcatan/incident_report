<?php

namespace App\Listeners;

use App\Events\IncidentReturnedToDepartment;
use App\Notifications\IncidentReturnedToDepartmentNotification;
use App\Support\IncidentReviewers;
use Illuminate\Support\Facades\Notification;

class NotifyDepartmentHeadsOfReturn
{
    public function handle(IncidentReturnedToDepartment $event): void
    {
        $recipients = IncidentReviewers::departmentHeads($event->incident);

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new IncidentReturnedToDepartmentNotification($event->incident, $event->comments));
        }
    }
}
