<?php

namespace App\Notifications;

use App\Enums\Role;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationFacade;

class EffectivenessCheckDueNotification extends Notification
{
    use Queueable;

    public function __construct(private Incident $incident)
    {
    }

    /** Tells the active CQI Committee once, and stamps the incident so the daily run skips it. */
    public static function sendFor(Incident $incident): void
    {
        $committee = User::active()->withRole(Role::CqiCommittee)->get();
        if ($committee->isNotEmpty()) {
            NotificationFacade::send($committee, new self($incident));
        }
        $incident->forceFill(['effectiveness_notified_at' => now()])->saveQuietly();
    }

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'incident_id' => $this->incident->id,
            'incident_number' => $this->incident->incident_number,
            'tab' => 'approvals',
            'message' => "Effectiveness check due for {$this->incident->incident_number}: did the corrective actions work?",
        ];
    }
}
