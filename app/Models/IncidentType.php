<?php

namespace App\Models;

use App\Enums\Severity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IncidentType extends Model
{
    use HasFactory;

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
}
