# tdh_user Live Integration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the app's local `users`/`departments` tables with live, read-only reads of the shared `tdh_user` database (users, user_priv, section, designation) via the existing `user` DB connection.

**Architecture:** `User`, `Department`, `UserPrivilege`, `Designation` Eloquent models point at the `user` connection and refuse writes outside tests. Role comes from `user_priv.level` where `syscode = 'IR'` (default Staff); department = `users.section`. `incident_report` keeps its `*_id` columns but loses the FK constraints and the local user/department tables. Tests run the `user` connection as a separate in-memory SQLite DB with a replica schema.

**Tech Stack:** Laravel 9.52 (PHP 8.2), MySQL 8 (WAMP), Inertia v1 server / @inertiajs/vue3 v2, Vue 3, PHPUnit on SQLite in-memory.

**Spec:** `docs/superpowers/specs/2026-09-24-tdh-user-integration-design.md` — read it first.

---

## ⚠️ Safety rules for every task (read before touching anything)

1. **Never write to the live `tdh_user` database.** No `INSERT/UPDATE/DELETE` against it, no seeders touching it, no tinker writes. (The single approved row insert for user 1 is done by the controller session at browser-verification time, not by any task.)
2. **Never run `migrate:fresh`, `migrate:reset`, `migrate:rollback`, `db:wipe`, or any migration with `--database=user`.** Task 6 runs exactly one forward `php artisan migrate` on the default (`incident_report`) connection.
3. Before running the test suite, run `php artisan config:clear` once — a cached config would bypass `phpunit.xml` and point the `user` connection at live MySQL. (Task 1 adds a guard that aborts the suite if this happens.)
4. Run tests with: `php artisan test` (whole suite) or `php artisan test --filter=ClassName`.
5. Commit after each task with the `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>` trailer.

## File map

| File | Status | Responsibility |
|---|---|---|
| `config/tdh.php` | create | connection name + static syscode `IR` |
| `config/database.php` | modify | `user` connection driver from env, own URL var |
| `phpunit.xml` | modify | `user` → SQLite in-memory; `DB_FOREIGN_KEYS=false` |
| `.env.example` | modify | document `USER_*` vars |
| `tests/Support/TdhTestSchema.php` | create | test-only replica of tdh tables, with live-DB guard |
| `tests/TestCase.php` | modify | build replica schema before each test |
| `app/Models/Concerns/ReadOnlyTdhModel.php` | create | connection binding + write refusal |
| `app/Models/UserPrivilege.php` | create | `tdh_user.user_priv` |
| `app/Models/Designation.php` | create | `tdh_user.designation` |
| `app/Models/Department.php` | rewrite | `tdh_user.section` |
| `app/Models/User.php` | rewrite | `tdh_user.users` + role/department/name accessors, scopes |
| `app/Models/DatabaseNotification.php` | create | pins notifications to the default connection |
| `database/factories/DepartmentFactory.php` | rewrite | section rows; maps `name` → `description` |
| `database/factories/UserFactory.php` | rewrite | tdh user rows; maps `role` → user_priv row, `department_id` → `section` |
| `database/seeders/DatabaseSeeder.php` | modify | drop DevUserSeeder/DepartmentSeeder |
| `database/seeders/DevUserSeeder.php`, `DepartmentSeeder.php` | delete | fake users/departments no longer exist |
| `app/Http/Controllers/IncidentController.php` | modify | department/user option queries |
| `app/Services/AnalyticsService.php` | modify | `name` → `description` for departments |
| `app/Listeners/NotifyReviewersOfSubmittedIncident.php` | modify | role scope instead of `role` column |
| `app/Console/Commands/CheckOverdueIncidents.php` | modify | role scope instead of `role` column |
| `app/Http/Requests/**` (5 files) | modify | `exists` rules target the `user` connection |
| `app/Http/Middleware/HandleInertiaRequests.php` | modify | shared `auth.user` shape |
| `app/Http/Controllers/Auth/AuthenticatedSessionController.php` | modify | username login, active only, no remember |
| `resources/js/Pages/Auth/Login.vue` | modify | Username field, remove remember-me |
| `database/migrations/2026_09_24_000001_detach_local_users_and_departments.php` | create | drop FKs + local tables on `incident_report` |
| `tests/Feature/Tdh/*.php` | create | new tests per task |
| `tests/Feature/Auth/AuthenticationTest.php` | modify | username login |

---

### Task 1: Config + isolated test harness for the `user` connection

**Files:**
- Create: `config/tdh.php`, `tests/Support/TdhTestSchema.php`, `tests/Feature/Tdh/TdhTestHarnessTest.php`
- Modify: `config/database.php` (the `'user' => [` block), `phpunit.xml`, `.env.example`, `tests/TestCase.php`

- [ ] **Step 1: Create `config/tdh.php`**

```php
<?php

/*
|--------------------------------------------------------------------------
| tdh_user integration
|--------------------------------------------------------------------------
| Users, their sections (this app's "departments") and their per-system
| privileges live in the hospital's shared tdh_user database, read live
| and never written. See docs/superpowers/specs/2026-09-24-tdh-user-integration-design.md.
*/

return [
    // Connection name in config/database.php that points at tdh_user.
    'connection' => 'user',

    // This system's static code in tdh_user.user_priv.syscode (varchar(20)).
    // Rows for other systems (hris, dtr, ...) are ignored.
    'syscode' => 'IR',
];
```

- [ ] **Step 2: Fix the `user` connection in `config/database.php`**

In the `'user' => [` block change exactly two lines:

```php
            'driver' => env('USER_CONNECTION', 'mysql'),
            'url' => env('USER_DATABASE_URL'),
```

(Previously `'driver' => 'mysql'` and `'url' => env('DATABASE_URL')` — the latter would have made both connections follow the same URL if `DATABASE_URL` were ever set. Also fix the block's indentation to 8 spaces like its siblings.)

- [ ] **Step 3: Point the `user` connection at in-memory SQLite in `phpunit.xml`**

Add inside `<php>` after the `DB_DATABASE` line:

```xml
        <env name="DB_FOREIGN_KEYS" value="false"/>
        <env name="USER_CONNECTION" value="sqlite"/>
        <env name="USER_DATABASE" value=":memory:"/>
```

- [ ] **Step 4: Document the vars in `.env.example`**

After the `DB_PASSWORD=` line add:

```
# Shared hospital user directory (read-only). See config/tdh.php.
USER_CONNECTION=mysql
USER_HOST=127.0.0.1
USER_PORT=3306
USER_DATABASE=tdh_user
USER_USERNAME=root
USER_PASSWORD=
```

- [ ] **Step 5: Write the failing harness test** `tests/Feature/Tdh/TdhTestHarnessTest.php`

```php
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
```

- [ ] **Step 6: Run it — expect FAIL** (`replica table users` missing)

Run: `php artisan config:clear && php artisan test --filter=TdhTestHarnessTest`

- [ ] **Step 7: Create `tests/Support/TdhTestSchema.php`**

```php
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
```

- [ ] **Step 8: Call it from `tests/TestCase.php`**

```php
<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\TdhTestSchema;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        TdhTestSchema::create();
    }
}
```

- [ ] **Step 9: Run harness test, then full suite — expect PASS, 182 + 2 tests**

Run: `php artisan test --filter=TdhTestHarnessTest` then `php artisan test`
Expected: all green (models still use the default connection at this point; nothing else changed behaviour).

- [ ] **Step 10: Commit**

```bash
git add config/tdh.php config/database.php phpunit.xml .env.example tests/Support/TdhTestSchema.php tests/TestCase.php tests/Feature/Tdh/TdhTestHarnessTest.php
git commit -m "test: isolate the tdh_user connection as in-memory SQLite with a replica schema"
```

---

### Task 2: Read-only tdh base trait + `UserPrivilege` and `Designation` models

**Files:**
- Create: `app/Models/Concerns/ReadOnlyTdhModel.php`, `app/Models/UserPrivilege.php`, `app/Models/Designation.php`, `tests/Feature/Tdh/ReadOnlyTdhModelTest.php`

- [ ] **Step 1: Write the failing test** `tests/Feature/Tdh/ReadOnlyTdhModelTest.php`

```php
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
```

- [ ] **Step 2: Run — expect FAIL** (`Class "App\Models\UserPrivilege" not found`)

Run: `php artisan test --filter=ReadOnlyTdhModelTest`

- [ ] **Step 3: Create `app/Models/Concerns/ReadOnlyTdhModel.php`**

```php
<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Binds a model to the shared tdh_user database (config('tdh.connection'))
 * and refuses every Eloquent save/delete on it. tdh_user is shared by ~20
 * hospital systems; this app only ever reads it.
 *
 * Writes are allowed only when running tests against the in-memory SQLite
 * replica (tests/Support/TdhTestSchema.php), so factories can seed it.
 * Raw query-builder writes are not intercepted — never issue them against
 * the tdh connection.
 */
trait ReadOnlyTdhModel
{
    public static function bootReadOnlyTdhModel(): void
    {
        foreach (['saving', 'deleting'] as $event) {
            static::$event(function (Model $model) {
                if (! static::tdhWritesAllowed($model)) {
                    throw new LogicException('tdh_user is read-only from incident-report.');
                }
            });
        }
    }

    public function getConnectionName()
    {
        return config('tdh.connection');
    }

    private static function tdhWritesAllowed(Model $model): bool
    {
        return app()->environment('testing')
            && $model->getConnection()->getDriverName() === 'sqlite';
    }
}
```

- [ ] **Step 4: Create `app/Models/UserPrivilege.php`**

```php
<?php

namespace App\Models;

use App\Models\Concerns\ReadOnlyTdhModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * tdh_user.user_priv — one row per (user, system). Only rows whose syscode
 * is this system's (config('tdh.syscode')) mean anything here; `level`
 * holds an App\Enums\Role backing value.
 */
class UserPrivilege extends Model
{
    use ReadOnlyTdhModel;

    public $timestamps = false;

    protected $table = 'user_priv';

    protected $fillable = ['user_id', 'syscode', 'level'];

    public function scopeForThisSystem(Builder $query): Builder
    {
        return $query->where('syscode', config('tdh.syscode'));
    }
}
```

- [ ] **Step 5: Create `app/Models/Designation.php`**

```php
<?php

namespace App\Models;

use App\Models\Concerns\ReadOnlyTdhModel;
use Illuminate\Database\Eloquent\Model;

/** tdh_user.designation — job titles referenced by tdh_user.users.designation. */
class Designation extends Model
{
    use ReadOnlyTdhModel;

    protected $table = 'designation';

    protected $fillable = ['description'];
}
```

- [ ] **Step 6: Run — expect PASS (5 tests)**, then full suite green.

Run: `php artisan test --filter=ReadOnlyTdhModelTest` then `php artisan test`

- [ ] **Step 7: Commit**

```bash
git add app/Models/Concerns/ReadOnlyTdhModel.php app/Models/UserPrivilege.php app/Models/Designation.php tests/Feature/Tdh/ReadOnlyTdhModelTest.php
git commit -m "feat: add read-only tdh_user models for user privileges and designations"
```

---

### Task 3: `Department` becomes `tdh_user.section`

**Files:**
- Rewrite: `app/Models/Department.php`, `database/factories/DepartmentFactory.php`
- Modify: `app/Http/Controllers/IncidentController.php` (lines ~62, ~94, ~173), `app/Services/AnalyticsService.php` (~233, ~366), `app/Http/Requests/Concerns/ValidatesIncidentData.php` (~20, ~39), `app/Http/Requests/CorrectiveActions/CreateCorrectiveActionRequest.php` (~28), `app/Http/Requests/CorrectiveActions/UpdateCorrectiveActionRequest.php` (~27), `database/seeders/DatabaseSeeder.php`
- Delete: `database/seeders/DepartmentSeeder.php`, `database/seeders/DevUserSeeder.php`
- Test: `tests/Feature/Tdh/DepartmentTest.php`

Note: `DevUserSeeder` is deleted here (not in Task 4) because after this task it would try to write departments/users through read-only models.

- [ ] **Step 1: Write the failing test** `tests/Feature/Tdh/DepartmentTest.php`

```php
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
```

- [ ] **Step 2: Run — expect FAIL**

Run: `php artisan test --filter=DepartmentTest`

- [ ] **Step 3: Rewrite `app/Models/Department.php`**

```php
<?php

namespace App\Models;

use App\Models\Concerns\ReadOnlyTdhModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * A hospital unit = a row in tdh_user.section (read-only). Users belong to
 * one via tdh_user.users.section; incidents store its id in department_id.
 * tdh_user.section has no active flag, so every section is selectable
 * except placeholder rows whose description is '-'.
 */
class Department extends Model
{
    use HasFactory, ReadOnlyTdhModel;

    protected $table = 'section';

    protected $fillable = ['division', 'description', 'code', 'head', 'subsection'];

    protected $casts = [
        'division' => 'integer',
        'head' => 'integer',
    ];

    protected $appends = ['name'];

    protected $visible = ['id', 'code', 'name'];

    public function getNameAttribute(): string
    {
        return (string) $this->description;
    }

    /** Named headUser, not head: `head` is the raw column this relation reads. */
    public function headUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'head');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'section');
    }

    public function scopeSelectable(Builder $query): Builder
    {
        return $query->where('description', '!=', '-')->orderBy('description');
    }

    /** @return Collection<int, array{id: int, name: string}> dropdown options */
    public static function options(): Collection
    {
        return static::selectable()
            ->get(['id', 'description'])
            ->map(fn (Department $department) => ['id' => $department->id, 'name' => $department->name])
            ->values();
    }
}
```

- [ ] **Step 4: Rewrite `database/factories/DepartmentFactory.php`**

```php
<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Builds tdh_user.section rows in the test replica. Accepts the legacy
 * `name` attribute (maps to `description`) so existing call sites like
 * Department::factory()->create(['name' => 'Surgery']) keep working.
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Department>
 */
class DepartmentFactory extends Factory
{
    public function definition()
    {
        return [
            'description' => $this->faker->unique()->company() . ' Department',
            'code' => strtoupper($this->faker->unique()->lexify('???')),
            'division' => 0,
            'head' => 0,
        ];
    }

    public function newModel(array $attributes = [])
    {
        if (array_key_exists('name', $attributes)) {
            $attributes['description'] = $attributes['name'];
            unset($attributes['name']);
        }

        return parent::newModel($attributes);
    }
}
```

- [ ] **Step 5: Update call sites**

`app/Http/Controllers/IncidentController.php` — replace each of the three department lines:

```php
            'departments' => Department::where('is_active', true)->get(['id', 'name']),
```
and
```php
            'departments' => Department::where('is_active', true)->orderBy('name')->get(['id', 'name']),
```
with
```php
            'departments' => Department::options(),
```

`app/Services/AnalyticsService.php`:
- line ~233: `->orderBy('name')` → `->orderBy('description')`
- line ~366: `->pluck('name', 'id')` → `->pluck('description', 'id')`

- [ ] **Step 6: Point department `exists` rules at tdh_user.section**

`app/Http/Requests/Concerns/ValidatesIncidentData.php` (add `use Illuminate\Validation\Rule;` if missing):

```php
            'department_id' => [$required, Rule::exists(config('tdh.connection') . '.section', 'id')],
```
```php
            'individuals.*.department_id' => ['nullable', Rule::exists(config('tdh.connection') . '.section', 'id')],
```

`CreateCorrectiveActionRequest.php` and `UpdateCorrectiveActionRequest.php`:

```php
            'responsible_department_id' => ['nullable', Rule::exists(config('tdh.connection') . '.section', 'id')],
```

- [ ] **Step 7: Remove fake-data seeders**

Delete `database/seeders/DepartmentSeeder.php` and `database/seeders/DevUserSeeder.php`. In `database/seeders/DatabaseSeeder.php` the `run()` body becomes:

```php
        // Users and departments come from the shared tdh_user database
        // (read-only); only this app's own reference data is seeded.
        $this->call([
            IncidentTypeSeeder::class,
            ContributingFactorSeeder::class,
        ]);
```

Then `grep -rn "DevUserSeeder\|DepartmentSeeder" app database tests` — expected: no matches.

- [ ] **Step 8: Run DepartmentTest, then full suite — expect all green**

Run: `php artisan test --filter=DepartmentTest` then `php artisan test`
If an existing test fails, it is because it relied on a local `departments` column (`is_active`, `name` in a query, `parent_department_id`). Fix the *app* code to use `description`/`options()`; do not weaken the test.

- [ ] **Step 9: Commit**

```bash
git add -A app/Models/Department.php database/factories/DepartmentFactory.php app/Http/Controllers/IncidentController.php app/Services/AnalyticsService.php app/Http/Requests database/seeders tests/Feature/Tdh/DepartmentTest.php
git commit -m "feat: read departments live from tdh_user.section"
```

---

### Task 4: `User` becomes `tdh_user.users` (role from user_priv, department from section)

**Files:**
- Rewrite: `app/Models/User.php`, `database/factories/UserFactory.php`
- Create: `app/Models/DatabaseNotification.php`, `tests/Feature/Tdh/TdhUserTest.php`
- Modify: `app/Http/Controllers/IncidentController.php` (~162-172), `app/Listeners/NotifyReviewersOfSubmittedIncident.php`, `app/Console/Commands/CheckOverdueIncidents.php` (~41), `app/Http/Requests/Incidents/AssignIncidentRequest.php` (~21), `app/Http/Requests/CorrectiveActions/CreateCorrectiveActionRequest.php` (~27), `app/Http/Requests/CorrectiveActions/UpdateCorrectiveActionRequest.php` (~26), `app/Http/Requests/Investigations/AddTeamMemberRequest.php` (~21), `app/Http/Requests/Investigations/StartInvestigationRequest.php` (~25), `app/Http/Middleware/HandleInertiaRequests.php` (~34-36)

**Why the notifications model:** Laravel's `newRelatedInstance()` copies the parent's `$connection` onto a related model that has none. A `User` hydrated from tdh_user carries `connection = 'user'`, so `$user->notifications()` would query `tdh_user.notifications` (does not exist). `App\Models\DatabaseNotification` pins itself to the default connection.

- [ ] **Step 1: Write the failing test** `tests/Feature/Tdh/TdhUserTest.php`

```php
<?php

namespace Tests\Feature\Tdh;

use App\Enums\Role;
use App\Models\Department;
use App\Models\Designation;
use App\Models\User;
use App\Models\UserPrivilege;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class TdhUserTest extends TestCase
{
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
```

- [ ] **Step 2: Run — expect FAIL**

Run: `php artisan test --filter=TdhUserTest`

- [ ] **Step 3: Create `app/Models/DatabaseNotification.php`**

```php
<?php

namespace App\Models;

use Illuminate\Notifications\DatabaseNotification as BaseDatabaseNotification;

/**
 * Pins notifications to this app's own database. Without this, Laravel's
 * newRelatedInstance() would inherit the tdh_user connection from the
 * User model and look for tdh_user.notifications.
 */
class DatabaseNotification extends BaseDatabaseNotification
{
    public function getConnectionName()
    {
        return config('database.default');
    }
}
```

- [ ] **Step 4: Rewrite `app/Models/User.php`**

```php
<?php

namespace App\Models;

use App\Enums\Role;
use App\Models\Concerns\ReadOnlyTdhModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * A person = a row in the shared tdh_user.users table (read-only).
 *
 * - role: tdh_user.user_priv.level for this system's syscode (config('tdh.syscode'));
 *   no row, or an unrecognised level, means Role::Staff.
 * - department_id: tdh_user.users.section (0 = none).
 * - is_active: status === '1'.
 *
 * Remember-me is disabled (getRememberTokenName() returns ''): remember_token
 * is shared with other hospital systems and must never be rotated from here.
 */
class User extends Authenticatable
{
    use HasFactory, Notifiable, ReadOnlyTdhModel;

    /** Columns this app reads. Never loads signature/picture/api_token/security_pin. */
    private const COLUMNS = [
        'id', 'fname', 'mname', 'lname', 'suffix', 'title', 'username', 'password',
        'designation', 'other_designation', 'section', 'status',
    ];

    protected $table = 'users';

    protected $with = ['privilege', 'designationRecord'];

    protected $casts = [
        'designation' => 'integer',
        'section' => 'integer',
    ];

    protected $appends = ['name', 'role', 'department_id', 'designation_title'];

    /** Whitelist: tdh rows carry password hashes, tokens, PINs and signatures. */
    protected $visible = ['id', 'username', 'name', 'role', 'department_id', 'designation_title'];

    protected static function booted(): void
    {
        static::addGlobalScope('tdhColumns', function (Builder $query) {
            if ($query->getQuery()->columns === null) {
                $query->select($query->getModel()->qualifyColumns(self::COLUMNS));
            }
        });
    }

    public function privilege(): HasOne
    {
        return $this->hasOne(UserPrivilege::class, 'user_id')->forThisSystem()->oldest('id');
    }

    public function designationRecord(): BelongsTo
    {
        return $this->belongsTo(Designation::class, 'designation');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'section');
    }

    public function notifications(): MorphMany
    {
        return $this->morphMany(DatabaseNotification::class, 'notifiable')->latest();
    }

    public function getNameAttribute(): string
    {
        $middleInitial = filled($this->mname) ? mb_substr(trim($this->mname), 0, 1) . '.' : null;

        return collect([$this->title, $this->fname, $middleInitial, $this->lname, $this->suffix])
            ->map(fn ($part) => is_string($part) ? trim($part) : $part)
            ->filter()
            ->implode(' ');
    }

    public function getRoleAttribute(): Role
    {
        $level = $this->privilege?->level;

        if ($level === null) {
            return Role::Staff;
        }

        $role = Role::tryFrom($level);

        if ($role === null) {
            Log::warning('Unrecognised IR privilege level in tdh_user.user_priv; treating as Staff.', [
                'user_id' => $this->id,
                'level' => $level,
            ]);

            return Role::Staff;
        }

        return $role;
    }

    public function getDepartmentIdAttribute(): ?int
    {
        $section = $this->attributes['section'] ?? null;

        return $section ? (int) $section : null;
    }

    public function getIsActiveAttribute(): bool
    {
        return (string) ($this->attributes['status'] ?? '') === '1';
    }

    public function getDesignationTitleAttribute(): ?string
    {
        return $this->designationRecord?->description ?: ($this->other_designation ?: null);
    }

    public function getRememberTokenName()
    {
        return '';
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('status'), '1');
    }

    public function scopeOrderByName(Builder $query): Builder
    {
        return $query->orderBy($this->qualifyColumn('lname'))->orderBy($this->qualifyColumn('fname'));
    }

    /**
     * Users whose IR role is any of $roles. Role::Staff also matches users with
     * no IR row (or an unrecognised level) — i.e. anyone not holding an
     * elevated IR level — mirroring getRoleAttribute().
     *
     * @param  Role|string|array<Role|string>  $roles
     */
    public function scopeWithRole(Builder $query, Role|string|array $roles): Builder
    {
        $roles = collect(Arr::wrap($roles))->map(fn ($role) => $role instanceof Role ? $role : Role::from($role));
        $elevated = $roles->reject(fn (Role $role) => $role === Role::Staff)->map(fn (Role $role) => $role->value)->values()->all();
        $allElevated = collect(Role::cases())->reject(fn (Role $role) => $role === Role::Staff)->map(fn (Role $role) => $role->value)->values()->all();

        $idsWithLevels = fn (array $levels) => UserPrivilege::query()->forThisSystem()->whereIn('level', $levels)->select('user_id');

        return $query->where(function (Builder $query) use ($roles, $elevated, $allElevated, $idsWithLevels) {
            if ($elevated !== []) {
                $query->whereIn($this->qualifyColumn('id'), $idsWithLevels($elevated));
            }

            if ($roles->contains(Role::Staff)) {
                $query->orWhereNotIn($this->qualifyColumn('id'), $idsWithLevels($allElevated));
            }
        });
    }
}
```

Note: `HasApiTokens` is intentionally removed (no API tokens are issued; `personal_access_tokens` is dropped in Task 6).

- [ ] **Step 5: Rewrite `database/factories/UserFactory.php`**

```php
<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\User;
use App\Models\UserPrivilege;
use Illuminate\Database\Eloquent\Factories\Factory;
use WeakMap;

/**
 * Builds tdh_user.users rows in the test replica. For compatibility with
 * the existing suite it accepts two non-column attributes:
 *   - 'role'          => Role|string  -> writes an IR user_priv row (none for Staff)
 *   - 'department_id' => ?int         -> stored as users.section (null -> 0)
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /** @var WeakMap<User, Role>|null */
    private static ?WeakMap $pendingRoles = null;

    public function definition()
    {
        return [
            'fname' => $this->faker->firstName(),
            'mname' => $this->faker->lastName(),
            'lname' => $this->faker->lastName(),
            'username' => $this->faker->unique()->userName(),
            'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', // password
            'designation' => 0,
            'division' => 0,
            'section' => 0,
            'status' => '1',
        ];
    }

    public function inactive(): static
    {
        return $this->state(['status' => '0']);
    }

    public function newModel(array $attributes = [])
    {
        $role = $attributes['role'] ?? null;
        unset($attributes['role']);

        if (array_key_exists('department_id', $attributes)) {
            $attributes['section'] = $attributes['department_id'] ?? 0;
            unset($attributes['department_id']);
        }

        $model = parent::newModel($attributes);

        if ($role !== null) {
            self::pendingRoles()[$model] = $role instanceof Role ? $role : Role::from($role);
        }

        return $model;
    }

    public function configure()
    {
        return $this->afterCreating(function (User $user) {
            $role = self::pendingRoles()[$user] ?? null;

            if ($role === null) {
                return;
            }

            UserPrivilege::create([
                'user_id' => $user->id,
                'syscode' => config('tdh.syscode'),
                'level' => $role->value,
            ]);

            $user->unsetRelation('privilege');
        });
    }

    private static function pendingRoles(): WeakMap
    {
        return self::$pendingRoles ??= new WeakMap();
    }
}
```

(An explicit `Role::Staff` also writes a `staff` IR row — harmless, and it exercises the "row says staff" path.)

- [ ] **Step 6: Replace the role/is_active column queries**

`app/Http/Controllers/IncidentController.php` (~162-172):

```php
            'investigators' => $user->can('assign', $incident)
                ? User::active()->withRole(Role::Investigator)->orderByName()->get()
                : [],
            'potentialTeamMembers' => ($canStartInvestigation || $canManageInvestigationTeam)
                ? User::active()->orderByName()->get()
                : [],
```
```php
            'potentialResponsibleUsers' => User::active()->orderByName()->get(),
```

(Serialized via `$visible`: `id, username, name, role, department_id, designation_title` — the Vue panels read `id`, `name`, `role`.)

`app/Listeners/NotifyReviewersOfSubmittedIncident.php` — replace the `$recipients = ...->get();` statement:

```php
        $recipients = User::active()->where(function ($query) use ($incident) {
            $query->withRole([Role::QualitySafetyOfficer, Role::Administrator]);

            if ($incident->department_id !== null) {
                $query->orWhere(function ($query) use ($incident) {
                    $query->withRole([Role::Supervisor, Role::DepartmentHead])
                        ->where('section', $incident->department_id);
                });
            }
        })->get();
```

`app/Console/Commands/CheckOverdueIncidents.php` (~41):

```php
        $recipients = User::active()->withRole(config('incident_workflow.escalation_recipient_roles'))->get();
```

- [ ] **Step 7: Point user `exists` rules at tdh_user**

`app/Http/Requests/Incidents/AssignIncidentRequest.php` (~21) — the investigator rule becomes:

```php
                Rule::exists(config('tdh.connection') . '.user_priv', 'user_id')
                    ->where('syscode', config('tdh.syscode'))
                    ->where('level', Role::Investigator->value),
```

In `CreateCorrectiveActionRequest.php`, `UpdateCorrectiveActionRequest.php`, `AddTeamMemberRequest.php`, `StartInvestigationRequest.php`, replace every `Rule::exists('users', 'id')` with:

```php
Rule::exists(config('tdh.connection') . '.users', 'id')
```

Then: `grep -rn "exists('users'\|exists:users\|exists('departments'\|exists:departments\|where('role'\|whereIn('role'\|where('is_active', true)->.*User\|User::where" app` — expected: no matches (IncidentType/ContributingFactor `is_active` queries are local tables and stay).

- [ ] **Step 8: Shared Inertia user in `app/Http/Middleware/HandleInertiaRequests.php`**

Replace the `'user' => $request->user()?->only([...])` entry with:

```php
                'user' => ($user = $request->user()) ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'role' => $user->role,
                    'designation' => $user->designation_title,
                    'department_id' => $user->department_id,
                ] : null,
```

(`designation` key kept so `AuthenticatedLayout.vue` and `Step1ReporterInfo.vue` need no change; `email` is gone — confirm with `grep -rn "user?\?\.email\|\.email" resources/js` → only `Auth/Login.vue`, handled in Task 5.)

- [ ] **Step 9: Run TdhUserTest, then the full suite**

Run: `php artisan test --filter=TdhUserTest` then `php artisan test`
Expected: all green except `AuthenticationTest` login tests (they post `email`; fixed in Task 5). If anything else fails, the cause is app code reading a local user column — fix the app code, not the test. Do **not** add `whereHas`/joins from incident tables to users/section: they are different databases.

- [ ] **Step 10: Commit**

```bash
git add app/Models/User.php app/Models/DatabaseNotification.php database/factories/UserFactory.php app/Http/Controllers/IncidentController.php app/Listeners/NotifyReviewersOfSubmittedIncident.php app/Console/Commands/CheckOverdueIncidents.php app/Http/Requests app/Http/Middleware/HandleInertiaRequests.php tests/Feature/Tdh/TdhUserTest.php
git commit -m "feat: read users live from tdh_user with IR role from user_priv"
```

---

### Task 5: Log in with tdh username; never touch the shared remember_token

**Files:**
- Modify: `app/Http/Controllers/Auth/AuthenticatedSessionController.php` (`store()`), `resources/js/Pages/Auth/Login.vue`, `tests/Feature/Auth/AuthenticationTest.php`

- [ ] **Step 1: Update/extend `tests/Feature/Auth/AuthenticationTest.php`**

Replace the two credential tests and add three new ones:

```php
    public function test_users_can_authenticate_with_their_tdh_username(): void
    {
        $user = User::factory()->create(['username' => 'apangcatan']);

        $response = $this->post('/login', [
            'username' => 'apangcatan',
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect('/');
    }

    public function test_users_cannot_authenticate_with_invalid_password(): void
    {
        User::factory()->create(['username' => 'apangcatan']);

        $this->post('/login', [
            'username' => 'apangcatan',
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_inactive_tdh_users_cannot_authenticate(): void
    {
        User::factory()->inactive()->create(['username' => 'retired']);

        $this->post('/login', [
            'username' => 'retired',
            'password' => 'password',
        ])->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_login_and_logout_never_touch_the_shared_remember_token(): void
    {
        $user = User::factory()->create(['username' => 'apangcatan', 'remember_token' => 'owned-by-hris']);

        $this->post('/login', ['username' => 'apangcatan', 'password' => 'password', 'remember' => true]);
        $this->post('/logout');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'remember_token' => 'owned-by-hris'], config('tdh.connection'));
    }

    public function test_the_shared_auth_user_prop_has_no_credentials(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/')->assertInertia(fn ($page) => $page
            ->where('auth.user.id', $user->id)
            ->where('auth.user.role', 'staff')
            ->missing('auth.user.password')
            ->missing('auth.user.email'));
    }
```

- [ ] **Step 2: Run — expect FAIL** (login still validates `email`)

Run: `php artisan test --filter=AuthenticationTest`

- [ ] **Step 3: Rewrite `store()` in `AuthenticatedSessionController.php`**

```php
    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        // tdh_user accounts only; status '1' = active. No remember-me: the
        // remember_token column is shared with other hospital systems.
        if (! Auth::attempt($credentials + ['status' => '1'])) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'username' => 'These credentials do not match our records.',
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }
```

- [ ] **Step 4: Update `resources/js/Pages/Auth/Login.vue`**

- `useForm({ username: '', password: '' })` (remove `email`, `remember`).
- The first field: label text `Username`, `for="username"`, `id="username"`, `v-model="form.username"`, `type="text"`, `autocomplete="username"`, keep `autofocus`; error span reads `form.errors.username`.
- Delete the whole "remember me" `<label>` block that contains `v-model="form.remember"`.
- Sub-heading text: `Sign in with your hospital (tdh) username and password.`

Then `npm run build` — expected: success.

- [ ] **Step 5: Run — expect PASS**, then full suite all green.

Run: `php artisan test --filter=AuthenticationTest` then `php artisan test`

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Auth/AuthenticatedSessionController.php resources/js/Pages/Auth/Login.vue tests/Feature/Auth/AuthenticationTest.php
git commit -m "feat: log in with tdh_user username; disable remember-me on the shared token"
```

---

### Task 6: Detach `incident_report` from the local users/departments tables

**Files:**
- Create: `database/migrations/2026_09_24_000001_detach_local_users_and_departments.php`, `tests/Feature/Tdh/LocalSchemaTest.php`

- [ ] **Step 1: Write the failing test** `tests/Feature/Tdh/LocalSchemaTest.php`

```php
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
```

- [ ] **Step 2: Run — expect FAIL**

Run: `php artisan test --filter=LocalSchemaTest`

- [ ] **Step 3: Create the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Users and departments now live in the shared tdh_user database (read
 * live, never written — see config/tdh.php). MySQL cannot enforce foreign
 * keys across databases, so the constraints pointing at the old local
 * tables are dropped (columns and their indexes are kept) and the local
 * tables are removed.
 *
 * One-way: incident_report held no real data when this ran (2026-09-24).
 * On SQLite (tests only) Laravel 9 cannot drop foreign keys; phpunit.xml
 * disables FK enforcement there instead.
 */
return new class extends Migration
{
    private const FOREIGN_KEYS = [
        'users' => ['department_id'],
        'departments' => ['parent_department_id', 'head_user_id'],
        'incidents' => ['reporter_id', 'department_id', 'assigned_investigator_id', 'supervisor_reviewed_by', 'closed_by'],
        'incident_individuals' => ['department_id'],
        'incident_actions' => ['responsible_user_id'],
        'attachments' => ['uploaded_by'],
        'audit_logs' => ['actor_id'],
        'investigations' => ['lead_investigator_id'],
        'investigation_team_members' => ['user_id'],
        'corrective_actions' => ['responsible_user_id', 'responsible_department_id', 'completed_by', 'verified_by'],
        'approvals' => ['requested_by', 'approver_id'],
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            foreach (self::FOREIGN_KEYS as $table => $columns) {
                Schema::table($table, function (Blueprint $blueprint) use ($columns) {
                    foreach ($columns as $column) {
                        $blueprint->dropForeign([$column]);
                    }
                });
            }
        }

        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('password_resets');
        Schema::dropIfExists('users');
        Schema::dropIfExists('departments');
    }

    public function down(): void
    {
        throw new RuntimeException('Irreversible: users/departments now come from tdh_user.');
    }
};
```

- [ ] **Step 4: Run — expect PASS**, then full suite all green.

Run: `php artisan test --filter=LocalSchemaTest` then `php artisan test`

- [ ] **Step 5: Apply to `incident_report` (forward migration only)**

Run: `php artisan config:clear && php artisan migrate --force`
Expected output: exactly one migration, `2026_09_24_000001_detach_local_users_and_departments ... DONE`.
Then verify with the WAMP client (read-only query):

```bash
/c/wamp64/bin/mysql/mysql8.0.31/bin/mysql.exe -uroot -e "SELECT TABLE_NAME, REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA='incident_report' AND REFERENCED_TABLE_NAME IN ('users','departments'); SHOW TABLES FROM incident_report LIKE 'users';"
```
Expected: empty result sets.

Then seed only reference data: `php artisan db:seed --force` (runs IncidentTypeSeeder + ContributingFactorSeeder on `incident_report`; must not touch tdh_user).

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_09_24_000001_detach_local_users_and_departments.php tests/Feature/Tdh/LocalSchemaTest.php
git commit -m "feat: drop local users/departments tables and their FK constraints"
```

---

### Task 7: Documentation

**Files:**
- Modify: `docs/architecture.md` (§2.1 Identity & org structure; add §9j)

- [ ] **Step 1: Update §2.1** — replace the local `users`/`departments` description and the `external_user_id` paragraph with: users = live read-only `tdh_user.users` via the `user` connection; department = `tdh_user.section` (via `users.section`); role = `tdh_user.user_priv.level` where `syscode = 'IR'` (Role enum values, default Staff); login = tdh username/password, active = `status '1'`; no remember-me; no FKs from incident_report to users/departments.

- [ ] **Step 2: Add §9j "tdh_user live integration (2026-09-24)"** covering: why read-live (user decision), the read-only trait, the `remember_token` hazard, the notifications-connection gotcha (`newRelatedInstance`), the SQLite FK limitation + `DB_FOREIGN_KEYS=false`, the test replica + its live-DB guard, and the rule "never `whereHas`/join across incident tables and users/section".

- [ ] **Step 3: Commit**

```bash
git add docs/architecture.md
git commit -m "docs: document the live tdh_user integration"
```

---

## Controller-session checklist after Task 7 (not for implementer subagents)

1. Holistic review of the whole diff (per project workflow).
2. With user consent already given: `INSERT INTO tdh_user.user_priv (user_id, syscode, level) VALUES (1, 'IR', 'department_head');` — the only live write in this project.
3. Record `remember_token` for users 1 and 1116, then Playwright against `php artisan serve`: log in as 1116 (Staff) → file an incident in IMISS (section 21) → log out; log in as 1 (Department Head) → incident visible in department-scoped list → log out. Re-read both `remember_token` values: must be unchanged.
4. Update memory (`project-state`, `project-client-process-flow`), then resume brainstorming the Department Assessment workflow.
