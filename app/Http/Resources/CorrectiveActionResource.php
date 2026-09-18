<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CorrectiveActionResource extends JsonResource
{
    public static $wrap = null;

    /**
     * No typed Request $request / array return here — matches this
     * project's actual Laravel 9.52.22 base JsonResource::toArray()
     * signature (untyped); see docs/architecture.md §9f for why a
     * typed signature fails on this version.
     */
    public function toArray($request)
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'capa_number' => $this->capa_number,
            'description' => $this->description,
            'action_type' => [
                'value' => $this->action_type->value,
                'label' => $this->action_type->label(),
            ],
            'priority' => [
                'value' => $this->priority->value,
                'label' => $this->priority->label(),
            ],
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
            ],
            'due_date' => $this->due_date,
            'is_overdue' => $this->isOverdue(),
            'responsible_user' => $this->whenLoaded('responsibleUser', fn () => $this->responsibleUser ? [
                'id' => $this->responsibleUser->id,
                'name' => $this->responsibleUser->name,
            ] : null),
            'responsible_department' => $this->whenLoaded('responsibleDepartment', fn () => $this->responsibleDepartment ? [
                'id' => $this->responsibleDepartment->id,
                'name' => $this->responsibleDepartment->name,
            ] : null),
            'root_cause_finding_id' => $this->root_cause_finding_id,
            'completion_notes' => $this->completion_notes,
            'completed_by' => $this->whenLoaded('completedBy', fn () => $this->completedBy ? ['id' => $this->completedBy->id, 'name' => $this->completedBy->name] : null),
            'completed_at' => $this->completed_at,
            'verification_comments' => $this->verification_comments,
            'verified_by' => $this->whenLoaded('verifiedBy', fn () => $this->verifiedBy ? ['id' => $this->verifiedBy->id, 'name' => $this->verifiedBy->name] : null),
            'verified_at' => $this->verified_at,
            'can' => [
                'update' => $user->can('update', $this->resource),
                'progress' => $user->can('progress', $this->resource),
                'complete' => $user->can('complete', $this->resource),
                'verify' => $user->can('verify', $this->resource),
            ],
        ];
    }
}
