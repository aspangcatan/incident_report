<?php

namespace App\Http\Requests\Incidents;

use App\Models\Department;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGuestReportRequest extends FormRequest
{
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
            'incident_type_id' => ['required', Rule::exists('incident_types', 'id')->where('is_active', true)],
            'department_id' => ['nullable', Department::selectableRule()],
            'occurred_at' => ['required', 'date', 'before_or_equal:now'],
            'location' => ['required', 'string', 'max:255'],
            'summary' => ['required', 'string'],
            'legal_attestation' => ['accepted'],
        ];
    }
}
