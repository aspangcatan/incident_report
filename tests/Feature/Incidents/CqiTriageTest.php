<?php

namespace Tests\Feature\Incidents;

use App\Enums\IncidentStatus;
use App\Enums\Role;
use App\Enums\Severity;
use App\Models\Department;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\User;
use App\Services\IncidentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CqiTriageTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();
        $this->department = Department::factory()->create();
    }

    private function submitted(): Incident
    {
        $incident = app(IncidentService::class)->createDraft(User::factory()->create(), [
            'department_id' => $this->department->id,
            'incident_type_ids' => [IncidentType::factory()->create()->id],
            'occurred_at' => now(),
            'location' => 'Ward 3',
            'summary' => 'Test incident.',
        ]);
        app(IncidentService::class)->submit($incident);

        return $incident->fresh();
    }

    private function assessed(Severity $severity = Severity::Level2Moderate): Incident
    {
        $incident = $this->submitted();
        app(IncidentService::class)->saveAssessment($incident, ['severity' => $severity->value]);
        app(IncidentService::class)->completeAssessment($incident->fresh(), User::factory()->headOf($this->department->id)->create(['department_id' => $this->department->id]));

        return $incident->fresh();
    }

    private function cqi(): User
    {
        return User::factory()->create(['role' => Role::QualitySafetyOfficer]);
    }

    public function test_the_cqi_office_can_change_the_severity_at_triage(): void
    {
        $incident = $this->assessed(Severity::Level2Moderate);

        $this->actingAs($this->cqi())
            ->post("/incidents/{$incident->id}/review", ['severity' => Severity::Level5Sentinel->value, 'comments' => 'Patient died.'])
            ->assertRedirect();

        $incident->refresh();
        $this->assertSame(IncidentStatus::Reviewed, $incident->status);
        $this->assertSame(Severity::Level5Sentinel, $incident->severity);
        $this->assertTrue($incident->is_sentinel_event);
        $this->assertStringContainsString('Severity changed from', $incident->supervisor_comments);
    }

    public function test_triaging_to_critical_is_not_a_sentinel_event(): void
    {
        $incident = $this->assessed(Severity::Level2Moderate);

        $this->actingAs($this->cqi())
            ->post("/incidents/{$incident->id}/review", ['severity' => Severity::Level4Critical->value, 'comments' => 'Life-threatening harm.'])
            ->assertRedirect();

        $incident->refresh();
        $this->assertSame(Severity::Level4Critical, $incident->severity);
        $this->assertFalse($incident->is_sentinel_event);
    }

    public function test_the_cqi_office_can_skip_investigation_for_low_or_moderate_with_a_reason(): void
    {
        $cqi = $this->cqi();
        $incident = $this->assessed(Severity::Level1Low);
        app(IncidentService::class)->markReviewed($incident, $cqi, null);

        $this->actingAs($cqi)->post("/incidents/{$incident->id}/skip-investigation", [])->assertSessionHasErrors('reason');

        $this->actingAs($cqi)
            ->post("/incidents/{$incident->id}/skip-investigation", ['reason' => 'Minor, cause already clear.'])
            ->assertRedirect();

        $incident->refresh();
        $this->assertSame(IncidentStatus::CorrectiveAction, $incident->status);
        $this->assertSame('Minor, cause already clear.', $incident->investigation_skipped_reason);
        $this->assertSame($cqi->id, $incident->investigation_skipped_by);
    }

    public function test_high_or_sentinel_incidents_must_be_investigated(): void
    {
        $cqi = $this->cqi();
        $incident = $this->assessed(Severity::Level3High);
        app(IncidentService::class)->markReviewed($incident, $cqi, null);

        $this->assertFalse($cqi->can('skipInvestigation', $incident->fresh()));
        $this->actingAs($cqi)
            ->post("/incidents/{$incident->id}/skip-investigation", ['reason' => 'x'])
            ->assertForbidden();
    }

    public function test_the_department_cannot_skip_investigation(): void
    {
        $incident = $this->assessed(Severity::Level1Low);
        app(IncidentService::class)->markReviewed($incident, $this->cqi(), null);
        $head = User::factory()->headOf($this->department->id)->create(['department_id' => $this->department->id]);

        $this->assertFalse($head->can('skipInvestigation', $incident->fresh()));
    }

    public function test_a_skipped_incident_goes_to_the_departments_capa_stage(): void
    {
        $incident = $this->assessed(Severity::Level1Low);
        $cqi = $this->cqi();
        app(IncidentService::class)->markReviewed($incident, $cqi, null);
        app(IncidentService::class)->skipInvestigation($incident->fresh(), $cqi, 'Minor.');
        $head = User::factory()->headOf($this->department->id)->create(['department_id' => $this->department->id]);

        $this->assertTrue($head->can('markNoCorrectiveActionNeeded', $incident->fresh()));
        $this->actingAs($head)->get("/incidents/{$incident->id}")->assertInertia(fn ($page) => $page
            ->where('can.createCorrectiveAction', true)
            ->where('incident.investigation_skipped_reason', 'Minor.'));
    }

    public function test_the_focal_person_recommends_an_investigator_during_assessment(): void
    {
        $incident = $this->submitted();
        $focal = User::factory()->create(['role' => Role::Supervisor, 'department_id' => $this->department->id]);
        $colleague = User::factory()->create(['department_id' => $this->department->id]);
        $outsider = User::factory()->create(['department_id' => Department::factory()->create()->id]);

        $this->actingAs($focal)
            ->post("/incidents/{$incident->id}/assessment", ['recommended_investigator_id' => $outsider->id])
            ->assertSessionHasErrors('recommended_investigator_id');

        $this->actingAs($focal)
            ->post("/incidents/{$incident->id}/assessment", ['recommended_investigator_id' => $colleague->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($colleague->id, $incident->fresh()->recommended_investigator_id);
    }

    public function test_plain_staff_cannot_set_the_recommendation(): void
    {
        $incident = $this->submitted();
        $staff = User::factory()->create(['department_id' => $this->department->id]);
        $colleague = User::factory()->create(['department_id' => $this->department->id]);

        $this->actingAs($staff)->post("/incidents/{$incident->id}/assessment", ['recommended_investigator_id' => $colleague->id]);

        $this->assertNull($incident->fresh()->recommended_investigator_id);
    }
}
