<?php

namespace App\Http\Requests\Incidents;

use App\Http\Requests\Concerns\ValidatesIncidentData;
use App\Models\Department;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGuestReportRequest extends FormRequest
{
    use ValidatesIncidentData;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'website' => ['prohibited'], // honeypot: hidden from people, filled by bots
            'guest_name' => ['required', 'string', 'max:255'],
            'guest_contact' => ['required', 'string', 'max:255'],
            'guest_relationship' => ['required', Rule::in(['patient', 'relative', 'visitor', 'other'])],
            ...$this->incidentTypeRules(true),
            'department_id' => ['nullable', Department::selectableRule()],
            'occurred_at' => ['required', 'date', 'before_or_equal:now'],
            'location' => ['required', 'string', 'max:255'],
            ...$this->injuryRules(true),
            'summary' => ['required', 'string'],
            'legal_attestation' => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return $this->incidentMessages();
    }

    public function attributes(): array
    {
        return $this->incidentAttributes();
    }
}
