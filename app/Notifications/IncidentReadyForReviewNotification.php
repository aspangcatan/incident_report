<?php

namespace App\Notifications;

use App\Models\Incident;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class IncidentReadyForReviewNotification extends Notification
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
            'message' => "Incident {$this->incident->incident_number} has been assessed by the department and is ready for review.",
        ];
    }
}
