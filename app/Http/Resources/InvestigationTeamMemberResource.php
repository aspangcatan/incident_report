<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class InvestigationTeamMemberResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'role_in_team' => $this->role_in_team,
            'user_id' => $this->user_id,
            // Null when the member was deleted from tdh_user (no FK guards it).
            'user' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ] : null),
        ];
    }
}
