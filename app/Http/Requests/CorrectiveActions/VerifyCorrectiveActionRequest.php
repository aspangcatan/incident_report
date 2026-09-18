<?php

namespace App\Http\Requests\CorrectiveActions;

use App\DataTransferObjects\CorrectiveActions\VerifyCorrectiveActionData;
use Illuminate\Foundation\Http\FormRequest;

class VerifyCorrectiveActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('verify', $this->route('correctiveAction'));
    }

    public function rules(): array
    {
        return [
            'verification_comments' => ['required', 'string'],
        ];
    }

    public function toDto(): VerifyCorrectiveActionData
    {
        return VerifyCorrectiveActionData::fromArray($this->validated());
    }
}
