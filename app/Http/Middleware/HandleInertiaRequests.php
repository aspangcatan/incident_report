<?php

namespace App\Http\Middleware;

use App\Enums\RecurrenceReviewStatus;
use App\Enums\Role;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\RecurrenceReview;
use App\Models\SafetyAlert;
use App\Models\User;
use App\Queries\CorrectiveActionQueueQuery;
use App\Queries\IncidentQueueQuery;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'auth' => [
                'user' => ($user = $request->user()) ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'role' => $user->role,
                    'designation' => $user->designation_title,
                    'department_id' => $user->department_id,
                ] : null,
                'can' => $user ? [
                    'viewAllIncidents' => $user->can('viewAny', Incident::class),
                    'investigationWorkspace' => IncidentQueueQuery::investigationWorkspace($user),
                    'capaOperations' => CorrectiveActionQueueQuery::allowed($user),
                    'viewAnalytics' => $user->can('viewAnalytics', Incident::class),
                    'administration' => in_array($user->role, [Role::Administrator, Role::QualitySafetyOfficer], true),
                    'manageIncidentTypes' => $user->can('viewAny', IncidentType::class),
                    'manageWorkflowDurations' => $user->can('manageWorkflowDurations'),
                ] : [],
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'unreadNotificationsCount' => fn () => $request->user()?->unreadNotifications()->count() ?? 0,
            'queueCounts' => fn () => ($user = $request->user()) ? $this->queueCounts($user) : [],
            // Newest safety alert this user has not acknowledged yet (banner).
            'pendingSafetyAlert' => fn () => ($user = $request->user())
                ? SafetyAlert::addressedTo($user)->notAcknowledgedBy($user)->latest('id')->first(['id', 'title', 'urgency'])
                : null,
        ]);
    }

    /** Badge counts, from the same builders the queue pages use. Zero counts are omitted. */
    private function queueCounts(User $user): array
    {
        // Draft Reports counts only reports sent back to this reporter.
        $counts = [
            'drafts' => Incident::returned()->where('reporter_id', $user->id)->count(),
            'safety-alerts' => SafetyAlert::addressedTo($user)->notAcknowledgedBy($user)->count(),
            'recurrence-reviews' => $user->role === Role::QualitySafetyOfficer
                ? RecurrenceReview::where('status', RecurrenceReviewStatus::Submitted)->count()
                : RecurrenceReview::where('status', RecurrenceReviewStatus::Open)->where('assigned_to', $user->id)->count(),
        ];

        foreach (IncidentQueueQuery::QUEUES as $queue => [, , $badge]) {
            if ($badge && IncidentQueueQuery::allowed($queue, $user)) {
                $counts[$queue] = IncidentQueueQuery::builder($queue, $user)->count();
            }
        }

        if (CorrectiveActionQueueQuery::allowed($user)) {
            foreach (CorrectiveActionQueueQuery::QUEUES as $queue => [, , $badge]) {
                if ($badge) {
                    $counts[$queue] = CorrectiveActionQueueQuery::builder($queue, $user)->count();
                }
            }
        }

        return array_filter($counts);
    }
}
