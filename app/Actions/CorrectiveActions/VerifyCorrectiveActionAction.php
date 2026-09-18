<?php

namespace App\Actions\CorrectiveActions;

use App\DataTransferObjects\CorrectiveActions\VerifyCorrectiveActionData;
use App\Models\CorrectiveAction;
use App\Models\User;
use App\Services\CorrectiveActionService;

class VerifyCorrectiveActionAction
{
    public function __construct(private CorrectiveActionService $correctiveActions)
    {
    }

    public function __invoke(CorrectiveAction $correctiveAction, User $verifier, VerifyCorrectiveActionData $data): CorrectiveAction
    {
        return $this->correctiveActions->verify($correctiveAction, $verifier, $data);
    }
}
