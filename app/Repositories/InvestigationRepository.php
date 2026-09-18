<?php

namespace App\Repositories;

use App\Models\Investigation;

class InvestigationRepository
{
    public function create(array $attributes): Investigation
    {
        return Investigation::create($attributes);
    }

    public function update(Investigation $investigation, array $attributes): Investigation
    {
        $investigation->fill($attributes);
        $investigation->save();

        return $investigation;
    }

    /**
     * escalated_at is deliberately excluded from Investigation::$fillable
     * (it's system-managed, only ever set by the daily escalation sweep),
     * so it needs forceFill() rather than the mass-assignable update() above.
     */
    public function markEscalated(Investigation $investigation): void
    {
        $investigation->forceFill(['escalated_at' => now()])->save();
    }
}
