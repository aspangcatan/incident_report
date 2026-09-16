<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Models\Incident;

class IncidentObserver
{
    public function created(Incident $incident): void
    {
        AuditLog::record($incident, 'created');
    }

    public function updated(Incident $incident): void
    {
        if ($incident->wasChanged('assigned_investigator_id') && $incident->assigned_investigator_id !== null) {
            AuditLog::record(
                $incident,
                'assigned',
                $incident->auditComment,
                ['assigned_investigator_id' => $incident->getOriginal('assigned_investigator_id')],
                ['assigned_investigator_id' => $incident->assigned_investigator_id],
            );
            $incident->auditComment = null;

            return;
        }

        if ($incident->wasChanged('status')) {
            AuditLog::record(
                $incident,
                'status_changed',
                $incident->auditComment,
                ['status' => $incident->getOriginal('status')],
                ['status' => $incident->status->value],
            );
            $incident->auditComment = null;
        }
    }
}
