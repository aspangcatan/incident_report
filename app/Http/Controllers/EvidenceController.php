<?php

namespace App\Http\Controllers;

use App\Models\Incident;
use App\Policies\IncidentPolicy;
use App\Services\EvidenceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Evidence added after reporting: during Department Assessment or the investigation. */
class EvidenceController extends Controller
{
    public function store(Request $request, Incident $incident, EvidenceService $evidence): RedirectResponse
    {
        $stage = app(IncidentPolicy::class)->evidenceStage($request->user(), $incident);
        abort_if($stage === null, 403);

        $data = $request->validate([
            'files' => ['required', 'array', 'min:1'],
            'files.*' => EvidenceService::FILE_RULES,
        ], ['files.required' => 'Choose at least one file.']);

        $evidence->store($incident, $request->user(), $data['files'], $stage);

        return back()->with('success', count($data['files']) === 1 ? 'File added to the evidence.' : 'Files added to the evidence.');
    }
}
