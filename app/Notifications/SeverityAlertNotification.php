<?php

namespace App\Notifications;

use App\Models\Incident;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** Immediate alert for Moderate and above, per the client's Escalation & Notification Matrix. */
class SeverityAlertNotification extends Notification
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
        $severity = $this->incident->severity;
        $department = $this->incident->department?->name ?? 'an unknown department';
        $number = $this->incident->incident_number ?? "#{$this->incident->id}";

        return [
            'incident_id' => $this->incident->id,
            'incident_number' => $this->incident->incident_number,
            'message' => "{$severity->label()} incident: {$number} ({$department}) was rated {$severity->romanNumeral()} – {$severity->label()}.",
        ];
    }
}
