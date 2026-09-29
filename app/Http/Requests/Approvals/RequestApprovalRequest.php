<?php

namespace App\Http\Requests\Approvals;

use Illuminate\Foundation\Http\FormRequest;

class RequestApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('requestApproval', $this->route('incident'));
    }

    public function rules(): array
    {
        return [
            'lessons_learned' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return ['lessons_learned.required' => 'Write what the hospital should learn from this incident.'];
    }
}
