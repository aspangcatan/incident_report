<?php

namespace App\Notifications;

use App\Enums\Severity;
use App\Models\Incident;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** Early alert on submit: the incident type suggests High or above; the department has not confirmed the level yet. */
class ProvisionalSeverityAlertNotification extends Notification
{
    use Queueable;

    public function __construct(private Incident $incident, private Severity $severity, private string $typeName)
    {
    }

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toDatabase($notifiable): array
    {
        $department = $this->incident->department?->name ?? 'an unknown department';
        $number = $this->incident->incident_number ?? "#{$this->incident->id}";

        return [
            'incident_id' => $this->incident->id,
            'incident_number' => $this->incident->incident_number,
            'severity' => $this->severity->value,
            'provisional' => true,
            'message' => "Possible {$this->severity->label()} incident just reported: {$number} ({$department}). "
                . "Suggested by the system from the incident type ({$this->typeName}); the department has not confirmed the level yet.",
        ];
    }
}
