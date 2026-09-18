<?php

namespace App\Actions\Investigations;

use App\DataTransferObjects\Investigations\FindingData;
use App\Models\InvestigationFinding;
use App\Services\InvestigationService;

class UpdateFindingAction
{
    public function __construct(private InvestigationService $investigations)
    {
    }

    public function __invoke(InvestigationFinding $finding, FindingData $data): InvestigationFinding
    {
        return $this->investigations->updateFinding($finding, $data);
    }
}
