<?php

namespace App\Http\Requests\Incidents;

use App\Http\Requests\Concerns\ValidatesIncidentData;
use App\Models\Incident;
use Illuminate\Foundation\Http\FormRequest;

class StoreIncidentRequest extends FormRequest
{
    use ValidatesIncidentData;

    public function authorize(): bool
    {
        return $this->user()->can('create', Incident::class);
    }

    public function rules(): array
    {
        return $this->incidentRules();
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
