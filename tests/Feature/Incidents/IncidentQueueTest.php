<?php

namespace Tests\Feature\Incidents;

use App\DataTransferObjects\Investigations\CompleteInvestigationData;
use App\DataTransferObjects\Investigations\FindingData;
use App\DataTransferObjects\Investigations\StartInvestigationData;
use App\Enums\IncidentStatus;
use App\Enums\InvestigationMethodology;
use App\Enums\Role;
use App\Enums\Severity;
use App\Models\Department;
use App\Models\Incident;
use App\Models\User;
use App\Services\IncidentService;
use App\Services\InvestigationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class IncidentQueueTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Builds an incident and lands it directly on the given status.
     * Submitted/later statuses go through submit() first so incident_number
     * etc. are populated the way real incidents are, then forceFill jumps
     * straight to the target status (existing tests use this for statuses
     * unreachable through a single service call).
     */
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

    /** Same lifecycle as CorrectiveActionTest::incidentReadyForCapa(), ending with a completed investigation. */
    private function incidentWithCompletedInvestigation(): Incident
    {
        $department = Department::factory()->create();
        $reporter = User::factory()->create();
        $supervisor = User::factory()->create(['role' => Role::Supervisor, 'department_id' => $department->id]);
        $investigator = User::factory()->create(['role' => Role::Investigator]);

        $incident = app(IncidentService::class)->createDraft($reporter, [
            'department_id' => $department->id,
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

    private function assertQueueLists(string $scope, User $user, array $expectedIds): void
    {
        $response = $this->actingAs($user)->get("/incidents?scope={$scope}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Incidents/Index')
            ->where('scope', $scope)
            ->where('incidents.data', fn (Collection $data) => $data->pluck('id')->sort()->values()->all()
                === collect($expectedIds)->sort()->values()->all()));
    }

    public static function statusQueues(): array
    {
        return [
            'awaiting-assessment' => ['awaiting-assessment', [IncidentStatus::Submitted], IncidentStatus::ForReview],
            'pending-review' => ['pending-review', [IncidentStatus::ForReview], IncidentStatus::Submitted],
            'under-investigation' => ['under-investigation', [IncidentStatus::UnderInvestigation], IncidentStatus::Submitted],
            'corrective-actions' => ['corrective-actions', [IncidentStatus::CorrectiveAction, IncidentStatus::ForVerification], IncidentStatus::Submitted],
            'resolved' => ['resolved', [IncidentStatus::Verified, IncidentStatus::ForApproval, IncidentStatus::Closed], IncidentStatus::Submitted],
            'investigation-queue' => ['investigation-queue', [IncidentStatus::Reviewed, IncidentStatus::Assigned], IncidentStatus::Submitted],
        ];
    }

    /** @dataProvider statusQueues */
    public function test_queue_lists_exactly_the_incidents_in_its_statuses(string $scope, array $included, IncidentStatus $excluded): void
    {
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);

        $expectedIds = collect($included)->map(fn (IncidentStatus $status) => $this->incidentAtStatus($status)->id)->all();
        $this->incidentAtStatus($excluded);
        $this->incidentAtStatus(IncidentStatus::Draft);

        $this->assertQueueLists($scope, $qso, $expectedIds);
    }

    public function test_investigation_history_lists_incidents_with_a_completed_investigation(): void
    {
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $completed = $this->incidentWithCompletedInvestigation();
        $this->incidentAtStatus(IncidentStatus::UnderInvestigation);

        $this->assertQueueLists('investigation-history', $qso, [$completed->id]);
    }

    public function test_assigned_to_me_lists_only_incidents_assigned_to_the_current_investigator(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $other = User::factory()->create(['role' => Role::Investigator]);

        $mine = $this->incidentAtStatus(IncidentStatus::Assigned, ['assigned_investigator_id' => $investigator->id]);
        $this->incidentAtStatus(IncidentStatus::UnderInvestigation, ['assigned_investigator_id' => $other->id]);
        $this->incidentAtStatus(IncidentStatus::Assigned);
        $this->incidentAtStatus(IncidentStatus::CorrectiveAction, ['assigned_investigator_id' => $investigator->id]);

        $this->assertQueueLists('assigned-to-me', $investigator, [$mine->id]);
    }

    public function test_staff_cannot_open_the_pending_review_queue(): void
    {
        $staff = User::factory()->create(['role' => Role::Staff]);

        $this->actingAs($staff)->get('/incidents?scope=pending-review')->assertForbidden();
    }

    public function test_staff_sees_only_their_departments_submitted_incidents_in_awaiting_assessment(): void
    {
        $departmentA = Department::factory()->create();
        $departmentB = Department::factory()->create();
        $staff = User::factory()->create(['role' => Role::Staff, 'department_id' => $departmentA->id]);

        $mine = $this->incidentAtStatus(IncidentStatus::Submitted, ['department_id' => $departmentA->id]);
        $this->incidentAtStatus(IncidentStatus::Submitted, ['department_id' => $departmentB->id]);

        $this->assertQueueLists('awaiting-assessment', $staff, [$mine->id]);
    }

    public function test_supervisor_without_a_department_sees_nothing_in_awaiting_assessment(): void
    {
        $supervisor = User::factory()->create(['role' => Role::Supervisor, 'department_id' => null]);
        $this->incidentAtStatus(IncidentStatus::Submitted, ['department_id' => null]);

        $this->assertQueueLists('awaiting-assessment', $supervisor, []);
    }

    public function test_page_passes_the_queue_title_and_description(): void
    {
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $this->incidentAtStatus(IncidentStatus::ForReview);

        $this->actingAs($qso)->get('/incidents?scope=pending-review')
            ->assertInertia(fn ($page) => $page
                ->where('queue.title', 'Pending Review')
                ->where('queue.description', 'Assessed incidents waiting for review.'));
    }
}
