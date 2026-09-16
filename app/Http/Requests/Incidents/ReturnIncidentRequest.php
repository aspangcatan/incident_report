<?php

namespace App\Http\Requests\Incidents;

use Illuminate\Foundation\Http\FormRequest;

class ReturnIncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('review', $this->route('incident'));
    }

    public function rules(): array
    {
        return [
            'comments' => ['required', 'string'],
        ];
    }
}
