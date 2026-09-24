<?php

namespace Tests\Feature\Tdh;

use App\Enums\Role;
use App\Models\Department;
use App\Models\Designation;
use App\Models\User;
use App\Models\UserPrivilege;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class TdhUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_is_read_from_tdh_user(): void
    {
        $user = User::factory()->create();

        $this->assertSame(config('tdh.connection'), $user->getConnectionName());
        $this->assertDatabaseHas('users', ['id' => $user->id], config('tdh.connection'));
    }

    public function test_no_ir_privilege_row_means_staff(): void
    {
        $user = User::factory()->create();

        $this->assertSame(Role::Staff, $user->fresh()->role);
    }

    public function test_role_comes_from_the_ir_privilege_row_only(): void
    {
        $user = User::factory()->create();
        UserPrivilege::create(['user_id' => $user->id, 'syscode' => 'hris', 'level' => 'administrator']);
        UserPrivilege::create(['user_id' => $user->id, 'syscode' => 'IR', 'level' => 'department_head']);

        $this->assertSame(Role::DepartmentHead, $user->fresh()->role);
    }

    public function test_an_unrecognised_level_falls_back_to_staff_and_logs_a_warning(): void
    {
        Log::spy();
        $user = User::factory()->create();
        UserPrivilege::create(['user_id' => $user->id, 'syscode' => 'IR', 'level' => 'DH']);

        $this->assertSame(Role::Staff, $user->fresh()->role);
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_every_role_value_fits_the_user_priv_level_column(): void
    {
        foreach (Role::cases() as $role) {
            $this->assertLessThanOrEqual(30, strlen($role->value), "{$role->value} exceeds user_priv.level varchar(30)");
        }
    }

    public function test_the_factory_maps_role_and_department_id(): void
    {
        $department = Department::factory()->create();
        $user = User::factory()->create(['role' => Role::Supervisor, 'department_id' => $department->id]);

        $fresh = $user->fresh();
        $this->assertSame(Role::Supervisor, $fresh->role);
        $this->assertSame($department->id, $fresh->department_id);
        $this->assertSame($department->id, $fresh->department->id);
    }

    public function test_no_section_means_no_department(): void
    {
        $user = User::factory()->create(['department_id' => null]);

        $this->assertNull($user->fresh()->department_id);
    }

    public function test_name_is_built_from_the_name_parts(): void
    {
        $user = User::factory()->create([
            'title' => 'Dr.', 'fname' => 'Juan', 'mname' => 'Santos', 'lname' => 'Dela Cruz', 'suffix' => 'Jr.',
        ]);

        $this->assertSame('Dr. Juan S. Dela Cruz Jr.', $user->fresh()->name);
    }

    public function test_designation_title_prefers_the_designation_table(): void
    {
        $designation = Designation::create(['description' => 'Nurse II']);
        $user = User::factory()->create(['designation' => $designation->id, 'other_designation' => 'ignored']);
        $other = User::factory()->create(['designation' => 0, 'other_designation' => 'Job Order Aide']);

        $this->assertSame('Nurse II', $user->fresh()->designation_title);
        $this->assertSame('Job Order Aide', $other->fresh()->designation_title);
    }

    public function test_only_status_1_is_active(): void
    {
        $active = User::factory()->create(['status' => '1']);
        $inactive = User::factory()->create(['status' => '0']);

        $this->assertTrue($active->fresh()->is_active);
        $this->assertFalse($inactive->fresh()->is_active);
        $this->assertSame([$active->id], User::active()->pluck('id')->all());
    }

    public function test_with_role_scope_matches_ir_levels_and_treats_no_row_as_staff(): void
    {
        $staffNoRow = User::factory()->create();
        $staffWithRow = User::factory()->create(['role' => Role::Staff]);
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $hrisAdminOnly = User::factory()->create();
        UserPrivilege::create(['user_id' => $hrisAdminOnly->id, 'syscode' => 'hris', 'level' => 'administrator']);

        $this->assertEqualsCanonicalizing([$investigator->id], User::withRole(Role::Investigator)->pluck('id')->all());
        $this->assertEqualsCanonicalizing(
            [$investigator->id, $qso->id],
            User::withRole([Role::Investigator, 'quality_safety_officer'])->pluck('id')->all()
        );
        $this->assertEqualsCanonicalizing(
            [$staffNoRow->id, $staffWithRow->id, $hrisAdminOnly->id],
            User::withRole(Role::Staff)->pluck('id')->all()
        );
    }

    public function test_serialization_never_exposes_credentials_or_images(): void
    {
        $user = User::factory()->create(['api_token' => 'secret-token', 'security_pin' => '1234', 'picture' => 'base64...']);

        $array = User::find($user->id)->toArray();

        $this->assertEqualsCanonicalizing(
            ['id', 'username', 'name', 'role', 'department_id', 'designation_title'],
            array_keys($array)
        );
    }

    public function test_notifications_live_in_the_incident_report_database(): void
    {
        $user = User::find(User::factory()->create()->id);

        $this->assertSame(0, $user->unreadNotifications()->count());
        $this->assertSame(config('database.default'), $user->notifications()->getRelated()->getConnectionName());
    }
}
