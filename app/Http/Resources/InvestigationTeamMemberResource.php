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
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ]),
        ];
    }
}
