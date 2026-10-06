<?php

namespace Tests\Feature\Incidents;

use App\Enums\IncidentStatus;
use App\Enums\Role;
use App\Models\Department;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\User;
use App\Notifications\IncidentSubmittedNotification;
use App\Services\IncidentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class GuestReportTest extends TestCase
{
    use RefreshDatabase;

    private function data(array $extra = []): array
    {
        return array_merge([
            'guest_name' => 'Maria Santos',
            'guest_contact' => '0917 123 4567',
            'guest_relationship' => 'relative',
            'incident_type_ids' => [IncidentType::factory()->create()->id],
            'department_id' => Department::factory()->create()->id,
            'occurred_at' => now()->subHour()->format('Y-m-d H:i'),
            'location' => 'Ward 3, bed 12',
            'has_injury' => false,
            'summary' => 'My mother fell while walking to the bathroom.',
        ], $extra);
    }

    public function test_the_service_submits_a_guest_report_without_a_reporter(): void
    {
        $incident = app(IncidentService::class)->submitGuestReport($this->data());

        $incident->refresh();
        $this->assertNull($incident->reporter_id);
        $this->assertSame(IncidentStatus::Submitted, $incident->status);
        $this->assertNotNull($incident->incident_number);
        $this->assertNotNull($incident->legal_attestation_at);
        $this->assertSame('Maria Santos', $incident->guest_name);
        $this->assertSame('relative', $incident->guest_relationship);
        $this->assertTrue($incident->isGuestReport());
    }

    private function post_(array $extra = [])
    {
        return $this->post('/report', array_merge($this->data(), ['legal_attestation' => true], $extra));
    }

    public function test_the_report_page_is_public(): void
    {
        $this->get('/report')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Guest/Report')
            ->has('incidentTypes')
            ->has('departments'));
    }

    public function test_a_guest_can_submit_and_sees_the_reference_number(): void
    {
        $response = $this->post_();

        $incident = Incident::latest('id')->first();
        $response->assertRedirect('/report/submitted');
        $this->assertSame(IncidentStatus::Submitted, $incident->status);
        $this->assertNull($incident->reporter_id);

        $this->get('/report/submitted')->assertInertia(fn ($page) => $page
            ->component('Guest/Submitted')
            ->where('reference', $incident->incident_number));
    }

    public function test_the_submitted_page_without_a_reference_goes_back_to_the_form(): void
    {
        $this->get('/report/submitted')->assertRedirect('/report');
    }

    public function test_the_department_is_optional_and_then_qso_admin_are_notified(): void
    {
        Notification::fake();
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);

        $this->post_(['department_id' => null])->assertRedirect('/report/submitted');

        $this->assertNull(Incident::latest('id')->first()->department_id);
        Notification::assertSentTo($qso, IncidentSubmittedNotification::class);
    }

    public function test_validation_rules(): void
    {
        // More than 3 POSTs in one test — take the throttle out of the picture here.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $this->post('/report', ['website' => ''])->assertSessionHasErrors([
            'guest_name', 'guest_contact', 'guest_relationship', 'incident_type_ids', 'has_injury',
            'occurred_at', 'location', 'summary', 'legal_attestation',
        ]);
        $this->post_(['guest_relationship' => 'doctor'])->assertSessionHasErrors('guest_relationship');
        $this->post_(['occurred_at' => now()->addDay()->format('Y-m-d H:i')])->assertSessionHasErrors('occurred_at');
        $this->post_(['department_id' => Department::factory()->create(['name' => '-'])->id])->assertSessionHasErrors('department_id');
    }

    public function test_the_honeypot_rejects_bots(): void
    {
        $this->post_(['website' => 'http://spam.example'])->assertSessionHasErrors('website');
        $this->assertSame(0, Incident::count());
    }

    public function test_submissions_are_rate_limited(): void
    {
        foreach (range(1, 3) as $i) {
            $this->post_()->assertRedirect('/report/submitted');
        }

        $this->post_()->assertStatus(429);
    }

    public function test_guest_reports_cannot_be_returned_to_a_reporter(): void
    {
        $head = User::factory()->headOf($dept = Department::factory()->create())->create(['department_id' => $dept->id]);
        $incident = app(IncidentService::class)->submitGuestReport($this->data(['department_id' => $dept->id]));

        $this->assertFalse($head->can('returnToReporter', $incident));
        $this->actingAs($head)->post("/incidents/{$incident->id}/return", ['comments' => 'x'])->assertForbidden();
        $this->actingAs($head)->get("/incidents/{$incident->id}")->assertInertia(fn ($page) => $page
            ->where('can.returnToReporter', false)
            ->where('incident.guest_name', 'Maria Santos'));
    }
}
