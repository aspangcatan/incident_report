<?php

namespace App\Services;

use App\Enums\IncidentStatus;
use App\Enums\RecurrenceReviewStatus;
use App\Enums\Role;
use App\Models\Incident;
use App\Models\RecurrenceReview;
use App\Models\User;
use App\Notifications\RecurrenceReviewNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Notification;

class RecurrenceReviewService
{
    public const WINDOW_DAYS = AnalyticsService::REPEAT_PATTERN_WINDOW_DAYS;

    /** Incidents of the pattern: same department and type, reported in the window. */
    public function patternIncidents(int $departmentId, int $incidentTypeId): Builder
    {
        return Incident::query()
            ->where('status', '!=', IncidentStatus::Draft)
            ->where('department_id', $departmentId)
            ->where('reported_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->whereHas('incidentTypes', fn ($q) => $q->where('incident_types.id', $incidentTypeId));
    }

    public function open(User $creator, array $data): RecurrenceReview
    {
        $review = RecurrenceReview::create([
            ...$data,
            'incident_count' => $this->patternIncidents($data['department_id'], $data['incident_type_id'])->count(),
            'status' => RecurrenceReviewStatus::Open,
            'created_by' => $creator->id,
        ]);

        $this->notify($review->assignee, $review, "Recurrence review assigned to you: {$review->incidentType->name} keeps recurring in your department. Due {$review->due_date->format('M j, Y')}.");

        return $review;
    }

    public function submit(RecurrenceReview $review, string $fixDescription): RecurrenceReview
    {
        $review->update([
            'fix_description' => $fixDescription,
            'status' => RecurrenceReviewStatus::Submitted,
            'submitted_at' => now(),
        ]);

        $cqi = User::active()->withRole(Role::QualitySafetyOfficer)->get();
        if ($cqi->isNotEmpty()) {
            Notification::send($cqi, new RecurrenceReviewNotification($review, "Recurrence review submitted for {$review->incidentType->name}: ready for the CQI Office."));
        }

        return $review;
    }

    public function close(RecurrenceReview $review, User $decider, string $comments): RecurrenceReview
    {
        $review->update([
            'status' => RecurrenceReviewStatus::Closed,
            'decision_comments' => $comments,
            'closed_by' => $decider->id,
            'closed_at' => now(),
        ]);

        $this->notify($review->assignee, $review, "Recurrence review for {$review->incidentType->name} was closed by the CQI Office.");

        return $review;
    }

    /** Back to the department for more work. */
    public function returnToDepartment(RecurrenceReview $review, string $comments): RecurrenceReview
    {
        $review->update([
            'status' => RecurrenceReviewStatus::Open,
            'decision_comments' => $comments,
        ]);

        $this->notify($review->assignee, $review, "Recurrence review for {$review->incidentType->name} was returned: {$comments}");

        return $review;
    }

    private function notify(?User $user, RecurrenceReview $review, string $message): void
    {
        if ($user !== null) {
            Notification::send($user, new RecurrenceReviewNotification($review, $message));
        }
    }
}
