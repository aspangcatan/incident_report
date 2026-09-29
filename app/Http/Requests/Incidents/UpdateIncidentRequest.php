<?php

namespace App\Http\Requests\Incidents;

use App\Http\Requests\Concerns\ValidatesIncidentData;
use Illuminate\Foundation\Http\FormRequest;

class UpdateIncidentRequest extends FormRequest
{
    use ValidatesIncidentData;

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('incident'));
    }

    public function rules(): array
    {
        return $this->incidentRules();
    }

    public function messages(): array
    {
        return $this->incidentMessages();
    }
}
