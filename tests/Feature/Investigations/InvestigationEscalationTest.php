<?php

namespace Tests\Feature\Investigations;

use App\DataTransferObjects\Investigations\StartInvestigationData;
use App\Enums\Role;
use App\Enums\Severity;
use App\Models\Department;
use App\Models\IncidentType;
use App\Models\User;
use App\Notifications\IncidentEscalationNotification;
use App\Services\IncidentService;
use App\Services\InvestigationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class InvestigationEscalationTest extends TestCase
{
    use RefreshDatabase;

    private function assignedIncident(User $investigator): \App\Models\Incident
    {
        $department = Department::factory()->create();
        $incidentType = IncidentType::factory()->create();
        $reporter = User::factory()->create();
        $supervisor = User::factory()->create(['role' => Role::Supervisor, 'department_id' => $department->id]);

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

        return $incident->fresh();
    }

    public function test_an_overdue_in_progress_investigation_is_escalated_once(): void
    {
        Notification::fake();
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $incident = $this->assignedIncident($investigator);
        $investigation = app(InvestigationService::class)->start($incident, $investigator, StartInvestigationData::fromArray([
            'objective' => 'x',
            'methodology' => 'five_whys',
            'target_completion_at' => now()->subDay()->toDateTimeString(),
        ]));

        Artisan::call('incidents:check-overdue');

        Notification::assertSentTo($qso, IncidentEscalationNotification::class);
        $this->assertNotNull($investigation->fresh()->escalated_at);

        Notification::fake();
        Artisan::call('incidents:check-overdue');
        Notification::assertNothingSent();
    }
}
