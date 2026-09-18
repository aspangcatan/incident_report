<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class InvestigationFindingResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'sequence' => $this->sequence,
            'category' => $this->category,
            'question' => $this->question,
            'finding' => $this->finding,
            'is_root_cause' => $this->is_root_cause,
        ];
    }
}
