<?php

namespace App\Models;

use App\Enums\RecurrenceReviewStatus;
use App\Enums\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** CQI Office's tracked review of a recurring pattern (same type, same department). */
class RecurrenceReview extends Model
{
    protected $fillable = [
        'department_id', 'incident_type_id', 'incident_count', 'status', 'assigned_to', 'due_date',
        'cqi_notes', 'fix_description', 'submitted_at', 'decision_comments', 'closed_by', 'closed_at', 'created_by',
    ];

    protected $casts = [
        'status' => RecurrenceReviewStatus::class,
        'department_id' => 'integer',
        'incident_type_id' => 'integer',
        'assigned_to' => 'integer',
        'closed_by' => 'integer',
        'created_by' => 'integer',
        'due_date' => 'date',
        'submitted_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function incidentType(): BelongsTo
    {
        return $this->belongsTo(IncidentType::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function isOverdue(): bool
    {
        return $this->status !== RecurrenceReviewStatus::Closed && $this->due_date->isBefore(today());
    }

    /** Oversight sees all; departments see their own; anyone sees reviews assigned to them. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->role->seesAllIncidents()) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($user) {
            $q->where('assigned_to', $user->id);

            if ($user->role === Role::Supervisor && $user->department_id !== null) {
                $q->orWhere('department_id', $user->department_id);
            }

            if ($user->isDepartmentHead()) {
                $q->orWhereIn('department_id', $user->headedDepartmentIds());
            }

            if ($user->role === Role::Leadership) {
                $q->orWhereIn('department_id', $user->leadershipDepartmentIds());
            }
        });
    }
}
