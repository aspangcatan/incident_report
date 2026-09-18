<?php

namespace App\Actions\Approvals;

use App\DataTransferObjects\Approvals\MarkNoCorrectiveActionNeededData;
use App\Models\Approval;
use App\Models\Incident;
use App\Models\User;
use App\Services\ApprovalService;

class MarkNoCorrectiveActionNeededAction
{
    public function __construct(private ApprovalService $approvals)
    {
    }

    public function __invoke(Incident $incident, User $requester, MarkNoCorrectiveActionNeededData $data): Approval
    {
        return $this->approvals->markNoCorrectiveActionNeeded($incident, $requester, $data);
    }
}
