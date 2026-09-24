<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Test-only replica of the live tdh_user tables this app reads (column
 * names/types mirror tdh_user as inspected 2026-09-24). Built on the `user`
 * connection before every test.
 *
 * Refuses to run unless that connection is in-memory SQLite, so a cached
 * config or a bad phpunit.xml can never create tables in — or let factories
 * write to — the live shared tdh_user database.
 */
final class TdhTestSchema
{
    public static function create(): void
    {
        $name = config('tdh.connection');
        $connection = DB::connection($name);

        if ($connection->getDriverName() !== 'sqlite' || $connection->getDatabaseName() !== ':memory:') {
            throw new RuntimeException(
                "Refusing to build the tdh test schema: connection \"{$name}\" is not in-memory SQLite. "
                . 'Check USER_CONNECTION/USER_DATABASE in phpunit.xml and run `php artisan config:clear`.'
            );
        }

        $schema = Schema::connection($name);

        if ($schema->hasTable('users')) {
            return;
        }

        $schema->create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('fname');
            $table->string('mname')->nullable();
            $table->string('lname');
            $table->string('suffix')->nullable();
            $table->string('title', 100)->nullable();
            $table->string('username')->nullable()->unique();
            $table->integer('designation')->default(0);
            $table->string('other_designation')->nullable();
            $table->integer('division')->default(0);
            $table->integer('section')->default(0);
            $table->string('password')->nullable();
            $table->string('api_token', 100)->nullable();
            $table->string('security_pin')->nullable();
            $table->longText('signature')->nullable();
            $table->longText('picture')->nullable();
            $table->string('status')->default('1');
            $table->string('remember_token', 100)->nullable();
            $table->timestamps();
        });

        $schema->create('user_priv', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->string('syscode', 20);
            $table->string('level', 30);
        });

        $schema->create('section', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('division')->default(0);
            $table->string('description');
            $table->integer('head')->default(0);
            $table->string('code')->default('');
            $table->integer('subsection')->nullable();
            $table->timestamps();
        });

        $schema->create('designation', function (Blueprint $table) {
            $table->increments('id');
            $table->string('description');
            $table->timestamps();
        });
    }
}
