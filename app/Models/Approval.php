<?php

namespace App\Models;

use App\Enums\ApprovalStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Approval extends Model
{
    protected $fillable = [
        'incident_id',
        'requested_by',
        'request_comments',
        'status',
        'due_at',
        'approver_id',
        'decision_comments',
        'decided_at',
    ];

    protected $casts = [
        'requested_by' => 'integer',
        'status' => ApprovalStatus::class,
        'due_at' => 'datetime',
        'decided_at' => 'datetime',
        'escalated_at' => 'datetime',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function isOverdue(): bool
    {
        return $this->status === ApprovalStatus::Pending
            && $this->due_at !== null
            && $this->due_at->isPast();
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('status', ApprovalStatus::Pending->value)
            ->whereNotNull('due_at')
            ->where('due_at', '<', now());
    }
}
