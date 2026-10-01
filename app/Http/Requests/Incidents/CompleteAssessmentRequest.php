<?php

namespace App\Http\Requests\Incidents;

use App\Enums\Severity;
use Illuminate\Validation\Rules\Enum;

class CompleteAssessmentRequest extends SaveAssessmentRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('completeAssessment', $this->route('incident'));
    }

    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'severity' => ['required', new Enum(Severity::class)],
            'actions_taken' => ['required', 'array', 'min:1'],
        ]);
    }

    public function messages(): array
    {
        return [
            'actions_taken.required' => 'Add at least one immediate action taken before completing the assessment.',
            'actions_taken.min' => 'Add at least one immediate action taken before completing the assessment.',
            'actions_taken.*.description.required_with' => 'Describe what was done in action :position, or remove it.',
        ];
    }
}
