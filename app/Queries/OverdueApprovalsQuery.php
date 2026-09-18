<?php

namespace App\Queries;

use App\Models\Approval;
use Illuminate\Database\Eloquent\Collection;

class OverdueApprovalsQuery
{
    public function get(): Collection
    {
        return Approval::overdue()
            ->whereNull('escalated_at')
            ->get();
    }
}
