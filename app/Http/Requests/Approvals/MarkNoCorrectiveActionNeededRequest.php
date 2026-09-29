<?php

namespace App\Http\Requests\Approvals;

use App\DataTransferObjects\Approvals\MarkNoCorrectiveActionNeededData;
use Illuminate\Foundation\Http\FormRequest;

class MarkNoCorrectiveActionNeededRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('markNoCorrectiveActionNeeded', $this->route('incident'));
    }

    public function rules(): array
    {
        return [
            'justification' => ['required', 'string'],
            'lessons_learned' => ['required', 'string'],
        ];
    }

    public function toDto(): MarkNoCorrectiveActionNeededData
    {
        return MarkNoCorrectiveActionNeededData::fromArray($this->validated());
    }
}
