<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ContributingFactor extends Model
{
    protected $fillable = ['label', 'category', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function incidents(): BelongsToMany
    {
        return $this->belongsToMany(Incident::class, 'incident_contributing_factor');
    }
}
