<?php

namespace Tests\Feature\Tdh;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LocalSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_user_and_department_tables_are_gone(): void
    {
        foreach (['users', 'departments', 'password_resets', 'personal_access_tokens'] as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} should no longer exist in incident_report");
        }
    }

    public function test_reference_columns_are_kept(): void
    {
        $this->assertTrue(Schema::hasColumns('incidents', ['reporter_id', 'department_id', 'assigned_investigator_id']));
        $this->assertTrue(Schema::hasColumns('corrective_actions', ['responsible_user_id', 'responsible_department_id']));
    }
}
