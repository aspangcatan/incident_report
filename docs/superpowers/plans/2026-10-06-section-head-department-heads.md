# Department Heads from `section.head` Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A user is Department Head of every section whose `tdh_user.section.head` is their id — on top of any IR level — replacing the `department_head` IR level.

**Architecture:** Three `User` methods (`headedDepartmentIds()`, `isDepartmentHead()`, `isHeadOf()`) become the single source of "is head"; every `Role::DepartmentHead` check is rewritten to use them; `Role::DepartmentHead` is removed last. A temporary `UserFactory` shim keeps the existing tests green until the last task converts them.

**Tech Stack:** Laravel 9.52 (PHP 8.2), Inertia, PHPUnit on in-memory SQLite (tdh tables built by `tests/Support/TdhTestSchema.php`).

**Spec:** `docs/superpowers/specs/2026-10-06-section-head-department-heads-design.md`

**Project rules for every task:**
- `php artisan config:clear` before `php artisan test`. `php artisan test a b` runs only the first path — run one path at a time, or the whole suite (~8 min; use a long timeout).
- Never commit `config/incident_workflow.php` or `app/DataTransferObjects/Investigations/AddTeamMemberData.php`. Always `git add` explicit paths.
- Use the Edit tool for PHP edits (scripted sed/python edits have mangled backslashes in this repo).
- Commit on master; messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Keep it simple: implement exactly the rule, no extra guards.

---

### Task 1: `User` head methods + factory support

**Files:**
- Modify: `app/Models/User.php` (next to `leadershipDepartmentIds()`)
- Modify: `database/factories/UserFactory.php`
- Test: `tests/Feature/Tdh/SectionHeadTest.php` (create)

- [ ] **Step 1: Write the failing tests** — `tests/Feature/Tdh/SectionHeadTest.php`:

```php
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
```

- [ ] **Step 2: Run** `php artisan config:clear && php artisan test tests/Feature/Tdh/SectionHeadTest.php` — Expected: FAIL (`headOf` / `headedDepartmentIds` undefined).

- [ ] **Step 3: Implement.** In `app/Models/User.php`, below `leadershipDepartmentIds()`:

```php
    private ?array $headedDepartmentIdsCache = null;

    /** Sections whose tdh_user.section.head is this user — they are its Department/Service Head. */
    public function headedDepartmentIds(): array
    {
        return $this->headedDepartmentIdsCache ??= Department::where('head', $this->id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function isDepartmentHead(): bool
    {
        return $this->headedDepartmentIds() !== [];
    }

    public function isHeadOf(?int $departmentId): bool
    {
        return $departmentId !== null && in_array($departmentId, $this->headedDepartmentIds(), true);
    }
```

In `database/factories/UserFactory.php` add a state (and `use App\Models\Department;`):

```php
    /** Make the user the head (tdh_user.section.head) of these sections. */
    public function headOf(Department|int ...$departments): static
    {
        return $this->afterCreating(function (User $user) use ($departments) {
            foreach ($departments as $department) {
                Department::whereKey($department instanceof Department ? $department->id : $department)
                    ->update(['head' => $user->id]);
            }
        });
    }
```

**Temporary shim** (removed in Task 5) so the existing suite keeps passing while call sites move to `isHeadOf()`: in `configure()`'s `afterCreating`, after creating the `UserPrivilege` row, add:

```php
            // TEMPORARY (removed in Task 5): old tests make heads via 'role' => DepartmentHead.
            if ($role === Role::DepartmentHead && $user->department_id !== null) {
                Department::whereKey($user->department_id)->update(['head' => $user->id]);
            }
```

`Department` is a `ReadOnlyTdhModel`; writes are allowed in tests on SQLite — if `update()` is refused, read `app/Models/Concerns/ReadOnlyTdhModel.php` and use whatever the factories already use to write tdh rows.

- [ ] **Step 4: Run** the test file — Expected: 3 passed. Then the full suite — Expected: all pass (nothing reads the new methods yet).

- [ ] **Step 5: Commit** `app/Models/User.php database/factories/UserFactory.php tests/Feature/Tdh/SectionHeadTest.php` — "feat: users head the sections whose tdh head column is their id".

---

### Task 2: Policies use `isHeadOf()`

**Files:**
- Modify: `app/Policies/IncidentPolicy.php`, `app/Policies/CorrectiveActionPolicy.php`
- Test: `tests/Feature/Tdh/SectionHeadTest.php`

Rewrite every Department Head check. Exact changes in `IncidentPolicy`:

```php
    public function viewAny(User $user): bool
    {
        return ! in_array($user->role, [Role::Staff, Role::Administrator], true) || $user->isDepartmentHead();
    }

    public function viewAnalytics(User $user): bool
    {
        return in_array($user->role, [
            Role::QualitySafetyOfficer,
            Role::Management,
            Role::CqiCommittee,
            Role::Leadership,
            Role::Supervisor,
        ], true) || $user->isDepartmentHead();
    }
```

In `view()`, replace the `Supervisor, DepartmentHead` block and keep Leadership:

```php
        if ($user->isHeadOf($incident->department_id)) {
            return true;
        }

        if ($user->role === Role::Supervisor) {
            return $incident->department_id !== null && $incident->department_id === $user->department_id;
        }
```

(put the `isHeadOf` check before the Supervisor/Leadership checks, after `seesAllIncidents()`).

`assess()`: `return ($user->department_id !== null && $incident->department_id === $user->department_id) || $user->isHeadOf($incident->department_id);`

`recommendInvestigator()`: `return ($user->role === Role::Supervisor && $user->department_id !== null && $incident->department_id === $user->department_id) || $user->isHeadOf($incident->department_id);`

`completeAssessment()`: last return becomes `return $user->isHeadOf($incident->department_id);`

`confirmEvidencePreserved()`: replace the role/department lines with
`&& (($user->role === Role::Supervisor && $incident->department_id !== null && $incident->department_id === $user->department_id) || $user->isHeadOf($incident->department_id));`

`isHeadOfIncidentDepartment()`: `return $user->isHeadOf($incident->department_id);`

`CorrectiveActionPolicy`:

```php
    public function verify(User $user, CorrectiveAction $correctiveAction): bool
    {
        // ... status and completed_by checks unchanged ...
        $incident = $correctiveAction->incident;

        return ($user->role === Role::Supervisor && $this->belongsToIncidentDepartment($user, $incident))
            || $user->isHeadOf($incident->department_id);
    }

    private function isHeadOfIncidentDepartment(User $user, Incident $incident): bool
    {
        return $user->isHeadOf($incident->department_id);
    }
```

Update the class docblock wording ("its Department Head (tdh section head)").

- [ ] **Step 1: Write failing tests** — append to `SectionHeadTest` (add imports `App\Enums\IncidentStatus`, `App\Enums\Severity`, `App\Models\Incident`, `App\Models\IncidentType`, `App\Services\IncidentService`):

```php
    /** A submitted incident in $department. */
    private function submittedIncident(Department $department): Incident
    {
        $service = app(IncidentService::class);
        $incident = $service->createDraft(User::factory()->create(), [
            'department_id' => $department->id,
            'incident_type_ids' => [IncidentType::factory()->create()->id],
            'occurred_at' => now(),
            'location' => 'Ward 3',
            'summary' => 'Test incident.',
        ]);
        $service->submit($incident);

        return $incident->fresh();
    }

    public function test_a_staff_level_head_assesses_incidents_of_the_section_they_head_only(): void
    {
        [$own, $headed] = Department::factory()->count(2)->create();
        $head = User::factory()->headOf($headed)->create(['department_id' => $own->id]);

        $inHeaded = $this->submittedIncident($headed);
        $inOwn = $this->submittedIncident($own);

        $this->assertTrue($head->can('viewAny', Incident::class));
        $this->assertTrue($head->can('viewAnalytics', Incident::class));
        $this->assertTrue($head->can('view', $inHeaded));
        $this->assertTrue($head->can('completeAssessment', $inHeaded));
        $this->assertTrue($head->can('recommendInvestigator', $inHeaded));
        $this->assertFalse($head->can('completeAssessment', $inOwn));
    }

    public function test_a_leadership_user_who_heads_a_section_gets_both_scopes(): void
    {
        [$mapped, $headed, $other] = Department::factory()->count(3)->create();
        $leader = User::factory()->headOf($headed)->create(['role' => Role::Leadership]);
        \Illuminate\Support\Facades\DB::table('leadership_departments')->insert([
            'user_id' => $leader->id, 'department_id' => $mapped->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertTrue($leader->can('view', $this->submittedIncident($mapped)));
        $this->assertFalse($leader->can('completeAssessment', $this->submittedIncident($mapped)));
        $this->assertTrue($leader->can('completeAssessment', $this->submittedIncident($headed)));
        $this->assertFalse($leader->can('view', $this->submittedIncident($other)));
    }
```

- [ ] **Step 2: Run** the file — Expected: the 2 new tests FAIL.
- [ ] **Step 3: Implement** the policy changes above.
- [ ] **Step 4: Run** the file (5 passed), then `tests/Feature/Incidents`, `tests/Feature/CorrectiveActions`, `tests/Feature/Approvals`, `tests/Feature/ClientRolesAccessTest.php` one at a time — all pass (the factory shim makes old heads real section heads).
- [ ] **Step 5: Commit** — "feat: department head permissions come from the section head".

---

### Task 3: Visibility scopes, queues and sidebar

**Files:**
- Modify: `app/Models/Incident.php` (`scopeVisibleTo`), `app/Models/RecurrenceReview.php` (`scopeVisibleTo`), `app/Queries/IncidentQueueQuery.php`, `app/Queries/CorrectiveActionQueueQuery.php`
- Test: `tests/Feature/Tdh/SectionHeadTest.php`

`Incident::scopeVisibleTo` — inside the `where` closure, replace the `if Supervisor/DepartmentHead … elseif Leadership … else …` block with:

```php
            if ($user->role === Role::Supervisor) {
                // Without a department they get no department clause, only the shared ones.
                if ($user->department_id !== null) {
                    $q->orWhere('department_id', $user->department_id);
                }
            } elseif ($user->role === Role::Leadership) {
                $q->orWhereIn('department_id', $user->leadershipDepartmentIds());
            } elseif ($user->department_id !== null) {
                $q->orWhere(fn (Builder $q) => $q
                    ->where('status', IncidentStatus::Submitted)
                    ->where('department_id', $user->department_id));
            }

            // Department/Service Heads see every incident of the sections they head.
            if ($user->isDepartmentHead()) {
                $q->orWhereIn('department_id', $user->headedDepartmentIds());
            }
```

`RecurrenceReview::scopeVisibleTo` — the Supervisor/DepartmentHead line becomes `if ($user->role === Role::Supervisor && $user->department_id !== null)`, and add:

```php
            if ($user->isDepartmentHead()) {
                $q->orWhereIn('department_id', $user->headedDepartmentIds());
            }
```

`IncidentQueueQuery::investigationWorkspace`: `in_array($user->role, [Role::Investigator, Role::Supervisor, Role::QualitySafetyOfficer], true) || $user->isDepartmentHead() || Incident::where(...)->exists()`.

`CorrectiveActionQueueQuery`: introduce

```php
    /** Focal Persons, Department Heads and the CQI Office oversee CAPAs. */
    private static function oversees(User $user): bool
    {
        return in_array($user->role, [Role::Supervisor, Role::QualitySafetyOfficer], true) || $user->isDepartmentHead();
    }
```

and use it in `allowed()` (`self::oversees($user) || CorrectiveAction::where(...)->exists()`) and `builder()` (`$query = self::oversees($user) ? ... : ...`).

- [ ] **Step 1: Failing tests** — append:

```php
    public function test_a_head_lists_incidents_and_gets_queues_for_the_sections_they_head(): void
    {
        [$own, $headed, $other] = Department::factory()->count(3)->create();
        $head = User::factory()->headOf($headed)->create(['department_id' => $own->id]);
        $visible = $this->submittedIncident($headed);
        $hidden = $this->submittedIncident($other);

        $ids = Incident::query()->visibleTo($head)->pluck('id')->all();
        $this->assertContains($visible->id, $ids);
        $this->assertNotContains($hidden->id, $ids);

        $this->assertTrue(\App\Queries\IncidentQueueQuery::investigationWorkspace($head));
        $this->assertTrue(\App\Queries\CorrectiveActionQueueQuery::allowed($head));

        $this->actingAs($head)->get('/')->assertInertia(fn ($page) => $page
            ->where('auth.can.viewAllIncidents', true)
            ->where('auth.can.viewAnalytics', true)
            ->where('auth.can.investigationWorkspace', true)
            ->where('auth.can.capaOperations', true));
    }
```

- [ ] **Step 2: Run** — FAIL. **Step 3: Implement.** **Step 4: Run** the file, then `tests/Feature/Analytics`, `tests/Feature/Dashboard`, `tests/Feature/QueueCountsTest.php`, `tests/Feature/RecurrenceReviewTest.php`, `tests/Feature/SidebarPermissionsTest.php` — all pass.
- [ ] **Step 5: Commit** — "feat: section heads see and work the queues of the sections they head".

---

### Task 4: Who gets notified / can be assigned

**Files:**
- Modify: `app/Support/IncidentReviewers.php`, `app/Http/Requests/RecurrenceReviews/StoreRecurrenceReviewRequest.php` (`assignees()`)
- Test: `tests/Feature/Tdh/SectionHeadTest.php`

`IncidentReviewers`:

```php
/** People who assess/triage an incident: the CQI Office, plus the Focal Persons and the Head of its department. */
final class IncidentReviewers
{
    public static function for(Incident $incident): Collection
    {
        $reviewers = User::active()->where(function ($query) use ($incident) {
            $query->withRole(Role::QualitySafetyOfficer);

            if ($incident->department_id !== null) {
                $query->orWhere(fn ($query) => $query->withRole(Role::Supervisor)->where('section', $incident->department_id));
            }
        })->get();

        return $reviewers->merge(self::departmentHeads($incident))->unique('id')->values();
    }

    /** The head (tdh_user.section.head) of the incident's department, if active. */
    public static function departmentHeads(Incident $incident): Collection
    {
        $headId = $incident->department_id !== null ? Department::whereKey($incident->department_id)->value('head') : null;

        return $headId ? User::active()->whereKey($headId)->get() : new Collection();
    }
}
```

(add `use App\Models\Department;`; `merge` on an Eloquent Collection keeps it Eloquent — check the return type still satisfies callers.)

`StoreRecurrenceReviewRequest::assignees()`:

```php
    /** Who can own a review for a department: its active Head (section head) and Safety Focal Person(s). */
    public static function assignees(int $departmentId)
    {
        $headId = Department::whereKey($departmentId)->value('head');

        return User::active()
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->withRole(\App\Enums\Role::Supervisor)->where('section', $departmentId))
                ->when($headId, fn ($q) => $q->orWhere('id', $headId)))
            ->orderByName()
            ->get();
    }
```

Check the error message `"Choose the department's Head or Safety Focal Person."` still fits (it does).

- [ ] **Step 1: Failing tests** — append:

```php
    public function test_the_section_head_is_notified_and_a_non_head_colleague_is_not(): void
    {
        $section = Department::factory()->create();
        $head = User::factory()->headOf($section)->create();          // lives in another section
        $colleague = User::factory()->create(['department_id' => $section->id]);
        $incident = $this->submittedIncident($section);

        $reviewers = \App\Support\IncidentReviewers::for($incident)->pluck('id')->all();
        $this->assertContains($head->id, $reviewers);
        $this->assertNotContains($colleague->id, $reviewers);
        $this->assertSame([$head->id], \App\Support\IncidentReviewers::departmentHeads($incident)->pluck('id')->all());
    }

    public function test_an_inactive_head_is_not_notified(): void
    {
        $section = Department::factory()->create();
        User::factory()->inactive()->headOf($section)->create();

        $this->assertCount(0, \App\Support\IncidentReviewers::departmentHeads($this->submittedIncident($section)));
    }

    public function test_a_recurrence_review_can_be_assigned_to_the_section_head(): void
    {
        $section = Department::factory()->create();
        $head = User::factory()->headOf($section)->create();

        $this->assertContains($head->id, \App\Http\Requests\RecurrenceReviews\StoreRecurrenceReviewRequest::assignees($section->id)->pluck('id')->all());
    }
```

- [ ] **Step 2: Run** — FAIL. **Step 3: Implement.** **Step 4: Run** the file, then `tests/Feature/EscalationRecipientsTest.php`, `tests/Feature/SeverityAlertTest.php`, `tests/Feature/EscalationCommandTest.php`, `tests/Feature/RecurrenceReviewTest.php`, `tests/Feature/Incidents` — all pass. If a test relied on two Department Heads of the same section being notified, the shim makes the *second* one the head; fix such a test only if it fails, by keeping one head.
- [ ] **Step 5: Commit** — "feat: the section head receives department-head notifications and recurrence reviews".

---

### Task 5: Retire `Role::DepartmentHead`

**Files:**
- Modify: `app/Enums/Role.php` (remove the case and its label)
- Modify: `database/factories/UserFactory.php` (remove the Task 1 shim)
- Modify: every test using `Role::DepartmentHead` (list: `grep -rln "Role::DepartmentHead" tests`)
- Modify: any remaining app reference (`grep -rn "DepartmentHead" app resources/js` — `NotifyDepartmentHeadsOfReturn` is a class name, keep it)

- [ ] **Step 1:** Remove `case DepartmentHead` and its `label()` arm from `App\Enums\Role`. Remove the shim from `UserFactory::configure()`.
- [ ] **Step 2:** Convert every test: `User::factory()->create(['role' => Role::DepartmentHead, 'department_id' => $d->id])` → `User::factory()->headOf($d)->create(['department_id' => $d->id])` (keep `department_id` so "same department" staff behaviour is unchanged). Where no department is given, give the head a department created in the test. In `SidebarPermissionsTest`, drop the `department head` dataset row and add a test that a Staff user who heads a section gets `viewAllIncidents`, `investigationWorkspace`, `capaOperations`, `viewAnalytics` = true and `administration`/`manageIncidentTypes` = false. In `tests/Feature/Tdh/TdhUserTest.php`, any test of the `department_head` level must now expect Staff (and the unknown-level warning, if that file already asserts warnings for unknown levels).
- [ ] **Step 3:** Add to `SectionHeadTest`:

```php
    public function test_the_retired_department_head_level_resolves_to_staff(): void
    {
        $user = User::factory()->create();
        \App\Models\UserPrivilege::create(['user_id' => $user->id, 'syscode' => config('tdh.syscode'), 'level' => 'department_head']);
        $user->unsetRelation('privilege');

        $this->assertSame(Role::Staff, $user->role);
    }
```

- [ ] **Step 4:** Run the full suite — all pass. Fix test-only fallout (e.g. two heads for one section → one head). If any **app** behaviour has to change to make a test pass, stop and report instead.
- [ ] **Step 5:** `npm run build` (Role labels may be shown in Vue).
- [ ] **Step 6: Commit** — "refactor: retire the department_head IR level".

---

### Task 6: Docs

- [ ] Append to `docs/architecture.md` a section "9r. Department Heads from section.head (2026-10-06)" summarising the spec's Rules and the `User` methods, and mark the older role table rows/sections that mention the `department_head` level as superseded (one-line note, don't rewrite history). Commit `docs/architecture.md` — "docs: department heads from section.head".

---

## After all tasks (controller)

- Holistic review of `git diff <start>..HEAD` against the spec.
- Browser check on scratch SQLite: a Staff user who heads section B (but belongs to A) sees B's incident in lists, completes its assessment; the sidebar shows the department-head items; user who heads nothing doesn't.
