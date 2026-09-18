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
        app(InvestigationService::class)->addFinding($investigation, \App\DataTransferObjects\Investigations\FindingData::fromArray([
            'question' => 'Why?', 'finding' => 'Root cause.', 'is_root_cause' => true,
        ]));
        app(InvestigationService::class)->complete($investigation->fresh(), CompleteInvestigationData::fromArray(['conclusion' => 'Done.']));

        return $incident->fresh();
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

    public function test_qso_can_create_a_corrective_action_once_the_incident_is_at_corrective_action_stage(): void
    {
        $incident = $this->incidentReadyForCapa();
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);

        $this->assertTrue($qso->can('create', [\App\Models\CorrectiveAction::class, $incident]));
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
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);

        $incident = app(IncidentService::class)->createDraft($reporter, [
            'department_id' => $department->id,
            'incident_type_id' => $incidentType->id,
            'severity' => Severity::Level2Moderate->value,
            'occurred_at' => now(),
            'location' => 'Ward 3',
            'summary' => 'Test incident.',
        ]);
        app(IncidentService::class)->submit($incident);

        $this->assertFalse($qso->can('create', [\App\Models\CorrectiveAction::class, $incident->fresh()]));
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
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);

        $this->assertFalse($qso->can('create', [\App\Models\CorrectiveAction::class, $incident->fresh()]));
    }

    public function test_only_qso_or_admin_can_update_an_open_corrective_action(): void
    {
        $incident = $this->incidentReadyForCapa();
        $capa = app(CorrectiveActionService::class)->create($incident, $this->capaData());
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $stranger = User::factory()->create();

        $this->assertTrue($qso->can('update', $capa));
        $this->assertFalse($stranger->can('update', $capa));
    }

    public function test_a_corrective_action_can_no_longer_be_updated_once_it_is_for_verification(): void
    {
        $incident = $this->incidentReadyForCapa();
        $capa = app(CorrectiveActionService::class)->create($incident, $this->capaData());
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $this->actingAs(User::factory()->create());
        app(CorrectiveActionService::class)->complete($capa, CompleteCorrectiveActionData::fromArray(['completion_notes' => 'Done.']));

        $this->assertFalse($qso->can('update', $capa->fresh()));
    }

    public function test_only_the_responsible_user_or_qso_can_mark_it_in_progress_or_complete_it(): void
    {
        $incident = $this->incidentReadyForCapa();
        $responsible = User::factory()->create();
        $capa = app(CorrectiveActionService::class)->create($incident, $this->capaData(['responsible_user_id' => $responsible->id]));
        $stranger = User::factory()->create();

        $this->assertTrue($responsible->can('progress', $capa));
        $this->assertTrue($responsible->can('complete', $capa));
        $this->assertFalse($stranger->can('progress', $capa));
        $this->assertFalse($stranger->can('complete', $capa));
    }

    public function test_a_supervisor_can_verify_but_the_person_who_completed_it_cannot_even_if_also_a_supervisor(): void
    {
        $incident = $this->incidentReadyForCapa();
        $capa = app(CorrectiveActionService::class)->create($incident, $this->capaData());
        $completer = User::factory()->create(['role' => Role::Supervisor]);
        $this->actingAs($completer);
        app(CorrectiveActionService::class)->complete($capa, CompleteCorrectiveActionData::fromArray(['completion_notes' => 'Done.']));
        $otherSupervisor = User::factory()->create(['role' => Role::Supervisor]);

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
}
