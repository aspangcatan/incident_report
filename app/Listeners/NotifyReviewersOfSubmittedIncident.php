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

        $recipients = User::where(function ($query) {
            $query->whereIn('role', [Role::QualitySafetyOfficer, Role::Administrator]);
        })->when($incident->department_id !== null, function ($query) use ($incident) {
            $query->orWhere(function ($query) use ($incident) {
                $query->whereIn('role', [Role::Supervisor, Role::DepartmentHead])
                    ->where('department_id', $incident->department_id);
            });
        })->get();

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new IncidentSubmittedNotification($incident));
        }
    }
}
