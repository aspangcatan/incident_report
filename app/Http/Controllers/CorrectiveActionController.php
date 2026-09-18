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
use App\Models\CorrectiveAction;
use App\Models\Incident;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CorrectiveActionController extends Controller
{
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

        return back()->with('success', 'Corrective action completed and sent for verification.');
    }

    public function verify(VerifyCorrectiveActionRequest $request, CorrectiveAction $correctiveAction, VerifyCorrectiveActionAction $action): RedirectResponse
    {
        $action($correctiveAction, $request->user(), $request->toDto());

        return back()->with('success', 'Corrective action verified.');
    }
}
