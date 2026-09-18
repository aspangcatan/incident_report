<?php

namespace App\Actions\CorrectiveActions;

use App\DataTransferObjects\CorrectiveActions\CorrectiveActionData;
use App\Models\CorrectiveAction;
use App\Models\Incident;
use App\Services\CorrectiveActionService;

class CreateCorrectiveActionAction
{
    public function __construct(private CorrectiveActionService $correctiveActions)
    {
    }

    public function __invoke(Incident $incident, CorrectiveActionData $data): CorrectiveAction
    {
        return $this->correctiveActions->create($incident, $data);
    }
}
