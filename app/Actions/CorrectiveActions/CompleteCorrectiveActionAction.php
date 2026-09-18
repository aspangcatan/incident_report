<?php

namespace App\Actions\CorrectiveActions;

use App\DataTransferObjects\CorrectiveActions\CompleteCorrectiveActionData;
use App\Models\CorrectiveAction;
use App\Services\CorrectiveActionService;

class CompleteCorrectiveActionAction
{
    public function __construct(private CorrectiveActionService $correctiveActions)
    {
    }

    public function __invoke(CorrectiveAction $correctiveAction, CompleteCorrectiveActionData $data): CorrectiveAction
    {
        return $this->correctiveActions->complete($correctiveAction, $data);
    }
}
