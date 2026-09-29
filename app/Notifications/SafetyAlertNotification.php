<?php

namespace App\Notifications;

use App\Models\SafetyAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class SafetyAlertNotification extends Notification
{
    use Queueable;

    public function __construct(private SafetyAlert $alert)
    {
    }

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'safety_alert_id' => $this->alert->id,
            'message' => "Safety alert ({$this->alert->urgency->label()}): {$this->alert->title}",
        ];
    }
}
