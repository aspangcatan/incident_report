<?php

namespace Tests\Feature;

use App\Enums\IncidentStatus;
use App\Enums\RecurrenceReviewStatus;
use App\Enums\Role;
use App\Enums\Severity;
use App\Models\Department;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\RecurrenceReview;
use App\Models\User;
use App\Notifications\RecurrenceReviewNotification;
use App\Services\AnalyticsService;
use App\Services\IncidentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RecurrenceReviewTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    private IncidentType $type;

    private User $head;

    private User $cqi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->department = Department::factory()->create();
        $this->type = IncidentType::factory()->create(['name' => 'Falls']);
        $this->head = User::factory()->create(['role' => Role::DepartmentHead, 'department_id' => $this->department->id]);
        $this->cqi = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
    }

    private function incident(): Incident
    {
        $incident = app(IncidentService::class)->createDraft(User::factory()->create(), [
            'department_id' => $this->department->id,
            'incident_type_ids' => [$this->type->id],
            'occurred_at' => now(),
            'location' => 'Ward 3',
            'summary' => 'Patient fell.',
        ]);
        app(IncidentService::class)->submit($incident);

        return $incident->fresh();
    }

    private function open(): RecurrenceReview
    {
        $this->actingAs($this->cqi)->post('/recurrence-reviews', [
            'department_id' => $this->department->id,
            'incident_type_id' => $this->type->id,
            'assigned_to' => $this->head->id,
            'due_date' => now()->addDays(14)->toDateString(),
            'cqi_notes' => 'Three falls near the same bathroom.',
        ])->assertSessionHasNoErrors();

        return RecurrenceReview::latest('id')->first();
    }

    public function test_the_cqi_office_opens_a_review_on_a_pattern_and_the_head_is_notified(): void
    {
        Notification::fake();
        foreach (range(1, 3) as $i) {
            $this->incident();
        }

        $review = $this->open();

        $this->assertSame(RecurrenceReviewStatus::Open, $review->status);
        $this->assertSame(3, $review->incident_count);
        Notification::assertSentTo($this->head, RecurrenceReviewNotification::class);
    }

    public function test_only_the_cqi_office_opens_reviews_and_only_for_the_departments_head_or_focal_person(): void
    {
        $this->actingAs($this->head)->get("/recurrence-reviews/create?department_id={$this->department->id}&incident_type_id={$this->type->id}")->assertForbidden();

        $outsider = User::factory()->create(['role' => Role::DepartmentHead, 'department_id' => Department::factory()->create()->id]);
        $this->actingAs($this->cqi)->post('/recurrence-reviews', [
            'department_id' => $this->department->id,
            'incident_type_id' => $this->type->id,
            'assigned_to' => $outsider->id,
            'due_date' => now()->addDays(14)->toDateString(),
            'cqi_notes' => 'x',
        ])->assertSessionHasErrors('assigned_to');
    }

    public function test_the_assignee_submits_a_fix_and_the_cqi_office_closes_it(): void
    {
        $review = $this->open();

        $this->actingAs($this->cqi)->post("/recurrence-reviews/{$review->id}/submit", ['fix_description' => 'x'])->assertForbidden();
        $this->actingAs($this->head)->post("/recurrence-reviews/{$review->id}/submit", [])->assertSessionHasErrors('fix_description');
        $this->actingAs($this->head)->post("/recurrence-reviews/{$review->id}/submit", ['fix_description' => 'Grab bars installed; night lighting added.'])->assertSessionHasNoErrors();
        $this->assertSame(RecurrenceReviewStatus::Submitted, $review->fresh()->status);

        $this->actingAs($this->head)->post("/recurrence-reviews/{$review->id}/decide", ['decision' => 'close', 'comments' => 'x'])->assertForbidden();
        $this->actingAs($this->cqi)->post("/recurrence-reviews/{$review->id}/decide", ['decision' => 'close', 'comments' => 'Good fix.'])->assertSessionHasNoErrors();

        $review->refresh();
        $this->assertSame(RecurrenceReviewStatus::Closed, $review->status);
        $this->assertSame($this->cqi->id, $review->closed_by);
    }

    public function test_the_cqi_office_can_return_it_for_more_work(): void
    {
        $review = $this->open();
        $this->actingAs($this->head)->post("/recurrence-reviews/{$review->id}/submit", ['fix_description' => 'Reminded staff.']);

        $this->actingAs($this->cqi)->post("/recurrence-reviews/{$review->id}/decide", ['decision' => 'return', 'comments' => 'A reminder is not a system fix.']);

        $this->assertSame(RecurrenceReviewStatus::Open, $review->fresh()->status);
        $this->assertTrue($this->head->can('submit', $review->fresh()));
    }

    public function test_the_analytics_pattern_shows_its_review(): void
    {
        foreach (range(1, 3) as $i) {
            $this->incident();
        }
        $pattern = fn () => collect(app(AnalyticsService::class)->overview($this->cqi)['recurringPatterns'])->first();

        $this->assertNull($pattern()['review']);
        $review = $this->open();
        $this->assertSame('open', $pattern()['review']['status']);

        $review->update(['status' => RecurrenceReviewStatus::Closed, 'closed_at' => now()->subDays(120)]);
        $this->assertNull($pattern()['review'], 'A review closed long ago no longer covers a pattern that is back');
    }

    public function test_reviews_are_visible_to_the_department_and_oversight_but_not_other_departments(): void
    {
        $review = $this->open();
        $otherHead = User::factory()->create(['role' => Role::DepartmentHead, 'department_id' => Department::factory()->create()->id]);

        $this->actingAs($this->head)->get("/recurrence-reviews/{$review->id}")->assertOk();
        $this->actingAs(User::factory()->create(['role' => Role::CqiCommittee]))->get("/recurrence-reviews/{$review->id}")->assertOk();
        $this->actingAs($otherHead)->get("/recurrence-reviews/{$review->id}")->assertForbidden();
    }

    public function test_an_incident_page_lists_similar_closed_incidents_with_their_lessons(): void
    {
        $past = $this->incident();
        $past->forceFill(['status' => IncidentStatus::Closed, 'closed_at' => now(), 'lessons_learned' => 'Add grab bars.', 'lessons_published_at' => now(), 'severity' => Severity::Level1Low])->saveQuietly();
        $current = $this->incident();

        $this->actingAs($this->head)->get("/incidents/{$current->id}")->assertInertia(fn ($page) => $page
            ->has('similarIncidents', 1)
            ->where('similarIncidents.0.lesson', 'Add grab bars.'));
    }
}
