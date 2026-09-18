<?php

namespace App\Models;

use App\Enums\InvestigationMethodology;
use App\Enums\InvestigationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Investigation extends Model
{
    protected $fillable = [
        'incident_id',
        'lead_investigator_id',
        'objective',
        'methodology',
        'started_at',
        'target_completion_at',
        'completed_at',
        'conclusion',
        'status',
    ];

    protected $casts = [
        'methodology' => InvestigationMethodology::class,
        'status' => InvestigationStatus::class,
        'started_at' => 'datetime',
        'target_completion_at' => 'datetime',
        'completed_at' => 'datetime',
        'escalated_at' => 'datetime',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function leadInvestigator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lead_investigator_id');
    }

    public function teamMembers(): HasMany
    {
        return $this->hasMany(InvestigationTeamMember::class);
    }

    /**
     * Ordered by sequence (used by five_whys) then id as a tiebreaker, so
     * methodologies that leave sequence null (fishbone/hfacs/contributing
     * factors, all rows tied at null) still render in a stable, insertion
     * order instead of whatever order the DB happens to return them in.
     */
    public function findings(): HasMany
    {
        return $this->hasMany(InvestigationFinding::class)->orderBy('sequence')->orderBy('id');
    }
}
