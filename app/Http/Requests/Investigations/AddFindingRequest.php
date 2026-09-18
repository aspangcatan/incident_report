<?php

namespace App\Http\Requests\Investigations;

use App\DataTransferObjects\Investigations\FindingData;
use Illuminate\Foundation\Http\FormRequest;

class AddFindingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('recordFindings', $this->route('investigation'));
    }

    public function rules(): array
    {
        return [
            'category' => ['nullable', 'string', 'max:100'],
            'question' => ['nullable', 'string'],
            'finding' => ['required', 'string'],
            'is_root_cause' => ['boolean'],
        ];
    }

    public function toDto(): FindingData
    {
        return FindingData::fromArray($this->validated());
    }
}
