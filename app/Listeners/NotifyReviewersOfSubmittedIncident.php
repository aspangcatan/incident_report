<?php

namespace App\Listeners;

use App\Enums\Role;
use App\Events\IncidentSubmitted;
use App\Models\User;
use App\Notifications\IncidentSubmittedNotification;
use Illuminate\Support\Facades\Notification;

class NotifyReviewersOfSubmittedIncident
{
    public function handle(IncidentSubmitted $event): void
    {
        $incident = $event->incident;

        $recipients = User::active()->where(function ($query) use ($incident) {
            $query->withRole([Role::QualitySafetyOfficer, Role::Administrator]);

            if ($incident->department_id !== null) {
                $query->orWhere(function ($query) use ($incident) {
                    $query->withRole([Role::Supervisor, Role::DepartmentHead])
                        ->where('section', $incident->department_id);
                });
            }
        })->get();

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new IncidentSubmittedNotification($incident));
        }
    }
}
