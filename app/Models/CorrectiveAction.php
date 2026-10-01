<?php

namespace App\Models;

use App\Enums\CorrectiveActionPriority;
use App\Enums\CorrectiveActionStatus;
use App\Enums\CorrectiveActionType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CorrectiveAction extends Model
{
    protected $fillable = [
        'capa_number',
        'incident_id',
        'investigation_id',
        'description',
        'root_cause_finding_id',
        'action_type',
        'responsible_user_id',
        'responsible_department_id',
        'priority',
        'due_date',
        'status',
        'completion_notes',
        'completed_by',
        'completed_at',
        'verification_comments',
        'verified_by',
        'verified_at',
    ];

    protected $casts = [
        'responsible_user_id' => 'integer',
        'completed_by' => 'integer',
        'action_type' => CorrectiveActionType::class,
        'priority' => CorrectiveActionPriority::class,
        'status' => CorrectiveActionStatus::class,
        'due_date' => 'date',
        'completed_at' => 'datetime',
        'verified_at' => 'datetime',
        'escalated_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function investigation(): BelongsTo
    {
        return $this->belongsTo(Investigation::class);
    }

    public function rootCauseFinding(): BelongsTo
    {
        return $this->belongsTo(InvestigationFinding::class, 'root_cause_finding_id');
    }

    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function responsibleDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'responsible_department_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * Per docs/architecture.md §3.2: overdue is determined logically, never
     * stored. A CAPA is overdue once its due date has fully passed and it
     * hasn't reached the one truly terminal status, Verified.
     */
    public function isOverdue(): bool
    {
        return $this->due_date->lt(now()->startOfDay()) && $this->status !== CorrectiveActionStatus::Verified;
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('due_date', '<', now()->startOfDay())
            ->where('status', '!=', CorrectiveActionStatus::Verified->value);
    }
}
