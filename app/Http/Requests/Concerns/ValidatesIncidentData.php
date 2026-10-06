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
            'incident_type_ids.*' => [Rule::exists('incident_types', 'id')->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $this->keptIncidentTypeIds()))],
            'incident_type_other' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** Type ids that stay valid even if switched off (a draft keeps the types it already has). */
    protected function keptIncidentTypeIds(): array
    {
        return [];
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
            'injury_chemical_details' => [Rule::requiredIf(fn () => $submitting
                && $this->boolean('has_injury')
                && in_array(InjuryAgent::Chemicals->value, (array) $this->input('injury_agents'), true)), 'nullable', 'string', 'max:255'],
        ];
    }

    protected function incidentMessages(): array
    {
        return [
            'incident_type_ids.required_without' => 'Choose at least one incident type, or tick Others and specify it.',
            'incident_type_ids.*.exists' => 'This incident type is no longer available. Untick it and choose another.',
            'has_injury.required' => 'Answer whether anyone was injured.',
            'injury_causes.required' => 'Choose at least one cause of injury, or tick Others and specify it.',
            'injury_agents.required' => 'Choose at least one agent of injury, or tick Others and specify it.',
            'injury_chemical_details.required' => 'Say which chemical was involved.',
            'department_id.required' => 'Choose the department or clinical unit.',
            'occurred_at.required' => 'Enter the date and time of the incident.',
            'location.required' => 'Enter where the incident happened.',
            'summary.required' => 'Describe what happened.',
            'legal_attestation.accepted' => 'Tick the acknowledgment box in Section 1 before submitting.',
            'individuals.*.person_type.required_with' => 'Choose the person type for person :position.',
            'individuals.*.name.required_with' => 'Enter the full name of person :position.',
            'witnesses.*.name.required_with' => 'Enter the full name of witness :position.',
            'narrative_events.*.description.required_with' => 'Describe what happened in event :position, or remove it.',
        ];
    }

    /** Readable field names for any other message, e.g. "The full name of person 1 must not be greater than 255 characters." */
    protected function incidentAttributes(): array
    {
        return [
            'individuals.*.person_type' => 'person type of person :position',
            'individuals.*.name' => 'full name of person :position',
            'individuals.*.identifier' => 'HRN / employee no. of person :position',
            'individuals.*.role_description' => 'role / designation of person :position',
            'individuals.*.details' => 'additional details of person :position',
            'witnesses.*.name' => 'full name of witness :position',
            'witnesses.*.designation' => 'designation of witness :position',
            'witnesses.*.address' => 'address of witness :position',
            'witnesses.*.contact_number' => 'contact number of witness :position',
            'witnesses.*.statement' => 'statement of witness :position',
            'narrative_events.*.occurred_at' => 'time of event :position',
            'narrative_events.*.description' => 'description of event :position',
            'attachments.*' => 'attachment :position',
            'department_id' => 'department',
            'occurred_at' => 'date and time of the incident',
            'police_notified_at' => 'date and time police were notified',
        ];
    }
}
