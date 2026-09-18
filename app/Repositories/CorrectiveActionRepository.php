<?php

namespace App\Repositories;

use App\Models\CorrectiveAction;

class CorrectiveActionRepository
{
    public function create(array $attributes): CorrectiveAction
    {
        return CorrectiveAction::create($attributes);
    }

    public function update(CorrectiveAction $correctiveAction, array $attributes): CorrectiveAction
    {
        $correctiveAction->fill($attributes);
        $correctiveAction->save();

        return $correctiveAction;
    }

    /**
     * escalated_at is deliberately excluded from CorrectiveAction::$fillable
     * (system-managed, only ever set by the daily escalation sweep).
     */
    public function markEscalated(CorrectiveAction $correctiveAction): void
    {
        $correctiveAction->forceFill(['escalated_at' => now()])->save();
    }
}
