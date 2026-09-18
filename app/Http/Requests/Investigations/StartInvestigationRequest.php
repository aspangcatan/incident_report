<?php

namespace App\Http\Requests\Investigations;

use App\DataTransferObjects\Investigations\StartInvestigationData;
use App\Enums\InvestigationMethodology;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StartInvestigationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('start', $this->route('incident'));
    }

    public function rules(): array
    {
        return [
            'objective' => ['required', 'string'],
            'methodology' => ['required', new Enum(InvestigationMethodology::class)],
            'target_completion_at' => ['nullable', 'date', 'after:today'],
            'team_members' => ['nullable', 'array'],
            'team_members.*.user_id' => ['required_with:team_members', Rule::exists('users', 'id')],
            'team_members.*.role_in_team' => ['required_with:team_members', 'string'],
        ];
    }

    public function toDto(): StartInvestigationData
    {
        return StartInvestigationData::fromArray($this->validated());
    }
}
