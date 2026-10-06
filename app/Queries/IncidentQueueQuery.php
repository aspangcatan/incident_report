<?php

namespace App\Queries;

use App\Enums\IncidentStatus;
use App\Enums\InvestigationStatus;
use App\Enums\Role;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every incident work queue, defined once. Used by the incident list
 * (/incidents?scope=…) and the sidebar badge counts, so a badge always
 * matches its page. See docs/superpowers/specs/2026-09-25-work-queues-design.md.
 */
final class IncidentQueueQuery
{
    /** scope => [title, description, badge?] */
    public const QUEUES = [
        'awaiting-assessment' => ['Awaiting Assessment', 'Submitted reports waiting for the department to assess them.', true],
        'pending-review' => ['Pending Review', 'Assessed incidents waiting for review.', true],
        'under-investigation' => ['Under Investigation', 'Incidents with an investigation in progress.', true],
        'corrective-actions' => ['Corrective Actions', 'Incidents in the corrective and preventive action stage.', true],
        'resolved' => ['Resolved / Closed', 'Verified, awaiting approval, or closed.', false],
        'investigation-queue' => ['Investigation Queue', 'Reviewed incidents waiting for an investigator or to be started.', true],
        'assigned-to-me' => ['Assigned to Me', 'Investigations assigned to you that are not finished.', true],
        'investigation-history' => ['History & Findings', 'Incidents whose investigation is completed.', false],
    ];

    public static function exists(string $queue): bool
    {
        return array_key_exists($queue, self::QUEUES);
    }

    public static function allowed(string $queue, User $user): bool
    {
        return match ($queue) {
            'awaiting-assessment' => true,
            'pending-review', 'under-investigation', 'corrective-actions', 'resolved' => $user->can('viewAny', Incident::class),
            'investigation-queue', 'assigned-to-me', 'investigation-history' => self::investigationWorkspace($user),
            default => false,
        };
    }

    public static function investigationWorkspace(User $user): bool
    {
        return in_array($user->role, [Role::Investigator, Role::Supervisor, Role::QualitySafetyOfficer], true)
            || $user->isDepartmentHead()
            // Department staff can be assigned to investigate too; they need the workspace to find it.
            || Incident::where('assigned_investigator_id', $user->id)->exists();
    }

    public static function builder(string $queue, User $user): Builder
    {
        $query = Incident::query()->where('status', '!=', IncidentStatus::Draft)->visibleTo($user);

        return match ($queue) {
            'awaiting-assessment' => $query->where('status', IncidentStatus::Submitted),
            'pending-review' => $query->where('status', IncidentStatus::ForReview),
            'under-investigation' => $query->where('status', IncidentStatus::UnderInvestigation),
            'corrective-actions' => $query->whereIn('status', [IncidentStatus::CorrectiveAction, IncidentStatus::ForVerification]),
            'resolved' => $query->whereIn('status', [IncidentStatus::Verified, IncidentStatus::ForApproval, IncidentStatus::Closed]),
            'investigation-queue' => $query->whereIn('status', [IncidentStatus::Reviewed, IncidentStatus::Assigned]),
            'assigned-to-me' => $query->where('assigned_investigator_id', $user->id)
                ->whereIn('status', [IncidentStatus::Assigned, IncidentStatus::UnderInvestigation]),
            'investigation-history' => $query->whereHas('investigation', fn (Builder $q) => $q->where('status', InvestigationStatus::Completed)),
        };
    }
}
