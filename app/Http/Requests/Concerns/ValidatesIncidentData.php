<?php

namespace App\Http\Requests\Concerns;

use App\Enums\InjuryAgent;
use App\Enums\InjuryCause;
use App\Enums\PersonType;
use App\Models\Department;
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
            ...$this->incidentTypeRules($submitting),
            'department_id' => [$required, Department::selectableRule()],
            'occurred_at' => [$required, 'date'],
            'location' => [$required, 'string', 'max:255'],
            ...$this->injuryRules($submitting),
            'summary' => [$required, 'string'],
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
            'individuals.*.department_id' => ['nullable', Department::selectableRule()],
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

            'attachments' => ['array'],
            'attachments.*' => ['file', 'max:25600', 'mimes:pdf,png,jpg,jpeg,doc,docx'],
        ];
    }

    /** At least one listed type, or the reporter's own "Others (Specify)" text. */
    protected function incidentTypeRules(bool $submitting): array
    {
        return [
            'incident_type_ids' => [$submitting ? 'required_without:incident_type_other' : 'nullable', 'array'],
            'incident_type_ids.*' => [Rule::exists('incident_types', 'id')->where('is_active', true)],
            'incident_type_other' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** "Was anyone injured?" — if yes, at least one cause and one agent (a listed choice or Others). */
    protected function injuryRules(bool $submitting): array
    {
        $needs = fn (string $otherField) => Rule::requiredIf(fn () => $submitting
            && $this->boolean('has_injury')
            && blank($this->input($otherField)));

        return [
            'has_injury' => [$submitting ? 'required' : 'nullable', 'boolean'],
            'injury_causes' => [$needs('injury_cause_other'), 'nullable', 'array'],
            'injury_causes.*' => [new Enum(InjuryCause::class)],
            'injury_cause_other' => ['nullable', 'string', 'max:255'],
            'injury_agents' => [$needs('injury_agent_other'), 'nullable', 'array'],
            'injury_agents.*' => [new Enum(InjuryAgent::class)],
            'injury_agent_other' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function incidentMessages(): array
    {
        return [
            'incident_type_ids.required_without' => 'Choose at least one incident type, or tick Others and specify it.',
            'has_injury.required' => 'Answer whether anyone was injured.',
            'injury_causes.required' => 'Choose at least one cause of injury, or tick Others and specify it.',
            'injury_agents.required' => 'Choose at least one agent of injury, or tick Others and specify it.',
        ];
    }
}
