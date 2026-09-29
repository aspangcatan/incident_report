<?php

namespace App\Http\Requests\Incidents;

use App\Enums\Severity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class ReviewIncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('review', $this->route('incident'));
    }

    public function rules(): array
    {
        return [
            'comments' => ['nullable', 'string'],
            'severity' => ['nullable', new Enum(Severity::class)],
        ];
    }
}
