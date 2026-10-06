<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Enums\Severity;
use App\Models\Department;
use App\Models\Incident;
use App\Models\User;
use App\Services\IncidentService;
use App\Support\WorkflowDurations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkflowDurationsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => Role::Administrator]);
    }

    /** A full, valid form: every field, default values. */
    private function payload(array $overrides = []): array
    {
        $perLevel = fn (int $hours) => collect(Severity::cases())->mapWithKeys(fn (Severity $level) => [$level->value => $hours])->all();

        return array_replace_recursive([
            'review_sla_hours' => $perLevel(72),
            'investigation_sla_hours' => $perLevel(168),
            'approval_sla_hours' => $perLevel(72),
            'assessment_sla_hours' => 72,
            'effectiveness_wait_days' => 30,
        ], $overrides);
    }

    public function test_config_defaults_are_used_when_nothing_is_saved(): void
    {
        $this->assertSame(240, WorkflowDurations::get('investigation_sla_hours.level_1_low'));
        $this->assertSame(72, WorkflowDurations::get('assessment_sla_hours'));
    }

    public function test_a_saved_value_replaces_the_config_default(): void
    {
        WorkflowDurations::save(['investigation_sla_hours.level_1_low' => 10]);

        $this->assertSame(10, WorkflowDurations::get('investigation_sla_hours.level_1_low'));
        $this->assertSame(168, WorkflowDurations::get('investigation_sla_hours.level_2_moderate'));
    }

    public function test_only_the_it_admin_can_open_the_page(): void
    {
        $this->actingAs($this->admin())->get('/admin/workflow-durations')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/WorkflowDurations')
                ->has('levels', 5)
                ->where('values.investigation_sla_hours.level_1_low', 240)
                ->where('values.effectiveness_wait_days', (int) config('incident_workflow.effectiveness_wait_days')));

        foreach ([Role::Staff, Role::QualitySafetyOfficer, Role::Management, Role::CqiCommittee] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->get('/admin/workflow-durations')->assertForbidden();
            $this->actingAs($user)->put('/admin/workflow-durations', $this->payload())->assertForbidden();
        }
    }

    public function test_the_it_admin_saves_the_durations(): void
    {
        $this->actingAs($this->admin())
            ->put('/admin/workflow-durations', $this->payload([
                'review_sla_hours' => ['level_5_sentinel' => 12],
                'effectiveness_wait_days' => 0,
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(12, WorkflowDurations::get('review_sla_hours.level_5_sentinel'));
        $this->assertSame(0, WorkflowDurations::get('effectiveness_wait_days'));
        $this->assertSame(17, DB::table('workflow_settings')->count());
    }

    public function test_out_of_range_values_are_rejected(): void
    {
        $this->actingAs($this->admin())
            ->put('/admin/workflow-durations', $this->payload([
                'review_sla_hours' => ['level_1_low' => 0],
                'investigation_sla_hours' => ['level_2_moderate' => 8761],
                'approval_sla_hours' => ['level_3_high' => 'abc'],
                'assessment_sla_hours' => null,
                'effectiveness_wait_days' => 366,
            ]))
            ->assertSessionHasErrors([
                'review_sla_hours.level_1_low',
                'investigation_sla_hours.level_2_moderate',
                'approval_sla_hours.level_3_high',
                'assessment_sla_hours',
                'effectiveness_wait_days',
            ]);

        $this->assertSame(0, DB::table('workflow_settings')->count());
    }

    public function test_a_new_investigation_deadline_uses_the_saved_hours(): void
    {
        WorkflowDurations::save(['investigation_sla_hours.level_2_moderate' => 240]);

        $incident = app(IncidentService::class)->createDraft(User::factory()->create(), [
            'department_id' => Department::factory()->create()->id,
            'occurred_at' => now(),
            'location' => 'Ward 3',
            'summary' => 'Test incident.',
        ]);
        $incident->forceFill(['severity' => Severity::Level2Moderate])->save();

        $this->freezeTime(function () use ($incident) {
            app(IncidentService::class)->assignInvestigator($incident->fresh(), User::factory()->create());

            $this->assertTrue(Incident::find($incident->id)->target_closure_date->toDateString() === now()->addDays(10)->toDateString());
        });
    }
}
