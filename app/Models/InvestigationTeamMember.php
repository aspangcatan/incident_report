<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvestigationTeamMember extends Model
{
    protected $fillable = ['investigation_id', 'user_id', 'role_in_team'];

    protected $casts = ['user_id' => 'integer'];

    public function investigation(): BelongsTo
    {
        return $this->belongsTo(Investigation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
