<?php

namespace Tests\Feature\Incidents;

use App\DataTransferObjects\CorrectiveActions\CompleteCorrectiveActionData;
use App\DataTransferObjects\CorrectiveActions\CorrectiveActionData;
use App\DataTransferObjects\CorrectiveActions\VerifyCorrectiveActionData;
use App\Enums\IncidentStatus;
use App\Enums\Role;
use App\Enums\Severity;
use App\Models\Department;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\User;
use App\Notifications\EffectivenessCheckDueNotification;
use App\Notifications\ReadyForClosureNotification;
use App\Services\CorrectiveActionService;
use App\Services\IncidentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class EffectivenessCheckTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    private User $head;

    private User $committee;

    protected function setUp(): void
    {
        parent::setUp();
        // Independent of the local setting (it may be 0 while someone tests by hand).
        config(['incident_workflow.effectiveness_wait_days' => 30]);
        $this->department = Department::factory()->create();
        $this->head = User::factory()->headOf($this->department->id)->create(['department_id' => $this->department->id]);
        $this->committee = User::factory()->create(['role' => Role::CqiCommittee]);
    }

    /** Reported, triaged low, investigation skipped, one CAPA done and verified. */
    private function verifiedIncident(): Incident
    {
        $service = app(IncidentService::class);
        $incident = $service->createDraft(User::factory()->create(), [
            'department_id' => $this->department->id,
            'incident_type_ids' => [IncidentType::factory()->create()->id],
            'severity' => Severity::Level1Low->value,
            'occurred_at' => now(),
            'location' => 'Ward 3',
            'summary' => 'Test incident.',
        ]);
        $service->submit($incident);
        $service->completeAssessment($incident->fresh(), $this->head);
        $cqi = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $service->markReviewed($incident->fresh(), $cqi, null);
        $service->skipInvestigation($incident->fresh(), $cqi, 'Minor.');

        $capa = app(CorrectiveActionService::class)->create($incident->fresh(), CorrectiveActionData::fromArray([
            'description' => 'Fix the rail.', 'action_type' => 'corrective', 'priority' => 'high',
            'due_date' => now()->addDays(7)->toDateString(),
        ]));
        $this->actingAs(User::factory()->create(['department_id' => $this->department->id]));
        app(CorrectiveActionService::class)->complete($capa, CompleteCorrectiveActionData::fromArray(['completion_notes' => 'Done.']));
        app(CorrectiveActionService::class)->verify($capa->fresh(), $this->head, VerifyCorrectiveActionData::fromArray(['verification_comments' => 'OK.']));

        return $incident->fresh();
    }

    public function test_verifying_every_capa_starts_a_30_day_wait(): void
    {
        $incident = $this->verifiedIncident();

        $this->assertSame(IncidentStatus::Verified, $incident->status);
        $this->assertTrue($incident->effectiveness_due_at->between(now()->addDays(29), now()->addDays(31)));
        $this->assertFalse($this->committee->can('checkEffectiveness', $incident));
        $this->assertFalse($this->head->can('requestApproval', $incident));
    }

    public function test_after_the_wait_an_effective_result_allows_closure(): void
    {
        $incident = $this->verifiedIncident();
        $this->travel(31)->days();

        $this->actingAs($this->committee)->post("/incidents/{$incident->id}/effectiveness", ['effective' => true])
            ->assertSessionHasErrors('notes');
        $this->actingAs($this->committee)
            ->post("/incidents/{$incident->id}/effectiveness", ['effective' => true, 'notes' => 'No falls in 30 days.'])
            ->assertRedirect();

        $incident->refresh();
        $this->assertSame('effective', $incident->effectiveness_result);
        $this->assertSame(IncidentStatus::Verified, $incident->status);
        $this->assertTrue($this->head->can('requestApproval', $incident));
        $this->assertFalse($this->committee->can('checkEffectiveness', $incident));
    }

    public function test_not_effective_sends_it_back_to_corrective_action(): void
    {
        $incident = $this->verifiedIncident();
        $this->travel(31)->days();

        $this->actingAs($this->committee)
            ->post("/incidents/{$incident->id}/effectiveness", ['effective' => false, 'notes' => 'Two more falls.'])
            ->assertRedirect();

        $incident->refresh();
        $this->assertSame(IncidentStatus::CorrectiveAction, $incident->status);
        $this->assertTrue($this->head->can('create', [\App\Models\CorrectiveAction::class, $incident]));
    }

    public function test_only_the_cqi_committee_checks_effectiveness(): void
    {
        $incident = $this->verifiedIncident();
        $this->travel(31)->days();

        $this->assertTrue($this->committee->can('checkEffectiveness', $incident));
        foreach ([
            $this->head,
            User::factory()->create(['role' => Role::Supervisor, 'department_id' => $this->department->id]),
            User::factory()->create(['role' => Role::QualitySafetyOfficer]),
            User::factory()->create(['role' => Role::Management]),
        ] as $user) {
            $this->assertFalse($user->can('checkEffectiveness', $incident), $user->role->value);
        }
    }

    public function test_the_daily_run_tells_the_cqi_committee_once_when_the_check_is_due(): void
    {
        Notification::fake();
        User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $this->verifiedIncident();

        $this->artisan('incidents:check-overdue');
        Notification::assertNotSentTo($this->committee, EffectivenessCheckDueNotification::class);

        $this->travel(31)->days();
        $this->artisan('incidents:check-overdue');
        $this->artisan('incidents:check-overdue');
        Notification::assertSentToTimes($this->committee, EffectivenessCheckDueNotification::class, 1);
        Notification::assertNotSentTo($this->head, EffectivenessCheckDueNotification::class);
    }

    public function test_with_no_waiting_period_the_committee_is_told_at_once_and_only_once(): void
    {
        config(['incident_workflow.effectiveness_wait_days' => 0]);
        Notification::fake();
        User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $this->verifiedIncident();
        Notification::assertSentToTimes($this->committee, EffectivenessCheckDueNotification::class, 1);

        $this->artisan('incidents:check-overdue');
        Notification::assertSentToTimes($this->committee, EffectivenessCheckDueNotification::class, 1);
    }

    public function test_the_committee_sees_due_checks_in_its_own_queue_with_a_badge(): void
    {
        $incident = $this->verifiedIncident();

        // Still waiting: nothing to do yet.
        $this->actingAs($this->committee)->get('/incidents?scope=effectiveness-checks')
            ->assertInertia(fn ($page) => $page->where('incidents.total', 0)->missing('queueCounts.effectiveness-checks'));

        $this->travel(31)->days();
        $this->actingAs($this->committee)->get('/incidents?scope=effectiveness-checks')
            ->assertInertia(fn ($page) => $page
                ->where('incidents.data.0.id', $incident->id)
                ->where('queueCounts.effectiveness-checks', 1));

        $this->actingAs($this->committee)
            ->post("/incidents/{$incident->id}/effectiveness", ['effective' => true, 'notes' => 'No falls in 30 days.']);
        $this->actingAs($this->committee)->get('/incidents?scope=effectiveness-checks')
            ->assertInertia(fn ($page) => $page->where('incidents.total', 0));
    }

    public function test_an_effective_result_tells_the_department_head_and_fills_their_closure_queue(): void
    {
        Notification::fake();
        $incident = $this->verifiedIncident();
        $this->travel(31)->days();

        $this->actingAs($this->head)->get('/incidents?scope=ready-for-closure')
            ->assertInertia(fn ($page) => $page->where('incidents.total', 0)->missing('queueCounts.ready-for-closure'));

        $this->actingAs($this->committee)
            ->post("/incidents/{$incident->id}/effectiveness", ['effective' => true, 'notes' => 'No falls in 30 days.']);
        Notification::assertSentToTimes($this->head, ReadyForClosureNotification::class, 1);

        $this->actingAs($this->head)->get('/incidents?scope=ready-for-closure')
            ->assertInertia(fn ($page) => $page
                ->where('incidents.data.0.id', $incident->id)
                ->where('queueCounts.ready-for-closure', 1));

        $this->actingAs($this->head)
            ->post("/incidents/{$incident->id}/request-approval", ['lessons_learned' => 'Check bed rails each shift.'])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->head)->get('/incidents?scope=ready-for-closure')
            ->assertInertia(fn ($page) => $page->where('incidents.total', 0));
    }

    public function test_a_not_effective_result_does_not_tell_the_department_head(): void
    {
        Notification::fake();
        $incident = $this->verifiedIncident();
        $this->travel(31)->days();

        $this->actingAs($this->committee)
            ->post("/incidents/{$incident->id}/effectiveness", ['effective' => false, 'notes' => 'Two more falls.']);

        Notification::assertNotSentTo($this->head, ReadyForClosureNotification::class);
    }

    public function test_only_department_heads_can_open_the_closure_queue(): void
    {
        $this->actingAs($this->committee)->get('/incidents?scope=ready-for-closure')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => Role::Supervisor, 'department_id' => $this->department->id]))
            ->get('/incidents?scope=ready-for-closure')->assertForbidden();
    }

    public function test_only_the_cqi_committee_can_open_the_effectiveness_queue(): void
    {
        $this->actingAs($this->head)->get('/incidents?scope=effectiveness-checks')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => Role::QualitySafetyOfficer]))
            ->get('/incidents?scope=effectiveness-checks')->assertForbidden();
    }
}
