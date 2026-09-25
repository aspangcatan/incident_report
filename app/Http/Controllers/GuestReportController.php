<?php

namespace App\Http\Controllers;

use App\Http\Requests\Incidents\StoreGuestReportRequest;
use App\Models\Department;
use App\Models\IncidentType;
use App\Services\IncidentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Public incident reporting for patients, relatives and visitors (no login). */
class GuestReportController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Guest/Report', [
            'incidentTypes' => IncidentType::where('is_active', true)->get(['id', 'name']),
            'departments' => Department::options(),
        ]);
    }

    public function store(StoreGuestReportRequest $request, IncidentService $incidents): RedirectResponse
    {
        $incident = $incidents->submitGuestReport($request->validated());

        return redirect()->route('guest-report.submitted')->with('guest_reference', $incident->incident_number);
    }

    public function submitted(Request $request): Response|RedirectResponse
    {
        $reference = $request->session()->get('guest_reference');

        if ($reference === null) {
            return redirect()->route('guest-report.create');
        }

        return Inertia::render('Guest/Submitted', ['reference' => $reference]);
    }
}
