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
            'incident_type_ids' => [$incidentType->id],
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

    public function test_starting_an_investigation_defaults_target_completion_to_now_plus_the_severitys_investigation_sla(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $incident = $this->assignedIncident($investigator);

        $investigation = app(InvestigationService::class)->start($incident, $investigator, $this->startData('hfacs'));

        // Level 2 Moderate -> 168 hours per config/incident_workflow.php
        $this->assertEqualsWithDelta(
            now()->addHours(168)->timestamp,
            $investigation->target_completion_at->timestamp,
            5
        );
    }

    public function test_starting_a_late_investigation_does_not_inherit_an_already_past_deadline(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $incident = $this->assignedIncident($investigator);
        // Simulate the incident's original assignment-time SLA having already lapsed.
        $incident->forceFill(['target_closure_date' => now()->subDays(5)])->save();

        $investigation = app(InvestigationService::class)->start($incident->fresh(), $investigator, $this->startData('hfacs'));

        $this->assertTrue($investigation->target_completion_at->isFuture());
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

    public function test_the_assigned_investigator_can_start_an_investigation(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $incident = $this->assignedIncident($investigator);

        $this->assertTrue($investigator->can('start', $incident));
    }

    public function test_a_different_investigator_cannot_start_the_investigation(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $otherInvestigator = User::factory()->create(['role' => Role::Investigator]);
        $incident = $this->assignedIncident($investigator);

        $this->assertFalse($otherInvestigator->can('start', $incident));
    }

    public function test_the_cqi_office_cannot_start_someone_elses_investigation(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $incident = $this->assignedIncident($investigator);

        $this->assertFalse($qso->can('start', $incident));
        $this->assertTrue($investigator->can('start', $incident));
    }

    public function test_nobody_can_start_an_investigation_before_the_incident_is_assigned(): void
    {
        $reporter = $this->makeReporter();
        $department = Department::factory()->create();
        $incidentType = IncidentType::factory()->create();
        $investigator = User::factory()->create(['role' => Role::Investigator]);

        $incident = app(IncidentService::class)->createDraft($reporter, [
            'department_id' => $department->id,
            'incident_type_ids' => [$incidentType->id],
            'severity' => Severity::Level2Moderate->value,
            'occurred_at' => now(),
            'location' => 'Ward 3',
            'summary' => 'Test incident.',
        ]);
        app(IncidentService::class)->submit($incident);

        $this->assertFalse($investigator->can('start', $incident->fresh()));
    }

    public function test_only_the_lead_investigator_manages_the_team_and_completes(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $teamMember = User::factory()->create();
        $incident = $this->assignedIncident($investigator);
        $investigation = app(InvestigationService::class)->start($incident, $investigator, $this->startData('fishbone', [
            'team_members' => [['user_id' => $teamMember->id, 'role_in_team' => 'Rep']],
        ]));
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);

        $this->assertTrue($investigator->can('manageTeam', $investigation));
        $this->assertFalse($qso->can('manageTeam', $investigation));
        $this->assertFalse($teamMember->can('manageTeam', $investigation));
        $this->assertFalse($qso->can('complete', $investigation));
        $this->assertFalse($qso->can('recordFindings', $investigation));
    }

    public function test_a_cqi_facilitator_added_to_the_team_records_findings_but_does_not_lead(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $incident = $this->assignedIncident($investigator);
        $investigation = app(InvestigationService::class)->start($incident, $investigator, $this->startData('simple', [
            'team_members' => [['user_id' => $qso->id, 'role_in_team' => 'Facilitator']],
        ]));

        $this->assertTrue($qso->can('recordFindings', $investigation));
        $this->assertFalse($qso->can('manageTeam', $investigation));
        $this->assertFalse($qso->can('complete', $investigation));
    }

    public function test_a_team_member_can_record_findings_but_a_stranger_cannot(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $teamMember = User::factory()->create();
        $stranger = User::factory()->create();
        $incident = $this->assignedIncident($investigator);
        $investigation = app(InvestigationService::class)->start($incident, $investigator, $this->startData('fishbone', [
            'team_members' => [['user_id' => $teamMember->id, 'role_in_team' => 'Rep']],
        ]));

        $this->assertTrue($investigator->can('recordFindings', $investigation));
        $this->assertTrue($teamMember->can('recordFindings', $investigation));
        $this->assertFalse($stranger->can('recordFindings', $investigation));
    }

    public function test_nobody_can_record_findings_on_a_completed_investigation(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $incident = $this->assignedIncident($investigator);
        $investigation = app(InvestigationService::class)->start($incident, $investigator, $this->startData('five_whys'));
        app(InvestigationService::class)->addFinding($investigation, $this->findingData(['question' => 'Q', 'finding' => 'F', 'is_root_cause' => true]));
        app(InvestigationService::class)->complete($investigation->fresh(), CompleteInvestigationData::fromArray(['conclusion' => 'Done.']));

        $this->assertFalse($investigator->can('recordFindings', $investigation->fresh()));
    }

    public function test_only_the_lead_investigator_or_qso_can_complete_the_investigation(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $teamMember = User::factory()->create();
        $incident = $this->assignedIncident($investigator);
        $investigation = app(InvestigationService::class)->start($incident, $investigator, $this->startData('fishbone', [
            'team_members' => [['user_id' => $teamMember->id, 'role_in_team' => 'Rep']],
        ]));

        $this->assertTrue($investigator->can('complete', $investigation));
        $this->assertFalse($teamMember->can('complete', $investigation));
    }

    public function test_the_assigned_investigator_can_start_an_investigation_via_http(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $incident = $this->assignedIncident($investigator);

        // No RCA methodology to choose: every investigation is a simple one.
        $response = $this->actingAs($investigator)->post("/incidents/{$incident->id}/investigation", [
            'objective' => 'Determine root cause.',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('investigations', ['incident_id' => $incident->id, 'methodology' => 'simple']);
    }

    public function test_starting_an_investigation_requires_an_objective_and_ignores_any_methodology(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $incident = $this->assignedIncident($investigator);

        $this->actingAs($investigator)
            ->post("/incidents/{$incident->id}/investigation", ['methodology' => 'five_whys'])
            ->assertSessionHasErrors(['objective'])
            ->assertSessionDoesntHaveErrors(['methodology']);

        $this->actingAs($investigator)
            ->post("/incidents/{$incident->id}/investigation", ['objective' => 'Find out why.', 'methodology' => 'five_whys']);
        $this->assertDatabaseHas('investigations', ['incident_id' => $incident->id, 'methodology' => 'simple']);
    }

    public function test_starting_an_investigation_rejects_an_explicit_past_target_completion_date(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $incident = $this->assignedIncident($investigator);

        $this->actingAs($investigator)
            ->post("/incidents/{$incident->id}/investigation", [
                'objective' => 'Determine root cause.',
                'methodology' => 'five_whys',
                'target_completion_at' => now()->subDay()->toDateString(),
            ])
            ->assertSessionHasErrors(['target_completion_at']);
    }

    public function test_adding_a_team_member_via_http(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $nurse = User::factory()->create();
        $incident = $this->assignedIncident($investigator);
        $investigation = app(InvestigationService::class)->start($incident, $investigator, $this->startData('fishbone'));

        $response = $this->actingAs($investigator)->post("/investigations/{$investigation->id}/team-members", [
            'user_id' => $nurse->id,
            'role_in_team' => 'Nursing Service Rep',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('investigation_team_members', ['investigation_id' => $investigation->id, 'user_id' => $nurse->id]);
    }

    public function test_a_stranger_cannot_add_a_team_member_via_http(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $stranger = User::factory()->create();
        $nurse = User::factory()->create();
        $incident = $this->assignedIncident($investigator);
        $investigation = app(InvestigationService::class)->start($incident, $investigator, $this->startData('fishbone'));

        $this->actingAs($stranger)
            ->post("/investigations/{$investigation->id}/team-members", ['user_id' => $nurse->id, 'role_in_team' => 'Rep'])
            ->assertForbidden();
    }

    public function test_adding_the_same_team_member_twice_returns_a_validation_error_not_a_server_error(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $nurse = User::factory()->create();
        $incident = $this->assignedIncident($investigator);
        $investigation = app(InvestigationService::class)->start($incident, $investigator, $this->startData('fishbone'));

        $this->actingAs($investigator)->post("/investigations/{$investigation->id}/team-members", [
            'user_id' => $nurse->id,
            'role_in_team' => 'Nursing Service Rep',
        ])->assertRedirect();

        $this->actingAs($investigator)
            ->post("/investigations/{$investigation->id}/team-members", [
                'user_id' => $nurse->id,
                'role_in_team' => 'Nursing Service Rep',
            ])
            ->assertSessionHasErrors(['user_id']);

        $this->assertSame(
            1,
            $investigation->teamMembers()->where('user_id', $nurse->id)->count()
        );
    }

    public function test_starting_an_investigation_rejects_a_duplicate_user_id_in_the_team_members_list(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $nurse = User::factory()->create();
        $incident = $this->assignedIncident($investigator);

        $this->actingAs($investigator)
            ->post("/incidents/{$incident->id}/investigation", [
                'objective' => 'Determine root cause.',
                'methodology' => 'fishbone',
                'team_members' => [
                    ['user_id' => $nurse->id, 'role_in_team' => 'Nursing Service Rep'],
                    ['user_id' => $nurse->id, 'role_in_team' => 'Duplicate'],
                ],
            ])
            ->assertSessionHasErrors(['team_members.0.user_id', 'team_members.1.user_id']);

        $this->assertDatabaseMissing('investigations', ['incident_id' => $incident->id]);
    }

    public function test_the_lead_cannot_be_removed_from_the_team(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $incident = $this->assignedIncident($investigator);
        $investigation = app(InvestigationService::class)->start($incident, $investigator, $this->startData('simple'));
        $leadRow = $investigation->teamMembers()->where('user_id', $investigator->id)->first();

        $this->actingAs($investigator)
            ->delete("/investigations/{$investigation->id}/team-members/{$leadRow->id}")
            ->assertSessionHas('error');

        $this->assertDatabaseHas('investigation_team_members', ['id' => $leadRow->id]);
    }

    public function test_removing_a_team_member_scoped_to_another_investigation_is_rejected(): void
    {
        $investigatorA = User::factory()->create(['role' => Role::Investigator]);
        $investigatorB = User::factory()->create(['role' => Role::Investigator]);
        $nurse = User::factory()->create();
        $incidentA = $this->assignedIncident($investigatorA);
        $incidentB = $this->assignedIncident($investigatorB);
        $investigationA = app(InvestigationService::class)->start($incidentA, $investigatorA, $this->startData('fishbone'));
        $investigationB = app(InvestigationService::class)->start($incidentB, $investigatorB, $this->startData('fishbone', [
            'team_members' => [['user_id' => $nurse->id, 'role_in_team' => 'Rep']],
        ]));
        $memberOfB = $investigationB->teamMembers->firstWhere('user_id', $nurse->id);

        $this->actingAs($investigatorA)
            ->delete("/investigations/{$investigationA->id}/team-members/{$memberOfB->id}")
            ->assertNotFound();
    }

    public function test_a_team_member_can_add_a_finding_via_http(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $incident = $this->assignedIncident($investigator);
        $investigation = app(InvestigationService::class)->start($incident, $investigator, $this->startData('five_whys'));

        $response = $this->actingAs($investigator)->post("/investigations/{$investigation->id}/findings", [
            'question' => 'Why did it happen?',
            'finding' => 'Because of X.',
            'is_root_cause' => false,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('investigation_findings', ['investigation_id' => $investigation->id, 'finding' => 'Because of X.']);
    }

    public function test_updating_a_finding_via_http(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $incident = $this->assignedIncident($investigator);
        $investigation = app(InvestigationService::class)->start($incident, $investigator, $this->startData('five_whys'));
        $finding = app(InvestigationService::class)->addFinding($investigation, $this->findingData(['question' => 'Q', 'finding' => 'Original', 'is_root_cause' => false]));

        $this->actingAs($investigator)
            ->patch("/investigations/{$investigation->id}/findings/{$finding->id}", [
                'question' => 'Q', 'finding' => 'Corrected.', 'is_root_cause' => true, 'category' => 'equipment',
            ])
            ->assertRedirect();

        $this->assertSame('Corrected.', $finding->fresh()->finding);
        $this->assertTrue($finding->fresh()->is_root_cause);
        $this->assertSame('equipment', $finding->fresh()->category);
    }

    public function test_a_root_cause_finding_needs_a_known_cause_type(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $incident = $this->assignedIncident($investigator);
        $investigation = app(InvestigationService::class)->start($incident, $investigator, $this->startData());

        $this->actingAs($investigator)
            ->post("/investigations/{$investigation->id}/findings", ['finding' => 'Pump failed.', 'is_root_cause' => true])
            ->assertSessionHasErrors('category');

        $this->actingAs($investigator)
            ->post("/investigations/{$investigation->id}/findings", ['finding' => 'Pump failed.', 'is_root_cause' => true, 'category' => 'bad luck'])
            ->assertSessionHasErrors('category');

        $this->actingAs($investigator)
            ->post("/investigations/{$investigation->id}/findings", ['finding' => 'Night shift was short.', 'is_root_cause' => false])
            ->assertSessionHasNoErrors();
    }

    public function test_updating_a_finding_scoped_to_another_investigation_is_rejected(): void
    {
        $investigatorA = User::factory()->create(['role' => Role::Investigator]);
        $investigatorB = User::factory()->create(['role' => Role::Investigator]);
        $incidentA = $this->assignedIncident($investigatorA);
        $incidentB = $this->assignedIncident($investigatorB);
        $investigationA = app(InvestigationService::class)->start($incidentA, $investigatorA, $this->startData('five_whys'));
        $investigationB = app(InvestigationService::class)->start($incidentB, $investigatorB, $this->startData('five_whys'));
        $findingOfB = app(InvestigationService::class)->addFinding($investigationB, $this->findingData(['question' => 'Q', 'finding' => 'F', 'is_root_cause' => false]));

        $this->actingAs($investigatorA)
            ->patch("/investigations/{$investigationA->id}/findings/{$findingOfB->id}", ['finding' => 'Hijacked.', 'is_root_cause' => false])
            ->assertNotFound();
    }

    public function test_completing_an_investigation_requires_at_least_one_finding(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $incident = $this->assignedIncident($investigator);
        $investigation = app(InvestigationService::class)->start($incident, $investigator, $this->startData('five_whys'));

        $this->actingAs($investigator)
            ->post("/investigations/{$investigation->id}/complete", ['conclusion' => 'Done.'])
            ->assertSessionHasErrors(['conclusion']);

        $this->assertSame(InvestigationStatus::InProgress, $investigation->fresh()->status);
    }

    public function test_completing_an_investigation_via_http(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $incident = $this->assignedIncident($investigator);
        $investigation = app(InvestigationService::class)->start($incident, $investigator, $this->startData('five_whys'));
        app(InvestigationService::class)->addFinding($investigation, $this->findingData(['question' => 'Q', 'finding' => 'Root cause.', 'is_root_cause' => true]));

        $response = $this->actingAs($investigator)->post("/investigations/{$investigation->id}/complete", [
            'conclusion' => 'Root cause: pump miscalibration.',
        ]);

        $response->assertRedirect();
        $this->assertSame(InvestigationStatus::Completed, $investigation->fresh()->status);
    }

    public function test_the_incident_show_page_exposes_a_resource_shaped_investigation_prop_and_can_flags(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $incident = $this->assignedIncident($investigator);

        $response = $this->actingAs($investigator)->get("/incidents/{$incident->id}?tab=investigation");

        $response->assertInertia(fn ($page) => $page
            ->component('Incidents/Show')
            ->where('investigation', null)
            ->where('can.startInvestigation', true)
            ->where('can.completeInvestigation', false)
        );

        $investigation = app(InvestigationService::class)->start($incident->fresh(), $investigator, $this->startData('five_whys'));

        $this->actingAs($investigator)
            ->get("/incidents/{$incident->id}?tab=investigation")
            ->assertInertia(fn ($page) => $page
                ->where('can.startInvestigation', false)
                ->where('can.recordFindings', true)
                ->where('investigation.id', $investigation->id)
                ->where('investigation.methodology.value', 'five_whys')
                ->where('investigation.methodology.label', '5 Whys')
                ->where('investigation.methodology.uses_sequence', true)
                ->missing('incident.investigation')
            );
    }

    public function test_an_inactive_tdh_user_cannot_be_added_to_the_team(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $retired = User::factory()->inactive()->create();
        $incident = $this->assignedIncident($investigator);
        $investigation = app(InvestigationService::class)->start($incident, $investigator, $this->startData('fishbone'));

        $this->actingAs($investigator)->post("/investigations/{$investigation->id}/team-members", [
            'user_id' => $retired->id,
            'role_in_team' => 'Nursing Service Rep',
        ])->assertSessionHasErrors(['user_id']);
    }
}
