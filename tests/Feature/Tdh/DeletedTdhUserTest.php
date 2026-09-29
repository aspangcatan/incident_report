<?php

namespace Tests\Feature\Tdh;

use App\DataTransferObjects\Investigations\StartInvestigationData;
use App\Enums\Role;
use App\Enums\Severity;
use App\Models\Department;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\User;
use App\Services\IncidentService;
use App\Services\InvestigationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * tdh_user hard-deletes users and there are no FKs from incident_report to
 * it, so any stored user id may point at a row that no longer exists.
 */
class DeletedTdhUserTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    private User $reporter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->department = Department::factory()->create();
        $this->reporter = User::factory()->create(['department_id' => $this->department->id]);
    }

    private function submittedIncident(): Incident
    {
        $incident = app(IncidentService::class)->createDraft($this->reporter, [
            'department_id' => $this->department->id,
            'incident_type_ids' => [IncidentType::factory()->create()->id],
            'severity' => Severity::Level2Moderate->value,
            'occurred_at' => now(),
            'location' => 'Ward 3',
            'summary' => 'Test incident.',
        ]);
        app(IncidentService::class)->submit($incident);

        return $incident->fresh();
    }

    public function test_incident_show_renders_when_the_lead_investigator_was_deleted_from_tdh(): void
    {
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $supervisor = User::factory()->create(['role' => Role::Supervisor, 'department_id' => $this->department->id]);
        $investigator = User::factory()->create(['role' => Role::Investigator]);

        $incident = $this->submittedIncident();
        app(IncidentService::class)->markReviewed($incident, $supervisor, null);
        app(IncidentService::class)->assignInvestigator($incident->fresh(), $investigator);
        app(InvestigationService::class)->start($incident->fresh(), $investigator, StartInvestigationData::fromArray([
            'objective' => 'x',
            'methodology' => 'five_whys',
            'target_completion_at' => now()->addWeek()->toDateTimeString(),
        ]));

        $investigator->delete();

        $this->actingAs($qso)
            ->get("/incidents/{$incident->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('investigation.lead_investigator', null)
                ->where('investigation.team_members.0.user', null));
    }

    public function test_returning_an_incident_whose_reporter_was_deleted_does_not_fail(): void
    {
        $supervisor = User::factory()->create(['role' => Role::Supervisor, 'department_id' => $this->department->id]);
        $incident = $this->submittedIncident();

        $this->reporter->delete();

        app(IncidentService::class)->returnForRevision($incident->fresh(), $supervisor, 'Please add detail.');

        $this->assertSame('draft', $incident->fresh()->status->value);
    }
}
