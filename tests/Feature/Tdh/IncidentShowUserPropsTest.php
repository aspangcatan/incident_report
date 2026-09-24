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

    public function test_a_qso_who_can_create_corrective_actions_gets_responsible_users_as_id_and_name_only(): void
    {
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $incident = $this->submittedIncident();
        $incident->forceFill(['status' => IncidentStatus::CorrectiveAction])->save();

        $props = $this->actingAs($qso)->get("/incidents/{$incident->id}")->assertOk()->viewData('page')['props'];

        $this->assertEntryKeys($props['potentialResponsibleUsers'], ['id', 'name']);
    }

    public function test_a_qso_gets_no_responsible_users_when_no_corrective_action_can_be_created_or_edited(): void
    {
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $incident = $this->submittedIncident();

        $this->actingAs($qso)
            ->get("/incidents/{$incident->id}")
            ->assertInertia(fn (Assert $page) => $page->where('potentialResponsibleUsers', []));
    }

    public function test_investigators_are_active_investigators_as_id_and_name_only(): void
    {
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        User::factory()->inactive()->create(['role' => Role::Investigator]);
        $incident = $this->reviewedIncident();

        $props = $this->actingAs($qso)->get("/incidents/{$incident->id}")->assertOk()->viewData('page')['props'];

        $this->assertEntryKeys($props['investigators'], ['id', 'name']);
        $this->assertSame([$investigator->id], array_column($props['investigators'], 'id'));
    }

    public function test_potential_team_members_are_id_name_and_role_only(): void
    {
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $incident = $this->reviewedIncident();
        app(IncidentService::class)->assignInvestigator($incident, $investigator);

        $props = $this->actingAs($qso)->get("/incidents/{$incident->id}")->assertOk()->viewData('page')['props'];

        $this->assertEntryKeys($props['potentialTeamMembers'], ['id', 'name', 'role']);
    }
}
