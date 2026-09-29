<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SafetyAlertAcknowledgement extends Model
{
    public $timestamps = false;

    protected $fillable = ['safety_alert_id', 'user_id', 'acknowledged_at'];

    protected $casts = [
        'user_id' => 'integer',
        'acknowledged_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
