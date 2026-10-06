<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\Severity;
use App\Models\Department;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\User;
use App\Notifications\SeverityAlertNotification;
use App\Services\IncidentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SeverityAlertTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;
    private User $head;
    private User $cqi;
    private User $leader;
    private User $executive;
    private User $committee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->department = Department::factory()->create();
        $this->head = User::factory()->headOf($this->department->id)->create(['department_id' => $this->department->id]);
        $this->cqi = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $this->leader = User::factory()->create(['role' => Role::Leadership]);
        DB::table('leadership_departments')->insert(['user_id' => $this->leader->id, 'department_id' => $this->department->id]);
        $this->executive = User::factory()->create(['role' => Role::Management]);
        $this->committee = User::factory()->create(['role' => Role::CqiCommittee]);
    }

    private function assessedBy(User $assessor, Severity $severity): Incident
    {
        $incident = app(IncidentService::class)->createDraft(User::factory()->create(), [
            'department_id' => $this->department->id,
            'incident_type_ids' => [IncidentType::factory()->create()->id],
            'occurred_at' => now(),
            'location' => 'Ward 3',
            'summary' => 'Alert test.',
        ]);
        app(IncidentService::class)->submit($incident);
        app(IncidentService::class)->saveAssessment($incident->fresh(), ['severity' => $severity->value]);
        app(IncidentService::class)->completeAssessment($incident->fresh(), $assessor);

        return $incident->fresh();
    }

    public function test_a_moderate_assessment_alerts_cqi_but_not_the_head_who_set_it(): void
    {
        Notification::fake();
        $this->assessedBy($this->head, Severity::Level2Moderate);

        Notification::assertSentTo($this->cqi, SeverityAlertNotification::class);
        Notification::assertNotSentTo([$this->head, $this->leader, $this->executive], SeverityAlertNotification::class);
    }

    public function test_a_department_head_who_did_not_set_the_level_is_alerted(): void
    {
        Notification::fake();
        $otherHead = User::factory()->headOf($this->department->id)->create(['department_id' => $this->department->id]);

        $this->assessedBy($this->head, Severity::Level2Moderate);

        Notification::assertSentTo($otherHead, SeverityAlertNotification::class);
        Notification::assertNotSentTo($this->head, SeverityAlertNotification::class);
    }

    public function test_a_high_assessment_alerts_cqi_and_the_mapped_leadership(): void
    {
        Notification::fake();
        $this->assessedBy($this->head, Severity::Level3High);

        Notification::assertSentTo([$this->cqi, $this->leader], SeverityAlertNotification::class);
        Notification::assertNotSentTo([$this->head, $this->executive, $this->committee], SeverityAlertNotification::class);
    }

    public function test_a_sentinel_assessment_alerts_cqi_leadership_and_executives_not_the_committee(): void
    {
        Notification::fake();
        $this->assessedBy($this->head, Severity::Level5Sentinel);

        Notification::assertSentTo([$this->cqi, $this->leader, $this->executive], SeverityAlertNotification::class);
        Notification::assertNotSentTo([$this->head, $this->committee], SeverityAlertNotification::class);
    }

    public function test_a_low_assessment_alerts_nobody(): void
    {
        Notification::fake();
        $this->assessedBy($this->head, Severity::Level1Low);

        // The CQI Office still gets its "ready for triage" notice, just no severity alert.
        Notification::assertNothingSentTo([$this->leader, $this->executive]);
        Notification::assertNotSentTo($this->cqi, SeverityAlertNotification::class);
    }

    public function test_triage_that_keeps_the_level_sends_no_second_alert(): void
    {
        $incident = $this->assessedBy($this->head, Severity::Level3High);
        Notification::fake();

        app(IncidentService::class)->markReviewed($incident, $this->cqi, null, Severity::Level3High);

        Notification::assertNotSentTo([$this->leader, $this->head, $this->executive], SeverityAlertNotification::class);
    }

    public function test_triage_that_changes_the_level_alerts_the_new_levels_people_but_not_the_cqi_actor(): void
    {
        $incident = $this->assessedBy($this->head, Severity::Level2Moderate);
        Notification::fake();

        app(IncidentService::class)->markReviewed($incident, $this->cqi, null, Severity::Level4Critical);

        Notification::assertSentTo([$this->leader, $this->executive], SeverityAlertNotification::class);
        Notification::assertNotSentTo([$this->cqi, $this->head], SeverityAlertNotification::class);
    }

    public function test_the_message_names_the_level(): void
    {
        Notification::fake();
        $incident = $this->assessedBy($this->head, Severity::Level4Critical);

        Notification::assertSentTo($this->cqi, SeverityAlertNotification::class, function ($notification) use ($incident) {
            $data = $notification->toDatabase($this->cqi);

            return $data['incident_id'] === $incident->id && str_contains($data['message'], 'Level IV – Critical');
        });
    }
}
