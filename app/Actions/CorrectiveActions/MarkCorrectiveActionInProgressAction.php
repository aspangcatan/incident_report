<?php

namespace App\Actions\CorrectiveActions;

use App\Models\CorrectiveAction;
use App\Services\CorrectiveActionService;

class MarkCorrectiveActionInProgressAction
{
    public function __construct(private CorrectiveActionService $correctiveActions)
    {
    }

    public function __invoke(CorrectiveAction $correctiveAction): CorrectiveAction
    {
        return $this->correctiveActions->markInProgress($correctiveAction);
    }
}
