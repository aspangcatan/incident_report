<?php

namespace App\Http\Controllers;

use App\Actions\Investigations\AddFindingAction;
use App\Actions\Investigations\AddTeamMemberAction;
use App\Actions\Investigations\CompleteInvestigationAction;
use App\Actions\Investigations\DeleteFindingAction;
use App\Actions\Investigations\RemoveTeamMemberAction;
use App\Actions\Investigations\StartInvestigationAction;
use App\Actions\Investigations\UpdateFindingAction;
use App\Http\Requests\Investigations\AddFindingRequest;
use App\Http\Requests\Investigations\AddTeamMemberRequest;
use App\Http\Requests\Investigations\CompleteInvestigationRequest;
use App\Http\Requests\Investigations\StartInvestigationRequest;
use App\Http\Requests\Investigations\UpdateFindingRequest;
use App\Models\Incident;
use App\Models\Investigation;
use App\Models\InvestigationFinding;
use App\Models\InvestigationTeamMember;
use Illuminate\Http\RedirectResponse;

class InvestigationController extends Controller
{
    public function start(StartInvestigationRequest $request, Incident $incident, StartInvestigationAction $action): RedirectResponse
    {
        $action($incident, $request->user(), $request->toDto());

        return redirect()
            ->route('incidents.show', ['incident' => $incident, 'tab' => 'investigation'])
            ->with('success', 'Investigation started.');
    }

    public function addTeamMember(AddTeamMemberRequest $request, Investigation $investigation, AddTeamMemberAction $action): RedirectResponse
    {
        $action($investigation, $request->toDto());

        return back()->with('success', 'Team member added.');
    }

    public function removeTeamMember(Investigation $investigation, InvestigationTeamMember $teamMember, RemoveTeamMemberAction $action): RedirectResponse
    {
        $this->authorize('manageTeam', $investigation);
        abort_unless($teamMember->investigation_id === $investigation->id, 404);

        if ($teamMember->user_id === $investigation->lead_investigator_id) {
            return back()->with('error', 'The lead investigator can't be removed from the team.');
        }

        $action($teamMember);

        return back()->with('success', 'Team member removed.');
    }

    public function addFinding(AddFindingRequest $request, Investigation $investigation, AddFindingAction $action): RedirectResponse
    {
        $action($investigation, $request->toDto());

        return back()->with('success', 'Finding added.');
    }

    public function updateFinding(UpdateFindingRequest $request, Investigation $investigation, InvestigationFinding $finding, UpdateFindingAction $action): RedirectResponse
    {
        abort_unless($finding->investigation_id === $investigation->id, 404);

        $action($finding, $request->toDto());

        return back()->with('success', 'Finding updated.');
    }

    public function deleteFinding(Investigation $investigation, InvestigationFinding $finding, DeleteFindingAction $action): RedirectResponse
    {
        $this->authorize('recordFindings', $investigation);
        abort_unless($finding->investigation_id === $investigation->id, 404);

        $action($finding);

        return back()->with('success', 'Finding removed.');
    }

    public function complete(CompleteInvestigationRequest $request, Investigation $investigation, CompleteInvestigationAction $action): RedirectResponse
    {
        $action($investigation, $request->toDto());

        return redirect()
            ->route('incidents.show', ['incident' => $investigation->incident_id, 'tab' => 'investigation'])
            ->with('success', 'Investigation completed.');
    }
}
