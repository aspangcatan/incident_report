<?php

namespace Tests\Feature\Incidents;

use App\Enums\Role;
use App\Models\Department;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidentTypesAndInjuryTest extends TestCase
{
    use RefreshDatabase;

    private function report(array $extra = []): array
    {
        return array_merge([
            'action' => 'submit',
            'department_id' => Department::factory()->create()->id,
            'incident_type_ids' => [IncidentType::factory()->create()->id],
            'occurred_at' => now()->subHour()->toDateTimeString(),
            'location' => 'Ward 3',
            'has_injury' => false,
            'summary' => 'Patient slipped near the sink.',
            'legal_attestation' => true,
        ], $extra);
    }

    private function reporter(): User
    {
        return User::factory()->create(['role' => Role::Staff]);
    }

    public function test_a_report_can_have_several_types_and_an_others_text(): void
    {
        $fall = IncidentType::factory()->create();
        $equipment = IncidentType::factory()->create();

        $this->actingAs($this->reporter())->post('/incidents', $this->report([
            'incident_type_ids' => [$fall->id, $equipment->id],
            'incident_type_other' => 'Wet floor sign missing',
        ]))->assertSessionHasNoErrors();

        $incident = Incident::first();
        $this->assertEqualsCanonicalizing([$fall->id, $equipment->id], $incident->incidentTypes->pluck('id')->all());
        $this->assertSame('Wet floor sign missing', $incident->incident_type_other);
    }

    public function test_others_alone_is_enough_but_nothing_at_all_is_not(): void
    {
        $this->actingAs($this->reporter())
            ->post('/incidents', $this->report(['incident_type_ids' => [], 'incident_type_other' => 'Power outage']))
            ->assertSessionHasNoErrors();

        $this->actingAs($this->reporter())
            ->post('/incidents', $this->report(['incident_type_ids' => [], 'incident_type_other' => '']))
            ->assertSessionHasErrors('incident_type_ids');
    }

    public function test_an_injury_needs_a_cause_and_an_agent(): void
    {
        $this->actingAs($this->reporter())
            ->post('/incidents', $this->report(['has_injury' => true]))
            ->assertSessionHasErrors(['injury_causes', 'injury_agents']);

        $this->actingAs($this->reporter())
            ->post('/incidents', $this->report(['has_injury' => true, 'injury_causes' => ['not_a_cause'], 'injury_agent_other' => 'Loose tile']))
            ->assertSessionHasErrors('injury_causes.0')
            ->assertSessionDoesntHaveErrors('injury_agents');
    }

    public function test_injury_details_are_saved(): void
    {
        $this->actingAs($this->reporter())->post('/incidents', $this->report([
            'has_injury' => true,
            'injury_causes' => ['slip_trip_fall', 'struck_against'],
            'injury_agents' => ['floor_stairs_walkway'],
            'injury_agent_other' => 'Wet mop',
        ]))->assertSessionHasNoErrors();

        $incident = Incident::first();
        $this->assertTrue($incident->has_injury);
        $this->assertSame(['slip_trip_fall', 'struck_against'], $incident->injury_causes);
        $this->assertSame(['floor_stairs_walkway'], $incident->injury_agents);
        $this->assertSame('Wet mop', $incident->injury_agent_other);
    }

    public function test_answering_no_injury_clears_cause_and_agent(): void
    {
        $reporter = $this->reporter();
        $this->actingAs($reporter)->post('/incidents', $this->report([
            'action' => 'draft', 'has_injury' => true, 'injury_causes' => ['burn_scald'], 'injury_cause_other' => 'Steam',
        ]));
        $incident = Incident::first();

        $this->actingAs($reporter)->patch("/incidents/{$incident->id}", $this->report(['action' => 'draft', 'has_injury' => false, 'injury_causes' => ['burn_scald']]));

        $incident->refresh();
        $this->assertFalse($incident->has_injury);
        $this->assertNull($incident->injury_causes);
        $this->assertNull($incident->injury_cause_other);
    }

    public function test_a_guest_report_saves_types_and_injury_details(): void
    {
        $type = IncidentType::factory()->create();

        $this->post('/report', [
            'guest_name' => 'Maria Santos',
            'guest_contact' => '0917 123 4567',
            'guest_relationship' => 'visitor',
            'incident_type_ids' => [$type->id],
            'incident_type_other' => 'Broken chair',
            'occurred_at' => now()->subHour()->format('Y-m-d H:i'),
            'location' => 'Lobby',
            'has_injury' => true,
            'injury_causes' => ['fall_from_height'],
            'injury_agents' => ['furniture_fixtures'],
            'summary' => 'The chair collapsed.',
            'legal_attestation' => true,
        ])->assertRedirect('/report/submitted');

        $incident = Incident::first();
        $this->assertSame([$type->id], $incident->incidentTypes->pluck('id')->all());
        $this->assertSame('Broken chair', $incident->incident_type_other);
        $this->assertSame(['fall_from_height'], $incident->injury_causes);
        $this->assertSame(['furniture_fixtures'], $incident->injury_agents);
    }

    public function test_the_forms_receive_the_injury_choices(): void
    {
        $this->get('/report')->assertInertia(fn ($page) => $page
            ->where('injuryOptions.causes.needlestick', 'Needlestick / sharps injury')
            ->where('injuryOptions.agents.vehicle', 'Vehicle'));

        $this->actingAs($this->reporter())->get('/incidents/create')->assertInertia(fn ($page) => $page
            ->has('injuryOptions.causes')
            ->has('injuryOptions.agents'));
    }
}
