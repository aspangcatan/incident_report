<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\Severity;
use App\Models\Department;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\User;
use App\Notifications\ProvisionalSeverityAlertNotification;
use App\Notifications\SeverityAlertNotification;
use App\Services\IncidentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** On submit, a High+ level suggested by the incident type alerts that level's people at once. */
class ProvisionalSeverityAlertTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;
    private User $head;
    private User $assessor;
    private User $cqi;
    private User $leader;
    private User $executive;

    protected function setUp(): void
    {
        parent::setUp();
        $this->department = Department::factory()->create();
        $this->head = User::factory()->headOf($this->department->id)->create(['department_id' => $this->department->id]);
        $this->assessor = User::factory()->create(['role' => Role::Supervisor, 'department_id' => $this->department->id]);
        $this->cqi = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $this->leader = User::factory()->create(['role' => Role::Leadership]);
        DB::table('leadership_departments')->insert(['user_id' => $this->leader->id, 'department_id' => $this->department->id]);
        $this->executive = User::factory()->create(['role' => Role::Management]);
    }

    private function submitted(?Severity $typeDefault, ?User $reporter = null): Incident
    {
        $type = IncidentType::factory()->create(['name' => 'Wrong-site surgery', 'default_severity' => $typeDefault]);
        $service = app(IncidentService::class);
        $incident = $service->createDraft($reporter ?? User::factory()->create(), [
            'department_id' => $this->department->id,
            'incident_type_ids' => [$type->id],
            'occurred_at' => now(),
            'location' => 'OR 2',
            'summary' => 'Provisional alert test.',
        ]);
        $service->submit($incident);

        return $incident->fresh();
    }

    private function assess(Incident $incident, Severity $severity): void
    {
        app(IncidentService::class)->saveAssessment($incident->fresh(), ['severity' => $severity->value]);
        app(IncidentService::class)->completeAssessment($incident->fresh(), $this->assessor);
    }

    public function test_a_sentinel_suggestion_alerts_the_sentinel_recipients_on_submit(): void
    {
        Notification::fake();
        $incident = $this->submitted(Severity::Level5Sentinel);

        Notification::assertSentTo([$this->cqi, $this->leader, $this->executive], ProvisionalSeverityAlertNotification::class,
            fn ($notification, $channels, $notifiable) => str_contains($notification->toDatabase($notifiable)['message'], 'Possible Sentinel incident')
                && str_contains($notification->toDatabase($notifiable)['message'], 'Wrong-site surgery'));
        // Sentinel's default recipients do not include the Department Head.
        Notification::assertNotSentTo($this->head, ProvisionalSeverityAlertNotification::class);
        $this->assertSame('level_5_sentinel', $incident->provisional_alert_severity);
        $this->assertNull($incident->severity, 'The system suggestion never sets the level itself.');
    }

    public function test_moderate_or_no_suggestion_sends_nothing_on_submit(): void
    {
        Notification::fake();
        $this->submitted(Severity::Level2Moderate);
        $this->submitted(null);

        Notification::assertNotSentTo([$this->cqi, $this->leader, $this->executive, $this->head], ProvisionalSeverityAlertNotification::class);
    }

    public function test_the_reporter_is_not_alerted_about_their_own_report(): void
    {
        Notification::fake();
        $this->submitted(Severity::Level4Critical, $this->cqi);

        Notification::assertNotSentTo($this->cqi, ProvisionalSeverityAlertNotification::class);
        Notification::assertSentTo($this->executive, ProvisionalSeverityAlertNotification::class);
    }

    public function test_confirming_the_same_level_does_not_alert_again(): void
    {
        Notification::fake();
        $incident = $this->submitted(Severity::Level3High);
        $this->assess($incident, Severity::Level3High);

        Notification::assertNotSentTo([$this->cqi, $this->leader], SeverityAlertNotification::class);
    }

    public function test_a_different_level_at_assessment_alerts_that_levels_people(): void
    {
        Notification::fake();
        $incident = $this->submitted(Severity::Level3High);
        $this->assess($incident, Severity::Level4Critical);

        Notification::assertSentTo([$this->cqi, $this->leader, $this->executive], SeverityAlertNotification::class);
    }

    public function test_a_resubmitted_report_is_not_alerted_twice_at_the_same_level(): void
    {
        Notification::fake();
        $incident = $this->submitted(Severity::Level3High);
        app(IncidentService::class)->submit($incident->fresh());

        Notification::assertSentToTimes($this->cqi, ProvisionalSeverityAlertNotification::class, 1);
    }
}
