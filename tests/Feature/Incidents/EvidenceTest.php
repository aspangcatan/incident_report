<?php

namespace Tests\Feature\Incidents;

use App\DataTransferObjects\CorrectiveActions\CorrectiveActionData;
use App\DataTransferObjects\Investigations\StartInvestigationData;
use App\Enums\EvidenceStage;
use App\Enums\IncidentStatus;
use App\Enums\Role;
use App\Enums\Severity;
use App\Models\Attachment;
use App\Models\Department;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\User;
use App\Services\CorrectiveActionService;
use App\Services\IncidentService;
use App\Services\InvestigationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EvidenceTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->department = Department::factory()->create();
    }

    private function submitted(): Incident
    {
        $incident = app(IncidentService::class)->createDraft(User::factory()->create(), [
            'department_id' => $this->department->id,
            'incident_type_ids' => [IncidentType::factory()->create()->id],
            'severity' => Severity::Level2Moderate->value,
            'occurred_at' => now(),
            'location' => 'Ward 3',
            'summary' => 'Test.',
        ]);
        app(IncidentService::class)->submit($incident);

        return $incident->fresh();
    }

    private function photo(): UploadedFile
    {
        return UploadedFile::fake()->image('scene.jpg');
    }

    public function test_department_staff_add_evidence_during_assessment(): void
    {
        $incident = $this->submitted();
        $staff = User::factory()->create(['department_id' => $this->department->id]);

        $this->actingAs($staff)->post("/incidents/{$incident->id}/evidence", ['files' => [$this->photo()]])->assertSessionHasNoErrors();

        $file = Attachment::first();
        $this->assertSame(EvidenceStage::Assessment, $file->stage);
        $this->assertSame($staff->id, $file->uploaded_by);
        $this->assertSame($incident->id, $file->attachable_id);
    }

    public function test_outsiders_and_later_stages_cannot_add_department_evidence(): void
    {
        $incident = $this->submitted();
        $outsider = User::factory()->create(['department_id' => Department::factory()->create()->id]);
        $this->actingAs($outsider)->post("/incidents/{$incident->id}/evidence", ['files' => [$this->photo()]])->assertForbidden();

        $head = User::factory()->headOf($this->department->id)->create(['department_id' => $this->department->id]);
        app(IncidentService::class)->saveAssessment($incident, ['severity' => Severity::Level2Moderate->value]);
        app(IncidentService::class)->completeAssessment($incident->fresh(), $head);

        $this->actingAs($head)->post("/incidents/{$incident->id}/evidence", ['files' => [$this->photo()]])->assertForbidden();
    }

    public function test_the_investigation_team_adds_evidence_during_the_investigation(): void
    {
        $incident = $this->submitted();
        $cqi = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $investigator = User::factory()->create(['department_id' => $this->department->id]);
        app(IncidentService::class)->markReviewed($incident->fresh(), $cqi, null);
        app(IncidentService::class)->assignInvestigator($incident->fresh(), $investigator);
        app(InvestigationService::class)->start($incident->fresh(), $investigator, StartInvestigationData::fromArray(['objective' => 'Why.']));

        $this->actingAs($cqi)->post("/incidents/{$incident->id}/evidence", ['files' => [$this->photo()]])->assertForbidden();
        $this->actingAs($investigator)->post("/incidents/{$incident->id}/evidence", ['files' => [UploadedFile::fake()->create('statement.pdf', 50, 'application/pdf')]])->assertSessionHasNoErrors();

        $this->assertSame(EvidenceStage::Investigation, Attachment::first()->stage);
    }

    public function test_wrong_file_types_and_empty_uploads_are_refused(): void
    {
        $incident = $this->submitted();
        $staff = User::factory()->create(['department_id' => $this->department->id]);

        $this->actingAs($staff)->post("/incidents/{$incident->id}/evidence", [])->assertSessionHasErrors('files');
        $this->actingAs($staff)->post("/incidents/{$incident->id}/evidence", ['files' => [UploadedFile::fake()->create('virus.exe', 10)]])->assertSessionHasErrors('files.0');
    }

    public function test_capa_completion_can_include_proof_linked_to_the_capa(): void
    {
        $incident = $this->submitted();
        $incident->forceFill(['status' => IncidentStatus::CorrectiveAction])->saveQuietly();
        $owner = User::factory()->create(['department_id' => $this->department->id]);
        $capa = app(CorrectiveActionService::class)->create($incident->fresh(), CorrectiveActionData::fromArray([
            'description' => 'Put up signage.', 'action_type' => 'corrective', 'priority' => 'low',
            'due_date' => now()->addDays(3)->toDateString(), 'responsible_user_id' => $owner->id,
        ]));
        $this->actingAs($owner)->post("/corrective-actions/{$capa->id}/progress");

        $this->actingAs($owner)->post("/corrective-actions/{$capa->id}/complete", [
            'completion_notes' => 'Signage installed.',
            'attachments' => [UploadedFile::fake()->image('signage.png')],
        ])->assertSessionHasNoErrors();

        $file = Attachment::first();
        $this->assertSame(EvidenceStage::Capa, $file->stage);
        $this->assertSame($capa->id, $file->corrective_action_id);
        $this->assertSame($incident->id, $file->attachable_id);
    }

    public function test_the_incident_page_shows_each_files_stage_and_uploader(): void
    {
        $incident = $this->submitted();
        $staff = User::factory()->create(['department_id' => $this->department->id]);
        $this->actingAs($staff)->post("/incidents/{$incident->id}/evidence", ['files' => [$this->photo()]]);

        $this->actingAs($staff)->get("/incidents/{$incident->id}")->assertInertia(fn ($page) => $page
            ->where('incident.attachments.0.stage', 'assessment')
            ->where('incident.attachments.0.uploaded_by.name', $staff->name)
            ->where('can.addEvidence', true)
            ->where('evidenceStage', 'Department Assessment'));
    }
}
