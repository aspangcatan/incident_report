<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Enums\Severity;
use App\Models\Department;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\User;
use App\Notifications\SeverityAlertNotification;
use App\Services\IncidentService;
use App\Support\EscalationRecipients;
use App\Support\SeverityAlertSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SeverityAlertRecipientsTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();
        $this->department = Department::factory()->create();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => Role::Administrator]);
    }

    /** Every level present; $overrides replaces whole levels. */
    private function payload(array $overrides = []): array
    {
        return ['recipients' => array_replace(SeverityAlertSettings::all(), $overrides)];
    }

    private function incident(Severity $severity, ?int $departmentId = null): Incident
    {
        $incident = app(IncidentService::class)->createDraft(User::factory()->create(), [
            'department_id' => $departmentId ?? $this->department->id,
            'incident_type_ids' => [IncidentType::factory()->create()->id],
            'occurred_at' => now(),
            'location' => 'Ward 3',
            'summary' => 'Settings test.',
        ]);
        $incident->forceFill(['severity' => $severity])->save();

        return $incident->fresh();
    }

    private function ids($users): array
    {
        return collect($users)->pluck('id')->sort()->values()->all();
    }

    public function test_the_defaults_are_the_clients_matrix(): void
    {
        $this->assertSame([
            'level_1_low' => [],
            'level_2_moderate' => ['department_head', 'quality_safety_officer'],
            'level_3_high' => ['department_head', 'leadership', 'quality_safety_officer'],
            'level_4_critical' => ['leadership', 'quality_safety_officer', 'management'],
            'level_5_sentinel' => ['leadership', 'quality_safety_officer', 'management'],
        ], SeverityAlertSettings::all());
    }

    public function test_only_the_it_admin_can_open_and_save_the_page(): void
    {
        $this->actingAs($this->admin())->get('/admin/severity-alerts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/SeverityAlerts')
                ->has('levels', 5)
                ->has('groups', count(SeverityAlertSettings::options()))
                ->where('recipients.level_2_moderate', ['department_head', 'quality_safety_officer']));

        foreach ([Role::Staff, Role::Supervisor, Role::QualitySafetyOfficer, Role::Management, Role::CqiCommittee] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->get('/admin/severity-alerts')->assertForbidden();
            $this->actingAs($user)->put('/admin/severity-alerts', $this->payload())->assertForbidden();
        }
    }

    public function test_the_it_admin_saves_the_recipients_and_a_blank_level_alerts_nobody(): void
    {
        $this->actingAs($this->admin())
            ->put('/admin/severity-alerts', $this->payload([
                'level_1_low' => ['supervisor'],
                'level_2_moderate' => [],
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(['supervisor'], SeverityAlertSettings::recipientsFor(Severity::Level1Low));
        $this->assertSame([], SeverityAlertSettings::recipientsFor(Severity::Level2Moderate));
        $this->assertSame(['department_head', 'leadership', 'quality_safety_officer'], SeverityAlertSettings::recipientsFor(Severity::Level3High));
        $this->assertSame(5, DB::table('severity_alert_settings')->count());

        User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $this->assertSame([], $this->ids(EscalationRecipients::forSeverity($this->incident(Severity::Level2Moderate))));
    }

    public function test_unknown_groups_and_missing_levels_are_rejected(): void
    {
        $payload = $this->payload(['level_3_high' => ['quality_safety_officer', 'janitors']]);
        unset($payload['recipients']['level_5_sentinel']);

        $this->actingAs($this->admin())
            ->put('/admin/severity-alerts', $payload)
            ->assertSessionHasErrors(['recipients.level_3_high.1', 'recipients.level_5_sentinel']);

        $this->assertSame(0, DB::table('severity_alert_settings')->count());
    }

    public function test_department_groups_reach_only_the_incidents_department(): void
    {
        $other = Department::factory()->create();
        $focal = User::factory()->create(['role' => Role::Supervisor, 'department_id' => $this->department->id]);
        $staff = User::factory()->create(['department_id' => $this->department->id]);
        User::factory()->create(['role' => Role::Supervisor, 'department_id' => $other->id]);
        User::factory()->create(['department_id' => $other->id]);
        User::factory()->create(['role' => Role::Investigator, 'department_id' => $this->department->id]); // not Staff

        SeverityAlertSettings::save(['level_3_high' => ['supervisor', 'staff']]);
        $incident = $this->incident(Severity::Level3High);

        $this->assertSame($this->ids([$focal, $staff]), $this->ids(EscalationRecipients::forSeverity($incident)));
    }

    public function test_hospital_wide_roles_reach_every_active_holder(): void
    {
        $committee = User::factory()->create(['role' => Role::CqiCommittee]);
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        User::factory()->inactive()->create(['role' => Role::CqiCommittee]);

        SeverityAlertSettings::save(['level_1_low' => ['cqi_committee', 'investigator']]);

        $this->assertSame($this->ids([$committee, $investigator]), $this->ids(EscalationRecipients::forSeverity($this->incident(Severity::Level1Low))));
    }

    public function test_an_incident_without_a_department_skips_the_department_groups(): void
    {
        $cqi = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        User::factory()->create(['role' => Role::Supervisor, 'department_id' => $this->department->id]);

        SeverityAlertSettings::save(['level_2_moderate' => ['department_head', 'supervisor', 'staff', 'leadership', 'quality_safety_officer']]);
        $incident = $this->incident(Severity::Level2Moderate);
        $incident->forceFill(['department_id' => null])->save();

        $this->assertSame($this->ids([$cqi]), $this->ids(EscalationRecipients::forSeverity($incident->fresh())));
    }

    public function test_a_stale_saved_key_is_ignored(): void
    {
        DB::table('severity_alert_settings')->insert(['severity' => 'level_2_moderate', 'recipients' => json_encode(['department_head', 'old_role'])]);

        $this->assertSame(['department_head'], SeverityAlertSettings::recipientsFor(Severity::Level2Moderate));
    }

    public function test_the_assessment_alert_follows_the_saved_settings(): void
    {
        $head = User::factory()->headOf($this->department->id)->create(['department_id' => $this->department->id]);
        $committee = User::factory()->create(['role' => Role::CqiCommittee]);
        $cqi = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        SeverityAlertSettings::save(['level_2_moderate' => ['cqi_committee']]);

        Notification::fake();
        $incident = $this->incident(Severity::Level2Moderate);
        app(IncidentService::class)->submit($incident);
        app(IncidentService::class)->completeAssessment($incident->fresh(), $head);

        Notification::assertSentTo($committee, SeverityAlertNotification::class);
        Notification::assertNotSentTo($cqi, SeverityAlertNotification::class);
    }
}
