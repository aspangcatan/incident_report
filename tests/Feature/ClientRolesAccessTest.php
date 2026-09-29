<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\Severity;
use App\Models\Department;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\User;
use App\Services\IncidentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ClientRolesAccessTest extends TestCase
{
    use RefreshDatabase;

    private function assessedIncident(Department $department): Incident
    {
        $service = app(IncidentService::class);
        $incident = $service->createDraft(User::factory()->create(), [
            'department_id' => $department->id,
            'incident_type_ids' => [IncidentType::factory()->create()->id],
            'severity' => Severity::Level2Moderate->value,
            'occurred_at' => now(),
            'location' => 'Ward 3',
            'summary' => 'Test incident.',
        ]);
        $service->submit($incident);
        $head = User::factory()->create(['role' => Role::DepartmentHead, 'department_id' => $department->id]);
        $service->completeAssessment($incident->fresh(), $head);

        return $incident->fresh();
    }

    private function leaderOver(Department ...$departments): User
    {
        $leader = User::factory()->create(['role' => Role::Leadership]);
        foreach ($departments as $department) {
            DB::table('leadership_departments')->insert(['user_id' => $leader->id, 'department_id' => $department->id]);
        }

        return $leader;
    }

    public function test_executives_and_the_committee_see_every_incident(): void
    {
        $incident = $this->assessedIncident(Department::factory()->create());

        foreach ([Role::Management, Role::CqiCommittee, Role::QualitySafetyOfficer] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->assertTrue($user->can('view', $incident), $role->value);
            $this->assertTrue(Incident::visibleTo($user)->whereKey($incident->id)->exists(), $role->value);
            $this->assertTrue($user->can('viewAnalytics', Incident::class), $role->value);
        }
    }

    public function test_leadership_sees_only_the_departments_mapped_to_them(): void
    {
        $mine = Department::factory()->create();
        $other = Department::factory()->create();
        $inMine = $this->assessedIncident($mine);
        $inOther = $this->assessedIncident($other);
        $leader = $this->leaderOver($mine);

        $this->assertTrue($leader->can('view', $inMine));
        $this->assertFalse($leader->can('view', $inOther));
        $this->assertSame([$inMine->id], Incident::visibleTo($leader)->pluck('id')->all());
        $this->assertTrue($leader->can('viewAny', Incident::class));
        $this->assertTrue($leader->can('viewAnalytics', Incident::class));
    }

    public function test_the_it_administrator_has_no_clinical_access(): void
    {
        $incident = $this->assessedIncident(Department::factory()->create());
        $admin = User::factory()->create(['role' => Role::Administrator]);

        $this->assertFalse($admin->can('view', $incident));
        $this->assertFalse($admin->can('viewAny', Incident::class));
        $this->assertFalse($admin->can('viewAnalytics', Incident::class));
        $this->assertFalse($admin->can('review', $incident));
        $this->assertFalse(Incident::visibleTo($admin)->whereKey($incident->id)->exists());
        $this->actingAs($admin)->get("/incidents/{$incident->id}")->assertForbidden();
        $this->actingAs($admin)->get('/analytics')->assertForbidden();
    }

    public function test_admin_and_cqi_office_map_leaders_to_departments(): void
    {
        $leader = $this->leaderOver();
        $departments = Department::factory()->count(2)->create();

        foreach ([Role::Administrator, Role::QualitySafetyOfficer] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->get('/admin/leadership')
                ->assertInertia(fn ($page) => $page->component('Admin/Leadership')->where('leaders.0.id', $leader->id));
        }

        $this->actingAs(User::factory()->create(['role' => Role::Administrator]))
            ->put("/admin/leadership/{$leader->id}", ['department_ids' => $departments->pluck('id')->all()])
            ->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing($departments->pluck('id')->all(), $leader->fresh()->leadershipDepartmentIds());
    }

    public function test_other_roles_cannot_map_leaders_and_only_leaders_can_be_mapped(): void
    {
        $leader = $this->leaderOver();

        $this->actingAs(User::factory()->create(['role' => Role::DepartmentHead]))
            ->get('/admin/leadership')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => Role::Management]))
            ->put("/admin/leadership/{$leader->id}", ['department_ids' => []])->assertForbidden();

        $notALeader = User::factory()->create(['role' => Role::Staff]);
        $this->actingAs(User::factory()->create(['role' => Role::Administrator]))
            ->put("/admin/leadership/{$notALeader->id}", ['department_ids' => []])->assertNotFound();
    }
}
