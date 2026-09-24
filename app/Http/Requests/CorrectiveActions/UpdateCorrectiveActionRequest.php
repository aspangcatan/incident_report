<?php

namespace App\Http\Requests\CorrectiveActions;

use App\DataTransferObjects\CorrectiveActions\CorrectiveActionData;
use App\Enums\CorrectiveActionPriority;
use App\Enums\CorrectiveActionType;
use App\Models\Department;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UpdateCorrectiveActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('correctiveAction'));
    }

    public function rules(): array
    {
        return [
            'description' => ['required', 'string'],
            'action_type' => ['required', new Enum(CorrectiveActionType::class)],
            'priority' => ['required', new Enum(CorrectiveActionPriority::class)],
            'due_date' => ['required', 'date'],
            // An active user, or the one already responsible (who may have
            // been deactivated in tdh_user since) so unrelated edits still save.
            'responsible_user_id' => [
                'nullable',
                Rule::exists(config('tdh.connection') . '.users', 'id')->where(fn ($query) => $query
                    ->where('status', '1')
                    ->orWhere('id', $this->route('correctiveAction')->responsible_user_id)),
            ],
            'responsible_department_id' => ['nullable', Department::selectableRule()],
            'root_cause_finding_id' => [
                'nullable',
                Rule::exists('investigation_findings', 'id')
                    ->where('investigation_id', $this->route('correctiveAction')->incident->investigation?->id),
            ],
        ];
    }

    public function toDto(): CorrectiveActionData
    {
        return CorrectiveActionData::fromArray($this->validated());
    }
}
