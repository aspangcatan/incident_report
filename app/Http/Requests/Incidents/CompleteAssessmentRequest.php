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
        ]);
    }
}
