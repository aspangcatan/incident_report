<?php

namespace Tests\Feature\Incidents;

use App\Enums\Severity;
use App\Models\Department;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\User;
use App\Services\IncidentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuggestedSeverityTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();
        $this->department = Department::factory()->create();
    }

    private function submittedIncident(array $types): Incident
    {
        $incident = app(IncidentService::class)->createDraft(User::factory()->create(), [
            'department_id' => $this->department->id,
            'incident_type_ids' => collect($types)->pluck('id')->all(),
            'occurred_at' => now(),
            'location' => 'Ward 3',
            'summary' => 'Test incident.',
        ]);
        app(IncidentService::class)->submit($incident);

        return $incident->fresh();
    }

    public function test_the_highest_default_among_the_types_is_suggested(): void
    {
        $incident = $this->submittedIncident([
            IncidentType::factory()->create(['name' => 'Fall', 'default_severity' => Severity::Level2Moderate]),
            IncidentType::factory()->create(['name' => 'Medication error', 'default_severity' => Severity::Level3High]),
            IncidentType::factory()->create(['name' => 'Other', 'default_severity' => null]),
        ]);

        $this->assertSame(
            ['severity' => Severity::Level3High, 'type' => 'Medication error'],
            $incident->suggestedSeverity(),
        );
    }

    public function test_nothing_is_suggested_when_no_type_has_a_default(): void
    {
        $incident = $this->submittedIncident([IncidentType::factory()->create(['default_severity' => null])]);

        $this->assertNull($incident->suggestedSeverity());
    }

    public function test_nothing_is_suggested_once_a_severity_is_set(): void
    {
        $incident = $this->submittedIncident([IncidentType::factory()->create(['default_severity' => Severity::Level3High])]);
        $incident->update(['severity' => Severity::Level1Low]);

        $this->assertNull($incident->fresh()->suggestedSeverity());
    }

    public function test_the_suggestion_does_not_set_the_incident_severity(): void
    {
        $incident = $this->submittedIncident([IncidentType::factory()->create(['default_severity' => Severity::Level5Sentinel])]);

        $this->assertNull($incident->severity);
    }

    public function test_the_show_page_passes_the_suggestion(): void
    {
        $incident = $this->submittedIncident([IncidentType::factory()->create(['name' => 'Fall', 'default_severity' => Severity::Level2Moderate])]);
        $head = User::factory()->headOf($this->department->id)->create(['department_id' => $this->department->id]);

        $this->actingAs($head)->get("/incidents/{$incident->id}")
            ->assertInertia(fn ($page) => $page
                ->where('suggestedSeverity.value', 'level_2_moderate')
                ->where('suggestedSeverity.label', 'Moderate')
                ->where('suggestedSeverity.type', 'Fall'));
    }
}
