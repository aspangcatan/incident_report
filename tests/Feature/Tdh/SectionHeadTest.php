<?php

namespace Tests\Feature\Tdh;

use App\Enums\Role;
use App\Models\Department;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\User;
use App\Services\IncidentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SectionHeadTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_heads_the_sections_whose_head_column_is_their_id(): void
    {
        $own = Department::factory()->create();
        [$a, $b] = Department::factory()->count(2)->create();
        $user = User::factory()->headOf($a, $b)->create(['department_id' => $own->id]);

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $user->headedDepartmentIds());
        $this->assertTrue($user->isDepartmentHead());
        $this->assertTrue($user->isHeadOf($a->id));
        $this->assertFalse($user->isHeadOf($own->id));
        $this->assertFalse($user->isHeadOf(null));
    }

    public function test_a_user_who_heads_nothing_is_not_a_department_head(): void
    {
        $user = User::factory()->create();

        $this->assertSame([], $user->headedDepartmentIds());
        $this->assertFalse($user->isDepartmentHead());
    }

    public function test_heading_a_section_keeps_the_ir_level(): void
    {
        $section = Department::factory()->create();
        $user = User::factory()->headOf($section)->create(['role' => Role::Leadership]);

        $this->assertSame(Role::Leadership, $user->role);
        $this->assertTrue($user->isHeadOf($section->id));
    }

    /** A submitted incident in $department. */
    private function submittedIncident(Department $department): Incident
    {
        $service = app(IncidentService::class);
        $incident = $service->createDraft(User::factory()->create(), [
            'department_id' => $department->id,
            'incident_type_ids' => [IncidentType::factory()->create()->id],
            'occurred_at' => now(),
            'location' => 'Ward 3',
            'summary' => 'Test incident.',
        ]);
        $service->submit($incident);

        return $incident->fresh();
    }

    public function test_a_staff_level_head_assesses_incidents_of_the_section_they_head_only(): void
    {
        [$own, $headed] = Department::factory()->count(2)->create();
        $head = User::factory()->headOf($headed)->create(['department_id' => $own->id]);

        $inHeaded = $this->submittedIncident($headed);
        $inOwn = $this->submittedIncident($own);

        $this->assertTrue($head->can('viewAny', Incident::class));
        $this->assertTrue($head->can('viewAnalytics', Incident::class));
        $this->assertTrue($head->can('view', $inHeaded));
        $this->assertTrue($head->can('completeAssessment', $inHeaded));
        $this->assertTrue($head->can('recommendInvestigator', $inHeaded));
        $this->assertFalse($head->can('completeAssessment', $inOwn));
    }

    public function test_a_leadership_user_who_heads_a_section_gets_both_scopes(): void
    {
        [$mapped, $headed, $other] = Department::factory()->count(3)->create();
        $leader = User::factory()->headOf($headed)->create(['role' => Role::Leadership]);
        \Illuminate\Support\Facades\DB::table('leadership_departments')->insert([
            'user_id' => $leader->id, 'department_id' => $mapped->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertTrue($leader->can('view', $this->submittedIncident($mapped)));
        $this->assertFalse($leader->can('completeAssessment', $this->submittedIncident($mapped)));
        $this->assertTrue($leader->can('completeAssessment', $this->submittedIncident($headed)));
        $this->assertFalse($leader->can('view', $this->submittedIncident($other)));
    }

    public function test_a_head_lists_incidents_and_gets_queues_for_the_sections_they_head(): void
    {
        [$own, $headed, $other] = Department::factory()->count(3)->create();
        $head = User::factory()->headOf($headed)->create(['department_id' => $own->id]);
        $visible = $this->submittedIncident($headed);
        $hidden = $this->submittedIncident($other);

        $ids = Incident::query()->visibleTo($head)->pluck('id')->all();
        $this->assertContains($visible->id, $ids);
        $this->assertNotContains($hidden->id, $ids);

        $this->assertTrue(\App\Queries\IncidentQueueQuery::investigationWorkspace($head));
        $this->assertTrue(\App\Queries\CorrectiveActionQueueQuery::allowed($head));

        $this->actingAs($head)->get('/')->assertInertia(fn ($page) => $page
            ->where('auth.can.viewAllIncidents', true)
            ->where('auth.can.viewAnalytics', true)
            ->where('auth.can.investigationWorkspace', true)
            ->where('auth.can.capaOperations', true));
    }
}
