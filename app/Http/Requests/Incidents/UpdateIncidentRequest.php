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

    protected function keptIncidentTypeIds(): array
    {
        return $this->route('incident')->incidentTypes()->pluck('incident_types.id')->all();
    }

    public function messages(): array
    {
        return $this->incidentMessages();
    }

    public function attributes(): array
    {
        return $this->incidentAttributes();
    }
}
