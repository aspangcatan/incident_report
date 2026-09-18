<?php

namespace Tests\Feature\Investigations;

use App\DataTransferObjects\Investigations\CompleteInvestigationData;
use App\DataTransferObjects\Investigations\FindingData;
use App\DataTransferObjects\Investigations\StartInvestigationData;
use App\Enums\IncidentStatus;
use App\Enums\InvestigationMethodology;
use App\Enums\InvestigationStatus;
use App\Enums\Role;
use App\Enums\Severity;
use App\Models\Department;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\User;
use App\Services\IncidentService;
use App\Services\InvestigationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvestigationTest extends TestCase
{
    use RefreshDatabase;

    private function makeReporter(): User
    {
        return User::factory()->create();
    }

    /**
     * A submitted, reviewed, and assigned incident — ready for start().
     */
    private function assignedIncident(?User $investigator = null, ?Department $department = null): Incident
    {
        $department ??= Department::factory()->create();
        $incidentType = IncidentType::factory()->create();
        $reporter = $this->makeReporter();
        $supervisor = User::factory()->create(['role' => Role::Supervisor, 'department_id' => $department->id]);
        $investigator ??= User::factory()->create(['role' => Role::Investigator]);

        $incident = app(IncidentService::class)->createDraft($reporter, [
            'department_id' => $department->id,
            'incident_type_id' => $incidentType->id,
            'severity' => Severity::Level2Moderate->value,
            'occurred_at' => now(),
            'location' => 'Ward 3',
            'summary' => 'Test incident.',
        ]);
        app(IncidentService::class)->submit($incident);
        app(IncidentService::class)->markReviewed($incident->fresh(), $supervisor, null);
        app(IncidentService::class)->assignInvestigator($incident->fresh(), $investigator);

        return $incident->fresh();
    }

    private function startData(string $methodology = 'five_whys', array $extra = []): StartInvestigationData
    {
        return StartInvestigationData::fromArray(array_merge([
            'objective' => 'Determine root cause.',
            'methodology' => $methodology,
        ], $extra));
    }

    private function findingData(array $data): FindingData
    {
        return FindingData::fromArray($data);
    }

    public function test_starting_an_investigation_sets_status_and_moves_the_incident_to_under_investigation(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $incident = $this->assignedIncident($investigator);

        $investigation = app(InvestigationService::class)->start($incident, $investigator, $this->startData('five_whys', [
            'objective' => 'Determine why the pump was double-rated.',
        ]));

        $this->assertSame(InvestigationStatus::InProgress, $investigation->status);
        $this->assertSame($investigator->id, $investigation->lead_investigator_id);
        $this->assertNotNull($investigation->started_at);
        $this->assertSame(IncidentStatus::UnderInvestigation, $incident->fresh()->status);
    }

    public function test_starting_an_investigation_adds_the_lead_investigator_as_a_team_member(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $incident = $this->assignedIncident($investigator);

        $investigation = app(InvestigationService::class)->start($incident, $investigator, $this->startData('fishbone'));

        $this->assertCount(1, $investigation->teamMembers);
        $this->assertSame($investigator->id, $investigation->teamMembers->first()->user_id);
        $this->assertSame('Lead Investigator', $investigation->teamMembers->first()->role_in_team);
    }

    public function test_starting_an_investigation_adds_additional_team_members_and_deduplicates_the_lead(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $nurse = User::factory()->create();
        $incident = $this->assignedIncident($investigator);

        $investigation = app(InvestigationService::class)->start($incident, $investigator, $this->startData('fishbone', [
            'team_members' => [
                ['user_id' => $investigator->id, 'role_in_team' => 'Duplicate of lead'],
                ['user_id' => $nurse->id, 'role_in_team' => 'Nursing Service Rep'],
            ],
        ]));

        $this->assertCount(2, $investigation->teamMembers);
        $this->assertSame('Nursing Service Rep', $investigation->teamMembers->firstWhere('user_id', $nurse->id)->role_in_team);
    }

    public function test_starting_an_investigation_defaults_target_completion_to_the_incidents_target_closure_date(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $incident = $this->assignedIncident($investigator);

        $investigation = app(InvestigationService::class)->start($incident, $investigator, $this->startData('hfacs'));

        $this->assertSame(
            $incident->target_closure_date->timestamp,
            $investigation->target_completion_at->timestamp
        );
    }

    public function test_starting_an_investigation_writes_an_investigation_started_audit_log_entry(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $incident = $this->assignedIncident($investigator);

        app(InvestigationService::class)->start($incident, $investigator, $this->startData('five_whys', [
            'objective' => 'Determine root cause.',
        ]));

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Incident::class,
            'auditable_id' => $incident->id,
            'action' => 'investigation_started',
        ]);
    }

    public function test_adding_a_five_whys_finding_auto_assigns_sequence(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $incident = $this->assignedIncident($investigator);
        $investigation = app(InvestigationService::class)->start($incident, $investigator, $this->startData('five_whys'));

        $first = app(InvestigationService::class)->addFinding($investigation, $this->findingData([
            'question' => 'Why did X happen?', 'finding' => 'Because Y.', 'is_root_cause' => false,
        ]));
        $second = app(InvestigationService::class)->addFinding($investigation->fresh(), $this->findingData([
            'question' => 'Why did Y happen?', 'finding' => 'Because Z.', 'is_root_cause' => true,
        ]));

        $this->assertSame(1, $first->sequence);
        $this->assertSame(2, $second->sequence);
    }

    public function test_adding_a_finding_writes_a_finding_added_audit_log_entry(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $incident = $this->assignedIncident($investigator);
        $investigation = app(InvestigationService::class)->start($incident, $investigator, $this->startData('fishbone'));

        app(InvestigationService::class)->addFinding($investigation, $this->findingData([
            'category' => 'Equipment', 'finding' => 'Pump firmware outdated.', 'is_root_cause' => false,
        ]));

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Incident::class,
            'auditable_id' => $incident->id,
            'action' => 'finding_added',
        ]);
    }

    public function test_deleting_a_five_whys_finding_renumbers_the_remaining_ones_contiguously(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $incident = $this->assignedIncident($investigator);
        $investigation = app(InvestigationService::class)->start($incident, $investigator, $this->startData('five_whys'));
        $service = app(InvestigationService::class);
        $one = $service->addFinding($investigation, $this->findingData(['question' => 'Q1', 'finding' => 'F1', 'is_root_cause' => false]));
        $two = $service->addFinding($investigation->fresh(), $this->findingData(['question' => 'Q2', 'finding' => 'F2', 'is_root_cause' => false]));
        $three = $service->addFinding($investigation->fresh(), $this->findingData(['question' => 'Q3', 'finding' => 'F3', 'is_root_cause' => true]));

        $service->deleteFinding($two);

        $remaining = $investigation->fresh()->findings;
        $this->assertCount(2, $remaining);
        $this->assertSame([1, 2], $remaining->pluck('sequence')->all());
        $this->assertSame([$one->id, $three->id], $remaining->pluck('id')->all());
    }

    public function test_updating_a_finding(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $incident = $this->assignedIncident($investigator);
        $investigation = app(InvestigationService::class)->start($incident, $investigator, $this->startData('five_whys'));
        $service = app(InvestigationService::class);
        $finding = $service->addFinding($investigation, $this->findingData(['question' => 'Q', 'finding' => 'Original', 'is_root_cause' => false]));

        $updated = $service->updateFinding($finding, $this->findingData(['question' => 'Q', 'finding' => 'Corrected.', 'is_root_cause' => true]));

        $this->assertSame('Corrected.', $updated->finding);
        $this->assertTrue($updated->is_root_cause);
    }

    public function test_completing_an_investigation_sets_status_and_moves_the_incident_to_corrective_action(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $incident = $this->assignedIncident($investigator);
        $investigation = app(InvestigationService::class)->start($incident, $investigator, $this->startData('five_whys'));
        app(InvestigationService::class)->addFinding($investigation, $this->findingData([
            'question' => 'Why?', 'finding' => 'Root cause found.', 'is_root_cause' => true,
        ]));

        $completed = app(InvestigationService::class)->complete(
            $investigation->fresh(),
            CompleteInvestigationData::fromArray(['conclusion' => 'Root cause: pump miscalibration.'])
        );

        $this->assertSame(InvestigationStatus::Completed, $completed->status);
        $this->assertNotNull($completed->completed_at);
        $this->assertSame('Root cause: pump miscalibration.', $completed->conclusion);
        $this->assertSame(IncidentStatus::CorrectiveAction, $incident->fresh()->status);
    }

    public function test_removing_a_team_member(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $nurse = User::factory()->create();
        $incident = $this->assignedIncident($investigator);
        $investigation = app(InvestigationService::class)->start($incident, $investigator, $this->startData('fishbone', [
            'team_members' => [['user_id' => $nurse->id, 'role_in_team' => 'Nursing Service Rep']],
        ]));
        $member = $investigation->teamMembers->firstWhere('user_id', $nurse->id);

        app(InvestigationService::class)->removeTeamMember($member);

        $this->assertDatabaseMissing('investigation_team_members', ['id' => $member->id]);
    }
}
