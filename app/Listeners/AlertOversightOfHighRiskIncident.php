<?php

namespace App\Listeners;

use App\Enums\Role;
use App\Enums\Severity;
use App\Events\IncidentReviewed;
use App\Models\User;
use App\Notifications\HighRiskIncidentNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/** Once the CQI Office triages an incident as High or Sentinel, oversight is alerted. */
class AlertOversightOfHighRiskIncident
{
    public function handle(IncidentReviewed $event): void
    {
        $incident = $event->incident;

        if (! in_array($incident->severity, [Severity::Level3High, Severity::Level4CriticalSentinel], true)) {
            return;
        }

        $leaderIds = DB::table('leadership_departments')->where('department_id', $incident->department_id)->pluck('user_id');

        $recipients = User::active()->where(fn ($query) => $query
            ->withRole([Role::Management, Role::CqiCommittee])
            ->orWhere(fn ($query) => $query->withRole(Role::Leadership)->whereIn('id', $leaderIds))
        )->get();

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new HighRiskIncidentNotification($incident));
        }
    }
}
