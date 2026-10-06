<?php

namespace App\Http\Requests\IncidentTypes;

use App\DataTransferObjects\IncidentTypes\IncidentTypeData;
use App\Enums\Severity;
use App\Models\IncidentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Unique;

class StoreIncidentTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', IncidentType::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', $this->uniqueName()],
            'category' => ['required', Rule::in(array_keys(IncidentType::CATEGORIES))],
            'default_severity' => ['nullable', new Enum(Severity::class)],
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Enter a name for the incident type.',
            'name.unique' => 'An incident type with this name already exists.',
            'category.required' => 'Choose a category.',
            'category.in' => 'Choose a category from the list.',
            'default_severity.Illuminate\Validation\Rules\Enum' => 'Choose a severity level from the list.',
        ];
    }

    protected function uniqueName(): Unique
    {
        return Rule::unique('incident_types', 'name');
    }

    public function toDto(): IncidentTypeData
    {
        return IncidentTypeData::fromArray($this->validated());
    }
}
