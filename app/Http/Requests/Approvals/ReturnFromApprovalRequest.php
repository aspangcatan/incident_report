<?php

namespace App\Http\Requests\Approvals;

use App\DataTransferObjects\Approvals\DecideApprovalData;
use Illuminate\Foundation\Http\FormRequest;

class ReturnFromApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        $approval = $this->route('approval');

        return $this->user()->can('returnFromApproval', [$approval->incident, $approval]);
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
