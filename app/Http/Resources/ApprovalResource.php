<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ApprovalResource extends JsonResource
{
    public function toArray($request)
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
            ],
            'stage' => [
                'value' => $this->stage->value,
                'label' => $this->stage->label(),
            ],
            'request_comments' => $this->request_comments,
            'due_at' => $this->due_at,
            'is_overdue' => $this->isOverdue(),
            'requested_by' => $this->whenLoaded('requestedBy', fn () => $this->requestedBy ? [
                'id' => $this->requestedBy->id,
                'name' => $this->requestedBy->name,
            ] : null),
            'approver' => $this->whenLoaded('approver', fn () => $this->approver ? [
                'id' => $this->approver->id,
                'name' => $this->approver->name,
            ] : null),
            'comments' => $this->decision_comments,
            'decided_at' => $this->decided_at,
            'created_at' => $this->created_at,
            'can' => [
                'approve' => $user->can('approveClosure', [$this->incident, $this->resource]),
                'return' => $user->can('returnFromApproval', [$this->incident, $this->resource]),
            ],
        ];
    }
}
