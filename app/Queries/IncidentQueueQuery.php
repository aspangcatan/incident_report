<?php

namespace App\Queries;

use App\Enums\ApprovalStage;
use App\Enums\ApprovalStatus;
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
        'effectiveness-checks' => ['Effectiveness Checks', 'Verified incidents past their waiting period: confirm whether the corrective actions worked.', true],
        'ready-for-closure' => ['Ready for Closure', 'Corrective actions confirmed effective: request closure approval.', true],
        'closure-approvals' => ['Closure Approvals', 'Closure requests and their lessons learned, waiting for your approval.', true],
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
            'effectiveness-checks' => $user->role === Role::CqiCommittee,
            'ready-for-closure' => $user->isDepartmentHead(),
            'closure-approvals' => in_array($user->role, [Role::QualitySafetyOfficer, Role::CqiCommittee], true),
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
            // Same condition as IncidentPolicy::checkEffectiveness().
            'effectiveness-checks' => $query->where('status', IncidentStatus::Verified)
                ->where(fn (Builder $q) => $q->whereNull('effectiveness_result')->orWhere('effectiveness_result', '!=', 'effective'))
                ->where('effectiveness_due_at', '<=', now()),
            // Same condition as IncidentPolicy::requestApproval().
            'ready-for-closure' => $query->where('status', IncidentStatus::Verified)
                ->where('effectiveness_result', 'effective')
                ->whereIn('department_id', $user->headedDepartmentIds()),
            // Same condition as IncidentPolicy::approveClosure(): a pending row at this user's stage, not their own request.
            'closure-approvals' => $query->where('status', IncidentStatus::ForApproval)
                ->whereHas('approvals', fn (Builder $q) => $q
                    ->where('status', ApprovalStatus::Pending)
                    ->whereIn('stage', array_filter(ApprovalStage::cases(), fn (ApprovalStage $stage) => $stage->deciderRole() === $user->role))
                    ->where('requested_by', '!=', $user->id)),
            'resolved' => $query->whereIn('status', [IncidentStatus::Verified, IncidentStatus::ForApproval, IncidentStatus::Closed]),
            'investigation-queue' => $query->whereIn('status', [IncidentStatus::Reviewed, IncidentStatus::Assigned]),
            'assigned-to-me' => $query->where('assigned_investigator_id', $user->id)
                ->whereIn('status', [IncidentStatus::Assigned, IncidentStatus::UnderInvestigation]),
            'investigation-history' => $query->whereHas('investigation', fn (Builder $q) => $q->where('status', InvestigationStatus::Completed)),
        };
    }
}
