<?php

namespace App\Http\Controllers;

use App\Actions\Approvals\ApproveClosureAction;
use App\Actions\Approvals\MarkNoCorrectiveActionNeededAction;
use App\Actions\Approvals\RequestApprovalAction;
use App\Actions\Approvals\ReturnFromApprovalAction;
use App\Enums\IncidentStatus;
use App\Http\Requests\Approvals\ApproveClosureRequest;
use App\Http\Requests\Approvals\MarkNoCorrectiveActionNeededRequest;
use App\Http\Requests\Approvals\RequestApprovalRequest;
use App\Http\Requests\Approvals\ReturnFromApprovalRequest;
use App\Models\Approval;
use App\Models\Incident;
use Illuminate\Http\RedirectResponse;

class ApprovalController extends Controller
{
    public function requestApproval(RequestApprovalRequest $request, Incident $incident, RequestApprovalAction $action): RedirectResponse
    {
        $action($incident, $request->user(), $request->validated('lessons_learned'));

        return back()->with('success', 'Closure approval requested.');
    }

    public function markNoCorrectiveActionNeeded(MarkNoCorrectiveActionNeededRequest $request, Incident $incident, MarkNoCorrectiveActionNeededAction $action): RedirectResponse
    {
        $action($incident, $request->user(), $request->toDto());

        return back()->with('success', 'Marked as requiring no corrective action; closure approval requested.');
    }

    public function approve(ApproveClosureRequest $request, Approval $approval, ApproveClosureAction $action): RedirectResponse
    {
        $action($approval, $request->user(), $request->toDto());

        return back()->with('success', $approval->incident->fresh()->status === IncidentStatus::Closed
            ? 'Incident approved and closed.'
            : 'Approved - now waiting for the CQI Committee\'s sign-off.');
    }

    public function returnForRevision(ReturnFromApprovalRequest $request, Approval $approval, ReturnFromApprovalAction $action): RedirectResponse
    {
        $action($approval, $request->user(), $request->toDto());

        return back()->with('success', 'Returned for further corrective action.');
    }
}
