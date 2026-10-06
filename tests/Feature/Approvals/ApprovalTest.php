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
use App\Enums\ApprovalStage;
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
use App\Notifications\CommitteeSignOffNeededNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ApprovalTest extends TestCase
{
    use RefreshDatabase;

    /**
     * An incident with a completed investigation, sitting at
     * IncidentStatus::CorrectiveAction with zero corrective actions.
     */
    private function incidentThroughInvestigation(Severity $severity = Severity::Level2Moderate): Incident
    {
        $department = Department::factory()->create();
        $incidentType = IncidentType::factory()->create();
        $reporter = User::factory()->create();
        $supervisor = User::factory()->create(['role' => Role::Supervisor, 'department_id' => $department->id]);
        $investigator = User::factory()->create(['role' => Role::Investigator]);

        $incident = app(IncidentService::class)->createDraft($reporter, [
            'department_id' => $department->id,
            'incident_type_ids' => [$incidentType->id],
            'severity' => $severity->value,
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

    private function headOf(Incident $incident): User
    {
        return User::factory()->headOf($incident->department_id)->create(['department_id' => $incident->department_id]);
    }

    /** An incident with one verified CAPA, sitting at IncidentStatus::Verified. */
    private function incidentReadyForApproval(Severity $severity = Severity::Level2Moderate): Incident
    {
        $incident = $this->incidentThroughInvestigation($severity);

        $capa = app(CorrectiveActionService::class)->create($incident, CorrectiveActionData::fromArray([
            'description' => 'Retrain staff.', 'action_type' => 'corrective', 'priority' => 'high',
            'due_date' => now()->addDays(14)->toDateString(),
        ]));
        $this->actingAs(User::factory()->create());
        app(CorrectiveActionService::class)->complete($capa, CompleteCorrectiveActionData::fromArray(['completion_notes' => 'Done.']));
        $verifier = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        app(CorrectiveActionService::class)->verify($capa->fresh(), $verifier, VerifyCorrectiveActionData::fromArray(['verification_comments' => 'Confirmed.']));

        // The effectiveness check has passed, so closure can be requested.
        $incident->fresh()->forceFill(['effectiveness_result' => 'effective'])->saveQuietly();

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

    /**
     * config('incident_workflow.approval_sla_hours') is keyed by every
     * Severity case's own ->value, not just Level2Moderate - confirm the
     * lookup actually resolves per-severity instead of silently falling
     * through to the default every time.
     */
    public function test_due_at_uses_the_sla_hours_configured_for_the_incidents_own_severity(): void
    {
        $cases = [
            Severity::Level1Low,
            Severity::Level2Moderate,
            Severity::Level3High,
            Severity::Level4Critical,
            Severity::Level5Sentinel,
        ];

        foreach ($cases as $severity) {
            $incident = $this->incidentReadyForApproval($severity);
            $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
            $expectedHours = config('incident_workflow.approval_sla_hours.' . $severity->value);
            $this->assertNotNull($expectedHours, "No SLA hours configured for {$severity->value}");

            $before = now();
            $approval = app(ApprovalService::class)->requestApproval($incident, $qso);

            $this->assertNotNull($approval->due_at);
            $this->assertTrue(
                $approval->due_at->between($before->copy()->addHours($expectedHours)->subMinute(), $before->copy()->addHours($expectedHours)->addMinute()),
                "due_at for {$severity->value} did not resolve to the configured {$expectedHours}-hour SLA."
            );
        }
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
        $approver = User::factory()->create(['role' => Role::QualitySafetyOfficer]);

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
        $approver = User::factory()->create(['role' => Role::QualitySafetyOfficer]);

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
        $approver = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
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

    public function test_the_department_head_can_request_approval_once_the_incident_is_verified(): void
    {
        $incident = $this->incidentReadyForApproval();

        $this->assertTrue($this->headOf($incident)->can('requestApproval', $incident));
    }

    public function test_quality_staff_management_and_other_department_heads_cannot_request_approval(): void
    {
        $incident = $this->incidentReadyForApproval();
        $otherHead = User::factory()->headOf($headDept = Department::factory()->create())->create(['department_id' => $headDept->id]);
        $supervisor = User::factory()->create(['role' => Role::Supervisor, 'department_id' => $incident->department_id]);

        foreach ([Role::QualitySafetyOfficer, Role::Administrator, Role::Management] as $role) {
            $this->assertFalse(User::factory()->create(['role' => $role])->can('requestApproval', $incident), $role->value);
        }
        $this->assertFalse($otherHead->can('requestApproval', $incident));
        $this->assertFalse($supervisor->can('requestApproval', $incident));
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
        $head = $this->headOf($incident);

        $this->assertFalse($head->can('requestApproval', $incident->fresh()));
    }

    public function test_the_department_head_can_mark_no_corrective_action_needed_when_zero_capas_exist(): void
    {
        $incident = $this->incidentThroughInvestigation();

        $this->assertTrue($this->headOf($incident)->can('markNoCorrectiveActionNeeded', $incident));
    }

    public function test_quality_staff_and_other_department_heads_cannot_mark_no_corrective_action_needed(): void
    {
        $incident = $this->incidentThroughInvestigation();
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $admin = User::factory()->create(['role' => Role::Administrator]);
        $otherHead = User::factory()->headOf($headDept = Department::factory()->create())->create(['department_id' => $headDept->id]);

        $this->assertFalse($qso->can('markNoCorrectiveActionNeeded', $incident));
        $this->assertFalse($admin->can('markNoCorrectiveActionNeeded', $incident));
        $this->assertFalse($otherHead->can('markNoCorrectiveActionNeeded', $incident));
        $this->actingAs($qso)
            ->post("/incidents/{$incident->id}/no-corrective-action-needed", ['justification' => 'x'])
            ->assertForbidden();
    }

    public function test_marking_no_corrective_action_needed_is_rejected_once_a_corrective_action_exists(): void
    {
        $incident = $this->incidentThroughInvestigation();
        app(CorrectiveActionService::class)->create($incident, CorrectiveActionData::fromArray([
            'description' => 'x', 'action_type' => 'corrective', 'priority' => 'high',
            'due_date' => now()->addDays(7)->toDateString(),
        ]));
        $head = $this->headOf($incident);

        $this->assertFalse($head->can('markNoCorrectiveActionNeeded', $incident->fresh()));
    }

    /**
     * After a no-CAPA-needed request is Returned, the incident is back at
     * CorrectiveAction with (still) zero corrective actions - the ability
     * must be usable a second time, producing a second Approval row while
     * the first, already-Returned row remains permanently undecidable
     * (same row-scoped guard as the CAPA-verified resubmission path).
     */
    public function test_marking_no_corrective_action_needed_can_be_used_again_after_a_return_with_still_zero_capas(): void
    {
        $incident = $this->incidentThroughInvestigation();
        $head = $this->headOf($incident);
        $first = app(ApprovalService::class)->markNoCorrectiveActionNeeded(
            $incident,
            $head,
            MarkNoCorrectiveActionNeededData::fromArray(['justification' => 'Initial call: no CAPA needed.'])
        );
        $approver = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        app(ApprovalService::class)->returnForRevision($first, $approver, DecideApprovalData::fromArray(['comments' => 'Disagree, please reconsider.']));

        $this->assertSame(IncidentStatus::CorrectiveAction, $incident->fresh()->status);
        $this->assertFalse($incident->fresh()->correctiveActions()->exists());
        $this->assertTrue($head->can('markNoCorrectiveActionNeeded', $incident->fresh()));

        $second = app(ApprovalService::class)->markNoCorrectiveActionNeeded(
            $incident->fresh(),
            $head,
            MarkNoCorrectiveActionNeededData::fromArray(['justification' => 'Reconsidered: still no CAPA needed.'])
        );

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, $incident->fresh()->approvals()->count());
        $this->assertSame(IncidentStatus::ForApproval, $incident->fresh()->status);
        $this->assertFalse($approver->can('approveClosure', [$incident->fresh(), $first]));
        $this->assertFalse($approver->can('returnFromApproval', [$incident->fresh(), $first]));
        $this->assertTrue($approver->can('approveClosure', [$incident->fresh(), $second]));
    }

    public function test_only_the_cqi_office_decides_the_first_closure_stage(): void
    {
        $incident = $this->incidentReadyForApproval();
        $approval = app(ApprovalService::class)->requestApproval($incident, $this->headOf($incident));

        $cqi = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $this->assertTrue($cqi->can('approveClosure', [$incident->fresh(), $approval]));
        $this->assertTrue($cqi->can('returnFromApproval', [$incident->fresh(), $approval]));

        foreach ([Role::Management, Role::Administrator, Role::CqiCommittee] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->assertFalse($user->can('approveClosure', [$incident->fresh(), $approval]), $role->value);
            $this->assertFalse($user->can('returnFromApproval', [$incident->fresh(), $approval]), $role->value);
        }
    }

    public function test_a_department_head_cannot_approve_or_return_closure_even_for_their_own_department(): void
    {
        $incident = $this->incidentReadyForApproval();
        $requester = $this->headOf($incident);
        $approval = app(ApprovalService::class)->requestApproval($incident, $requester);
        $sameDeptHead = $this->headOf($incident);
        $otherDeptHead = User::factory()->headOf($otherDept = Department::factory()->create())->create(['department_id' => $otherDept->id]);

        foreach ([$requester, $sameDeptHead, $otherDeptHead] as $head) {
            $this->assertFalse($head->can('approveClosure', [$incident->fresh(), $approval]));
            $this->assertFalse($head->can('returnFromApproval', [$incident->fresh(), $approval]));
        }
        $this->actingAs($sameDeptHead)
            ->post("/approvals/{$approval->id}/approve", ['comments' => 'x'])
            ->assertForbidden();
    }

    public function test_a_supervisor_cannot_approve_closure(): void
    {
        $incident = $this->incidentReadyForApproval();
        $approval = app(ApprovalService::class)->requestApproval($incident, User::factory()->create(['role' => Role::QualitySafetyOfficer]));
        $supervisor = User::factory()->create(['role' => Role::Supervisor, 'department_id' => $incident->department_id]);

        $this->assertFalse($supervisor->can('approveClosure', [$incident->fresh(), $approval]));
    }

    public function test_the_requester_cannot_approve_or_return_their_own_request_even_as_cqi_office(): void
    {
        $incident = $this->incidentReadyForApproval();
        $admin = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $approval = app(ApprovalService::class)->requestApproval($incident, $admin);
        $otherAdmin = User::factory()->create(['role' => Role::QualitySafetyOfficer]);

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
        $approver = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
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

    public function test_the_department_head_can_request_approval_via_http_but_qso_cannot(): void
    {
        $incident = $this->incidentReadyForApproval();
        $head = $this->headOf($incident);
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);

        $this->actingAs($qso)
            ->post("/incidents/{$incident->id}/request-approval", ['lessons_learned' => 'Check the rail weekly.'])
            ->assertForbidden();

        $this->actingAs($head)
            ->post("/incidents/{$incident->id}/request-approval", ['lessons_learned' => 'Check the rail weekly.'])
            ->assertRedirect();

        $this->assertDatabaseHas('approvals', ['incident_id' => $incident->id, 'requested_by' => $head->id]);
        $this->assertSame(IncidentStatus::ForApproval, $incident->fresh()->status);
    }

    public function test_an_investigator_cannot_request_approval_via_http(): void
    {
        $incident = $this->incidentReadyForApproval();
        $investigator = User::factory()->create(['role' => Role::Investigator]);

        $this->actingAs($investigator)
            ->post("/incidents/{$incident->id}/request-approval", ['lessons_learned' => 'Check the rail weekly.'])
            ->assertForbidden();
    }

    public function test_marking_no_corrective_action_needed_via_http_requires_a_justification(): void
    {
        $incident = $this->incidentThroughInvestigation();
        $head = $this->headOf($incident);

        $this->actingAs($head)
            ->post("/incidents/{$incident->id}/no-corrective-action-needed", [])
            ->assertSessionHasErrors(['justification']);
    }

    public function test_marking_no_corrective_action_needed_via_http(): void
    {
        $incident = $this->incidentThroughInvestigation();
        $head = $this->headOf($incident);

        $this->actingAs($head)
            ->post("/incidents/{$incident->id}/no-corrective-action-needed", ['justification' => 'Near miss, no fix needed.', 'lessons_learned' => 'Near misses are worth reporting.'])
            ->assertRedirect();

        $this->assertSame(IncidentStatus::ForApproval, $incident->fresh()->status);
    }

    public function test_approving_via_http_requires_comments(): void
    {
        $incident = $this->incidentReadyForApproval();
        $approval = app(ApprovalService::class)->requestApproval($incident, User::factory()->create(['role' => Role::QualitySafetyOfficer]));
        $management = User::factory()->create(['role' => Role::QualitySafetyOfficer]);

        $this->actingAs($management)
            ->post("/approvals/{$approval->id}/approve", [])
            ->assertSessionHasErrors(['comments']);
    }

    public function test_approving_via_http(): void
    {
        $incident = $this->incidentReadyForApproval();
        $approval = app(ApprovalService::class)->requestApproval($incident, User::factory()->create(['role' => Role::QualitySafetyOfficer]));
        $management = User::factory()->create(['role' => Role::QualitySafetyOfficer]);

        $this->actingAs($management)
            ->post("/approvals/{$approval->id}/approve", ['comments' => 'Confirmed effective.'])
            ->assertRedirect();

        $this->assertSame(IncidentStatus::Closed, $incident->fresh()->status);
    }

    public function test_the_requester_cannot_approve_their_own_request_via_http(): void
    {
        $incident = $this->incidentReadyForApproval();
        $admin = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $approval = app(ApprovalService::class)->requestApproval($incident, $admin);

        $this->actingAs($admin)
            ->post("/approvals/{$approval->id}/approve", ['comments' => 'x'])
            ->assertForbidden();
    }

    public function test_returning_via_http(): void
    {
        $incident = $this->incidentReadyForApproval();
        $approval = app(ApprovalService::class)->requestApproval($incident, User::factory()->create(['role' => Role::QualitySafetyOfficer]));
        $management = User::factory()->create(['role' => Role::QualitySafetyOfficer]);

        $this->actingAs($management)
            ->post("/approvals/{$approval->id}/return", ['comments' => 'Needs more work.'])
            ->assertRedirect();

        $this->assertSame(IncidentStatus::CorrectiveAction, $incident->fresh()->status);
    }

    public function test_the_incident_show_page_exposes_resource_shaped_approvals_with_per_item_can_flags(): void
    {
        $incident = $this->incidentReadyForApproval();
        $head = $this->headOf($incident);
        $approval = app(ApprovalService::class)->requestApproval($incident, $head);
        $management = User::factory()->create(['role' => Role::QualitySafetyOfficer]);

        $this->actingAs($management)
            ->get("/incidents/{$incident->id}?tab=approvals")
            ->assertInertia(fn ($page) => $page
                ->where('approvals.0.id', $approval->id)
                ->where('approvals.0.status.value', 'pending')
                ->where('approvals.0.can.approve', true)
                ->where('approvals.0.can.return', true)
            );

        $this->actingAs($head)
            ->get("/incidents/{$incident->id}?tab=approvals")
            ->assertInertia(fn ($page) => $page
                ->where('can.requestApproval', false) // already requested; incident is no longer Verified
                ->where('approvals.0.can.approve', false)
            );
    }

    public function test_a_high_risk_closure_needs_the_committee_after_the_cqi_office(): void
    {
        Notification::fake();
        $committee = User::factory()->create(['role' => Role::CqiCommittee]);
        $incident = $this->incidentReadyForApproval(Severity::Level3High);
        $first = app(ApprovalService::class)->requestApproval($incident, $this->headOf($incident));
        $cqi = User::factory()->create(['role' => Role::QualitySafetyOfficer]);

        $this->actingAs($cqi)->post("/approvals/{$first->id}/approve", ['comments' => 'CAPA done.'])->assertRedirect();

        $incident->refresh();
        $this->assertSame(IncidentStatus::ForApproval, $incident->status);
        $second = $incident->approvals()->latest('id')->first();
        $this->assertSame(ApprovalStage::Committee, $second->stage);
        $this->assertSame(ApprovalStatus::Pending, $second->status);
        Notification::assertSentTo($committee, CommitteeSignOffNeededNotification::class);

        $this->assertFalse($cqi->can('approveClosure', [$incident, $second]));
        $this->assertTrue($committee->can('approveClosure', [$incident, $second]));

        $this->actingAs($committee)->post("/approvals/{$second->id}/approve", ['comments' => 'Agreed.'])->assertRedirect();
        $this->assertSame(IncidentStatus::Closed, $incident->fresh()->status);
    }

    public function test_the_committee_can_return_a_high_risk_closure_to_the_department(): void
    {
        $incident = $this->incidentReadyForApproval(Severity::Level5Sentinel);
        $first = app(ApprovalService::class)->requestApproval($incident, $this->headOf($incident));
        app(ApprovalService::class)->approve($first, User::factory()->create(['role' => Role::QualitySafetyOfficer]), DecideApprovalData::fromArray(['comments' => 'OK.']));
        $second = $incident->approvals()->latest('id')->first();
        $committee = User::factory()->create(['role' => Role::CqiCommittee]);

        $this->actingAs($committee)->post("/approvals/{$second->id}/return", ['comments' => 'Retrain all staff first.'])->assertRedirect();

        $this->assertSame(IncidentStatus::CorrectiveAction, $incident->fresh()->status);
    }

    public function test_a_critical_closure_also_needs_the_committee(): void
    {
        $incident = $this->incidentReadyForApproval(Severity::Level4Critical);
        $first = app(ApprovalService::class)->requestApproval($incident, $this->headOf($incident));
        app(ApprovalService::class)->approve($first, User::factory()->create(['role' => Role::QualitySafetyOfficer]), DecideApprovalData::fromArray(['comments' => 'OK.']));

        $this->assertSame(IncidentStatus::ForApproval, $incident->fresh()->status);
        $this->assertSame(2, $incident->approvals()->count());
    }

    public function test_a_moderate_closure_closes_on_the_cqi_offices_approval_alone(): void
    {
        $incident = $this->incidentReadyForApproval(Severity::Level2Moderate);
        $approval = app(ApprovalService::class)->requestApproval($incident, $this->headOf($incident));

        app(ApprovalService::class)->approve($approval, User::factory()->create(['role' => Role::QualitySafetyOfficer]), DecideApprovalData::fromArray(['comments' => 'OK.']));

        $this->assertSame(IncidentStatus::Closed, $incident->fresh()->status);
        $this->assertSame(1, $incident->approvals()->count());
    }
}
