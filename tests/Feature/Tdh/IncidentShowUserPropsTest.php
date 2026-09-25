<?php

namespace Tests\Feature\Tdh;

use App\Enums\IncidentStatus;
use App\Enums\Role;
use App\Enums\Severity;
use App\Models\Department;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\User;
use App\Services\IncidentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Incident Show must not ship the hospital directory: user lists are sent
 * only to viewers who can use them, and only with the fields the panels need.
 */
class IncidentShowUserPropsTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    private User $reporter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->department = Department::factory()->create();
        $this->reporter = User::factory()->create(['department_id' => $this->department->id]);
    }

    private function submittedIncident(): Incident
    {
        $incident = app(IncidentService::class)->createDraft($this->reporter, [
            'department_id' => $this->department->id,
            'incident_type_id' => IncidentType::factory()->create()->id,
            'severity' => Severity::Level2Moderate->value,
            'occurred_at' => now(),
            'location' => 'Ward 3',
            'summary' => 'Test incident.',
        ]);
        app(IncidentService::class)->submit($incident);

        return $incident->fresh();
    }

    private function reviewedIncident(): Incident
    {
        $incident = $this->submittedIncident();
        $supervisor = User::factory()->create(['role' => Role::Supervisor, 'department_id' => $this->department->id]);
        app(IncidentService::class)->markReviewed($incident, $supervisor, null);

        return $incident->fresh();
    }

    private function assertEntryKeys(array $entries, array $keys): void
    {
        $this->assertNotEmpty($entries);

        foreach ($entries as $entry) {
            $this->assertEqualsCanonicalizing($keys, array_keys($entry));
        }
    }

    public function test_a_staff_reporter_gets_no_user_lists(): void
    {
        User::factory()->count(3)->create();
        $incident = $this->submittedIncident();

        $this->actingAs($this->reporter)
            ->get("/incidents/{$incident->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('potentialResponsibleUsers', [])
                ->where('potentialTeamMembers', [])
                ->where('investigators', []));
    }

    public function test_a_department_head_who_can_create_corrective_actions_gets_responsible_users_as_id_name_and_label_only(): void
    {
        $head = User::factory()->create(['role' => Role::DepartmentHead, 'department_id' => $this->department->id]);
        $incident = $this->submittedIncident();
        $incident->forceFill(['status' => IncidentStatus::CorrectiveAction])->save();

        $props = $this->actingAs($head)->get("/incidents/{$incident->id}")->assertOk()->viewData('page')['props'];

        $this->assertEntryKeys($props['potentialResponsibleUsers'], ['id', 'name', 'label']);
    }

    public function test_a_qso_gets_no_responsible_users_at_the_corrective_action_stage_since_it_cannot_create_corrective_actions(): void
    {
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $incident = $this->submittedIncident();
        $incident->forceFill(['status' => IncidentStatus::CorrectiveAction])->save();

        $this->actingAs($qso)
            ->get("/incidents/{$incident->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.createCorrectiveAction', false)
                ->where('potentialResponsibleUsers', []));
    }

    public function test_a_qso_gets_no_responsible_users_when_no_corrective_action_can_be_created_or_edited(): void
    {
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $incident = $this->submittedIncident();

        $this->actingAs($qso)
            ->get("/incidents/{$incident->id}")
            ->assertInertia(fn (Assert $page) => $page->where('potentialResponsibleUsers', []));
    }

    public function test_investigators_are_active_investigators_and_department_staff_as_id_name_and_label_only(): void
    {
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        User::factory()->inactive()->create(['role' => Role::Investigator]);
        $colleague = User::factory()->create(['department_id' => $this->department->id]);
        User::factory()->inactive()->create(['department_id' => $this->department->id]);
        User::factory()->create(['department_id' => Department::factory()->create()->id]);
        $incident = $this->reviewedIncident();

        $props = $this->actingAs($qso)->get("/incidents/{$incident->id}")->assertOk()->viewData('page')['props'];

        $this->assertEntryKeys($props['investigators'], ['id', 'name', 'label']);
        // The reporter and the supervisor who reviewed it are department staff too.
        $expected = User::active()->where('section', $this->department->id)->pluck('id')->push($investigator->id)->all();
        $this->assertContains($colleague->id, $expected);
        $this->assertEqualsCanonicalizing($expected, array_column($props['investigators'], 'id'));
    }

    public function test_potential_team_members_are_id_name_role_and_label_only(): void
    {
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $incident = $this->reviewedIncident();
        app(IncidentService::class)->assignInvestigator($incident, $investigator);

        $props = $this->actingAs($qso)->get("/incidents/{$incident->id}")->assertOk()->viewData('page')['props'];

        $this->assertEntryKeys($props['potentialTeamMembers'], ['id', 'name', 'role', 'label']);
    }

    /**
     * Real tdh data has many same-name people (and leading-space username
     * twins), so pickers show "name — section" to tell them apart, falling
     * back to the designation and then to the user id.
     */
    public function test_picker_labels_disambiguate_same_name_users(): void
    {
        // Read from the investigator picker: it can list people from any
        // section (and none), which the CAPA responsible picker no longer does.
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $incident = $this->reviewedIncident();

        $pharmacy = Department::factory()->create(['description' => 'Pharmacy']);
        $inSection = User::factory()->create(['role' => Role::Investigator, 'fname' => 'Ana', 'mname' => null, 'lname' => 'Reyes', 'department_id' => $pharmacy->id]);
        $withDesignation = User::factory()->create(['role' => Role::Investigator, 'fname' => 'Ana', 'mname' => null, 'lname' => 'Reyes', 'department_id' => null, 'other_designation' => 'Nurse II']);
        $bare = User::factory()->create(['role' => Role::Investigator, 'fname' => 'Ana', 'mname' => null, 'lname' => 'Reyes', 'department_id' => null]);

        $props = $this->actingAs($qso)->get("/incidents/{$incident->id}")->assertOk()->viewData('page')['props'];
        $labels = array_column($props['investigators'], 'label', 'id');

        $this->assertSame('Ana Reyes — Pharmacy', $labels[$inSection->id]);
        $this->assertSame('Ana Reyes — Nurse II', $labels[$withDesignation->id]);
        $this->assertSame("Ana Reyes — #{$bare->id}", $labels[$bare->id]);
    }

    public function test_responsible_users_are_only_active_staff_of_the_incidents_department(): void
    {
        $head = User::factory()->create(['role' => Role::DepartmentHead, 'department_id' => $this->department->id]);
        $colleague = User::factory()->create(['department_id' => $this->department->id]);
        $retired = User::factory()->inactive()->create(['department_id' => $this->department->id]);
        $outsider = User::factory()->create(['department_id' => Department::factory()->create()->id]);
        $noSection = User::factory()->create(['department_id' => null]);
        $incident = $this->submittedIncident();
        $incident->forceFill(['status' => IncidentStatus::CorrectiveAction])->save();

        $props = $this->actingAs($head)->get("/incidents/{$incident->id}")->assertOk()->viewData('page')['props'];
        $ids = array_column($props['potentialResponsibleUsers'], 'id');

        $this->assertEqualsCanonicalizing([$this->reporter->id, $colleague->id, $head->id], $ids);
        $this->assertNotContains($retired->id, $ids);
        $this->assertNotContains($outsider->id, $ids);
        $this->assertNotContains($noSection->id, $ids);
    }

    public function test_a_department_head_of_the_incidents_department_gets_responsible_users(): void
    {
        $head = User::factory()->create(['role' => Role::DepartmentHead, 'department_id' => $this->department->id]);
        $incident = $this->submittedIncident();
        $incident->forceFill(['status' => IncidentStatus::CorrectiveAction])->save();

        $props = $this->actingAs($head)->get("/incidents/{$incident->id}")->assertOk()->viewData('page')['props'];

        $this->assertTrue($props['can']['createCorrectiveAction']);
        $this->assertEqualsCanonicalizing([$this->reporter->id, $head->id], array_column($props['potentialResponsibleUsers'], 'id'));
    }
}
