<?php

namespace App\Http\Controllers;

use App\Actions\CorrectiveActions\CompleteCorrectiveActionAction;
use App\Actions\CorrectiveActions\CreateCorrectiveActionAction;
use App\Actions\CorrectiveActions\MarkCorrectiveActionInProgressAction;
use App\Actions\CorrectiveActions\UpdateCorrectiveActionAction;
use App\Actions\CorrectiveActions\VerifyCorrectiveActionAction;
use App\Http\Requests\CorrectiveActions\CompleteCorrectiveActionRequest;
use App\Http\Requests\CorrectiveActions\CreateCorrectiveActionRequest;
use App\Http\Requests\CorrectiveActions\UpdateCorrectiveActionRequest;
use App\Http\Requests\CorrectiveActions\VerifyCorrectiveActionRequest;
use App\Enums\EvidenceStage;
use App\Models\CorrectiveAction;
use App\Services\EvidenceService;
use App\Models\Incident;
use App\Queries\CorrectiveActionQueueQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CorrectiveActionController extends Controller
{
    public function index(Request $request): Response
    {
        $queue = $request->string('queue', 'open')->toString();
        abort_unless(CorrectiveActionQueueQuery::exists($queue), 404);
        abort_unless(CorrectiveActionQueueQuery::allowed($request->user()), 403);

        [$title, $description] = CorrectiveActionQueueQuery::QUEUES[$queue];

        $actions = CorrectiveActionQueueQuery::builder($queue, $request->user())
            ->with(['incident:id,incident_number', 'responsibleUser'])
            ->orderBy('due_date')->orderBy('id')
            ->paginate(15)->withQueryString()
            ->through(fn (CorrectiveAction $action) => [
                'id' => $action->id,
                'capa_number' => $action->capa_number,
                'description' => $action->description,
                'incident' => ['id' => $action->incident->id, 'incident_number' => $action->incident->incident_number],
                'responsible' => $action->responsibleUser?->name,
                'priority' => $action->priority->label(),
                'due_date' => $action->due_date?->toDateString(),
                // Same rule as the Overdue queue and escalation: due before today, not verified.
                'is_overdue' => $action->due_date !== null && $action->isOverdue(),
                'status' => ['value' => $action->status->value, 'label' => $action->status->label()],
            ]);

        return Inertia::render('CorrectiveActions/Index', [
            'actions' => $actions,
            'queue' => ['key' => $queue, 'title' => $title, 'description' => $description],
        ]);
    }

    public function store(CreateCorrectiveActionRequest $request, Incident $incident, CreateCorrectiveActionAction $action): RedirectResponse
    {
        $action($incident, $request->toDto());

        return redirect()
            ->route('incidents.show', ['incident' => $incident, 'tab' => 'capa'])
            ->with('success', 'Corrective action added.');
    }

    public function update(UpdateCorrectiveActionRequest $request, CorrectiveAction $correctiveAction, UpdateCorrectiveActionAction $action): RedirectResponse
    {
        $action($correctiveAction, $request->toDto());

        return back()->with('success', 'Corrective action updated.');
    }

    public function progress(Request $request, CorrectiveAction $correctiveAction, MarkCorrectiveActionInProgressAction $action): RedirectResponse
    {
        $this->authorize('progress', $correctiveAction);

        $action($correctiveAction);

        return back()->with('success', 'Marked in progress.');
    }

    public function complete(CompleteCorrectiveActionRequest $request, CorrectiveAction $correctiveAction, CompleteCorrectiveActionAction $action): RedirectResponse
    {
        $action($correctiveAction, $request->toDto());

        // Proof of completion goes into the incident's evidence, linked to this CAPA.
        app(EvidenceService::class)->store(
            $correctiveAction->incident,
            $request->user(),
            $request->file('attachments', []),
            EvidenceStage::Capa,
            $correctiveAction->id,
        );

        return back()->with('success', 'Corrective action completed and sent for verification.');
    }

    public function verify(VerifyCorrectiveActionRequest $request, CorrectiveAction $correctiveAction, VerifyCorrectiveActionAction $action): RedirectResponse
    {
        $action($correctiveAction, $request->user(), $request->toDto());

        return back()->with('success', 'Corrective action verified.');
    }
}
