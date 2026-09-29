<?php

namespace App\Http\Requests\Investigations;

use App\DataTransferObjects\Investigations\FindingData;
use App\Enums\RootCauseType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateFindingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('recordFindings', $this->route('investigation'));
    }

    public function rules(): array
    {
        return [
            'category' => ['nullable', 'required_if:is_root_cause,true', new Enum(RootCauseType::class)],
            'question' => ['nullable', 'string'],
            'finding' => ['required', 'string'],
            'is_root_cause' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return ['category.required_if' => 'Choose what kind of cause this is.'];
    }

    public function toDto(): FindingData
    {
        return FindingData::fromArray($this->validated());
    }
}
