<?php

namespace App\Services;

use App\Enums\IncidentStatus;
use App\Enums\InvestigationMethodology;
use App\Enums\InvestigationStatus;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Investigation;
use App\Models\InvestigationFinding;
use App\Models\InvestigationTeamMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class InvestigationService
{
    public function start(Incident $incident, User $leadInvestigator, array $data): Investigation
    {
        return DB::transaction(function () use ($incident, $leadInvestigator, $data) {
            $investigation = Investigation::create([
                'incident_id' => $incident->id,
                'lead_investigator_id' => $leadInvestigator->id,
                'objective' => $data['objective'],
                'methodology' => $data['methodology'],
                'started_at' => now(),
                'target_completion_at' => $data['target_completion_at'] ?? $incident->target_closure_date,
                'status' => InvestigationStatus::InProgress,
            ]);

            $investigation->teamMembers()->create([
                'user_id' => $leadInvestigator->id,
                'role_in_team' => 'Lead Investigator',
            ]);

            foreach ($data['team_members'] ?? [] as $member) {
                if ((int) $member['user_id'] === $leadInvestigator->id) {
                    continue;
                }

                $investigation->teamMembers()->create([
                    'user_id' => $member['user_id'],
                    'role_in_team' => $member['role_in_team'],
                ]);
            }

            $incident->status = IncidentStatus::UnderInvestigation;
            $incident->save();

            AuditLog::record($incident, 'investigation_started', $data['objective']);

            return $investigation->fresh(['teamMembers']);
        });
    }

    public function addTeamMember(Investigation $investigation, User $user, string $roleInTeam): InvestigationTeamMember
    {
        return $investigation->teamMembers()->create([
            'user_id' => $user->id,
            'role_in_team' => $roleInTeam,
        ]);
    }

    public function removeTeamMember(InvestigationTeamMember $teamMember): void
    {
        $teamMember->delete();
    }

    public function addFinding(Investigation $investigation, array $data): InvestigationFinding
    {
        if ($investigation->methodology === InvestigationMethodology::FiveWhys) {
            $data['sequence'] = $investigation->findings()->count() + 1;
        }

        $finding = $investigation->findings()->create($data);

        AuditLog::record($investigation->incident, 'finding_added', $data['finding']);

        return $finding;
    }

    public function updateFinding(InvestigationFinding $finding, array $data): InvestigationFinding
    {
        $finding->update($data);

        return $finding;
    }

    public function deleteFinding(InvestigationFinding $finding): void
    {
        $investigation = $finding->investigation;
        $finding->delete();

        if ($investigation->methodology === InvestigationMethodology::FiveWhys) {
            $investigation->findings()->get()->values()->each(
                fn (InvestigationFinding $remaining, int $index) => $remaining->update(['sequence' => $index + 1])
            );
        }
    }

    public function complete(Investigation $investigation, string $conclusion): Investigation
    {
        return DB::transaction(function () use ($investigation, $conclusion) {
            $investigation->status = InvestigationStatus::Completed;
            $investigation->conclusion = $conclusion;
            $investigation->completed_at = now();
            $investigation->save();

            $incident = $investigation->incident;
            $incident->status = IncidentStatus::CorrectiveAction;
            $incident->save();

            AuditLog::record($incident, 'investigation_completed', $conclusion);

            return $investigation;
        });
    }
}
