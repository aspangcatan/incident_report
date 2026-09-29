<?php

namespace App\Http\Requests\Investigations;

use App\DataTransferObjects\Investigations\FindingData;
use App\Enums\RcaTool;
use App\Http\Requests\Concerns\ValidatesFindingData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class AddFindingRequest extends FormRequest
{
    use ValidatesFindingData;

    public function authorize(): bool
    {
        return $this->user()->can('recordFindings', $this->route('investigation'));
    }

    public function rules(): array
    {
        return [
            'tool' => ['nullable', new Enum(RcaTool::class)],
            ...$this->findingRules(RcaTool::tryFrom((string) $this->input('tool'))),
        ];
    }

    public function attributes(): array
    {
        return $this->findingAttributes(RcaTool::tryFrom((string) $this->input('tool')));
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
