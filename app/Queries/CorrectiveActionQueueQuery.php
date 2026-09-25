<?php

namespace App\Queries;

use App\Enums\CorrectiveActionStatus;
use App\Enums\Role;
use App\Models\CorrectiveAction;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/** Every CAPA work queue, defined once — used by /corrective-actions?queue=… and the sidebar badges. */
final class CorrectiveActionQueueQuery
{
    /** queue => [title, description, badge?] */
    public const QUEUES = [
        'open' => ['Open Actions', 'Corrective actions not yet completed.', true],
        'for-verification' => ['For Verification', 'Completed actions waiting to be verified.', true],
        'overdue' => ['Overdue Actions', 'Actions past their due date and not yet verified.', true],
        'completed' => ['Completed Archive', 'Verified corrective actions.', false],
    ];

    public static function exists(string $queue): bool
    {
        return array_key_exists($queue, self::QUEUES);
    }

    /** Also the sidebar's capaOperations flag. */
    public static function allowed(User $user): bool
    {
        return in_array($user->role, [Role::Supervisor, Role::DepartmentHead, Role::QualitySafetyOfficer, Role::Administrator], true)
            || CorrectiveAction::where('responsible_user_id', $user->id)->exists();
    }

    public static function builder(string $queue, User $user): Builder
    {
        $query = CorrectiveAction::query()->where(fn (Builder $q) => $q
            ->whereIn('incident_id', Incident::query()->select('id')->visibleTo($user))
            ->orWhere('responsible_user_id', $user->id));

        return match ($queue) {
            'open' => $query->whereIn('status', [CorrectiveActionStatus::Open, CorrectiveActionStatus::InProgress]),
            'for-verification' => $query->where('status', CorrectiveActionStatus::ForVerification),
            'overdue' => $query->overdue(),
            'completed' => $query->where('status', CorrectiveActionStatus::Verified),
        };
    }
}
