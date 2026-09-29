<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Department;
use App\Models\SafetyAlert;
use App\Models\User;
use App\Notifications\SafetyAlertNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SafetyAlertTest extends TestCase
{
    use RefreshDatabase;

    private function cqi(): User
    {
        return User::factory()->create(['role' => Role::QualitySafetyOfficer]);
    }

    private function issue(array $data = []): SafetyAlert
    {
        $this->actingAs($this->cqi())->post('/safety-alerts', array_merge([
            'title' => 'Wrong-site IV line',
            'message' => 'Double-check the line before connecting.',
            'urgency' => 'warning',
            'audience' => 'all',
        ], $data))->assertSessionHasNoErrors();

        return SafetyAlert::latest('id')->first();
    }

    public function test_only_the_cqi_office_can_issue_an_alert(): void
    {
        foreach ([Role::Staff, Role::DepartmentHead, Role::Management, Role::Administrator, Role::CqiCommittee] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->post('/safety-alerts', ['title' => 'x', 'message' => 'x', 'urgency' => 'information', 'audience' => 'all'])
                ->assertForbidden();
        }
    }

    public function test_an_alert_to_all_staff_notifies_every_active_user(): void
    {
        Notification::fake();
        $staff = User::factory()->create();
        $inactive = User::factory()->inactive()->create();

        $alert = $this->issue();

        Notification::assertSentTo($staff, SafetyAlertNotification::class);
        Notification::assertNotSentTo($inactive, SafetyAlertNotification::class);
        $this->assertSame('all', $alert->audience);
    }

    public function test_an_alert_to_departments_reaches_only_those_departments(): void
    {
        Notification::fake();
        $icu = Department::factory()->create();
        $inIcu = User::factory()->create(['department_id' => $icu->id]);
        $elsewhere = User::factory()->create(['department_id' => Department::factory()->create()->id]);

        $alert = $this->issue(['audience' => 'departments', 'department_ids' => [$icu->id]]);

        Notification::assertSentTo($inIcu, SafetyAlertNotification::class);
        Notification::assertNotSentTo($elsewhere, SafetyAlertNotification::class);
        $this->actingAs($elsewhere)->get("/safety-alerts/{$alert->id}")->assertForbidden();
        $this->actingAs($inIcu)->get("/safety-alerts/{$alert->id}")->assertOk();
    }

    public function test_departments_are_required_when_sending_to_departments(): void
    {
        $this->actingAs($this->cqi())
            ->post('/safety-alerts', ['title' => 'x', 'message' => 'x', 'urgency' => 'critical', 'audience' => 'departments', 'department_ids' => []])
            ->assertSessionHasErrors('department_ids');
    }

    public function test_a_recipient_sees_the_banner_until_they_acknowledge(): void
    {
        $staff = User::factory()->create();
        $alert = $this->issue();

        $this->actingAs($staff)->get('/')->assertInertia(fn ($page) => $page
            ->where('pendingSafetyAlert.id', $alert->id)
            ->where('queueCounts.safety-alerts', 1));

        $this->actingAs($staff)->post("/safety-alerts/{$alert->id}/acknowledge")->assertRedirect();

        $this->actingAs($staff)->get('/')->assertInertia(fn ($page) => $page
            ->where('pendingSafetyAlert', null)
            ->missing('queueCounts.safety-alerts'));
        $this->actingAs($staff)->post("/safety-alerts/{$alert->id}/acknowledge")->assertForbidden();
    }

    public function test_the_cqi_office_sees_who_has_and_has_not_acknowledged(): void
    {
        $read = User::factory()->create(['fname' => 'Alreadyread', 'lname' => 'Person']);
        $unread = User::factory()->create(['fname' => 'Notyetread', 'lname' => 'Person']);
        $alert = $this->issue();
        $this->actingAs($read)->post("/safety-alerts/{$alert->id}/acknowledge");

        $this->actingAs($this->cqi())->get("/safety-alerts/{$alert->id}")->assertInertia(fn ($page) => $page
            ->component('SafetyAlerts/Show')
            ->where('tracking.acknowledged.0.name', $read->name)
            ->where('tracking.pending', fn ($pending) => collect($pending)->contains($unread->name) && ! collect($pending)->contains($read->name)));

        $this->actingAs($unread)->get("/safety-alerts/{$alert->id}")->assertInertia(fn ($page) => $page->where('tracking', null));
    }
}
