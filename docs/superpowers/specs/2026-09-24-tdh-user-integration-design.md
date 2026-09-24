# tdh_user Live Integration — Design

**Date:** 2026-09-24
**Status:** Approved (brainstorming), pending spec review
**Supersedes:** the "local `users` table + hand-rolled auth as a stand-in" arrangement from Phase 0–2 (`docs/architecture.md` §2.1, §9c). `external_user_id` is retired.

## 1. Goal

Replace this app's local `users` and `departments` tables with **live, read-only reads of the hospital's shared `tdh_user` database**, via the `user` connection already defined in `config/database.php` (`USER_*` env vars). People log in with their existing tdh username/password; their role in this app comes from `tdh_user.user_priv`; their department is their tdh section.

Out of scope: the Department Assessment workflow, the public guest form, and Work Queues (all paused behind this — see memory `project-client-process-flow`).

## 2. Hard constraints

1. **This app never writes to `tdh_user`.** It is shared by ~20 other systems (hris, dtr, paperless, …). The models bound to it are read-only at the code level (see §4.3).
2. **No migration ever runs against the `user` connection.** All schema changes target `incident_report` (the default `mysql` connection) only. No `migrate:fresh`/`db:wipe` against `tdh_user`, ever.
3. **Tests never touch live `tdh_user`.** Under PHPUnit the `user` connection is an isolated SQLite in-memory database with a test-only replica schema.
4. Data in `incident_report` may be wiped (user-confirmed, 2026-09-24); it holds no real incidents.

## 3. Source schema (live `tdh_user`, as inspected 2026-09-24)

| Table | Columns used | Notes |
|---|---|---|
| `users` | `id` (int unsigned), `fname`, `mname`, `lname`, `suffix`, `title`, `username` (unique), `password` (bcrypt `$2y$12$`), `designation` (FK → `designation.id`), `other_designation`, `section` (FK → `section.id`), `status` (varchar: `'1'` active, `'0'` inactive, stray values e.g. `'JO'`) | InnoDB. No email column. `remember_token` exists and is **shared with other systems**. |
| `user_priv` | `user_id`, `syscode` varchar(20), `level` varchar(30) | MyISAM. One row per (user, system). |
| `section` | `id`, `division`, `description`, `code`, `head` (user id), `subsection` | ~100 rows. This app's "department". |
| `designation` | `id`, `description` | Job title. |

`division` is not used (too coarse).

## 4. Design

### 4.1 Config

New `config/tdh.php`:

```php
return [
    'connection' => 'user',   // name of the connection in config/database.php
    'syscode' => 'IR',        // this system's static code in tdh_user.user_priv
];
```

`syscode` is a fixed identifier for this system — every `user_priv` lookup filters on it; rows for other systems are ignored.

### 4.2 Role resolution

- Role = `user_priv.level` where `user_id = users.id AND syscode = config('tdh.syscode')`.
- `level` values are exactly the `App\Enums\Role` backing values: `staff`, `supervisor`, `investigator`, `department_head`, `quality_safety_officer`, `administrator`, `management`. Longest is 22 chars; `level` is varchar(30). A unit test asserts every `Role` case value is ≤ 30 chars.
- **No IR row → `Role::Staff`.** Any active tdh user may log in and file a report.
- **Unrecognised level → `Role::Staff`** plus a `Log::warning` naming the user id and the raw level. Fails closed (least privilege), never errors.
- If a user has multiple IR rows (not expected), the highest-privilege one is **not** guessed — the lowest `user_priv.id` wins (the `privilege` relation orders by `id`). Duplicate detection/logging is out of scope.

### 4.3 Models

**`App\Models\User`** — binds to the connection via the `ReadOnlyTdhModel` trait's `getConnectionName()`, `$table = 'users'`.

- Accessors (appended, so existing `user.name` / `user.role` / `user.designation` / `user.department_id` / `user.is_active` consumers keep working):
  - `name` — `"{title} {fname} {mname-initial}. {lname} {suffix}"`, blanks collapsed.
  - `role` — `Role` enum per §4.2, loaded via a `hasOne` `privilege()` relation scoped to the IR syscode (eager-loadable to avoid N+1).
  - `department_id` — `section`.
  - `designation_title` — `designationRecord` relation's `description`, falling back to `other_designation`. (Named `designation_title`, not `designation`, because `designation` is the raw FK column the relation reads; an accessor of the same name would break the relation. The shared Inertia `auth.user.designation` key is filled from it, so the frontend is unchanged.)
  - Serialization is a **whitelist** (`$visible`): `id, username, name, role, department_id, designation_title`. tdh rows carry `password`, `api_token`, `security_pin`, `signature`, `picture`, `remember_token` — none may ever reach an Inertia prop. A global select scope loads only the columns this app needs (skipping the longtext `signature`/`picture`).
  - `is_active` — `status === '1'`.
- Scopes replacing the six existing column queries:
  - `scopeActive()` → `where('status', '1')`
  - `scopeWithRole(Role|array)` → `whereIn('id', user_priv subquery for syscode IR and level IN …)`. For `Role::Staff` the scope must also match users with **no** IR row (`whereNotIn` on the IR subquery OR level = staff).
  - `scopeOrderByName()` → `orderBy('lname')->orderBy('fname')` (replaces `orderBy('name')`).
- Relation `department()` → `belongsTo(Department::class, 'section')`.
- **Read-only enforcement:** a shared `ReadOnlyTdhModel` trait guards both halves of Eloquent's write surface and **throws** `LogicException('tdh_user is read-only from incident-report')` (throwing, not returning `false`, so a write can never fail silently): (1) model writes — `performInsert`/`performUpdate`/`performDeleteOnModel`/`incrementOrDecrement` overrides, so `save`/`create`/`update`/`delete`/`increment`/`decrement` are covered even via `saveQuietly`/`deleteQuietly`/`Model::withoutEvents()`, which suppress events but not these methods; `saving`/`deleting` event listeners are kept as redundant belt-and-braces; and (2) query-builder writes — `newEloquentBuilder()` returns a `ReadOnlyTdhBuilder` that guards `update`/`delete`/`forceDelete`/`increment`/`decrement`/`upsert`/`truncate`/`insert`/`insertOrIgnore`/`insertGetId`/`insertUsing` (the last four are otherwise forwarded straight to the base query builder by Eloquent's `$passthru`, bypassing any model-instance hook). The exemption requires **both** `app()->environment('testing')` **and** the connection's driver being `sqlite`, so factories can seed the in-memory replica but a misconfigured test connection cannot silently write to a real database. Only raw `DB::connection('user')->...` query-builder statements are not intercepted by any of this — the plan forbids issuing them, and production's `USER_USERNAME` account must be SELECT-only as the actual enforcement boundary for that gap (see `.env.example`, §5).
- **Remember-me disabled:** `getRememberTokenName()` returns `''`. Laravel's `SessionGuard` then never reads or rotates `remember_token`, so logging out of this app cannot invalidate "remember me" in other systems sharing the column. The login form's remember checkbox is removed.
- `HasApiTokens` (Sanctum) is removed — no API tokens are issued by this app.
- Notifications (`Notifiable`) keep working: `notifications.notifiable_id` stores the tdh user id in `incident_report`.

**`App\Models\Department`** — binds to the connection via the `ReadOnlyTdhModel` trait's `getConnectionName()`, `$table = 'section'`, read-only as above.

- Accessor `name` → `description`. `code` as-is. `head()` → `belongsTo(User::class, 'head')`. `users()` → `hasMany(User::class, 'section')`.
- No `is_active` column exists: every section is treated as active. Existing `Department::where('is_active', true)` calls become `Department::query()->orderBy('description')`. Junk rows whose `description` is `'-'` are excluded by a `scopeSelectable()` used for dropdowns.
- `parent`/`children` relations (unused) are removed.

### 4.4 Authentication

- Login form: **Username** + Password (replaces Email). No "remember me".
- `AuthenticatedSessionController::store()` → `Auth::attempt(['username' => …, 'password' => …, 'status' => '1'])`. Inactive (`status ≠ '1'`) users fail with the same generic "credentials do not match" message.
- bcrypt `$2y$12$` hashes verify with Laravel's default `Hash::check`. No rehash-on-login (that would write to tdh_user).
- No registration, password reset, or profile editing in this app — those belong to tdh_user's own tooling. The `password_resets` table is dropped.
- Shared Inertia `auth.user` exposes `id, name, username, role, designation, department_id` (replacing `email`).

### 4.5 `incident_report` schema changes

One new migration on the default connection:

1. Drop every foreign-key **constraint** pointing at `users` or `departments` (~20 columns across incidents, incident_individuals, incident_actions, attachments, audit_logs, investigations, investigation_team_members, corrective_actions, approvals). **Columns are kept** as indexed unsigned integers — MySQL cannot enforce FKs across databases, and referential integrity to tdh ids is now an application concern.
2. Drop tables `users`, `departments`, `password_resets`, `personal_access_tokens`.

The migration is a forward migration (`php artisan migrate`), **not** a rewrite of old migrations + `migrate:fresh`. `down()` throws — this change is one-way. On SQLite (tests only) the FK drops are skipped because Laravel 9 cannot drop SQLite foreign keys; `phpunit.xml` sets `DB_FOREIGN_KEYS=false` so the now-dangling references are not enforced in tests, matching production MySQL after the migration.

Validation rules referencing these tables (e.g. `Rule::exists('users', 'id')`) switch to `Rule::exists(config('tdh.connection').'.users', 'id')` (and `.section` for departments), with role constraints expressed via the model scope rather than a `where('role', …)` column clause.

### 4.6 Seeders

- `DevUserSeeder` and `DepartmentSeeder` are deleted. `DatabaseSeeder` seeds only `IncidentTypeSeeder` and `ContributingFactorSeeder`.
- The one live data change needed for the sample users is done by hand (with the user's consent, during browser verification), **not** by a seeder:
  - `INSERT INTO tdh_user.user_priv (user_id, syscode, level) VALUES (1, 'IR', 'department_head');`
  - User 1116 needs no row (defaults to Staff). Both users are in section 21 (IMISS).

### 4.7 Tests

- `config/database.php`'s `user` connection reads its driver from `env('USER_CONNECTION', 'mysql')` (the variable already exists in `.env`). `phpunit.xml` sets `USER_CONNECTION=sqlite` and `USER_DATABASE=:memory:`, giving the `user` connection its own SQLite in-memory database, separate from the default connection's.
- A test-only migration directory `tests/Support/tdh_migrations/` creates `users`, `user_priv`, `section`, `designation` with tdh's column names/types; the base `TestCase` runs it on the `user` connection before each test (alongside `RefreshDatabase` for the default connection).
- `UserFactory` writes tdh-shaped columns (`fname`, `lname`, `username`, `password`, `section`, `status`) and, via an `afterCreating` hook, the IR `user_priv` row when a `role` state other than Staff is requested. Existing call sites `User::factory()->create(['role' => Role::X, 'department_id' => $id])` keep working through factory attribute mapping (`department_id` → `section`, `role` → privilege row).
- `DepartmentFactory` writes `section` rows.
- New tests: role resolution (IR row, no row, unknown level, other-system row ignored), `withRole(Staff)` includes no-row users, login by username, inactive user refused, write attempts on `User`/`Department` throw outside testing, logout does not touch `remember_token`, every `Role` value ≤ 30 chars.
- All 182 existing tests must pass unchanged in intent.

### 4.8 Verification

Browser pass via `php artisan serve` + Playwright (per project convention): log in as live user **1116** (Staff) and user **1** (Department Head, after the one-row insert), file a report as 1116 in IMISS, confirm user 1 sees it in the department-scoped list, confirm logout leaves `tdh_user.users.remember_token` for both users byte-identical to before.

## 5. Risks

| Risk | Mitigation |
|---|---|
| Logout rotates shared `remember_token`, breaking other systems' remember-me | `getRememberTokenName()` returns `''`; covered by a test and the browser check |
| Accidental write to live tdh_user | Model-level throw outside testing; no migrations on the `user` connection |
| Test suite hits live tdh_user | `user` connection forced to SQLite in-memory in `phpunit.xml` |
| N+1 on role lookups in lists | `privilege` relation eager-loaded where users are listed |
| Orphaned ids after a tdh user is deleted | `belongsTo` returns null; UI already tolerates null relations (`?.name`) |
| Cross-DB `whereHas` on users/departments | None exist today (verified by grep); plan notes to avoid adding any |
| Raw `DB::connection('user')` writes bypass model guards | SELECT-only MySQL account for `USER_USERNAME` (deployment requirement) |
| `DB_FOREIGN_KEYS=false` disables ALL FK enforcement in tests, including incident-internal cascades (incident_id cascades, investigation_findings → corrective_actions.root_cause_finding_id nullOnDelete, restrictOnDelete on lead_investigator_id/requested_by) | No current test depends on them (verified); behaviour that needs cascades must be made explicit in services/tests |
