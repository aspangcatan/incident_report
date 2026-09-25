<?php

namespace App\Http\Requests\Incidents;

use App\Enums\ActionStatus;
use App\Enums\Severity;
use App\Models\Department;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class SaveAssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('assess', $this->route('incident'));
    }

    public function rules(): array
    {
        return [
            'recommendations' => ['nullable', 'string'],
            'severity' => ['nullable', new Enum(Severity::class)],
            'department_id' => ['nullable', Department::selectableRule()],
            'actions_taken' => ['array'],
            'actions_taken.*.description' => ['required_with:actions_taken', 'string'],
            'actions_taken.*.responsible_name' => ['nullable', 'string', 'max:255'],
            'actions_taken.*.performed_at' => ['nullable', 'date'],
            'actions_taken.*.status' => ['nullable', new Enum(ActionStatus::class)],
        ];
    }

    /** Validated data minus the fields this user may not change. */
    public function assessmentData(): array
    {
        $data = $this->validated();
        $incident = $this->route('incident');

        if (! $this->user()->can('completeAssessment', $incident)) {
            unset($data['severity']);
        }

        if (! $this->user()->can('changeDepartment', $incident) || empty($data['department_id'])) {
            unset($data['department_id']);
        }

        return $data;
    }
}
