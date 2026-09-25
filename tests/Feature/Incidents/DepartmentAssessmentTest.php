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
            'incident_type_id' => IncidentType::factory()->create()->id,
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
}
