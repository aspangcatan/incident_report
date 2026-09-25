# Work Queues Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Wire the sidebar's Incident Management, Investigation Workspace and CAPA Operations placeholders to real, visibility-scoped queue pages with badge counts and active-link highlighting.

**Architecture:** Two small query registries in `app/Queries/` define every queue once; the incident list (`/incidents?scope=`), a new CAPA list (`/corrective-actions?queue=`) and the shared badge counts all use them. Access follows the sidebar flags already shared as `auth.can`.

**Tech Stack:** Laravel 9.52 (PHP 8.2), Inertia v1 + @inertiajs/vue3 v2, Vue 3, Tailwind v3, PHPUnit (SQLite in-memory).

**Spec:** `docs/superpowers/specs/2026-09-25-work-queues-design.md`.

---

## Ground rules

- Keep it simple — exactly what the task says.
- Users/departments are live read-only `tdh_user` (`docs/architecture.md` §9j): never write to it; never `whereHas`/join between incident tables and users/section (subqueries on incident_report tables like `investigation_team_members`/`corrective_actions` are fine).
- No migrations in this plan. Never run migrate:fresh/reset/rollback/db:wipe/db:seed.
- `php artisan config:clear` before tests; full suite `php artisan test` (285 passing at start).
- Existing test helpers build incidents through `IncidentService` methods directly (`createDraft([... 'severity' => ...])`, `submit`, `completeAssessment`, `markReviewed`, `assignInvestigator`, …) — reuse them.
- Commit after each task with trailer `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

---

### Task 1: Team members and CAPA owners can open the incident

**Files:** `app/Policies/IncidentPolicy.php` (`view()`), `app/Models/Incident.php` (`scopeVisibleTo()`), test `tests/Feature/Incidents/IncidentAccessTest.php` (create).

- [ ] **Step 1: Failing tests** — build an incident through to `CorrectiveAction` status the way `tests/Feature/CorrectiveActions/CorrectiveActionTest.php::incidentReadyForCapa()` does (copy that helper), then:
  - a Staff user added as an investigation team member (`investigation_team_members` row via the existing service/HTTP path the tests use) → `GET /incidents/{id}` 200 and the incident appears in `Incident::visibleTo($user)`;
  - a Staff user set as `responsible_user_id` on a CAPA of that incident → 200 and visible;
  - an unrelated Staff user → 403 and not visible.

- [ ] **Step 2: Run — FAIL.**

- [ ] **Step 3: Implement.** In `IncidentPolicy::view()`, before the final `return false;`:

```php
        // People doing the work elsewhere in the lifecycle can open the incident.
        if ($incident->investigation?->teamMembers()->where('user_id', $user->id)->exists()) {
            return true;
        }

        if ($incident->correctiveActions()->where('responsible_user_id', $user->id)->exists()) {
            return true;
        }
```

In `Incident::scopeVisibleTo()`, keep the QSO/Admin/Management early return, then wrap the role-specific conditions and add the two shared ones:

```php
        return $query->where(function (Builder $q) use ($user) {
            if (in_array($user->role, [Role::Supervisor, Role::DepartmentHead], true)) {
                if ($user->department_id !== null) {
                    $q->where('department_id', $user->department_id);
                }
            } else {
                $q->where('reporter_id', $user->id)
                    ->orWhere('assigned_investigator_id', $user->id);

                if ($user->department_id !== null) {
                    $q->orWhere(fn (Builder $q) => $q
                        ->where('status', IncidentStatus::Submitted)
                        ->where('department_id', $user->department_id));
                }
            }

            $q->orWhereIn('id', Investigation::query()
                ->select('incident_id')
                ->whereIn('id', InvestigationTeamMember::query()->select('investigation_id')->where('user_id', $user->id)))
              ->orWhereIn('id', CorrectiveAction::query()->select('incident_id')->where('responsible_user_id', $user->id));
        });
```

Preserve the existing null-department behaviour for Supervisor/Department Head (they must not match incidents with a null department — with the new structure, a supervisor without a department only matches via the two shared clauses). Check the column names on `investigations`/`investigation_team_members` and import the models. Mirror exactly what `view()` allows.

- [ ] **Step 4: Full suite green** (existing visibility tests in `IncidentReportingTest`/`AnalyticsTest` must still pass unchanged). **Commit** `feat: let investigation team members and CAPA owners open their incidents`.

---

### Task 2: Incident queues

**Files:** create `app/Queries/IncidentQueueQuery.php`; modify `app/Http/Controllers/IncidentController.php` (`index()`); test `tests/Feature/Incidents/IncidentQueueTest.php` (create).

- [ ] **Step 1: Query class**

```php
<?php

namespace App\Queries;

use App\Enums\IncidentStatus;
use App\Enums\InvestigationStatus;
use App\Enums\Role;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every incident work queue, defined once. Used by the incident list
 * (/incidents?scope=…) and the sidebar badge counts, so a badge always
 * matches its page. See docs/superpowers/specs/2026-09-25-work-queues-design.md.
 */
final class IncidentQueueQuery
{
    /** scope => [title, description, badge?] */
    public const QUEUES = [
        'awaiting-assessment' => ['Awaiting Assessment', 'Submitted reports waiting for the department to assess them.', true],
        'pending-review' => ['Pending Review', 'Assessed incidents waiting for review.', true],
        'under-investigation' => ['Under Investigation', 'Incidents with an investigation in progress.', true],
        'corrective-actions' => ['Corrective Actions', 'Incidents in the corrective and preventive action stage.', true],
        'resolved' => ['Resolved / Closed', 'Verified, awaiting approval, or closed.', false],
        'investigation-queue' => ['Investigation Queue', 'Reviewed incidents waiting for an investigator or to be started.', true],
        'assigned-to-me' => ['Assigned to Me', 'Investigations assigned to you that are not finished.', true],
        'investigation-history' => ['History & Findings', 'Incidents whose investigation is completed.', false],
    ];

    public static function exists(string $queue): bool
    {
        return array_key_exists($queue, self::QUEUES);
    }

    public static function allowed(string $queue, User $user): bool
    {
        return match ($queue) {
            'awaiting-assessment' => true,
            'pending-review', 'under-investigation', 'corrective-actions', 'resolved' => $user->can('viewAny', Incident::class),
            'investigation-queue', 'assigned-to-me', 'investigation-history' => self::investigationWorkspace($user),
            default => false,
        };
    }

    public static function investigationWorkspace(User $user): bool
    {
        return in_array($user->role, [Role::Investigator, Role::Supervisor, Role::DepartmentHead, Role::QualitySafetyOfficer, Role::Administrator], true);
    }

    public static function builder(string $queue, User $user): Builder
    {
        $query = Incident::query()->where('status', '!=', IncidentStatus::Draft)->visibleTo($user);

        return match ($queue) {
            'awaiting-assessment' => $query->where('status', IncidentStatus::Submitted),
            'pending-review' => $query->where('status', IncidentStatus::ForReview),
            'under-investigation' => $query->where('status', IncidentStatus::UnderInvestigation),
            'corrective-actions' => $query->whereIn('status', [IncidentStatus::CorrectiveAction, IncidentStatus::ForVerification]),
            'resolved' => $query->whereIn('status', [IncidentStatus::Verified, IncidentStatus::ForApproval, IncidentStatus::Closed]),
            'investigation-queue' => $query->whereIn('status', [IncidentStatus::Reviewed, IncidentStatus::Assigned]),
            'assigned-to-me' => $query->where('assigned_investigator_id', $user->id)
                ->whereIn('status', [IncidentStatus::Assigned, IncidentStatus::UnderInvestigation]),
            'investigation-history' => $query->whereHas('investigation', fn (Builder $q) => $q->where('status', InvestigationStatus::Completed)),
        };
    }
}
```

(`whereHas('investigation')` is fine — both tables are in `incident_report`.) Move `investigationWorkspace()` into this class and make `HandleInertiaRequests` call `IncidentQueueQuery::investigationWorkspace($user)` instead of its inline `in_array` so the flag and the page use one rule.

- [ ] **Step 2: Failing tests** `IncidentQueueTest` — one data-driven test per queue: create incidents in each relevant status (via the service helpers; for `Submitted`/`ForReview` use `submit`/`completeAssessment`; for later statuses `forceFill(['status' => …])->save()` is acceptable in tests), GET `/incidents?scope=<queue>` as a QSO and assert `incidents.data` ids equal exactly the expected set; plus:
  - Staff GET `?scope=pending-review` → 403; Staff GET `?scope=awaiting-assessment` → 200 listing only their department's `Submitted` incidents (and not another department's);
  - Supervisor without department sees nothing in `awaiting-assessment` from null-department incidents;
  - Investigator `assigned-to-me` lists only incidents assigned to them;
  - the page passes `queue` meta: `->where('queue.title', 'Pending Review')`.

- [ ] **Step 3: Controller** — `IncidentController::index()`:

```php
        if (IncidentQueueQuery::exists($scope)) {
            abort_unless(IncidentQueueQuery::allowed($scope, $user), 403);

            [$title, $description] = IncidentQueueQuery::QUEUES[$scope];

            return Inertia::render('Incidents/Index', [
                'incidents' => IncidentQueueQuery::builder($scope, $user)
                    ->with(['incidentType', 'department', 'reporter'])
                    ->latest('id')->paginate(15)->withQueryString(),
                'scope' => $scope,
                'queue' => ['title' => $title, 'description' => $description],
            ]);
        }
```

placed before the existing `drafts`/`all`/`my-reports` branches; the existing render adds `'queue' => null`.

- [ ] **Step 4: Full suite green. Commit** `feat: incident work queues on the incident list`.

---

### Task 3: CAPA queues

**Files:** create `app/Queries/CorrectiveActionQueueQuery.php`; modify `app/Http/Controllers/CorrectiveActionController.php` (add `index()`), `routes/web.php`; test `tests/Feature/CorrectiveActions/CorrectiveActionQueueTest.php` (create). The page component comes in Task 5 — if `assertInertia(->component('CorrectiveActions/Index'))` requires the file to exist, create a placeholder `resources/js/Pages/CorrectiveActions/Index.vue` (`<template><div /></template>`) and say so.

- [ ] **Step 1: Query class**

```php
<?php

namespace App\Queries;

use App\Enums\CorrectiveActionStatus;
use App\Enums\Role;
use App\Models\CorrectiveAction;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/** Every CAPA work queue, defined once — used by /corrective-actions?queue=… and the sidebar badges. */
final class CorrectiveActionQueueQuery
{
    /** queue => [title, description, badge?] */
    public const QUEUES = [
        'open' => ['Open Actions', 'Corrective actions not yet completed.', true],
        'for-verification' => ['For Verification', 'Completed actions waiting to be verified.', true],
        'overdue' => ['Overdue Actions', 'Actions past their due date and not yet verified.', true],
        'completed' => ['Completed Archive', 'Verified corrective actions.', false],
    ];

    public static function exists(string $queue): bool
    {
        return array_key_exists($queue, self::QUEUES);
    }

    /** Also the sidebar's capaOperations flag. */
    public static function allowed(User $user): bool
    {
        return in_array($user->role, [Role::Supervisor, Role::DepartmentHead, Role::QualitySafetyOfficer, Role::Administrator], true)
            || CorrectiveAction::where('responsible_user_id', $user->id)->exists();
    }

    public static function builder(string $queue, User $user): Builder
    {
        $query = CorrectiveAction::query()->where(fn (Builder $q) => $q
            ->whereIn('incident_id', Incident::query()->select('id')->visibleTo($user))
            ->orWhere('responsible_user_id', $user->id));

        return match ($queue) {
            'open' => $query->whereIn('status', [CorrectiveActionStatus::Open, CorrectiveActionStatus::InProgress]),
            'for-verification' => $query->where('status', CorrectiveActionStatus::ForVerification),
            'overdue' => $query->overdue(),
            'completed' => $query->where('status', CorrectiveActionStatus::Verified),
        };
    }
}
```

`HandleInertiaRequests`'s `capaOperations` flag becomes `CorrectiveActionQueueQuery::allowed($user)`.

- [ ] **Step 2: Failing tests** — per queue, a QSO sees exactly the CAPAs in the right statuses (overdue: due date in the past and not verified); a Staff user responsible for one CAPA sees it in `open` and gets `auth.can.capaOperations === true`; an unrelated Staff user gets 403 and `capaOperations === false`; unknown queue → 404; each row has `capa_number`, `description`, `incident` {id, incident_number}, `responsible` name, `priority` label, `due_date`, `is_overdue`, `status` {value,label}.

- [ ] **Step 3: Controller + route** — `CorrectiveActionController::index()`:

```php
    public function index(Request $request): Response
    {
        $queue = $request->string('queue', 'open')->toString();
        abort_unless(CorrectiveActionQueueQuery::exists($queue), 404);
        abort_unless(CorrectiveActionQueueQuery::allowed($request->user()), 403);

        [$title, $description] = CorrectiveActionQueueQuery::QUEUES[$queue];

        $actions = CorrectiveActionQueueQuery::builder($queue, $request->user())
            ->with(['incident:id,incident_number', 'responsibleUser'])
            ->orderBy('due_date')->orderBy('id')
            ->paginate(15)->withQueryString()
            ->through(fn (CorrectiveAction $action) => [
                'id' => $action->id,
                'capa_number' => $action->capa_number,
                'description' => $action->description,
                'incident' => ['id' => $action->incident->id, 'incident_number' => $action->incident->incident_number],
                'responsible' => $action->responsibleUser?->name,
                'priority' => $action->priority->label(),
                'due_date' => $action->due_date?->toDateString(),
                'is_overdue' => $action->due_date !== null && $action->due_date->isPast() && $action->status !== CorrectiveActionStatus::Verified,
                'status' => ['value' => $action->status->value, 'label' => $action->status->label()],
            ]);

        return Inertia::render('CorrectiveActions/Index', [
            'actions' => $actions,
            'queue' => ['key' => $queue, 'title' => $title, 'description' => $description],
        ]);
    }
```

Route (inside the auth group, before the `{correctiveAction}` routes): `Route::get('/corrective-actions', [CorrectiveActionController::class, 'index'])->name('corrective-actions.index');`. Check the controller's existing constructor/imports and that `due_date` is cast to a date.

- [ ] **Step 4: Full suite green. Commit** `feat: corrective action work queues`.

---

### Task 4: Badge counts

**Files:** `app/Http/Middleware/HandleInertiaRequests.php`; test `tests/Feature/QueueCountsTest.php` (create).

- [ ] **Step 1: Failing tests** — for a QSO with incidents in `Submitted`/`ForReview` and one overdue CAPA: shared `queueCounts` equals `['awaiting-assessment' => n1, 'pending-review' => n2, 'overdue' => 1, …]` with zero counts omitted and non-badge queues (`resolved`, `investigation-history`, `completed`) absent; for each present key the count equals the queue page's `incidents.total` / `actions.total`; a Staff user never gets keys for queues they can't open.

- [ ] **Step 2: Implement** — add to `share()`:

```php
            'queueCounts' => fn () => ($user = $request->user()) ? $this->queueCounts($user) : [],
```

and

```php
    /** Badge counts, from the same builders the queue pages use. Zero counts are omitted. */
    private function queueCounts(User $user): array
    {
        $counts = [];

        foreach (IncidentQueueQuery::QUEUES as $queue => [, , $badge]) {
            if ($badge && IncidentQueueQuery::allowed($queue, $user)) {
                $counts[$queue] = IncidentQueueQuery::builder($queue, $user)->count();
            }
        }

        if (CorrectiveActionQueueQuery::allowed($user)) {
            foreach (CorrectiveActionQueueQuery::QUEUES as $queue => [, , $badge]) {
                if ($badge) {
                    $counts[$queue] = CorrectiveActionQueueQuery::builder($queue, $user)->count();
                }
            }
        }

        return array_filter($counts);
    }
```

(Incident and CAPA queue keys don't collide. Draft Reports keeps no badge.)

- [ ] **Step 3: Full suite green. Commit** `feat: sidebar queue badge counts`.

---

### Task 5: Frontend

**Files:** `resources/js/Layouts/AuthenticatedLayout.vue`, `resources/js/Pages/Incidents/Index.vue`, create/replace `resources/js/Pages/CorrectiveActions/Index.vue`.

- [ ] **Step 1: Sidebar** — set real hrefs and a `queue` key on the items:
  - Incident Management: add **Awaiting Assessment** (`/incidents?scope=awaiting-assessment`, queue `awaiting-assessment`, icon `clipboard-list` or another already-registered Font Awesome icon — check the icon registry in `resources/js/app.js`/wherever icons are `library.add`-ed and register a new one if needed), always visible; Pending Review → `?scope=pending-review`; Under Investigation → `?scope=under-investigation`; Corrective Actions → `?scope=corrective-actions`; Resolved / Closed → `?scope=resolved`.
  - Investigation Workspace: `?scope=investigation-queue`, `?scope=assigned-to-me`, `?scope=investigation-history`.
  - CAPA Operations: `/corrective-actions?queue=open|for-verification|overdue|completed`.
  - Badge: replace `count: null` with the lookup `page.props.queueCounts?.[item.queue]`; render the badge only when truthy; Overdue uses `bg-error text-on-error`, others keep the current badge classes.
  - Active link: a computed `isActive(href)` comparing `new URL(href, window.location.origin)` path + its `scope`/`queue` param with the current `page.url` (Inertia's `usePage().url`); `/incidents` with no scope counts as `my-reports`. Active style = the Dashboard item's current active classes; make the Dashboard item use `isActive('/')` too, and Executive Overview `isActive('/analytics')`. Use Inertia `<Link>` for the nav items instead of `<a>` if the rest of the layout does (keep `<a>` otherwise).

- [ ] **Step 2: `Incidents/Index.vue`** — accept prop `queue: { type: Object, default: null }`. When `queue` is set: page heading = `queue.title`, a one-line `queue.description` under it, and hide the My Reports/Drafts/All switcher; empty state text = `Nothing in ${queue.title} right now.`. Otherwise unchanged.

- [ ] **Step 3: `CorrectiveActions/Index.vue`** — `AuthenticatedLayout`; heading `queue.title` + description; table columns: CAPA No. (`capa_number`), Description (truncate ~80 chars), Incident (link `/incidents/{id}?tab=capa` showing `incident_number` — check the CAPA tab key used by `Incidents/Show.vue`), Responsible, Priority, Due (red text when `is_overdue`), Status (plain pill); reuse `Components/Pagination.vue` the way `Incidents/Index.vue` does; empty state `Nothing in ${queue.title} right now.`. Match `Incidents/Index.vue` table styling.

- [ ] **Step 4:** `npm run build` succeeds; full suite green. **Commit** `feat: work queue pages, sidebar badges and active highlighting`.

---

### Task 6: Documentation

- [ ] Add "§9m. Work Queues (2026-09-25)" to `docs/architecture.md`: queue table (key, definition, access), the two registries, badge counts from the same builders, the access fix (team members / CAPA owners), `capaOperations` now includes CAPA owners, active highlighting. Update §9b's "sidebar wiring"/badge-count notes as superseded. Commit `docs: document work queues`.

---

## After Task 6 (controller session)

Holistic review; browser check with the local test sessions (users 1116 Staff, 1 Department Head): each wired item opens, badges equal list totals, active item highlighted; update memory.
