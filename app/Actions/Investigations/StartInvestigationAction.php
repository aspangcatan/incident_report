<?php

namespace App\Actions\Investigations;

use App\DataTransferObjects\Investigations\StartInvestigationData;
use App\Models\Incident;
use App\Models\Investigation;
use App\Models\User;
use App\Services\InvestigationService;

class StartInvestigationAction
{
    public function __construct(private InvestigationService $investigations)
    {
    }

    public function __invoke(Incident $incident, User $leadInvestigator, StartInvestigationData $data): Investigation
    {
        return $this->investigations->start($incident, $leadInvestigator, $data);
    }
}
