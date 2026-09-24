<?php

namespace Tests\Feature\Tdh;

use App\Models\Department;
use Tests\TestCase;

class DepartmentTest extends TestCase
{
    public function test_a_department_is_a_tdh_section(): void
    {
        $department = Department::factory()->create(['name' => 'ER / IER', 'code' => 'ER']);

        $this->assertSame(config('tdh.connection'), $department->getConnectionName());
        $this->assertDatabaseHas('section', ['id' => $department->id, 'description' => 'ER / IER'], config('tdh.connection'));
        $this->assertSame('ER / IER', $department->fresh()->name);
    }

    public function test_serialization_exposes_only_id_name_and_code(): void
    {
        $department = Department::factory()->create(['name' => 'Pharmacy', 'code' => 'PHARMA']);

        $this->assertSame(['id' => $department->id, 'code' => 'PHARMA', 'name' => 'Pharmacy'], $department->fresh()->toArray());
    }

    public function test_options_are_sorted_by_name_and_exclude_placeholder_sections(): void
    {
        Department::factory()->create(['name' => 'Radiology and Imaging']);
        Department::factory()->create(['name' => '-']);
        Department::factory()->create(['name' => 'Anesthesia']);

        $this->assertSame(
            ['Anesthesia', 'Radiology and Imaging'],
            Department::options()->pluck('name')->all()
        );
        $this->assertSame(['id', 'name'], array_keys(Department::options()->first()));
    }
}
