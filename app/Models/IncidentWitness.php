<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentWitness extends Model
{
    protected $fillable = ['name', 'designation', 'address', 'contact_number', 'statement'];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }
}
