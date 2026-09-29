<?php

namespace Tests\Feature;

use App\DataTransferObjects\CorrectiveActions\CorrectiveActionData;
use App\DataTransferObjects\Investigations\CompleteInvestigationData;
use App\DataTransferObjects\Investigations\FindingData;
use App\DataTransferObjects\Investigations\StartInvestigationData;
use App\Enums\CorrectiveActionStatus;
use App\Enums\IncidentStatus;
use App\Enums\InvestigationMethodology;
use App\Enums\Role;
use App\Enums\Severity;
use App\Models\CorrectiveAction;
use App\Models\Department;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\User;
use App\Services\CorrectiveActionService;
use App\Services\IncidentService;
use App\Services\InvestigationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QueueCountsTest extends TestCase
{
    use RefreshDatabase;

    /** Same helper as IncidentQueueTest::incidentAtStatus(). */
    private function incidentAtStatus(IncidentStatus $status, array $overrides = []): Incident
    {
        $reporter = User::factory()->create();
        $departmentId = array_key_exists('department_id', $overrides)
            ? $overrides['department_id']
            : Department::factory()->create()->id;

        $incident = app(IncidentService::class)->createDraft($reporter, [
            'department_id' => $departmentId,
            'severity' => Severity::Level2Moderate->value,
            'occurred_at' => now(),
            'location' => 'ER',
            'summary' => 'Test incident.',
        ]);

        if ($status !== IncidentStatus::Draft) {
            app(IncidentService::class)->submit($incident);
        }

        $incident->forceFill(array_merge(['status' => $status, 'department_id' => $departmentId], $overrides))->save();

        return $incident->fresh();
    }

    /** Same lifecycle as CorrectiveActionQueueTest::incidentReadyForCapa(). */
    private function incidentReadyForCapa(): Incident
    {
        $department = Department::factory()->create();
        $incidentType = IncidentType::factory()->create();
        $reporter = User::factory()->create();
        $supervisor = User::factory()->create(['role' => Role::Supervisor, 'department_id' => $department->id]);
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

    /** Same helper as CorrectiveActionQueueTest::capaAtStatus(). */
    private function capaAtStatus(CorrectiveActionStatus $status, array $overrides = []): CorrectiveAction
    {
        $incident = $this->incidentReadyForCapa();

        $capa = app(CorrectiveActionService::class)->create($incident, CorrectiveActionData::fromArray([
            'description' => 'Implement double-check checklist.',
            'action_type' => 'corrective',
            'priority' => 'high',
            'due_date' => now()->addDays(14)->toDateString(),
        ]));

        $capa->forceFill(array_merge(['status' => $status], $overrides))->save();

        return $capa->fresh();
    }

    private function assertQueuePageTotalMatches(User $user, string $url, string $prop, int $expected): void
    {
        $this->actingAs($user)->get($url)
            ->assertInertia(fn ($page) => $page->where("{$prop}.total", $expected));
    }

    public function test_queue_counts_match_each_queue_pages_total_for_a_qso(): void
    {
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);

        $this->incidentAtStatus(IncidentStatus::Submitted);
        $this->incidentAtStatus(IncidentStatus::Submitted);
        $this->incidentAtStatus(IncidentStatus::ForReview);
        // Completing the investigation (inside capaAtStatus's lifecycle) leaves the
        // underlying incident at CorrectiveAction status, so this also counts toward
        // the incident-level 'corrective-actions' queue, on top of the CAPA-level
        // 'open'/'overdue' queues for the CAPA record itself.
        $this->capaAtStatus(CorrectiveActionStatus::Open, ['due_date' => now()->subDay()->toDateString()]);

        $response = $this->actingAs($qso)->get('/');

        $response->assertInertia(fn ($page) => $page->where('queueCounts', [
            'awaiting-assessment' => 2,
            'pending-review' => 1,
            'corrective-actions' => 1,
            'open' => 1,
            'overdue' => 1,
        ]));

        $this->assertQueuePageTotalMatches($qso, '/incidents?scope=awaiting-assessment', 'incidents', 2);
        $this->assertQueuePageTotalMatches($qso, '/incidents?scope=pending-review', 'incidents', 1);
        $this->assertQueuePageTotalMatches($qso, '/incidents?scope=corrective-actions', 'incidents', 1);
        $this->assertQueuePageTotalMatches($qso, '/corrective-actions?queue=open', 'actions', 1);
        $this->assertQueuePageTotalMatches($qso, '/corrective-actions?queue=overdue', 'actions', 1);
    }

    public function test_zero_counts_and_non_badge_queues_are_omitted(): void
    {
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);

        // Only non-badge queues (resolved) have data, and no CAPA exists at all.
        $this->incidentAtStatus(IncidentStatus::Closed);
        $this->incidentAtStatus(IncidentStatus::Verified);
        $this->incidentAtStatus(IncidentStatus::ForApproval);

        $this->actingAs($qso)->get('/')
            ->assertInertia(fn ($page) => $page->where('queueCounts', []));
    }

    public function test_staff_user_never_gets_keys_for_queues_they_cannot_open(): void
    {
        $department = Department::factory()->create();
        $staff = User::factory()->create(['role' => Role::Staff, 'department_id' => $department->id]);

        // Staff can see awaiting-assessment for their own department.
        $this->incidentAtStatus(IncidentStatus::Submitted, ['department_id' => $department->id]);
        // Staff cannot open pending-review, even though it has data.
        $this->incidentAtStatus(IncidentStatus::ForReview, ['department_id' => $department->id]);
        // Staff is not responsible for any CAPA, so capaOperations is false and no CAPA keys appear.
        $this->capaAtStatus(CorrectiveActionStatus::Open, ['due_date' => now()->subDay()->toDateString()]);

        $this->actingAs($staff)->get('/')
            ->assertInertia(fn ($page) => $page->where('queueCounts', ['awaiting-assessment' => 1]));
    }
}
