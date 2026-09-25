<?php

namespace App\Http\Requests\CorrectiveActions;

use App\DataTransferObjects\CorrectiveActions\CorrectiveActionData;
use App\Enums\CorrectiveActionPriority;
use App\Enums\CorrectiveActionType;
use App\Models\Department;
use App\Models\User;
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
            // Active staff of the incident's department (same check as the
            // picker), or the one already responsible (who may have been
            // deactivated or moved section in tdh_user since) so unrelated
            // edits still save.
            'responsible_user_id' => ['nullable', 'integer', function (string $attribute, $value, $fail) {
                $correctiveAction = $this->route('correctiveAction');

                if ((int) $value === $correctiveAction->responsible_user_id) {
                    return;
                }

                if (! User::find($value)?->canBeResponsibleFor($correctiveAction->incident)) {
                    $fail('The responsible person must be active staff of the incident\'s department.');
                }
            }],
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
