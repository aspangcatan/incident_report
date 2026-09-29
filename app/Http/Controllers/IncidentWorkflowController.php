<?php

namespace App\Http\Controllers;

use App\Enums\Severity;
use App\Http\Requests\Incidents\AssignIncidentRequest;
use App\Http\Requests\Incidents\CompleteAssessmentRequest;
use App\Http\Requests\Incidents\ReturnIncidentRequest;
use App\Http\Requests\Incidents\ReturnToDepartmentRequest;
use App\Http\Requests\Incidents\ReviewIncidentRequest;
use App\Http\Requests\Incidents\SaveAssessmentRequest;
use App\Http\Requests\Incidents\SkipInvestigationRequest;
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
        $severity = $request->validated('severity') ? Severity::from($request->validated('severity')) : null;
        $this->incidents->markReviewed($incident, $request->user(), $request->validated('comments'), $severity);

        return redirect()->route('incidents.show', $incident)->with('success', 'Triage done - now assign an investigator or record that none is needed.');
    }

    public function skipInvestigation(SkipInvestigationRequest $request, Incident $incident): RedirectResponse
    {
        $this->incidents->skipInvestigation($incident, $request->user(), $request->validated('reason'));

        return redirect()->route('incidents.show', $incident)->with('success', 'No investigation needed - the department can now handle corrective actions.');
    }

    public function returnForRevision(ReturnIncidentRequest $request, Incident $incident): RedirectResponse
    {
        $this->incidents->returnForRevision($incident, $request->user(), $request->validated('comments'));

        // Once returned, status is back to Draft, which IncidentPolicy::view() hides from
        // everyone except the reporter - so the reviewer can't be sent to incidents.show.
        return redirect()->route('incidents.index')->with('success', 'Incident returned to the reporter for revision.');
    }

    public function assign(AssignIncidentRequest $request, Incident $incident): RedirectResponse
    {
        $investigator = User::findOrFail($request->validated('assigned_investigator_id'));

        $this->incidents->assignInvestigator($incident, $investigator, $request->validated('target_closure_date'));

        return redirect()->route('incidents.show', $incident)->with('success', 'Investigator assigned.');
    }

    public function saveAssessment(SaveAssessmentRequest $request, Incident $incident): RedirectResponse
    {
        $this->incidents->saveAssessment($incident, $request->assessmentData());

        return back()->with('success', 'Assessment saved.');
    }

    public function completeAssessment(CompleteAssessmentRequest $request, Incident $incident): RedirectResponse
    {
        $this->incidents->saveAssessment($incident, $request->assessmentData());
        $this->incidents->completeAssessment($incident->fresh(), $request->user());

        return redirect()->route('incidents.show', $incident)->with('success', 'Assessment completed — the incident is ready for review.');
    }

    public function returnToDepartment(ReturnToDepartmentRequest $request, Incident $incident): RedirectResponse
    {
        $this->incidents->returnToDepartment($incident, $request->user(), $request->validated('comments'));

        return redirect()->route('incidents.show', $incident)->with('success', 'Incident returned to the department.');
    }
}
