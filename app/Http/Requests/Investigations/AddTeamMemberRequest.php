<?php

namespace App\Http\Requests\Investigations;

use App\DataTransferObjects\Investigations\AddTeamMemberData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddTeamMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageTeam', $this->route('investigation'));
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', Rule::exists('users', 'id')],
            'role_in_team' => ['required', 'string'],
        ];
    }

    public function toDto(): AddTeamMemberData
    {
        return AddTeamMemberData::fromArray($this->validated());
    }
}
