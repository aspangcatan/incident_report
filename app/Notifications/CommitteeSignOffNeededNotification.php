<?php

namespace App\Notifications;

use App\Models\Incident;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** A High-or-above incident passed CQI Office approval and waits for the Committee. */
class CommitteeSignOffNeededNotification extends Notification
{
    use Queueable;

    public function __construct(private Incident $incident)
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
            'message' => "{$this->incident->incident_number} ({$this->incident->severity->label()}) needs the CQI Committee's closure sign-off.",
        ];
    }
}
