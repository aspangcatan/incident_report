<?php

namespace App\Http\Requests\Incidents;

use Illuminate\Foundation\Http\FormRequest;

class RecordEffectivenessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('checkEffectiveness', $this->route('incident'));
    }

    public function rules(): array
    {
        return [
            'effective' => ['required', 'boolean'],
            'notes' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'effective.required' => 'Choose whether the actions were effective.',
            'notes.required' => 'Describe the evidence: did it happen again, what did you check?',
        ];
    }
}
