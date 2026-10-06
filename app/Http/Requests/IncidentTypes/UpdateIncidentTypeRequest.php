<?php

namespace App\Http\Requests\IncidentTypes;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class UpdateIncidentTypeRequest extends StoreIncidentTypeRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('incidentType'));
    }

    protected function uniqueName(): Unique
    {
        return Rule::unique('incident_types', 'name')->ignore($this->route('incidentType'));
    }
}
