<?php

namespace App\Http\Requests\Concerns;

use App\Enums\ActionStatus;
use App\Enums\PersonType;
use App\Enums\Severity;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

trait ValidatesIncidentData
{
    protected function incidentRules(): array
    {
        $submitting = $this->input('action') === 'submit';
        $required = $submitting ? 'required' : 'nullable';

        return [
            'action' => ['required', 'in:draft,submit'],
            'incident_type_id' => [$required, 'exists:incident_types,id'],
            'department_id' => [$required, Rule::exists(config('tdh.connection') . '.section', 'id')],
            'severity' => [$required, new Enum(Severity::class)],
            'occurred_at' => [$required, 'date'],
            'location' => [$required, 'string', 'max:255'],
            'summary' => [$required, 'string'],
            'recommendations' => ['nullable', 'string'],
            'legal_attestation' => [$submitting ? 'accepted' : 'nullable'],

            'police_notified' => ['boolean'],
            'police_station' => ['nullable', 'string', 'max:255'],
            'police_officer_in_charge' => ['nullable', 'string', 'max:255'],
            'police_blotter_no' => ['nullable', 'string', 'max:255'],
            'police_notified_at' => ['nullable', 'date'],

            'individuals' => ['array'],
            'individuals.*.person_type' => ['required_with:individuals', new Enum(PersonType::class)],
            'individuals.*.name' => ['required_with:individuals', 'string', 'max:255'],
            'individuals.*.identifier' => ['nullable', 'string', 'max:255'],
            'individuals.*.role_description' => ['nullable', 'string', 'max:255'],
            'individuals.*.department_id' => ['nullable', Rule::exists(config('tdh.connection') . '.section', 'id')],
            'individuals.*.details' => ['nullable', 'string'],

            'witnesses' => ['array'],
            'witnesses.*.name' => ['required_with:witnesses', 'string', 'max:255'],
            'witnesses.*.designation' => ['nullable', 'string', 'max:255'],
            'witnesses.*.address' => ['nullable', 'string', 'max:255'],
            'witnesses.*.contact_number' => ['nullable', 'string', 'max:50'],
            'witnesses.*.statement' => ['nullable', 'string'],

            'narrative_events' => ['array'],
            'narrative_events.*.occurred_at' => ['nullable', 'string', 'max:50'],
            'narrative_events.*.description' => ['required_with:narrative_events', 'string'],

            'actions_taken' => ['array'],
            'actions_taken.*.description' => ['required_with:actions_taken', 'string'],
            'actions_taken.*.responsible_name' => ['nullable', 'string', 'max:255'],
            'actions_taken.*.performed_at' => ['nullable', 'date'],
            'actions_taken.*.status' => ['nullable', new Enum(ActionStatus::class)],

            'contributing_factor_ids' => ['array'],
            'contributing_factor_ids.*' => ['exists:contributing_factors,id'],

            'attachments' => ['array'],
            'attachments.*' => ['file', 'max:25600', 'mimes:pdf,png,jpg,jpeg,doc,docx'],
        ];
    }
}
