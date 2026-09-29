<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class InvestigationFindingResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'tool' => $this->tool->value,
            'sequence' => $this->sequence,
            'group_name' => $this->group_name,
            'occurred_at' => $this->occurred_at,
            'is_flagged' => $this->is_flagged,
            'category' => $this->category,
            'question' => $this->question,
            'finding' => $this->finding,
            'is_root_cause' => $this->is_root_cause,
        ];
    }
}
