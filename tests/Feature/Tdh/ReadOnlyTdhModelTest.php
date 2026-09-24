<?php

namespace Tests\Feature\Tdh;

use App\Models\Designation;
use App\Models\UserPrivilege;
use LogicException;
use Tests\TestCase;

class ReadOnlyTdhModelTest extends TestCase
{
    public function test_tdh_models_use_the_tdh_connection(): void
    {
        $this->assertSame(config('tdh.connection'), (new UserPrivilege())->getConnectionName());
        $this->assertSame(config('tdh.connection'), (new Designation())->getConnectionName());
    }

    public function test_writes_are_allowed_against_the_test_replica(): void
    {
        $privilege = UserPrivilege::create(['user_id' => 5, 'syscode' => 'IR', 'level' => 'staff']);

        $this->assertDatabaseHas('user_priv', ['id' => $privilege->id, 'level' => 'staff'], config('tdh.connection'));
    }

    public function test_saving_outside_the_testing_environment_throws(): void
    {
        $this->app['env'] = 'production';

        $this->expectException(LogicException::class);

        UserPrivilege::create(['user_id' => 5, 'syscode' => 'IR', 'level' => 'staff']);
    }

    public function test_deleting_outside_the_testing_environment_throws(): void
    {
        $designation = Designation::create(['description' => 'Nurse I']);
        $this->app['env'] = 'production';

        $this->expectException(LogicException::class);

        $designation->delete();
    }

    public function test_for_this_system_scope_ignores_other_systems(): void
    {
        UserPrivilege::create(['user_id' => 5, 'syscode' => 'hris', 'level' => 'admin']);
        UserPrivilege::create(['user_id' => 5, 'syscode' => 'IR', 'level' => 'investigator']);

        $this->assertSame(['investigator'], UserPrivilege::forThisSystem()->pluck('level')->all());
    }
}
