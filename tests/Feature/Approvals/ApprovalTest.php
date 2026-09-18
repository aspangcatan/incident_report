<?php

namespace Tests\Feature\Approvals;

use App\DataTransferObjects\Approvals\DecideApprovalData;
use App\DataTransferObjects\Approvals\MarkNoCorrectiveActionNeededData;
use App\DataTransferObjects\CorrectiveActions\CompleteCorrectiveActionData;
use App\DataTransferObjects\CorrectiveActions\CorrectiveActionData;
use App\DataTransferObjects\CorrectiveActions\VerifyCorrectiveActionData;
use App\DataTransferObjects\Investigations\CompleteInvestigationData;
use App\DataTransferObjects\Investigations\FindingData;
use App\DataTransferObjects\Investigations\StartInvestigationData;
use App\Enums\ApprovalStatus;
use App\Enums\IncidentStatus;
use App\Enums\InvestigationMethodology;
use App\Enums\Role;
use App\Enums\Severity;
use App\Models\Department;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\CorrectiveActionService;
use App\Services\IncidentService;
use App\Services\InvestigationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApprovalTest extends TestCase
{
    use RefreshDatabase;

    /**
     * An incident with a completed investigation, sitting at
     * IncidentStatus::CorrectiveAction with zero corrective actions.
     */
    private function incidentThroughInvestigation(): Incident
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

    /** An incident with one verified CAPA, sitting at IncidentStatus::Verified. */
    private function incidentReadyForApproval(): Incident
    {
        $incident = $this->incidentThroughInvestigation();

        $capa = app(CorrectiveActionService::class)->create($incident, CorrectiveActionData::fromArray([
            'description' => 'Retrain staff.', 'action_type' => 'corrective', 'priority' => 'high',
            'due_date' => now()->addDays(14)->toDateString(),
        ]));
        $this->actingAs(User::factory()->create());
        app(CorrectiveActionService::class)->complete($capa, CompleteCorrectiveActionData::fromArray(['completion_notes' => 'Done.']));
        $verifier = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        app(CorrectiveActionService::class)->verify($capa->fresh(), $verifier, VerifyCorrectiveActionData::fromArray(['verification_comments' => 'Confirmed.']));

        return $incident->fresh();
    }

    public function test_requesting_approval_creates_a_pending_approval_and_advances_the_incident(): void
    {
        $incident = $this->incidentReadyForApproval();
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);

        $approval = app(ApprovalService::class)->requestApproval($incident, $qso);

        $this->assertSame(ApprovalStatus::Pending, $approval->status);
        $this->assertSame($qso->id, $approval->requested_by);
        $this->assertNull($approval->request_comments);
        $this->assertNotNull($approval->due_at);
        $this->assertSame(IncidentStatus::ForApproval, $incident->fresh()->status);
    }

    public function test_marking_no_corrective_action_needed_creates_a_pending_approval_with_the_justification(): void
    {
        $incident = $this->incidentThroughInvestigation();
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);

        $approval = app(ApprovalService::class)->markNoCorrectiveActionNeeded(
            $incident,
            $qso,
            MarkNoCorrectiveActionNeededData::fromArray(['justification' => 'Near-miss only; no system change required.'])
        );

        $this->assertSame(ApprovalStatus::Pending, $approval->status);
        $this->assertSame('Near-miss only; no system change required.', $approval->request_comments);
        $this->assertSame(IncidentStatus::ForApproval, $incident->fresh()->status);
    }

    public function test_requesting_approval_writes_an_audit_log_entry_describing_why(): void
    {
        $incident = $this->incidentReadyForApproval();
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);

        app(ApprovalService::class)->requestApproval($incident, $qso);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Incident::class,
            'auditable_id' => $incident->id,
            'action' => 'status_changed',
            'description' => 'Corrective action(s) verified; requesting closure approval.',
        ]);
    }

    public function test_approving_closes_the_incident(): void
    {
        $incident = $this->incidentReadyForApproval();
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $approval = app(ApprovalService::class)->requestApproval($incident, $qso);
        $approver = User::factory()->create(['role' => Role::Management]);

        $decided = app(ApprovalService::class)->approve($approval, $approver, DecideApprovalData::fromArray(['comments' => 'All good.']));

        $this->assertSame(ApprovalStatus::Approved, $decided->status);
        $this->assertSame($approver->id, $decided->approver_id);
        $this->assertSame('All good.', $decided->decision_comments);
        $this->assertNotNull($decided->decided_at);
        $this->assertSame(IncidentStatus::Closed, $incident->fresh()->status);
        $this->assertSame($approver->id, $incident->fresh()->closed_by);
        $this->assertNotNull($incident->fresh()->closed_at);
    }

    public function test_returning_for_revision_sends_the_incident_back_to_corrective_action(): void
    {
        $incident = $this->incidentReadyForApproval();
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $approval = app(ApprovalService::class)->requestApproval($incident, $qso);
        $approver = User::factory()->create(['role' => Role::Management]);

        $decided = app(ApprovalService::class)->returnForRevision($approval, $approver, DecideApprovalData::fromArray(['comments' => 'Needs a stronger fix.']));

        $this->assertSame(ApprovalStatus::Returned, $decided->status);
        $this->assertSame($approver->id, $decided->approver_id);
        $this->assertSame(IncidentStatus::CorrectiveAction, $incident->fresh()->status);
    }

    public function test_a_returned_incident_can_be_resubmitted_for_approval_producing_a_second_approval_record(): void
    {
        $incident = $this->incidentReadyForApproval();
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $first = app(ApprovalService::class)->requestApproval($incident, $qso);
        $approver = User::factory()->create(['role' => Role::Management]);
        app(ApprovalService::class)->returnForRevision($first, $approver, DecideApprovalData::fromArray(['comments' => 'Not enough.']));

        // A new CAPA is added/verified in response, advancing back to Verified again.
        $capa = app(CorrectiveActionService::class)->create($incident->fresh(), CorrectiveActionData::fromArray([
            'description' => 'Additional fix.', 'action_type' => 'corrective', 'priority' => 'high',
            'due_date' => now()->addDays(7)->toDateString(),
        ]));
        $this->actingAs(User::factory()->create());
        app(CorrectiveActionService::class)->complete($capa, CompleteCorrectiveActionData::fromArray(['completion_notes' => 'Done.']));
        app(CorrectiveActionService::class)->verify($capa->fresh(), User::factory()->create(['role' => Role::QualitySafetyOfficer]), VerifyCorrectiveActionData::fromArray(['verification_comments' => 'Confirmed.']));

        $second = app(ApprovalService::class)->requestApproval($incident->fresh(), $qso);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, $incident->fresh()->approvals()->count());
        $this->assertSame(IncidentStatus::ForApproval, $incident->fresh()->status);
    }

    public function test_qso_can_request_approval_once_the_incident_is_verified(): void
    {
        $incident = $this->incidentReadyForApproval();
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);

        $this->assertTrue($qso->can('requestApproval', $incident));
    }

    public function test_an_investigator_cannot_request_approval(): void
    {
        $incident = $this->incidentReadyForApproval();
        $investigator = User::factory()->create(['role' => Role::Investigator]);

        $this->assertFalse($investigator->can('requestApproval', $incident));
    }

    public function test_nobody_can_request_approval_before_the_incident_is_verified(): void
    {
        $incident = $this->incidentThroughInvestigation();
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);

        $this->assertFalse($qso->can('requestApproval', $incident->fresh()));
    }

    public function test_qso_can_mark_no_corrective_action_needed_when_zero_capas_exist(): void
    {
        $incident = $this->incidentThroughInvestigation();
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);

        $this->assertTrue($qso->can('markNoCorrectiveActionNeeded', $incident));
    }

    public function test_marking_no_corrective_action_needed_is_rejected_once_a_corrective_action_exists(): void
    {
        $incident = $this->incidentThroughInvestigation();
        app(CorrectiveActionService::class)->create($incident, CorrectiveActionData::fromArray([
            'description' => 'x', 'action_type' => 'corrective', 'priority' => 'high',
            'due_date' => now()->addDays(7)->toDateString(),
        ]));
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);

        $this->assertFalse($qso->can('markNoCorrectiveActionNeeded', $incident->fresh()));
    }

    public function test_management_and_administrator_can_approve_closure_hospital_wide(): void
    {
        $incident = $this->incidentReadyForApproval();
        $approval = app(ApprovalService::class)->requestApproval($incident, User::factory()->create(['role' => Role::QualitySafetyOfficer]));
        $management = User::factory()->create(['role' => Role::Management]);
        $admin = User::factory()->create(['role' => Role::Administrator]);

        $this->assertTrue($management->can('approveClosure', [$incident->fresh(), $approval]));
        $this->assertTrue($admin->can('approveClosure', [$incident->fresh(), $approval]));
    }

    public function test_a_department_head_can_only_approve_closure_for_their_own_department(): void
    {
        $incident = $this->incidentReadyForApproval();
        $approval = app(ApprovalService::class)->requestApproval($incident, User::factory()->create(['role' => Role::QualitySafetyOfficer]));
        $sameDeptHead = User::factory()->create(['role' => Role::DepartmentHead, 'department_id' => $incident->department_id]);
        $otherDeptHead = User::factory()->create(['role' => Role::DepartmentHead, 'department_id' => null]);

        $this->assertTrue($sameDeptHead->can('approveClosure', [$incident->fresh(), $approval]));
        $this->assertFalse($otherDeptHead->can('approveClosure', [$incident->fresh(), $approval]));
    }

    public function test_a_supervisor_cannot_approve_closure(): void
    {
        $incident = $this->incidentReadyForApproval();
        $approval = app(ApprovalService::class)->requestApproval($incident, User::factory()->create(['role' => Role::QualitySafetyOfficer]));
        $supervisor = User::factory()->create(['role' => Role::Supervisor, 'department_id' => $incident->department_id]);

        $this->assertFalse($supervisor->can('approveClosure', [$incident->fresh(), $approval]));
    }

    public function test_the_requester_cannot_approve_or_return_their_own_request_even_as_administrator(): void
    {
        $incident = $this->incidentReadyForApproval();
        $admin = User::factory()->create(['role' => Role::Administrator]);
        $approval = app(ApprovalService::class)->requestApproval($incident, $admin);
        $otherAdmin = User::factory()->create(['role' => Role::Administrator]);

        $this->assertFalse($admin->can('approveClosure', [$incident->fresh(), $approval]));
        $this->assertFalse($admin->can('returnFromApproval', [$incident->fresh(), $approval]));
        $this->assertTrue($otherAdmin->can('approveClosure', [$incident->fresh(), $approval]));
        $this->assertTrue($otherAdmin->can('returnFromApproval', [$incident->fresh(), $approval]));
    }

    public function test_an_already_decided_approval_can_no_longer_be_approved_or_returned_even_if_the_incident_is_for_approval_again(): void
    {
        $incident = $this->incidentReadyForApproval();
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $first = app(ApprovalService::class)->requestApproval($incident, $qso);
        $approver = User::factory()->create(['role' => Role::Management]);
        app(ApprovalService::class)->returnForRevision($first, $approver, DecideApprovalData::fromArray(['comments' => 'Not enough.']));

        $capa = app(CorrectiveActionService::class)->create($incident->fresh(), CorrectiveActionData::fromArray([
            'description' => 'Additional fix.', 'action_type' => 'corrective', 'priority' => 'high',
            'due_date' => now()->addDays(7)->toDateString(),
        ]));
        $this->actingAs(User::factory()->create());
        app(CorrectiveActionService::class)->complete($capa, CompleteCorrectiveActionData::fromArray(['completion_notes' => 'Done.']));
        app(CorrectiveActionService::class)->verify($capa->fresh(), User::factory()->create(['role' => Role::QualitySafetyOfficer]), VerifyCorrectiveActionData::fromArray(['verification_comments' => 'Confirmed.']));
        $second = app(ApprovalService::class)->requestApproval($incident->fresh(), $qso);

        // The incident is ForApproval again, but $first is the old, already-Returned
        // row from the earlier cycle - it must stay permanently undecidable, even
        // though the incident's *current* status would otherwise allow a decision.
        $this->assertFalse($approver->can('approveClosure', [$incident->fresh(), $first]));
        $this->assertFalse($approver->can('returnFromApproval', [$incident->fresh(), $first]));
        $this->assertTrue($approver->can('approveClosure', [$incident->fresh(), $second]));
    }

    public function test_qso_can_request_approval_via_http(): void
    {
        $incident = $this->incidentReadyForApproval();
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);

        $this->actingAs($qso)
            ->post("/incidents/{$incident->id}/request-approval")
            ->assertRedirect();

        $this->assertDatabaseHas('approvals', ['incident_id' => $incident->id, 'requested_by' => $qso->id]);
        $this->assertSame(IncidentStatus::ForApproval, $incident->fresh()->status);
    }

    public function test_an_investigator_cannot_request_approval_via_http(): void
    {
        $incident = $this->incidentReadyForApproval();
        $investigator = User::factory()->create(['role' => Role::Investigator]);

        $this->actingAs($investigator)
            ->post("/incidents/{$incident->id}/request-approval")
            ->assertForbidden();
    }

    public function test_marking_no_corrective_action_needed_via_http_requires_a_justification(): void
    {
        $incident = $this->incidentThroughInvestigation();
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);

        $this->actingAs($qso)
            ->post("/incidents/{$incident->id}/no-corrective-action-needed", [])
            ->assertSessionHasErrors(['justification']);
    }

    public function test_marking_no_corrective_action_needed_via_http(): void
    {
        $incident = $this->incidentThroughInvestigation();
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);

        $this->actingAs($qso)
            ->post("/incidents/{$incident->id}/no-corrective-action-needed", ['justification' => 'Near miss, no fix needed.'])
            ->assertRedirect();

        $this->assertSame(IncidentStatus::ForApproval, $incident->fresh()->status);
    }

    public function test_approving_via_http_requires_comments(): void
    {
        $incident = $this->incidentReadyForApproval();
        $approval = app(ApprovalService::class)->requestApproval($incident, User::factory()->create(['role' => Role::QualitySafetyOfficer]));
        $management = User::factory()->create(['role' => Role::Management]);

        $this->actingAs($management)
            ->post("/approvals/{$approval->id}/approve", [])
            ->assertSessionHasErrors(['comments']);
    }

    public function test_approving_via_http(): void
    {
        $incident = $this->incidentReadyForApproval();
        $approval = app(ApprovalService::class)->requestApproval($incident, User::factory()->create(['role' => Role::QualitySafetyOfficer]));
        $management = User::factory()->create(['role' => Role::Management]);

        $this->actingAs($management)
            ->post("/approvals/{$approval->id}/approve", ['comments' => 'Confirmed effective.'])
            ->assertRedirect();

        $this->assertSame(IncidentStatus::Closed, $incident->fresh()->status);
    }

    public function test_the_requester_cannot_approve_their_own_request_via_http(): void
    {
        $incident = $this->incidentReadyForApproval();
        $admin = User::factory()->create(['role' => Role::Administrator]);
        $approval = app(ApprovalService::class)->requestApproval($incident, $admin);

        $this->actingAs($admin)
            ->post("/approvals/{$approval->id}/approve", ['comments' => 'x'])
            ->assertForbidden();
    }

    public function test_returning_via_http(): void
    {
        $incident = $this->incidentReadyForApproval();
        $approval = app(ApprovalService::class)->requestApproval($incident, User::factory()->create(['role' => Role::QualitySafetyOfficer]));
        $management = User::factory()->create(['role' => Role::Management]);

        $this->actingAs($management)
            ->post("/approvals/{$approval->id}/return", ['comments' => 'Needs more work.'])
            ->assertRedirect();

        $this->assertSame(IncidentStatus::CorrectiveAction, $incident->fresh()->status);
    }

    public function test_the_incident_show_page_exposes_resource_shaped_approvals_with_per_item_can_flags(): void
    {
        $incident = $this->incidentReadyForApproval();
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $approval = app(ApprovalService::class)->requestApproval($incident, $qso);
        $management = User::factory()->create(['role' => Role::Management]);

        $this->actingAs($management)
            ->get("/incidents/{$incident->id}?tab=approvals")
            ->assertInertia(fn ($page) => $page
                ->where('approvals.0.id', $approval->id)
                ->where('approvals.0.status.value', 'pending')
                ->where('approvals.0.can.approve', true)
                ->where('approvals.0.can.return', true)
            );

        $this->actingAs($qso)
            ->get("/incidents/{$incident->id}?tab=approvals")
            ->assertInertia(fn ($page) => $page
                ->where('can.requestApproval', false) // already requested; incident is no longer Verified
                ->where('approvals.0.can.approve', false)
            );
    }
}
