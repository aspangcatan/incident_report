<?php

namespace App\Actions\Approvals;

use App\DataTransferObjects\Approvals\DecideApprovalData;
use App\Models\Approval;
use App\Models\User;
use App\Services\ApprovalService;

class ApproveClosureAction
{
    public function __construct(private ApprovalService $approvals)
    {
    }

    public function __invoke(Approval $approval, User $approver, DecideApprovalData $data): Approval
    {
        return $this->approvals->approve($approval, $approver, $data);
    }
}
