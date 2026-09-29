<?php

namespace App\Models;

use App\Enums\RcaTool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvestigationFinding extends Model
{
    protected $fillable = ['investigation_id', 'tool', 'sequence', 'category', 'group_name', 'occurred_at', 'is_flagged', 'question', 'finding', 'is_root_cause'];

    protected $attributes = ['tool' => 'simple'];

    protected $casts = [
        'tool' => RcaTool::class,
        'is_root_cause' => 'boolean',
        'is_flagged' => 'boolean',
        'occurred_at' => 'datetime',
    ];

    public function investigation(): BelongsTo
    {
        return $this->belongsTo(Investigation::class);
    }
}
