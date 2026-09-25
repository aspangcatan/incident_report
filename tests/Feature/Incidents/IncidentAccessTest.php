<?php

namespace Tests\Feature\Incidents;

use App\DataTransferObjects\CorrectiveActions\CorrectiveActionData;
use App\DataTransferObjects\Investigations\CompleteInvestigationData;
use App\DataTransferObjects\Investigations\FindingData;
use App\DataTransferObjects\Investigations\StartInvestigationData;
use App\Enums\InvestigationMethodology;
use App\Enums\Role;
use App\Enums\Severity;
use App\Models\Department;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\User;
use App\Services\CorrectiveActionService;
use App\Services\IncidentService;
use App\Services\InvestigationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidentAccessTest extends TestCase
{
    use RefreshDatabase;

    /** Same as CorrectiveActionTest::incidentReadyForCapa(). */
    private function incidentReadyForCapa(?User $investigator = null): Incident
    {
        $department = Department::factory()->create();
        $incidentType = IncidentType::factory()->create();
        $reporter = User::factory()->create();
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
        $investigation = app(InvestigationService::class)->start($incident->fresh(), $investigator, StartInvestigationData::fromArray([
            'objective' => 'Determine root cause.', 'methodology' => InvestigationMethodology::FiveWhys->value,
        ]));
        app(InvestigationService::class)->addFinding($investigation, FindingData::fromArray([
            'question' => 'Why?', 'finding' => 'Root cause.', 'is_root_cause' => true,
        ]));
        app(InvestigationService::class)->complete($investigation->fresh(), CompleteInvestigationData::fromArray(['conclusion' => 'Done.']));

        return $incident->fresh();
    }

    private function addCapaFor(Incident $incident, User $responsible): void
    {
        app(CorrectiveActionService::class)->create($incident, CorrectiveActionData::fromArray([
            'description' => 'Implement double-check checklist.',
            'action_type' => 'corrective',
            'priority' => 'high',
            'due_date' => now()->addDays(14)->toDateString(),
            'responsible_user_id' => $responsible->id,
        ]));
    }

    private function assertVisible(User $user, Incident $incident): void
    {
        $this->actingAs($user)->get("/incidents/{$incident->id}")->assertOk();
        $this->assertTrue(Incident::visibleTo($user)->whereKey($incident->id)->exists());
    }

    private function assertNotVisible(User $user, Incident $incident): void
    {
        $this->actingAs($user)->get("/incidents/{$incident->id}")->assertForbidden();
        $this->assertFalse(Incident::visibleTo($user)->whereKey($incident->id)->exists());
    }

    public function test_an_investigation_team_member_can_open_the_incident(): void
    {
        $incident = $this->incidentReadyForCapa();
        $member = User::factory()->create(['role' => Role::Staff]);
        app(InvestigationService::class)->addTeamMember($incident->investigation, $member, 'Nurse reviewer');

        $this->assertVisible($member, $incident);
    }

    public function test_a_capa_responsible_person_can_open_the_incident(): void
    {
        $incident = $this->incidentReadyForCapa();
        $responsible = User::factory()->create(['role' => Role::Staff]);
        $this->addCapaFor($incident, $responsible);

        $this->assertVisible($responsible, $incident);
    }

    public function test_an_unrelated_staff_member_still_cannot_open_the_incident(): void
    {
        $incident = $this->incidentReadyForCapa();
        $unrelated = User::factory()->create(['role' => Role::Staff]);

        $this->assertNotVisible($unrelated, $incident);
    }

    public function test_work_on_another_incident_does_not_grant_access(): void
    {
        $incident = $this->incidentReadyForCapa();
        $other = $this->incidentReadyForCapa();
        $user = User::factory()->create(['role' => Role::Staff]);
        app(InvestigationService::class)->addTeamMember($other->investigation, $user, 'Nurse reviewer');
        $this->addCapaFor($other, $user);

        $this->assertNotVisible($user, $incident);
        $this->assertVisible($user, $other);
    }

    public function test_a_supervisor_without_a_department_sees_only_incidents_they_work_on(): void
    {
        $incident = $this->incidentReadyForCapa();
        $other = $this->incidentReadyForCapa();
        $noDepartment = $this->incidentReadyForCapa();
        $noDepartment->forceFill(['department_id' => null])->saveQuietly();
        $supervisor = User::factory()->create(['role' => Role::Supervisor, 'department_id' => null]);
        app(InvestigationService::class)->addTeamMember($incident->investigation, $supervisor, 'Reviewer');

        $this->assertVisible($supervisor, $incident);
        $this->assertNotVisible($supervisor, $other);
        $this->assertNotVisible($supervisor, $noDepartment->fresh());
        $this->assertSame([$incident->id], Incident::visibleTo($supervisor)->pluck('id')->all());
    }

    public function test_a_supervisor_sees_their_own_report_filed_in_another_department(): void
    {
        $supervisor = User::factory()->create(['role' => Role::Supervisor, 'department_id' => Department::factory()->create()->id]);
        $incident = app(IncidentService::class)->createDraft($supervisor, [
            'department_id' => Department::factory()->create()->id,
            'incident_type_id' => IncidentType::factory()->create()->id,
            'occurred_at' => now(),
            'location' => 'Lobby',
            'summary' => 'Filed in another department.',
        ]);
        app(IncidentService::class)->submit($incident);

        $this->actingAs($supervisor)->get("/incidents/{$incident->id}")->assertOk();
        $this->assertTrue(Incident::visibleTo($supervisor)->whereKey($incident->id)->exists());
    }
}
