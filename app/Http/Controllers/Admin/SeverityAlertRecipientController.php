<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Severity;
use App\Http\Controllers\Controller;
use App\Support\SeverityAlertSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** IT Admin settings: who receives the severity alert at each of the five fixed levels. */
class SeverityAlertRecipientController extends Controller
{
    public function index(): Response
    {
        $this->authorize('manageSeverityAlerts');

        return Inertia::render('Admin/SeverityAlerts', [
            'levels' => collect(Severity::cases())->map(fn (Severity $level) => [
                'value' => $level->value,
                'numeral' => $level->romanNumeral(),
                'label' => $level->label(),
            ])->values(),
            'groups' => collect(SeverityAlertSettings::options())->map(fn (array $option, string $key) => [
                'value' => $key,
                'label' => $option[0],
                'scope' => $option[1],
            ])->values(),
            'recipients' => SeverityAlertSettings::all(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorize('manageSeverityAlerts');

        $rules = ['recipients' => ['required', 'array']];
        foreach (Severity::cases() as $level) {
            $rules["recipients.{$level->value}"] = ['present', 'array'];
            $rules["recipients.{$level->value}.*"] = ['string', 'distinct', Rule::in(SeverityAlertSettings::keys())];
        }

        $data = $request->validate($rules, [
            'recipients.*.*.in' => 'Unknown recipient group.',
        ]);

        SeverityAlertSettings::save($data['recipients']);

        return back()->with('success', 'Severity alert recipients saved.');
    }
}
