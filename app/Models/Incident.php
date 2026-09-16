<?php

namespace App\Models;

use App\Enums\IncidentStatus;
use App\Enums\Severity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Incident extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'department_id',
        'incident_type_id',
        'severity',
        'occurred_at',
        'location',
        'summary',
        'recommendations',
        'police_notified',
        'police_station',
        'police_officer_in_charge',
        'police_blotter_no',
        'police_notified_at',
    ];

    protected $casts = [
        'severity' => Severity::class,
        'status' => IncidentStatus::class,
        'is_sentinel_event' => 'boolean',
        'police_notified' => 'boolean',
        'occurred_at' => 'datetime',
        'reported_at' => 'datetime',
        'police_notified_at' => 'datetime',
        'supervisor_reviewed_at' => 'datetime',
        'closed_at' => 'datetime',
        'target_closure_date' => 'date',
        'legal_attestation_at' => 'datetime',
    ];

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function incidentType(): BelongsTo
    {
        return $this->belongsTo(IncidentType::class);
    }

    public function assignedInvestigator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_investigator_id');
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
}
