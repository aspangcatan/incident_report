<?php

namespace App\Http\Requests\Incidents;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignIncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('assign', $this->route('incident'));
    }

    public function rules(): array
    {
        return [
            'assigned_investigator_id' => [
                'required',
                Rule::exists('users', 'id')->where(fn ($query) => $query->where('role', Role::Investigator->value)),
            ],
            'target_closure_date' => ['nullable', 'date', 'after:today'],
        ];
    }
}
