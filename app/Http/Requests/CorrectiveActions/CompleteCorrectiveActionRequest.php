<?php

namespace App\Http\Requests\CorrectiveActions;

use App\DataTransferObjects\CorrectiveActions\CompleteCorrectiveActionData;
use Illuminate\Foundation\Http\FormRequest;

class CompleteCorrectiveActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('complete', $this->route('correctiveAction'));
    }

    public function rules(): array
    {
        return [
            'completion_notes' => ['required', 'string'],
        ];
    }

    public function toDto(): CompleteCorrectiveActionData
    {
        return CompleteCorrectiveActionData::fromArray($this->validated());
    }
}
