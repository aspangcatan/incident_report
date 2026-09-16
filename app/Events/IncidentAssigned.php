<?php

namespace App\Events;

use App\Models\Incident;
use Illuminate\Foundation\Events\Dispatchable;

class IncidentAssigned
{
    use Dispatchable;

    public function __construct(public Incident $incident)
    {
    }
}
