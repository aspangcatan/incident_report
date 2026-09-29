<?php

namespace App\Listeners;

use App\Enums\Role;
use App\Events\IncidentAssessed;
use App\Models\User;
use App\Notifications\IncidentReadyForReviewNotification;
use Illuminate\Support\Facades\Notification;

class NotifyReviewersOfAssessedIncident
{
    public function handle(IncidentAssessed $event): void
    {
        // Triage belongs to the CQI Office alone.
        $recipients = User::active()->withRole(Role::QualitySafetyOfficer)->get();

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new IncidentReadyForReviewNotification($event->incident));
        }
    }
}
