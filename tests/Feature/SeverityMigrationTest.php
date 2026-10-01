<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\IncidentType;
use App\Models\User;
use App\Services\IncidentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SeverityMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_critical_sentinel_rows_become_sentinel(): void
    {
        $incident = app(IncidentService::class)->createDraft(User::factory()->create(), [
            'department_id' => Department::factory()->create()->id,
            'incident_type_ids' => [IncidentType::factory()->create()->id],
            'occurred_at' => now(),
            'location' => 'ICU',
            'summary' => 'Legacy level.',
        ]);
        $type = IncidentType::factory()->create();
        DB::table('incidents')->where('id', $incident->id)->update(['severity' => 'level_4_critical_sentinel']);
        DB::table('incident_types')->where('id', $type->id)->update(['default_severity' => 'level_4_critical_sentinel']);

        $migration = require database_path('migrations/2026_10_01_000001_split_critical_and_sentinel_severity.php');
        $migration->up();

        $this->assertSame('level_5_sentinel', DB::table('incidents')->where('id', $incident->id)->value('severity'));
        $this->assertSame('level_5_sentinel', DB::table('incident_types')->where('id', $type->id)->value('default_severity'));
    }
}
