<?php

namespace Tests\Feature\Incidents;

use App\Enums\IncidentStatus;
use App\Enums\Role;
use App\Enums\Severity;
use App\Models\Department;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\User;
use App\Notifications\IncidentReadyForReviewNotification;
use App\Notifications\IncidentReturnedToDepartmentNotification;
use App\Services\IncidentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class DepartmentAssessmentTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();
        $this->department = Department::factory()->create();
    }

    private function submittedIncident(): Incident
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

    private function departmentHead(): User
    {
        return User::factory()->create(['role' => Role::DepartmentHead, 'department_id' => $this->department->id]);
    }

    public function test_save_assessment_stores_actions_recommendations_and_severity(): void
    {
        $incident = $this->submittedIncident();

        app(IncidentService::class)->saveAssessment($incident, [
            'recommendations' => 'Install grab rails.',
            'severity' => Severity::Level3High->value,
            'actions_taken' => [['description' => 'Called the doctor', 'responsible_name' => 'Nurse A']],
        ]);

        $incident->refresh();
        $this->assertSame('Install grab rails.', $incident->recommendations);
        $this->assertSame(Severity::Level3High, $incident->severity);
        $this->assertSame(['Called the doctor'], $incident->actions()->pluck('description')->all());
        $this->assertSame(IncidentStatus::Submitted, $incident->status);
    }

    public function test_complete_assessment_moves_to_for_review_and_records_the_assessor(): void
    {
        Notification::fake();
        $head = $this->departmentHead();
        $supervisor = User::factory()->create(['role' => Role::Supervisor, 'department_id' => $this->department->id]);
        $incident = $this->submittedIncident();
        app(IncidentService::class)->saveAssessment($incident, ['severity' => Severity::Level4CriticalSentinel->value]);

        app(IncidentService::class)->completeAssessment($incident->fresh(), $head);

        $incident->refresh();
        $this->assertSame(IncidentStatus::ForReview, $incident->status);
        $this->assertSame($head->id, $incident->assessed_by);
        $this->assertNotNull($incident->assessed_at);
        $this->assertTrue($incident->is_sentinel_event);
        Notification::assertSentTo([$head, $supervisor], IncidentReadyForReviewNotification::class);
    }

    public function test_return_to_department_moves_back_to_submitted_and_notifies_the_department_head(): void
    {
        Notification::fake();
        $head = $this->departmentHead();
        $reviewer = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $incident = $this->submittedIncident();
        app(IncidentService::class)->saveAssessment($incident, ['severity' => Severity::Level2Moderate->value]);
        app(IncidentService::class)->completeAssessment($incident->fresh(), $head);

        app(IncidentService::class)->returnToDepartment($incident->fresh(), $reviewer, 'Severity looks too low.');

        $this->assertSame(IncidentStatus::Submitted, $incident->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['auditable_id' => $incident->id, 'action' => 'status_changed', 'description' => 'Severity looks too low.']);
        Notification::assertSentTo($head, IncidentReturnedToDepartmentNotification::class);
    }

    private function payload(array $extra = []): array
    {
        return array_merge([
            'recommendations' => 'Install grab rails.',
            'actions_taken' => [['description' => 'Called the doctor']],
        ], $extra);
    }

    public function test_department_staff_can_view_and_save_but_not_set_severity(): void
    {
        $staff = User::factory()->create(['department_id' => $this->department->id]);
        $incident = $this->submittedIncident();

        $this->actingAs($staff)->get("/incidents/{$incident->id}")->assertOk();
        $this->actingAs($staff)
            ->post("/incidents/{$incident->id}/assessment", $this->payload(['severity' => Severity::Level4CriticalSentinel->value]))
            ->assertSessionHasNoErrors();

        $incident->refresh();
        $this->assertSame('Install grab rails.', $incident->recommendations);
        $this->assertNull($incident->severity);
    }

    public function test_staff_of_another_department_cannot_save(): void
    {
        $outsider = User::factory()->create(['department_id' => Department::factory()->create()->id]);
        $incident = $this->submittedIncident();

        $this->actingAs($outsider)->post("/incidents/{$incident->id}/assessment", $this->payload())->assertForbidden();
    }

    public function test_department_staff_lose_view_access_once_assessed(): void
    {
        $staff = User::factory()->create(['department_id' => $this->department->id]);
        $incident = $this->submittedIncident();
        app(IncidentService::class)->saveAssessment($incident, ['severity' => Severity::Level2Moderate->value]);
        app(IncidentService::class)->completeAssessment($incident->fresh(), $this->departmentHead());

        $this->actingAs($staff)->get("/incidents/{$incident->id}")->assertForbidden();
    }

    public function test_department_head_can_complete_with_severity(): void
    {
        $incident = $this->submittedIncident();

        $this->actingAs($this->departmentHead())
            ->post("/incidents/{$incident->id}/assessment/complete", $this->payload(['severity' => Severity::Level3High->value]))
            ->assertRedirect("/incidents/{$incident->id}");

        $this->assertSame(IncidentStatus::ForReview, $incident->fresh()->status);
        $this->assertSame(Severity::Level3High, $incident->fresh()->severity);
    }

    public function test_complete_requires_severity(): void
    {
        $incident = $this->submittedIncident();

        $this->actingAs($this->departmentHead())
            ->post("/incidents/{$incident->id}/assessment/complete", $this->payload())
            ->assertSessionHasErrors('severity');
    }

    public function test_supervisor_and_staff_cannot_complete(): void
    {
        $incident = $this->submittedIncident();

        foreach ([Role::Supervisor, Role::Staff] as $role) {
            $user = User::factory()->create(['role' => $role, 'department_id' => $this->department->id]);
            $this->actingAs($user)
                ->post("/incidents/{$incident->id}/assessment/complete", $this->payload(['severity' => Severity::Level1Low->value]))
                ->assertForbidden();
        }
    }

    public function test_qso_can_complete_and_change_the_department(): void
    {
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $other = Department::factory()->create();
        $incident = $this->submittedIncident();

        $this->actingAs($qso)->post("/incidents/{$incident->id}/assessment/complete", $this->payload([
            'severity' => Severity::Level1Low->value,
            'department_id' => $other->id,
        ]))->assertRedirect();

        $this->assertSame($other->id, $incident->fresh()->department_id);
        $this->assertSame(IncidentStatus::ForReview, $incident->fresh()->status);
    }

    public function test_department_head_can_return_to_the_reporter(): void
    {
        $incident = $this->submittedIncident();

        $this->actingAs($this->departmentHead())
            ->post("/incidents/{$incident->id}/return", ['comments' => 'Please add the time.'])
            ->assertRedirect('/incidents');

        $this->assertSame(IncidentStatus::Draft, $incident->fresh()->status);
    }

    public function test_review_is_only_possible_at_for_review(): void
    {
        $supervisor = User::factory()->create(['role' => Role::Supervisor, 'department_id' => $this->department->id]);
        $incident = $this->submittedIncident();

        $this->actingAs($supervisor)->post("/incidents/{$incident->id}/review")->assertForbidden();

        app(IncidentService::class)->saveAssessment($incident, ['severity' => Severity::Level2Moderate->value]);
        app(IncidentService::class)->completeAssessment($incident->fresh(), $this->departmentHead());

        $this->actingAs($supervisor)->post("/incidents/{$incident->id}/review")->assertRedirect();
        $this->assertSame(IncidentStatus::Reviewed, $incident->fresh()->status);
    }

    public function test_reviewer_can_return_to_the_department(): void
    {
        $supervisor = User::factory()->create(['role' => Role::Supervisor, 'department_id' => $this->department->id]);
        $incident = $this->submittedIncident();
        app(IncidentService::class)->saveAssessment($incident, ['severity' => Severity::Level2Moderate->value]);
        app(IncidentService::class)->completeAssessment($incident->fresh(), $this->departmentHead());

        $this->actingAs($supervisor)
            ->post("/incidents/{$incident->id}/return-to-department", ['comments' => 'Check severity.'])
            ->assertRedirect("/incidents/{$incident->id}");

        $this->assertSame(IncidentStatus::Submitted, $incident->fresh()->status);
    }

    public function test_show_exposes_assessment_abilities(): void
    {
        $incident = $this->submittedIncident();

        $this->actingAs($this->departmentHead())->get("/incidents/{$incident->id}")
            ->assertInertia(fn ($page) => $page
                ->where('can.assess', true)
                ->where('can.completeAssessment', true)
                ->where('can.changeDepartment', false)
                ->where('can.review', false));
    }
}
