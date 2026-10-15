<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Enums\Severity;
use App\Models\Department;
use App\Models\EscalationRun;
use App\Models\Incident;
use App\Models\User;
use App\Notifications\IncidentEscalationNotification;
use App\Services\IncidentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class EscalationEngineTest extends TestCase
{
    use RefreshDatabase;

    /** A report submitted five days ago and never assessed: past the 72-hour assessment limit. */
    private function unassessedIncident(): Incident
    {
        $service = app(IncidentService::class);
        $incident = $service->createDraft(User::factory()->create(), [
            'department_id' => Department::factory()->create()->id,
            'severity' => Severity::Level2Moderate->value,
            'occurred_at' => now()->subDays(5),
            'location' => 'Ward 3',
            'summary' => 'Test incident.',
        ]);
        $service->submit($incident);
        $incident->forceFill(['reported_at' => now()->subDays(5)])->save();

        return $incident->fresh();
    }

    public function test_the_it_admin_and_cqi_office_can_open_it_and_others_cannot(): void
    {
        foreach ([Role::Administrator, Role::QualitySafetyOfficer] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->get('/admin/escalation-engine')->assertOk();
        }
        foreach ([Role::Staff, Role::CqiCommittee, Role::Management, Role::Supervisor] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->get('/admin/escalation-engine')->assertForbidden();
            $this->actingAs($user)->post('/admin/escalation-engine/run')->assertForbidden();
        }
    }

    public function test_it_lists_overdue_work_and_flags_a_scheduler_that_never_ran(): void
    {
        $incident = $this->unassessedIncident();

        $this->actingAs(User::factory()->create(['role' => Role::QualitySafetyOfficer]))
            ->get('/admin/escalation-engine')
            ->assertInertia(fn ($page) => $page
                ->component('Admin/EscalationEngine')
                ->where('schedulerStale', true)
                ->where('overdue.0.incident_id', $incident->id)
                ->where('overdue.0.stage', 'Department assessment')
                ->where('overdue.0.escalated_at', null)
                ->has('rules', 9)
                ->has('runs', 0));
    }

    public function test_run_now_escalates_once_and_records_who_ran_it(): void
    {
        Notification::fake();
        $cqi = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $incident = $this->unassessedIncident();

        $this->actingAs($cqi)->post('/admin/escalation-engine/run')->assertSessionHas('success');
        $this->actingAs($cqi)->post('/admin/escalation-engine/run');

        Notification::assertSentToTimes($cqi, IncidentEscalationNotification::class, 1);
        $this->assertNotNull($incident->fresh()->assessment_escalated_at);

        $runs = EscalationRun::orderBy('id')->get();
        $this->assertCount(2, $runs);
        $this->assertSame($cqi->id, $runs[0]->triggered_by);
        $this->assertSame(1, $runs[0]->counts['assessment']);
        $this->assertSame(0, $runs[1]->total());

        $this->actingAs($cqi)->get('/admin/escalation-engine')
            ->assertInertia(fn ($page) => $page
                ->where('schedulerStale', false)
                ->where('runs.0.triggered_by', $cqi->name)
                ->whereNot('overdue.0.escalated_at', null));
    }

    public function test_the_daily_schedule_records_its_run_without_a_person(): void
    {
        $this->artisan('incidents:check-overdue');

        $run = EscalationRun::sole();
        $this->assertNull($run->triggered_by);
        $this->assertNotNull($run->finished_at);
    }

    public function test_a_run_older_than_a_day_counts_as_a_stopped_scheduler(): void
    {
        EscalationRun::create(['started_at' => now()->subHours(30), 'finished_at' => now()->subHours(30), 'counts' => []]);

        $this->actingAs(User::factory()->create(['role' => Role::Administrator]))->get('/admin/escalation-engine')
            ->assertInertia(fn ($page) => $page->where('schedulerStale', true));
    }
}
