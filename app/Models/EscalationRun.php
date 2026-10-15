<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One incidents:check-overdue run: when, who started it, and what it sent per rule. */
class EscalationRun extends Model
{
    protected $fillable = ['triggered_by', 'started_at', 'finished_at', 'counts', 'warning'];

    protected $casts = [
        'triggered_by' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'counts' => 'array',
    ];

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    /** Notices sent in this run, all rules together. */
    public function total(): int
    {
        return array_sum($this->counts ?? []);
    }
}
