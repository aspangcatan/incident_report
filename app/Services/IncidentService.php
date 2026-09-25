<?php

namespace App\Services;

use App\Enums\IncidentStatus;
use App\Enums\Severity;
use App\Events\IncidentAssessed;
use App\Events\IncidentAssigned;
use App\Events\IncidentReturnedForRevision;
use App\Events\IncidentReturnedToDepartment;
use App\Events\IncidentReviewed;
use App\Events\IncidentSubmitted;
use App\Models\ContributingFactor;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
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
                    if ($incident->incident_number === null) {
                        $incident->incident_number = $this->generateIncidentNumber();
                    }
                    $incident->status = IncidentStatus::Submitted;
                    $incident->reported_at = now();
                    $incident->review_escalated_at = null;
                    $incident->legal_attestation_at = now();
                    // Severity is set later by the Department Head (completeAssessment()).
                    $incident->assessment_escalated_at = null;
                    $incident->save();
                });

                IncidentSubmitted::dispatch($incident);

                return $incident;
            } catch (QueryException $e) {
                $attempts++;

                if ($attempts >= self::MAX_SUBMIT_ATTEMPTS || ! $this->isDuplicateIncidentNumber($e)) {
                    throw $e;
                }

                $incident->incident_number = null;
            }
        }
    }

    public function markReviewed(Incident $incident, User $reviewer, ?string $comments): Incident
    {
        DB::transaction(function () use ($incident, $reviewer, $comments) {
            $incident->auditComment = $comments;
            $incident->status = IncidentStatus::Reviewed;
            $incident->supervisor_reviewed_by = $reviewer->id;
            $incident->supervisor_reviewed_at = now();
            $incident->supervisor_comments = $comments;
            $incident->save();
        });

        IncidentReviewed::dispatch($incident);

        return $incident;
    }

    public function returnForRevision(Incident $incident, User $reviewer, string $comments): Incident
    {
        DB::transaction(function () use ($incident, $reviewer, $comments) {
            $incident->auditComment = $comments;
            $incident->status = IncidentStatus::Draft;
            $incident->supervisor_reviewed_by = $reviewer->id;
            $incident->supervisor_reviewed_at = now();
            $incident->supervisor_comments = $comments;
            $incident->save();
        });

        IncidentReturnedForRevision::dispatch($incident, $comments);

        return $incident;
    }

    public function assignInvestigator(Incident $incident, User $investigator, \DateTimeInterface|string|null $targetClosureDate = null): Incident
    {
        DB::transaction(function () use ($incident, $investigator, $targetClosureDate) {
            $incident->assigned_investigator_id = $investigator->id;
            $incident->status = IncidentStatus::Assigned;
            $incident->target_closure_date = $targetClosureDate
                ?? now()->addHours(
                    config('incident_workflow.investigation_sla_hours.' . $incident->severity->value, 168)
                );
            $incident->assignment_escalated_at = null;
            $incident->save();
        });

        IncidentAssigned::dispatch($incident);

        return $incident;
    }

    /**
     * Department Assessment edits while the incident is Submitted. Callers pass
     * only the keys the current user may change (see SaveAssessmentRequest).
     */
    public function saveAssessment(Incident $incident, array $data): Incident
    {
        return DB::transaction(function () use ($incident, $data) {
            $incident->fill(Arr::only($data, ['recommendations', 'severity', 'department_id']));
            $incident->save();

            if (array_key_exists('actions_taken', $data)) {
                $incident->actions()->delete();
                $incident->actions()->createMany($data['actions_taken'] ?? []);
            }

            return $incident;
        });
    }

    public function completeAssessment(Incident $incident, User $assessor): Incident
    {
        DB::transaction(function () use ($incident, $assessor) {
            $incident->status = IncidentStatus::ForReview;
            $incident->assessed_by = $assessor->id;
            $incident->assessed_at = now();
            $incident->review_escalated_at = null;
            $incident->is_sentinel_event = $incident->severity === Severity::Level4CriticalSentinel;
            $incident->save();
        });

        IncidentAssessed::dispatch($incident);

        return $incident;
    }

    public function returnToDepartment(Incident $incident, User $reviewer, string $comments): Incident
    {
        DB::transaction(function () use ($incident, $reviewer, $comments) {
            $incident->auditComment = $comments;
            $incident->status = IncidentStatus::Submitted;
            $incident->supervisor_reviewed_by = $reviewer->id;
            $incident->supervisor_comments = $comments;
            $incident->save();
        });

        IncidentReturnedToDepartment::dispatch($incident, $comments);

        return $incident;
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
