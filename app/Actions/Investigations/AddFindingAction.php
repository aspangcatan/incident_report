<?php

namespace App\Actions\Investigations;

use App\DataTransferObjects\Investigations\FindingData;
use App\Models\Investigation;
use App\Models\InvestigationFinding;
use App\Services\InvestigationService;

class AddFindingAction
{
    public function __construct(private InvestigationService $investigations)
    {
    }

    public function __invoke(Investigation $investigation, FindingData $data): InvestigationFinding
    {
        return $this->investigations->addFinding($investigation, $data);
    }
}
