<?php

namespace App\Http\Controllers;

use App\Http\Requests\Incidents\AssignIncidentRequest;
use App\Http\Requests\Incidents\ReturnIncidentRequest;
use App\Http\Requests\Incidents\ReviewIncidentRequest;
use App\Models\Incident;
use App\Models\User;
use App\Services\IncidentService;
use Illuminate\Http\RedirectResponse;

class IncidentWorkflowController extends Controller
{
    public function __construct(private IncidentService $incidents)
    {
    }

    public function review(ReviewIncidentRequest $request, Incident $incident): RedirectResponse
    {
        $this->incidents->markReviewed($incident, $request->user(), $request->validated('comments'));

        return back()->with('success', 'Incident marked as reviewed.');
    }

    public function returnForRevision(ReturnIncidentRequest $request, Incident $incident): RedirectResponse
    {
        $this->incidents->returnForRevision($incident, $request->user(), $request->validated('comments'));

        return redirect()->route('incidents.index')->with('success', 'Incident returned to the reporter for revision.');
    }

    public function assign(AssignIncidentRequest $request, Incident $incident): RedirectResponse
    {
        $investigator = User::findOrFail($request->validated('assigned_investigator_id'));

        $this->incidents->assignInvestigator($incident, $investigator, $request->validated('target_closure_date'));

        return redirect()->route('incidents.show', $incident)->with('success', 'Investigator assigned.');
    }
}
