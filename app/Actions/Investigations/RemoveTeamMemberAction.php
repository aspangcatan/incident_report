<?php

namespace App\Actions\Investigations;

use App\Models\InvestigationTeamMember;
use App\Services\InvestigationService;

class RemoveTeamMemberAction
{
    public function __construct(private InvestigationService $investigations)
    {
    }

    public function __invoke(InvestigationTeamMember $teamMember): void
    {
        $this->investigations->removeTeamMember($teamMember);
    }
}
