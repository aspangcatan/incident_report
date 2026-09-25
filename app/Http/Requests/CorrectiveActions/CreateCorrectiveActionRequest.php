<?php

namespace App\Http\Requests\CorrectiveActions;

use App\DataTransferObjects\CorrectiveActions\CorrectiveActionData;
use App\Enums\CorrectiveActionPriority;
use App\Enums\CorrectiveActionType;
use App\Models\CorrectiveAction;
use App\Models\Department;
use App\Models\User;
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
            // Active staff of the incident's department (same check as the picker).
            'responsible_user_id' => ['nullable', 'integer', function (string $attribute, $value, $fail) {
                if (! User::find($value)?->canBeResponsibleFor($this->route('incident'))) {
                    $fail('The responsible person must be active staff of the incident\'s department.');
                }
            }],
            'responsible_department_id' => ['nullable', Department::selectableRule()],
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
