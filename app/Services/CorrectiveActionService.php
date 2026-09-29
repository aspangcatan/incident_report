<?php

namespace App\Services;

use App\DataTransferObjects\CorrectiveActions\CompleteCorrectiveActionData;
use App\DataTransferObjects\CorrectiveActions\CorrectiveActionData;
use App\DataTransferObjects\CorrectiveActions\VerifyCorrectiveActionData;
use App\Enums\CorrectiveActionStatus;
use App\Enums\IncidentStatus;
use App\Models\AuditLog;
use App\Models\CorrectiveAction;
use App\Models\Incident;
use App\Models\User;
use App\Repositories\CorrectiveActionRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CorrectiveActionService
{
    private const MAX_CREATE_ATTEMPTS = 5;

    public function __construct(private CorrectiveActionRepository $correctiveActions)
    {
    }

    public function create(Incident $incident, CorrectiveActionData $data): CorrectiveAction
    {
        $attempts = 0;

        while (true) {
            try {
                return DB::transaction(function () use ($incident, $data) {
                    $correctiveAction = $this->correctiveActions->create([
                        'capa_number' => $this->generateCapaNumber(),
                        'incident_id' => $incident->id,
                        'investigation_id' => $incident->investigation?->id,
                        'status' => CorrectiveActionStatus::Open,
                        ...$data->toAttributes(),
                    ]);

                    AuditLog::record($incident, 'action_created', $data->description);

                    return $correctiveAction;
                });
            } catch (QueryException $e) {
                $attempts++;

                if ($attempts >= self::MAX_CREATE_ATTEMPTS || ! $this->isDuplicateCapaNumber($e)) {
                    throw $e;
                }
            }
        }
    }

    public function update(CorrectiveAction $correctiveAction, CorrectiveActionData $data): CorrectiveAction
    {
        return $this->correctiveActions->update($correctiveAction, $data->toAttributes());
    }

    public function markInProgress(CorrectiveAction $correctiveAction): CorrectiveAction
    {
        return $this->correctiveActions->update($correctiveAction, [
            'status' => CorrectiveActionStatus::InProgress,
        ]);
    }

    public function complete(CorrectiveAction $correctiveAction, CompleteCorrectiveActionData $data): CorrectiveAction
    {
        return DB::transaction(function () use ($correctiveAction, $data) {
            $correctiveAction = $this->correctiveActions->update($correctiveAction, [
                'status' => CorrectiveActionStatus::ForVerification,
                'completion_notes' => $data->completionNotes,
                'completed_by' => Auth::id(),
                'completed_at' => now(),
            ]);

            AuditLog::record($correctiveAction->incident, 'action_completed', $data->completionNotes);

            $this->maybeAdvanceToForVerification($correctiveAction->incident);

            return $correctiveAction;
        });
    }

    /**
     * Takes an explicit $verifier rather than reading Auth::id() the way
     * complete() does, because CorrectiveActionPolicy::verify() must compare
     * the acting user against completed_by to enforce "never
     * self-verification" — that check needs the user available to it, not
     * just implicitly resolved deep inside this method.
     */
    public function verify(CorrectiveAction $correctiveAction, User $verifier, VerifyCorrectiveActionData $data): CorrectiveAction
    {
        return DB::transaction(function () use ($correctiveAction, $verifier, $data) {
            $correctiveAction = $this->correctiveActions->update($correctiveAction, [
                'status' => CorrectiveActionStatus::Verified,
                'verification_comments' => $data->verificationComments,
                'verified_by' => $verifier->id,
                'verified_at' => now(),
            ]);

            AuditLog::record($correctiveAction->incident, 'action_verified', $data->verificationComments);

            $this->maybeAdvanceToVerified($correctiveAction->incident);

            return $correctiveAction;
        });
    }

    /**
     * Nothing else in the app moves an incident past corrective_action, so
     * this rollup is load-bearing, not cosmetic: once every CAPA on the
     * incident has reached for_verification (or further, verified), the
     * incident itself advances. Guarded on the incident's current status so
     * this only ever fires once, from the expected starting point.
     */
    private function maybeAdvanceToForVerification(Incident $incident): void
    {
        if ($incident->status !== IncidentStatus::CorrectiveAction) {
            return;
        }

        $unsettled = $incident->correctiveActions()
            ->whereIn('status', [
                CorrectiveActionStatus::Open->value,
                CorrectiveActionStatus::InProgress->value,
                CorrectiveActionStatus::Completed->value,
            ])
            ->exists();

        if (! $unsettled && $incident->correctiveActions()->exists()) {
            $incident->status = IncidentStatus::ForVerification;
            $incident->save();
        }
    }

    private function maybeAdvanceToVerified(Incident $incident): void
    {
        if ($incident->status !== IncidentStatus::ForVerification) {
            return;
        }

        $unverified = $incident->correctiveActions()
            ->where('status', '!=', CorrectiveActionStatus::Verified->value)
            ->exists();

        if (! $unverified && $incident->correctiveActions()->exists()) {
            $incident->status = IncidentStatus::Verified;
            // Effectiveness check: wait, then the Department Head confirms the actions worked.
            $incident->effectiveness_due_at = now()->addDays((int) config('incident_workflow.effectiveness_wait_days', 30));
            $incident->effectiveness_result = null;
            $incident->effectiveness_notes = null;
            $incident->effectiveness_checked_by = null;
            $incident->effectiveness_checked_at = null;
            $incident->effectiveness_notified_at = null;
            $incident->save();
        }
    }

    private function isDuplicateCapaNumber(QueryException $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'corrective_actions_capa_number_unique')
            || str_contains($message, 'UNIQUE constraint failed: corrective_actions.capa_number');
    }

    private function generateCapaNumber(): string
    {
        $year = now()->year;
        $prefix = "CAPA-{$year}-";

        $count = CorrectiveAction::where('capa_number', 'like', $prefix . '%')
            ->lockForUpdate()
            ->count();

        return $prefix . str_pad((string) ($count + 1), 3, '0', STR_PAD_LEFT);
    }
}
