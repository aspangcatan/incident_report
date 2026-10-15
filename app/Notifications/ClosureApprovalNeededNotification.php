<?php

namespace App\Notifications;

use App\Models\Incident;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ClosureApprovalNeededNotification extends Notification
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
            'tab' => 'approvals',
            'message' => "{$this->incident->incident_number} is requesting closure: review the lessons learned and approve or return it.",
        ];
    }
}
