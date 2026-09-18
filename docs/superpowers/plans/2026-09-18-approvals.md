# Phase 7: Approvals & Closure Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Once an incident has either (a) reached `IncidentStatus::Verified` (every CAPA on it verified) or (b) reached `IncidentStatus::CorrectiveAction` with zero corrective actions ever created on it, let a Quality & Safety Officer / Administrator request closure approval. A Department Head (scoped to their own department), Management, or Administrator — never the person who requested it — then either approves (closing the incident immediately) or returns it for further corrective action. Every incident can be closed exactly once per approval cycle; a returned incident can be re-submitted for approval again later, producing a fresh approval record. All gated by policy, audit-logged, and covered by a fifth SLA-escalation sweep alongside the existing four.

**Architecture — continues Phases 5 & 6's layered pattern, per explicit user confirmation (2026-09-18) at the start of this phase.** Same layers: an Enum (`App\Enums\ApprovalStatus`), a Model (`App\Models\Approval`), DTOs (`app/DataTransferObjects/Approvals/`), a Repository (`app/Repositories/ApprovalRepository.php`), a Query (`app/Queries/OverdueApprovalsQuery.php`), a Service (`app/Services/ApprovalService.php`) holding all business logic, thin invokable Actions (`app/Actions/Approvals/`), a Resource (`app/Http/Resources/ApprovalResource.php`) with per-item `can` flags. **Authorization is the one exception to "one policy per model" in this phase**: `requestApproval`/`markNoCorrectiveActionNeeded`/`approveClosure`/`returnFromApproval` all live on the *existing* `IncidentPolicy`, not a new `ApprovalPolicy` — they all gate on the `Incident`'s own status and role, exactly like `docs/architecture.md` §4 already specifies ("`IncidentPolicy` — ... `approve`, `close` gated per stage + role"), the same way `start()` (which kicks off an Investigation) already lives on `IncidentPolicy` rather than `InvestigationPolicy`.

**Design decisions confirmed with the user before writing this plan (2026-09-18):**
1. **Who requests, who approves:** Quality & Safety Officer / Administrator can request. Department Head (own department only — same department-scoping already used by `IncidentPolicy::hasReviewOrAssignAccess()`), Management, or Administrator can approve/return — but never the same user who made the request, even if that user's role would otherwise qualify (e.g. an Administrator can request, but that specific Administrator cannot then approve their own request; a *different* Administrator can).
2. **The "no CAPA needed" gap is being closed this phase.** Right now, an incident can only ever reach `Verified` by going through at least one verified CAPA (Phase 6's rollup). Architecture.md's original design assumed low-severity incidents could skip investigation/CAPA entirely — that broader skip was never built and stays deferred (a much larger config-driven "required stages per severity" feature). What *is* built this phase is a narrower, well-defined fix: once an incident's investigation is complete (`status === CorrectiveAction`) and **zero corrective actions have ever been created on it**, a QSO/Administrator can mark it as needing no corrective action (with a required written justification) and send it straight into the approval queue — without inventing a fake CAPA just to satisfy the rollup.
3. **Approve = close in one action.** Mirrors CAPA's `complete()` going straight to `for_verification` in one step rather than a separate resting stage. There is no intermediate "approved but not yet closed" status.
4. **Escalation:** a fifth SLA sweep, `approval_sla_hours` per severity in `config/incident_workflow.php`, mirroring `review_sla_hours` (same "a person must look at this and decide" shape) — `120/72/48/24` hours for `level_1_low` / `level_2_moderate` / `level_3_high` / `level_4_critical_sentinel`.
5. **No new domain Events/Listeners/Notifications**, consistent with Phases 5 & 6 (not Phase 4) — the interactive actions (`requestApproval`, `markNoCorrectiveActionNeeded`, `approve`, `returnForRevision`) only write audit trail entries (via the existing `IncidentObserver` + `$incident->auditComment`, the same mechanism every prior phase's `IncidentService` methods already use); the escalation sweep is the only source of notifications for this stage, reusing the existing `IncidentEscalationNotification` class.
6. **Scope:** only the incident detail page's Approvals tab is built this phase (same precedent as Phases 5 & 6 each scoping to their own `Show.vue` tab, no cross-incident queue page). A returned-then-corrected incident produces a **new** `approvals` row when re-submitted, rather than reusing/overwriting the returned one — so the tab renders the full decision history, oldest first is wrong, newest first like CAPA's card list.

**Tech Stack:** Laravel 9 (PHP 8.2 backed enums, Eloquent, Form Requests, Policies), Vue 3 + Inertia (`useForm`) — unchanged from Phases 5 & 6.

**Deliberately out of scope:** the broader "skip investigation/CAPA entirely for low-severity incidents" config-driven feature (still deferred, unchanged from Phase 1's original note); analytics/"Learn" dashboards (Phase 8); a cross-incident Approvals queue page; new domain Events (see decision 5); an `ApprovalPolicy` class (see architecture note above).

---

## File Structure

**Backend — new files:**
- `database/migrations/2026_09_18_000010_create_approvals_table.php`
- `app/Enums/ApprovalStatus.php`
- `app/Models/Approval.php`
- `app/DataTransferObjects/Approvals/MarkNoCorrectiveActionNeededData.php`
- `app/DataTransferObjects/Approvals/DecideApprovalData.php`
- `app/Repositories/ApprovalRepository.php`
- `app/Queries/OverdueApprovalsQuery.php`
- `app/Services/ApprovalService.php`
- `app/Http/Requests/Approvals/MarkNoCorrectiveActionNeededRequest.php`
- `app/Http/Requests/Approvals/ApproveClosureRequest.php`
- `app/Http/Requests/Approvals/ReturnFromApprovalRequest.php`
- `app/Actions/Approvals/RequestApprovalAction.php`
- `app/Actions/Approvals/MarkNoCorrectiveActionNeededAction.php`
- `app/Actions/Approvals/ApproveClosureAction.php`
- `app/Actions/Approvals/ReturnFromApprovalAction.php`
- `app/Http/Controllers/ApprovalController.php`
- `app/Http/Resources/ApprovalResource.php`
- `tests/Feature/Approvals/ApprovalTest.php`
- `tests/Feature/Approvals/ApprovalEscalationTest.php`
- `resources/js/Components/Incidents/ApprovalPanel.vue`

**Backend — modified files:**
- `app/Models/Incident.php` (add `approvals()` relation)
- `app/Policies/IncidentPolicy.php` (add `requestApproval`/`markNoCorrectiveActionNeeded`/`approveClosure`/`returnFromApproval`)
- `config/incident_workflow.php` (add `approval_sla_hours`)
- `app/Console/Commands/CheckOverdueIncidents.php` (add `escalateOverdueApprovals()`)
- `routes/web.php` (approval routes)
- `app/Http/Controllers/IncidentController.php` (`show()`: add `approvals`, `can.requestApproval`, `can.markNoCorrectiveActionNeeded`)

**Frontend — modified files:**
- `resources/js/Pages/Incidents/Show.vue` (mount `ApprovalPanel` in the `approvals` tab)

---

### Task 1: Migration

**Files:** `database/migrations/2026_09_18_000010_create_approvals_table.php`

- [ ] **Step 1: Write it**

```bash
cd C:\wamp64\projects\incident-report
php artisan make:migration create_approvals_table
```

Rename to `2026_09_18_000010_create_approvals_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->text('request_comments')->nullable();
            $table->string('status');
            $table->timestamp('due_at')->nullable();
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('decision_comments')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('approvals');
    }
};
```

`request_comments`, `due_at`, and `escalated_at` are additions beyond `docs/architecture.md` §2.4's original schema — same deliberate, documented pattern Phases 5 & 6 used for `investigations`/`corrective_actions`. `request_comments` holds the required justification when an incident is closed with no CAPA (Design decision 2); `due_at` is computed once at request time (mirroring how `Investigation.target_completion_at` and `CorrectiveAction.due_date` are each stored rather than recomputed) so the escalation sweep (Task 8) can query it directly; `escalated_at` is the same one-shot flag pattern as every prior phase. `requested_by` is `restrictOnDelete()` (a required actor reference, matching `incidents.reporter_id`'s convention) while `approver_id` is `nullOnDelete()` (nullable until decided, matching `incidents.assigned_investigator_id`'s convention). The decision-comments column is named `decision_comments`, not the bare `comments` architecture.md §2.4 uses — this table already has `request_comments` as a sibling column, and two similarly-named free-text columns on the same row (one written by the requester, one by the approver) would be exactly the kind of ambiguity `corrective_actions` avoids by using `completion_notes` vs. `verification_comments` instead of two columns both called something like "comments". All four `_at` columns (`due_at`, `decided_at`, `escalated_at`, plus the inherited `timestamps()`) use `timestamp()`, matching the convention every Phase 5/6 addition already settled on (`investigations`/`corrective_actions`), not the older `dateTime()` used in the original Phase 1 `incidents` table.

- [ ] **Step 2: Run migrations and verify**

```bash
php artisan migrate
```

Expected: `2026_09_18_000010_create_approvals_table` shows `DONE`, no errors.

- [ ] **Step 3: Commit**

```bash
git add database/migrations
git commit -m "feat: add approvals table"
```

---

### Task 2: ApprovalStatus enum

**Files:** `app/Enums/ApprovalStatus.php`

- [ ] **Step 1: Write it**

```php
<?php

namespace App\Enums;

/**
 * Status of a single closure-approval request. Unlike other lifecycle
 * enums in this app, `Returned` is terminal for its own row, not a dead
 * end for the incident: a returned incident goes back to
 * IncidentStatus::CorrectiveAction, and resubmitting for approval later
 * creates a brand-new `approvals` row rather than resetting this one back
 * to Pending - so an incident's approval history is a list of these rows,
 * not a single mutable record.
 */
enum ApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Returned = 'returned';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending Approval',
            self::Approved => 'Approved',
            self::Returned => 'Returned for Revision',
        };
    }
}
```

- [ ] **Step 2: Verify**

```bash
cd C:\wamp64\projects\incident-report
php artisan tinker --execute="echo App\Enums\ApprovalStatus::Pending->label();"
```

Expected: `Pending Approval`.

- [ ] **Step 3: Commit**

```bash
git add app/Enums/ApprovalStatus.php
git commit -m "feat: add ApprovalStatus enum"
```

---

### Task 3: Approval model

**Files:**
- Create: `app/Models/Approval.php`
- Modify: `app/Models/Incident.php`

- [ ] **Step 1: Write `Approval`**

```php
<?php

namespace App\Models;

use App\Enums\ApprovalStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Approval extends Model
{
    protected $fillable = [
        'incident_id',
        'requested_by',
        'request_comments',
        'status',
        'due_at',
        'approver_id',
        'decision_comments',
        'decided_at',
    ];

    protected $casts = [
        'status' => ApprovalStatus::class,
        'due_at' => 'datetime',
        'decided_at' => 'datetime',
        'escalated_at' => 'datetime',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function isOverdue(): bool
    {
        return $this->status === ApprovalStatus::Pending
            && $this->due_at !== null
            && $this->due_at->isPast();
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('status', ApprovalStatus::Pending->value)
            ->whereNotNull('due_at')
            ->where('due_at', '<', now());
    }
}
```

- [ ] **Step 2: Add the `approvals()` relation to `Incident`**

In `app/Models/Incident.php`, add near `correctiveActions()`:

```php
    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class);
    }
```

(`HasMany` is already imported in this file.)

- [ ] **Step 3: Verify**

```bash
cd C:\wamp64\projects\incident-report
php artisan tinker --execute="echo App\Models\Approval::class . ' OK';"
```

Expected: no fatal errors.

- [ ] **Step 4: Commit**

```bash
git add app/Models/Approval.php app/Models/Incident.php
git commit -m "feat: add Approval model and Incident::approvals() relation"
```

---

### Task 4: DTOs

**Files:**
- Create: `app/DataTransferObjects/Approvals/MarkNoCorrectiveActionNeededData.php`
- Create: `app/DataTransferObjects/Approvals/DecideApprovalData.php`

- [ ] **Step 1: Write `MarkNoCorrectiveActionNeededData`**

```php
<?php

namespace App\DataTransferObjects\Approvals;

/**
 * `justification` maps to the `approvals.request_comments` column at the
 * Service layer - named for what the requester is actually asked to type
 * on this specific form ("why does this incident need no corrective
 * action?"), not for the generic column it's stored in, which stays null
 * on the ordinary CAPA-verified request path.
 */
final class MarkNoCorrectiveActionNeededData
{
    public function __construct(
        public readonly string $justification,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(justification: $data['justification']);
    }
}
```

- [ ] **Step 2: Write `DecideApprovalData`**

Shared by both `approve()` and `returnForRevision()` on the Service — same shape either way, mirroring how Phase 6's `VerifyCorrectiveActionData` had its own single-field shape reused across a similarly-small decision action.

```php
<?php

namespace App\DataTransferObjects\Approvals;

/**
 * `comments` maps to the `approvals.decision_comments` column at the
 * Service layer, not a same-named one - that column was deliberately
 * renamed away from a bare "comments" so it wouldn't sit ambiguously next
 * to `request_comments` on the same row. This DTO's own scope (deciding
 * one approval) is narrow enough that "comments" alone isn't ambiguous
 * here.
 */
final class DecideApprovalData
{
    public function __construct(
        public readonly string $comments,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(comments: $data['comments']);
    }
}
```

- [ ] **Step 3: Verify**

```bash
cd C:\wamp64\projects\incident-report
php artisan tinker --execute="var_dump(App\DataTransferObjects\Approvals\DecideApprovalData::fromArray(['comments' => 'x'])->comments);"
```

Expected: `string(1) "x"`.

- [ ] **Step 4: Commit**

```bash
git add app/DataTransferObjects/Approvals
git commit -m "feat: add DTOs for approval no-CAPA-justification and decision inputs"
```

---

### Task 5: Repository and Query

**Files:**
- Create: `app/Repositories/ApprovalRepository.php`
- Create: `app/Queries/OverdueApprovalsQuery.php`

- [ ] **Step 1: Write `ApprovalRepository`**

```php
<?php

namespace App\Repositories;

use App\Models\Approval;

class ApprovalRepository
{
    public function create(array $attributes): Approval
    {
        return Approval::create($attributes);
    }

    public function update(Approval $approval, array $attributes): Approval
    {
        $approval->fill($attributes);
        $approval->save();

        return $approval;
    }

    /**
     * escalated_at is deliberately excluded from Approval::$fillable
     * (system-managed, only ever set by the daily escalation sweep).
     */
    public function markEscalated(Approval $approval): void
    {
        $approval->forceFill(['escalated_at' => now()])->save();
    }
}
```

- [ ] **Step 2: Write `OverdueApprovalsQuery`**

```php
<?php

namespace App\Queries;

use App\Models\Approval;
use Illuminate\Database\Eloquent\Collection;

class OverdueApprovalsQuery
{
    public function get(): Collection
    {
        return Approval::overdue()
            ->whereNull('escalated_at')
            ->get();
    }
}
```

- [ ] **Step 3: Verify**

```bash
cd C:\wamp64\projects\incident-report
php artisan tinker --execute="echo App\Repositories\ApprovalRepository::class . ' ' . App\Queries\OverdueApprovalsQuery::class . ' OK';"
```

Expected: no fatal errors.

- [ ] **Step 4: Commit**

```bash
git add app/Repositories/ApprovalRepository.php app/Queries/OverdueApprovalsQuery.php
git commit -m "feat: add ApprovalRepository and OverdueApprovalsQuery"
```

---

### Task 6: ApprovalService

**Files:**
- Create: `app/Services/ApprovalService.php`
- Create: `tests/Feature/Approvals/ApprovalTest.php`

- [ ] **Step 1: Write the failing test file**

```php
<?php

namespace Tests\Feature\Approvals;

use App\DataTransferObjects\Approvals\DecideApprovalData;
use App\DataTransferObjects\Approvals\MarkNoCorrectiveActionNeededData;
use App\DataTransferObjects\CorrectiveActions\CompleteCorrectiveActionData;
use App\DataTransferObjects\CorrectiveActions\CorrectiveActionData;
use App\DataTransferObjects\CorrectiveActions\VerifyCorrectiveActionData;
use App\DataTransferObjects\Investigations\CompleteInvestigationData;
use App\DataTransferObjects\Investigations\FindingData;
use App\DataTransferObjects\Investigations\StartInvestigationData;
use App\Enums\ApprovalStatus;
use App\Enums\IncidentStatus;
use App\Enums\InvestigationMethodology;
use App\Enums\Role;
use App\Enums\Severity;
use App\Models\Department;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\CorrectiveActionService;
use App\Services\IncidentService;
use App\Services\InvestigationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApprovalTest extends TestCase
{
    use RefreshDatabase;

    /**
     * An incident with a completed investigation, sitting at
     * IncidentStatus::CorrectiveAction with zero corrective actions.
     */
    private function incidentThroughInvestigation(): Incident
    {
        $department = Department::factory()->create();
        $incidentType = IncidentType::factory()->create();
        $reporter = User::factory()->create();
        $supervisor = User::factory()->create(['role' => Role::Supervisor, 'department_id' => $department->id]);
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
        app(IncidentService::class)->markReviewed($incident->fresh(), $supervisor, null);
        app(IncidentService::class)->assignInvestigator($incident->fresh(), $investigator);
        $investigation = app(InvestigationService::class)->start($incident->fresh(), $investigator, StartInvestigationData::fromArray([
            'objective' => 'Determine root cause.', 'methodology' => InvestigationMethodology::FiveWhys->value,
        ]));
        app(InvestigationService::class)->addFinding($investigation, FindingData::fromArray([
            'question' => 'Why?', 'finding' => 'Root cause.', 'is_root_cause' => true,
        ]));
        app(InvestigationService::class)->complete($investigation->fresh(), CompleteInvestigationData::fromArray(['conclusion' => 'Done.']));

        return $incident->fresh();
    }

    /** An incident with one verified CAPA, sitting at IncidentStatus::Verified. */
    private function incidentReadyForApproval(): Incident
    {
        $incident = $this->incidentThroughInvestigation();

        $capa = app(CorrectiveActionService::class)->create($incident, CorrectiveActionData::fromArray([
            'description' => 'Retrain staff.', 'action_type' => 'corrective', 'priority' => 'high',
            'due_date' => now()->addDays(14)->toDateString(),
        ]));
        $this->actingAs(User::factory()->create());
        app(CorrectiveActionService::class)->complete($capa, CompleteCorrectiveActionData::fromArray(['completion_notes' => 'Done.']));
        $verifier = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        app(CorrectiveActionService::class)->verify($capa->fresh(), $verifier, VerifyCorrectiveActionData::fromArray(['verification_comments' => 'Confirmed.']));

        return $incident->fresh();
    }

    public function test_requesting_approval_creates_a_pending_approval_and_advances_the_incident(): void
    {
        $incident = $this->incidentReadyForApproval();
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);

        $approval = app(ApprovalService::class)->requestApproval($incident, $qso);

        $this->assertSame(ApprovalStatus::Pending, $approval->status);
        $this->assertSame($qso->id, $approval->requested_by);
        $this->assertNull($approval->request_comments);
        $this->assertNotNull($approval->due_at);
        $this->assertSame(IncidentStatus::ForApproval, $incident->fresh()->status);
    }

    public function test_marking_no_corrective_action_needed_creates_a_pending_approval_with_the_justification(): void
    {
        $incident = $this->incidentThroughInvestigation();
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);

        $approval = app(ApprovalService::class)->markNoCorrectiveActionNeeded(
            $incident,
            $qso,
            MarkNoCorrectiveActionNeededData::fromArray(['justification' => 'Near-miss only; no system change required.'])
        );

        $this->assertSame(ApprovalStatus::Pending, $approval->status);
        $this->assertSame('Near-miss only; no system change required.', $approval->request_comments);
        $this->assertSame(IncidentStatus::ForApproval, $incident->fresh()->status);
    }

    public function test_requesting_approval_writes_an_audit_log_entry_describing_why(): void
    {
        $incident = $this->incidentReadyForApproval();
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);

        app(ApprovalService::class)->requestApproval($incident, $qso);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Incident::class,
            'auditable_id' => $incident->id,
            'action' => 'status_changed',
            'description' => 'Corrective action(s) verified; requesting closure approval.',
        ]);
    }

    public function test_approving_closes_the_incident(): void
    {
        $incident = $this->incidentReadyForApproval();
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $approval = app(ApprovalService::class)->requestApproval($incident, $qso);
        $approver = User::factory()->create(['role' => Role::Management]);

        $decided = app(ApprovalService::class)->approve($approval, $approver, DecideApprovalData::fromArray(['comments' => 'All good.']));

        $this->assertSame(ApprovalStatus::Approved, $decided->status);
        $this->assertSame($approver->id, $decided->approver_id);
        $this->assertSame('All good.', $decided->decision_comments);
        $this->assertNotNull($decided->decided_at);
        $this->assertSame(IncidentStatus::Closed, $incident->fresh()->status);
        $this->assertSame($approver->id, $incident->fresh()->closed_by);
        $this->assertNotNull($incident->fresh()->closed_at);
    }

    public function test_returning_for_revision_sends_the_incident_back_to_corrective_action(): void
    {
        $incident = $this->incidentReadyForApproval();
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $approval = app(ApprovalService::class)->requestApproval($incident, $qso);
        $approver = User::factory()->create(['role' => Role::Management]);

        $decided = app(ApprovalService::class)->returnForRevision($approval, $approver, DecideApprovalData::fromArray(['comments' => 'Needs a stronger fix.']));

        $this->assertSame(ApprovalStatus::Returned, $decided->status);
        $this->assertSame($approver->id, $decided->approver_id);
        $this->assertSame(IncidentStatus::CorrectiveAction, $incident->fresh()->status);
    }

    public function test_a_returned_incident_can_be_resubmitted_for_approval_producing_a_second_approval_record(): void
    {
        $incident = $this->incidentReadyForApproval();
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $first = app(ApprovalService::class)->requestApproval($incident, $qso);
        $approver = User::factory()->create(['role' => Role::Management]);
        app(ApprovalService::class)->returnForRevision($first, $approver, DecideApprovalData::fromArray(['comments' => 'Not enough.']));

        // A new CAPA is added/verified in response, advancing back to Verified again.
        $capa = app(CorrectiveActionService::class)->create($incident->fresh(), CorrectiveActionData::fromArray([
            'description' => 'Additional fix.', 'action_type' => 'corrective', 'priority' => 'high',
            'due_date' => now()->addDays(7)->toDateString(),
        ]));
        $this->actingAs(User::factory()->create());
        app(CorrectiveActionService::class)->complete($capa, CompleteCorrectiveActionData::fromArray(['completion_notes' => 'Done.']));
        app(CorrectiveActionService::class)->verify($capa->fresh(), User::factory()->create(['role' => Role::QualitySafetyOfficer]), VerifyCorrectiveActionData::fromArray(['verification_comments' => 'Confirmed.']));

        $second = app(ApprovalService::class)->requestApproval($incident->fresh(), $qso);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, $incident->fresh()->approvals()->count());
        $this->assertSame(IncidentStatus::ForApproval, $incident->fresh()->status);
    }
}
```

- [ ] **Step 2: Run to see it fail**

```bash
cd C:\wamp64\projects\incident-report
php artisan test --filter=ApprovalTest
```

Expected: FAIL — `App\Services\ApprovalService` doesn't exist yet.

- [ ] **Step 3: Write `ApprovalService`**

```php
<?php

namespace App\Services;

use App\DataTransferObjects\Approvals\DecideApprovalData;
use App\DataTransferObjects\Approvals\MarkNoCorrectiveActionNeededData;
use App\Enums\ApprovalStatus;
use App\Enums\IncidentStatus;
use App\Models\Approval;
use App\Models\Incident;
use App\Models\User;
use App\Repositories\ApprovalRepository;
use Illuminate\Support\Facades\DB;

class ApprovalService
{
    public function __construct(private ApprovalRepository $approvals)
    {
    }

    public function requestApproval(Incident $incident, User $requester): Approval
    {
        return $this->createPendingApproval($incident, $requester, null);
    }

    public function markNoCorrectiveActionNeeded(Incident $incident, User $requester, MarkNoCorrectiveActionNeededData $data): Approval
    {
        return $this->createPendingApproval($incident, $requester, $data->justification);
    }

    public function approve(Approval $approval, User $approver, DecideApprovalData $data): Approval
    {
        return DB::transaction(function () use ($approval, $approver, $data) {
            $approval = $this->approvals->update($approval, [
                'status' => ApprovalStatus::Approved,
                'approver_id' => $approver->id,
                'decision_comments' => $data->comments,
                'decided_at' => now(),
            ]);

            $incident = $approval->incident;
            $incident->auditComment = "Approved for closure: {$data->comments}";
            $incident->status = IncidentStatus::Closed;
            $incident->closed_by = $approver->id;
            $incident->closed_at = now();
            $incident->save();

            return $approval;
        });
    }

    public function returnForRevision(Approval $approval, User $approver, DecideApprovalData $data): Approval
    {
        return DB::transaction(function () use ($approval, $approver, $data) {
            $approval = $this->approvals->update($approval, [
                'status' => ApprovalStatus::Returned,
                'approver_id' => $approver->id,
                'decision_comments' => $data->comments,
                'decided_at' => now(),
            ]);

            $incident = $approval->incident;
            $incident->auditComment = "Returned for revision: {$data->comments}";
            $incident->status = IncidentStatus::CorrectiveAction;
            $incident->save();

            return $approval;
        });
    }

    /**
     * Both public "request" methods funnel here — the DB operations are
     * identical either way (create a pending Approval row, move the
     * incident to ForApproval); only the human-readable audit description
     * differs. Which source status is actually allowed (Verified vs
     * CorrectiveAction-with-zero-CAPAs) is IncidentPolicy's job, not this
     * Service's — the same division of responsibility every prior phase's
     * Service/Policy pair already uses.
     */
    private function createPendingApproval(Incident $incident, User $requester, ?string $justification): Approval
    {
        return DB::transaction(function () use ($incident, $requester, $justification) {
            $approval = $this->approvals->create([
                'incident_id' => $incident->id,
                'requested_by' => $requester->id,
                'request_comments' => $justification,
                'status' => ApprovalStatus::Pending,
                'due_at' => now()->addHours(
                    config('incident_workflow.approval_sla_hours.' . $incident->severity->value, 72)
                ),
            ]);

            $incident->auditComment = $justification
                ? "No corrective action required: {$justification}"
                : 'Corrective action(s) verified; requesting closure approval.';
            $incident->status = IncidentStatus::ForApproval;
            $incident->save();

            return $approval;
        });
    }
}
```

`config/incident_workflow.php` does not have `approval_sla_hours` yet — that's added in Task 8 alongside the escalation sweep that reads it. The `?? 72` default keeps this task's tests passing (via the config array's absence returning `null`, falling through to the default) even before Task 8 adds the real per-severity values; Task 8's test will assert the real values are actually read.

- [ ] **Step 4: Run tests to see them pass**

```bash
php artisan test --filter=ApprovalTest
```

Expected: `6 passed`.

- [ ] **Step 5: Run the full suite**

```bash
php artisan test
```

Expected: all green (Phases 3-6 suite plus these 7).

- [ ] **Step 6: Commit**

```bash
git add app/Services/ApprovalService.php tests/Feature/Approvals/ApprovalTest.php
git commit -m "feat: add ApprovalService (request/no-CAPA-needed/approve/return)"
```

---

### Task 7: IncidentPolicy additions

**Files:**
- Modify: `app/Policies/IncidentPolicy.php`
- Modify: `tests/Feature/Approvals/ApprovalTest.php`

- [ ] **Step 1: Add failing tests**

Append to the test class:

```php
    public function test_qso_can_request_approval_once_the_incident_is_verified(): void
    {
        $incident = $this->incidentReadyForApproval();
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);

        $this->assertTrue($qso->can('requestApproval', $incident));
    }

    public function test_an_investigator_cannot_request_approval(): void
    {
        $incident = $this->incidentReadyForApproval();
        $investigator = User::factory()->create(['role' => Role::Investigator]);

        $this->assertFalse($investigator->can('requestApproval', $incident));
    }

    public function test_nobody_can_request_approval_before_the_incident_is_verified(): void
    {
        $incident = $this->incidentThroughInvestigation();
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);

        $this->assertFalse($qso->can('requestApproval', $incident->fresh()));
    }

    public function test_qso_can_mark_no_corrective_action_needed_when_zero_capas_exist(): void
    {
        $incident = $this->incidentThroughInvestigation();
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);

        $this->assertTrue($qso->can('markNoCorrectiveActionNeeded', $incident));
    }

    public function test_marking_no_corrective_action_needed_is_rejected_once_a_corrective_action_exists(): void
    {
        $incident = $this->incidentThroughInvestigation();
        app(CorrectiveActionService::class)->create($incident, CorrectiveActionData::fromArray([
            'description' => 'x', 'action_type' => 'corrective', 'priority' => 'high',
            'due_date' => now()->addDays(7)->toDateString(),
        ]));
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);

        $this->assertFalse($qso->can('markNoCorrectiveActionNeeded', $incident->fresh()));
    }

    public function test_management_and_administrator_can_approve_closure_hospital_wide(): void
    {
        $incident = $this->incidentReadyForApproval();
        $approval = app(ApprovalService::class)->requestApproval($incident, User::factory()->create(['role' => Role::QualitySafetyOfficer]));
        $management = User::factory()->create(['role' => Role::Management]);
        $admin = User::factory()->create(['role' => Role::Administrator]);

        $this->assertTrue($management->can('approveClosure', [$incident->fresh(), $approval]));
        $this->assertTrue($admin->can('approveClosure', [$incident->fresh(), $approval]));
    }

    public function test_a_department_head_can_only_approve_closure_for_their_own_department(): void
    {
        $incident = $this->incidentReadyForApproval();
        $approval = app(ApprovalService::class)->requestApproval($incident, User::factory()->create(['role' => Role::QualitySafetyOfficer]));
        $sameDeptHead = User::factory()->create(['role' => Role::DepartmentHead, 'department_id' => $incident->department_id]);
        $otherDeptHead = User::factory()->create(['role' => Role::DepartmentHead, 'department_id' => null]);

        $this->assertTrue($sameDeptHead->can('approveClosure', [$incident->fresh(), $approval]));
        $this->assertFalse($otherDeptHead->can('approveClosure', [$incident->fresh(), $approval]));
    }

    public function test_a_supervisor_cannot_approve_closure(): void
    {
        $incident = $this->incidentReadyForApproval();
        $approval = app(ApprovalService::class)->requestApproval($incident, User::factory()->create(['role' => Role::QualitySafetyOfficer]));
        $supervisor = User::factory()->create(['role' => Role::Supervisor, 'department_id' => $incident->department_id]);

        $this->assertFalse($supervisor->can('approveClosure', [$incident->fresh(), $approval]));
    }

    public function test_the_requester_cannot_approve_or_return_their_own_request_even_as_administrator(): void
    {
        $incident = $this->incidentReadyForApproval();
        $admin = User::factory()->create(['role' => Role::Administrator]);
        $approval = app(ApprovalService::class)->requestApproval($incident, $admin);
        $otherAdmin = User::factory()->create(['role' => Role::Administrator]);

        $this->assertFalse($admin->can('approveClosure', [$incident->fresh(), $approval]));
        $this->assertFalse($admin->can('returnFromApproval', [$incident->fresh(), $approval]));
        $this->assertTrue($otherAdmin->can('approveClosure', [$incident->fresh(), $approval]));
        $this->assertTrue($otherAdmin->can('returnFromApproval', [$incident->fresh(), $approval]));
    }

    public function test_an_already_decided_approval_can_no_longer_be_approved_or_returned_even_if_the_incident_is_for_approval_again(): void
    {
        $incident = $this->incidentReadyForApproval();
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $first = app(ApprovalService::class)->requestApproval($incident, $qso);
        $approver = User::factory()->create(['role' => Role::Management]);
        app(ApprovalService::class)->returnForRevision($first, $approver, DecideApprovalData::fromArray(['comments' => 'Not enough.']));

        $capa = app(CorrectiveActionService::class)->create($incident->fresh(), CorrectiveActionData::fromArray([
            'description' => 'Additional fix.', 'action_type' => 'corrective', 'priority' => 'high',
            'due_date' => now()->addDays(7)->toDateString(),
        ]));
        $this->actingAs(User::factory()->create());
        app(CorrectiveActionService::class)->complete($capa, CompleteCorrectiveActionData::fromArray(['completion_notes' => 'Done.']));
        app(CorrectiveActionService::class)->verify($capa->fresh(), User::factory()->create(['role' => Role::QualitySafetyOfficer]), VerifyCorrectiveActionData::fromArray(['verification_comments' => 'Confirmed.']));
        $second = app(ApprovalService::class)->requestApproval($incident->fresh(), $qso);

        // The incident is ForApproval again, but $first is the old, already-Returned
        // row from the earlier cycle - it must stay permanently undecidable, even
        // though the incident's *current* status would otherwise allow a decision.
        $this->assertFalse($approver->can('approveClosure', [$incident->fresh(), $first]));
        $this->assertFalse($approver->can('returnFromApproval', [$incident->fresh(), $first]));
        $this->assertTrue($approver->can('approveClosure', [$incident->fresh(), $second]));
    }
```

- [ ] **Step 2: Run to confirm they fail**

```bash
php artisan test --filter=ApprovalTest
```

Expected: FAIL — the new abilities don't exist on `IncidentPolicy` yet, so every `can()` call returns `false`, including the ones expected to be `true`.

- [ ] **Step 3: Add the four abilities to `IncidentPolicy`**

In `app/Policies/IncidentPolicy.php`, add the imports and the four public methods plus two private helpers (near the existing `hasReviewOrAssignAccess` helper):

```php
use App\Enums\ApprovalStatus;
use App\Models\Approval;
```

`approveClosure`/`returnFromApproval` take the specific `Approval` row as a third argument, not just the incident — called as `$user->can('approveClosure', [$incident, $approval])`, which Laravel resolves to `IncidentPolicy::approveClosure($user, $incident, $approval)` (the policy class is picked from the array's first element, the same "extra context" idiom `CorrectiveActionPolicy::create()` already uses via `[CorrectiveAction::class, $incident]`). This matters: an incident can cycle `Verified → ForApproval → (returned) → CorrectiveAction → Verified → ForApproval` again, producing a *second* `Approval` row while the first, already-`Returned` row still exists. If these abilities only checked `$incident->status === ForApproval`, the old row would incorrectly stay "decidable" forever, since the incident's status alone can't tell two `Approval` rows apart — only checking the specific row's own `status` can.

```php
    public function requestApproval(User $user, Incident $incident): bool
    {
        if ($incident->status !== IncidentStatus::Verified) {
            return false;
        }

        return $this->isQualityStaff($user);
    }

    public function markNoCorrectiveActionNeeded(User $user, Incident $incident): bool
    {
        if ($incident->status !== IncidentStatus::CorrectiveAction) {
            return false;
        }

        if ($incident->correctiveActions()->exists()) {
            return false;
        }

        return $this->isQualityStaff($user);
    }

    public function approveClosure(User $user, Incident $incident, Approval $approval): bool
    {
        if ($incident->status !== IncidentStatus::ForApproval) {
            return false;
        }

        if ($approval->status !== ApprovalStatus::Pending) {
            return false;
        }

        return $this->hasApprovalAuthority($user, $incident, $approval);
    }

    public function returnFromApproval(User $user, Incident $incident, Approval $approval): bool
    {
        if ($incident->status !== IncidentStatus::ForApproval) {
            return false;
        }

        if ($approval->status !== ApprovalStatus::Pending) {
            return false;
        }

        return $this->hasApprovalAuthority($user, $incident, $approval);
    }

    private function isQualityStaff(User $user): bool
    {
        return in_array($user->role, [Role::QualitySafetyOfficer, Role::Administrator], true);
    }

    /**
     * Management/Administrator approve hospital-wide; a DepartmentHead is
     * scoped to their own department, same convention as
     * hasReviewOrAssignAccess() above. Either way, the specific user who
     * requested *this* Approval row is excluded, even if their role would
     * otherwise qualify - mirrors CorrectiveActionPolicy::verify()'s
     * never-self-verification check, checked by user id, not just role,
     * for the same reason (an Administrator can both request and
     * ordinarily approve, so role alone isn't a strong enough guard).
     * Checking $approval->requested_by directly (rather than re-querying
     * "the" pending approval on the incident) is both correct and cheap -
     * $approval is already the exact row callers are deciding on.
     */
    private function hasApprovalAuthority(User $user, Incident $incident, Approval $approval): bool
    {
        if (in_array($user->role, [Role::Management, Role::Administrator], true)) {
            return $approval->requested_by !== $user->id;
        }

        if ($user->role === Role::DepartmentHead) {
            if ($incident->department_id === null || $incident->department_id !== $user->department_id) {
                return false;
            }

            return $approval->requested_by !== $user->id;
        }

        return false;
    }
```

- [ ] **Step 4: Run tests, then the full suite**

```bash
php artisan test --filter=ApprovalTest
```

Expected: `16 passed`.

```bash
php artisan test
```

Expected: all green.

- [ ] **Step 5: Commit**

```bash
git add app/Policies/IncidentPolicy.php tests/Feature/Approvals/ApprovalTest.php
git commit -m "feat: add requestApproval/markNoCorrectiveActionNeeded/approveClosure/returnFromApproval to IncidentPolicy"
```

---

### Task 8: Escalation sweep

**Files:**
- Modify: `config/incident_workflow.php`
- Modify: `app/Console/Commands/CheckOverdueIncidents.php`
- Create: `tests/Feature/Approvals/ApprovalEscalationTest.php`

- [ ] **Step 1: Add `approval_sla_hours` to the config**

In `config/incident_workflow.php`, add after `investigation_sla_hours`:

```php
    /*
    |--------------------------------------------------------------------------
    | Closure approval SLA (hours from request to decision, per severity)
    |--------------------------------------------------------------------------
    */
    'approval_sla_hours' => [
        'level_1_low' => 120,
        'level_2_moderate' => 72,
        'level_3_high' => 48,
        'level_4_critical_sentinel' => 24,
    ],
```

- [ ] **Step 2: Write the failing test file**

```php
<?php

namespace Tests\Feature\Approvals;

use App\DataTransferObjects\Investigations\CompleteInvestigationData;
use App\DataTransferObjects\Investigations\FindingData;
use App\DataTransferObjects\Investigations\StartInvestigationData;
use App\Enums\InvestigationMethodology;
use App\Enums\Role;
use App\Enums\Severity;
use App\Models\Department;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\User;
use App\Notifications\IncidentEscalationNotification;
use App\Services\ApprovalService;
use App\Services\IncidentService;
use App\Services\InvestigationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ApprovalEscalationTest extends TestCase
{
    use RefreshDatabase;

    private function incidentThroughInvestigation(): Incident
    {
        $department = Department::factory()->create();
        $incidentType = IncidentType::factory()->create();
        $reporter = User::factory()->create();
        $supervisor = User::factory()->create(['role' => Role::Supervisor, 'department_id' => $department->id]);
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
        app(IncidentService::class)->markReviewed($incident->fresh(), $supervisor, null);
        app(IncidentService::class)->assignInvestigator($incident->fresh(), $investigator);
        $investigation = app(InvestigationService::class)->start($incident->fresh(), $investigator, StartInvestigationData::fromArray([
            'objective' => 'x', 'methodology' => InvestigationMethodology::FiveWhys->value,
        ]));
        app(InvestigationService::class)->addFinding($investigation, FindingData::fromArray(['question' => 'Q', 'finding' => 'F', 'is_root_cause' => true]));
        app(InvestigationService::class)->complete($investigation->fresh(), CompleteInvestigationData::fromArray(['conclusion' => 'Done.']));

        return $incident->fresh();
    }

    public function test_an_overdue_pending_approval_is_escalated_once(): void
    {
        Notification::fake();
        $incident = $this->incidentThroughInvestigation();
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $approval = app(ApprovalService::class)->markNoCorrectiveActionNeeded(
            $incident,
            $qso,
            \App\DataTransferObjects\Approvals\MarkNoCorrectiveActionNeededData::fromArray(['justification' => 'Near miss.'])
        );
        $approval->forceFill(['due_at' => now()->subDay()])->save();

        Artisan::call('incidents:check-overdue');

        Notification::assertSentTo($qso, IncidentEscalationNotification::class);
        $this->assertNotNull($approval->fresh()->escalated_at);

        Notification::fake();
        Artisan::call('incidents:check-overdue');
        Notification::assertNothingSent();
    }

    public function test_requesting_approval_computes_due_at_from_the_configured_severity_sla(): void
    {
        $incident = $this->incidentThroughInvestigation();
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);

        $before = now();
        $approval = app(ApprovalService::class)->markNoCorrectiveActionNeeded(
            $incident,
            $qso,
            \App\DataTransferObjects\Approvals\MarkNoCorrectiveActionNeededData::fromArray(['justification' => 'x'])
        );

        // Severity::Level2Moderate => 72 hours per config/incident_workflow.php.
        $this->assertTrue($approval->due_at->diffInHours($before->copy()->addHours(72)) < 1);
    }
}
```

- [ ] **Step 3: Run to confirm they fail**

```bash
cd C:\wamp64\projects\incident-report
php artisan test --filter=ApprovalEscalationTest
```

Expected: FAIL — the new sweep doesn't exist yet.

- [ ] **Step 4: Add `escalateOverdueApprovals()` to `CheckOverdueIncidents`**

Read the current file first — it already has a constructor injecting `OverdueInvestigationsQuery`/`InvestigationRepository`/`OverdueCorrectiveActionsQuery`/`CorrectiveActionRepository`. Add two more constructor-promoted dependencies alongside them:

```php
    public function __construct(
        private OverdueInvestigationsQuery $overdueInvestigations,
        private InvestigationRepository $investigations,
        private OverdueCorrectiveActionsQuery $overdueCorrectiveActions,
        private CorrectiveActionRepository $correctiveActions,
        private OverdueApprovalsQuery $overdueApprovals,
        private ApprovalRepository $approvals,
    ) {
        parent::__construct();
    }
```

Add the imports:

```php
use App\Models\Approval;
use App\Queries\OverdueApprovalsQuery;
use App\Repositories\ApprovalRepository;
```

Add the call in `handle()`, after `escalateOverdueCorrectiveActions($recipients);`:

```php
        $this->escalateOverdueApprovals($recipients);
```

Add the new private method:

```php
    private function escalateOverdueApprovals(Collection $recipients): void
    {
        $this->overdueApprovals->get()->each(function (Approval $approval) use ($recipients) {
            Notification::send(
                $recipients,
                new IncidentEscalationNotification($approval->incident, 'Closure approval SLA breached')
            );
            $this->approvals->markEscalated($approval);
        });
    }
```

- [ ] **Step 5: Run tests, then the full suite**

```bash
php artisan test --filter=ApprovalEscalationTest
```

Expected: `2 passed`.

```bash
php artisan test
```

Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add config/incident_workflow.php app/Console/Commands/CheckOverdueIncidents.php tests/Feature/Approvals/ApprovalEscalationTest.php
git commit -m "feat: escalate closure approvals that breach their due date"
```

---

### Task 9: Form Requests, Actions, Controller, routes

**Files:**
- Create: `app/Http/Requests/Approvals/MarkNoCorrectiveActionNeededRequest.php`
- Create: `app/Http/Requests/Approvals/ApproveClosureRequest.php`
- Create: `app/Http/Requests/Approvals/ReturnFromApprovalRequest.php`
- Create: `app/Actions/Approvals/RequestApprovalAction.php`
- Create: `app/Actions/Approvals/MarkNoCorrectiveActionNeededAction.php`
- Create: `app/Actions/Approvals/ApproveClosureAction.php`
- Create: `app/Actions/Approvals/ReturnFromApprovalAction.php`
- Create: `app/Http/Controllers/ApprovalController.php`
- Modify: `routes/web.php`
- Modify: `tests/Feature/Approvals/ApprovalTest.php`

- [ ] **Step 1: Add failing HTTP-level tests**

Append to `ApprovalTest`:

```php
    public function test_qso_can_request_approval_via_http(): void
    {
        $incident = $this->incidentReadyForApproval();
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);

        $this->actingAs($qso)
            ->post("/incidents/{$incident->id}/request-approval")
            ->assertRedirect();

        $this->assertDatabaseHas('approvals', ['incident_id' => $incident->id, 'requested_by' => $qso->id]);
        $this->assertSame(IncidentStatus::ForApproval, $incident->fresh()->status);
    }

    public function test_an_investigator_cannot_request_approval_via_http(): void
    {
        $incident = $this->incidentReadyForApproval();
        $investigator = User::factory()->create(['role' => Role::Investigator]);

        $this->actingAs($investigator)
            ->post("/incidents/{$incident->id}/request-approval")
            ->assertForbidden();
    }

    public function test_marking_no_corrective_action_needed_via_http_requires_a_justification(): void
    {
        $incident = $this->incidentThroughInvestigation();
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);

        $this->actingAs($qso)
            ->post("/incidents/{$incident->id}/no-corrective-action-needed", [])
            ->assertSessionHasErrors(['justification']);
    }

    public function test_marking_no_corrective_action_needed_via_http(): void
    {
        $incident = $this->incidentThroughInvestigation();
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);

        $this->actingAs($qso)
            ->post("/incidents/{$incident->id}/no-corrective-action-needed", ['justification' => 'Near miss, no fix needed.'])
            ->assertRedirect();

        $this->assertSame(IncidentStatus::ForApproval, $incident->fresh()->status);
    }

    public function test_approving_via_http_requires_comments(): void
    {
        $incident = $this->incidentReadyForApproval();
        $approval = app(ApprovalService::class)->requestApproval($incident, User::factory()->create(['role' => Role::QualitySafetyOfficer]));
        $management = User::factory()->create(['role' => Role::Management]);

        $this->actingAs($management)
            ->post("/approvals/{$approval->id}/approve", [])
            ->assertSessionHasErrors(['comments']);
    }

    public function test_approving_via_http(): void
    {
        $incident = $this->incidentReadyForApproval();
        $approval = app(ApprovalService::class)->requestApproval($incident, User::factory()->create(['role' => Role::QualitySafetyOfficer]));
        $management = User::factory()->create(['role' => Role::Management]);

        $this->actingAs($management)
            ->post("/approvals/{$approval->id}/approve", ['comments' => 'Confirmed effective.'])
            ->assertRedirect();

        $this->assertSame(IncidentStatus::Closed, $incident->fresh()->status);
    }

    public function test_the_requester_cannot_approve_their_own_request_via_http(): void
    {
        $incident = $this->incidentReadyForApproval();
        $admin = User::factory()->create(['role' => Role::Administrator]);
        $approval = app(ApprovalService::class)->requestApproval($incident, $admin);

        $this->actingAs($admin)
            ->post("/approvals/{$approval->id}/approve", ['comments' => 'x'])
            ->assertForbidden();
    }

    public function test_returning_via_http(): void
    {
        $incident = $this->incidentReadyForApproval();
        $approval = app(ApprovalService::class)->requestApproval($incident, User::factory()->create(['role' => Role::QualitySafetyOfficer]));
        $management = User::factory()->create(['role' => Role::Management]);

        $this->actingAs($management)
            ->post("/approvals/{$approval->id}/return", ['comments' => 'Needs more work.'])
            ->assertRedirect();

        $this->assertSame(IncidentStatus::CorrectiveAction, $incident->fresh()->status);
    }
```

- [ ] **Step 2: Run to confirm they fail**

```bash
php artisan test --filter=ApprovalTest
```

Expected: FAIL — routes don't exist yet (404s).

- [ ] **Step 3: Write the 3 Form Requests**

`app/Http/Requests/Approvals/MarkNoCorrectiveActionNeededRequest.php`:
```php
<?php

namespace App\Http\Requests\Approvals;

use App\DataTransferObjects\Approvals\MarkNoCorrectiveActionNeededData;
use Illuminate\Foundation\Http\FormRequest;

class MarkNoCorrectiveActionNeededRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('markNoCorrectiveActionNeeded', $this->route('incident'));
    }

    public function rules(): array
    {
        return [
            'justification' => ['required', 'string'],
        ];
    }

    public function toDto(): MarkNoCorrectiveActionNeededData
    {
        return MarkNoCorrectiveActionNeededData::fromArray($this->validated());
    }
}
```

`app/Http/Requests/Approvals/ApproveClosureRequest.php`:
```php
<?php

namespace App\Http\Requests\Approvals;

use App\DataTransferObjects\Approvals\DecideApprovalData;
use Illuminate\Foundation\Http\FormRequest;

class ApproveClosureRequest extends FormRequest
{
    public function authorize(): bool
    {
        $approval = $this->route('approval');

        return $this->user()->can('approveClosure', [$approval->incident, $approval]);
    }

    public function rules(): array
    {
        return [
            'comments' => ['required', 'string'],
        ];
    }

    public function toDto(): DecideApprovalData
    {
        return DecideApprovalData::fromArray($this->validated());
    }
}
```

`app/Http/Requests/Approvals/ReturnFromApprovalRequest.php`:
```php
<?php

namespace App\Http\Requests\Approvals;

use App\DataTransferObjects\Approvals\DecideApprovalData;
use Illuminate\Foundation\Http\FormRequest;

class ReturnFromApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        $approval = $this->route('approval');

        return $this->user()->can('returnFromApproval', [$approval->incident, $approval]);
    }

    public function rules(): array
    {
        return [
            'comments' => ['required', 'string'],
        ];
    }

    public function toDto(): DecideApprovalData
    {
        return DecideApprovalData::fromArray($this->validated());
    }
}
```

There is no dedicated Form Request for `requestApproval` — it has no body to validate, same pattern Phase 6 used for `progress()` (see `CorrectiveActionController::progress()`); the controller authorizes it directly with a plain `Illuminate\Http\Request`.

- [ ] **Step 4: Write the 4 Actions**

`app/Actions/Approvals/RequestApprovalAction.php`:
```php
<?php

namespace App\Actions\Approvals;

use App\Models\Approval;
use App\Models\Incident;
use App\Models\User;
use App\Services\ApprovalService;

class RequestApprovalAction
{
    public function __construct(private ApprovalService $approvals)
    {
    }

    public function __invoke(Incident $incident, User $requester): Approval
    {
        return $this->approvals->requestApproval($incident, $requester);
    }
}
```

`app/Actions/Approvals/MarkNoCorrectiveActionNeededAction.php`:
```php
<?php

namespace App\Actions\Approvals;

use App\DataTransferObjects\Approvals\MarkNoCorrectiveActionNeededData;
use App\Models\Approval;
use App\Models\Incident;
use App\Models\User;
use App\Services\ApprovalService;

class MarkNoCorrectiveActionNeededAction
{
    public function __construct(private ApprovalService $approvals)
    {
    }

    public function __invoke(Incident $incident, User $requester, MarkNoCorrectiveActionNeededData $data): Approval
    {
        return $this->approvals->markNoCorrectiveActionNeeded($incident, $requester, $data);
    }
}
```

`app/Actions/Approvals/ApproveClosureAction.php`:
```php
<?php

namespace App\Actions\Approvals;

use App\DataTransferObjects\Approvals\DecideApprovalData;
use App\Models\Approval;
use App\Models\User;
use App\Services\ApprovalService;

class ApproveClosureAction
{
    public function __construct(private ApprovalService $approvals)
    {
    }

    public function __invoke(Approval $approval, User $approver, DecideApprovalData $data): Approval
    {
        return $this->approvals->approve($approval, $approver, $data);
    }
}
```

`app/Actions/Approvals/ReturnFromApprovalAction.php`:
```php
<?php

namespace App\Actions\Approvals;

use App\DataTransferObjects\Approvals\DecideApprovalData;
use App\Models\Approval;
use App\Models\User;
use App\Services\ApprovalService;

class ReturnFromApprovalAction
{
    public function __construct(private ApprovalService $approvals)
    {
    }

    public function __invoke(Approval $approval, User $approver, DecideApprovalData $data): Approval
    {
        return $this->approvals->returnForRevision($approval, $approver, $data);
    }
}
```

- [ ] **Step 5: Write `ApprovalController`**

```php
<?php

namespace App\Http\Controllers;

use App\Actions\Approvals\ApproveClosureAction;
use App\Actions\Approvals\MarkNoCorrectiveActionNeededAction;
use App\Actions\Approvals\RequestApprovalAction;
use App\Actions\Approvals\ReturnFromApprovalAction;
use App\Http\Requests\Approvals\ApproveClosureRequest;
use App\Http\Requests\Approvals\MarkNoCorrectiveActionNeededRequest;
use App\Http\Requests\Approvals\ReturnFromApprovalRequest;
use App\Models\Approval;
use App\Models\Incident;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ApprovalController extends Controller
{
    public function requestApproval(Request $request, Incident $incident, RequestApprovalAction $action): RedirectResponse
    {
        $this->authorize('requestApproval', $incident);

        $action($incident, $request->user());

        return back()->with('success', 'Closure approval requested.');
    }

    public function markNoCorrectiveActionNeeded(MarkNoCorrectiveActionNeededRequest $request, Incident $incident, MarkNoCorrectiveActionNeededAction $action): RedirectResponse
    {
        $action($incident, $request->user(), $request->toDto());

        return back()->with('success', 'Marked as requiring no corrective action; closure approval requested.');
    }

    public function approve(ApproveClosureRequest $request, Approval $approval, ApproveClosureAction $action): RedirectResponse
    {
        $action($approval, $request->user(), $request->toDto());

        return back()->with('success', 'Incident approved and closed.');
    }

    public function returnForRevision(ReturnFromApprovalRequest $request, Approval $approval, ReturnFromApprovalAction $action): RedirectResponse
    {
        $action($approval, $request->user(), $request->toDto());

        return back()->with('success', 'Returned for further corrective action.');
    }
}
```

- [ ] **Step 6: Add routes**

In `routes/web.php`, add the import:

```php
use App\Http\Controllers\ApprovalController;
```

Inside the `auth` middleware group, after the corrective-action routes:

```php
    Route::post('/incidents/{incident}/request-approval', [ApprovalController::class, 'requestApproval'])->name('approvals.request');
    Route::post('/incidents/{incident}/no-corrective-action-needed', [ApprovalController::class, 'markNoCorrectiveActionNeeded'])->name('approvals.no-corrective-action');
    Route::post('/approvals/{approval}/approve', [ApprovalController::class, 'approve'])->name('approvals.approve');
    Route::post('/approvals/{approval}/return', [ApprovalController::class, 'returnForRevision'])->name('approvals.return');
```

- [ ] **Step 7: Run tests**

```bash
php artisan test --filter=ApprovalTest
```

Expected: `24 passed` (16 from Task 7 + 8 new HTTP tests).

```bash
php artisan test
```

Expected: all green.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Requests/Approvals app/Actions/Approvals app/Http/Controllers/ApprovalController.php routes/web.php tests/Feature/Approvals/ApprovalTest.php
git commit -m "feat: add approval request/no-CAPA-needed/approve/return routes"
```

---

### Task 10: Resource and IncidentController@show wiring

**Files:**
- Create: `app/Http/Resources/ApprovalResource.php`
- Modify: `app/Http/Controllers/IncidentController.php`

- [ ] **Step 1: Write `ApprovalResource`**

Like `CorrectiveActionResource`, this needs per-item `can` flags (`approve`/`return`) — the returned/approved history rows must show `false` once decided, and stay `false` forever even after a later resubmission cycle puts the incident back into `ForApproval` via a *different* `Approval` row. The `approveClosure`/`returnFromApproval` abilities live on `IncidentPolicy` but take the specific `Approval` row as well as the incident (`$user->can('approveClosure', [$this->incident, $this->resource])`) precisely so each row's own `status` — not just the incident's current status — gates its own flags. This Resource needs the parent `Incident` loaded on each row to check them.

The JSON key stays `comments` (there's no sibling ambiguity in the payload the frontend already reads distinctly-named `request_comments` and `comments`) even though it reads from the model's `decision_comments` column — that column name exists specifically to avoid ambiguity at the *database* row level, where `request_comments` and a second bare `comments` column would sit side by side.

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ApprovalResource extends JsonResource
{
    public function toArray($request)
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
            ],
            'request_comments' => $this->request_comments,
            'due_at' => $this->due_at,
            'is_overdue' => $this->isOverdue(),
            'requested_by' => $this->whenLoaded('requestedBy', fn () => $this->requestedBy ? [
                'id' => $this->requestedBy->id,
                'name' => $this->requestedBy->name,
            ] : null),
            'approver' => $this->whenLoaded('approver', fn () => $this->approver ? [
                'id' => $this->approver->id,
                'name' => $this->approver->name,
            ] : null),
            'comments' => $this->decision_comments,
            'decided_at' => $this->decided_at,
            'created_at' => $this->created_at,
            'can' => [
                'approve' => $user->can('approveClosure', [$this->incident, $this->resource]),
                'return' => $user->can('returnFromApproval', [$this->incident, $this->resource]),
            ],
        ];
    }
}
```

- [ ] **Step 2: Wire into `IncidentController::show()`**

Add the import:

```php
use App\Http\Resources\ApprovalResource;
```

After the existing `$correctiveActions = ...` block, add:

```php
        $approvals = $incident->approvals()
            ->with(['requestedBy', 'approver', 'incident'])
            ->latest('id')
            ->get();
```

Add to the `Inertia::render` props array:

```php
            'approvals' => ApprovalResource::collection($approvals),
```

Add to the `can` array:

```php
                'requestApproval' => $user->can('requestApproval', $incident),
                'markNoCorrectiveActionNeeded' => $user->can('markNoCorrectiveActionNeeded', $incident),
```

- [ ] **Step 3: Add a regression test**

Append to `tests/Feature/Approvals/ApprovalTest.php`:

```php
    public function test_the_incident_show_page_exposes_resource_shaped_approvals_with_per_item_can_flags(): void
    {
        $incident = $this->incidentReadyForApproval();
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $approval = app(ApprovalService::class)->requestApproval($incident, $qso);
        $management = User::factory()->create(['role' => Role::Management]);

        $this->actingAs($management)
            ->get("/incidents/{$incident->id}?tab=approvals")
            ->assertInertia(fn ($page) => $page
                ->where('approvals.0.id', $approval->id)
                ->where('approvals.0.status.value', 'pending')
                ->where('approvals.0.can.approve', true)
                ->where('approvals.0.can.return', true)
            );

        $this->actingAs($qso)
            ->get("/incidents/{$incident->id}?tab=approvals")
            ->assertInertia(fn ($page) => $page
                ->where('can.requestApproval', false) // already requested; incident is no longer Verified
                ->where('approvals.0.can.approve', false)
            );
    }
```

- [ ] **Step 4: Run tests**

```bash
php artisan test --filter=ApprovalTest
```

Expected: `25 passed`.

```bash
php artisan test
```

Expected: all green.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Resources/ApprovalResource.php app/Http/Controllers/IncidentController.php tests/Feature/Approvals/ApprovalTest.php
git commit -m "feat: expose approvals via a Resource with per-item can flags"
```

---

### Task 11: ApprovalPanel.vue

**Files:**
- Create: `resources/js/Components/Incidents/ApprovalPanel.vue`

- [ ] **Step 1: Write the component**

```vue
<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { formatDate } from '@/Utils/formatDate';

const props = defineProps({
    incident: { type: Object, required: true },
    approvals: { type: Array, default: () => [] },
    can: { type: Object, required: true },
});

const requestForm = useForm({});

function requestApproval() {
    requestForm.post(`/incidents/${props.incident.id}/request-approval`, { preserveScroll: true });
}

const showNoCorrectiveActionForm = ref(false);
const noCorrectiveActionForm = useForm({ justification: '' });

function markNoCorrectiveActionNeeded() {
    noCorrectiveActionForm.post(`/incidents/${props.incident.id}/no-corrective-action-needed`, {
        preserveScroll: true,
        onSuccess: () => {
            noCorrectiveActionForm.reset();
            showNoCorrectiveActionForm.value = false;
        },
    });
}

const decidingId = ref(null);
const decidingMode = ref(null); // 'approve' | 'return'
const decideForm = useForm({ comments: '' });

function startDeciding(approvalId, mode) {
    decidingId.value = approvalId;
    decidingMode.value = mode;
}

function submitDecision(approvalId) {
    const url = decidingMode.value === 'approve'
        ? `/approvals/${approvalId}/approve`
        : `/approvals/${approvalId}/return`;

    decideForm.post(url, {
        preserveScroll: true,
        onSuccess: () => {
            decideForm.reset();
            decidingId.value = null;
            decidingMode.value = null;
        },
    });
}
</script>

<template>
    <div class="flex flex-col gap-space-lg">
        <div class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-space-md">
            <div class="flex flex-wrap items-center justify-between gap-space-sm">
                <h2 class="font-title-lg text-title-lg text-primary font-bold">Closure Approval</h2>
                <div class="flex gap-2">
                    <button
                        v-if="can.requestApproval"
                        type="button"
                        class="px-3 py-1.5 rounded-lg bg-secondary text-on-secondary font-label-md text-label-md font-semibold"
                        @click="requestApproval"
                        :disabled="requestForm.processing"
                    >
                        Request Approval
                    </button>
                    <button
                        v-if="can.markNoCorrectiveActionNeeded"
                        type="button"
                        class="px-3 py-1.5 rounded-lg bg-surface-container text-primary font-label-md text-label-md font-semibold"
                        @click="showNoCorrectiveActionForm = !showNoCorrectiveActionForm"
                    >
                        {{ showNoCorrectiveActionForm ? 'Cancel' : 'No Corrective Action Needed' }}
                    </button>
                </div>
            </div>

            <form v-if="showNoCorrectiveActionForm" class="flex flex-col gap-2 p-space-md rounded-lg bg-surface-container-low" @submit.prevent="markNoCorrectiveActionNeeded">
                <textarea
                    v-model="noCorrectiveActionForm.justification"
                    rows="2"
                    placeholder="Why does this incident need no corrective action?"
                    class="p-2 rounded-lg bg-surface-container"
                />
                <span v-if="noCorrectiveActionForm.errors.justification" class="font-body-sm text-body-sm text-error">{{ noCorrectiveActionForm.errors.justification }}</span>
                <button type="submit" :disabled="noCorrectiveActionForm.processing" class="px-4 py-2 rounded-lg bg-primary text-on-primary font-label-md text-label-md font-semibold disabled:opacity-60 w-fit">
                    Submit for Approval
                </button>
            </form>

            <div v-if="!approvals.length" class="text-center font-body-sm text-body-sm text-outline p-space-md">
                No closure approval has been requested yet.
            </div>

            <div v-for="approval in approvals" :key="approval.id" class="p-space-md rounded-xl bg-surface-container-low flex flex-col gap-3">
                <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                    <div class="flex flex-col gap-1 max-w-xl">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="px-3 py-1 rounded-full bg-secondary-container text-on-secondary-container font-label-sm text-body-sm font-semibold">
                                {{ approval.status.label }}
                            </span>
                            <span v-if="approval.due_at" class="font-code-tabular text-body-sm" :class="approval.is_overdue ? 'text-error font-bold' : 'text-outline'">
                                Due: {{ formatDate(approval.due_at) }}<template v-if="approval.is_overdue"> (Overdue)</template>
                            </span>
                        </div>
                        <p v-if="approval.request_comments" class="font-body-sm text-body-sm text-on-surface-variant">
                            No corrective action needed: {{ approval.request_comments }}
                        </p>
                        <p class="font-body-sm text-body-sm text-outline">
                            Requested by {{ approval.requested_by?.name }} on {{ formatDate(approval.created_at) }}
                        </p>
                        <p v-if="approval.approver" class="font-body-sm text-body-sm text-outline">
                            {{ approval.status.value === 'approved' ? 'Approved' : 'Returned' }} by {{ approval.approver.name }} on {{ formatDate(approval.decided_at) }}: "{{ approval.comments }}"
                        </p>
                    </div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <button v-if="approval.can.approve" type="button" class="px-3 py-1.5 rounded-lg bg-primary text-on-primary font-label-md text-label-md" @click="startDeciding(approval.id, 'approve')">
                            Approve &amp; Close
                        </button>
                        <button v-if="approval.can.return" type="button" class="px-3 py-1.5 rounded-lg bg-surface-container text-primary font-label-md text-label-md" @click="startDeciding(approval.id, 'return')">
                            Return for Revision
                        </button>
                    </div>
                </div>

                <div v-if="decidingId === approval.id" class="flex flex-col gap-2 p-space-sm rounded-lg bg-surface-container">
                    <textarea
                        v-model="decideForm.comments"
                        rows="2"
                        :placeholder="decidingMode === 'approve' ? 'Approval comments' : 'Reason for returning'"
                        class="p-2 rounded-lg bg-surface-container-low"
                    />
                    <span v-if="decideForm.errors.comments" class="font-body-sm text-body-sm text-error">{{ decideForm.errors.comments }}</span>
                    <div class="flex gap-2">
                        <button type="button" class="px-3 py-1.5 rounded-lg bg-primary text-on-primary font-label-sm text-body-sm" @click="submitDecision(approval.id)">Submit</button>
                        <button type="button" class="px-3 py-1.5 rounded-lg bg-surface-container-lowest font-label-sm text-body-sm" @click="decidingId = null; decidingMode = null">Cancel</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
```

No `ConfirmationDialog` here either, for the same reason `CapaPanel.vue` skips it — both "Approve & Close" and "Return for Revision" already require typing comments into an inline panel before a second Submit click confirms.

- [ ] **Step 2: Commit**

```bash
git add resources/js/Components/Incidents/ApprovalPanel.vue
git commit -m "feat: add ApprovalPanel component (closure approval UI)"
```

---

### Task 12: Wire ApprovalPanel into Show.vue

**Files:**
- Modify: `resources/js/Pages/Incidents/Show.vue`

- [ ] **Step 1: Import the component and accept the new prop**

```js
import ApprovalPanel from '@/Components/Incidents/ApprovalPanel.vue';
```

Extend `defineProps` (add alongside the existing CAPA props):

```js
    approvals: { type: Array, default: () => [] },
```

- [ ] **Step 2: Add the `approvals` branch**

Insert a new `v-else-if="activeTab === 'approvals'"` branch immediately before the final catch-all `v-else` (after the `CapaPanel` branch added in Phase 6):

```html
        <ApprovalPanel
            v-else-if="activeTab === 'approvals'"
            :incident="incident"
            :approvals="approvals"
            :can="can"
        />
```

- [ ] **Step 3: Build frontend assets**

```bash
cd C:\wamp64\projects\incident-report
npm run build
```

Expected: no errors.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/Incidents/Show.vue
git commit -m "feat: render the Approvals tab with real closure-approval data"
```

---

### Task 13: Final holistic review and verification

**Files:** none (verification-only task).

- [ ] **Step 1: Run the full backend test suite**

```bash
php artisan test
```

Expected: all green — every prior phase's tests plus all new Phase 7 tests.

- [ ] **Step 2: Holistic cross-task code review**

Read across the full diff for this phase (`git log --oneline <first-Phase-7-commit>..HEAD`), not just each task's own delta. Specifically check:

1. **The requester/approver self-check, keyed to the wrong scope.** An earlier draft of this plan had `approveClosure`/`returnFromApproval` take only the `Incident`, checking `status === ForApproval` and re-querying "the" pending approval on the incident for the self-check. Task 10's code-quality review caught that this was a real authorization gap, not just a style nit: after a `Returned → CorrectiveAction → Verified → ForApproval` resubmission cycle, the incident has a *second* `Approval` row while the first, already-`Returned` row still exists — and since the ability never looked at any specific row's own status, that first row stayed permanently "decidable" by anyone who could decide the second one. **This was fixed within the phase** (both abilities now take the `Approval` row as well as the incident, require `$approval->status === Pending`, and check `$approval->requested_by` directly instead of re-querying) and is covered by `test_an_already_decided_approval_can_no_longer_be_approved_or_returned_even_if_the_incident_is_for_approval_again`. Re-confirm this test still passes and still actually exercises the two-row scenario (not just the happy path) — this is the single riskiest piece of logic in the phase and deserves a second look even though it's already fixed.
2. **`markNoCorrectiveActionNeeded` re-entrancy** — after it's used once and the incident is later *returned* (back to `CorrectiveAction`), can it be used a second time if still zero CAPAs exist? Trace whether `IncidentPolicy::markNoCorrectiveActionNeeded()`'s guard (`status === CorrectiveAction && zero corrective actions`) still holds correctly on a second pass, and that doing so doesn't collide with the first (now-`Returned`) `Approval` row. Add a test if this path isn't already covered.
3. **`due_at` severity lookup** — confirm `config('incident_workflow.approval_sla_hours.' . $incident->severity->value)` is read correctly for all four `Severity` cases, not just the `Level2Moderate` case the existing tests use — add a quick data-provider-style check or additional assertions if only one severity is currently exercised.
4. **Audit trail ordering** — confirm the `status_changed` rows this phase produces (via `IncidentObserver`, same mechanism as every prior phase) interleave correctly in true chronological order with pre-existing investigation/CAPA audit entries (the Phase 4 `->latest()->latest('id')` fix, confirmed action-agnostic in both the Phase 5 and Phase 6 holistic reviews).
5. **Forward-looking notes raised during task reviews, not acted on mid-phase (deliberately deferred to this step):**
   - The `incidentThroughInvestigation()`/`incidentReadyForApproval()` test helpers in `ApprovalTest.php` are now a 4th near-duplicate of the same incident-setup scaffolding also living in `CorrectiveActionTest.php` and `CorrectiveActionEscalationTest.php`. Worth a shared trait/base test case now, before a 5th copy appears in Phase 8.
   - `CheckOverdueIncidents` now has 5 escalation sweeps and 6 constructor-injected dependencies. Still readable, but flagged as the point where a small "escalator" abstraction would pay for itself if a 6th sweep is ever added — don't build it now, just note it.
   - `InvestigationResource`/`CorrectiveActionResource` both carry a comment claiming their `public static $wrap = null;` is what disables Inertia's "data" envelope. It isn't — `AppServiceProvider::boot()` already calls `JsonResource::withoutWrapping()` globally, so those per-class declarations are redundant and their comments are misleading (harmless, since the actual behavior is still correct either way). Worth a documentation fix, not a behavior change.
   - `IncidentController::show()` now assembles props/can-flags for four concerns (Overview, Investigation, CorrectiveAction, Approval) in one method. Still coherent, but flagged as the natural point to consider an `IncidentShowData` builder if a Phase 8+ tab adds a fifth concern — explicitly a "flag, don't act" item per the standing instruction to ask before extending the DTO/Repository/Action layering pattern to a new kind of class.
6. Re-run `php artisan test` and `npm run build` yourself — don't just trust individual task reports.

- [ ] **Step 3: Browser verification**

A headless Chromium (Playwright) was available and used for Phase 6's own final verification — use the same approach here (`php artisan serve` on a scratch port, not the WAMP vhost on port 80, which serves a different landing page for this project). Drive the full flow end-to-end on a fresh incident:
1. As a QSO/Administrator, take an incident through review → assignment → investigation → CAPA → verified (reusing the Phase 5/6 flow), **then** request approval; as Department Head/Management/Administrator (a *different* user than the requester), verify "Approve & Close" moves the incident to `Closed`.
2. Separately, take a second fresh incident through review → assignment → investigation (no CAPA), use "No Corrective Action Needed" with a justification, and verify it reaches the approval queue and then `Closed` the same way.
3. Exercise a "Return for Revision" once, confirming the incident lands back at `Corrective Action` status and a new CAPA can be added and the cycle can be resubmitted for approval.
Confirm zero browser console errors throughout, and screenshot the final `Closed` status badge as evidence.

- [ ] **Step 4: Update `docs/architecture.md`**

Add a `§9h` entry (following the `§9e`/`§9f`/`§9g` pattern) documenting: what shipped, the confirmed design decisions from this plan's header (requester/approver role split with the never-self-decide guard, the no-CAPA-needed gap closure and its intentionally narrow scope, approve-closes-in-one-step, no new domain events), any bugs the holistic review caught, and the browser verification outcome.

- [ ] **Step 5: Update memory**

Per the standing instruction, update `project_state.md` and `MEMORY.md` in `C:\Users\DOH\.claude\projects\c--wamp64-projects-incident-report\memory\` with a "Phase 7 (Approvals & Closure) complete" entry, and update `feedback_architecture.md` to note this is now the third phase area confirming the layered pattern's continued, unprompted-pushback-free use.

- [ ] **Step 6: Final commit**

```bash
git add docs/architecture.md
git commit -m "docs: mark Phase 7 (Approvals & Closure) plan complete, document in architecture.md"
```
