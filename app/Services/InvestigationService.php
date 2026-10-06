<?php

namespace App\Services;

use App\DataTransferObjects\Investigations\CompleteInvestigationData;
use App\DataTransferObjects\Investigations\FindingData;
use App\DataTransferObjects\Investigations\StartInvestigationData;
use App\Enums\IncidentStatus;
use App\Enums\InvestigationMethodology;
use App\Enums\InvestigationStatus;
use App\Enums\RcaTool;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Investigation;
use App\Models\InvestigationFinding;
use App\Models\InvestigationTeamMember;
use App\Models\User;
use App\Repositories\InvestigationFindingRepository;
use App\Repositories\InvestigationRepository;
use App\Repositories\InvestigationTeamMemberRepository;
use App\Support\WorkflowDurations;
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
                    WorkflowDurations::forLevel('investigation_sla_hours', $incident->severity)
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
        // Older 5 Whys investigations add 5 Whys rows by default.
        $tool = $data->tool !== null ? RcaTool::from($data->tool)
            : ($investigation->methodology === InvestigationMethodology::FiveWhys ? RcaTool::FiveWhys : RcaTool::Simple);
        $attributes = [...$data->toAttributes(), 'tool' => $tool];

        if ($tool->isSequenced()) {
            $attributes['sequence'] = $investigation->findings()->where('tool', $tool->value)->count() + 1;
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
        $tool = $finding->tool;
        $this->findings->delete($finding);

        // Keep the numbering of that tool's remaining steps contiguous.
        if ($tool->isSequenced()) {
            $this->findings->allFor($investigation)->where('tool', $tool)->values()->each(
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
