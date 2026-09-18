<?php

namespace App\Actions\CorrectiveActions;

use App\DataTransferObjects\CorrectiveActions\CorrectiveActionData;
use App\Models\CorrectiveAction;
use App\Services\CorrectiveActionService;

class UpdateCorrectiveActionAction
{
    public function __construct(private CorrectiveActionService $correctiveActions)
    {
    }

    public function __invoke(CorrectiveAction $correctiveAction, CorrectiveActionData $data): CorrectiveAction
    {
        return $this->correctiveActions->update($correctiveAction, $data);
    }
}
