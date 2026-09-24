<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class InvestigationResource extends JsonResource
{
    /**
     * Inertia serializes this resource directly via toResponse(), which
     * applies Laravel's default "data" envelope. Since the frontend reads
     * this prop's fields directly (investigation.id, investigation.status,
     * ...), the envelope is disabled here so the prop is the flat object
     * shape, not {"data": {...}}.
     */
    public static $wrap = null;

    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'objective' => $this->objective,
            'methodology' => [
                'value' => $this->methodology->value,
                'label' => $this->methodology->label(),
                'uses_sequence' => $this->methodology->usesSequence(),
                'uses_category' => $this->methodology->usesCategory(),
            ],
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
            ],
            'started_at' => $this->started_at,
            'target_completion_at' => $this->target_completion_at,
            'completed_at' => $this->completed_at,
            'conclusion' => $this->conclusion,
            // Null when the lead was deleted from tdh_user (no FK guards it).
            'lead_investigator' => $this->whenLoaded('leadInvestigator', fn () => $this->leadInvestigator ? [
                'id' => $this->leadInvestigator->id,
                'name' => $this->leadInvestigator->name,
            ] : null),
            'team_members' => InvestigationTeamMemberResource::collection($this->whenLoaded('teamMembers')),
            'findings' => InvestigationFindingResource::collection($this->whenLoaded('findings')),
        ];
    }
}
