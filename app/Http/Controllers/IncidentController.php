<?php

namespace App\Http\Controllers;

use App\Enums\IncidentStatus;
use App\Enums\Role;
use App\Http\Requests\Incidents\StoreIncidentRequest;
use App\Http\Requests\Incidents\UpdateIncidentRequest;
use App\Http\Resources\ApprovalResource;
use App\Http\Resources\CorrectiveActionResource;
use App\Http\Resources\InvestigationFindingResource;
use App\Http\Resources\InvestigationResource;
use App\Models\CorrectiveAction;
use App\Models\Department;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\User;
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
            'departments' => Department::options(),
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
            'departments' => Department::options(),
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
            'individuals', 'witnesses', 'actions', 'narrativeEvents', 'contributingFactors', 'attachments', 'assessor',
        ]);

        $user = $request->user();

        $canStartInvestigation = $user->can('start', $incident);
        $investigation = $incident->investigation?->load(['leadInvestigator', 'teamMembers.user', 'findings']);
        // Accessing the investigation relation above caches it on $incident (Eloquent
        // caches every relation it resolves, however it was resolved), which would
        // otherwise duplicate it - unfiltered, with nested user PII - inside the raw
        // 'incident' prop below. Drop the cached relation so $incident serializes
        // exactly as it did before this feature: the investigation is exposed solely
        // through the Resource-shaped 'investigation' prop. Use $investigation (not
        // $incident->investigation) anywhere else below, or this reopens the leak.
        $incident->unsetRelation('investigation');
        $canManageInvestigationTeam = $investigation && $user->can('manageTeam', $investigation);

        $correctiveActions = $incident->correctiveActions()
            ->with(['responsibleUser', 'responsibleDepartment', 'completedBy', 'verifiedBy'])
            ->latest('id')
            ->get();

        // The active-user directory (~1600 people) is loaded at most once, and
        // only when a list the viewer can actually use needs it. Each list is
        // reduced to the fields its panel reads - never the full User shape -
        // plus a 'label' that tells same-name people apart (see pickerLabel()).
        $activeUsers = null;
        $directory = function () use (&$activeUsers) {
            return $activeUsers ??= User::active()->with('department')->orderByName()->get();
        };
        $pickerEntries = fn ($users, array $fields) => $users
            ->map(fn (User $candidate) => $candidate->only($fields) + ['label' => $this->pickerLabel($candidate)])
            ->values();

        $canCreateCorrectiveAction = $user->can('create', [CorrectiveAction::class, $incident]);
        $canEditAnyCorrectiveAction = $correctiveActions->contains(fn (CorrectiveAction $action) => $user->can('update', $action));

        $approvals = $incident->approvals()
            ->with(['requestedBy', 'approver', 'incident'])
            ->latest('id')
            ->get();

        return Inertia::render('Incidents/Show', [
            'incident' => $incident,
            'tab' => $request->string('tab', 'overview')->toString(),
            // Fetched separately rather than via load() above: Incident::auditLogs() is
            // deliberately unordered (Eloquent appends orderBy rather than replacing it,
            // so a hardcoded ->latest() on the relation would silently break any future
            // caller that tries to reorder it) - ordering is applied explicitly here instead.
            // Secondary sort by id: created_at has only second-level precision, and a single
            // save() can trigger multiple audit log rows (e.g. assignInvestigator() writes
            // both an "assigned" and a "status_changed" row) within the same second, so
            // created_at alone cannot reliably order same-second rows chronologically.
            'auditLogs' => $incident->auditLogs()->with('actor')->latest()->latest('id')->get(),
            'investigation' => $investigation ? new InvestigationResource($investigation) : null,
            'investigators' => $user->can('assign', $incident)
                ? $pickerEntries($directory()->filter(fn (User $candidate) => $candidate->role === Role::Investigator), ['id', 'name'])
                : [],
            'potentialTeamMembers' => ($canStartInvestigation || $canManageInvestigationTeam)
                ? $pickerEntries($directory(), ['id', 'name', 'role'])
                : [],
            'correctiveActions' => CorrectiveActionResource::collection($correctiveActions),
            'approvals' => ApprovalResource::collection($approvals),
            'investigationFindings' => $investigation
                ? InvestigationFindingResource::collection($investigation->findings)
                : [],
            // Only CapaPanel's create form (can.createCorrectiveAction) and per-action
            // edit form (action.can.update) read this list.
            'potentialResponsibleUsers' => ($canCreateCorrectiveAction || $canEditAnyCorrectiveAction)
                ? $pickerEntries($directory(), ['id', 'name'])
                : [],
            'departments' => Department::options(),
            'can' => [
                'update' => $user->can('update', $incident),
                'review' => $user->can('review', $incident),
                'assess' => $user->can('assess', $incident),
                'completeAssessment' => $user->can('completeAssessment', $incident),
                'changeDepartment' => $user->can('changeDepartment', $incident),
                'assign' => $user->can('assign', $incident),
                'startInvestigation' => $canStartInvestigation,
                'manageInvestigationTeam' => $canManageInvestigationTeam,
                'recordFindings' => $investigation && $user->can('recordFindings', $investigation),
                'completeInvestigation' => $investigation && $user->can('complete', $investigation),
                'createCorrectiveAction' => $canCreateCorrectiveAction,
                'requestApproval' => $user->can('requestApproval', $incident),
                'markNoCorrectiveActionNeeded' => $user->can('markNoCorrectiveActionNeeded', $incident),
            ],
        ]);
    }

    /**
     * "Name — Section" for user pickers: tdh_user has many active people
     * sharing a name (and leading-space username twins), so the name alone
     * lets someone pick the wrong account. Falls back to the designation,
     * then to the user id.
     */
    private function pickerLabel(User $user): string
    {
        $qualifier = trim((string) $user->department?->name);

        if ($qualifier === '' || $qualifier === '-') {
            $qualifier = trim((string) $user->designation_title);
        }

        if ($qualifier === '') {
            $qualifier = "#{$user->id}";
        }

        return "{$user->name} — {$qualifier}";
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
