<?php

namespace App\Http\Requests\Approvals;

use App\DataTransferObjects\Approvals\DecideApprovalData;
use Illuminate\Foundation\Http\FormRequest;

class ReturnFromApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('returnFromApproval', $this->route('approval')->incident);
    }

    public function rules(): array
    {
        return [
            'comments' => ['required', 'string'],
        ];
    }

    public function toDto(): DecideApprovalData
    {
        return DecideApprovalData::fromArray($this->validated());
    }
}
