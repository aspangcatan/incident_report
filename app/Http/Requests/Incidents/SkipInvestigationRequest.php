<?php

namespace App\Http\Requests\Incidents;

use Illuminate\Foundation\Http\FormRequest;

class SkipInvestigationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('skipInvestigation', $this->route('incident'));
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return ['reason.required' => 'Explain why no investigation is needed.'];
    }
}
