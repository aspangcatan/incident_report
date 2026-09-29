<?php

namespace Tests\Feature\Approvals;

use App\DataTransferObjects\Investigations\CompleteInvestigationData;
use App\DataTransferObjects\Investigations\FindingData;
use App\DataTransferObjects\Investigations\StartInvestigationData;
use App\Enums\InvestigationMethodology;
use App\Enums\Role;
use App\Enums\Severity;
use App\Models\Department;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\User;
use App\Notifications\IncidentEscalationNotification;
use App\Services\ApprovalService;
use App\Services\IncidentService;
use App\Services\InvestigationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ApprovalEscalationTest extends TestCase
{
    use RefreshDatabase;

    private function incidentThroughInvestigation(): Incident
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
            'objective' => 'x', 'methodology' => InvestigationMethodology::FiveWhys->value,
        ]));
        app(InvestigationService::class)->addFinding($investigation, FindingData::fromArray(['question' => 'Q', 'finding' => 'F', 'is_root_cause' => true]));
        app(InvestigationService::class)->complete($investigation->fresh(), CompleteInvestigationData::fromArray(['conclusion' => 'Done.']));

        return $incident->fresh();
    }

    public function test_an_overdue_pending_approval_is_escalated_once(): void
    {
        Notification::fake();
        $incident = $this->incidentThroughInvestigation();
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $approval = app(ApprovalService::class)->markNoCorrectiveActionNeeded(
            $incident,
            $qso,
            \App\DataTransferObjects\Approvals\MarkNoCorrectiveActionNeededData::fromArray(['justification' => 'Near miss.'])
        );
        $approval->forceFill(['due_at' => now()->subDay()])->save();

        Artisan::call('incidents:check-overdue');

        Notification::assertSentTo($qso, IncidentEscalationNotification::class);
        $this->assertNotNull($approval->fresh()->escalated_at);

        Notification::fake();
        Artisan::call('incidents:check-overdue');
        Notification::assertNothingSent();
    }

    public function test_requesting_approval_computes_due_at_from_the_configured_severity_sla(): void
    {
        $incident = $this->incidentThroughInvestigation();
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);

        $before = now();
        $approval = app(ApprovalService::class)->markNoCorrectiveActionNeeded(
            $incident,
            $qso,
            \App\DataTransferObjects\Approvals\MarkNoCorrectiveActionNeededData::fromArray(['justification' => 'x'])
        );

        // Severity::Level2Moderate => 72 hours per config/incident_workflow.php.
        $this->assertTrue($approval->due_at->diffInHours($before->copy()->addHours(72)) < 1);
    }
}
