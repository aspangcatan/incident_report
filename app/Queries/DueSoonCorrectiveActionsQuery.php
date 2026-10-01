<?php

namespace App\Queries;

use App\Enums\CorrectiveActionStatus;
use App\Models\CorrectiveAction;
use Illuminate\Database\Eloquent\Collection;

/** Corrective actions due tomorrow that the owner hasn't finished and that haven't had their reminder. */
class DueSoonCorrectiveActionsQuery
{
    public function get(): Collection
    {
        return CorrectiveAction::whereNull('reminder_sent_at')
            ->whereNull('escalated_at')
            ->whereDate('due_date', now()->addDay()->toDateString())
            ->whereIn('status', [CorrectiveActionStatus::Open->value, CorrectiveActionStatus::InProgress->value])
            ->get();
    }
}
