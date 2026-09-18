<?php

namespace App\Actions\Investigations;

use App\Models\InvestigationFinding;
use App\Services\InvestigationService;

class DeleteFindingAction
{
    public function __construct(private InvestigationService $investigations)
    {
    }

    public function __invoke(InvestigationFinding $finding): void
    {
        $this->investigations->deleteFinding($finding);
    }
}
