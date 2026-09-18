<?php

namespace App\Repositories;

use App\Models\Investigation;
use App\Models\InvestigationTeamMember;

class InvestigationTeamMemberRepository
{
    public function create(Investigation $investigation, array $attributes): InvestigationTeamMember
    {
        return $investigation->teamMembers()->create($attributes);
    }

    public function delete(InvestigationTeamMember $teamMember): void
    {
        $teamMember->delete();
    }
}
