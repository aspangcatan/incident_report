<?php

namespace App\Models;

use App\Models\Concerns\ReadOnlyTdhModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * tdh_user.user_priv — one row per (user, system). Only rows whose syscode
 * is this system's (config('tdh.syscode')) mean anything here; `level`
 * holds an App\Enums\Role backing value.
 */
class UserPrivilege extends Model
{
    use ReadOnlyTdhModel;

    public $timestamps = false;

    protected $table = 'user_priv';

    protected $fillable = ['user_id', 'syscode', 'level'];

    public function scopeForThisSystem(Builder $query): Builder
    {
        return $query->where('syscode', config('tdh.syscode'));
    }
}
