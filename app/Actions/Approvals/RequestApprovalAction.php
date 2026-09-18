<?php

namespace App\Actions\Approvals;

use App\Models\Approval;
use App\Models\Incident;
use App\Models\User;
use App\Services\ApprovalService;

class RequestApprovalAction
{
    public function __construct(private ApprovalService $approvals)
    {
    }

    public function __invoke(Incident $incident, User $requester): Approval
    {
        return $this->approvals->requestApproval($incident, $requester);
    }
}
