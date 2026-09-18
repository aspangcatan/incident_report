<?php

namespace App\Actions\Investigations;

use App\DataTransferObjects\Investigations\CompleteInvestigationData;
use App\Models\Investigation;
use App\Services\InvestigationService;

class CompleteInvestigationAction
{
    public function __construct(private InvestigationService $investigations)
    {
    }

    public function __invoke(Investigation $investigation, CompleteInvestigationData $data): Investigation
    {
        return $this->investigations->complete($investigation, $data);
    }
}
