<?php

namespace App\Notifications;

use App\Models\RecurrenceReview;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** One notification for each step of a recurrence review; the message says what happened. */
class RecurrenceReviewNotification extends Notification
{
    use Queueable;

    public function __construct(private RecurrenceReview $review, private string $message)
    {
    }

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'recurrence_review_id' => $this->review->id,
            'message' => $this->message,
        ];
    }
}
