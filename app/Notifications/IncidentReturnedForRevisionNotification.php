<?php

namespace App\Notifications;

use App\Models\Incident;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class IncidentReturnedForRevisionNotification extends Notification
{
    use Queueable;

    public function __construct(private Incident $incident, private string $comments)
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
            'message' => "Your report {$this->incident->incident_number} was returned for revision: {$this->comments}",
        ];
    }
}
