<?php

namespace App\Http\Resources;

use App\Models\IncidentType;
use Illuminate\Http\Resources\Json\JsonResource;

/** One row on the Incident Types settings page. Expects IncidentType::USAGE_COUNTS loaded. */
class IncidentTypeResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'category' => $this->category,
            'category_label' => IncidentType::CATEGORIES[$this->category] ?? $this->category,
            'default_severity' => $this->default_severity?->value,
            'is_active' => $this->is_active,
            'usage_count' => $this->incidents_count,
            'can_delete' => $request->user()->can('delete', $this->resource),
        ];
    }
}
