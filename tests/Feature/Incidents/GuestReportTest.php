<?php

namespace Tests\Feature\Incidents;

use App\Enums\IncidentStatus;
use App\Models\Department;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Services\IncidentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'incident_type_id' => IncidentType::factory()->create()->id,
            'department_id' => Department::factory()->create()->id,
            'occurred_at' => now()->subHour()->format('Y-m-d H:i'),
            'location' => 'Ward 3, bed 12',
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
}
