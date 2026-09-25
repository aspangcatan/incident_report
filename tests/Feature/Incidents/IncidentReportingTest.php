<?php

namespace Tests\Feature\Incidents;

use App\Enums\IncidentStatus;
use App\Enums\Role;
use App\Enums\Severity;
use App\Models\Attachment;
use App\Models\ContributingFactor;
use App\Models\Department;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\User;
use App\Services\IncidentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class IncidentReportingTest extends TestCase
{
    use RefreshDatabase;

    private function makeReporter(): User
    {
        return User::factory()->create();
    }

    public function test_create_draft_creates_an_incident_owned_by_the_reporter(): void
    {
        $reporter = $this->makeReporter();
        $service = app(IncidentService::class);

        $incident = $service->createDraft($reporter, [
            'location' => 'Emergency Department, Bay 3',
        ]);

        $this->assertDatabaseHas('incidents', [
            'id' => $incident->id,
            'reporter_id' => $reporter->id,
            'status' => IncidentStatus::Draft->value,
            'location' => 'Emergency Department, Bay 3',
        ]);
        $this->assertNull($incident->incident_number);
    }

    public function test_submit_assigns_a_sequential_incident_number_and_changes_status(): void
    {
        $reporter = $this->makeReporter();
        $department = Department::factory()->create();
        $incidentType = IncidentType::factory()->create();
        $service = app(IncidentService::class);

        $first = $service->createDraft($reporter, [
            'department_id' => $department->id,
            'incident_type_id' => $incidentType->id,
            'severity' => Severity::Level2Moderate->value,
            'occurred_at' => now(),
            'location' => 'Ward 3',
            'summary' => 'Patient fall near the nurses station.',
        ]);
        $service->submit($first);

        $second = $service->createDraft($reporter, [
            'department_id' => $department->id,
            'incident_type_id' => $incidentType->id,
            'severity' => Severity::Level1Low->value,
            'occurred_at' => now(),
            'location' => 'Ward 4',
            'summary' => 'Near miss with medication cart.',
        ]);
        $service->submit($second);

        $year = now()->year;
        $this->assertSame("IR-{$year}-000001", $first->fresh()->incident_number);
        $this->assertSame("IR-{$year}-000002", $second->fresh()->incident_number);
        $this->assertSame(IncidentStatus::Submitted, $second->fresh()->status);
        $this->assertNotNull($second->fresh()->reported_at);
    }

    public function test_submit_does_not_set_is_sentinel_event(): void
    {
        // Severity is unknown at submission time; it's set later by the
        // Department Head when completing the assessment (see Task 2).
        $reporter = $this->makeReporter();
        $service = app(IncidentService::class);

        $incident = $service->createDraft($reporter, [
            'occurred_at' => now(),
            'location' => 'ICU Bed 2',
            'summary' => 'Sentinel event.',
        ]);
        $service->submit($incident);

        $this->assertFalse($incident->fresh()->is_sentinel_event);
    }

    public function test_submit_retries_incident_number_generation_on_unique_constraint_collision(): void
    {
        $reporter = $this->makeReporter();
        $year = now()->year;

        // Bypass the service to plant an incident that already holds the
        // number the service's first (colliding) attempt will try to reuse.
        $existing = new Incident(['location' => 'Ward 1']);
        $existing->reporter_id = $reporter->id;
        $existing->status = IncidentStatus::Submitted;
        $existing->incident_number = "IR-{$year}-000001";
        $existing->save();

        $service = $this->partialMock(IncidentService::class, function ($mock) use ($year) {
            $mock->shouldAllowMockingProtectedMethods();
            $mock->shouldReceive('generateIncidentNumber')
                ->twice()
                ->andReturn("IR-{$year}-000001", "IR-{$year}-000002");
        });

        $draft = $service->createDraft($reporter, [
            'severity' => Severity::Level1Low->value,
            'occurred_at' => now(),
            'location' => 'Ward 2',
            'summary' => 'Near miss with medication cart.',
        ]);

        $service->submit($draft);

        $this->assertSame("IR-{$year}-000002", $draft->fresh()->incident_number);
        $this->assertSame(IncidentStatus::Submitted, $draft->fresh()->status);
    }

    public function test_owner_can_view_and_update_their_own_draft(): void
    {
        $reporter = $this->makeReporter();
        $incident = app(IncidentService::class)->createDraft($reporter, ['location' => 'ER']);

        $this->assertTrue($reporter->can('view', $incident));
        $this->assertTrue($reporter->can('update', $incident));
    }

    public function test_other_staff_cannot_view_or_update_someone_elses_draft(): void
    {
        $reporter = $this->makeReporter();
        $other = $this->makeReporter();
        $incident = app(IncidentService::class)->createDraft($reporter, ['location' => 'ER']);

        $this->assertFalse($other->can('view', $incident));
        $this->assertFalse($other->can('update', $incident));
    }

    public function test_owner_cannot_update_after_submission(): void
    {
        $reporter = $this->makeReporter();
        $incident = app(IncidentService::class)->createDraft($reporter, [
            'occurred_at' => now(), 'location' => 'ER', 'summary' => 'x',
        ]);
        app(IncidentService::class)->submit($incident);

        $this->assertFalse($reporter->can('update', $incident->fresh()));
        $this->assertTrue($reporter->can('view', $incident->fresh()));
    }

    public function test_supervisor_cannot_view_someone_elses_draft(): void
    {
        $reporter = $this->makeReporter();
        $supervisor = User::factory()->create(['role' => \App\Enums\Role::Supervisor]);

        $draft = app(IncidentService::class)->createDraft($reporter, ['location' => 'ER']);
        $this->assertFalse($supervisor->can('view', $draft));
    }

    public function test_supervisor_can_view_submitted_incident_from_their_own_department(): void
    {
        $reporter = $this->makeReporter();
        $department = Department::factory()->create();
        $supervisor = User::factory()->create([
            'role' => \App\Enums\Role::Supervisor,
            'department_id' => $department->id,
        ]);

        $submitted = app(IncidentService::class)->createDraft($reporter, [
            'department_id' => $department->id,
            'occurred_at' => now(), 'location' => 'ER', 'summary' => 'x',
        ]);
        app(IncidentService::class)->submit($submitted);

        $this->assertTrue($supervisor->can('view', $submitted->fresh()));
    }

    public function test_supervisor_cannot_view_submitted_incident_from_a_different_department(): void
    {
        $reporter = $this->makeReporter();
        $incidentDepartment = Department::factory()->create();
        $supervisorDepartment = Department::factory()->create();
        $supervisor = User::factory()->create([
            'role' => \App\Enums\Role::Supervisor,
            'department_id' => $supervisorDepartment->id,
        ]);

        $submitted = app(IncidentService::class)->createDraft($reporter, [
            'department_id' => $incidentDepartment->id,
            'occurred_at' => now(), 'location' => 'ER', 'summary' => 'x',
        ]);
        app(IncidentService::class)->submit($submitted);

        $this->assertFalse($supervisor->can('view', $submitted->fresh()));
    }

    public function test_supervisor_with_no_department_cannot_view_any_submitted_incident(): void
    {
        $reporter = $this->makeReporter();
        $department = Department::factory()->create();
        $supervisor = User::factory()->create(['role' => \App\Enums\Role::Supervisor]);

        $this->assertNull($supervisor->department_id);

        $submitted = app(IncidentService::class)->createDraft($reporter, [
            'department_id' => $department->id,
            'occurred_at' => now(), 'location' => 'ER', 'summary' => 'x',
        ]);
        app(IncidentService::class)->submit($submitted);

        $this->assertFalse($supervisor->can('view', $submitted->fresh()));
    }

    public function test_supervisor_with_no_department_cannot_view_an_incident_with_no_department_either(): void
    {
        $reporter = $this->makeReporter();
        $supervisor = User::factory()->create(['role' => \App\Enums\Role::Supervisor]);

        $this->assertNull($supervisor->department_id);

        $submitted = app(IncidentService::class)->createDraft($reporter, [
            'occurred_at' => now(), 'location' => 'ER', 'summary' => 'x',
        ]);
        app(IncidentService::class)->submit($submitted);

        $this->assertNull($submitted->fresh()->department_id);
        $this->assertFalse($supervisor->can('view', $submitted->fresh()));
    }

    public function test_quality_safety_officer_can_view_submitted_incidents_from_any_department(): void
    {
        $reporter = $this->makeReporter();
        $incidentDepartment = Department::factory()->create();
        $qsoDepartment = Department::factory()->create();
        $qso = User::factory()->create([
            'role' => \App\Enums\Role::QualitySafetyOfficer,
            'department_id' => $qsoDepartment->id,
        ]);

        $submitted = app(IncidentService::class)->createDraft($reporter, [
            'department_id' => $incidentDepartment->id,
            'occurred_at' => now(), 'location' => 'ER', 'summary' => 'x',
        ]);
        app(IncidentService::class)->submit($submitted);

        $this->assertNotEquals($qso->department_id, $submitted->fresh()->department_id);
        $this->assertTrue($qso->can('view', $submitted->fresh()));
    }

    public function test_a_staff_member_can_save_a_draft_via_http(): void
    {
        $reporter = $this->makeReporter();

        $response = $this->actingAs($reporter)->post('/incidents', [
            'action' => 'draft',
            'location' => 'ER Bay 1',
        ]);

        $incident = Incident::first();
        $response->assertRedirect("/incidents/{$incident->id}/edit");
        $this->assertSame('ER Bay 1', $incident->location);
        $this->assertTrue($incident->status->isDraft());
    }

    public function test_submitting_without_required_fields_fails_validation(): void
    {
        $reporter = $this->makeReporter();

        $response = $this->actingAs($reporter)->post('/incidents', [
            'action' => 'submit',
        ]);

        $response->assertSessionHasErrors(['incident_type_id', 'department_id', 'occurred_at', 'location', 'summary', 'legal_attestation']);
        $this->assertDatabaseCount('incidents', 0);
    }

    public function test_a_staff_member_can_submit_a_complete_report_via_http(): void
    {
        $reporter = $this->makeReporter();
        $department = Department::factory()->create();
        $incidentType = IncidentType::factory()->create();

        $response = $this->actingAs($reporter)->post('/incidents', [
            'action' => 'submit',
            'department_id' => $department->id,
            'incident_type_id' => $incidentType->id,
            'severity' => Severity::Level2Moderate->value,
            'occurred_at' => now()->toDateTimeString(),
            'location' => 'ICU',
            'summary' => 'Full incident summary.',
            'legal_attestation' => true,
        ]);

        $incident = Incident::first();
        $response->assertRedirect("/incidents/{$incident->id}");
        $this->assertSame(IncidentStatus::Submitted, $incident->fresh()->status);
        $this->assertNotNull($incident->fresh()->incident_number);
    }

    public function test_a_user_cannot_update_someone_elses_draft_via_http(): void
    {
        $reporter = $this->makeReporter();
        $other = $this->makeReporter();
        $incident = app(IncidentService::class)->createDraft($reporter, ['location' => 'ER']);

        $this->actingAs($other)
            ->patch("/incidents/{$incident->id}", ['action' => 'draft', 'location' => 'hacked'])
            ->assertForbidden();
    }

    public function test_incident_index_scopes_to_the_current_users_reports_by_default(): void
    {
        $reporter = $this->makeReporter();
        $other = $this->makeReporter();
        app(IncidentService::class)->createDraft($reporter, ['location' => 'mine']);
        app(IncidentService::class)->createDraft($other, ['location' => 'not mine']);

        $response = $this->actingAs($reporter)->get('/incidents?scope=drafts');

        $response->assertInertia(fn ($page) => $page
            ->component('Incidents/Index')
            ->has('incidents.data', 1)
        );
    }

    public function test_scope_all_only_shows_a_supervisor_incidents_from_their_own_department(): void
    {
        $reporter = $this->makeReporter();
        $departmentA = Department::factory()->create();
        $departmentB = Department::factory()->create();
        $supervisor = User::factory()->create([
            'role' => Role::Supervisor,
            'department_id' => $departmentA->id,
        ]);

        $inDepartmentA = app(IncidentService::class)->createDraft($reporter, [
            'department_id' => $departmentA->id,
            'occurred_at' => now(), 'location' => 'ER', 'summary' => 'department A incident',
        ]);
        app(IncidentService::class)->submit($inDepartmentA);

        $inDepartmentB = app(IncidentService::class)->createDraft($reporter, [
            'department_id' => $departmentB->id,
            'occurred_at' => now(), 'location' => 'ER', 'summary' => 'department B incident',
        ]);
        app(IncidentService::class)->submit($inDepartmentB);

        $response = $this->actingAs($supervisor)->get('/incidents?scope=all');

        $response->assertInertia(fn ($page) => $page
            ->component('Incidents/Index')
            ->has('incidents.data', 1)
            ->where('incidents.data.0.id', $inDepartmentA->id)
        );
    }

    public function test_scope_all_excludes_null_department_incidents_for_a_null_department_supervisor(): void
    {
        $reporter = $this->makeReporter();
        $supervisor = User::factory()->create(['role' => Role::Supervisor]);

        $this->assertNull($supervisor->department_id);

        $submitted = app(IncidentService::class)->createDraft($reporter, [
            'occurred_at' => now(), 'location' => 'ER', 'summary' => 'no department incident',
        ]);
        app(IncidentService::class)->submit($submitted);

        $this->assertNull($submitted->fresh()->department_id);

        $response = $this->actingAs($supervisor)->get('/incidents?scope=all');

        $response->assertInertia(fn ($page) => $page
            ->component('Incidents/Index')
            ->has('incidents.data', 0)
        );
    }

    public function test_scope_all_shows_a_quality_safety_officer_incidents_from_every_department(): void
    {
        $reporter = $this->makeReporter();
        $departmentA = Department::factory()->create();
        $departmentB = Department::factory()->create();
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);

        $inDepartmentA = app(IncidentService::class)->createDraft($reporter, [
            'department_id' => $departmentA->id,
            'occurred_at' => now(), 'location' => 'ER', 'summary' => 'department A incident',
        ]);
        app(IncidentService::class)->submit($inDepartmentA);

        $inDepartmentB = app(IncidentService::class)->createDraft($reporter, [
            'department_id' => $departmentB->id,
            'occurred_at' => now(), 'location' => 'ER', 'summary' => 'department B incident',
        ]);
        app(IncidentService::class)->submit($inDepartmentB);

        $response = $this->actingAs($qso)->get('/incidents?scope=all');

        $response->assertInertia(fn ($page) => $page
            ->component('Incidents/Index')
            ->has('incidents.data', 2)
        );
    }

    public function test_show_renders_a_fully_populated_submitted_incident(): void
    {
        $reporter = $this->makeReporter();
        $department = Department::factory()->create();
        $incidentType = IncidentType::factory()->create();
        $factor = ContributingFactor::create(['label' => 'Fatigue', 'category' => 'Human Factors', 'is_active' => true]);

        $incident = app(IncidentService::class)->createDraft($reporter, [
            'department_id' => $department->id,
            'incident_type_id' => $incidentType->id,
            'severity' => Severity::Level2Moderate->value,
            'occurred_at' => now(),
            'location' => 'ICU Bed 4',
            'summary' => 'Full incident summary.',
            'recommendations' => 'Review staffing levels.',
            'police_notified' => true,
            'police_station' => 'Central Police Station',
            'police_officer_in_charge' => 'PO1 Santos',
            'police_blotter_no' => 'BLT-001',
            'police_notified_at' => now(),
            'individuals' => [
                ['person_type' => 'patient', 'name' => 'Juan Dela Cruz', 'role_description' => 'Patient involved'],
            ],
            'witnesses' => [
                ['name' => 'Maria Clara', 'statement' => 'Saw the incident happen.'],
            ],
            'actions_taken' => [
                ['description' => 'Notified attending physician.', 'responsible_name' => 'Nurse Reyes', 'status' => 'completed'],
            ],
            'narrative_events' => [
                ['occurred_at' => '08:00', 'description' => 'Patient found on the floor.'],
            ],
            'contributing_factor_ids' => [$factor->id],
        ]);
        app(IncidentService::class)->submit($incident);

        $response = $this->actingAs($reporter)->get("/incidents/{$incident->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Incidents/Show')
            ->where('incident.id', $incident->id)
            ->has('incident.individuals', 1)
            ->has('incident.witnesses', 1)
            ->has('incident.actions', 1)
            ->has('incident.narrative_events', 1)
            ->has('incident.contributing_factors', 1)
            ->where('incident.police_notified', true)
            ->where('incident.police_station', 'Central Police Station')
        );
    }

    public function test_a_staff_member_can_upload_and_download_an_attachment_via_http(): void
    {
        Storage::fake('local');
        $reporter = $this->makeReporter();
        $department = Department::factory()->create();
        $incidentType = IncidentType::factory()->create();

        $response = $this->actingAs($reporter)->post('/incidents', [
            'action' => 'submit',
            'department_id' => $department->id,
            'incident_type_id' => $incidentType->id,
            'severity' => Severity::Level2Moderate->value,
            'occurred_at' => now()->toDateTimeString(),
            'location' => 'ICU',
            'summary' => 'Full incident summary.',
            'legal_attestation' => true,
            'attachments' => [UploadedFile::fake()->create('evidence.pdf', 100, 'application/pdf')],
        ]);

        $incident = Incident::first();
        $response->assertRedirect("/incidents/{$incident->id}");

        $attachment = Attachment::first();
        $this->assertNotNull($attachment);
        $this->assertSame($incident->id, $attachment->attachable_id);
        $this->assertSame(Incident::class, $attachment->attachable_type);

        $this->actingAs($reporter)
            ->get("/attachments/{$attachment->id}")
            ->assertOk();
    }

    public function test_a_user_cannot_download_someone_elses_attachment(): void
    {
        Storage::fake('local');
        $reporter = $this->makeReporter();
        $other = $this->makeReporter();
        $department = Department::factory()->create();
        $incidentType = IncidentType::factory()->create();

        $this->actingAs($reporter)->post('/incidents', [
            'action' => 'submit',
            'department_id' => $department->id,
            'incident_type_id' => $incidentType->id,
            'severity' => Severity::Level2Moderate->value,
            'occurred_at' => now()->toDateTimeString(),
            'location' => 'ICU',
            'summary' => 'Full incident summary.',
            'legal_attestation' => true,
            'attachments' => [UploadedFile::fake()->create('evidence.pdf', 100, 'application/pdf')],
        ]);

        $attachment = Attachment::first();

        $this->actingAs($other)
            ->get("/attachments/{$attachment->id}")
            ->assertForbidden();
    }

    public function test_an_incident_cannot_be_filed_against_a_placeholder_section(): void
    {
        $reporter = $this->makeReporter();
        $placeholder = Department::factory()->create(['description' => '-']);

        $this->actingAs($reporter)->post('/incidents', [
            'action' => 'draft',
            'department_id' => $placeholder->id,
            'individuals' => [['person_type' => 'patient', 'name' => 'X', 'department_id' => $placeholder->id]],
        ])->assertSessionHasErrors(['department_id', 'individuals.0.department_id']);
    }

    public function test_the_reporter_can_submit_without_a_severity(): void
    {
        $reporter = $this->makeReporter();
        $department = Department::factory()->create();
        $type = IncidentType::factory()->create();

        $this->actingAs($reporter)->post('/incidents', [
            'action' => 'submit',
            'department_id' => $department->id,
            'incident_type_id' => $type->id,
            'occurred_at' => now()->subHour()->format('Y-m-d H:i'),
            'location' => 'Ward 3',
            'summary' => 'Patient slipped.',
            'legal_attestation' => true,
        ])->assertSessionHasNoErrors();

        $incident = Incident::latest('id')->first();
        $this->assertSame(IncidentStatus::Submitted, $incident->status);
        $this->assertNull($incident->severity);
        $this->assertFalse($incident->is_sentinel_event);
    }

    public function test_the_reporter_form_ignores_severity_actions_recommendations_and_factors(): void
    {
        $reporter = $this->makeReporter();
        $department = Department::factory()->create();
        $type = IncidentType::factory()->create();
        $factor = ContributingFactor::create(['label' => 'Fatigue', 'category' => 'Human Factors', 'is_active' => true]);

        $this->actingAs($reporter)->post('/incidents', [
            'action' => 'draft',
            'department_id' => $department->id,
            'incident_type_id' => $type->id,
            'severity' => Severity::Level4CriticalSentinel->value,
            'recommendations' => 'Install rails.',
            'actions_taken' => [['description' => 'Called doctor']],
            'contributing_factor_ids' => [$factor->id],
        ]);

        $incident = Incident::latest('id')->first();
        $this->assertNull($incident->severity);
        $this->assertNull($incident->recommendations);
        $this->assertSame(0, $incident->actions()->count());
        $this->assertSame(0, $incident->contributingFactors()->count());
    }

    public function test_updating_a_returned_draft_keeps_department_entered_actions(): void
    {
        $reporter = $this->makeReporter();
        $incident = app(IncidentService::class)->createDraft($reporter, ['location' => 'ER']);
        $incident->actions()->create(['description' => 'Entered by the department']);

        $this->actingAs($reporter)->patch("/incidents/{$incident->id}", [
            'action' => 'draft',
            'location' => 'ER bay 2',
        ]);

        $this->assertSame(['Entered by the department'], $incident->actions()->pluck('description')->all());
    }
}
