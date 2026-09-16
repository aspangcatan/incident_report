<?php

namespace App\Events;

use App\Models\Incident;
use Illuminate\Foundation\Events\Dispatchable;

class IncidentReviewed
{
    use Dispatchable;

    public function __construct(public Incident $incident)
    {
    }
}
