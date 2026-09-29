<?php

namespace App\Models;

use App\Enums\IncidentStatus;
use App\Enums\Role;
use App\Enums\Severity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Incident extends Model
{
    use SoftDeletes;

    /**
     * Optional human-readable note attached to the next audit_logs row that
     * IncidentObserver writes for this model (e.g. a supervisor's revision
     * reason). Not a database column — read and cleared by the observer.
     */
    public ?string $auditComment = null;

    protected $fillable = [
        'department_id',
        'incident_type_other',
        'severity',
        'occurred_at',
        'location',
        'has_injury',
        'injury_causes',
        'injury_cause_other',
        'injury_agents',
        'injury_agent_other',
        'summary',
        'recommendations',
        'police_notified',
        'police_station',
        'police_officer_in_charge',
        'police_blotter_no',
        'police_notified_at',
    ];

    protected $casts = [
        'reporter_id' => 'integer',
        'department_id' => 'integer',
        'assigned_investigator_id' => 'integer',
        'severity' => Severity::class,
        'status' => IncidentStatus::class,
        'is_sentinel_event' => 'boolean',
        'police_notified' => 'boolean',
        'has_injury' => 'boolean',
        'injury_causes' => 'array',
        'injury_agents' => 'array',
        'occurred_at' => 'datetime',
        'reported_at' => 'datetime',
        'police_notified_at' => 'datetime',
        'supervisor_reviewed_at' => 'datetime',
        'closed_at' => 'datetime',
        'target_closure_date' => 'date',
        'legal_attestation_at' => 'datetime',
        'review_escalated_at' => 'datetime',
        'assignment_escalated_at' => 'datetime',
        'assessed_by' => 'integer',
        'assessed_at' => 'datetime',
        'assessment_escalated_at' => 'datetime',
    ];

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function isGuestReport(): bool
    {
        return $this->reporter_id === null;
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** One or more types; incident_type_other holds the reporter's own "Others (Specify)" text. */
    public function incidentTypes(): BelongsToMany
    {
        return $this->belongsToMany(IncidentType::class)->orderBy('incident_types.id');
    }

    public function assignedInvestigator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_investigator_id');
    }

    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by');
    }

    public function investigation(): HasOne
    {
        return $this->hasOne(Investigation::class);
    }

    public function correctiveActions(): HasMany
    {
        return $this->hasMany(CorrectiveAction::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class);
    }

    public function individuals(): HasMany
    {
        return $this->hasMany(IncidentIndividual::class);
    }

    public function witnesses(): HasMany
    {
        return $this->hasMany(IncidentWitness::class);
    }

    public function actions(): HasMany
    {
        return $this->hasMany(IncidentAction::class);
    }

    public function narrativeEvents(): HasMany
    {
        return $this->hasMany(IncidentNarrativeEvent::class)->orderBy('sort_order');
    }

    public function contributingFactors(): BelongsToMany
    {
        return $this->belongsToMany(ContributingFactor::class, 'incident_contributing_factor');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'auditable');
    }

    /**
     * Filters non-draft incidents to what the given user is allowed to see,
     * mirroring IncidentPolicy::view() — keep these two in sync if either changes.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if (in_array($user->role, [Role::QualitySafetyOfficer, Role::Administrator, Role::Management], true)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($user) {
            // Everyone sees what they reported or were assigned to investigate (mirrors view()).
            $q->where('reporter_id', $user->id)
                ->orWhere('assigned_investigator_id', $user->id);

            if (in_array($user->role, [Role::Supervisor, Role::DepartmentHead], true)) {
                // Without a department they get no department clause, only the shared ones.
                if ($user->department_id !== null) {
                    $q->orWhere('department_id', $user->department_id);
                }
            } else {
                if ($user->department_id !== null) {
                    $q->orWhere(fn (Builder $q) => $q
                        ->where('status', IncidentStatus::Submitted)
                        ->where('department_id', $user->department_id));
                }
            }

            // Investigation team members and CAPA owners can open the incidents they work on.
            $q->orWhereIn('id', Investigation::query()
                ->select('incident_id')
                ->whereIn('id', InvestigationTeamMember::query()->select('investigation_id')->where('user_id', $user->id)))
                ->orWhereIn('id', CorrectiveAction::query()->select('incident_id')->where('responsible_user_id', $user->id));
        });
    }
}
