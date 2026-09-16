<?php

namespace App\Models;

use App\Enums\PersonType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentIndividual extends Model
{
    protected $fillable = [
        'person_type', 'name', 'identifier', 'role_description', 'department_id', 'details',
    ];

    protected $casts = [
        'person_type' => PersonType::class,
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
