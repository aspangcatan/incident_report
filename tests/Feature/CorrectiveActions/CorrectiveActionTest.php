<?php

namespace Tests\Feature\CorrectiveActions;

use App\DataTransferObjects\CorrectiveActions\CompleteCorrectiveActionData;
use App\DataTransferObjects\CorrectiveActions\CorrectiveActionData;
use App\DataTransferObjects\CorrectiveActions\VerifyCorrectiveActionData;
use App\DataTransferObjects\Investigations\CompleteInvestigationData;
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

class CorrectiveActionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * An incident that has finished its investigation and is ready for
     * CAPA creation (Incident.status === corrective_action).
     */
    private function incidentReadyForCapa(?User $investigator = null): Incident
    {
        $department = Department::factory()->create();
        $incidentType = IncidentType::factory()->create();
        $reporter = User::factory()->create();
        $supervisor = User::factory()->create(['role' => Role::Supervisor, 'department_id' => $department->id]);
        $investigator ??= User::factory()->create(['role' => Role::Investigator]);

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
        app(InvestigationService::class)->addFinding($investigation, \App\DataTransferObjects\Investigations\FindingData::fromArray([
            'question' => 'Why?', 'finding' => 'Root cause.', 'is_root_cause' => true,
        ]));
        app(InvestigationService::class)->complete($investigation->fresh(), CompleteInvestigationData::fromArray(['conclusion' => 'Done.']));

        return $incident->fresh();
    }

    private function headOf(Incident $incident): User
    {
        return User::factory()->headOf($incident->department_id)->create(['department_id' => $incident->department_id]);
    }

    private function capaData(array $extra = []): CorrectiveActionData
    {
        return CorrectiveActionData::fromArray(array_merge([
            'description' => 'Implement double-check checklist.',
            'action_type' => 'corrective',
            'priority' => 'high',
            'due_date' => now()->addDays(14)->toDateString(),
        ], $extra));
    }

    public function test_creating_a_corrective_action_generates_a_capa_number_and_sets_status_open(): void
    {
        $incident = $this->incidentReadyForCapa();

        $capa = app(CorrectiveActionService::class)->create($incident, $this->capaData());

        $this->assertMatchesRegularExpression('/^CAPA-' . now()->year . '-\d{3}$/', $capa->capa_number);
        $this->assertSame(CorrectiveActionStatus::Open, $capa->status);
        $this->assertSame($incident->id, $capa->incident_id);
    }

    public function test_creating_a_corrective_action_links_the_incidents_investigation_automatically(): void
    {
        $incident = $this->incidentReadyForCapa();

        $capa = app(CorrectiveActionService::class)->create($incident, $this->capaData());

        $this->assertSame($incident->investigation->id, $capa->investigation_id);
    }

    public function test_creating_a_corrective_action_writes_an_action_created_audit_log_entry(): void
    {
        $incident = $this->incidentReadyForCapa();

        app(CorrectiveActionService::class)->create($incident, $this->capaData());

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Incident::class,
            'auditable_id' => $incident->id,
            'action' => 'action_created',
        ]);
    }

    public function test_capa_numbers_increment_within_a_year(): void
    {
        $incident = $this->incidentReadyForCapa();
        $service = app(CorrectiveActionService::class);

        $first = $service->create($incident, $this->capaData());
        $second = $service->create($incident->fresh(), $this->capaData());

        $this->assertNotSame($first->capa_number, $second->capa_number);
    }

    public function test_updating_a_corrective_action(): void
    {
        $incident = $this->incidentReadyForCapa();
        $capa = app(CorrectiveActionService::class)->create($incident, $this->capaData());

        $updated = app(CorrectiveActionService::class)->update($capa, $this->capaData(['description' => 'Revised description.', 'priority' => 'critical']));

        $this->assertSame('Revised description.', $updated->description);
        $this->assertSame(\App\Enums\CorrectiveActionPriority::Critical, $updated->priority);
    }

    public function test_marking_a_corrective_action_in_progress(): void
    {
        $incident = $this->incidentReadyForCapa();
        $capa = app(CorrectiveActionService::class)->create($incident, $this->capaData());

        $updated = app(CorrectiveActionService::class)->markInProgress($capa);

        $this->assertSame(CorrectiveActionStatus::InProgress, $updated->status);
    }

    public function test_completing_a_corrective_action_moves_it_straight_to_for_verification(): void
    {
        $incident = $this->incidentReadyForCapa();
        $capa = app(CorrectiveActionService::class)->create($incident, $this->capaData());
        $responsible = User::factory()->create();
        $this->actingAs($responsible);

        $completed = app(CorrectiveActionService::class)->complete($capa, CompleteCorrectiveActionData::fromArray(['completion_notes' => 'Checklist rolled out.']));

        $this->assertSame(CorrectiveActionStatus::ForVerification, $completed->status);
        $this->assertSame($responsible->id, $completed->completed_by);
        $this->assertNotNull($completed->completed_at);
        $this->assertSame('Checklist rolled out.', $completed->completion_notes);
    }

    public function test_completing_a_corrective_action_writes_an_action_completed_audit_log_entry(): void
    {
        $incident = $this->incidentReadyForCapa();
        $capa = app(CorrectiveActionService::class)->create($incident, $this->capaData());
        $this->actingAs(User::factory()->create());

        app(CorrectiveActionService::class)->complete($capa, CompleteCorrectiveActionData::fromArray(['completion_notes' => 'Done.']));

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Incident::class,
            'auditable_id' => $incident->id,
            'action' => 'action_completed',
        ]);
    }

    public function test_completing_the_only_capa_advances_the_incident_to_for_verification(): void
    {
        $incident = $this->incidentReadyForCapa();
        $capa = app(CorrectiveActionService::class)->create($incident, $this->capaData());
        $this->actingAs(User::factory()->create());

        app(CorrectiveActionService::class)->complete($capa, CompleteCorrectiveActionData::fromArray(['completion_notes' => 'Done.']));

        $this->assertSame(IncidentStatus::ForVerification, $incident->fresh()->status);
    }

    public function test_completing_one_of_two_capas_does_not_yet_advance_the_incident(): void
    {
        $incident = $this->incidentReadyForCapa();
        $service = app(CorrectiveActionService::class);
        $capaOne = $service->create($incident, $this->capaData());
        $service->create($incident->fresh(), $this->capaData());
        $this->actingAs(User::factory()->create());

        $service->complete($capaOne, CompleteCorrectiveActionData::fromArray(['completion_notes' => 'Done.']));

        $this->assertSame(IncidentStatus::CorrectiveAction, $incident->fresh()->status);
    }

    public function test_verifying_the_last_capa_advances_the_incident_to_verified(): void
    {
        $incident = $this->incidentReadyForCapa();
        $service = app(CorrectiveActionService::class);
        $capa = $service->create($incident, $this->capaData());
        $responsible = User::factory()->create();
        $this->actingAs($responsible);
        $service->complete($capa, CompleteCorrectiveActionData::fromArray(['completion_notes' => 'Done.']));
        $verifier = User::factory()->create(['role' => Role::QualitySafetyOfficer]);

        $verified = $service->verify($capa->fresh(), $verifier, VerifyCorrectiveActionData::fromArray(['verification_comments' => 'Confirmed effective.']));

        $this->assertSame(CorrectiveActionStatus::Verified, $verified->status);
        $this->assertSame($verifier->id, $verified->verified_by);
        $this->assertSame(IncidentStatus::Verified, $incident->fresh()->status);
    }

    public function test_verifying_writes_an_action_verified_audit_log_entry(): void
    {
        $incident = $this->incidentReadyForCapa();
        $service = app(CorrectiveActionService::class);
        $capa = $service->create($incident, $this->capaData());
        $this->actingAs(User::factory()->create());
        $service->complete($capa, CompleteCorrectiveActionData::fromArray(['completion_notes' => 'Done.']));
        $verifier = User::factory()->create(['role' => Role::QualitySafetyOfficer]);

        $service->verify($capa->fresh(), $verifier, VerifyCorrectiveActionData::fromArray(['verification_comments' => 'Confirmed.']));

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Incident::class,
            'auditable_id' => $incident->id,
            'action' => 'action_verified',
        ]);
    }

    public function test_a_third_capa_created_after_the_first_two_reach_for_verification_does_not_get_stuck(): void
    {
        $incident = $this->incidentReadyForCapa();
        $service = app(CorrectiveActionService::class);
        $capaOne = $service->create($incident, $this->capaData());
        $capaTwo = $service->create($incident->fresh(), $this->capaData());
        $completer = User::factory()->create();
        $this->actingAs($completer);

        $service->complete($capaOne, CompleteCorrectiveActionData::fromArray(['completion_notes' => 'Done.']));
        $service->complete($capaTwo, CompleteCorrectiveActionData::fromArray(['completion_notes' => 'Done.']));

        // Both CAPAs are now for_verification, so the incident has already
        // rolled forward - before the third CAPA even exists.
        $this->assertSame(IncidentStatus::ForVerification, $incident->fresh()->status);

        // A QSO now opens a third CAPA on the same incident. create() never
        // touches incident status, so the incident stays at for_verification
        // even though CAPA #3 is freshly Open - it does not revert, but it
        // must not get permanently stuck there either.
        $capaThree = $service->create($incident->fresh(), $this->capaData());
        $this->assertSame(CorrectiveActionStatus::Open, $capaThree->status);
        $this->assertSame(IncidentStatus::ForVerification, $incident->fresh()->status);

        // Completing CAPA #3 re-checks maybeAdvanceToForVerification(), but its
        // guard (status !== CorrectiveAction) makes this a no-op since the
        // incident already passed that stage - it must not error or misfire.
        $service->complete($capaThree, CompleteCorrectiveActionData::fromArray(['completion_notes' => 'Done.']));
        $this->assertSame(IncidentStatus::ForVerification, $incident->fresh()->status);

        $verifier = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $service->verify($capaOne->fresh(), $verifier, VerifyCorrectiveActionData::fromArray(['verification_comments' => 'Confirmed.']));
        $service->verify($capaTwo->fresh(), $verifier, VerifyCorrectiveActionData::fromArray(['verification_comments' => 'Confirmed.']));

        // Two of three verified: the incident must not advance to Verified yet.
        $this->assertSame(IncidentStatus::ForVerification, $incident->fresh()->status);

        $service->verify($capaThree->fresh(), $verifier, VerifyCorrectiveActionData::fromArray(['verification_comments' => 'Confirmed.']));

        // All three verified: the incident correctly self-corrects to Verified,
        // proving it never got permanently stuck despite the late-added CAPA.
        $this->assertSame(IncidentStatus::Verified, $incident->fresh()->status);
    }

    public function test_the_department_head_can_create_a_corrective_action_once_the_incident_is_at_corrective_action_stage(): void
    {
        $incident = $this->incidentReadyForCapa();
        $head = $this->headOf($incident);
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);

        $this->assertTrue($head->can('create', [\App\Models\CorrectiveAction::class, $incident]));
        $this->assertFalse($qso->can('create', [\App\Models\CorrectiveAction::class, $incident]));
    }

    public function test_an_investigator_cannot_create_a_corrective_action(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $incident = $this->incidentReadyForCapa($investigator);

        $this->assertFalse($investigator->can('create', [\App\Models\CorrectiveAction::class, $incident]));
    }

    public function test_nobody_can_create_a_corrective_action_before_the_incident_reaches_corrective_action_stage(): void
    {
        $reporter = User::factory()->create();
        $department = Department::factory()->create();
        $incidentType = IncidentType::factory()->create();
        $head = User::factory()->headOf($department->id)->create(['department_id' => $department->id]);

        $incident = app(IncidentService::class)->createDraft($reporter, [
            'department_id' => $department->id,
            'incident_type_ids' => [$incidentType->id],
            'severity' => Severity::Level2Moderate->value,
            'occurred_at' => now(),
            'location' => 'Ward 3',
            'summary' => 'Test incident.',
        ]);
        app(IncidentService::class)->submit($incident);

        $this->assertFalse($head->can('create', [\App\Models\CorrectiveAction::class, $incident->fresh()]));
    }

    public function test_nobody_can_create_a_corrective_action_once_the_incident_has_already_advanced_past_corrective_action(): void
    {
        $incident = $this->incidentReadyForCapa();
        $service = app(CorrectiveActionService::class);
        $capa = $service->create($incident, $this->capaData());
        $this->actingAs(User::factory()->create());
        $service->complete($capa, CompleteCorrectiveActionData::fromArray(['completion_notes' => 'Done.']));
        // Completing the only CAPA rolls the incident forward to for_verification.
        $this->assertSame(IncidentStatus::ForVerification, $incident->fresh()->status);
        $head = $this->headOf($incident);

        $this->assertFalse($head->can('create', [\App\Models\CorrectiveAction::class, $incident->fresh()]));
    }

    public function test_only_the_department_head_can_update_an_open_corrective_action(): void
    {
        $incident = $this->incidentReadyForCapa();
        $capa = app(CorrectiveActionService::class)->create($incident, $this->capaData());
        $head = $this->headOf($incident);
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $stranger = User::factory()->create();

        $this->assertTrue($head->can('update', $capa));
        $this->assertFalse($qso->can('update', $capa));
        $this->assertFalse($stranger->can('update', $capa));
    }

    public function test_a_corrective_action_can_no_longer_be_updated_once_it_is_for_verification(): void
    {
        $incident = $this->incidentReadyForCapa();
        $capa = app(CorrectiveActionService::class)->create($incident, $this->capaData());
        $head = $this->headOf($incident);
        $this->actingAs(User::factory()->create());
        app(CorrectiveActionService::class)->complete($capa, CompleteCorrectiveActionData::fromArray(['completion_notes' => 'Done.']));

        $this->assertFalse($head->can('update', $capa->fresh()));
    }

    public function test_only_the_responsible_user_can_mark_it_in_progress_or_complete_it(): void
    {
        $incident = $this->incidentReadyForCapa();
        $responsible = User::factory()->create();
        $capa = app(CorrectiveActionService::class)->create($incident, $this->capaData(['responsible_user_id' => $responsible->id]));
        $stranger = User::factory()->create();
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $admin = User::factory()->create(['role' => Role::Administrator]);
        $head = $this->headOf($incident);

        $this->assertTrue($responsible->can('progress', $capa));
        $this->assertFalse($responsible->can('complete', $capa), 'Not before Start Work');
        foreach ([$stranger, $qso, $admin, $head] as $other) {
            $this->assertFalse($other->can('progress', $capa));
        }

        $this->actingAs($responsible)->post("/corrective-actions/{$capa->id}/progress")->assertRedirect();
        $capa->refresh();

        $this->assertTrue($responsible->can('complete', $capa));
        foreach ([$stranger, $qso, $admin, $head] as $other) {
            $this->assertFalse($other->can('complete', $capa));
        }
    }

    public function test_completing_before_start_work_is_refused(): void
    {
        $incident = $this->incidentReadyForCapa();
        $responsible = User::factory()->create();
        $capa = app(CorrectiveActionService::class)->create($incident, $this->capaData(['responsible_user_id' => $responsible->id]));

        $this->actingAs($responsible)
            ->post("/corrective-actions/{$capa->id}/complete", ['completion_notes' => 'Done.'])
            ->assertForbidden();
        $this->assertSame(\App\Enums\CorrectiveActionStatus::Open, $capa->fresh()->status);
    }

    public function test_someone_cannot_start_or_complete_a_colleagues_capa_via_http(): void
    {
        $incident = $this->incidentReadyForCapa();
        $owner = User::factory()->create();
        $colleague = User::factory()->create();
        $capa = app(CorrectiveActionService::class)->create($incident, $this->capaData(['responsible_user_id' => $owner->id]));

        $this->actingAs($colleague)->post("/corrective-actions/{$capa->id}/progress")->assertForbidden();
        $this->actingAs($owner)->post("/corrective-actions/{$capa->id}/progress");
        $this->actingAs($colleague)
            ->post("/corrective-actions/{$capa->id}/complete", ['completion_notes' => 'Done.'])
            ->assertForbidden();
    }

    public function test_a_supervisor_can_verify_but_the_person_who_completed_it_cannot_even_if_also_a_supervisor(): void
    {
        $incident = $this->incidentReadyForCapa();
        $capa = app(CorrectiveActionService::class)->create($incident, $this->capaData());
        $completer = User::factory()->create(['role' => Role::Supervisor]);
        $this->actingAs($completer);
        app(CorrectiveActionService::class)->complete($capa, CompleteCorrectiveActionData::fromArray(['completion_notes' => 'Done.']));
        $otherSupervisor = User::factory()->create(['role' => Role::Supervisor, 'department_id' => $incident->department_id]);

        $this->assertTrue($otherSupervisor->can('verify', $capa->fresh()));
        $this->assertFalse($completer->can('verify', $capa->fresh()));
    }

    public function test_an_investigator_cannot_verify_a_corrective_action(): void
    {
        $incident = $this->incidentReadyForCapa();
        $capa = app(CorrectiveActionService::class)->create($incident, $this->capaData());
        $this->actingAs(User::factory()->create());
        app(CorrectiveActionService::class)->complete($capa, CompleteCorrectiveActionData::fromArray(['completion_notes' => 'Done.']));
        $investigator = User::factory()->create(['role' => Role::Investigator]);

        $this->assertFalse($investigator->can('verify', $capa->fresh()));
    }

    public function test_qso_cannot_create_a_corrective_action_via_http(): void
    {
        $incident = $this->incidentReadyForCapa();
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);

        $this->actingAs($qso)->post("/incidents/{$incident->id}/corrective-actions", $this->capaPayload())
            ->assertForbidden();
        $this->assertDatabaseMissing('corrective_actions', ['incident_id' => $incident->id]);
    }

    public function test_the_department_head_can_create_a_corrective_action_via_http(): void
    {
        $incident = $this->incidentReadyForCapa();
        $head = $this->headOf($incident);

        $response = $this->actingAs($head)->post("/incidents/{$incident->id}/corrective-actions", [
            'description' => 'Implement double-check checklist.',
            'action_type' => 'corrective',
            'priority' => 'high',
            'due_date' => now()->addDays(14)->toDateString(),
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('corrective_actions', ['incident_id' => $incident->id]);
    }

    public function test_creating_a_corrective_action_requires_a_description_type_priority_and_due_date(): void
    {
        $incident = $this->incidentReadyForCapa();
        $head = $this->headOf($incident);

        $this->actingAs($head)
            ->post("/incidents/{$incident->id}/corrective-actions", [])
            ->assertSessionHasErrors(['description', 'action_type', 'priority', 'due_date']);
    }

    public function test_root_cause_finding_id_cannot_reference_a_different_incidents_investigation(): void
    {
        $incidentA = $this->incidentReadyForCapa();
        $incidentB = $this->incidentReadyForCapa();
        $head = $this->headOf($incidentA);
        $foreignFindingId = $incidentB->fresh()->investigation->findings->first()->id;

        $response = $this->actingAs($head)->post("/incidents/{$incidentA->id}/corrective-actions", [
            'description' => 'Implement double-check checklist.',
            'action_type' => 'corrective',
            'priority' => 'high',
            'due_date' => now()->addDays(14)->toDateString(),
            'root_cause_finding_id' => $foreignFindingId,
        ]);

        $response->assertSessionHasErrors(['root_cause_finding_id']);
        $this->assertDatabaseMissing('corrective_actions', ['incident_id' => $incidentA->id]);
    }

    public function test_an_investigator_cannot_create_a_corrective_action_via_http(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $incident = $this->incidentReadyForCapa($investigator);

        $this->actingAs($investigator)
            ->post("/incidents/{$incident->id}/corrective-actions", [
                'description' => 'x', 'action_type' => 'corrective', 'priority' => 'high',
                'due_date' => now()->addDays(14)->toDateString(),
            ])
            ->assertForbidden();
    }

    public function test_updating_a_corrective_action_via_http(): void
    {
        $incident = $this->incidentReadyForCapa();
        $capa = app(CorrectiveActionService::class)->create($incident, $this->capaData());
        $head = $this->headOf($incident);

        $this->actingAs($head)
            ->patch("/corrective-actions/{$capa->id}", [
                'description' => 'Revised.', 'action_type' => 'corrective', 'priority' => 'critical',
                'due_date' => now()->addDays(7)->toDateString(),
            ])
            ->assertRedirect();

        $this->assertSame('Revised.', $capa->fresh()->description);
    }

    public function test_marking_in_progress_via_http(): void
    {
        $incident = $this->incidentReadyForCapa();
        $responsible = User::factory()->create();
        $capa = app(CorrectiveActionService::class)->create($incident, $this->capaData(['responsible_user_id' => $responsible->id]));

        $this->actingAs($responsible)
            ->post("/corrective-actions/{$capa->id}/progress")
            ->assertRedirect();

        $this->assertSame(\App\Enums\CorrectiveActionStatus::InProgress, $capa->fresh()->status);
    }

    public function test_completing_via_http_requires_completion_notes(): void
    {
        $incident = $this->incidentReadyForCapa();
        $responsible = User::factory()->create();
        $capa = app(CorrectiveActionService::class)->create($incident, $this->capaData(['responsible_user_id' => $responsible->id]));

        $this->actingAs($responsible)->post("/corrective-actions/{$capa->id}/progress");
        $this->actingAs($responsible)
            ->post("/corrective-actions/{$capa->id}/complete", [])
            ->assertSessionHasErrors(['completion_notes']);
    }

    public function test_completing_via_http(): void
    {
        $incident = $this->incidentReadyForCapa();
        $responsible = User::factory()->create();
        $capa = app(CorrectiveActionService::class)->create($incident, $this->capaData(['responsible_user_id' => $responsible->id]));

        $this->actingAs($responsible)->post("/corrective-actions/{$capa->id}/progress");
        $this->actingAs($responsible)
            ->post("/corrective-actions/{$capa->id}/complete", ['completion_notes' => 'Done.'])
            ->assertRedirect();

        $this->assertSame(\App\Enums\CorrectiveActionStatus::ForVerification, $capa->fresh()->status);
    }

    public function test_the_person_who_completed_it_cannot_verify_it_via_http(): void
    {
        $incident = $this->incidentReadyForCapa();
        $completer = User::factory()->create(['role' => Role::Supervisor]);
        $capa = app(CorrectiveActionService::class)->create($incident, $this->capaData(['responsible_user_id' => $completer->id]));
        $this->actingAs($completer)->post("/corrective-actions/{$capa->id}/progress");
        $this->actingAs($completer)->post("/corrective-actions/{$capa->id}/complete", ['completion_notes' => 'Done.']);

        $this->actingAs($completer)
            ->post("/corrective-actions/{$capa->id}/verify", ['verification_comments' => 'Looks good.'])
            ->assertForbidden();
    }

    public function test_verifying_via_http(): void
    {
        $incident = $this->incidentReadyForCapa();
        $completer = User::factory()->create();
        $capa = app(CorrectiveActionService::class)->create($incident, $this->capaData(['responsible_user_id' => $completer->id]));
        $this->actingAs($completer)->post("/corrective-actions/{$capa->id}/progress");
        $this->actingAs($completer)->post("/corrective-actions/{$capa->id}/complete", ['completion_notes' => 'Done.']);
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $verifier = User::factory()->create(['role' => Role::Supervisor, 'department_id' => $incident->department_id]);

        $this->actingAs($qso)
            ->post("/corrective-actions/{$capa->id}/verify", ['verification_comments' => 'Confirmed.'])
            ->assertForbidden();
        $this->actingAs($verifier)
            ->post("/corrective-actions/{$capa->id}/verify", ['verification_comments' => 'Confirmed.'])
            ->assertRedirect();

        $this->assertSame(\App\Enums\CorrectiveActionStatus::Verified, $capa->fresh()->status);
    }

    public function test_the_incident_show_page_exposes_resource_shaped_corrective_actions_with_per_item_can_flags(): void
    {
        // $responsible also plays the incident's assigned investigator so that
        // IncidentPolicy::view() lets them load the show page (a plain, unrelated
        // user has no view access to someone else's incident) - this doesn't
        // affect the CorrectiveActionPolicy checks under test, which key off
        // role and responsible_user_id, not investigator assignment.
        $responsible = User::factory()->create();
        $incident = $this->incidentReadyForCapa($responsible);
        $head = $this->headOf($incident);
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $capa = app(CorrectiveActionService::class)->create($incident, $this->capaData(['responsible_user_id' => $responsible->id]));

        // The Department Head manages the CAPA but is not the one doing the work.
        $this->actingAs($head)
            ->get("/incidents/{$incident->id}?tab=capa")
            ->assertInertia(fn ($page) => $page
                ->where('can.createCorrectiveAction', true)
                ->where('correctiveActions.0.id', $capa->id)
                ->where('correctiveActions.0.capa_number', $capa->capa_number)
                ->where('correctiveActions.0.can.update', true)
                ->where('correctiveActions.0.can.progress', false)
            );

        // QSO can view the CAPA stage but not act in it.
        $this->actingAs($qso)
            ->get("/incidents/{$incident->id}?tab=capa")
            ->assertInertia(fn ($page) => $page
                ->where('can.createCorrectiveAction', false)
                ->where('correctiveActions.0.can.update', false)
                ->where('correctiveActions.0.can.progress', false)
            );

        $this->actingAs($responsible)
            ->get("/incidents/{$incident->id}?tab=capa")
            ->assertInertia(fn ($page) => $page
                ->where('correctiveActions.0.can.update', false)
                ->where('correctiveActions.0.can.progress', true)
            );
    }

    public function test_a_corrective_action_cannot_be_assigned_to_an_inactive_tdh_user(): void
    {
        $incident = $this->incidentReadyForCapa();
        $head = $this->headOf($incident);
        $retired = User::factory()->inactive()->create();

        $this->actingAs($head)->post("/incidents/{$incident->id}/corrective-actions", [
            'description' => 'x', 'action_type' => 'corrective', 'priority' => 'high',
            'due_date' => now()->addDays(14)->toDateString(),
            'responsible_user_id' => $retired->id,
        ])->assertSessionHasErrors(['responsible_user_id']);
    }

    public function test_a_corrective_action_cannot_be_assigned_to_a_placeholder_section(): void
    {
        $incident = $this->incidentReadyForCapa();
        $head = $this->headOf($incident);
        $placeholder = Department::factory()->create(['description' => '-']);

        $this->actingAs($head)->post("/incidents/{$incident->id}/corrective-actions", [
            'description' => 'x', 'action_type' => 'corrective', 'priority' => 'high',
            'due_date' => now()->addDays(14)->toDateString(),
            'responsible_department_id' => $placeholder->id,
        ])->assertSessionHasErrors(['responsible_department_id']);
    }

    public function test_updating_may_keep_a_responsible_user_who_has_since_been_deactivated(): void
    {
        $incident = $this->incidentReadyForCapa();
        $responsible = User::factory()->create();
        $capa = app(CorrectiveActionService::class)->create($incident, $this->capaData(['responsible_user_id' => $responsible->id]));
        $head = $this->headOf($incident);
        $retired = User::factory()->inactive()->create();
        $responsible->forceFill(['status' => '0'])->save();

        $payload = [
            'description' => 'Revised.', 'action_type' => 'corrective', 'priority' => 'high',
            'due_date' => now()->addDays(7)->toDateString(),
        ];

        $this->actingAs($head)->patch("/corrective-actions/{$capa->id}", $payload + ['responsible_user_id' => $responsible->id])
            ->assertSessionHasNoErrors();
        $this->actingAs($head)->patch("/corrective-actions/{$capa->id}", $payload + ['responsible_user_id' => $retired->id])
            ->assertSessionHasErrors(['responsible_user_id']);
    }

    private function capaPayload(array $extra = []): array
    {
        return array_merge([
            'description' => 'Implement double-check checklist.',
            'action_type' => 'corrective',
            'priority' => 'high',
            'due_date' => now()->addDays(14)->toDateString(),
        ], $extra);
    }

    private function completedCapa(Incident $incident): CorrectiveAction
    {
        $capa = app(CorrectiveActionService::class)->create($incident, $this->capaData());
        $this->actingAs(User::factory()->create(['department_id' => $incident->department_id]));
        app(CorrectiveActionService::class)->complete($capa, CompleteCorrectiveActionData::fromArray(['completion_notes' => 'Done.']));

        return $capa->fresh();
    }

    public function test_the_department_head_of_the_incidents_department_can_create_and_edit_a_corrective_action_via_http(): void
    {
        $incident = $this->incidentReadyForCapa();
        $head = User::factory()->headOf($incident->department_id)->create(['department_id' => $incident->department_id]);

        $this->actingAs($head)->post("/incidents/{$incident->id}/corrective-actions", $this->capaPayload())
            ->assertSessionHasNoErrors()->assertRedirect();
        $capa = CorrectiveAction::where('incident_id', $incident->id)->sole();

        $this->actingAs($head)->patch("/corrective-actions/{$capa->id}", $this->capaPayload(['description' => 'Revised.']))
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('Revised.', $capa->fresh()->description);
    }

    public function test_a_department_head_of_another_department_cannot_create_or_edit_a_corrective_action(): void
    {
        $incident = $this->incidentReadyForCapa();
        $capa = app(CorrectiveActionService::class)->create($incident, $this->capaData());
        $otherHead = User::factory()->headOf($headDept = Department::factory()->create())->create(['department_id' => $headDept->id]);

        $this->actingAs($otherHead)->post("/incidents/{$incident->id}/corrective-actions", $this->capaPayload())
            ->assertForbidden();
        $this->actingAs($otherHead)->patch("/corrective-actions/{$capa->id}", $this->capaPayload(['description' => 'Revised.']))
            ->assertForbidden();
    }

    public function test_a_supervisor_of_the_incidents_department_cannot_create_or_edit_a_corrective_action(): void
    {
        $incident = $this->incidentReadyForCapa();
        $capa = app(CorrectiveActionService::class)->create($incident, $this->capaData());
        $supervisor = User::factory()->create(['role' => Role::Supervisor, 'department_id' => $incident->department_id]);

        $this->assertFalse($supervisor->can('create', [CorrectiveAction::class, $incident]));
        $this->assertFalse($supervisor->can('update', $capa));
    }

    public function test_the_responsible_user_must_be_active_staff_of_the_incidents_department(): void
    {
        $incident = $this->incidentReadyForCapa();
        $head = $this->headOf($incident);
        $outsider = User::factory()->create(['department_id' => Department::factory()->create()->id]);
        $colleague = User::factory()->create(['department_id' => $incident->department_id]);

        $this->actingAs($head)->post("/incidents/{$incident->id}/corrective-actions", $this->capaPayload(['responsible_user_id' => $outsider->id]))
            ->assertSessionHasErrors(['responsible_user_id']);
        $this->actingAs($head)->post("/incidents/{$incident->id}/corrective-actions", $this->capaPayload(['responsible_user_id' => $colleague->id]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('corrective_actions', ['incident_id' => $incident->id, 'responsible_user_id' => $colleague->id]);
        $this->assertDatabaseMissing('corrective_actions', ['responsible_user_id' => $outsider->id]);
    }

    public function test_updating_rejects_a_new_responsible_user_from_another_department(): void
    {
        $incident = $this->incidentReadyForCapa();
        $capa = app(CorrectiveActionService::class)->create($incident, $this->capaData());
        $head = $this->headOf($incident);
        $outsider = User::factory()->create(['department_id' => Department::factory()->create()->id]);
        $colleague = User::factory()->create(['department_id' => $incident->department_id]);

        $this->actingAs($head)->patch("/corrective-actions/{$capa->id}", $this->capaPayload(['responsible_user_id' => $outsider->id]))
            ->assertSessionHasErrors(['responsible_user_id']);
        $this->actingAs($head)->patch("/corrective-actions/{$capa->id}", $this->capaPayload(['responsible_user_id' => $colleague->id]))
            ->assertSessionHasNoErrors();
        $this->assertSame($colleague->id, $capa->fresh()->responsible_user_id);
    }

    public function test_a_supervisor_or_department_head_of_the_incidents_department_can_verify(): void
    {
        $incident = $this->incidentReadyForCapa();
        $capa = $this->completedCapa($incident);
        $supervisor = User::factory()->create(['role' => Role::Supervisor, 'department_id' => $incident->department_id]);
        $head = User::factory()->headOf($incident->department_id)->create(['department_id' => $incident->department_id]);

        $this->assertTrue($supervisor->can('verify', $capa));
        $this->assertTrue($head->can('verify', $capa));

        $this->actingAs($supervisor)->post("/corrective-actions/{$capa->id}/verify", ['verification_comments' => 'Confirmed.'])
            ->assertRedirect();
        $this->assertSame(CorrectiveActionStatus::Verified, $capa->fresh()->status);
    }

    public function test_a_supervisor_or_department_head_of_another_department_cannot_verify(): void
    {
        $incident = $this->incidentReadyForCapa();
        $capa = $this->completedCapa($incident);
        $otherDepartment = Department::factory()->create()->id;
        $supervisor = User::factory()->create(['role' => Role::Supervisor, 'department_id' => $otherDepartment]);
        $head = User::factory()->headOf($otherDepartment)->create(['department_id' => $otherDepartment]);

        $this->assertFalse($supervisor->can('verify', $capa));
        $this->assertFalse($head->can('verify', $capa));
        $this->actingAs($supervisor)->post("/corrective-actions/{$capa->id}/verify", ['verification_comments' => 'x'])
            ->assertForbidden();
    }

    public function test_quality_staff_cannot_create_edit_or_verify_corrective_actions(): void
    {
        $incident = $this->incidentReadyForCapa();
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $admin = User::factory()->create(['role' => Role::Administrator]);
        $open = app(CorrectiveActionService::class)->create($incident, $this->capaData());
        $completed = $this->completedCapa($incident->fresh());

        foreach ([$qso, $admin] as $user) {
            $this->assertFalse($user->can('create', [CorrectiveAction::class, $incident]));
            $this->assertFalse($user->can('update', $open));
            $this->assertFalse($user->can('verify', $completed));
        }

        $this->actingAs($qso)->patch("/corrective-actions/{$open->id}", $this->capaPayload(['description' => 'Revised.']))
            ->assertForbidden();
        $this->actingAs($admin)->post("/corrective-actions/{$completed->id}/verify", ['verification_comments' => 'x'])
            ->assertForbidden();
    }

    public function test_quality_staff_cannot_progress_or_complete_someone_elses_corrective_action_via_http(): void
    {
        $incident = $this->incidentReadyForCapa();
        $responsible = User::factory()->create(['department_id' => $incident->department_id]);
        $capa = app(CorrectiveActionService::class)->create($incident, $this->capaData(['responsible_user_id' => $responsible->id]));
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);

        $this->actingAs($qso)->post("/corrective-actions/{$capa->id}/progress")->assertForbidden();
        $this->actingAs($qso)->post("/corrective-actions/{$capa->id}/complete", ['completion_notes' => 'Done.'])->assertForbidden();
        $this->assertSame(CorrectiveActionStatus::Open, $capa->fresh()->status);
    }

    public function test_the_completer_cannot_verify_even_as_department_head_of_the_incidents_department(): void
    {
        $incident = $this->incidentReadyForCapa();
        $head = User::factory()->headOf($incident->department_id)->create(['department_id' => $incident->department_id]);
        $capa = app(CorrectiveActionService::class)->create($incident, $this->capaData(['responsible_user_id' => $head->id]));
        $this->actingAs($head)->post("/corrective-actions/{$capa->id}/complete", ['completion_notes' => 'Done.']);

        $this->assertFalse($head->can('verify', $capa->fresh()));
    }
}
