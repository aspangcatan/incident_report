<?php

namespace Tests\Feature\Tdh;

use App\Models\Designation;
use App\Models\UserPrivilege;
use Illuminate\Support\Facades\DB;
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

    public function test_builder_update_is_blocked_outside_testing(): void
    {
        $privilege = UserPrivilege::create(['user_id' => 5, 'syscode' => 'IR', 'level' => 'staff']);
        $this->app['env'] = 'production';

        try {
            UserPrivilege::where('id', $privilege->id)->update(['level' => 'changed']);
            $this->fail('Expected a LogicException from the builder-level update.');
        } catch (LogicException $e) {
            // expected
        }

        $this->app['env'] = 'testing';
        $this->assertDatabaseHas('user_priv', ['id' => $privilege->id, 'level' => 'staff'], config('tdh.connection'));
    }

    public function test_builder_delete_is_blocked_outside_testing(): void
    {
        $privilege = UserPrivilege::create(['user_id' => 5, 'syscode' => 'IR', 'level' => 'staff']);
        $this->app['env'] = 'production';

        try {
            UserPrivilege::where('id', $privilege->id)->delete();
            $this->fail('Expected a LogicException from the builder-level delete.');
        } catch (LogicException $e) {
            // expected
        }

        $this->app['env'] = 'testing';
        $this->assertDatabaseHas('user_priv', ['id' => $privilege->id, 'level' => 'staff'], config('tdh.connection'));
    }

    public function test_builder_insert_is_blocked_outside_testing(): void
    {
        $this->app['env'] = 'production';

        $this->expectException(LogicException::class);

        Designation::insert(['description' => 'Nurse I']);
    }

    public function test_save_quietly_does_not_bypass_the_guard(): void
    {
        $this->app['env'] = 'production';

        $this->expectException(LogicException::class);

        (new Designation(['description' => 'Nurse I']))->saveQuietly();
    }

    public function test_increment_is_blocked_outside_testing(): void
    {
        $privilege = UserPrivilege::create(['user_id' => 5, 'syscode' => 'IR', 'level' => 'staff']);
        $this->app['env'] = 'production';

        try {
            $privilege->increment('user_id');
            $this->fail('Expected a LogicException from increment().');
        } catch (LogicException $e) {
            // expected
        }

        $this->app['env'] = 'testing';
        $this->assertDatabaseHas('user_priv', ['id' => $privilege->id, 'user_id' => 5], config('tdh.connection'));
    }

    public function test_without_events_does_not_bypass_the_guard(): void
    {
        $this->app['env'] = 'production';

        $this->expectException(LogicException::class);

        UserPrivilege::withoutEvents(function () {
            UserPrivilege::create(['user_id' => 5, 'syscode' => 'IR', 'level' => 'staff']);
        });
    }

    public function test_saving_with_a_non_sqlite_driver_throws_even_in_testing(): void
    {
        config([
            'database.connections.user.driver' => 'mysql',
            'database.connections.user.host' => '0.0.0.0',
            'database.connections.user.port' => '1',
            'database.connections.user.database' => '__nonexistent__',
        ]);
        DB::purge('user');

        $this->expectException(LogicException::class);

        (new UserPrivilege(['user_id' => 1, 'syscode' => 'IR', 'level' => 'staff']))->save();
    }
}
