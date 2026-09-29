<?php

namespace App\Http\Requests\SafetyAlerts;

use App\Enums\AlertUrgency;
use App\Models\Department;
use App\Models\SafetyAlert;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreSafetyAlertRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', SafetyAlert::class);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string'],
            'urgency' => ['required', new Enum(AlertUrgency::class)],
            'audience' => ['required', Rule::in(['all', 'departments'])],
            'department_ids' => ['exclude_unless:audience,departments', 'required', 'array', 'min:1'],
            'department_ids.*' => ['integer', 'distinct', Department::selectableRule()],
            'incident_id' => ['nullable', 'integer', 'exists:incidents,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'department_ids.required' => 'Choose at least one department.',
            'department_ids.min' => 'Choose at least one department.',
            'urgency.required' => 'Choose how urgent this alert is.',
        ];
    }
}
