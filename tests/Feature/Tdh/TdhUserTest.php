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

        $this->assertSame(Role::Staff, $user->fresh()->role);
    }

    public function test_an_unrecognised_level_falls_back_to_staff_and_logs_a_warning(): void
    {
        Log::spy();
        $user = User::factory()->create();
        UserPrivilege::create(['user_id' => $user->id, 'syscode' => 'IR', 'level' => 'DH']);

        $this->assertSame(Role::Staff, $user->fresh()->role);
        Log::shouldHaveReceived('warning')->once();
    }

    /**
     * Live user_priv.level is latin1_swedish_ci, so MySQL matches levels
     * case- and trailing-space-insensitively (scopeWithRole, exists rules);
     * the PHP side must resolve the same rows to the same role. The SQLite
     * replica compares case-sensitively, so only the PHP side is tested here —
     * and that is what the policies use.
     */
    public function test_the_level_is_matched_case_and_space_insensitively(): void
    {
        $admin = User::factory()->create();
        UserPrivilege::create(['user_id' => $admin->id, 'syscode' => 'IR', 'level' => 'Administrator ']);
        $investigator = User::factory()->create();
        UserPrivilege::create(['user_id' => $investigator->id, 'syscode' => 'IR', 'level' => 'INVESTIGATOR']);

        $this->assertSame(Role::Administrator, $admin->fresh()->role);
        $this->assertSame(Role::Investigator, $investigator->fresh()->role);
    }

    public function test_the_role_is_resolved_once_per_instance(): void
    {
        Log::spy();
        $user = User::factory()->create();
        UserPrivilege::create(['user_id' => $user->id, 'syscode' => 'IR', 'level' => 'DH']);
        $fresh = $user->fresh();

        $fresh->role;
        $fresh->role;
        $fresh->toArray();

        Log::shouldHaveReceived('warning')->once();
    }

    public function test_reloading_the_privilege_relation_re_resolves_the_role(): void
    {
        $user = User::factory()->create();
        $this->assertSame(Role::Staff, $user->role);

        UserPrivilege::create(['user_id' => $user->id, 'syscode' => 'IR', 'level' => 'investigator']);
        $user->unsetRelation('privilege');
        $this->assertSame(Role::Investigator, $user->role);

        $user->load('privilege');
        $this->assertSame(Role::Investigator, $user->role);
    }

    public function test_the_factory_role_is_visible_on_the_created_instance(): void
    {
        $user = User::factory()->create();
        $user->role; // resolve (and memoize) Staff before any row exists
        $investigator = User::factory()->create(['role' => Role::Investigator]);

        $this->assertSame(Role::Staff, $user->role);
        $this->assertSame(Role::Investigator, $investigator->role);
    }

    public function test_the_staff_scope_ignores_null_user_ids_in_the_privilege_subquery(): void
    {
        $sql = User::withRole(Role::Staff)->toSql();

        $this->assertStringContainsString('"user_id" is not null', $sql);
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

    public function test_name_skips_placeholder_parts_from_tdh_data(): void
    {
        $dashMiddle = User::factory()->create(['fname' => 'Juan', 'mname' => '-', 'lname' => 'Cruz', 'suffix' => 'N/A']);
        $junk = User::factory()->create(['title' => '.', 'fname' => ' Maria', 'mname' => 'none', 'lname' => 'Santos ', 'suffix' => '--']);

        $this->assertSame('Juan Cruz', $dashMiddle->fresh()->name);
        $this->assertSame('Maria Santos', $junk->fresh()->name);
    }

    public function test_a_non_honorific_title_is_rendered_as_post_nominals(): void
    {
        $user = User::factory()->create(['title' => 'MD, FPCHA', 'fname' => 'Juan', 'mname' => 'Dela', 'lname' => 'Cruz', 'suffix' => null]);

        $this->assertSame('Juan D. Cruz, MD, FPCHA', $user->fresh()->name);
    }

    public function test_order_by_name_ignores_leading_spaces(): void
    {
        $b = User::factory()->create(['fname' => 'Ana', 'lname' => 'Bautista']);
        $a = User::factory()->create(['fname' => 'Ana', 'lname' => ' Abad']);
        $c = User::factory()->create(['fname' => ' Carlo', 'lname' => 'Bautista']);

        $this->assertSame([$a->id, $b->id, $c->id], User::orderByName()->pluck('id')->all());
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
            ['id', 'name', 'role', 'department_id', 'designation_title'],
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
