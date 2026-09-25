<?php

namespace App\Http\Requests\Incidents;

use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

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
                'integer',
                // Same rule as the picker on the incident page (User::canInvestigate()).
                function (string $attribute, mixed $value, Closure $fail) {
                    $candidate = User::find($value);

                    if ($candidate === null || ! $candidate->canInvestigate($this->route('incident'))) {
                        $fail("Choose an active investigator or a staff member of this incident's department.");
                    }
                },
            ],
            'target_closure_date' => ['nullable', 'date', 'after:today'],
        ];
    }
}
