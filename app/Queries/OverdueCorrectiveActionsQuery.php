<?php

namespace App\Queries;

use App\Models\CorrectiveAction;
use Illuminate\Database\Eloquent\Collection;

class OverdueCorrectiveActionsQuery
{
    public function get(): Collection
    {
        return CorrectiveAction::overdue()
            ->whereNull('escalated_at')
            ->get();
    }
}
