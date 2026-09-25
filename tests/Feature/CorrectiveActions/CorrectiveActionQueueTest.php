<?php

namespace Tests\Feature\CorrectiveActions;

use App\DataTransferObjects\CorrectiveActions\CorrectiveActionData;
use App\DataTransferObjects\Investigations\CompleteInvestigationData;
use App\DataTransferObjects\Investigations\FindingData;
use App\DataTransferObjects\Investigations\StartInvestigationData;
use App\Enums\CorrectiveActionStatus;
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
use Illuminate\Support\Collection;
use Tests\TestCase;

class CorrectiveActionQueueTest extends TestCase
{
    use RefreshDatabase;

    /** Same lifecycle as CorrectiveActionTest::incidentReadyForCapa(). */
    private function incidentReadyForCapa(): Incident
    {
        $department = Department::factory()->create();
        $incidentType = IncidentType::factory()->create();
        $reporter = User::factory()->create();
        $supervisor = User::factory()->create(['role' => Role::Supervisor, 'department_id' => $department->id]);
        $investigator = User::factory()->create(['role' => Role::Investigator]);

        $incident = app(IncidentService::class)->createDraft($reporter, [
            'department_id' => $department->id,
            'incident_type_id' => $incidentType->id,
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

    /** Creates a CAPA on a fresh incident-ready-for-capa, then lands it at the given status/overrides. */
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

    private function assertQueueLists(string $queue, User $user, array $expectedIds): void
    {
        $response = $this->actingAs($user)->get("/corrective-actions?queue={$queue}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('CorrectiveActions/Index')
            ->where('queue.key', $queue)
            ->where('actions.data', fn (Collection $data) => $data->pluck('id')->sort()->values()->all()
                === collect($expectedIds)->sort()->values()->all()));
    }

    public static function statusQueues(): array
    {
        return [
            'open' => ['open', [CorrectiveActionStatus::Open, CorrectiveActionStatus::InProgress], CorrectiveActionStatus::ForVerification],
            'for-verification' => ['for-verification', [CorrectiveActionStatus::ForVerification], CorrectiveActionStatus::Open],
            'completed' => ['completed', [CorrectiveActionStatus::Verified], CorrectiveActionStatus::Open],
        ];
    }

    /** @dataProvider statusQueues */
    public function test_queue_lists_exactly_the_capas_in_its_statuses(string $queue, array $included, CorrectiveActionStatus $excluded): void
    {
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);

        $expectedIds = collect($included)->map(fn (CorrectiveActionStatus $status) => $this->capaAtStatus($status)->id)->all();
        $this->capaAtStatus($excluded);

        $this->assertQueueLists($queue, $qso, $expectedIds);
    }

    public function test_overdue_queue_lists_only_unverified_capas_past_their_due_date(): void
    {
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);

        $overdue = $this->capaAtStatus(CorrectiveActionStatus::Open, ['due_date' => now()->subDay()->toDateString()]);
        $this->capaAtStatus(CorrectiveActionStatus::Verified, ['due_date' => now()->subDay()->toDateString()]);
        $this->capaAtStatus(CorrectiveActionStatus::Open, ['due_date' => now()->addDay()->toDateString()]);

        $this->assertQueueLists('overdue', $qso, [$overdue->id]);
    }

    public function test_staff_responsible_for_a_capa_sees_it_in_open_queue_and_gets_the_capa_operations_flag(): void
    {
        $staff = User::factory()->create(['role' => Role::Staff]);
        $capa = $this->capaAtStatus(CorrectiveActionStatus::Open, ['responsible_user_id' => $staff->id]);

        $this->assertQueueLists('open', $staff, [$capa->id]);

        $this->actingAs($staff)->get('/')
            ->assertInertia(fn ($page) => $page->where('auth.can.capaOperations', true));
    }

    public function test_unrelated_staff_is_forbidden_and_has_no_capa_operations_flag(): void
    {
        $staff = User::factory()->create(['role' => Role::Staff]);
        $this->capaAtStatus(CorrectiveActionStatus::Open);

        $this->actingAs($staff)->get('/corrective-actions?queue=open')->assertForbidden();

        $this->actingAs($staff)->get('/')
            ->assertInertia(fn ($page) => $page->where('auth.can.capaOperations', false));
    }

    public function test_unknown_queue_returns_404(): void
    {
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);

        $this->actingAs($qso)->get('/corrective-actions?queue=bogus')->assertNotFound();
    }

    public function test_row_shape_includes_the_fields_the_table_needs(): void
    {
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $capa = $this->capaAtStatus(CorrectiveActionStatus::Open, ['due_date' => now()->subDay()->toDateString()]);

        $this->actingAs($qso)->get('/corrective-actions?queue=open')
            ->assertInertia(fn ($page) => $page
                ->where('actions.data.0.id', $capa->id)
                ->where('actions.data.0.capa_number', $capa->capa_number)
                ->where('actions.data.0.description', $capa->description)
                ->where('actions.data.0.incident.id', $capa->incident->id)
                ->where('actions.data.0.incident.incident_number', $capa->incident->incident_number)
                ->where('actions.data.0.responsible', $capa->responsibleUser?->name)
                ->where('actions.data.0.priority', $capa->priority->label())
                ->where('actions.data.0.due_date', $capa->due_date->toDateString())
                ->where('actions.data.0.is_overdue', true)
                ->where('actions.data.0.status.value', $capa->status->value)
                ->where('actions.data.0.status.label', $capa->status->label()));
    }

    public function test_page_passes_the_queue_title_and_description(): void
    {
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $this->capaAtStatus(CorrectiveActionStatus::ForVerification);

        $this->actingAs($qso)->get('/corrective-actions?queue=for-verification')
            ->assertInertia(fn ($page) => $page
                ->where('queue.title', 'For Verification')
                ->where('queue.description', 'Completed actions waiting to be verified.'));
    }
}
