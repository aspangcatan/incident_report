<?php

namespace App\Http\Requests\Investigations;

use App\DataTransferObjects\Investigations\FindingData;
use App\Http\Requests\Concerns\ValidatesFindingData;
use Illuminate\Foundation\Http\FormRequest;

class UpdateFindingRequest extends FormRequest
{
    use ValidatesFindingData;

    public function authorize(): bool
    {
        // A finding of another investigation is "not found" here, before its tool's rules apply.
        abort_unless($this->route('finding')->investigation_id === $this->route('investigation')->id, 404);

        return $this->user()->can('recordFindings', $this->route('investigation'));
    }

    /** A finding keeps the tool it was created with. */
    public function rules(): array
    {
        return $this->findingRules($this->route('finding')?->tool);
    }

    public function attributes(): array
    {
        return $this->findingAttributes($this->route('finding')?->tool);
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
