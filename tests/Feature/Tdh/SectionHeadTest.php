<?php

namespace Tests\Feature\Tdh;

use App\Enums\Role;
use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SectionHeadTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_heads_the_sections_whose_head_column_is_their_id(): void
    {
        $own = Department::factory()->create();
        [$a, $b] = Department::factory()->count(2)->create();
        $user = User::factory()->headOf($a, $b)->create(['department_id' => $own->id]);

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $user->headedDepartmentIds());
        $this->assertTrue($user->isDepartmentHead());
        $this->assertTrue($user->isHeadOf($a->id));
        $this->assertFalse($user->isHeadOf($own->id));
        $this->assertFalse($user->isHeadOf(null));
    }

    public function test_a_user_who_heads_nothing_is_not_a_department_head(): void
    {
        $user = User::factory()->create();

        $this->assertSame([], $user->headedDepartmentIds());
        $this->assertFalse($user->isDepartmentHead());
    }

    public function test_heading_a_section_keeps_the_ir_level(): void
    {
        $section = Department::factory()->create();
        $user = User::factory()->headOf($section)->create(['role' => Role::Leadership]);

        $this->assertSame(Role::Leadership, $user->role);
        $this->assertTrue($user->isHeadOf($section->id));
    }
}
