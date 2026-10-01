<?php

namespace App\Events;

use App\Enums\Severity;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

class IncidentReviewed
{
    use Dispatchable;

    /** $previousSeverity: the level before triage, so a severity alert fires only when the CQI Office changed it. */
    public function __construct(public Incident $incident, public ?User $actor = null, public ?Severity $previousSeverity = null)
    {
    }
}
