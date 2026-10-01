<?php

namespace Tests\Feature;

use App\Enums\CorrectiveActionPriority;
use App\Enums\Role;
use App\Enums\Severity;
use App\Models\CorrectiveAction;
use App\Models\Department;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\Investigation;
use App\Models\User;
use App\Services\IncidentService;
use App\Support\EscalationRecipients;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EscalationRecipientsTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;
    private User $head;
    private User $cqi;
    private User $leader;
    private User $executive;
    private User $committee;
    private User $otherHead;
    private User $otherLeader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->department = Department::factory()->create();
        $other = Department::factory()->create();
        $this->head = User::factory()->create(['role' => Role::DepartmentHead, 'department_id' => $this->department->id]);
        $this->cqi = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $this->leader = User::factory()->create(['role' => Role::Leadership]);
        DB::table('leadership_departments')->insert(['user_id' => $this->leader->id, 'department_id' => $this->department->id]);
        $this->executive = User::factory()->create(['role' => Role::Management]);
        $this->committee = User::factory()->create(['role' => Role::CqiCommittee]);
        $this->otherHead = User::factory()->create(['role' => Role::DepartmentHead, 'department_id' => $other->id]);
        $this->otherLeader = User::factory()->create(['role' => Role::Leadership]);
    }

    private function incident(?Severity $severity): Incident
    {
        $incident = app(IncidentService::class)->createDraft(User::factory()->create(), [
            'department_id' => $this->department->id,
            'incident_type_ids' => [IncidentType::factory()->create()->id],
            'occurred_at' => now(),
            'location' => 'Ward 3',
            'summary' => 'Matrix test.',
        ]);
        $incident->forceFill(['severity' => $severity])->save();

        return $incident->fresh();
    }

    private function ids($users): array
    {
        return $users->pluck('id')->sort()->values()->all();
    }

    private function expect(array $users): array
    {
        return collect($users)->pluck('id')->sort()->values()->all();
    }

    public function test_low_alerts_nobody(): void
    {
        $this->assertSame([], $this->ids(EscalationRecipients::forSeverity($this->incident(Severity::Level1Low))));
        $this->assertSame([], $this->ids(EscalationRecipients::forSeverity($this->incident(null))));
    }

    public function test_moderate_alerts_the_department_head_and_cqi(): void
    {
        $this->assertSame(
            $this->expect([$this->head, $this->cqi]),
            $this->ids(EscalationRecipients::forSeverity($this->incident(Severity::Level2Moderate)))
        );
    }

    public function test_high_adds_the_mapped_leadership(): void
    {
        $this->assertSame(
            $this->expect([$this->head, $this->cqi, $this->leader]),
            $this->ids(EscalationRecipients::forSeverity($this->incident(Severity::Level3High)))
        );
    }

    public function test_critical_and_sentinel_alert_cqi_leadership_and_executives(): void
    {
        foreach ([Severity::Level4Critical, Severity::Level5Sentinel] as $severity) {
            $this->assertSame(
                $this->expect([$this->cqi, $this->leader, $this->executive]),
                $this->ids(EscalationRecipients::forSeverity($this->incident($severity))),
                $severity->value
            );
        }
    }

    public function test_overdue_investigation_goes_to_the_lead_the_head_and_cqi(): void
    {
        $lead = User::factory()->create(['department_id' => $this->department->id]);
        $investigation = new Investigation();
        $investigation->setRelation('incident', $this->incident(Severity::Level2Moderate));
        $investigation->setRelation('leadInvestigator', $lead);

        $this->assertSame(
            $this->expect([$lead, $this->head, $this->cqi]),
            $this->ids(EscalationRecipients::forOverdueInvestigation($investigation))
        );
    }

    public function test_overdue_capa_goes_to_the_owner_the_head_and_cqi_and_critical_adds_executives(): void
    {
        $owner = User::factory()->create(['department_id' => $this->department->id]);
        $capa = new CorrectiveAction(['priority' => CorrectiveActionPriority::High]);
        $capa->setRelation('incident', $this->incident(Severity::Level2Moderate));
        $capa->setRelation('responsibleUser', $owner);

        $this->assertSame($this->expect([$owner, $this->head, $this->cqi]), $this->ids(EscalationRecipients::forOverdueCorrectiveAction($capa)));

        $capa->priority = CorrectiveActionPriority::Critical;
        $this->assertSame($this->expect([$owner, $this->head, $this->cqi, $this->executive]), $this->ids(EscalationRecipients::forOverdueCorrectiveAction($capa)));
    }

    public function test_a_missing_owner_is_skipped_and_people_are_not_listed_twice(): void
    {
        $capa = new CorrectiveAction(['priority' => CorrectiveActionPriority::Medium]);
        $capa->setRelation('incident', $this->incident(Severity::Level2Moderate));
        $capa->setRelation('responsibleUser', $this->head); // the head owns the action

        $this->assertSame($this->expect([$this->head, $this->cqi]), $this->ids(EscalationRecipients::forOverdueCorrectiveAction($capa)));

        $capa->setRelation('responsibleUser', null); // hard-deleted tdh user
        $this->assertSame($this->expect([$this->head, $this->cqi]), $this->ids(EscalationRecipients::forOverdueCorrectiveAction($capa)));
    }
}
