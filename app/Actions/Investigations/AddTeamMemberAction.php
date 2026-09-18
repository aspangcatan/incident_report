<?php

namespace App\Actions\Investigations;

use App\DataTransferObjects\Investigations\AddTeamMemberData;
use App\Models\Investigation;
use App\Models\InvestigationTeamMember;
use App\Models\User;
use App\Services\InvestigationService;

class AddTeamMemberAction
{
    public function __construct(private InvestigationService $investigations)
    {
    }

    public function __invoke(Investigation $investigation, AddTeamMemberData $data): InvestigationTeamMember
    {
        $user = User::findOrFail($data->userId);

        return $this->investigations->addTeamMember($investigation, $user, $data->roleInTeam);
    }
}
