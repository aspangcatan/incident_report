<?php

namespace App\Models;

use App\Enums\Severity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IncidentType extends Model
{
    use HasFactory;

    /** The client's categories (value => label). */
    public const CATEGORIES = [
        'injury' => 'Injury',
        'clinical' => 'Clinical',
        'exposure' => 'Exposure',
        'security' => 'Security',
        'property' => 'Property',
        'environment' => 'Environment',
        'conduct' => 'Conduct',
    ];

    /** Counts that decide whether a type may be deleted. */
    public const USAGE_COUNTS = ['incidents', 'legacyIncidents', 'recurrenceReviews'];

    protected $fillable = [
        'name',
        'category',
        'default_severity',
        'is_active',
    ];

    protected $casts = [
        'default_severity' => Severity::class,
        'is_active' => 'boolean',
    ];

    public function incidents(): BelongsToMany
    {
        return $this->belongsToMany(Incident::class);
    }

    /** Old single-type column (incidents.incident_type_id), no longer written. */
    public function legacyIncidents(): HasMany
    {
        return $this->hasMany(Incident::class, 'incident_type_id');
    }

    public function recurrenceReviews(): HasMany
    {
        return $this->hasMany(RecurrenceReview::class);
    }

    /** Used by any incident or recurrence review — such a type can only be switched off. */
    public function isInUse(): bool
    {
        if (! array_key_exists('incidents_count', $this->attributes)) {
            $this->loadCount(self::USAGE_COUNTS);
        }

        return $this->incidents_count + $this->legacy_incidents_count + $this->recurrence_reviews_count > 0;
    }
}
