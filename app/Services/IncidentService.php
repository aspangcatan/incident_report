<?php

namespace App\Services;

use App\Enums\IncidentStatus;
use App\Enums\Severity;
use App\Models\ContributingFactor;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class IncidentService
{
    private const MAX_SUBMIT_ATTEMPTS = 5;

    public function createDraft(User $reporter, array $data): Incident
    {
        return DB::transaction(function () use ($reporter, $data) {
            $incident = new Incident($this->onlyIncidentColumns($data));
            $incident->reporter_id = $reporter->id;
            $incident->status = IncidentStatus::Draft;
            $incident->save();

            $this->syncChildRecords($incident, $data);

            return $incident;
        });
    }

    public function updateDraft(Incident $incident, array $data): Incident
    {
        return DB::transaction(function () use ($incident, $data) {
            $incident->fill($this->onlyIncidentColumns($data));
            $incident->save();

            $this->syncChildRecords($incident, $data);

            return $incident;
        });
    }

    public function submit(Incident $incident): Incident
    {
        $attempts = 0;

        while (true) {
            try {
                DB::transaction(function () use ($incident) {
                    $incident->incident_number = $this->generateIncidentNumber();
                    $incident->status = IncidentStatus::Submitted;
                    $incident->reported_at = now();
                    $incident->legal_attestation_at = now();
                    $incident->is_sentinel_event = $incident->severity === Severity::Level4CriticalSentinel;
                    $incident->save();
                });

                return $incident;
            } catch (QueryException $e) {
                $attempts++;

                if ($attempts >= self::MAX_SUBMIT_ATTEMPTS || ! $this->isDuplicateIncidentNumber($e)) {
                    throw $e;
                }
            }
        }
    }

    private function isDuplicateIncidentNumber(QueryException $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'incidents_incident_number_unique')
            || str_contains($message, 'UNIQUE constraint failed: incidents.incident_number');
    }

    protected function generateIncidentNumber(): string
    {
        $year = now()->year;
        $prefix = "IR-{$year}-";

        $count = Incident::whereNotNull('incident_number')
            ->where('incident_number', 'like', $prefix . '%')
            ->lockForUpdate()
            ->count();

        return $prefix . str_pad((string) ($count + 1), 6, '0', STR_PAD_LEFT);
    }

    private function onlyIncidentColumns(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'department_id', 'incident_type_id', 'severity', 'occurred_at', 'location',
            'summary', 'recommendations', 'police_notified', 'police_station',
            'police_officer_in_charge', 'police_blotter_no', 'police_notified_at',
        ]));
    }

    private function syncChildRecords(Incident $incident, array $data): void
    {
        if (array_key_exists('individuals', $data)) {
            $incident->individuals()->delete();
            $incident->individuals()->createMany($data['individuals'] ?? []);
        }

        if (array_key_exists('witnesses', $data)) {
            $incident->witnesses()->delete();
            $incident->witnesses()->createMany($data['witnesses'] ?? []);
        }

        if (array_key_exists('actions_taken', $data)) {
            $incident->actions()->delete();
            $incident->actions()->createMany($data['actions_taken'] ?? []);
        }

        if (array_key_exists('narrative_events', $data)) {
            $incident->narrativeEvents()->delete();
            $events = collect($data['narrative_events'] ?? [])->values()->map(fn ($event, $i) => [
                ...$event,
                'sort_order' => $i,
            ])->all();
            $incident->narrativeEvents()->createMany($events);
        }

        if (array_key_exists('contributing_factor_ids', $data)) {
            $incident->contributingFactors()->sync(
                ContributingFactor::whereIn('id', $data['contributing_factor_ids'] ?? [])->pluck('id')
            );
        }
    }
}
