# Phase 5: Investigation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a lead investigator (the user assigned in Phase 4) start a formal investigation on a `reviewed`→`assigned` incident, staff a small investigation team, record Root Cause Analysis findings under one of the three configured methodologies (5 Whys / Fishbone / HFACS), and complete the investigation with a conclusion — advancing the incident to `corrective_action`. All gated by policy, all logged to the existing audit trail, all covered by the existing daily SLA-escalation sweep.

**Architecture — layered, per explicit user direction (2026-09-18):** this phase (and this phase only — Phases 1–4 keep their existing shape) is built with a fuller layering than the Service+Policy+FormRequest pattern used through Phase 4:

1. **Enums** — `InvestigationStatus`, `InvestigationMethodology` (already shipped, Task 2).
2. **Models** — `Investigation`, `InvestigationTeamMember`, `InvestigationFinding` (already shipped, Task 3).
3. **DTOs** (`app/DataTransferObjects/Investigations/`) — typed, readonly value objects (`StartInvestigationData`, `AddTeamMemberData`, `FindingData`, `CompleteInvestigationData`) built from a Form Request's `validated()` array via a `fromArray()` factory. Replaces passing raw associative arrays between layers.
4. **Repositories** (`app/Repositories/`) — one per model (`InvestigationRepository`, `InvestigationTeamMemberRepository`, `InvestigationFindingRepository`). Thin wrappers around Eloquent create/update/delete — the seam between the Service layer and persistence, per explicit user direction, even though there's only one persistence backend today.
5. **Queries** (`app/Queries/`) — dedicated classes for non-trivial/reusable reads. Only one exists in this phase: `OverdueInvestigationsQuery` (used by the daily escalation sweep).
6. **Services** (`app/Services/InvestigationService.php`) — unchanged responsibility from the original plan (owns the lifecycle: `start()`, `addTeamMember()`/`removeTeamMember()`, `addFinding()`/`updateFinding()`/`deleteFinding()`, `complete()`), but now built on Repositories instead of calling Eloquent directly, and accepting DTOs instead of raw arrays for multi-field inputs.
7. **Actions** (`app/Actions/Investigations/`) — one thin, single-purpose, invokable class per use case (`StartInvestigationAction`, `AddTeamMemberAction`, `RemoveTeamMemberAction`, `AddFindingAction`, `UpdateFindingAction`, `DeleteFindingAction`, `CompleteInvestigationAction`). Per explicit user direction, **Actions call into Services** — the Service keeps the business logic, the Action is the controller-facing entry point that resolves any request-level concerns (e.g. looking up a `User` by id) and delegates.
8. **Resources** (`app/Http/Resources/`, doubling as "Api Resources" per explicit user clarification — one layer, not two) — `InvestigationResource`, `InvestigationTeamMemberResource`, `InvestigationFindingResource` shape the data sent to the `investigation` Inertia prop, including pre-computed `methodology.label`/`uses_sequence`/`uses_category` and `status.label`, so the Vue layer doesn't duplicate the enums' label logic.
9. **Policies** — one method added to the existing `IncidentPolicy` (`start` — gated on the *incident*, alongside `review`/`assign`, since Laravel resolves a policy by the class of the object passed to `can()`) plus a new `InvestigationPolicy` (`manageTeam`/`recordFindings`/`complete` — gated on the *investigation instance*).
10. **Controllers/routes** — `InvestigationController` now only wires Form Requests → DTOs → Actions → redirects; it holds no business logic itself.

`start()` and `complete()` still drive the `Incident.status` transitions `assigned → under_investigation → corrective_action`, so `IncidentObserver` (Phase 4) keeps writing `status_changed` audit rows automatically; the two investigation-specific audit actions (`investigation_started`, `finding_added`, plus this phase's `investigation_completed`) are written explicitly by the Service, via the existing `AuditLog::record()` helper — the observer only fires on `Incident` attribute changes, not on `Investigation` row changes. Escalation reuses the existing `IncidentEscalationNotification` and the existing daily `incidents:check-overdue` command (no new Event/Listener/Notification classes) by adding a third sweep method that reads through `OverdueInvestigationsQuery` and writes through `InvestigationRepository::markEscalated()`. One new Vue component, `InvestigationPanel.vue`, replaces the "Investigation & Root Cause" tab's placeholder in `Show.vue`.

**Tech Stack:** Laravel 9 (PHP 8.2 backed enums, Eloquent, Form Requests, Policies), Vue 3 + Inertia (`useForm`, `ConfirmationDialog.vue` — unchanged stack from Phases 3–4).

**Deliberately out of scope:** CAPA/Approvals/Closure/analytics (later phases). Retrofitting Phases 1–4's existing `IncidentService`/`IncidentController`/etc. into this same layered architecture — explicitly declined by the user; those stay as they are. Repository *interfaces*/contracts bound in a service provider — the user asked for Repository classes, not swappable implementations behind interfaces, and there's only one persistence backend, so a contract layer would be pure ceremony.

**A note on the Stitch mockup vs. the schema:** the mockup's RCA workbench shows a live pill-toggle between "5 Whys / Fishbone / HFACS" as if a viewer could switch methodology on the fly. The schema (§2.3) stores one `methodology` value *per investigation*, chosen once at `start()`. This plan renders the investigation's one chosen methodology (read-only badge) and shapes the "Add finding" form to match it — a deliberate, small deviation from the visual mockup to stay faithful to the schema.

---

## File Structure

**Backend — new files (this phase):**
- `database/migrations/2026_09_18_000006_create_investigations_table.php` ✅ (Task 1, committed `0394392`/`6ee7b67`)
- `database/migrations/2026_09_18_000007_create_investigation_team_members_table.php` ✅
- `database/migrations/2026_09_18_000008_create_investigation_findings_table.php` ✅
- `app/Enums/InvestigationStatus.php` ✅ (Task 2, committed `0283200`)
- `app/Enums/InvestigationMethodology.php` ✅
- `app/Models/Investigation.php` ✅ (Task 3, committed `984b59e`)
- `app/Models/InvestigationTeamMember.php` ✅
- `app/Models/InvestigationFinding.php` ✅
- `app/DataTransferObjects/Investigations/StartInvestigationData.php` (Task 4)
- `app/DataTransferObjects/Investigations/AddTeamMemberData.php` (Task 4)
- `app/DataTransferObjects/Investigations/FindingData.php` (Task 4)
- `app/DataTransferObjects/Investigations/CompleteInvestigationData.php` (Task 4)
- `app/Repositories/InvestigationRepository.php` (Task 5)
- `app/Repositories/InvestigationTeamMemberRepository.php` (Task 5)
- `app/Repositories/InvestigationFindingRepository.php` (Task 5)
- `app/Queries/OverdueInvestigationsQuery.php` (Task 5)
- `app/Services/InvestigationService.php` (Task 6 — supersedes the Task-4-from-the-original-plan version already committed at `2fc7a87`; this task rewrites it in place)
- `tests/Feature/Investigations/InvestigationTest.php` (Task 6 — supersedes the version at `2fc7a87`)
- `app/Policies/InvestigationPolicy.php` (Task 7)
- `tests/Feature/Investigations/InvestigationEscalationTest.php` (Task 8)
- `app/Actions/Investigations/StartInvestigationAction.php` (Task 9)
- `app/Actions/Investigations/AddTeamMemberAction.php` (Task 9)
- `app/Actions/Investigations/RemoveTeamMemberAction.php` (Task 9)
- `app/Actions/Investigations/AddFindingAction.php` (Task 9)
- `app/Actions/Investigations/UpdateFindingAction.php` (Task 9)
- `app/Actions/Investigations/DeleteFindingAction.php` (Task 9)
- `app/Actions/Investigations/CompleteInvestigationAction.php` (Task 9)
- `app/Http/Requests/Investigations/StartInvestigationRequest.php` (Task 9)
- `app/Http/Requests/Investigations/AddTeamMemberRequest.php` (Task 9)
- `app/Http/Requests/Investigations/AddFindingRequest.php` (Task 9)
- `app/Http/Requests/Investigations/UpdateFindingRequest.php` (Task 9)
- `app/Http/Requests/Investigations/CompleteInvestigationRequest.php` (Task 9)
- `app/Http/Controllers/InvestigationController.php` (Task 9)
- `app/Http/Resources/InvestigationResource.php` (Task 10)
- `app/Http/Resources/InvestigationTeamMemberResource.php` (Task 10)
- `app/Http/Resources/InvestigationFindingResource.php` (Task 10)
- `resources/js/Components/Incidents/InvestigationPanel.vue` (Task 11)

**Backend — modified files:**
- `app/Models/Incident.php` (add `investigation()` relation) ✅ (Task 3)
- `app/Policies/IncidentPolicy.php` (add `start()`) (Task 7)
- `app/Providers/AuthServiceProvider.php` (register `InvestigationPolicy`) (Task 7)
- `app/Console/Commands/CheckOverdueIncidents.php` (add `escalateOverdueInvestigations()` via `OverdueInvestigationsQuery`) (Task 8)
- `routes/web.php` (investigation routes) (Task 9)
- `app/Http/Controllers/IncidentController.php` (`show()`: add `investigation` resource prop, `can` flags, `potentialTeamMembers`) (Task 10)

**Frontend — modified files:**
- `resources/js/Pages/Incidents/Show.vue` (mount `InvestigationPanel`, pass the top-level `investigation` prop) (Task 12)

---

### Tasks 1–3: Migrations, Enums, Models — ✅ ALREADY COMPLETE

These are unaffected by the architecture change (a Repository wraps a Model either way; an Enum is an Enum) and are already implemented, reviewed, and committed to `master`:

- **Task 1** (migrations): commits `0394392` (initial) and `6ee7b67` (fixed FK naming/onDelete consistency per code review).
- **Task 2** (`InvestigationStatus`, `InvestigationMethodology` enums): commit `0283200`.
- **Task 3** (`Investigation`, `InvestigationTeamMember`, `InvestigationFinding` models + `Incident::investigation()`): commit `984b59e`.

Nothing to do here. If you're executing this plan fresh and these commits don't exist, stop and tell the user — the plan assumes they're already in place.

---

### Task 4: DTOs

**Files:**
- Create: `app/DataTransferObjects/Investigations/StartInvestigationData.php`
- Create: `app/DataTransferObjects/Investigations/AddTeamMemberData.php`
- Create: `app/DataTransferObjects/Investigations/FindingData.php`
- Create: `app/DataTransferObjects/Investigations/CompleteInvestigationData.php`

- [ ] **Step 1: Write `StartInvestigationData`**

```php
<?php

namespace App\DataTransferObjects\Investigations;

use App\Enums\InvestigationMethodology;
use Illuminate\Support\Carbon;

/**
 * Validated input for InvestigationService::start(). $teamMembers is
 * left as an array of ['user_id' => int, 'role_in_team' => string]
 * shapes rather than a nested DTO — it's already validated array data
 * by the time it reaches here, and wrapping it doesn't buy type safety
 * the Service doesn't already get from the outer DTO's typed properties.
 */
final class StartInvestigationData
{
    public function __construct(
        public readonly string $objective,
        public readonly InvestigationMethodology $methodology,
        public readonly ?Carbon $targetCompletionAt,
        public readonly array $teamMembers,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            objective: $data['objective'],
            methodology: InvestigationMethodology::from($data['methodology']),
            targetCompletionAt: isset($data['target_completion_at']) ? Carbon::parse($data['target_completion_at']) : null,
            teamMembers: $data['team_members'] ?? [],
        );
    }
}
```

- [ ] **Step 2: Write `AddTeamMemberData`**

```php
<?php

namespace App\DataTransferObjects\Investigations;

final class AddTeamMemberData
{
    public function __construct(
        public readonly int $userId,
        public readonly string $roleInTeam,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            userId: (int) $data['user_id'],
            roleInTeam: $data['role_in_team'],
        );
    }
}
```

- [ ] **Step 3: Write `FindingData`**

Used for both adding and updating a finding (same shape either way).

```php
<?php

namespace App\DataTransferObjects\Investigations;

final class FindingData
{
    public function __construct(
        public readonly ?string $category,
        public readonly ?string $question,
        public readonly string $finding,
        public readonly bool $isRootCause,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            category: $data['category'] ?? null,
            question: $data['question'] ?? null,
            finding: $data['finding'],
            isRootCause: $data['is_root_cause'] ?? false,
        );
    }

    public function toAttributes(): array
    {
        return [
            'category' => $this->category,
            'question' => $this->question,
            'finding' => $this->finding,
            'is_root_cause' => $this->isRootCause,
        ];
    }
}
```

- [ ] **Step 4: Write `CompleteInvestigationData`**

```php
<?php

namespace App\DataTransferObjects\Investigations;

final class CompleteInvestigationData
{
    public function __construct(
        public readonly string $conclusion,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(conclusion: $data['conclusion']);
    }
}
```

- [ ] **Step 5: Verify they load**

```bash
cd C:\wamp64\projects\incident-report
php artisan tinker --execute="var_dump(App\DataTransferObjects\Investigations\FindingData::fromArray(['finding' => 'x', 'is_root_cause' => true])->toAttributes());"
```

Expected: an array with `category` and `question` null, `finding` => `'x'`, `is_root_cause` => `true`.

- [ ] **Step 6: Commit**

```bash
git add app/DataTransferObjects
git commit -m "feat: add DTOs for investigation start/team/finding/complete inputs"
```

## Context for Task 4

Nothing else in the codebase depends on these yet — this task is pure scaffolding. `InvestigationMethodology::from()` (used in `StartInvestigationData::fromArray()`) throws `\ValueError` on an invalid string, which is fine: it's only ever called after Form Request validation (Task 9) has already confirmed the value is one of the enum's cases via `Illuminate\Validation\Rules\Enum`.

---

### Task 5: Repositories and Query

**Files:**
- Create: `app/Repositories/InvestigationRepository.php`
- Create: `app/Repositories/InvestigationTeamMemberRepository.php`
- Create: `app/Repositories/InvestigationFindingRepository.php`
- Create: `app/Queries/OverdueInvestigationsQuery.php`

- [ ] **Step 1: Write `InvestigationRepository`**

```php
<?php

namespace App\Repositories;

use App\Models\Investigation;

class InvestigationRepository
{
    public function create(array $attributes): Investigation
    {
        return Investigation::create($attributes);
    }

    public function update(Investigation $investigation, array $attributes): Investigation
    {
        $investigation->fill($attributes);
        $investigation->save();

        return $investigation;
    }

    /**
     * escalated_at is deliberately excluded from Investigation::$fillable
     * (it's system-managed, only ever set by the daily escalation sweep),
     * so it needs forceFill() rather than the mass-assignable update() above.
     */
    public function markEscalated(Investigation $investigation): void
    {
        $investigation->forceFill(['escalated_at' => now()])->save();
    }
}
```

- [ ] **Step 2: Write `InvestigationTeamMemberRepository`**

```php
<?php

namespace App\Repositories;

use App\Models\Investigation;
use App\Models\InvestigationTeamMember;

class InvestigationTeamMemberRepository
{
    public function create(Investigation $investigation, array $attributes): InvestigationTeamMember
    {
        return $investigation->teamMembers()->create($attributes);
    }

    public function delete(InvestigationTeamMember $teamMember): void
    {
        $teamMember->delete();
    }
}
```

- [ ] **Step 3: Write `InvestigationFindingRepository`**

```php
<?php

namespace App\Repositories;

use App\Models\Investigation;
use App\Models\InvestigationFinding;
use Illuminate\Database\Eloquent\Collection;

class InvestigationFindingRepository
{
    public function create(Investigation $investigation, array $attributes): InvestigationFinding
    {
        return $investigation->findings()->create($attributes);
    }

    public function update(InvestigationFinding $finding, array $attributes): InvestigationFinding
    {
        $finding->update($attributes);

        return $finding;
    }

    public function delete(InvestigationFinding $finding): void
    {
        $finding->delete();
    }

    public function countFor(Investigation $investigation): int
    {
        return $investigation->findings()->count();
    }

    /**
     * Already ordered by sequence then id — see Investigation::findings().
     */
    public function allFor(Investigation $investigation): Collection
    {
        return $investigation->findings()->get();
    }
}
```

- [ ] **Step 4: Write `OverdueInvestigationsQuery`**

```php
<?php

namespace App\Queries;

use App\Enums\InvestigationStatus;
use App\Models\Investigation;
use Illuminate\Database\Eloquent\Collection;

class OverdueInvestigationsQuery
{
    public function get(): Collection
    {
        return Investigation::whereNull('escalated_at')
            ->where('status', InvestigationStatus::InProgress)
            ->whereNotNull('target_completion_at')
            ->where('target_completion_at', '<', now())
            ->get();
    }
}
```

- [ ] **Step 5: Verify**

```bash
cd C:\wamp64\projects\incident-report
php artisan tinker --execute="echo App\Repositories\InvestigationRepository::class . ' ' . App\Repositories\InvestigationTeamMemberRepository::class . ' ' . App\Repositories\InvestigationFindingRepository::class . ' ' . App\Queries\OverdueInvestigationsQuery::class . ' OK';"
```

Expected: no fatal errors.

- [ ] **Step 6: Commit**

```bash
git add app/Repositories app/Queries
git commit -m "feat: add investigation repositories and overdue-investigations query"
```

## Context for Task 5

These are thin wrappers by design — there's a single persistence backend and no interface/contract layer was requested (see the plan header's "deliberately out of scope"). Their value here is the seam they create between `InvestigationService` (Task 6) and Eloquent specifics, per the user's explicit architecture direction, not runtime polymorphism. Don't add caching, query scoping beyond what's specified, or an interface — that would be building for a hypothetical need, not the one asked for.

---

### Task 6: Rewrite InvestigationService on Repositories + DTOs

**This task rewrites files that already exist and are committed** (`app/Services/InvestigationService.php` and `tests/Feature/Investigations/InvestigationTest.php`, from the pre-layering commit `2fc7a87`) — it's a retrofit, not new scaffolding. Follow TDD: update the tests to construct DTOs directly (no more raw arrays), confirm they fail against the *old* array-based service, then rewrite the service.

**Files:**
- Modify: `app/Services/InvestigationService.php`
- Modify: `tests/Feature/Investigations/InvestigationTest.php`

- [ ] **Step 1: Rewrite the test file to build DTOs instead of arrays**

Replace the entire contents of `tests/Feature/Investigations/InvestigationTest.php` with:

```php
<?php

namespace Tests\Feature\Investigations;

use App\DataTransferObjects\Investigations\CompleteInvestigationData;
use App\DataTransferObjects\Investigations\FindingData;
use App\DataTransferObjects\Investigations\StartInvestigationData;
use App\Enums\IncidentStatus;
use App\Enums\InvestigationMethodology;
use App\Enums\InvestigationStatus;
use App\Enums\Role;
use App\Enums\Severity;
use App\Models\Department;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\User;
use App\Services\IncidentService;
use App\Services\InvestigationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvestigationTest extends TestCase
{
    use RefreshDatabase;

    private function makeReporter(): User
    {
        return User::factory()->create();
    }

    /**
     * A submitted, reviewed, and assigned incident — ready for start().
     */
    private function assignedIncident(?User $investigator = null, ?Department $department = null): Incident
    {
        $department ??= Department::factory()->create();
        $incidentType = IncidentType::factory()->create();
        $reporter = $this->makeReporter();
        $supervisor = User::factory()->create(['role' => Role::Supervisor, 'department_id' => $department->id]);
        $investigator ??= User::factory()->create(['role' => Role::Investigator]);

        $incident = app(IncidentService::class)->createDraft($reporter, [
            'department_id' => $department->id,
            'incident_type_id' => $incidentType->id,
            'severity' => Severity::Level2Moderate->value,
            'occurred_at' => now(),
            'location' => 'Ward 3',
            'summary' => 'Test incident.',
        ]);
        app(IncidentService::class)->submit($incident);
        app(IncidentService::class)->markReviewed($incident->fresh(), $supervisor, null);
        app(IncidentService::class)->assignInvestigator($incident->fresh(), $investigator);

        return $incident->fresh();
    }

    private function startData(string $methodology = 'five_whys', array $extra = []): StartInvestigationData
    {
        return StartInvestigationData::fromArray(array_merge([
            'objective' => 'Determine root cause.',
            'methodology' => $methodology,
        ], $extra));
    }

    private function findingData(array $data): FindingData
    {
        return FindingData::fromArray($data);
    }

    public function test_starting_an_investigation_sets_status_and_moves_the_incident_to_under_investigation(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $incident = $this->assignedIncident($investigator);

        $investigation = app(InvestigationService::class)->start($incident, $investigator, $this->startData('five_whys', [
            'objective' => 'Determine why the pump was double-rated.',
        ]));

        $this->assertSame(InvestigationStatus::InProgress, $investigation->status);
        $this->assertSame($investigator->id, $investigation->lead_investigator_id);
        $this->assertNotNull($investigation->started_at);
        $this->assertSame(IncidentStatus::UnderInvestigation, $incident->fresh()->status);
    }

    public function test_starting_an_investigation_adds_the_lead_investigator_as_a_team_member(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $incident = $this->assignedIncident($investigator);

        $investigation = app(InvestigationService::class)->start($incident, $investigator, $this->startData('fishbone'));

        $this->assertCount(1, $investigation->teamMembers);
        $this->assertSame($investigator->id, $investigation->teamMembers->first()->user_id);
        $this->assertSame('Lead Investigator', $investigation->teamMembers->first()->role_in_team);
    }

    public function test_starting_an_investigation_adds_additional_team_members_and_deduplicates_the_lead(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $nurse = User::factory()->create();
        $incident = $this->assignedIncident($investigator);

        $investigation = app(InvestigationService::class)->start($incident, $investigator, $this->startData('fishbone', [
            'team_members' => [
                ['user_id' => $investigator->id, 'role_in_team' => 'Duplicate of lead'],
                ['user_id' => $nurse->id, 'role_in_team' => 'Nursing Service Rep'],
            ],
        ]));

        $this->assertCount(2, $investigation->teamMembers);
        $this->assertSame('Nursing Service Rep', $investigation->teamMembers->firstWhere('user_id', $nurse->id)->role_in_team);
    }

    public function test_starting_an_investigation_defaults_target_completion_to_the_incidents_target_closure_date(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $incident = $this->assignedIncident($investigator);

        $investigation = app(InvestigationService::class)->start($incident, $investigator, $this->startData('hfacs'));

        $this->assertSame(
            $incident->target_closure_date->timestamp,
            $investigation->target_completion_at->timestamp
        );
    }

    public function test_starting_an_investigation_writes_an_investigation_started_audit_log_entry(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $incident = $this->assignedIncident($investigator);

        app(InvestigationService::class)->start($incident, $investigator, $this->startData('five_whys', [
            'objective' => 'Determine root cause.',
        ]));

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Incident::class,
            'auditable_id' => $incident->id,
            'action' => 'investigation_started',
        ]);
    }

    public function test_adding_a_five_whys_finding_auto_assigns_sequence(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $incident = $this->assignedIncident($investigator);
        $investigation = app(InvestigationService::class)->start($incident, $investigator, $this->startData('five_whys'));

        $first = app(InvestigationService::class)->addFinding($investigation, $this->findingData([
            'question' => 'Why did X happen?', 'finding' => 'Because Y.', 'is_root_cause' => false,
        ]));
        $second = app(InvestigationService::class)->addFinding($investigation->fresh(), $this->findingData([
            'question' => 'Why did Y happen?', 'finding' => 'Because Z.', 'is_root_cause' => true,
        ]));

        $this->assertSame(1, $first->sequence);
        $this->assertSame(2, $second->sequence);
    }

    public function test_adding_a_finding_writes_a_finding_added_audit_log_entry(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $incident = $this->assignedIncident($investigator);
        $investigation = app(InvestigationService::class)->start($incident, $investigator, $this->startData('fishbone'));

        app(InvestigationService::class)->addFinding($investigation, $this->findingData([
            'category' => 'Equipment', 'finding' => 'Pump firmware outdated.', 'is_root_cause' => false,
        ]));

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Incident::class,
            'auditable_id' => $incident->id,
            'action' => 'finding_added',
        ]);
    }

    public function test_deleting_a_five_whys_finding_renumbers_the_remaining_ones_contiguously(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $incident = $this->assignedIncident($investigator);
        $investigation = app(InvestigationService::class)->start($incident, $investigator, $this->startData('five_whys'));
        $service = app(InvestigationService::class);
        $one = $service->addFinding($investigation, $this->findingData(['question' => 'Q1', 'finding' => 'F1', 'is_root_cause' => false]));
        $two = $service->addFinding($investigation->fresh(), $this->findingData(['question' => 'Q2', 'finding' => 'F2', 'is_root_cause' => false]));
        $three = $service->addFinding($investigation->fresh(), $this->findingData(['question' => 'Q3', 'finding' => 'F3', 'is_root_cause' => true]));

        $service->deleteFinding($two);

        $remaining = $investigation->fresh()->findings;
        $this->assertCount(2, $remaining);
        $this->assertSame([1, 2], $remaining->pluck('sequence')->all());
        $this->assertSame([$one->id, $three->id], $remaining->pluck('id')->all());
    }

    public function test_updating_a_finding(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $incident = $this->assignedIncident($investigator);
        $investigation = app(InvestigationService::class)->start($incident, $investigator, $this->startData('five_whys'));
        $service = app(InvestigationService::class);
        $finding = $service->addFinding($investigation, $this->findingData(['question' => 'Q', 'finding' => 'Original', 'is_root_cause' => false]));

        $updated = $service->updateFinding($finding, $this->findingData(['question' => 'Q', 'finding' => 'Corrected.', 'is_root_cause' => true]));

        $this->assertSame('Corrected.', $updated->finding);
        $this->assertTrue($updated->is_root_cause);
    }

    public function test_completing_an_investigation_sets_status_and_moves_the_incident_to_corrective_action(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $incident = $this->assignedIncident($investigator);
        $investigation = app(InvestigationService::class)->start($incident, $investigator, $this->startData('five_whys'));
        app(InvestigationService::class)->addFinding($investigation, $this->findingData([
            'question' => 'Why?', 'finding' => 'Root cause found.', 'is_root_cause' => true,
        ]));

        $completed = app(InvestigationService::class)->complete(
            $investigation->fresh(),
            CompleteInvestigationData::fromArray(['conclusion' => 'Root cause: pump miscalibration.'])
        );

        $this->assertSame(InvestigationStatus::Completed, $completed->status);
        $this->assertNotNull($completed->completed_at);
        $this->assertSame('Root cause: pump miscalibration.', $completed->conclusion);
        $this->assertSame(IncidentStatus::CorrectiveAction, $incident->fresh()->status);
    }

    public function test_removing_a_team_member(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $nurse = User::factory()->create();
        $incident = $this->assignedIncident($investigator);
        $investigation = app(InvestigationService::class)->start($incident, $investigator, $this->startData('fishbone', [
            'team_members' => [['user_id' => $nurse->id, 'role_in_team' => 'Nursing Service Rep']],
        ]));
        $member = $investigation->teamMembers->firstWhere('user_id', $nurse->id);

        app(InvestigationService::class)->removeTeamMember($member);

        $this->assertDatabaseMissing('investigation_team_members', ['id' => $member->id]);
    }
}
```

- [ ] **Step 2: Run to confirm it fails against the old service**

```bash
cd C:\wamp64\projects\incident-report
php artisan test --filter=InvestigationTest
```

Expected: FAIL — the old `InvestigationService::start()` etc. still expect `array $data`, not `StartInvestigationData`/`FindingData`/`CompleteInvestigationData` (type errors).

- [ ] **Step 3: Rewrite `InvestigationService`**

Replace the entire contents of `app/Services/InvestigationService.php` with:

```php
<?php

namespace App\Services;

use App\DataTransferObjects\Investigations\CompleteInvestigationData;
use App\DataTransferObjects\Investigations\FindingData;
use App\DataTransferObjects\Investigations\StartInvestigationData;
use App\Enums\IncidentStatus;
use App\Enums\InvestigationMethodology;
use App\Enums\InvestigationStatus;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Investigation;
use App\Models\InvestigationFinding;
use App\Models\InvestigationTeamMember;
use App\Models\User;
use App\Repositories\InvestigationFindingRepository;
use App\Repositories\InvestigationRepository;
use App\Repositories\InvestigationTeamMemberRepository;
use Illuminate\Support\Facades\DB;

class InvestigationService
{
    public function __construct(
        private InvestigationRepository $investigations,
        private InvestigationTeamMemberRepository $teamMembers,
        private InvestigationFindingRepository $findings,
    ) {
    }

    public function start(Incident $incident, User $leadInvestigator, StartInvestigationData $data): Investigation
    {
        return DB::transaction(function () use ($incident, $leadInvestigator, $data) {
            $investigation = $this->investigations->create([
                'incident_id' => $incident->id,
                'lead_investigator_id' => $leadInvestigator->id,
                'objective' => $data->objective,
                'methodology' => $data->methodology,
                'started_at' => now(),
                'target_completion_at' => $data->targetCompletionAt ?? $incident->target_closure_date,
                'status' => InvestigationStatus::InProgress,
            ]);

            $this->teamMembers->create($investigation, [
                'user_id' => $leadInvestigator->id,
                'role_in_team' => 'Lead Investigator',
            ]);

            foreach ($data->teamMembers as $member) {
                if ((int) $member['user_id'] === $leadInvestigator->id) {
                    continue;
                }

                $this->teamMembers->create($investigation, [
                    'user_id' => $member['user_id'],
                    'role_in_team' => $member['role_in_team'],
                ]);
            }

            $incident->status = IncidentStatus::UnderInvestigation;
            $incident->save();

            AuditLog::record($incident, 'investigation_started', $data->objective);

            return $investigation->fresh(['teamMembers']);
        });
    }

    public function addTeamMember(Investigation $investigation, User $user, string $roleInTeam): InvestigationTeamMember
    {
        return $this->teamMembers->create($investigation, [
            'user_id' => $user->id,
            'role_in_team' => $roleInTeam,
        ]);
    }

    public function removeTeamMember(InvestigationTeamMember $teamMember): void
    {
        $this->teamMembers->delete($teamMember);
    }

    public function addFinding(Investigation $investigation, FindingData $data): InvestigationFinding
    {
        $attributes = $data->toAttributes();

        if ($investigation->methodology === InvestigationMethodology::FiveWhys) {
            $attributes['sequence'] = $this->findings->countFor($investigation) + 1;
        }

        $finding = $this->findings->create($investigation, $attributes);

        AuditLog::record($investigation->incident, 'finding_added', $data->finding);

        return $finding;
    }

    public function updateFinding(InvestigationFinding $finding, FindingData $data): InvestigationFinding
    {
        return $this->findings->update($finding, $data->toAttributes());
    }

    public function deleteFinding(InvestigationFinding $finding): void
    {
        $investigation = $finding->investigation;
        $this->findings->delete($finding);

        if ($investigation->methodology === InvestigationMethodology::FiveWhys) {
            $this->findings->allFor($investigation)->values()->each(
                fn (InvestigationFinding $remaining, int $index) => $this->findings->update($remaining, ['sequence' => $index + 1])
            );
        }
    }

    public function complete(Investigation $investigation, CompleteInvestigationData $data): Investigation
    {
        return DB::transaction(function () use ($investigation, $data) {
            $investigation = $this->investigations->update($investigation, [
                'status' => InvestigationStatus::Completed,
                'conclusion' => $data->conclusion,
                'completed_at' => now(),
            ]);

            $incident = $investigation->incident;
            $incident->status = IncidentStatus::CorrectiveAction;
            $incident->save();

            AuditLog::record($incident, 'investigation_completed', $data->conclusion);

            return $investigation;
        });
    }
}
```

Note `updateFinding()`'s test is new in this rewrite (`test_updating_a_finding`) — the pre-layering version of this plan tested `updateFinding` only at the HTTP layer; it's pulled forward here since the DTO-based signature is worth a direct unit-level test too.

- [ ] **Step 4: Run tests to see them pass**

```bash
php artisan test --filter=InvestigationTest
```

Expected: `11 passed` (the original 10, plus the new `test_updating_a_finding`).

- [ ] **Step 5: Run the full suite**

```bash
php artisan test
```

Expected: all green — Phases 3/4 plus these 11.

- [ ] **Step 6: Commit**

```bash
git add app/Services/InvestigationService.php tests/Feature/Investigations/InvestigationTest.php
git commit -m "refactor: rebuild InvestigationService on repositories and DTOs"
```

## Context for Task 6

This supersedes the `InvestigationService`/`InvestigationTest.php` committed at `2fc7a87` under the pre-layering version of this plan — that code passed spec-compliance and code-quality review at the time, but the user has since asked for a Repository+DTO+Action+Resource architecture for this phase specifically. This task's job is purely mechanical translation (array access → DTO property access, direct Eloquent calls → Repository calls) — the actual business rules (auto-sequence for five_whys, renumbering on delete, audit logging targets, transaction boundaries) are unchanged from the version that already passed review, so don't relitigate those decisions, just carry them over faithfully into the new shape.

---

### Task 7: Policies

**Files:**
- Modify: `app/Policies/IncidentPolicy.php`
- Create: `app/Policies/InvestigationPolicy.php`
- Modify: `app/Providers/AuthServiceProvider.php`
- Modify: `tests/Feature/Investigations/InvestigationTest.php`

- [ ] **Step 1: Add failing tests**

Append to the test class in `tests/Feature/Investigations/InvestigationTest.php`:

```php
    public function test_the_assigned_investigator_can_start_an_investigation(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $incident = $this->assignedIncident($investigator);

        $this->assertTrue($investigator->can('start', $incident));
    }

    public function test_a_different_investigator_cannot_start_the_investigation(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $otherInvestigator = User::factory()->create(['role' => Role::Investigator]);
        $incident = $this->assignedIncident($investigator);

        $this->assertFalse($otherInvestigator->can('start', $incident));
    }

    public function test_qso_can_start_any_assigned_incidents_investigation(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $incident = $this->assignedIncident($investigator);

        $this->assertTrue($qso->can('start', $incident));
    }

    public function test_nobody_can_start_an_investigation_before_the_incident_is_assigned(): void
    {
        $reporter = $this->makeReporter();
        $department = Department::factory()->create();
        $incidentType = IncidentType::factory()->create();
        $investigator = User::factory()->create(['role' => Role::Investigator]);

        $incident = app(IncidentService::class)->createDraft($reporter, [
            'department_id' => $department->id,
            'incident_type_id' => $incidentType->id,
            'severity' => Severity::Level2Moderate->value,
            'occurred_at' => now(),
            'location' => 'Ward 3',
            'summary' => 'Test incident.',
        ]);
        app(IncidentService::class)->submit($incident);

        $this->assertFalse($investigator->can('start', $incident->fresh()));
    }

    public function test_only_the_lead_investigator_or_qso_can_manage_the_team(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $teamMember = User::factory()->create();
        $incident = $this->assignedIncident($investigator);
        $investigation = app(InvestigationService::class)->start($incident, $investigator, $this->startData('fishbone', [
            'team_members' => [['user_id' => $teamMember->id, 'role_in_team' => 'Rep']],
        ]));
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);

        $this->assertTrue($investigator->can('manageTeam', $investigation));
        $this->assertTrue($qso->can('manageTeam', $investigation));
        $this->assertFalse($teamMember->can('manageTeam', $investigation));
    }

    public function test_a_team_member_can_record_findings_but_a_stranger_cannot(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $teamMember = User::factory()->create();
        $stranger = User::factory()->create();
        $incident = $this->assignedIncident($investigator);
        $investigation = app(InvestigationService::class)->start($incident, $investigator, $this->startData('fishbone', [
            'team_members' => [['user_id' => $teamMember->id, 'role_in_team' => 'Rep']],
        ]));

        $this->assertTrue($investigator->can('recordFindings', $investigation));
        $this->assertTrue($teamMember->can('recordFindings', $investigation));
        $this->assertFalse($stranger->can('recordFindings', $investigation));
    }

    public function test_nobody_can_record_findings_on_a_completed_investigation(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $incident = $this->assignedIncident($investigator);
        $investigation = app(InvestigationService::class)->start($incident, $investigator, $this->startData('five_whys'));
        app(InvestigationService::class)->addFinding($investigation, $this->findingData(['question' => 'Q', 'finding' => 'F', 'is_root_cause' => true]));
        app(InvestigationService::class)->complete($investigation->fresh(), CompleteInvestigationData::fromArray(['conclusion' => 'Done.']));

        $this->assertFalse($investigator->can('recordFindings', $investigation->fresh()));
    }

    public function test_only_the_lead_investigator_or_qso_can_complete_the_investigation(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $teamMember = User::factory()->create();
        $incident = $this->assignedIncident($investigator);
        $investigation = app(InvestigationService::class)->start($incident, $investigator, $this->startData('fishbone', [
            'team_members' => [['user_id' => $teamMember->id, 'role_in_team' => 'Rep']],
        ]));

        $this->assertTrue($investigator->can('complete', $investigation));
        $this->assertFalse($teamMember->can('complete', $investigation));
    }
```

- [ ] **Step 2: Run to confirm they fail**

```bash
php artisan test --filter=InvestigationTest
```

Expected: FAIL — `start` isn't defined on `IncidentPolicy` and `InvestigationPolicy` doesn't exist, so every `can()` call returns `false`, including the ones expected to be `true`.

- [ ] **Step 3: Add `start()` to `IncidentPolicy`**

In `app/Policies/IncidentPolicy.php`, add after `assign()`:

```php
    public function start(User $user, Incident $incident): bool
    {
        if ($incident->status !== IncidentStatus::Assigned) {
            return false;
        }

        if (in_array($user->role, [Role::QualitySafetyOfficer, Role::Administrator], true)) {
            return true;
        }

        return $incident->assigned_investigator_id === $user->id;
    }
```

- [ ] **Step 4: Write `InvestigationPolicy`**

```php
<?php

namespace App\Policies;

use App\Enums\InvestigationStatus;
use App\Enums\Role;
use App\Models\Investigation;
use App\Models\User;

class InvestigationPolicy
{
    public function manageTeam(User $user, Investigation $investigation): bool
    {
        return $this->hasLeadAccess($user, $investigation);
    }

    public function recordFindings(User $user, Investigation $investigation): bool
    {
        if ($investigation->status !== InvestigationStatus::InProgress) {
            return false;
        }

        if ($this->hasLeadAccess($user, $investigation)) {
            return true;
        }

        return $investigation->teamMembers()->where('user_id', $user->id)->exists();
    }

    public function complete(User $user, Investigation $investigation): bool
    {
        if ($investigation->status !== InvestigationStatus::InProgress) {
            return false;
        }

        return $this->hasLeadAccess($user, $investigation);
    }

    private function hasLeadAccess(User $user, Investigation $investigation): bool
    {
        if (in_array($user->role, [Role::QualitySafetyOfficer, Role::Administrator], true)) {
            return true;
        }

        return $investigation->lead_investigator_id === $user->id;
    }
}
```

- [ ] **Step 5: Register the policy**

In `app/Providers/AuthServiceProvider.php`, change:

```php
    protected $policies = [
        \App\Models\Incident::class => \App\Policies\IncidentPolicy::class,
    ];
```

to:

```php
    protected $policies = [
        \App\Models\Incident::class => \App\Policies\IncidentPolicy::class,
        \App\Models\Investigation::class => \App\Policies\InvestigationPolicy::class,
    ];
```

- [ ] **Step 6: Run tests to see them pass**

```bash
php artisan test --filter=InvestigationTest
```

Expected: `19 passed`.

- [ ] **Step 7: Run the full suite**

```bash
php artisan test
```

Expected: all green.

- [ ] **Step 8: Commit**

```bash
git add app/Policies/IncidentPolicy.php app/Policies/InvestigationPolicy.php app/Providers/AuthServiceProvider.php tests/Feature/Investigations/InvestigationTest.php
git commit -m "feat: add start/manageTeam/recordFindings/complete authorization"
```

## Context for Task 7

Policies are unaffected by the Repository/DTO/Action layering — they gate access to a Model instance, same as `IncidentPolicy` already does. `CompleteInvestigationData::fromArray()` (used in the new test) comes from Task 4.

---

### Task 8: Escalation sweep for overdue investigations

**Files:**
- Modify: `app/Console/Commands/CheckOverdueIncidents.php`
- Create: `tests/Feature/Investigations/InvestigationEscalationTest.php`

- [ ] **Step 1: Write the failing test file**

```php
<?php

namespace Tests\Feature\Investigations;

use App\DataTransferObjects\Investigations\StartInvestigationData;
use App\Enums\Role;
use App\Enums\Severity;
use App\Models\Department;
use App\Models\IncidentType;
use App\Models\User;
use App\Notifications\IncidentEscalationNotification;
use App\Services\IncidentService;
use App\Services\InvestigationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class InvestigationEscalationTest extends TestCase
{
    use RefreshDatabase;

    private function assignedIncident(User $investigator): \App\Models\Incident
    {
        $department = Department::factory()->create();
        $incidentType = IncidentType::factory()->create();
        $reporter = User::factory()->create();
        $supervisor = User::factory()->create(['role' => Role::Supervisor, 'department_id' => $department->id]);

        $incident = app(IncidentService::class)->createDraft($reporter, [
            'department_id' => $department->id,
            'incident_type_id' => $incidentType->id,
            'severity' => Severity::Level2Moderate->value,
            'occurred_at' => now(),
            'location' => 'Ward 3',
            'summary' => 'Test incident.',
        ]);
        app(IncidentService::class)->submit($incident);
        app(IncidentService::class)->markReviewed($incident->fresh(), $supervisor, null);
        app(IncidentService::class)->assignInvestigator($incident->fresh(), $investigator);

        return $incident->fresh();
    }

    public function test_an_overdue_in_progress_investigation_is_escalated_once(): void
    {
        Notification::fake();
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $incident = $this->assignedIncident($investigator);
        $investigation = app(InvestigationService::class)->start($incident, $investigator, StartInvestigationData::fromArray([
            'objective' => 'x',
            'methodology' => 'five_whys',
            'target_completion_at' => now()->subDay()->toDateTimeString(),
        ]));

        Artisan::call('incidents:check-overdue');

        Notification::assertSentTo($qso, IncidentEscalationNotification::class);
        $this->assertNotNull($investigation->fresh()->escalated_at);

        Notification::fake();
        Artisan::call('incidents:check-overdue');
        Notification::assertNothingSent();
    }
}
```

- [ ] **Step 2: Run to confirm it fails**

```bash
cd C:\wamp64\projects\incident-report
php artisan test --filter=InvestigationEscalationTest
```

Expected: FAIL — `escalateOverdueInvestigations` doesn't exist yet, so no notification is ever sent and `escalated_at` stays null.

- [ ] **Step 3: Add `escalateOverdueInvestigations()` to `CheckOverdueIncidents`**

In `app/Console/Commands/CheckOverdueIncidents.php`, add the imports:

```php
use App\Queries\OverdueInvestigationsQuery;
use App\Repositories\InvestigationRepository;
```

Inject the query and repository via the constructor — read the current file first to see whether it already has a constructor (it may not, if it was written as a plain `Command` with no DI). If it has no constructor, add one:

```php
    public function __construct(
        private OverdueInvestigationsQuery $overdueInvestigations,
        private InvestigationRepository $investigations,
    ) {
        parent::__construct();
    }
```

Change `handle()` to also call the new sweep:

```php
    public function handle(): int
    {
        $recipients = User::whereIn('role', config('incident_workflow.escalation_recipient_roles'))->get();

        if ($recipients->isEmpty()) {
            $this->warn('No escalation recipients configured/found; skipping.');

            return self::SUCCESS;
        }

        $this->escalateOverdueReviews($recipients);
        $this->escalateOverdueAssignments($recipients);
        $this->escalateOverdueInvestigations($recipients);

        return self::SUCCESS;
    }
```

Add the new private method after `escalateOverdueAssignments()`:

```php
    private function escalateOverdueInvestigations(Collection $recipients): void
    {
        $this->overdueInvestigations->get()->each(function (Investigation $investigation) use ($recipients) {
            Notification::send($recipients, new IncidentEscalationNotification($investigation->incident, 'Investigation SLA breached'));
            $this->investigations->markEscalated($investigation);
        });
    }
```

Add the `use App\Models\Investigation;` import alongside the others if it isn't already there.

- [ ] **Step 4: Run tests to see them pass**

```bash
php artisan test --filter=InvestigationEscalationTest
```

Expected: `1 passed`.

- [ ] **Step 5: Run the full suite**

```bash
php artisan test
```

Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/CheckOverdueIncidents.php tests/Feature/Investigations/InvestigationEscalationTest.php
git commit -m "feat: escalate investigations that breach their target completion date"
```

## Context for Task 8

`CheckOverdueIncidents` already exists from Phase 4 with two sweep methods (`escalateOverdueReviews`, `escalateOverdueAssignments`) that query `Incident` directly with inline Eloquent — those two are **not** being retrofitted onto Repositories/Queries in this task (that would be touching Phase 4 code, which is out of scope per the user's answer). Only the new, investigation-specific third sweep goes through `OverdueInvestigationsQuery`/`InvestigationRepository`, per this phase's architecture.

---

### Task 9: DTO-aware Form Requests, Actions, Controller, routes

**Files:**
- Create: `app/Http/Requests/Investigations/StartInvestigationRequest.php`
- Create: `app/Http/Requests/Investigations/AddTeamMemberRequest.php`
- Create: `app/Http/Requests/Investigations/AddFindingRequest.php`
- Create: `app/Http/Requests/Investigations/UpdateFindingRequest.php`
- Create: `app/Http/Requests/Investigations/CompleteInvestigationRequest.php`
- Create: `app/Actions/Investigations/StartInvestigationAction.php`
- Create: `app/Actions/Investigations/AddTeamMemberAction.php`
- Create: `app/Actions/Investigations/RemoveTeamMemberAction.php`
- Create: `app/Actions/Investigations/AddFindingAction.php`
- Create: `app/Actions/Investigations/UpdateFindingAction.php`
- Create: `app/Actions/Investigations/DeleteFindingAction.php`
- Create: `app/Actions/Investigations/CompleteInvestigationAction.php`
- Create: `app/Http/Controllers/InvestigationController.php`
- Modify: `routes/web.php`
- Modify: `tests/Feature/Investigations/InvestigationTest.php`

- [ ] **Step 1: Add failing HTTP-level tests**

Append to `InvestigationTest`:

```php
    public function test_the_assigned_investigator_can_start_an_investigation_via_http(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $incident = $this->assignedIncident($investigator);

        $response = $this->actingAs($investigator)->post("/incidents/{$incident->id}/investigation", [
            'objective' => 'Determine root cause.',
            'methodology' => 'five_whys',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('investigations', ['incident_id' => $incident->id]);
    }

    public function test_starting_an_investigation_requires_an_objective_and_a_valid_methodology(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $incident = $this->assignedIncident($investigator);

        $this->actingAs($investigator)
            ->post("/incidents/{$incident->id}/investigation", ['methodology' => 'not-a-real-methodology'])
            ->assertSessionHasErrors(['objective', 'methodology']);
    }

    public function test_adding_a_team_member_via_http(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $nurse = User::factory()->create();
        $incident = $this->assignedIncident($investigator);
        $investigation = app(InvestigationService::class)->start($incident, $investigator, $this->startData('fishbone'));

        $response = $this->actingAs($investigator)->post("/investigations/{$investigation->id}/team-members", [
            'user_id' => $nurse->id,
            'role_in_team' => 'Nursing Service Rep',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('investigation_team_members', ['investigation_id' => $investigation->id, 'user_id' => $nurse->id]);
    }

    public function test_a_stranger_cannot_add_a_team_member_via_http(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $stranger = User::factory()->create();
        $nurse = User::factory()->create();
        $incident = $this->assignedIncident($investigator);
        $investigation = app(InvestigationService::class)->start($incident, $investigator, $this->startData('fishbone'));

        $this->actingAs($stranger)
            ->post("/investigations/{$investigation->id}/team-members", ['user_id' => $nurse->id, 'role_in_team' => 'Rep'])
            ->assertForbidden();
    }

    public function test_removing_a_team_member_scoped_to_another_investigation_is_rejected(): void
    {
        $investigatorA = User::factory()->create(['role' => Role::Investigator]);
        $investigatorB = User::factory()->create(['role' => Role::Investigator]);
        $nurse = User::factory()->create();
        $incidentA = $this->assignedIncident($investigatorA);
        $incidentB = $this->assignedIncident($investigatorB);
        $investigationA = app(InvestigationService::class)->start($incidentA, $investigatorA, $this->startData('fishbone'));
        $investigationB = app(InvestigationService::class)->start($incidentB, $investigatorB, $this->startData('fishbone', [
            'team_members' => [['user_id' => $nurse->id, 'role_in_team' => 'Rep']],
        ]));
        $memberOfB = $investigationB->teamMembers->firstWhere('user_id', $nurse->id);

        $this->actingAs($investigatorA)
            ->delete("/investigations/{$investigationA->id}/team-members/{$memberOfB->id}")
            ->assertNotFound();
    }

    public function test_a_team_member_can_add_a_finding_via_http(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $incident = $this->assignedIncident($investigator);
        $investigation = app(InvestigationService::class)->start($incident, $investigator, $this->startData('five_whys'));

        $response = $this->actingAs($investigator)->post("/investigations/{$investigation->id}/findings", [
            'question' => 'Why did it happen?',
            'finding' => 'Because of X.',
            'is_root_cause' => false,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('investigation_findings', ['investigation_id' => $investigation->id, 'finding' => 'Because of X.']);
    }

    public function test_updating_a_finding_via_http(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $incident = $this->assignedIncident($investigator);
        $investigation = app(InvestigationService::class)->start($incident, $investigator, $this->startData('five_whys'));
        $finding = app(InvestigationService::class)->addFinding($investigation, $this->findingData(['question' => 'Q', 'finding' => 'Original', 'is_root_cause' => false]));

        $this->actingAs($investigator)
            ->patch("/investigations/{$investigation->id}/findings/{$finding->id}", [
                'question' => 'Q', 'finding' => 'Corrected.', 'is_root_cause' => true,
            ])
            ->assertRedirect();

        $this->assertSame('Corrected.', $finding->fresh()->finding);
        $this->assertTrue($finding->fresh()->is_root_cause);
    }

    public function test_updating_a_finding_scoped_to_another_investigation_is_rejected(): void
    {
        $investigatorA = User::factory()->create(['role' => Role::Investigator]);
        $investigatorB = User::factory()->create(['role' => Role::Investigator]);
        $incidentA = $this->assignedIncident($investigatorA);
        $incidentB = $this->assignedIncident($investigatorB);
        $investigationA = app(InvestigationService::class)->start($incidentA, $investigatorA, $this->startData('five_whys'));
        $investigationB = app(InvestigationService::class)->start($incidentB, $investigatorB, $this->startData('five_whys'));
        $findingOfB = app(InvestigationService::class)->addFinding($investigationB, $this->findingData(['question' => 'Q', 'finding' => 'F', 'is_root_cause' => false]));

        $this->actingAs($investigatorA)
            ->patch("/investigations/{$investigationA->id}/findings/{$findingOfB->id}", ['finding' => 'Hijacked.', 'is_root_cause' => false])
            ->assertNotFound();
    }

    public function test_completing_an_investigation_requires_at_least_one_finding(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $incident = $this->assignedIncident($investigator);
        $investigation = app(InvestigationService::class)->start($incident, $investigator, $this->startData('five_whys'));

        $this->actingAs($investigator)
            ->post("/investigations/{$investigation->id}/complete", ['conclusion' => 'Done.'])
            ->assertSessionHasErrors(['conclusion']);

        $this->assertSame(InvestigationStatus::InProgress, $investigation->fresh()->status);
    }

    public function test_completing_an_investigation_via_http(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $incident = $this->assignedIncident($investigator);
        $investigation = app(InvestigationService::class)->start($incident, $investigator, $this->startData('five_whys'));
        app(InvestigationService::class)->addFinding($investigation, $this->findingData(['question' => 'Q', 'finding' => 'Root cause.', 'is_root_cause' => true]));

        $response = $this->actingAs($investigator)->post("/investigations/{$investigation->id}/complete", [
            'conclusion' => 'Root cause: pump miscalibration.',
        ]);

        $response->assertRedirect();
        $this->assertSame(InvestigationStatus::Completed, $investigation->fresh()->status);
    }
```

- [ ] **Step 2: Run to confirm they fail**

```bash
php artisan test --filter=InvestigationTest
```

Expected: FAIL — routes don't exist yet (404s).

- [ ] **Step 3: Write the 5 Form Requests, each with a `toDto()` method**

`app/Http/Requests/Investigations/StartInvestigationRequest.php`:
```php
<?php

namespace App\Http\Requests\Investigations;

use App\DataTransferObjects\Investigations\StartInvestigationData;
use App\Enums\InvestigationMethodology;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StartInvestigationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('start', $this->route('incident'));
    }

    public function rules(): array
    {
        return [
            'objective' => ['required', 'string'],
            'methodology' => ['required', new Enum(InvestigationMethodology::class)],
            'target_completion_at' => ['nullable', 'date'],
            'team_members' => ['nullable', 'array'],
            'team_members.*.user_id' => ['required_with:team_members', Rule::exists('users', 'id')],
            'team_members.*.role_in_team' => ['required_with:team_members', 'string'],
        ];
    }

    public function toDto(): StartInvestigationData
    {
        return StartInvestigationData::fromArray($this->validated());
    }
}
```

`app/Http/Requests/Investigations/AddTeamMemberRequest.php`:
```php
<?php

namespace App\Http\Requests\Investigations;

use App\DataTransferObjects\Investigations\AddTeamMemberData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddTeamMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageTeam', $this->route('investigation'));
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', Rule::exists('users', 'id')],
            'role_in_team' => ['required', 'string'],
        ];
    }

    public function toDto(): AddTeamMemberData
    {
        return AddTeamMemberData::fromArray($this->validated());
    }
}
```

`app/Http/Requests/Investigations/AddFindingRequest.php`:
```php
<?php

namespace App\Http\Requests\Investigations;

use App\DataTransferObjects\Investigations\FindingData;
use Illuminate\Foundation\Http\FormRequest;

class AddFindingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('recordFindings', $this->route('investigation'));
    }

    public function rules(): array
    {
        return [
            'category' => ['nullable', 'string', 'max:100'],
            'question' => ['nullable', 'string'],
            'finding' => ['required', 'string'],
            'is_root_cause' => ['boolean'],
        ];
    }

    public function toDto(): FindingData
    {
        return FindingData::fromArray($this->validated());
    }
}
```

`app/Http/Requests/Investigations/UpdateFindingRequest.php`:
```php
<?php

namespace App\Http\Requests\Investigations;

use App\DataTransferObjects\Investigations\FindingData;
use Illuminate\Foundation\Http\FormRequest;

class UpdateFindingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('recordFindings', $this->route('investigation'));
    }

    public function rules(): array
    {
        return [
            'category' => ['nullable', 'string', 'max:100'],
            'question' => ['nullable', 'string'],
            'finding' => ['required', 'string'],
            'is_root_cause' => ['boolean'],
        ];
    }

    public function toDto(): FindingData
    {
        return FindingData::fromArray($this->validated());
    }
}
```

`app/Http/Requests/Investigations/CompleteInvestigationRequest.php`:
```php
<?php

namespace App\Http\Requests\Investigations;

use App\DataTransferObjects\Investigations\CompleteInvestigationData;
use Illuminate\Foundation\Http\FormRequest;

class CompleteInvestigationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('complete', $this->route('investigation'));
    }

    public function rules(): array
    {
        return [
            'conclusion' => ['required', 'string'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->route('investigation')->findings()->count() === 0) {
                $validator->errors()->add('conclusion', 'Add at least one finding before completing the investigation.');
            }
        });
    }

    public function toDto(): CompleteInvestigationData
    {
        return CompleteInvestigationData::fromArray($this->validated());
    }
}
```

- [ ] **Step 4: Write the 7 Actions**

Each Action is a single-purpose, invokable class that resolves any request-level concerns (e.g. loading a `User` by id) and delegates straight to `InvestigationService`. This is the "Actions call into Services" arrangement — Services keep the business logic, Actions are the controller-facing entry point.

`app/Actions/Investigations/StartInvestigationAction.php`:
```php
<?php

namespace App\Actions\Investigations;

use App\DataTransferObjects\Investigations\StartInvestigationData;
use App\Models\Incident;
use App\Models\Investigation;
use App\Models\User;
use App\Services\InvestigationService;

class StartInvestigationAction
{
    public function __construct(private InvestigationService $investigations)
    {
    }

    public function __invoke(Incident $incident, User $leadInvestigator, StartInvestigationData $data): Investigation
    {
        return $this->investigations->start($incident, $leadInvestigator, $data);
    }
}
```

`app/Actions/Investigations/AddTeamMemberAction.php`:
```php
<?php

namespace App\Actions\Investigations;

use App\DataTransferObjects\Investigations\AddTeamMemberData;
use App\Models\Investigation;
use App\Models\InvestigationTeamMember;
use App\Models\User;
use App\Services\InvestigationService;

class AddTeamMemberAction
{
    public function __construct(private InvestigationService $investigations)
    {
    }

    public function __invoke(Investigation $investigation, AddTeamMemberData $data): InvestigationTeamMember
    {
        $user = User::findOrFail($data->userId);

        return $this->investigations->addTeamMember($investigation, $user, $data->roleInTeam);
    }
}
```

`app/Actions/Investigations/RemoveTeamMemberAction.php`:
```php
<?php

namespace App\Actions\Investigations;

use App\Models\InvestigationTeamMember;
use App\Services\InvestigationService;

class RemoveTeamMemberAction
{
    public function __construct(private InvestigationService $investigations)
    {
    }

    public function __invoke(InvestigationTeamMember $teamMember): void
    {
        $this->investigations->removeTeamMember($teamMember);
    }
}
```

`app/Actions/Investigations/AddFindingAction.php`:
```php
<?php

namespace App\Actions\Investigations;

use App\DataTransferObjects\Investigations\FindingData;
use App\Models\Investigation;
use App\Models\InvestigationFinding;
use App\Services\InvestigationService;

class AddFindingAction
{
    public function __construct(private InvestigationService $investigations)
    {
    }

    public function __invoke(Investigation $investigation, FindingData $data): InvestigationFinding
    {
        return $this->investigations->addFinding($investigation, $data);
    }
}
```

`app/Actions/Investigations/UpdateFindingAction.php`:
```php
<?php

namespace App\Actions\Investigations;

use App\DataTransferObjects\Investigations\FindingData;
use App\Models\InvestigationFinding;
use App\Services\InvestigationService;

class UpdateFindingAction
{
    public function __construct(private InvestigationService $investigations)
    {
    }

    public function __invoke(InvestigationFinding $finding, FindingData $data): InvestigationFinding
    {
        return $this->investigations->updateFinding($finding, $data);
    }
}
```

`app/Actions/Investigations/DeleteFindingAction.php`:
```php
<?php

namespace App\Actions\Investigations;

use App\Models\InvestigationFinding;
use App\Services\InvestigationService;

class DeleteFindingAction
{
    public function __construct(private InvestigationService $investigations)
    {
    }

    public function __invoke(InvestigationFinding $finding): void
    {
        $this->investigations->deleteFinding($finding);
    }
}
```

`app/Actions/Investigations/CompleteInvestigationAction.php`:
```php
<?php

namespace App\Actions\Investigations;

use App\DataTransferObjects\Investigations\CompleteInvestigationData;
use App\Models\Investigation;
use App\Services\InvestigationService;

class CompleteInvestigationAction
{
    public function __construct(private InvestigationService $investigations)
    {
    }

    public function __invoke(Investigation $investigation, CompleteInvestigationData $data): Investigation
    {
        return $this->investigations->complete($investigation, $data);
    }
}
```

- [ ] **Step 5: Write `InvestigationController`**

The controller now holds no business logic — only Form Request → Action wiring, the `abort_unless` nested-ownership guards (still the controller's job, since they gate *which* record a route may touch, not *whether* the operation is allowed), and redirects.

```php
<?php

namespace App\Http\Controllers;

use App\Actions\Investigations\AddFindingAction;
use App\Actions\Investigations\AddTeamMemberAction;
use App\Actions\Investigations\CompleteInvestigationAction;
use App\Actions\Investigations\DeleteFindingAction;
use App\Actions\Investigations\RemoveTeamMemberAction;
use App\Actions\Investigations\StartInvestigationAction;
use App\Actions\Investigations\UpdateFindingAction;
use App\Http\Requests\Investigations\AddFindingRequest;
use App\Http\Requests\Investigations\AddTeamMemberRequest;
use App\Http\Requests\Investigations\CompleteInvestigationRequest;
use App\Http\Requests\Investigations\StartInvestigationRequest;
use App\Http\Requests\Investigations\UpdateFindingRequest;
use App\Models\Incident;
use App\Models\Investigation;
use App\Models\InvestigationFinding;
use App\Models\InvestigationTeamMember;
use Illuminate\Http\RedirectResponse;

class InvestigationController extends Controller
{
    public function start(StartInvestigationRequest $request, Incident $incident, StartInvestigationAction $action): RedirectResponse
    {
        $action($incident, $request->user(), $request->toDto());

        return redirect()
            ->route('incidents.show', ['incident' => $incident, 'tab' => 'investigation'])
            ->with('success', 'Investigation started.');
    }

    public function addTeamMember(AddTeamMemberRequest $request, Investigation $investigation, AddTeamMemberAction $action): RedirectResponse
    {
        $action($investigation, $request->toDto());

        return back()->with('success', 'Team member added.');
    }

    public function removeTeamMember(Investigation $investigation, InvestigationTeamMember $teamMember, RemoveTeamMemberAction $action): RedirectResponse
    {
        $this->authorize('manageTeam', $investigation);
        abort_unless($teamMember->investigation_id === $investigation->id, 404);

        $action($teamMember);

        return back()->with('success', 'Team member removed.');
    }

    public function addFinding(AddFindingRequest $request, Investigation $investigation, AddFindingAction $action): RedirectResponse
    {
        $action($investigation, $request->toDto());

        return back()->with('success', 'Finding added.');
    }

    public function updateFinding(UpdateFindingRequest $request, Investigation $investigation, InvestigationFinding $finding, UpdateFindingAction $action): RedirectResponse
    {
        abort_unless($finding->investigation_id === $investigation->id, 404);

        $action($finding, $request->toDto());

        return back()->with('success', 'Finding updated.');
    }

    public function deleteFinding(Investigation $investigation, InvestigationFinding $finding, DeleteFindingAction $action): RedirectResponse
    {
        $this->authorize('recordFindings', $investigation);
        abort_unless($finding->investigation_id === $investigation->id, 404);

        $action($finding);

        return back()->with('success', 'Finding removed.');
    }

    public function complete(CompleteInvestigationRequest $request, Investigation $investigation, CompleteInvestigationAction $action): RedirectResponse
    {
        $action($investigation, $request->toDto());

        return redirect()
            ->route('incidents.show', ['incident' => $investigation->incident_id, 'tab' => 'investigation'])
            ->with('success', 'Investigation completed.');
    }
}
```

The `abort_unless` guards prevent a lead investigator on *their own* investigation from passing someone else's team-member/finding ID in the URL alongside their own investigation ID — Laravel's implicit route-model-binding resolves nested route params independently unless you opt into `scopeBindings()`, so without this check a valid investigation ID + a stranger's child-record ID would still both resolve, letting the wrong investigation's records be edited/deleted. Same class of bug as a department-scoping leak caught in an earlier phase of this project — checked explicitly here rather than relying on binding magic.

- [ ] **Step 6: Add routes**

In `routes/web.php`, add the import:

```php
use App\Http\Controllers\InvestigationController;
```

Inside the `auth` middleware group, after the existing `incidents.assign` route:

```php
    Route::post('/incidents/{incident}/investigation', [InvestigationController::class, 'start'])->name('incidents.investigation.start');
    Route::post('/investigations/{investigation}/team-members', [InvestigationController::class, 'addTeamMember'])->name('investigations.team-members.store');
    Route::delete('/investigations/{investigation}/team-members/{teamMember}', [InvestigationController::class, 'removeTeamMember'])->name('investigations.team-members.destroy');
    Route::post('/investigations/{investigation}/findings', [InvestigationController::class, 'addFinding'])->name('investigations.findings.store');
    Route::patch('/investigations/{investigation}/findings/{finding}', [InvestigationController::class, 'updateFinding'])->name('investigations.findings.update');
    Route::delete('/investigations/{investigation}/findings/{finding}', [InvestigationController::class, 'deleteFinding'])->name('investigations.findings.destroy');
    Route::post('/investigations/{investigation}/complete', [InvestigationController::class, 'complete'])->name('investigations.complete');
```

- [ ] **Step 7: Run tests**

```bash
php artisan test --filter=InvestigationTest
```

Expected: `29 passed` (19 from Task 7 + 10 new HTTP tests: the 8 from the original plan plus 2 extra ownership-scoping tests — `test_removing_a_team_member_scoped_to_another_investigation_is_rejected` and `test_updating_a_finding_scoped_to_another_investigation_is_rejected` — added in this rewrite to actually exercise both `abort_unless` guards, not just the team-member one).

```bash
php artisan test
```

Expected: all green.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Requests/Investigations app/Actions/Investigations app/Http/Controllers/InvestigationController.php routes/web.php tests/Feature/Investigations/InvestigationTest.php
git commit -m "feat: add investigation actions, DTO-aware form requests, controller, routes"
```

## Context for Task 9

`InvestigationController` should end up thin — if you find yourself writing any conditional business logic in it beyond the `abort_unless` ownership guards, that logic belongs in `InvestigationService` (called via the Action), not the controller. The Actions are intentionally almost content-free (a constructor + one `__invoke` line) — that's correct for this codebase size, not a sign something's missing; per the user's explicit direction, their job is only to sit between the HTTP layer and the Service.

---

### Task 10: Resources and IncidentController@show wiring

**Files:**
- Create: `app/Http/Resources/InvestigationResource.php`
- Create: `app/Http/Resources/InvestigationTeamMemberResource.php`
- Create: `app/Http/Resources/InvestigationFindingResource.php`
- Modify: `app/Http/Controllers/IncidentController.php`

- [ ] **Step 1: Write `InvestigationFindingResource`**

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvestigationFindingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sequence' => $this->sequence,
            'category' => $this->category,
            'question' => $this->question,
            'finding' => $this->finding,
            'is_root_cause' => $this->is_root_cause,
        ];
    }
}
```

- [ ] **Step 2: Write `InvestigationTeamMemberResource`**

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvestigationTeamMemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'role_in_team' => $this->role_in_team,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ]),
        ];
    }
}
```

- [ ] **Step 3: Write `InvestigationResource`**

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvestigationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'objective' => $this->objective,
            'methodology' => [
                'value' => $this->methodology->value,
                'label' => $this->methodology->label(),
                'uses_sequence' => $this->methodology->usesSequence(),
                'uses_category' => $this->methodology->usesCategory(),
            ],
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
            ],
            'started_at' => $this->started_at,
            'target_completion_at' => $this->target_completion_at,
            'completed_at' => $this->completed_at,
            'conclusion' => $this->conclusion,
            'lead_investigator' => $this->whenLoaded('leadInvestigator', fn () => [
                'id' => $this->leadInvestigator->id,
                'name' => $this->leadInvestigator->name,
            ]),
            'team_members' => InvestigationTeamMemberResource::collection($this->whenLoaded('teamMembers')),
            'findings' => InvestigationFindingResource::collection($this->whenLoaded('findings')),
        ];
    }
}
```

- [ ] **Step 4: Wire into `IncidentController@show`**

In `app/Http/Controllers/IncidentController.php`, add the import:

```php
use App\Http\Resources\InvestigationResource;
```

Remove `'investigation.leadInvestigator', 'investigation.teamMembers.user', 'investigation.findings'` from the `$incident->load([...])` call in `show()` — the investigation is no longer nested inside the raw `$incident` prop, it becomes its own top-level Resource-shaped prop. The `load()` call goes back to exactly what it was after Phase 4 (reporter, department, incidentType, assignedInvestigator, individuals, witnesses, actions, narrativeEvents, contributingFactors, attachments).

Change the `return Inertia::render(...)` block to:

```php
        $canStartInvestigation = $user->can('start', $incident);
        $investigation = $incident->investigation?->load(['leadInvestigator', 'teamMembers.user', 'findings']);
        $canManageInvestigationTeam = $investigation && $user->can('manageTeam', $investigation);

        return Inertia::render('Incidents/Show', [
            'incident' => $incident,
            'tab' => $request->string('tab', 'overview')->toString(),
            'auditLogs' => $incident->auditLogs()->with('actor')->latest()->latest('id')->get(),
            'investigation' => $investigation ? new InvestigationResource($investigation) : null,
            'investigators' => $user->can('assign', $incident)
                ? User::where('role', Role::Investigator)->where('is_active', true)->get(['id', 'name'])
                : [],
            'potentialTeamMembers' => ($canStartInvestigation || $canManageInvestigationTeam)
                ? User::where('is_active', true)->orderBy('name')->get(['id', 'name', 'role'])
                : [],
            'can' => [
                'update' => $user->can('update', $incident),
                'review' => $user->can('review', $incident),
                'assign' => $user->can('assign', $incident),
                'startInvestigation' => $canStartInvestigation,
                'manageInvestigationTeam' => $canManageInvestigationTeam,
                'recordFindings' => $investigation && $user->can('recordFindings', $investigation),
                'completeInvestigation' => $investigation && $user->can('complete', $investigation),
            ],
        ]);
```

- [ ] **Step 5: Add a regression test**

Append to `tests/Feature/Investigations/InvestigationTest.php`:

```php
    public function test_the_incident_show_page_exposes_a_resource_shaped_investigation_prop_and_can_flags(): void
    {
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $incident = $this->assignedIncident($investigator);

        $response = $this->actingAs($investigator)->get("/incidents/{$incident->id}?tab=investigation");

        $response->assertInertia(fn ($page) => $page
            ->component('Incidents/Show')
            ->where('investigation', null)
            ->where('can.startInvestigation', true)
            ->where('can.completeInvestigation', false)
        );

        $investigation = app(InvestigationService::class)->start($incident->fresh(), $investigator, $this->startData('five_whys'));

        $this->actingAs($investigator)
            ->get("/incidents/{$incident->id}?tab=investigation")
            ->assertInertia(fn ($page) => $page
                ->where('can.startInvestigation', false)
                ->where('can.recordFindings', true)
                ->where('investigation.id', $investigation->id)
                ->where('investigation.methodology.value', 'five_whys')
                ->where('investigation.methodology.label', '5 Whys')
                ->where('investigation.methodology.uses_sequence', true)
            );
    }
```

- [ ] **Step 6: Run tests**

```bash
php artisan test --filter=InvestigationTest
```

Expected: `30 passed`.

```bash
php artisan test
```

Expected: all green.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Resources app/Http/Controllers/IncidentController.php tests/Feature/Investigations/InvestigationTest.php
git commit -m "feat: expose investigation via a Resource-shaped Inertia prop"
```

## Context for Task 10

The `investigation` prop is now **top-level**, not nested at `incident.investigation` — this is a deliberate change from the pre-layering version of this plan, so that Vue always reads a clean Resource shape rather than a mix of a raw Eloquent-serialized model tree and a wrapped Resource. Task 11 (`InvestigationPanel.vue`) and Task 12 (`Show.vue` wiring) are written against this top-level prop.

**Implementation notes recorded after the fact (2026-09-18), since the actual shipped code differs from the literal snippets above in three ways, all confirmed correct by review:**
1. This codebase runs Laravel 9.52.22, whose base `JsonResource::toArray()` has no parameter/return type. The `toArray(Request $request): array` signature shown above is a fatal "declaration incompatible" error on this version — the shipped Resources use the untyped `toArray($request)` instead, same body.
2. `InvestigationResource` needs `public static $wrap = null;` — Inertia serializes it via `toResponse()->getData(true)`, which applies Laravel's default `"data"` envelope otherwise, putting every field under `investigation.data.*` instead of `investigation.*`.
3. `$incident->investigation?->load([...])` alone is NOT enough to keep `investigation` out of `$incident`'s own serialized JSON — Eloquent caches any relation it resolves regardless of how it's accessed, so touching `$incident->investigation` at all leaves it cached on the model and re-serialized raw (untrimmed, duplicating the Resource-shaped prop). The shipped code adds `$incident->unsetRelation('investigation');` right after reading it into `$investigation`, and the regression test asserts `->missing('incident.investigation')` to lock this in.

---

### Task 11: InvestigationPanel.vue

**Files:**
- Create: `resources/js/Components/Incidents/InvestigationPanel.vue`

- [ ] **Step 1: Write the component**

```vue
<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import ConfirmationDialog from '@/Components/ConfirmationDialog.vue';
import { formatDate } from '@/Utils/formatDate';

const props = defineProps({
    incident: { type: Object, required: true },
    investigation: { type: Object, default: null },
    can: { type: Object, required: true },
    potentialTeamMembers: { type: Array, default: () => [] },
});

const methodologyOptions = {
    five_whys: '5 Whys',
    fishbone: 'Fishbone (Ishikawa)',
    hfacs: 'Human Factors (HFACS)',
    contributing_factors: 'Contributing Factors',
};

const startForm = useForm({
    objective: '',
    methodology: 'five_whys',
    target_completion_at: '',
});

function startInvestigation() {
    startForm.post(`/incidents/${props.incident.id}/investigation`, { preserveScroll: true });
}

const memberForm = useForm({ user_id: null, role_in_team: '' });

function addTeamMember() {
    memberForm.post(`/investigations/${props.investigation.id}/team-members`, {
        preserveScroll: true,
        onSuccess: () => memberForm.reset(),
    });
}

function removeTeamMember(memberId) {
    memberForm.delete(`/investigations/${props.investigation.id}/team-members/${memberId}`, { preserveScroll: true });
}

const usesSequence = computed(() => props.investigation?.methodology?.uses_sequence ?? false);
const usesCategory = computed(() => props.investigation?.methodology?.uses_category ?? false);

const findingForm = useForm({ category: '', question: '', finding: '', is_root_cause: false });
const editingFindingId = ref(null);
const editForm = useForm({ category: '', question: '', finding: '', is_root_cause: false });

function addFinding() {
    findingForm.post(`/investigations/${props.investigation.id}/findings`, {
        preserveScroll: true,
        onSuccess: () => findingForm.reset(),
    });
}

function startEditing(finding) {
    editingFindingId.value = finding.id;
    editForm.category = finding.category ?? '';
    editForm.question = finding.question ?? '';
    editForm.finding = finding.finding;
    editForm.is_root_cause = finding.is_root_cause;
}

function saveEdit(findingId) {
    editForm.patch(`/investigations/${props.investigation.id}/findings/${findingId}`, {
        preserveScroll: true,
        onSuccess: () => (editingFindingId.value = null),
    });
}

function deleteFinding(findingId) {
    findingForm.delete(`/investigations/${props.investigation.id}/findings/${findingId}`, { preserveScroll: true });
}

const completeForm = useForm({ conclusion: '' });
const showCompleteConfirm = ref(false);

function completeInvestigation() {
    completeForm.post(`/investigations/${props.investigation.id}/complete`, {
        preserveScroll: true,
        onFinish: () => (showCompleteConfirm.value = false),
    });
}
</script>

<template>
    <div class="flex flex-col gap-space-lg">
        <div v-if="!investigation">
            <div v-if="can.startInvestigation" class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-space-sm">
                <h2 class="font-title-lg text-title-lg text-primary font-bold">Start Investigation</h2>
                <label class="font-label-md text-label-md text-on-surface font-semibold" for="objective">Investigation objective</label>
                <textarea
                    id="objective"
                    v-model="startForm.objective"
                    rows="3"
                    class="w-full p-3 rounded-lg bg-surface-container-low"
                />
                <span v-if="startForm.errors.objective" class="font-body-sm text-body-sm text-error">{{ startForm.errors.objective }}</span>

                <label class="font-label-md text-label-md text-on-surface font-semibold" for="methodology">RCA methodology</label>
                <select id="methodology" v-model="startForm.methodology" class="w-full p-3 rounded-lg bg-surface-container-low">
                    <option v-for="(label, value) in methodologyOptions" :key="value" :value="value">{{ label }}</option>
                </select>
                <span v-if="startForm.errors.methodology" class="font-body-sm text-body-sm text-error">{{ startForm.errors.methodology }}</span>

                <label class="font-label-md text-label-md text-on-surface font-semibold" for="target_completion_at">Target completion date</label>
                <input
                    id="target_completion_at"
                    v-model="startForm.target_completion_at"
                    type="date"
                    class="w-full p-3 rounded-lg bg-surface-container-low"
                />

                <button
                    type="button"
                    :disabled="startForm.processing"
                    class="px-4 py-2 rounded-lg bg-primary text-on-primary font-label-md text-label-md font-semibold disabled:opacity-60 w-fit"
                    @click="startInvestigation"
                >
                    Start Investigation
                </button>
            </div>
            <div v-else class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm text-center">
                <p class="font-body-md text-body-md text-on-surface-variant">
                    Investigation has not started yet. It becomes available once an investigator is assigned.
                </p>
            </div>
        </div>

        <template v-else>
            <div class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-space-md">
                <div class="flex flex-wrap items-center justify-between gap-space-sm">
                    <h3 class="font-title-lg text-title-lg text-on-surface">Investigation Mandate</h3>
                    <span class="px-3 py-1 rounded-full bg-secondary-container text-on-secondary-container font-label-sm text-body-sm font-semibold">
                        {{ investigation.status.label }}
                    </span>
                </div>
                <div class="p-space-md rounded-lg bg-surface-container-low flex flex-col gap-space-xs">
                    <span class="font-label-sm text-body-sm uppercase tracking-wider text-primary font-bold">Objective</span>
                    <p class="font-body-md text-body-md text-on-surface">{{ investigation.objective }}</p>
                </div>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    <div class="flex flex-col gap-0.5">
                        <span class="font-label-sm text-body-sm text-outline">Methodology</span>
                        <span class="font-title-sm text-title-sm text-primary font-semibold">{{ investigation.methodology.label }}</span>
                    </div>
                    <div class="flex flex-col gap-0.5">
                        <span class="font-label-sm text-body-sm text-outline">Started</span>
                        <span class="font-code-tabular text-body-sm text-on-surface">{{ formatDate(investigation.started_at) }}</span>
                    </div>
                    <div class="flex flex-col gap-0.5">
                        <span class="font-label-sm text-body-sm text-outline">Target Completion</span>
                        <span class="font-code-tabular text-body-sm text-on-surface">{{ formatDate(investigation.target_completion_at) }}</span>
                    </div>
                    <div class="flex flex-col gap-0.5">
                        <span class="font-label-sm text-body-sm text-outline">Lead Investigator</span>
                        <span class="font-body-sm text-body-sm text-on-surface">{{ investigation.lead_investigator?.name }}</span>
                    </div>
                </div>

                <div class="flex flex-col gap-2">
                    <span class="font-label-sm text-body-sm uppercase text-outline font-semibold">Investigation Team</span>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                        <div v-for="member in investigation.team_members" :key="member.id" class="flex items-center justify-between gap-2 p-2.5 rounded-lg bg-surface-container">
                            <div class="flex flex-col min-w-0">
                                <span class="font-title-sm text-title-sm text-on-surface truncate">{{ member.user?.name }}</span>
                                <span class="font-label-sm text-body-sm text-outline truncate">{{ member.role_in_team }}</span>
                            </div>
                            <button
                                v-if="can.manageInvestigationTeam"
                                type="button"
                                class="font-label-sm text-body-sm text-error"
                                @click="removeTeamMember(member.id)"
                            >
                                Remove
                            </button>
                        </div>
                    </div>

                    <form v-if="can.manageInvestigationTeam" class="flex flex-wrap items-end gap-2" @submit.prevent="addTeamMember">
                        <div class="flex flex-col">
                            <label class="font-label-sm text-body-sm text-on-surface" for="member_user_id">Add member</label>
                            <select id="member_user_id" v-model="memberForm.user_id" class="p-2 rounded-lg bg-surface-container-low">
                                <option :value="null" disabled>Select a user</option>
                                <option v-for="option in potentialTeamMembers" :key="option.id" :value="option.id">{{ option.name }}</option>
                            </select>
                        </div>
                        <div class="flex flex-col">
                            <label class="font-label-sm text-body-sm text-on-surface" for="member_role">Role on team</label>
                            <input id="member_role" v-model="memberForm.role_in_team" type="text" class="p-2 rounded-lg bg-surface-container-low" />
                        </div>
                        <button type="submit" :disabled="memberForm.processing" class="px-3 py-2 rounded-lg bg-primary text-on-primary font-label-sm text-body-sm font-semibold disabled:opacity-60">
                            Add
                        </button>
                    </form>
                </div>
            </div>

            <div class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-space-md">
                <h3 class="font-title-lg text-title-lg text-on-surface">Root Cause Analysis — {{ investigation.methodology.label }}</h3>

                <div v-if="!investigation.findings?.length" class="text-center font-body-sm text-body-sm text-outline p-space-md">
                    No findings recorded yet.
                </div>

                <div v-for="finding in investigation.findings" :key="finding.id" class="flex items-start gap-3 p-3 rounded-lg" :class="finding.is_root_cause ? 'bg-error-container/30' : 'bg-surface-container-low'">
                    <div v-if="usesSequence" class="w-8 h-8 rounded-full flex items-center justify-center font-code-tabular text-body-sm font-bold flex-shrink-0" :class="finding.is_root_cause ? 'bg-error text-on-error' : 'bg-primary text-on-primary'">
                        {{ finding.sequence }}
                    </div>

                    <template v-if="editingFindingId === finding.id">
                        <div class="flex-1 flex flex-col gap-2">
                            <input v-if="usesCategory" v-model="editForm.category" type="text" placeholder="Category" class="p-2 rounded-lg bg-surface-container" />
                            <input v-if="usesSequence" v-model="editForm.question" type="text" placeholder="Why…?" class="p-2 rounded-lg bg-surface-container" />
                            <textarea v-model="editForm.finding" rows="2" class="p-2 rounded-lg bg-surface-container" />
                            <label class="flex items-center gap-2 font-label-sm text-body-sm text-on-surface">
                                <input v-model="editForm.is_root_cause" type="checkbox" /> Root cause
                            </label>
                            <div class="flex gap-2">
                                <button type="button" class="px-3 py-1.5 rounded-lg bg-primary text-on-primary font-label-sm text-body-sm" @click="saveEdit(finding.id)">Save</button>
                                <button type="button" class="px-3 py-1.5 rounded-lg bg-surface-container font-label-sm text-body-sm" @click="editingFindingId = null">Cancel</button>
                            </div>
                        </div>
                    </template>
                    <template v-else>
                        <div class="flex-1 flex flex-col gap-0.5">
                            <span v-if="finding.category" class="font-label-sm text-body-sm text-secondary font-semibold">{{ finding.category }}</span>
                            <span v-if="finding.question" class="font-label-sm text-body-sm text-primary font-semibold">{{ finding.question }}</span>
                            <p class="font-body-md text-body-md text-on-surface">{{ finding.finding }}</p>
                            <span v-if="finding.is_root_cause" class="px-2 py-0.5 rounded-md bg-error text-on-error font-label-sm text-body-sm uppercase font-bold w-fit">Root Cause</span>
                        </div>
                        <div v-if="can.recordFindings" class="flex flex-col gap-1 flex-shrink-0">
                            <button type="button" class="font-label-sm text-body-sm text-primary" @click="startEditing(finding)">Edit</button>
                            <button type="button" class="font-label-sm text-body-sm text-error" @click="deleteFinding(finding.id)">Delete</button>
                        </div>
                    </template>
                </div>

                <form v-if="can.recordFindings" class="flex flex-col gap-2 p-space-md rounded-lg bg-surface-container-low" @submit.prevent="addFinding">
                    <span class="font-label-sm text-body-sm uppercase text-outline font-semibold">Add Finding</span>
                    <input v-if="usesCategory" v-model="findingForm.category" type="text" placeholder="Category (e.g. Equipment, Environment)" class="p-2 rounded-lg bg-surface-container" />
                    <input v-if="usesSequence" v-model="findingForm.question" type="text" placeholder="Why…?" class="p-2 rounded-lg bg-surface-container" />
                    <textarea v-model="findingForm.finding" rows="2" placeholder="Finding" class="p-2 rounded-lg bg-surface-container" />
                    <span v-if="findingForm.errors.finding" class="font-body-sm text-body-sm text-error">{{ findingForm.errors.finding }}</span>
                    <label class="flex items-center gap-2 font-label-sm text-body-sm text-on-surface">
                        <input v-model="findingForm.is_root_cause" type="checkbox" /> Mark as root cause
                    </label>
                    <button type="submit" :disabled="findingForm.processing" class="px-4 py-2 rounded-lg bg-primary text-on-primary font-label-md text-label-md font-semibold disabled:opacity-60 w-fit">
                        Add Finding
                    </button>
                </form>
            </div>

            <div v-if="investigation.status.value === 'completed'" class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-space-xs">
                <span class="font-label-sm text-body-sm uppercase text-outline font-semibold">Conclusion</span>
                <p class="font-body-md text-body-md text-on-surface">{{ investigation.conclusion }}</p>
                <span class="font-code-tabular text-body-sm text-outline">Completed {{ formatDate(investigation.completed_at) }}</span>
            </div>

            <div v-else-if="can.completeInvestigation" class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-space-sm">
                <h2 class="font-title-lg text-title-lg text-primary font-bold">Complete Investigation</h2>
                <label class="font-label-md text-label-md text-on-surface font-semibold" for="conclusion">Conclusion</label>
                <textarea id="conclusion" v-model="completeForm.conclusion" rows="3" class="w-full p-3 rounded-lg bg-surface-container-low" />
                <span v-if="completeForm.errors.conclusion" class="font-body-sm text-body-sm text-error">{{ completeForm.errors.conclusion }}</span>
                <button
                    type="button"
                    :disabled="completeForm.processing"
                    class="px-4 py-2 rounded-lg bg-primary text-on-primary font-label-md text-label-md font-semibold disabled:opacity-60 w-fit"
                    @click="showCompleteConfirm = true"
                >
                    Complete Investigation
                </button>
            </div>
        </template>

        <ConfirmationDialog
            :show="showCompleteConfirm"
            title="Complete this investigation?"
            message="This moves the incident forward to Corrective & Preventive Actions. Findings can no longer be edited afterward."
            confirm-label="Complete Investigation"
            :processing="completeForm.processing"
            @cancel="showCompleteConfirm = false"
            @confirm="completeInvestigation"
        />
    </div>
</template>
```

- [ ] **Step 2: Commit**

```bash
git add resources/js/Components/Incidents/InvestigationPanel.vue
git commit -m "feat: add InvestigationPanel component (RCA workbench UI)"
```

## Context for Task 11

`methodologyOptions` here is only the "Start Investigation" form's dropdown label map (needed before an `investigation` resource exists to read labels from) — once an investigation exists, all label rendering (`investigation.methodology.label`, `investigation.status.label`) comes from the `InvestigationResource`/enum `label()` methods on the backend (Task 10), not a duplicated client-side map. `usesSequence`/`usesCategory` read `investigation.methodology.uses_sequence`/`uses_category`, also computed backend-side by `InvestigationMethodology::usesSequence()`/`usesCategory()` (already shipped in Task 2) and passed through the Resource — this is the simplification the Resource layer buys over the pre-layering version of this plan, which duplicated a label map in Vue.

---

### Task 12: Wire InvestigationPanel into Show.vue

**Files:**
- Modify: `resources/js/Pages/Incidents/Show.vue`

- [ ] **Step 1: Import the component and accept the new top-level props**

Change the imports:

```js
import WorkflowActionsPanel from '@/Components/Incidents/WorkflowActionsPanel.vue';
import InvestigationPanel from '@/Components/Incidents/InvestigationPanel.vue';
```

Change `defineProps`:

```js
const props = defineProps({
    incident: { type: Object, required: true },
    tab: { type: String, required: true },
    can: { type: Object, required: true },
    investigators: { type: Array, default: () => [] },
    investigation: { type: Object, default: null },
    potentialTeamMembers: { type: Array, default: () => [] },
    auditLogs: { type: Array, required: true },
});
```

- [ ] **Step 2: Replace the placeholder branch with a real `investigation` branch**

Change:

```html
        <div v-else class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm text-center">
            <FontAwesomeIcon icon="circle-info" class="text-primary text-title-lg mb-2" />
            <p class="font-body-md text-body-md text-on-surface-variant">
                This tab will be available once the corresponding module ships in a later phase.
            </p>
        </div>
```

to:

```html
        <InvestigationPanel
            v-else-if="activeTab === 'investigation'"
            :incident="incident"
            :investigation="investigation"
            :can="can"
            :potential-team-members="potentialTeamMembers"
        />

        <div v-else class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm text-center">
            <FontAwesomeIcon icon="circle-info" class="text-primary text-title-lg mb-2" />
            <p class="font-body-md text-body-md text-on-surface-variant">
                This tab will be available once the corresponding module ships in a later phase.
            </p>
        </div>
```

(This must come before the final `v-else` block in the template's `v-if`/`v-else-if`/`v-else` chain — insert it as the last `v-else-if` immediately above the catch-all `v-else`. Note `:investigation="investigation"` reads the new top-level prop, NOT `incident.investigation` — that nested path no longer exists on the `incident` object as of Task 10.)

- [ ] **Step 3: Build frontend assets**

```bash
npm run build
```

Expected: no errors.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/Incidents/Show.vue
git commit -m "feat: render the Investigation tab with real RCA data"
```

---

### Task 13: Final holistic review and verification

**Files:** none (verification-only task).

- [ ] **Step 1: Run the full backend test suite**

```bash
php artisan test
```

Expected: all green — Phase 3 + Phase 4 + all new Phase 5 tests.

- [ ] **Step 2: Holistic cross-task code review**

Read through every file this plan touched as a whole, not task-by-task, specifically looking for:
1. **A scoping gap at the list/nested level.** Can a user who is a team member on Investigation A reach Investigation B's team-members/findings routes by guessing IDs? (Task 9's `abort_unless` guards should prevent this — verify they're present and actually tested by `test_removing_a_team_member_scoped_to_another_investigation_is_rejected` / `test_updating_a_finding_scoped_to_another_investigation_is_rejected`.)
2. **An ordering claim that isn't tested.** Verify `InvestigationFindingRepository::allFor()` (via `Investigation::findings()`'s `orderBy('sequence')->orderBy('id')`) is actually exercised by the renumbering test with out-of-order deletion, not just asserted on a fresh, already-ordered collection.
3. **Layering discipline.** Does `InvestigationController` or any Action contain business logic that should live in `InvestigationService`? Does `InvestigationService` call Eloquent directly anywhere instead of going through a Repository? Does anything construct a `StartInvestigationData`/`FindingData`/etc. by hand outside of a Form Request's `toDto()` or a test helper (it shouldn't need to)?
4. Confirm `'investigators'` (used by `WorkflowActionsPanel` for the *assign* step) and `'potentialTeamMembers'` (used by `InvestigationPanel` for team staffing) stay separate props serving separate lifecycle steps — `InvestigationPanel.vue` should never read `investigators`.

- [ ] **Step 3: Browser-verify end-to-end with Playwright**

Per the Phase 2 "always browser-test Inertia/Vue changes" lesson (`docs/architecture.md` §9a), don't trust `npm run build` + `php artisan test` alone. Using a dev-seeded account (see `DevUserSeeder`):
1. Log in as a supervisor, review and assign a submitted incident to an investigator.
2. Log out, log in as that investigator, open the incident, switch to the "Investigation & Root Cause" tab.
3. Start an investigation (pick 5 Whys), confirm the incident status badge updates to "Under Investigation" and the mandate panel renders with the backend-supplied methodology label.
4. Add a team member, confirm they appear in the team list.
5. Add 2–3 findings, confirm sequence numbers render 1, 2, 3 in order; delete the middle one and confirm the remaining two renumber to 1, 2.
6. Mark the last remaining finding as root cause, complete the investigation with a conclusion.
7. Confirm the incident status badge updates to "Corrective Action", the conclusion renders read-only, and the Audit Trail tab now shows `investigation_started` and `investigation_completed` entries in chronological order alongside the Phase 4 entries.
8. Confirm zero console errors throughout.

- [ ] **Step 4: Update `docs/architecture.md`**

Add a `§9f` entry (following the `§9e` Phase 4 pattern) documenting: this phase's layered architecture (DTOs/Repositories/Queries/Actions/Resources) as an explicit, phase-scoped deviation from Phases 1–4's simpler Service+Policy+FormRequest pattern, per direct user instruction on 2026-09-18 — note that Phases 1–4 were deliberately NOT retrofitted, so the codebase now has two coexisting architectural styles by area (this is intentional, not drift); the `investigations.escalated_at` column added beyond the original §2.3 schema; the mockup-vs-schema deviation (methodology chosen once at start); and the `abort_unless` nested-ownership guard pattern for future nested-resource controllers.

- [ ] **Step 5: Update memory**

Per the standing instruction, update `project_state.md` and `MEMORY.md` in `C:\Users\DOH\.claude\projects\c--wamp64-projects-incident-report\memory\` with a "Phase 5 (Investigation) complete" entry — what shipped, the mid-phase architecture pivot to a layered pattern (DTO/Repository/Query/Action/Resource) requested by the user and scoped to this phase only, any bugs the holistic review or Playwright pass caught, and what's next (Phase 6 — CAPA). Also record as a **feedback** memory: the user's standing preference for this layered architecture — ask at the start of future phases whether it should extend there too, don't assume either way.

- [ ] **Step 6: Final commit**

```bash
git add docs/architecture.md
git commit -m "docs: mark Phase 5 (Investigation) plan complete, document in architecture.md"
```
