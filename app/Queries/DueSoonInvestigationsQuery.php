<?php

namespace App\Queries;

use App\Enums\InvestigationStatus;
use App\Models\Investigation;
use Illuminate\Database\Eloquent\Collection;

/** In-progress investigations due within the next 24 hours that haven't had their reminder. */
class DueSoonInvestigationsQuery
{
    public function get(): Collection
    {
        return Investigation::whereNull('reminder_sent_at')
            ->whereNull('escalated_at')
            ->where('status', InvestigationStatus::InProgress)
            ->whereNotNull('target_completion_at')
            ->whereBetween('target_completion_at', [now(), now()->addDay()])
            ->get();
    }
}
