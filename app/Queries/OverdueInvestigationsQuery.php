<?php

namespace App\Queries;

use App\Enums\InvestigationStatus;
use App\Models\Investigation;
use Illuminate\Database\Eloquent\Collection;

class OverdueInvestigationsQuery
{
    public function get(): Collection
    {
        return Investigation::whereNull('escalated_at')
            ->where('status', InvestigationStatus::InProgress)
            ->whereNotNull('target_completion_at')
            ->where('target_completion_at', '<', now())
            ->get();
    }
}
