<?php

namespace Tests\Feature\Tdh;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TdhTestHarnessTest extends TestCase
{
    public function test_the_user_connection_is_an_isolated_in_memory_sqlite_database(): void
    {
        $connection = DB::connection(config('tdh.connection'));

        $this->assertSame('sqlite', $connection->getDriverName());
        $this->assertSame(':memory:', $connection->getDatabaseName());
        $this->assertNotSame(DB::connection()->getPdo(), $connection->getPdo());
    }

    public function test_the_replica_tdh_tables_exist_on_the_user_connection(): void
    {
        $schema = Schema::connection(config('tdh.connection'));

        foreach (['users', 'user_priv', 'section', 'designation'] as $table) {
            $this->assertTrue($schema->hasTable($table), "Missing replica table {$table}");
        }
    }
}
