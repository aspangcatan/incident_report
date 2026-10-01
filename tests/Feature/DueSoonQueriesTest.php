<?php

namespace Tests\Feature;

use App\DataTransferObjects\CorrectiveActions\CorrectiveActionData;
use App\DataTransferObjects\Investigations\CompleteInvestigationData;
use App\DataTransferObjects\Investigations\FindingData;
use App\DataTransferObjects\Investigations\StartInvestigationData;
use App\Enums\CorrectiveActionStatus;
use App\Enums\InvestigationMethodology;
use App\Enums\Role;
use App\Enums\Severity;
use App\Models\Department;
use App\Models\IncidentType;
use App\Models\Investigation;
use App\Models\User;
use App\Queries\DueSoonCorrectiveActionsQuery;
use App\Queries\DueSoonInvestigationsQuery;
use App\Repositories\CorrectiveActionRepository;
use App\Repositories\InvestigationRepository;
use App\Services\CorrectiveActionService;
use App\Services\IncidentService;
use App\Services\InvestigationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DueSoonQueriesTest extends TestCase
{
    use RefreshDatabase;

    private function startedInvestigation(Carbon $target): Investigation
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

        return app(InvestigationService::class)->start($incident->fresh(), $investigator, StartInvestigationData::fromArray([
            'objective' => 'x', 'methodology' => InvestigationMethodology::FiveWhys->value, 'target_completion_at' => $target->toDateTimeString(),
        ]));
    }

    private function incidentReadyForCapa(): \App\Models\Incident
    {
        $investigation = $this->startedInvestigation(now()->addDays(7));
        app(InvestigationService::class)->addFinding($investigation, FindingData::fromArray(['question' => 'Q', 'finding' => 'F', 'is_root_cause' => true]));
        app(InvestigationService::class)->complete($investigation->fresh(), CompleteInvestigationData::fromArray(['conclusion' => 'Done.']));

        return $investigation->incident->fresh();
    }

    public function test_an_investigation_due_within_a_day_is_due_soon_until_reminded(): void
    {
        $soon = $this->startedInvestigation(now()->addHours(12));
        $later = $this->startedInvestigation(now()->addDays(3));
        $overdue = $this->startedInvestigation(now()->subHour());

        $ids = app(DueSoonInvestigationsQuery::class)->get()->pluck('id')->all();
        $this->assertSame([$soon->id], $ids);

        app(InvestigationRepository::class)->markReminded($soon);
        $this->assertSame([], app(DueSoonInvestigationsQuery::class)->get()->pluck('id')->all());
    }

    public function test_a_capa_due_tomorrow_and_not_done_is_due_soon_until_reminded(): void
    {
        $incident = $this->incidentReadyForCapa();
        $make = fn (string $due) => app(CorrectiveActionService::class)->create($incident, CorrectiveActionData::fromArray([
            'description' => 'x', 'action_type' => 'corrective', 'priority' => 'high', 'due_date' => $due,
        ]));
        $tomorrow = $make(now()->addDay()->toDateString());
        $make(now()->addDays(2)->toDateString());
        $done = $make(now()->addDay()->toDateString());
        $done->forceFill(['status' => CorrectiveActionStatus::ForVerification])->save();

        $this->assertSame([$tomorrow->id], app(DueSoonCorrectiveActionsQuery::class)->get()->pluck('id')->all());

        app(CorrectiveActionRepository::class)->markReminded($tomorrow);
        $this->assertSame([], app(DueSoonCorrectiveActionsQuery::class)->get()->pluck('id')->all());
    }
}
