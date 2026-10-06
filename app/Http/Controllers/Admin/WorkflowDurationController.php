<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Severity;
use App\Http\Controllers\Controller;
use App\Support\WorkflowDurations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

/** IT Admin settings: workflow time limits. The five severity levels are fixed. */
class WorkflowDurationController extends Controller
{
    public function index(): Response
    {
        $this->authorize('manageWorkflowDurations');

        return Inertia::render('Admin/WorkflowDurations', [
            'levels' => collect(Severity::cases())->map(fn (Severity $level) => [
                'value' => $level->value,
                'numeral' => $level->romanNumeral(),
                'label' => $level->label(),
            ])->values(),
            'values' => Arr::undot(WorkflowDurations::all()),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorize('manageWorkflowDurations');

        $hours = ['required', 'integer', 'min:1', 'max:8760'];
        $rules = collect(WorkflowDurations::keys())->mapWithKeys(fn (string $key) => [$key => $hours])->all();
        $rules['effectiveness_wait_days'] = ['required', 'integer', 'min:0', 'max:365'];

        $data = $request->validate($rules, [
            '*.required' => 'Enter a number.',
            '*.*.required' => 'Enter a number.',
            '*.integer' => 'Use a whole number.',
            '*.*.integer' => 'Use a whole number.',
            '*.*.min' => 'Use at least :min.',
            '*.*.max' => 'Use :max or less.',
            'assessment_sla_hours.min' => 'Use at least :min.',
            'assessment_sla_hours.max' => 'Use :max or less.',
            'effectiveness_wait_days.min' => 'Use at least :min.',
            'effectiveness_wait_days.max' => 'Use :max or less.',
        ]);

        WorkflowDurations::save(Arr::only(Arr::dot($data), WorkflowDurations::keys()));

        return back()->with('success', 'Workflow durations saved.');
    }
}
