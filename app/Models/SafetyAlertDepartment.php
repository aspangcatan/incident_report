<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SafetyAlertDepartment extends Model
{
    public $timestamps = false;

    protected $fillable = ['safety_alert_id', 'department_id'];

    protected $casts = ['department_id' => 'integer'];
}
