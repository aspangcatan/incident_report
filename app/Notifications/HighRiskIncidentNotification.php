<?php

namespace App\Notifications;

use App\Models\Incident;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** High/Sentinel alert for Executives, the CQI Committee and the department's Leadership. */
class HighRiskIncidentNotification extends Notification
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
        $department = $this->incident->department?->name ?? 'an unknown department';

        return [
            'incident_id' => $this->incident->id,
            'incident_number' => $this->incident->incident_number,
            'message' => "High-risk alert: {$this->incident->incident_number} ({$department}) was triaged as {$this->incident->severity->label()}.",
        ];
    }
}
