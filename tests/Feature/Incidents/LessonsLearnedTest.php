<?php

namespace Tests\Feature\Incidents;

use App\DataTransferObjects\CorrectiveActions\CompleteCorrectiveActionData;
use App\DataTransferObjects\CorrectiveActions\CorrectiveActionData;
use App\DataTransferObjects\CorrectiveActions\VerifyCorrectiveActionData;
use App\Enums\Role;
use App\Enums\Severity;
use App\Models\Department;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\User;
use App\Services\CorrectiveActionService;
use App\Services\IncidentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LessonsLearnedTest extends TestCase
{
    use RefreshDatabase;

    private User $head;

    private User $reporter;

    /** Verified, effectiveness confirmed: ready for the Department Head to request closure. */
    private function readyForClosure(Severity $severity = Severity::Level2Moderate): Incident
    {
        $department = Department::factory()->create();
        $this->head = User::factory()->create(['role' => Role::DepartmentHead, 'department_id' => $department->id]);
        $this->reporter = User::factory()->create(['fname' => 'Secretreporter', 'lname' => 'Hiddenname']);
        $service = app(IncidentService::class);
        $incident = $service->createDraft($this->reporter, [
            'department_id' => $department->id,
            'incident_type_ids' => [IncidentType::factory()->create(['name' => 'Falls'])->id],
            'severity' => $severity->value,
            'occurred_at' => now(),
            'location' => 'Ward 3',
            'summary' => 'Test incident.',
        ]);
        $service->submit($incident);
        $service->completeAssessment($incident->fresh(), $this->head);
        $cqi = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $service->markReviewed($incident->fresh(), $cqi, null);
        $incident->fresh()->forceFill(['status' => \App\Enums\IncidentStatus::CorrectiveAction])->saveQuietly();

        $capa = app(CorrectiveActionService::class)->create($incident->fresh(), CorrectiveActionData::fromArray([
            'description' => 'Fix the rail.', 'action_type' => 'corrective', 'priority' => 'high',
            'due_date' => now()->addDays(7)->toDateString(),
        ]));
        $this->actingAs(User::factory()->create(['department_id' => $department->id]));
        app(CorrectiveActionService::class)->complete($capa, CompleteCorrectiveActionData::fromArray(['completion_notes' => 'Done.']));
        app(CorrectiveActionService::class)->verify($capa->fresh(), $this->head, VerifyCorrectiveActionData::fromArray(['verification_comments' => 'OK.']));
        $incident->fresh()->forceFill(['effectiveness_result' => 'effective'])->saveQuietly();

        return $incident->fresh();
    }

    public function test_requesting_closure_needs_a_lesson(): void
    {
        $incident = $this->readyForClosure();

        $this->actingAs($this->head)->post("/incidents/{$incident->id}/request-approval", [])
            ->assertSessionHasErrors('lessons_learned');
        $this->actingAs($this->head)->post("/incidents/{$incident->id}/request-approval", ['lessons_learned' => 'Check bed rails every shift.'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Check bed rails every shift.', $incident->fresh()->lessons_learned);
        $this->assertNull($incident->fresh()->lessons_published_at);
    }

    public function test_the_cqi_office_finalizes_the_lesson_and_it_is_published_on_closure(): void
    {
        $incident = $this->readyForClosure();
        $this->actingAs($this->head)->post("/incidents/{$incident->id}/request-approval", ['lessons_learned' => 'check rails']);
        $approval = $incident->approvals()->first();

        $this->actingAs(User::factory()->create(['role' => Role::QualitySafetyOfficer]))
            ->post("/approvals/{$approval->id}/approve", ['comments' => 'OK.', 'lessons_learned' => 'Check bed rails at every shift handover.'])
            ->assertSessionHasNoErrors();

        $incident->refresh();
        $this->assertSame('Check bed rails at every shift handover.', $incident->lessons_learned);
        $this->assertNotNull($incident->lessons_published_at);

        $this->actingAs(User::factory()->create())->get('/lessons-learned')->assertInertia(fn ($page) => $page
            ->component('Lessons/Index')
            ->where('lessons.data.0.lesson', 'Check bed rails at every shift handover.')
            ->where('lessons.data.0.types', ['Falls'])
            ->missing('lessons.data.0.reporter'));
        $this->actingAs(User::factory()->create())->get('/lessons-learned')->assertDontSee('Hiddenname');
    }

    public function test_a_high_risk_lesson_is_published_only_after_the_committee_approves(): void
    {
        $incident = $this->readyForClosure(Severity::Level3High);
        $this->actingAs($this->head)->post("/incidents/{$incident->id}/request-approval", ['lessons_learned' => 'Lesson.']);
        $first = $incident->approvals()->first();
        $this->actingAs(User::factory()->create(['role' => Role::QualitySafetyOfficer]))
            ->post("/approvals/{$first->id}/approve", ['comments' => 'OK.']);

        $this->assertNull($incident->fresh()->lessons_published_at);
        $this->actingAs(User::factory()->create())->get('/lessons-learned')->assertInertia(fn ($page) => $page->has('lessons.data', 0));

        $second = $incident->approvals()->latest('id')->first();
        $this->actingAs(User::factory()->create(['role' => Role::CqiCommittee]))
            ->post("/approvals/{$second->id}/approve", ['comments' => 'Agreed.']);

        $this->assertNotNull($incident->fresh()->lessons_published_at);
    }
}
