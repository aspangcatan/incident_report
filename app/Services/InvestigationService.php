<?php

namespace App\Services;

use App\DataTransferObjects\Investigations\CompleteInvestigationData;
use App\DataTransferObjects\Investigations\FindingData;
use App\DataTransferObjects\Investigations\StartInvestigationData;
use App\Enums\IncidentStatus;
use App\Enums\InvestigationMethodology;
use App\Enums\InvestigationStatus;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Investigation;
use App\Models\InvestigationFinding;
use App\Models\InvestigationTeamMember;
use App\Models\User;
use App\Repositories\InvestigationFindingRepository;
use App\Repositories\InvestigationRepository;
use App\Repositories\InvestigationTeamMemberRepository;
use Illuminate\Support\Facades\DB;

class InvestigationService
{
    public function __construct(
        private InvestigationRepository $investigations,
        private InvestigationTeamMemberRepository $teamMembers,
        private InvestigationFindingRepository $findings,
    ) {
    }

    public function start(Incident $incident, User $leadInvestigator, StartInvestigationData $data): Investigation
    {
        return DB::transaction(function () use ($incident, $leadInvestigator, $data) {
            $investigation = $this->investigations->create([
                'incident_id' => $incident->id,
                'lead_investigator_id' => $leadInvestigator->id,
                'objective' => $data->objective,
                'methodology' => $data->methodology,
                'started_at' => now(),
                'target_completion_at' => $data->targetCompletionAt ?? now()->addHours(
                    config('incident_workflow.investigation_sla_hours.' . $incident->severity->value, 168)
                ),
                'status' => InvestigationStatus::InProgress,
            ]);

            $this->teamMembers->create($investigation, [
                'user_id' => $leadInvestigator->id,
                'role_in_team' => 'Lead Investigator',
            ]);

            foreach ($data->teamMembers as $member) {
                if ((int) $member['user_id'] === $leadInvestigator->id) {
                    continue;
                }

                $this->teamMembers->create($investigation, [
                    'user_id' => $member['user_id'],
                    'role_in_team' => $member['role_in_team'],
                ]);
            }

            $incident->status = IncidentStatus::UnderInvestigation;
            $incident->save();

            AuditLog::record($incident, 'investigation_started', $data->objective);

            return $investigation->fresh(['teamMembers']);
        });
    }

    public function addTeamMember(Investigation $investigation, User $user, string $roleInTeam): InvestigationTeamMember
    {
        return $this->teamMembers->create($investigation, [
            'user_id' => $user->id,
            'role_in_team' => $roleInTeam,
        ]);
    }

    public function removeTeamMember(InvestigationTeamMember $teamMember): void
    {
        $this->teamMembers->delete($teamMember);
    }

    public function addFinding(Investigation $investigation, FindingData $data): InvestigationFinding
    {
        $attributes = $data->toAttributes();

        if ($investigation->methodology === InvestigationMethodology::FiveWhys) {
            $attributes['sequence'] = $this->findings->countFor($investigation) + 1;
        }

        $finding = $this->findings->create($investigation, $attributes);

        AuditLog::record($investigation->incident, 'finding_added', $data->finding);

        return $finding;
    }

    public function updateFinding(InvestigationFinding $finding, FindingData $data): InvestigationFinding
    {
        return $this->findings->update($finding, $data->toAttributes());
    }

    public function deleteFinding(InvestigationFinding $finding): void
    {
        $investigation = $finding->investigation;
        $this->findings->delete($finding);

        if ($investigation->methodology === InvestigationMethodology::FiveWhys) {
            $this->findings->allFor($investigation)->values()->each(
                fn (InvestigationFinding $remaining, int $index) => $this->findings->update($remaining, ['sequence' => $index + 1])
            );
        }
    }

    public function complete(Investigation $investigation, CompleteInvestigationData $data): Investigation
    {
        return DB::transaction(function () use ($investigation, $data) {
            $investigation = $this->investigations->update($investigation, [
                'status' => InvestigationStatus::Completed,
                'conclusion' => $data->conclusion,
                'completed_at' => now(),
            ]);

            $incident = $investigation->incident;
            $incident->status = IncidentStatus::CorrectiveAction;
            $incident->save();

            AuditLog::record($incident, 'investigation_completed', $data->conclusion);

            return $investigation;
        });
    }
}
