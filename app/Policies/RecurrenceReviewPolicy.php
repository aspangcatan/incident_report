<?php

namespace App\Policies;

use App\Enums\RecurrenceReviewStatus;
use App\Enums\Role;
use App\Models\RecurrenceReview;
use App\Models\User;

class RecurrenceReviewPolicy
{
    /** The CQI Office opens reviews on recurring patterns. */
    public function create(User $user): bool
    {
        return $user->role === Role::QualitySafetyOfficer;
    }

    public function view(User $user, RecurrenceReview $review): bool
    {
        return RecurrenceReview::visibleTo($user)->whereKey($review->id)->exists();
    }

    /** The assigned Department Head / Focal Person records the system-level fix. */
    public function submit(User $user, RecurrenceReview $review): bool
    {
        return $review->status === RecurrenceReviewStatus::Open && $review->assigned_to === $user->id;
    }

    /** The CQI Office closes it, or returns it for more work. */
    public function decide(User $user, RecurrenceReview $review): bool
    {
        return $review->status === RecurrenceReviewStatus::Submitted && $user->role === Role::QualitySafetyOfficer;
    }
}
