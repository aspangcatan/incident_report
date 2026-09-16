<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentNarrativeEvent extends Model
{
    protected $fillable = ['occurred_at', 'description', 'sort_order'];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }
}
