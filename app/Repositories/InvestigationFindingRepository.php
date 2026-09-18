<?php

namespace App\Repositories;

use App\Models\Investigation;
use App\Models\InvestigationFinding;
use Illuminate\Database\Eloquent\Collection;

class InvestigationFindingRepository
{
    public function create(Investigation $investigation, array $attributes): InvestigationFinding
    {
        return $investigation->findings()->create($attributes);
    }

    public function update(InvestigationFinding $finding, array $attributes): InvestigationFinding
    {
        $finding->update($attributes);

        return $finding;
    }

    public function delete(InvestigationFinding $finding): void
    {
        $finding->delete();
    }

    public function countFor(Investigation $investigation): int
    {
        return $investigation->findings()->count();
    }

    /**
     * Already ordered by sequence then id — see Investigation::findings().
     */
    public function allFor(Investigation $investigation): Collection
    {
        return $investigation->findings()->get();
    }
}
