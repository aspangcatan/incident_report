<?php

namespace App\Notifications;

use App\Models\Incident;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class IncidentEscalationNotification extends Notification
{
    use Queueable;

    public function __construct(private Incident $incident, private string $reason)
    {
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
            'message' => "Escalation: {$this->incident->incident_number} — {$this->reason}.",
        ];
    }
}
