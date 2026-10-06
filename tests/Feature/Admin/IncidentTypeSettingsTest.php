<?php

namespace Tests\Feature\Admin;

use App\Enums\RecurrenceReviewStatus;
use App\Enums\Role;
use App\Models\Department;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\RecurrenceReview;
use App\Models\User;
use App\Services\IncidentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IncidentTypeSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => Role::Administrator]);
    }

    /** A draft incident that uses $type through the incident_incident_type pivot. */
    private function incidentUsing(IncidentType $type): Incident
    {
        return app(IncidentService::class)->createDraft(User::factory()->create(), [
            'department_id' => Department::factory()->create()->id,
            'incident_type_ids' => [$type->id],
            'occurred_at' => now(),
            'location' => 'Ward 3',
            'summary' => 'Test incident.',
        ]);
    }

    private function recurrenceReviewUsing(IncidentType $type): RecurrenceReview
    {
        $user = User::factory()->create(['role' => Role::QualitySafetyOfficer]);

        return RecurrenceReview::create([
            'department_id' => Department::factory()->create()->id,
            'incident_type_id' => $type->id,
            'incident_count' => 3,
            'status' => RecurrenceReviewStatus::Open,
            'assigned_to' => $user->id,
            'due_date' => now()->addDays(14),
            'cqi_notes' => 'Pattern.',
            'created_by' => $user->id,
        ]);
    }

    public function test_a_new_type_is_not_in_use(): void
    {
        $this->assertFalse(IncidentType::factory()->create()->isInUse());
    }

    public function test_a_type_on_an_incident_is_in_use(): void
    {
        $type = IncidentType::factory()->create();
        $this->incidentUsing($type);

        $this->assertTrue($type->fresh()->isInUse());
    }

    public function test_a_type_on_the_legacy_incident_column_is_in_use(): void
    {
        $type = IncidentType::factory()->create();
        $incident = $this->incidentUsing(IncidentType::factory()->create());
        DB::table('incidents')->where('id', $incident->id)->update(['incident_type_id' => $type->id]);

        $this->assertTrue($type->fresh()->isInUse());
    }

    public function test_a_type_on_a_recurrence_review_is_in_use(): void
    {
        $type = IncidentType::factory()->create();
        $this->recurrenceReviewUsing($type);

        $this->assertTrue($type->fresh()->isInUse());
    }
}
