<?php

namespace App\Http\Requests\CorrectiveActions;

use App\DataTransferObjects\CorrectiveActions\CorrectiveActionData;
use App\Enums\CorrectiveActionPriority;
use App\Enums\CorrectiveActionType;
use App\Models\CorrectiveAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class CreateCorrectiveActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [CorrectiveAction::class, $this->route('incident')]);
    }

    public function rules(): array
    {
        return [
            'description' => ['required', 'string'],
            'action_type' => ['required', new Enum(CorrectiveActionType::class)],
            'priority' => ['required', new Enum(CorrectiveActionPriority::class)],
            'due_date' => ['required', 'date', 'after:today'],
            'responsible_user_id' => ['nullable', Rule::exists('users', 'id')],
            'responsible_department_id' => ['nullable', Rule::exists('departments', 'id')],
            'root_cause_finding_id' => [
                'nullable',
                Rule::exists('investigation_findings', 'id')
                    ->where('investigation_id', $this->route('incident')->investigation?->id),
            ],
        ];
    }

    public function toDto(): CorrectiveActionData
    {
        return CorrectiveActionData::fromArray($this->validated());
    }
}
