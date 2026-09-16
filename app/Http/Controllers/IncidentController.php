<?php

namespace App\Http\Controllers;

use App\Enums\IncidentStatus;
use App\Http\Requests\Incidents\StoreIncidentRequest;
use App\Http\Requests\Incidents\UpdateIncidentRequest;
use App\Models\ContributingFactor;
use App\Models\Department;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Services\IncidentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class IncidentController extends Controller
{
    public function __construct(private IncidentService $incidents)
    {
    }

    public function index(Request $request): Response
    {
        $scope = $request->string('scope', 'my-reports')->toString();
        $user = $request->user();

        $query = Incident::query()->with(['incidentType', 'department', 'reporter']);

        if ($scope === 'drafts') {
            $query->where('reporter_id', $user->id)->where('status', IncidentStatus::Draft);
        } elseif ($scope === 'all') {
            $this->authorize('viewAny', Incident::class);
            $query->where('status', '!=', IncidentStatus::Draft)->visibleTo($user);
        } else {
            $scope = 'my-reports';
            $query->where('reporter_id', $user->id)->where('status', '!=', IncidentStatus::Draft);
        }

        return Inertia::render('Incidents/Index', [
            'incidents' => $query->latest('id')->paginate(15)->withQueryString(),
            'scope' => $scope,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Incident::class);

        return Inertia::render('Incidents/Wizard', [
            'incident' => null,
            'incidentTypes' => IncidentType::where('is_active', true)->get(['id', 'name']),
            'departments' => Department::where('is_active', true)->get(['id', 'name']),
            'contributingFactors' => ContributingFactor::where('is_active', true)->get(['id', 'label', 'category']),
        ]);
    }

    public function store(StoreIncidentRequest $request): RedirectResponse
    {
        $incident = DB::transaction(function () use ($request) {
            $incident = $this->incidents->createDraft($request->user(), $request->validated());
            $this->storeAttachments($incident, $request);

            return $incident;
        });

        if ($request->input('action') === 'submit') {
            $this->incidents->submit($incident);

            return redirect()->route('incidents.show', $incident)->with('success', 'Incident report submitted successfully.');
        }

        return redirect()->route('incidents.edit', $incident)->with('success', 'Draft saved.');
    }

    public function edit(Incident $incident): Response
    {
        $this->authorize('update', $incident);

        $incident->load(['individuals', 'witnesses', 'actions', 'narrativeEvents', 'contributingFactors', 'attachments']);

        return Inertia::render('Incidents/Wizard', [
            'incident' => $incident,
            'incidentTypes' => IncidentType::where('is_active', true)->get(['id', 'name']),
            'departments' => Department::where('is_active', true)->get(['id', 'name']),
            'contributingFactors' => ContributingFactor::where('is_active', true)->get(['id', 'label', 'category']),
        ]);
    }

    public function update(UpdateIncidentRequest $request, Incident $incident): RedirectResponse
    {
        DB::transaction(function () use ($request, $incident) {
            $this->incidents->updateDraft($incident, $request->validated());
            $this->storeAttachments($incident, $request);
        });

        if ($request->input('action') === 'submit') {
            $this->incidents->submit($incident);

            return redirect()->route('incidents.show', $incident)->with('success', 'Incident report submitted successfully.');
        }

        return redirect()->route('incidents.edit', $incident)->with('success', 'Draft saved.');
    }

    public function show(Request $request, Incident $incident): Response
    {
        $this->authorize('view', $incident);

        $incident->load([
            'reporter', 'department', 'incidentType', 'assignedInvestigator',
            'individuals', 'witnesses', 'actions', 'narrativeEvents', 'contributingFactors', 'attachments',
        ]);

        return Inertia::render('Incidents/Show', [
            'incident' => $incident,
            'tab' => $request->string('tab', 'overview')->toString(),
        ]);
    }

    private function storeAttachments(Incident $incident, Request $request): void
    {
        foreach ($request->file('attachments', []) as $file) {
            $path = $file->store("incidents/{$incident->id}", 'local');

            $incident->attachments()->create([
                'uploaded_by' => $request->user()->id,
                'disk_path' => $path,
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'category' => 'evidence',
            ]);
        }
    }
}
