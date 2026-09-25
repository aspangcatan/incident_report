<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Models\Incident;
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
                    'administration' => $user->role === Role::Administrator,
                ] : [],
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'unreadNotificationsCount' => fn () => $request->user()?->unreadNotifications()->count() ?? 0,
        ]);
    }
}
