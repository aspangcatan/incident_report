<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Auth;

class AuditLog extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'auditable_type', 'auditable_id', 'actor_id', 'action', 'description', 'old_values', 'new_values',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
    ];

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public static function record(
        Model $auditable,
        string $action,
        ?string $description = null,
        ?array $oldValues = null,
        ?array $newValues = null
    ): self {
        return static::create([
            'auditable_type' => get_class($auditable),
            'auditable_id' => $auditable->getKey(),
            'actor_id' => Auth::id(),
            'action' => $action,
            'description' => $description,
            'old_values' => $oldValues,
            'new_values' => $newValues,
        ]);
    }
}
