<?php

namespace App\Http\Controllers;

use App\Enums\RecurrenceReviewStatus;
use App\Http\Requests\RecurrenceReviews\StoreRecurrenceReviewRequest;
use App\Models\Department;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\RecurrenceReview;
use App\Models\User;
use App\Services\RecurrenceReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Recurrence Prevention: tracked reviews of recurring patterns. */
class RecurrenceReviewController extends Controller
{
    public function __construct(private RecurrenceReviewService $reviews)
    {
    }

    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('RecurrenceReviews/Index', [
            'reviews' => RecurrenceReview::visibleTo($user)
                ->with(['department', 'incidentType', 'assignee'])
                ->orderByRaw("CASE status WHEN 'submitted' THEN 0 WHEN 'open' THEN 1 ELSE 2 END")
                ->latest('id')
                ->paginate(20)
                ->through(fn (RecurrenceReview $r) => $this->summary($r)),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', RecurrenceReview::class);
        $departmentId = $request->integer('department_id');
        $typeId = $request->integer('incident_type_id');
        abort_unless($departmentId && $typeId, 404);

        $department = Department::findOrFail($departmentId);
        $type = IncidentType::findOrFail($typeId);

        return Inertia::render('RecurrenceReviews/Create', [
            'department' => ['id' => $department->id, 'name' => $department->name],
            'incidentType' => ['id' => $type->id, 'name' => $type->name],
            'incidents' => $this->incidentList($request->user(), $department->id, $type->id),
            'assignees' => StoreRecurrenceReviewRequest::assignees($department->id)
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'role' => $u->role->label()])->values(),
            'windowDays' => RecurrenceReviewService::WINDOW_DAYS,
        ]);
    }

    public function store(StoreRecurrenceReviewRequest $request): RedirectResponse
    {
        $review = $this->reviews->open($request->user(), $request->validated());

        return redirect()->route('recurrence-reviews.show', $review)->with('success', 'Recurrence review opened and assigned.');
    }

    public function show(Request $request, RecurrenceReview $recurrenceReview): Response
    {
        $this->authorize('view', $recurrenceReview);
        $user = $request->user();
        $recurrenceReview->load(['department', 'incidentType', 'assignee', 'creator', 'closer']);

        return Inertia::render('RecurrenceReviews/Show', [
            'review' => [
                ...$this->summary($recurrenceReview),
                'cqi_notes' => $recurrenceReview->cqi_notes,
                'fix_description' => $recurrenceReview->fix_description,
                'submitted_at' => $recurrenceReview->submitted_at,
                'decision_comments' => $recurrenceReview->decision_comments,
                'closed_at' => $recurrenceReview->closed_at,
                'closer' => $recurrenceReview->closer?->name,
                'creator' => $recurrenceReview->creator?->name,
                'created_at' => $recurrenceReview->created_at,
            ],
            'incidents' => $this->incidentList($user, $recurrenceReview->department_id, $recurrenceReview->incident_type_id),
            'windowDays' => RecurrenceReviewService::WINDOW_DAYS,
            'can' => [
                'submit' => $user->can('submit', $recurrenceReview),
                'decide' => $user->can('decide', $recurrenceReview),
            ],
        ]);
    }

    public function submit(Request $request, RecurrenceReview $recurrenceReview): RedirectResponse
    {
        $this->authorize('submit', $recurrenceReview);
        $data = $request->validate(['fix_description' => ['required', 'string']], [], ['fix_description' => 'system-level fix']);

        $this->reviews->submit($recurrenceReview, $data['fix_description']);

        return back()->with('success', 'Submitted to the CQI Office.');
    }

    public function decide(Request $request, RecurrenceReview $recurrenceReview): RedirectResponse
    {
        $this->authorize('decide', $recurrenceReview);
        $data = $request->validate([
            'decision' => ['required', 'in:close,return'],
            'comments' => ['required', 'string'],
        ]);

        $data['decision'] === 'close'
            ? $this->reviews->close($recurrenceReview, $request->user(), $data['comments'])
            : $this->reviews->returnToDepartment($recurrenceReview, $data['comments']);

        return back()->with('success', $data['decision'] === 'close' ? 'Recurrence review closed.' : 'Returned to the department.');
    }

    private function summary(RecurrenceReview $review): array
    {
        return [
            'id' => $review->id,
            'department' => $review->department?->name,
            'incident_type' => $review->incidentType?->name,
            'incident_count' => $review->incident_count,
            'status' => ['value' => $review->status->value, 'label' => $review->status->label()],
            'assignee' => $review->assignee?->name,
            'due_date' => $review->due_date?->toDateString(),
            'is_overdue' => $review->isOverdue(),
        ];
    }

    /** The pattern's incidents the viewer is allowed to open. */
    private function incidentList(User $user, int $departmentId, int $typeId): array
    {
        return $this->reviews->patternIncidents($departmentId, $typeId)
            ->visibleTo($user)
            ->latest('reported_at')
            ->get(['id', 'incident_number', 'summary', 'severity', 'reported_at', 'status'])
            ->map(fn (Incident $i) => [
                'id' => $i->id,
                'incident_number' => $i->incident_number,
                'summary' => $i->summary,
                'severity' => $i->severity?->value,
                'status' => $i->status->label(),
                'reported_at' => $i->reported_at,
            ])->all();
    }
}
