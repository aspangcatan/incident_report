<?php

namespace App\Http\Requests\Investigations;

use App\DataTransferObjects\Investigations\CompleteInvestigationData;
use Illuminate\Foundation\Http\FormRequest;

class CompleteInvestigationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('complete', $this->route('investigation'));
    }

    public function rules(): array
    {
        return [
            'conclusion' => ['required', 'string'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->route('investigation')->findings()->count() === 0) {
                $validator->errors()->add('conclusion', 'Add at least one finding before completing the investigation.');
            }
        });
    }

    public function toDto(): CompleteInvestigationData
    {
        return CompleteInvestigationData::fromArray($this->validated());
    }
}
