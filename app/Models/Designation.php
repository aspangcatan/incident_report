<?php

namespace App\Models;

use App\Models\Concerns\ReadOnlyTdhModel;
use Illuminate\Database\Eloquent\Model;

/** tdh_user.designation — job titles referenced by tdh_user.users.designation. */
class Designation extends Model
{
    use ReadOnlyTdhModel;

    protected $table = 'designation';

    protected $fillable = ['description'];
}
