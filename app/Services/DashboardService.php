<?php

namespace App\Services;

use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\User;
use App\Queries\CorrectiveActionQueueQuery;
use Illuminate\Database\Eloquent\Builder;

/** Live counts for the dashboard, limited to the incidents the user can see. Drafts never count. */
class DashboardService
{
    public function overview(User $user): array
    {
        $byStatus = $this->visible($user)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($total) => (int) $total);

        $count = fn (IncidentStatus ...$statuses) => collect($statuses)->sum(fn (IncidentStatus $status) => $byStatus->get($status->value, 0));

        return [
            'stages' => [
                'reported' => $count(IncidentStatus::Submitted),
                'forReview' => $count(IncidentStatus::ForReview),
                'investigation' => $count(IncidentStatus::Reviewed, IncidentStatus::Assigned, IncidentStatus::UnderInvestigation),
                'capa' => $count(IncidentStatus::CorrectiveAction),
                'verification' => $count(IncidentStatus::ForVerification, IncidentStatus::Verified, IncidentStatus::ForApproval),
                'closed' => $count(IncidentStatus::Closed),
            ],
            'kpis' => [
                'totalThisYear' => $this->visible($user)->whereYear('reported_at', now()->year)->count(),
                'pendingReview' => $count(IncidentStatus::ForReview),
                'activeInvestigations' => $count(IncidentStatus::Assigned, IncidentStatus::UnderInvestigation),
                'overdueCapa' => CorrectiveActionQueueQuery::builder('overdue', $user)->count(),
                'sentinelThisYear' => $this->visible($user)->whereYear('reported_at', now()->year)->where('is_sentinel_event', true)->count(),
                'closedAndVerified' => $count(IncidentStatus::Verified, IncidentStatus::ForApproval, IncidentStatus::Closed),
            ],
        ];
    }

    private function visible(User $user): Builder
    {
        return Incident::query()->where('status', '!=', IncidentStatus::Draft)->visibleTo($user);
    }
}
