<?php

namespace App\Http\Controllers;

use App\Enums\AlertUrgency;
use App\Http\Requests\SafetyAlerts\StoreSafetyAlertRequest;
use App\Models\Department;
use App\Models\Incident;
use App\Models\SafetyAlert;
use App\Models\User;
use App\Services\SafetyAlertService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SafetyAlertController extends Controller
{
    public function __construct(private SafetyAlertService $alerts)
    {
    }

    public function index(Request $request): Response
    {
        $user = $request->user();

        $alerts = SafetyAlert::visibleTo($user)
            ->with('departments')
            ->withCount('acknowledgements')
            ->latest('id')
            ->paginate(20)
            ->through(fn (SafetyAlert $alert) => [
                ...$this->summary($alert),
                'acknowledged' => $alert->isAcknowledgedBy($user),
                'addressed_to_me' => $alert->isAddressedTo($user),
                'acknowledgements_count' => $user->can('viewAcknowledgements', $alert) ? $alert->acknowledgements_count : null,
            ]);

        return Inertia::render('SafetyAlerts/Index', [
            'alerts' => $alerts,
            'canCreate' => $user->can('create', SafetyAlert::class),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', SafetyAlert::class);

        $incident = $request->integer('incident') ? Incident::find($request->integer('incident')) : null;

        return Inertia::render('SafetyAlerts/Create', [
            'departments' => Department::options(),
            'urgencies' => collect(AlertUrgency::cases())->map(fn (AlertUrgency $u) => ['value' => $u->value, 'label' => $u->label()]),
            'incident' => $incident ? ['id' => $incident->id, 'incident_number' => $incident->incident_number] : null,
        ]);
    }

    public function store(StoreSafetyAlertRequest $request): RedirectResponse
    {
        $alert = $this->alerts->issue($request->user(), $request->validated());

        return redirect()->route('safety-alerts.show', $alert)->with('success', 'Safety alert issued.');
    }

    public function show(Request $request, SafetyAlert $safetyAlert): Response
    {
        $this->authorize('view', $safetyAlert);
        $user = $request->user();
        $safetyAlert->load(['departments', 'creator', 'incident']);

        $tracking = null;
        if ($user->can('viewAcknowledgements', $safetyAlert)) {
            $acks = $safetyAlert->acknowledgements()->with('user')->latest('acknowledged_at')->get();
            $ackedIds = $acks->pluck('user_id')->all();
            $tracking = [
                'acknowledged' => $acks->map(fn ($ack) => ['name' => $ack->user?->name ?? "#{$ack->user_id}", 'at' => $ack->acknowledged_at])->values(),
                'pending' => $safetyAlert->recipients()->whereNotIn('id', $ackedIds)->orderByName()->get()->map(fn (User $u) => $u->name)->values(),
            ];
        }

        return Inertia::render('SafetyAlerts/Show', [
            'alert' => [
                ...$this->summary($safetyAlert),
                'creator' => $safetyAlert->creator?->name,
                'incident' => $safetyAlert->incident ? [
                    'id' => $safetyAlert->incident->id,
                    'incident_number' => $safetyAlert->incident->incident_number,
                    'can_view' => $user->can('view', $safetyAlert->incident),
                ] : null,
            ],
            'acknowledged' => $safetyAlert->isAcknowledgedBy($user),
            'canAcknowledge' => $user->can('acknowledge', $safetyAlert),
            'tracking' => $tracking,
        ]);
    }

    public function acknowledge(Request $request, SafetyAlert $safetyAlert): RedirectResponse
    {
        $this->authorize('acknowledge', $safetyAlert);
        $this->alerts->acknowledge($safetyAlert, $request->user());

        return back()->with('success', 'Thank you - marked as read.');
    }

    private function summary(SafetyAlert $alert): array
    {
        $departmentNames = $alert->audience === 'departments'
            ? Department::whereIn('id', $alert->departments->pluck('department_id'))->get()->map->name->values()->all()
            : [];

        return [
            'id' => $alert->id,
            'title' => $alert->title,
            'message' => $alert->message,
            'urgency' => ['value' => $alert->urgency->value, 'label' => $alert->urgency->label()],
            'audience' => $alert->audience === 'all' ? 'All staff' : implode(', ', $departmentNames),
            'created_at' => $alert->created_at,
        ];
    }
}
