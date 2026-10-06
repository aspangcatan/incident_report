<?php

namespace Tests\Feature\Incidents;

use App\Enums\IncidentStatus;
use App\Enums\Role;
use App\Enums\Severity;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\User;
use App\Services\IncidentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SentinelPathwayTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->department = Department::factory()->create();
    }

    private function assessed(Severity $severity): Incident
    {
        $incident = app(IncidentService::class)->createDraft(User::factory()->create(), [
            'department_id' => $this->department->id,
            'incident_type_ids' => [IncidentType::factory()->create()->id],
            'occurred_at' => now(),
            'location' => 'ICU',
            'summary' => 'Sentinel pathway test.',
        ]);
        app(IncidentService::class)->submit($incident);
        app(IncidentService::class)->saveAssessment($incident->fresh(), ['severity' => $severity->value]);
        app(IncidentService::class)->completeAssessment($incident->fresh(), $this->departmentHead());

        return $incident->fresh();
    }

    private function departmentHead(): User
    {
        return User::factory()->headOf($this->department->id)->create(['department_id' => $this->department->id]);
    }

    private function focalPerson(): User
    {
        return User::factory()->create(['role' => Role::Supervisor, 'department_id' => $this->department->id]);
    }

    public function test_the_department_head_confirms_evidence_preserved(): void
    {
        $incident = $this->assessed(Severity::Level5Sentinel);
        $head = $this->departmentHead();

        $this->actingAs($head)->post("/incidents/{$incident->id}/evidence-preserved")->assertRedirect();

        $incident->refresh();
        $this->assertNotNull($incident->evidence_preserved_at);
        $this->assertSame($head->id, $incident->evidence_preserved_by);
        $this->assertTrue(AuditLog::where('auditable_id', $incident->id)->where('action', 'evidence_preserved')->exists());
    }

    public function test_the_focal_person_can_confirm(): void
    {
        $incident = $this->assessed(Severity::Level5Sentinel);

        $this->actingAs($this->focalPerson())->post("/incidents/{$incident->id}/evidence-preserved")->assertRedirect();

        $this->assertNotNull($incident->fresh()->evidence_preserved_at);
    }

    public function test_others_cannot_confirm(): void
    {
        $incident = $this->assessed(Severity::Level5Sentinel);
        $others = [
            User::factory()->create(['department_id' => $this->department->id]), // staff
            User::factory()->headOf($headDept = Department::factory()->create())->create(['department_id' => $headDept->id]),
            User::factory()->create(['role' => Role::QualitySafetyOfficer]),
        ];

        foreach ($others as $user) {
            $this->actingAs($user)->post("/incidents/{$incident->id}/evidence-preserved")->assertForbidden();
        }
        $this->assertNull($incident->fresh()->evidence_preserved_at);
    }

    public function test_only_sentinel_incidents_and_only_once(): void
    {
        $critical = $this->assessed(Severity::Level4Critical);
        $this->actingAs($this->departmentHead())->post("/incidents/{$critical->id}/evidence-preserved")->assertForbidden();

        $sentinel = $this->assessed(Severity::Level5Sentinel);
        $this->actingAs($this->departmentHead())->post("/incidents/{$sentinel->id}/evidence-preserved")->assertRedirect();
        $this->actingAs($this->departmentHead())->post("/incidents/{$sentinel->id}/evidence-preserved")->assertForbidden();
    }

    public function test_a_closed_incident_cannot_be_confirmed(): void
    {
        $incident = $this->assessed(Severity::Level5Sentinel);
        $incident->forceFill(['status' => IncidentStatus::Closed])->save();

        $this->actingAs($this->departmentHead())->post("/incidents/{$incident->id}/evidence-preserved")->assertForbidden();
    }

    public function test_the_incident_page_exposes_the_pathway_data(): void
    {
        $incident = $this->assessed(Severity::Level5Sentinel);
        $head = $this->departmentHead();

        $this->actingAs($head)->get("/incidents/{$incident->id}")
            ->assertInertia(fn (Assert $page) => $page->where('can.confirmEvidencePreserved', true));

        $this->actingAs($head)->post("/incidents/{$incident->id}/evidence-preserved");

        $this->actingAs($head)->get("/incidents/{$incident->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.confirmEvidencePreserved', false)
                ->where('incident.evidence_preserved_by.id', $head->id));
    }
}
