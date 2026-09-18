<?php

namespace App\Repositories;

use App\Models\Approval;

class ApprovalRepository
{
    public function create(array $attributes): Approval
    {
        return Approval::create($attributes);
    }

    public function update(Approval $approval, array $attributes): Approval
    {
        $approval->fill($attributes);
        $approval->save();

        return $approval;
    }

    /**
     * escalated_at is deliberately excluded from Approval::$fillable
     * (system-managed, only ever set by the daily escalation sweep).
     */
    public function markEscalated(Approval $approval): void
    {
        $approval->forceFill(['escalated_at' => now()])->save();
    }
}
