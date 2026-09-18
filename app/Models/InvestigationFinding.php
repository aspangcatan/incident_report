<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvestigationFinding extends Model
{
    protected $fillable = ['investigation_id', 'sequence', 'category', 'question', 'finding', 'is_root_cause'];

    protected $casts = [
        'is_root_cause' => 'boolean',
    ];

    public function investigation(): BelongsTo
    {
        return $this->belongsTo(Investigation::class);
    }
}
