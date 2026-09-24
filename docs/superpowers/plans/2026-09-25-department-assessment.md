# Department Assessment & Role-Based Sidebar Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move severity, immediate actions and recommendations off the reporter's form into a post-submission Department Assessment stage, and show each user only the sidebar menus they can use.

**Architecture:** Reuse the existing `Submitted` status as the assessment stage and the unused `ForReview` status as "ready for review". New methods go on the existing `IncidentService` / `IncidentWorkflowController` / `IncidentPolicy` (this area's established Phase 3–4 style — no DTO/Repository/Action layers; the user asked to keep it simple). Sidebar visibility is computed server-side and shared as `auth.can`.

**Tech Stack:** Laravel 9.52 (PHP 8.2), Inertia v1 server + @inertiajs/vue3 v2, Vue 3, Tailwind v3, Font Awesome, PHPUnit on SQLite in-memory.

**Spec:** `docs/superpowers/specs/2026-09-24-department-assessment-design.md` — read it first.

---

## Ground rules for every task

- Keep it simple: implement exactly what the task says; no extra guards or features.
- Users/departments are live, read-only `tdh_user` (see `docs/architecture.md` §9j). Never write to tdh_user; never add `whereHas`/joins between incident tables and users/section.
- Never run `migrate:fresh`/`reset`/`rollback`/`db:wipe`. Only Task 1 runs one forward `php artisan migrate --force` on `incident_report`.
- Run `php artisan config:clear` before tests. Full suite: `php artisan test` (~100 s, 250 passing at start). Single class: `php artisan test --filter=ClassName`.
- Existing test helpers build incidents by calling `IncidentService` methods directly (`createDraft([... 'severity' => ...])`, `submit`, `markReviewed`, `assignInvestigator`). The service does not check status, so these helpers keep working; only HTTP-level tests that hit changed endpoints need updating.
- Commit after each task, message ending with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## File map

| File | Change |
|---|---|
| `database/migrations/2026_09_25_000001_add_assessment_columns_to_incidents_table.php` | create: `assessed_by`, `assessed_at`, `assessment_escalated_at` |
| `config/incident_workflow.php` | add `assessment_sla_hours` |
| `app/Models/Incident.php` | casts + `assessor()` relation |
| `app/Http/Requests/Concerns/ValidatesIncidentData.php` | drop severity/recommendations/actions/contributing-factor rules |
| `app/Services/IncidentService.php` | `submit()` tweak; `saveAssessment()`, `completeAssessment()`, `returnToDepartment()` |
| `app/Support/IncidentReviewers.php` | create: shared reviewer-recipient query |
| `app/Events/IncidentAssessed.php`, `app/Events/IncidentReturnedToDepartment.php` | create |
| `app/Listeners/NotifyReviewersOfAssessedIncident.php`, `app/Listeners/NotifyDepartmentHeadsOfReturn.php` | create |
| `app/Notifications/IncidentReadyForReviewNotification.php`, `app/Notifications/IncidentReturnedToDepartmentNotification.php` | create |
| `app/Listeners/NotifyReviewersOfSubmittedIncident.php`, `app/Notifications/IncidentSubmittedNotification.php`, `app/Providers/EventServiceProvider.php` | modify |
| `app/Policies/IncidentPolicy.php`, `app/Models/Incident.php` (`scopeVisibleTo`) | new abilities; review at `ForReview`; dept view during assessment |
| `app/Http/Requests/Incidents/SaveAssessmentRequest.php`, `CompleteAssessmentRequest.php`, `ReturnToDepartmentRequest.php` | create |
| `app/Http/Requests/Incidents/ReturnIncidentRequest.php` | authorize via `completeAssessment` |
| `app/Http/Controllers/IncidentWorkflowController.php`, `routes/web.php`, `app/Http/Controllers/IncidentController.php` | new actions/routes; show flags; drop contributingFactors prop from wizard |
| `app/Console/Commands/CheckOverdueIncidents.php` | assessment sweep; review sweep from `assessed_at` |
| `resources/js/Pages/Incidents/Wizard.vue`, `Components/Incidents/Step2IncidentDetails.vue`, `Step5Description.vue`, `Step8Review.vue` | 6-step wizard |
| `resources/js/Components/Incidents/Step6ActionsTaken.vue`, `Step7Recommendations.vue` | delete |
| `resources/js/Components/Incidents/AssessmentPanel.vue` | create |
| `resources/js/Components/Incidents/WorkflowActionsPanel.vue`, `resources/js/Components/SeverityBadge.vue`, `resources/js/Pages/Incidents/Show.vue`, `resources/js/Pages/Incidents/Index.vue` | modify |
| `app/Http/Middleware/HandleInertiaRequests.php`, `resources/js/Layouts/AuthenticatedLayout.vue` | sidebar permissions |
| `tests/Feature/Incidents/DepartmentAssessmentTest.php`, `tests/Feature/SidebarPermissionsTest.php` | create |
| `tests/Feature/Incidents/IncidentReportingTest.php`, `IncidentWorkflowTest.php`, `tests/Feature/EscalationCommandTest.php` | update affected tests |
| `docs/architecture.md` | new §9k |

---

### Task 1: Schema, config, and slimmer reporter validation

**Files:** migration (create), `config/incident_workflow.php`, `app/Models/Incident.php`, `app/Http/Requests/Concerns/ValidatesIncidentData.php`, `app/Services/IncidentService.php` (`submit()`), `app/Http/Controllers/IncidentController.php` (`create()`/`edit()`), `tests/Feature/Incidents/IncidentReportingTest.php`.

- [ ] **Step 1: Write failing tests** — add to `tests/Feature/Incidents/IncidentReportingTest.php` (reuse the class's existing helpers/imports; `makeReporter()` exists; add `use App\Models\IncidentAction;` if missing):

```php
    public function test_the_reporter_can_submit_without_a_severity(): void
    {
        $reporter = $this->makeReporter();
        $department = Department::factory()->create();
        $type = IncidentType::factory()->create();

        $this->actingAs($reporter)->post('/incidents', [
            'action' => 'submit',
            'department_id' => $department->id,
            'incident_type_id' => $type->id,
            'occurred_at' => now()->subHour()->format('Y-m-d H:i'),
            'location' => 'Ward 3',
            'summary' => 'Patient slipped.',
            'legal_attestation' => true,
        ])->assertSessionHasNoErrors();

        $incident = Incident::latest('id')->first();
        $this->assertSame(IncidentStatus::Submitted, $incident->status);
        $this->assertNull($incident->severity);
        $this->assertFalse($incident->is_sentinel_event);
    }

    public function test_the_reporter_form_ignores_severity_actions_recommendations_and_factors(): void
    {
        $reporter = $this->makeReporter();
        $department = Department::factory()->create();
        $type = IncidentType::factory()->create();
        $factor = ContributingFactor::create(['label' => 'Fatigue', 'category' => 'Human Factors', 'is_active' => true]);

        $this->actingAs($reporter)->post('/incidents', [
            'action' => 'draft',
            'department_id' => $department->id,
            'incident_type_id' => $type->id,
            'severity' => Severity::Level4CriticalSentinel->value,
            'recommendations' => 'Install rails.',
            'actions_taken' => [['description' => 'Called doctor']],
            'contributing_factor_ids' => [$factor->id],
        ]);

        $incident = Incident::latest('id')->first();
        $this->assertNull($incident->severity);
        $this->assertNull($incident->recommendations);
        $this->assertSame(0, $incident->actions()->count());
        $this->assertSame(0, $incident->contributingFactors()->count());
    }

    public function test_updating_a_returned_draft_keeps_department_entered_actions(): void
    {
        $reporter = $this->makeReporter();
        $incident = app(IncidentService::class)->createDraft($reporter, ['location' => 'ER']);
        $incident->actions()->create(['description' => 'Entered by the department']);

        $this->actingAs($reporter)->patch("/incidents/{$incident->id}", [
            'action' => 'draft',
            'location' => 'ER bay 2',
        ]);

        $this->assertSame(['Entered by the department'], $incident->actions()->pluck('description')->all());
    }
```

Check the exact request field names the existing tests post to `/incidents` (e.g. whether `legal_attestation`, date format, `action`) and match them — copy from the nearest existing submit test in this file.

- [ ] **Step 2: Run — expect FAIL** — `php artisan test --filter=IncidentReportingTest`

- [ ] **Step 3: Migration** `database/migrations/2026_09_25_000001_add_assessment_columns_to_incidents_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Department Assessment stage (docs/superpowers/specs/2026-09-24-department-assessment-design.md).
 * assessed_by holds a tdh_user.users id — no FK (different database).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->unsignedBigInteger('assessed_by')->nullable()->index()->after('supervisor_comments');
            $table->dateTime('assessed_at')->nullable()->after('assessed_by');
            $table->dateTime('assessment_escalated_at')->nullable()->after('assessed_at');
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropColumn(['assessed_by', 'assessed_at', 'assessment_escalated_at']);
        });
    }
};
```

(SQLite: `dropColumn` of several columns in one call is fine in Laravel 9 only with doctrine/dbal; `down()` is never run in tests, so leave it.)

- [ ] **Step 4: Config** — in `config/incident_workflow.php` add before the review SLA block:

```php
    /*
    |--------------------------------------------------------------------------
    | Department assessment SLA (hours from submission; fixed — severity is
    | only known once the Department Head completes the assessment)
    |--------------------------------------------------------------------------
    */
    'assessment_sla_hours' => 72,
```

- [ ] **Step 5: Incident model** — add to `$casts`:

```php
        'assessed_by' => 'integer',
        'assessed_at' => 'datetime',
        'assessment_escalated_at' => 'datetime',
```

and a relation next to the other `belongsTo(User::class, ...)` relations:

```php
    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by');
    }
```

- [ ] **Step 6: Validation** — in `ValidatesIncidentData.php` delete the rules for `severity`, `recommendations`, `actions_taken`, `actions_taken.*.description`, `actions_taken.*.responsible_name`, `actions_taken.*.performed_at`, `actions_taken.*.status`, `contributing_factor_ids`, `contributing_factor_ids.*`. Remove now-unused `use` imports (`Severity`, `ActionStatus`) only if nothing else in the file uses them. Because `IncidentService::syncChildRecords()` only syncs keys that are present in validated data, the wizard can no longer touch actions or contributing factors.

- [ ] **Step 7: `IncidentService::submit()`** — inside the transaction replace

```php
                    $incident->is_sentinel_event = $incident->severity === Severity::Level4CriticalSentinel;
```

with

```php
                    // Severity is set later by the Department Head (completeAssessment()).
                    $incident->assessment_escalated_at = null;
```

- [ ] **Step 8: Wizard props** — in `IncidentController::create()` and `edit()` delete the `'contributingFactors' => ...` prop lines (and the `ContributingFactor` import if unused).

- [ ] **Step 9: Fix existing tests in `IncidentReportingTest` that asserted the old behaviour**: the required-fields test (`assertSessionHasErrors([... 'severity' ...])`) drops `'severity'`; tests that asserted a submitted severity/sentinel flag, persisted actions, recommendations or contributing factors from the reporter form either move those fields out of the payload or assert they're ignored. Don't delete tests that cover other behaviour.

- [ ] **Step 10: Run the class, then the full suite — all green.** Then apply the migration: `php artisan config:clear && php artisan migrate --force` (expect exactly this one migration).

- [ ] **Step 11: Commit** — `feat: drop severity, actions, recommendations and factors from the reporter form`

---

### Task 2: Assessment service methods, events and notifications

**Files:** `app/Services/IncidentService.php`, `app/Support/IncidentReviewers.php`, events/listeners/notifications listed in the file map, `app/Providers/EventServiceProvider.php`, `tests/Feature/Incidents/DepartmentAssessmentTest.php` (create).

- [ ] **Step 1: Write failing tests** — create `tests/Feature/Incidents/DepartmentAssessmentTest.php`:

```php
<?php

namespace Tests\Feature\Incidents;

use App\Enums\IncidentStatus;
use App\Enums\Role;
use App\Enums\Severity;
use App\Models\Department;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\User;
use App\Notifications\IncidentReadyForReviewNotification;
use App\Notifications\IncidentReturnedToDepartmentNotification;
use App\Services\IncidentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class DepartmentAssessmentTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();
        $this->department = Department::factory()->create();
    }

    private function submittedIncident(): Incident
    {
        $incident = app(IncidentService::class)->createDraft(User::factory()->create(), [
            'department_id' => $this->department->id,
            'incident_type_id' => IncidentType::factory()->create()->id,
            'occurred_at' => now(),
            'location' => 'Ward 3',
            'summary' => 'Test incident.',
        ]);
        app(IncidentService::class)->submit($incident);

        return $incident->fresh();
    }

    private function departmentHead(): User
    {
        return User::factory()->create(['role' => Role::DepartmentHead, 'department_id' => $this->department->id]);
    }

    public function test_save_assessment_stores_actions_recommendations_and_severity(): void
    {
        $incident = $this->submittedIncident();

        app(IncidentService::class)->saveAssessment($incident, [
            'recommendations' => 'Install grab rails.',
            'severity' => Severity::Level3High->value,
            'actions_taken' => [['description' => 'Called the doctor', 'responsible_name' => 'Nurse A']],
        ]);

        $incident->refresh();
        $this->assertSame('Install grab rails.', $incident->recommendations);
        $this->assertSame(Severity::Level3High, $incident->severity);
        $this->assertSame(['Called the doctor'], $incident->actions()->pluck('description')->all());
        $this->assertSame(IncidentStatus::Submitted, $incident->status);
    }

    public function test_complete_assessment_moves_to_for_review_and_records_the_assessor(): void
    {
        Notification::fake();
        $head = $this->departmentHead();
        $supervisor = User::factory()->create(['role' => Role::Supervisor, 'department_id' => $this->department->id]);
        $incident = $this->submittedIncident();
        app(IncidentService::class)->saveAssessment($incident, ['severity' => Severity::Level4CriticalSentinel->value]);

        app(IncidentService::class)->completeAssessment($incident->fresh(), $head);

        $incident->refresh();
        $this->assertSame(IncidentStatus::ForReview, $incident->status);
        $this->assertSame($head->id, $incident->assessed_by);
        $this->assertNotNull($incident->assessed_at);
        $this->assertTrue($incident->is_sentinel_event);
        Notification::assertSentTo([$head, $supervisor], IncidentReadyForReviewNotification::class);
    }

    public function test_return_to_department_moves_back_to_submitted_and_notifies_the_department_head(): void
    {
        Notification::fake();
        $head = $this->departmentHead();
        $reviewer = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $incident = $this->submittedIncident();
        app(IncidentService::class)->saveAssessment($incident, ['severity' => Severity::Level2Moderate->value]);
        app(IncidentService::class)->completeAssessment($incident->fresh(), $head);

        app(IncidentService::class)->returnToDepartment($incident->fresh(), $reviewer, 'Severity looks too low.');

        $this->assertSame(IncidentStatus::Submitted, $incident->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['auditable_id' => $incident->id, 'action' => 'status_changed', 'comment' => 'Severity looks too low.']);
        Notification::assertSentTo($head, IncidentReturnedToDepartmentNotification::class);
    }
}
```

(Check the `audit_logs` comment column name in `database/migrations/2026_09_18_000001_create_audit_logs_table.php` and `AuditLog::record()`; adjust `'comment'` if it differs.)

- [ ] **Step 2: Run — expect FAIL** — `php artisan test --filter=DepartmentAssessmentTest`

- [ ] **Step 3: `app/Support/IncidentReviewers.php`** — move the recipient query out of `NotifyReviewersOfSubmittedIncident` so three listeners share it:

```php
<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/** People who assess/review an incident: QSO/Admin, plus Supervisors and Department Heads of its department. */
final class IncidentReviewers
{
    public static function for(Incident $incident): Collection
    {
        return User::active()->where(function ($query) use ($incident) {
            $query->withRole([Role::QualitySafetyOfficer, Role::Administrator]);

            if ($incident->department_id !== null) {
                $query->orWhere(function ($query) use ($incident) {
                    $query->withRole([Role::Supervisor, Role::DepartmentHead])
                        ->where('section', $incident->department_id);
                });
            }
        })->get();
    }

    public static function departmentHeads(Incident $incident): Collection
    {
        if ($incident->department_id === null) {
            return new Collection();
        }

        return User::active()->withRole(Role::DepartmentHead)->where('section', $incident->department_id)->get();
    }
}
```

In `NotifyReviewersOfSubmittedIncident::handle()` replace the inline `$recipients = User::active()->where(...)->get();` with `$recipients = IncidentReviewers::for($incident);` (keep the rest). Copy the query from that listener exactly if it differs from the one above.

- [ ] **Step 4: Events** — `app/Events/IncidentAssessed.php`:

```php
<?php

namespace App\Events;

use App\Models\Incident;
use Illuminate\Foundation\Events\Dispatchable;

class IncidentAssessed
{
    use Dispatchable;

    public function __construct(public Incident $incident)
    {
    }
}
```

`app/Events/IncidentReturnedToDepartment.php`:

```php
<?php

namespace App\Events;

use App\Models\Incident;
use Illuminate\Foundation\Events\Dispatchable;

class IncidentReturnedToDepartment
{
    use Dispatchable;

    public function __construct(public Incident $incident, public string $comments)
    {
    }
}
```

- [ ] **Step 5: Notifications** — `app/Notifications/IncidentReadyForReviewNotification.php`:

```php
<?php

namespace App\Notifications;

use App\Models\Incident;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class IncidentReadyForReviewNotification extends Notification
{
    use Queueable;

    public function __construct(private Incident $incident)
    {
    }

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'incident_id' => $this->incident->id,
            'incident_number' => $this->incident->incident_number,
            'message' => "Incident {$this->incident->incident_number} has been assessed by the department and is ready for review.",
        ];
    }
}
```

`app/Notifications/IncidentReturnedToDepartmentNotification.php`:

```php
<?php

namespace App\Notifications;

use App\Models\Incident;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class IncidentReturnedToDepartmentNotification extends Notification
{
    use Queueable;

    public function __construct(private Incident $incident, private string $comments)
    {
    }

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'incident_id' => $this->incident->id,
            'incident_number' => $this->incident->incident_number,
            'message' => "Incident {$this->incident->incident_number} was returned to your department: {$this->comments}",
        ];
    }
}
```

In `IncidentSubmittedNotification::toDatabase()` change the message to:

```php
            'message' => "New incident {$this->incident->incident_number} submitted — awaiting department assessment.",
```

- [ ] **Step 6: Listeners** — `app/Listeners/NotifyReviewersOfAssessedIncident.php`:

```php
<?php

namespace App\Listeners;

use App\Events\IncidentAssessed;
use App\Notifications\IncidentReadyForReviewNotification;
use App\Support\IncidentReviewers;
use Illuminate\Support\Facades\Notification;

class NotifyReviewersOfAssessedIncident
{
    public function handle(IncidentAssessed $event): void
    {
        $recipients = IncidentReviewers::for($event->incident);

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new IncidentReadyForReviewNotification($event->incident));
        }
    }
}
```

`app/Listeners/NotifyDepartmentHeadsOfReturn.php`:

```php
<?php

namespace App\Listeners;

use App\Events\IncidentReturnedToDepartment;
use App\Notifications\IncidentReturnedToDepartmentNotification;
use App\Support\IncidentReviewers;
use Illuminate\Support\Facades\Notification;

class NotifyDepartmentHeadsOfReturn
{
    public function handle(IncidentReturnedToDepartment $event): void
    {
        $recipients = IncidentReviewers::departmentHeads($event->incident);

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new IncidentReturnedToDepartmentNotification($event->incident, $event->comments));
        }
    }
}
```

Register both in `EventServiceProvider::$listen`:

```php
        \App\Events\IncidentAssessed::class => [
            \App\Listeners\NotifyReviewersOfAssessedIncident::class,
        ],
        \App\Events\IncidentReturnedToDepartment::class => [
            \App\Listeners\NotifyDepartmentHeadsOfReturn::class,
        ],
```

- [ ] **Step 7: Service methods** — add to `IncidentService` (import `App\Events\IncidentAssessed`, `App\Events\IncidentReturnedToDepartment`, `Illuminate\Support\Arr`):

```php
    /**
     * Department Assessment edits while the incident is Submitted. Callers pass
     * only the keys the current user may change (see SaveAssessmentRequest).
     */
    public function saveAssessment(Incident $incident, array $data): Incident
    {
        return DB::transaction(function () use ($incident, $data) {
            $incident->fill(Arr::only($data, ['recommendations', 'severity', 'department_id']));
            $incident->save();

            if (array_key_exists('actions_taken', $data)) {
                $incident->actions()->delete();
                $incident->actions()->createMany($data['actions_taken'] ?? []);
            }

            return $incident;
        });
    }

    public function completeAssessment(Incident $incident, User $assessor): Incident
    {
        DB::transaction(function () use ($incident, $assessor) {
            $incident->status = IncidentStatus::ForReview;
            $incident->assessed_by = $assessor->id;
            $incident->assessed_at = now();
            $incident->review_escalated_at = null;
            $incident->is_sentinel_event = $incident->severity === Severity::Level4CriticalSentinel;
            $incident->save();
        });

        IncidentAssessed::dispatch($incident);

        return $incident;
    }

    public function returnToDepartment(Incident $incident, User $reviewer, string $comments): Incident
    {
        DB::transaction(function () use ($incident, $reviewer, $comments) {
            $incident->auditComment = $comments;
            $incident->status = IncidentStatus::Submitted;
            $incident->supervisor_reviewed_by = $reviewer->id;
            $incident->supervisor_comments = $comments;
            $incident->save();
        });

        IncidentReturnedToDepartment::dispatch($incident, $comments);

        return $incident;
    }
```

- [ ] **Step 8: Run DepartmentAssessmentTest, then the full suite — all green.**

- [ ] **Step 9: Commit** — `feat: add department assessment service methods, events and notifications`

---

### Task 3: Permissions, endpoints and review at For Review

**Files:** `app/Policies/IncidentPolicy.php`, `app/Models/Incident.php` (`scopeVisibleTo`), requests (3 new + `ReturnIncidentRequest`), `app/Http/Controllers/IncidentWorkflowController.php`, `routes/web.php`, `app/Http/Controllers/IncidentController.php` (`show()`), `tests/Feature/Incidents/DepartmentAssessmentTest.php`, `tests/Feature/Incidents/IncidentWorkflowTest.php` (+ any other test that POSTs `/review` or `/return` on a `Submitted` incident).

- [ ] **Step 1: Write failing HTTP tests** — append to `DepartmentAssessmentTest`:

```php
    private function payload(array $extra = []): array
    {
        return array_merge([
            'recommendations' => 'Install grab rails.',
            'actions_taken' => [['description' => 'Called the doctor']],
        ], $extra);
    }

    public function test_department_staff_can_view_and_save_but_not_set_severity(): void
    {
        $staff = User::factory()->create(['department_id' => $this->department->id]);
        $incident = $this->submittedIncident();

        $this->actingAs($staff)->get("/incidents/{$incident->id}")->assertOk();
        $this->actingAs($staff)
            ->post("/incidents/{$incident->id}/assessment", $this->payload(['severity' => Severity::Level4CriticalSentinel->value]))
            ->assertSessionHasNoErrors();

        $incident->refresh();
        $this->assertSame('Install grab rails.', $incident->recommendations);
        $this->assertNull($incident->severity);
    }

    public function test_staff_of_another_department_cannot_save(): void
    {
        $outsider = User::factory()->create(['department_id' => Department::factory()->create()->id]);
        $incident = $this->submittedIncident();

        $this->actingAs($outsider)->post("/incidents/{$incident->id}/assessment", $this->payload())->assertForbidden();
    }

    public function test_department_staff_lose_view_access_once_assessed(): void
    {
        $staff = User::factory()->create(['department_id' => $this->department->id]);
        $incident = $this->submittedIncident();
        app(IncidentService::class)->saveAssessment($incident, ['severity' => Severity::Level2Moderate->value]);
        app(IncidentService::class)->completeAssessment($incident->fresh(), $this->departmentHead());

        $this->actingAs($staff)->get("/incidents/{$incident->id}")->assertForbidden();
    }

    public function test_department_head_can_complete_with_severity(): void
    {
        $incident = $this->submittedIncident();

        $this->actingAs($this->departmentHead())
            ->post("/incidents/{$incident->id}/assessment/complete", $this->payload(['severity' => Severity::Level3High->value]))
            ->assertRedirect("/incidents/{$incident->id}");

        $this->assertSame(IncidentStatus::ForReview, $incident->fresh()->status);
        $this->assertSame(Severity::Level3High, $incident->fresh()->severity);
    }

    public function test_complete_requires_severity(): void
    {
        $incident = $this->submittedIncident();

        $this->actingAs($this->departmentHead())
            ->post("/incidents/{$incident->id}/assessment/complete", $this->payload())
            ->assertSessionHasErrors('severity');
    }

    public function test_supervisor_and_staff_cannot_complete(): void
    {
        $incident = $this->submittedIncident();

        foreach ([Role::Supervisor, Role::Staff] as $role) {
            $user = User::factory()->create(['role' => $role, 'department_id' => $this->department->id]);
            $this->actingAs($user)
                ->post("/incidents/{$incident->id}/assessment/complete", $this->payload(['severity' => Severity::Level1Low->value]))
                ->assertForbidden();
        }
    }

    public function test_qso_can_complete_and_change_the_department(): void
    {
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $other = Department::factory()->create();
        $incident = $this->submittedIncident();

        $this->actingAs($qso)->post("/incidents/{$incident->id}/assessment/complete", $this->payload([
            'severity' => Severity::Level1Low->value,
            'department_id' => $other->id,
        ]))->assertRedirect();

        $this->assertSame($other->id, $incident->fresh()->department_id);
        $this->assertSame(IncidentStatus::ForReview, $incident->fresh()->status);
    }

    public function test_department_head_can_return_to_the_reporter(): void
    {
        $incident = $this->submittedIncident();

        $this->actingAs($this->departmentHead())
            ->post("/incidents/{$incident->id}/return", ['comments' => 'Please add the time.'])
            ->assertRedirect('/incidents');

        $this->assertSame(IncidentStatus::Draft, $incident->fresh()->status);
    }

    public function test_review_is_only_possible_at_for_review(): void
    {
        $supervisor = User::factory()->create(['role' => Role::Supervisor, 'department_id' => $this->department->id]);
        $incident = $this->submittedIncident();

        $this->actingAs($supervisor)->post("/incidents/{$incident->id}/review")->assertForbidden();

        app(IncidentService::class)->saveAssessment($incident, ['severity' => Severity::Level2Moderate->value]);
        app(IncidentService::class)->completeAssessment($incident->fresh(), $this->departmentHead());

        $this->actingAs($supervisor)->post("/incidents/{$incident->id}/review")->assertRedirect();
        $this->assertSame(IncidentStatus::Reviewed, $incident->fresh()->status);
    }

    public function test_reviewer_can_return_to_the_department(): void
    {
        $supervisor = User::factory()->create(['role' => Role::Supervisor, 'department_id' => $this->department->id]);
        $incident = $this->submittedIncident();
        app(IncidentService::class)->saveAssessment($incident, ['severity' => Severity::Level2Moderate->value]);
        app(IncidentService::class)->completeAssessment($incident->fresh(), $this->departmentHead());

        $this->actingAs($supervisor)
            ->post("/incidents/{$incident->id}/return-to-department", ['comments' => 'Check severity.'])
            ->assertRedirect("/incidents/{$incident->id}");

        $this->assertSame(IncidentStatus::Submitted, $incident->fresh()->status);
    }

    public function test_show_exposes_assessment_abilities(): void
    {
        $incident = $this->submittedIncident();

        $this->actingAs($this->departmentHead())->get("/incidents/{$incident->id}")
            ->assertInertia(fn ($page) => $page
                ->where('can.assess', true)
                ->where('can.completeAssessment', true)
                ->where('can.changeDepartment', false)
                ->where('can.review', false));
    }
```

- [ ] **Step 2: Run — expect FAIL.**

- [ ] **Step 3: Policy** — in `IncidentPolicy`:

In `view()`, directly after the `if ($incident->status === IncidentStatus::Draft) { return false; }` block add:

```php
        // Department Assessment: anyone in the incident's department can open it to fill in actions/recommendations.
        if ($incident->status === IncidentStatus::Submitted
            && $user->department_id !== null
            && $incident->department_id === $user->department_id) {
            return true;
        }
```

Change the status check at the top of `review()` to:

```php
        if ($incident->status !== IncidentStatus::ForReview) {
            return false;
        }
```

Add:

```php
    public function assess(User $user, Incident $incident): bool
    {
        if ($incident->status !== IncidentStatus::Submitted) {
            return false;
        }

        if ($this->isQualityStaff($user)) {
            return true;
        }

        return $user->department_id !== null && $incident->department_id === $user->department_id;
    }

    /** Set severity, complete the assessment, or return the report to the reporter. */
    public function completeAssessment(User $user, Incident $incident): bool
    {
        if ($incident->status !== IncidentStatus::Submitted) {
            return false;
        }

        if ($this->isQualityStaff($user)) {
            return true;
        }

        return $user->role === Role::DepartmentHead
            && $user->department_id !== null
            && $incident->department_id === $user->department_id;
    }

    public function changeDepartment(User $user, Incident $incident): bool
    {
        return $incident->status === IncidentStatus::Submitted && $this->isQualityStaff($user);
    }
```

- [ ] **Step 4: `Incident::scopeVisibleTo()`** — in the final (Staff/Investigator) branch replace the closure body with:

```php
        return $query->where(function (Builder $q) use ($user) {
            $q->where('reporter_id', $user->id)
                ->orWhere('assigned_investigator_id', $user->id);

            if ($user->department_id !== null) {
                $q->orWhere(fn (Builder $q) => $q
                    ->where('status', IncidentStatus::Submitted)
                    ->where('department_id', $user->department_id));
            }
        });
```

- [ ] **Step 5: Requests** — `app/Http/Requests/Incidents/SaveAssessmentRequest.php`:

```php
<?php

namespace App\Http\Requests\Incidents;

use App\Enums\ActionStatus;
use App\Enums\Severity;
use App\Models\Department;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class SaveAssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('assess', $this->route('incident'));
    }

    public function rules(): array
    {
        return [
            'recommendations' => ['nullable', 'string'],
            'severity' => ['nullable', new Enum(Severity::class)],
            'department_id' => ['nullable', Department::selectableRule()],
            'actions_taken' => ['array'],
            'actions_taken.*.description' => ['required_with:actions_taken', 'string'],
            'actions_taken.*.responsible_name' => ['nullable', 'string', 'max:255'],
            'actions_taken.*.performed_at' => ['nullable', 'date'],
            'actions_taken.*.status' => ['nullable', new Enum(ActionStatus::class)],
        ];
    }

    /** Validated data minus the fields this user may not change. */
    public function assessmentData(): array
    {
        $data = $this->validated();
        $incident = $this->route('incident');

        if (! $this->user()->can('completeAssessment', $incident)) {
            unset($data['severity']);
        }

        if (! $this->user()->can('changeDepartment', $incident) || empty($data['department_id'])) {
            unset($data['department_id']);
        }

        return $data;
    }
}
```

`CompleteAssessmentRequest.php`:

```php
<?php

namespace App\Http\Requests\Incidents;

use App\Enums\Severity;
use Illuminate\Validation\Rules\Enum;

class CompleteAssessmentRequest extends SaveAssessmentRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('completeAssessment', $this->route('incident'));
    }

    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'severity' => ['required', new Enum(Severity::class)],
        ]);
    }
}
```

`ReturnToDepartmentRequest.php`:

```php
<?php

namespace App\Http\Requests\Incidents;

use Illuminate\Foundation\Http\FormRequest;

class ReturnToDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('review', $this->route('incident'));
    }

    public function rules(): array
    {
        return [
            'comments' => ['required', 'string'],
        ];
    }
}
```

In `ReturnIncidentRequest::authorize()` change `can('review', ...)` to `can('completeAssessment', ...)` (returning to the reporter now happens during assessment).

- [ ] **Step 6: Controller + routes** — add to `IncidentWorkflowController` (import the three new requests):

```php
    public function saveAssessment(SaveAssessmentRequest $request, Incident $incident): RedirectResponse
    {
        $this->incidents->saveAssessment($incident, $request->assessmentData());

        return back()->with('success', 'Assessment saved.');
    }

    public function completeAssessment(CompleteAssessmentRequest $request, Incident $incident): RedirectResponse
    {
        $this->incidents->saveAssessment($incident, $request->assessmentData());
        $this->incidents->completeAssessment($incident->fresh(), $request->user());

        return redirect()->route('incidents.show', $incident)->with('success', 'Assessment completed — the incident is ready for review.');
    }

    public function returnToDepartment(ReturnToDepartmentRequest $request, Incident $incident): RedirectResponse
    {
        $this->incidents->returnToDepartment($incident, $request->user(), $request->validated('comments'));

        return redirect()->route('incidents.show', $incident)->with('success', 'Incident returned to the department.');
    }
```

In `routes/web.php`, after the `incidents.return` route:

```php
    Route::post('/incidents/{incident}/assessment', [IncidentWorkflowController::class, 'saveAssessment'])->name('incidents.assessment.save');
    Route::post('/incidents/{incident}/assessment/complete', [IncidentWorkflowController::class, 'completeAssessment'])->name('incidents.assessment.complete');
    Route::post('/incidents/{incident}/return-to-department', [IncidentWorkflowController::class, 'returnToDepartment'])->name('incidents.return-to-department');
```

- [ ] **Step 7: `IncidentController::show()`** — make sure the `$incident->load([...])` list includes `'actions'` and `'assessor'`; add to the `'can'` array:

```php
                'assess' => $user->can('assess', $incident),
                'completeAssessment' => $user->can('completeAssessment', $incident),
                'changeDepartment' => $user->can('changeDepartment', $incident),
```

- [ ] **Step 8: Update existing tests** that POST `/review` or `/return` on a `Submitted` incident (mainly `tests/Feature/Incidents/IncidentWorkflowTest.php`; grep `tests/` for `/review'`, `/return'`, `incidents.review`, `incidents.return`). Add to `IncidentWorkflowTest`:

```php
    private function assessedIncident(User $reporter, ?Department $department = null): Incident
    {
        $incident = $this->submittedIncident($reporter, $department);
        app(IncidentService::class)->completeAssessment($incident, User::factory()->create(['role' => Role::QualitySafetyOfficer]));

        return $incident->fresh();
    }
```

(`submittedIncident()` already sets severity via `createDraft`.) Review tests use `assessedIncident()`. Tests of "return for revision" by a reviewer become either (a) Department Head returning a `Submitted` incident to the reporter via `/return`, or (b) reviewer returning a `ForReview` incident via `/return-to-department` — keep each test's intent. Notification-count tests may now see `IncidentReadyForReviewNotification` too; adjust expectations, not the app. Don't weaken authorization assertions.

- [ ] **Step 9: Run the two classes, then the full suite — all green.**

- [ ] **Step 10: Commit** — `feat: department assessment endpoints and permissions; review at For Review`

---

### Task 4: Escalation sweeps

**Files:** `app/Console/Commands/CheckOverdueIncidents.php`, `tests/Feature/EscalationCommandTest.php`.

- [ ] **Step 1: Write failing tests** — add to `EscalationCommandTest` (reuse its existing setup for escalation recipients — read the top of the file; it creates QSO/Admin recipients and a helper for incidents):

```php
    public function test_an_overdue_department_assessment_is_escalated_once(): void
    {
        $recipient = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $incident = app(IncidentService::class)->createDraft(User::factory()->create(), [
            'department_id' => Department::factory()->create()->id,
            'incident_type_id' => IncidentType::factory()->create()->id,
            'occurred_at' => now(), 'location' => 'ER', 'summary' => 'x',
        ]);
        app(IncidentService::class)->submit($incident);
        $incident->fresh()->forceFill(['reported_at' => now()->subHours(73)])->save();

        $this->artisan('incidents:check-overdue')->assertSuccessful();
        $this->artisan('incidents:check-overdue')->assertSuccessful();

        $this->assertNotNull($incident->fresh()->assessment_escalated_at);
        $this->assertCount(1, $recipient->notifications()->where('type', IncidentEscalationNotification::class)->get());
    }

    public function test_the_review_sla_counts_from_assessment_completion(): void
    {
        User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $incident = app(IncidentService::class)->createDraft(User::factory()->create(), [
            'department_id' => Department::factory()->create()->id,
            'incident_type_id' => IncidentType::factory()->create()->id,
            'severity' => Severity::Level4CriticalSentinel->value, // review SLA 24h
            'occurred_at' => now(), 'location' => 'ER', 'summary' => 'x',
        ]);
        app(IncidentService::class)->submit($incident);
        app(IncidentService::class)->completeAssessment($incident->fresh(), User::factory()->create(['role' => Role::QualitySafetyOfficer]));
        $incident->fresh()->forceFill(['reported_at' => now()->subDays(10), 'assessed_at' => now()->subHours(2)])->save();

        $this->artisan('incidents:check-overdue')->assertSuccessful();
        $this->assertNull($incident->fresh()->review_escalated_at);

        $incident->fresh()->forceFill(['assessed_at' => now()->subHours(25)])->save();
        $this->artisan('incidents:check-overdue')->assertSuccessful();
        $this->assertNotNull($incident->fresh()->review_escalated_at);
    }
```

(Add imports as needed. Check `notifications()->...->type` matches how the existing tests in this file assert escalation notifications and mirror their style.)

- [ ] **Step 2: Run — expect FAIL.**

- [ ] **Step 3: Implement** — in `handle()` call `$this->escalateOverdueAssessments($recipients);` before `escalateOverdueReviews`. Add:

```php
    private function escalateOverdueAssessments(Collection $recipients): void
    {
        $hours = (int) config('incident_workflow.assessment_sla_hours', 72);

        Incident::whereNull('assessment_escalated_at')
            ->where('status', IncidentStatus::Submitted)
            ->whereNotNull('reported_at')
            ->where('reported_at', '<=', now()->subHours($hours))
            ->get()
            ->each(function (Incident $incident) use ($recipients) {
                Notification::send($recipients, new IncidentEscalationNotification($incident, 'Department assessment SLA breached'));
                $incident->forceFill(['assessment_escalated_at' => now()])->save();
            });
    }
```

Change `escalateOverdueReviews()` to look at `ForReview` incidents timed from `assessed_at`:

```php
        Incident::whereNull('review_escalated_at')
            ->where('status', IncidentStatus::ForReview)
            ->whereNotNull('assessed_at')
            ->get()
            ->each(function (Incident $incident) use ($recipients) {
                $slaHours = config('incident_workflow.review_sla_hours.' . $incident->severity?->value);

                if ($slaHours === null || $incident->assessed_at->addHours($slaHours)->isFuture()) {
                    return;
                }

                Notification::send($recipients, new IncidentEscalationNotification($incident, 'Review SLA breached'));
                $incident->forceFill(['review_escalated_at' => now()])->save();
            });
```

- [ ] **Step 4: Update existing review-escalation tests** in `EscalationCommandTest` that set `reported_at` on a `Submitted` incident and expect a review escalation: move the incident to `ForReview` via `completeAssessment()` and set `assessed_at` instead of `reported_at`. Keep each test's intent (e.g. "escalates once", "not before SLA", "resubmission resets").

- [ ] **Step 5: Full suite green. Commit** — `feat: escalate overdue department assessments; time review SLA from assessment`

---

### Task 5: Frontend — 6-step wizard, assessment panel, review panel

**Files:** `resources/js/Pages/Incidents/Wizard.vue`, `Components/Incidents/Step2IncidentDetails.vue`, `Step5Description.vue`, `Step8Review.vue`, delete `Step6ActionsTaken.vue` and `Step7Recommendations.vue`, create `Components/Incidents/AssessmentPanel.vue`, modify `WorkflowActionsPanel.vue`, `Components/SeverityBadge.vue`, `Pages/Incidents/Show.vue`, `Pages/Incidents/Index.vue`.

No PHP tests; verify with `npm run build` and the browser check at the end of the plan.

- [ ] **Step 1: Wizard** — in `Wizard.vue`:
  - remove the `Step6ActionsTaken` / `Step7Recommendations` imports and their entries in `steps`;
  - `STEP_FIELDS` becomes:

```js
const STEP_FIELDS = {
    1: ['legal_attestation'],
    2: ['incident_type_id', 'department_id', 'occurred_at', 'location'],
    3: ['individuals'],
    4: ['witnesses', 'police_notified', 'police_station', 'police_officer_in_charge', 'police_blotter_no', 'police_notified_at'],
    5: ['summary', 'narrative_events', 'attachments'],
};
```
  - remove `severity`, `recommendations`, `actions_taken`, `contributing_factor_ids` from `useForm({...})`;
  - remove the `contributingFactors` prop and stop passing it to step components.

- [ ] **Step 2: Step components**
  - `Step2IncidentDetails.vue`: delete the `severities` const and the whole "Severity & Harm Level" block; heading → `Section 2: Incident Details`.
  - `Step5Description.vue`: delete the `contributingFactors` prop and the "Contributing Factors" block.
  - `Step8Review.vue`: delete the Severity row and the `SeverityBadge` import; heading → `Section 6: Review & Submit`.
  - Delete `Step6ActionsTaken.vue` and `Step7Recommendations.vue` (`git rm`). Keep a copy of Step6's action-row markup to reuse in Step 4 below.

- [ ] **Step 3: SeverityBadge** — make `severity` optional and show a neutral badge when missing:

```vue
<script setup>
import { computed } from 'vue';
import { severityLabel, severityBadgeClasses } from '@/Composables/useIncidentStatus';

const props = defineProps({
    severity: { type: String, default: null },
});

const label = computed(() => (props.severity ? severityLabel(props.severity) : 'Pending assessment'));
const classes = computed(() => (props.severity ? severityBadgeClasses(props.severity) : 'bg-surface-container text-on-surface-variant'));
</script>

<template>
    <span :class="classes" class="px-2.5 py-0.5 rounded-full font-label-sm text-body-sm font-semibold whitespace-nowrap">
        {{ label }}
    </span>
</template>
```

In `Show.vue` (line ~52) and `Index.vue` (line ~74) remove the `v-if="incident.severity"` so pending incidents show the neutral badge.

- [ ] **Step 4: `AssessmentPanel.vue`** — create:

```vue
<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import ConfirmationDialog from '@/Components/ConfirmationDialog.vue';
import SeverityBadge from '@/Components/SeverityBadge.vue';

const props = defineProps({
    incident: { type: Object, required: true },
    can: { type: Object, required: true },
    departments: { type: Array, default: () => [] },
});

const severities = [
    { value: 'level_1_low', label: 'Low Risk', numeral: 'Level I' },
    { value: 'level_2_moderate', label: 'Moderate Risk', numeral: 'Level II' },
    { value: 'level_3_high', label: 'High Severity', numeral: 'Level III' },
    { value: 'level_4_critical_sentinel', label: 'Critical / Sentinel', numeral: 'Level IV' },
];

const actionStatuses = [
    { value: 'pending', label: 'Pending' },
    { value: 'in_progress', label: 'In Progress' },
    { value: 'completed', label: 'Completed' },
];

let nextKey = 0;
const toRow = (action) => ({
    _key: nextKey++,
    description: action.description ?? '',
    responsible_name: action.responsible_name ?? '',
    performed_at: action.performed_at ? action.performed_at.slice(0, 16) : '',
    status: action.status ?? 'pending',
});

const editable = computed(() => props.incident.status === 'submitted' && props.can.assess);

const form = useForm({
    actions_taken: (props.incident.actions ?? []).map(toRow),
    recommendations: props.incident.recommendations ?? '',
    severity: props.incident.severity ?? null,
    department_id: props.incident.department_id ?? null,
});

const returnForm = useForm({ comments: '' });
const showCompleteConfirm = ref(false);
const showReturnConfirm = ref(false);

function addAction() {
    form.actions_taken.push(toRow({}));
}

function removeAction(index) {
    form.actions_taken.splice(index, 1);
}

function payload(data) {
    return { ...data, actions_taken: data.actions_taken.map(({ _key, ...row }) => row) };
}

function save() {
    form.transform(payload).post(`/incidents/${props.incident.id}/assessment`, { preserveScroll: true });
}

function complete() {
    form.transform(payload).post(`/incidents/${props.incident.id}/assessment/complete`, {
        preserveScroll: true,
        onFinish: () => (showCompleteConfirm.value = false),
    });
}

function confirmReturn() {
    if (!returnForm.comments.trim()) {
        returnForm.setError('comments', 'A comment is required when returning the report to the reporter.');
        return;
    }
    showReturnConfirm.value = true;
}

function returnToReporter() {
    returnForm.post(`/incidents/${props.incident.id}/return`, {
        onFinish: () => (showReturnConfirm.value = false),
    });
}
</script>

<template>
    <section class="flex flex-col gap-space-md">
        <div class="flex items-center justify-between">
            <h2 class="font-title-lg text-title-lg text-primary font-bold">Department Assessment</h2>
            <span v-if="incident.assessed_at" class="font-body-sm text-body-sm text-outline">
                Assessed by {{ incident.assessor?.name ?? 'a former user' }} on {{ new Date(incident.assessed_at).toLocaleString() }}
            </span>
        </div>

        <!-- Severity -->
        <div class="flex flex-col gap-1.5">
            <span class="font-label-md text-label-md text-on-surface font-semibold">Severity</span>
            <div v-if="editable && can.completeAssessment" class="grid grid-cols-2 md:grid-cols-4 gap-2">
                <label
                    v-for="option in severities"
                    :key="option.value"
                    class="p-3 rounded-lg cursor-pointer text-center"
                    :class="form.severity === option.value ? 'bg-amber-50 ring-2 ring-amber-500' : 'bg-surface-container-low hover:bg-surface-container'"
                >
                    <input v-model="form.severity" type="radio" :value="option.value" class="hidden" />
                    <span class="block font-label-sm text-body-sm text-outline">{{ option.numeral }}</span>
                    <span class="block font-body-md text-body-md text-on-surface font-semibold">{{ option.label }}</span>
                </label>
            </div>
            <SeverityBadge v-else :severity="incident.severity" class="self-start" />
            <span v-if="form.errors.severity" class="font-body-sm text-body-sm text-error">{{ form.errors.severity }}</span>
        </div>

        <!-- Department (QSO/Admin) -->
        <div v-if="editable && can.changeDepartment" class="flex flex-col gap-1.5">
            <label for="assessment_department" class="font-label-md text-label-md text-on-surface font-semibold">Department</label>
            <select id="assessment_department" v-model="form.department_id" class="w-full md:w-1/2 p-3 rounded-lg bg-surface-container-low">
                <option v-for="department in departments" :key="department.id" :value="department.id">{{ department.name }}</option>
            </select>
            <span v-if="form.errors.department_id" class="font-body-sm text-body-sm text-error">{{ form.errors.department_id }}</span>
        </div>

        <!-- Immediate actions -->
        <div class="flex flex-col gap-2">
            <div class="flex items-center justify-between">
                <span class="font-label-md text-label-md text-on-surface font-semibold">Immediate Actions Taken</span>
                <button v-if="editable" type="button" class="font-label-sm text-label-sm font-bold text-primary" @click="addAction">+ Add Action</button>
            </div>

            <p v-if="!editable && !(incident.actions ?? []).length" class="font-body-sm text-body-sm text-outline">No immediate actions recorded.</p>

            <template v-if="editable">
                <div v-for="(action, index) in form.actions_taken" :key="action._key" class="p-3 rounded-lg bg-surface-container-low flex flex-col gap-2">
                    <div class="flex items-center justify-between">
                        <span class="font-label-sm text-label-sm uppercase text-outline">Action {{ index + 1 }}</span>
                        <button type="button" class="text-error font-label-sm text-body-sm" @click="removeAction(index)">Remove</button>
                    </div>
                    <label class="sr-only" :for="'assessment-action-' + index">Intervention taken</label>
                    <textarea :id="'assessment-action-' + index" v-model="action.description" rows="2" placeholder="Intervention taken" class="p-2.5 rounded-lg bg-surface-container-lowest" />
                    <span v-if="form.errors[`actions_taken.${index}.description`]" class="font-body-sm text-body-sm text-error">{{ form.errors[`actions_taken.${index}.description`] }}</span>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-2">
                        <label class="sr-only" :for="'assessment-responsible-' + index">Responsible officer</label>
                        <input :id="'assessment-responsible-' + index" v-model="action.responsible_name" type="text" placeholder="Responsible officer" class="p-2.5 rounded-lg bg-surface-container-lowest" />
                        <label class="sr-only" :for="'assessment-performed-' + index">Date and time performed</label>
                        <input :id="'assessment-performed-' + index" v-model="action.performed_at" type="datetime-local" class="p-2.5 rounded-lg bg-surface-container-lowest" />
                        <label class="sr-only" :for="'assessment-status-' + index">Status</label>
                        <select :id="'assessment-status-' + index" v-model="action.status" class="p-2.5 rounded-lg bg-surface-container-lowest">
                            <option v-for="status in actionStatuses" :key="status.value" :value="status.value">{{ status.label }}</option>
                        </select>
                    </div>
                </div>
            </template>
            <ul v-else class="flex flex-col gap-2">
                <li v-for="action in incident.actions" :key="action.id" class="p-3 rounded-lg bg-surface-container-low">
                    <p class="font-body-md text-body-md text-on-surface">{{ action.description }}</p>
                    <p class="font-body-sm text-body-sm text-outline">
                        {{ action.responsible_name || '—' }} · {{ action.performed_at ? new Date(action.performed_at).toLocaleString() : '—' }} · {{ action.status }}
                    </p>
                </li>
            </ul>
        </div>

        <!-- Recommendations -->
        <div class="flex flex-col gap-1.5">
            <label for="assessment_recommendations" class="font-label-md text-label-md text-on-surface font-semibold">Recommendations</label>
            <textarea v-if="editable" id="assessment_recommendations" v-model="form.recommendations" rows="4" class="w-full p-3 rounded-lg bg-surface-container-low" placeholder="What should change to prevent this from happening again?" />
            <p v-else class="font-body-md text-body-md text-on-surface whitespace-pre-line">{{ incident.recommendations || '—' }}</p>
        </div>

        <!-- Buttons -->
        <div v-if="editable" class="flex flex-wrap gap-2">
            <button type="button" :disabled="form.processing" class="px-4 py-2 rounded-lg bg-surface-container text-on-surface font-label-md text-label-md disabled:opacity-60" @click="save">
                Save
            </button>
            <button
                v-if="can.completeAssessment"
                type="button"
                :disabled="form.processing"
                class="px-4 py-2 rounded-lg bg-primary text-on-primary font-label-md text-label-md font-semibold disabled:opacity-60"
                @click="showCompleteConfirm = true"
            >
                Complete Assessment
            </button>
        </div>

        <!-- Return to reporter -->
        <div v-if="editable && can.completeAssessment" class="flex flex-col gap-2 pt-space-sm border-t border-outline-variant">
            <label for="return_comments" class="font-label-md text-label-md text-on-surface font-semibold">Return to reporter</label>
            <textarea id="return_comments" v-model="returnForm.comments" rows="2" class="w-full p-3 rounded-lg bg-surface-container-low" placeholder="What does the reporter need to fix?" />
            <span v-if="returnForm.errors.comments" class="font-body-sm text-body-sm text-error">{{ returnForm.errors.comments }}</span>
            <button type="button" :disabled="returnForm.processing" class="self-start px-4 py-2 rounded-lg bg-surface-container text-on-surface font-label-md text-label-md disabled:opacity-60" @click="confirmReturn">
                Return to Reporter
            </button>
        </div>

        <ConfirmationDialog
            :show="showCompleteConfirm"
            title="Complete the assessment?"
            message="The incident will be sent for review. Department edits will be locked unless a reviewer returns it."
            confirm-label="Complete Assessment"
            @confirm="complete"
            @cancel="showCompleteConfirm = false"
        />
        <ConfirmationDialog
            :show="showReturnConfirm"
            title="Return to the reporter?"
            message="The report goes back to the reporter as a draft."
            confirm-label="Return to Reporter"
            @confirm="returnToReporter"
            @cancel="showReturnConfirm = false"
        />
    </section>
</template>
```

Check `ConfirmationDialog.vue`'s actual prop/event names (how `WorkflowActionsPanel.vue` uses it) and match them exactly; check `ActionStatus` enum values in `app/Enums/ActionStatus.php` and match `actionStatuses`.

- [ ] **Step 5: Mount it** — in `Show.vue`, import `AssessmentPanel` and render it as the first child of the Overview tab container (`<div v-if="activeTab === 'overview'" ...>`):

```vue
            <AssessmentPanel :incident="incident" :can="can" :departments="departments" />
```

Make sure `departments` is in Show.vue's `defineProps` (the controller already sends it). If the Overview tab already renders Immediate Actions / Recommendations sections, remove those duplicates.

- [ ] **Step 6: `WorkflowActionsPanel.vue`** — the review block shows only at `for_review`, and its return button sends the incident back to the department:
  - `v-if="can.review && incident.status === 'for_review'"`
  - `returnForRevision()` posts to `/incidents/${props.incident.id}/return-to-department` with `preserveScroll: true`;
  - button label `Return to Department`; the comment-required message → `A comment is required when returning an incident to the department.`; confirmation text updated to match.

- [ ] **Step 7: `npm run build`** — must succeed. `grep -rn "Step6ActionsTaken\|Step7Recommendations\|contributing_factor_ids\|contributingFactors" resources/js` → no matches.

- [ ] **Step 8: Commit** — `feat: six-step report form and department assessment panel`

---

### Task 6: Role-based sidebar

**Files:** `app/Http/Middleware/HandleInertiaRequests.php`, `resources/js/Layouts/AuthenticatedLayout.vue`, `tests/Feature/SidebarPermissionsTest.php` (create).

- [ ] **Step 1: Failing test** `tests/Feature/SidebarPermissionsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SidebarPermissionsTest extends TestCase
{
    use RefreshDatabase;

    /** @dataProvider roles */
    public function test_sidebar_flags_match_the_role(Role $role, array $expected): void
    {
        $user = User::factory()->create(['role' => $role]);

        $this->actingAs($user)->get('/')->assertInertia(fn ($page) => $page->where('auth.can', $expected));
    }

    public static function roles(): array
    {
        $flags = fn (bool $all, bool $inv, bool $capa, bool $analytics, bool $admin) => [
            'viewAllIncidents' => $all,
            'investigationWorkspace' => $inv,
            'capaOperations' => $capa,
            'viewAnalytics' => $analytics,
            'administration' => $admin,
        ];

        return [
            'staff' => [Role::Staff, $flags(false, false, false, false, false)],
            'investigator' => [Role::Investigator, $flags(true, true, false, false, false)],
            'supervisor' => [Role::Supervisor, $flags(true, true, true, true, false)],
            'department head' => [Role::DepartmentHead, $flags(true, true, true, true, false)],
            'qso' => [Role::QualitySafetyOfficer, $flags(true, true, true, true, false)],
            'administrator' => [Role::Administrator, $flags(true, true, true, true, true)],
            'management' => [Role::Management, $flags(true, false, false, true, false)],
        ];
    }
}
```

(PHPUnit 9 in Laravel 9 uses `@dataProvider` docblocks; if the project's PHPUnit version differs, use whatever the existing tests use.)

- [ ] **Step 2: Run — expect FAIL.**

- [ ] **Step 3: Share the flags** — in `HandleInertiaRequests::share()`, inside the `'auth'` array next to `'user'`:

```php
                'can' => ($user = $request->user()) ? [
                    'viewAllIncidents' => $user->can('viewAny', Incident::class),
                    'investigationWorkspace' => in_array($user->role, [Role::Investigator, Role::Supervisor, Role::DepartmentHead, Role::QualitySafetyOfficer, Role::Administrator], true),
                    'capaOperations' => in_array($user->role, [Role::Supervisor, Role::DepartmentHead, Role::QualitySafetyOfficer, Role::Administrator], true),
                    'viewAnalytics' => $user->can('viewAnalytics', Incident::class),
                    'administration' => $user->role === Role::Administrator,
                ] : [],
```

(Import `App\Enums\Role` and `App\Models\Incident`. If the `'user'` entry already assigns `$user` inline, reuse it rather than re-assigning.)

- [ ] **Step 4: Filter the sidebar** — in `AuthenticatedLayout.vue`:
  - add a `can` key to items/groups using the flag names above: on the Incident Management items All Incidents, Pending Review, Under Investigation, Corrective Actions, Resolved / Closed → `can: 'viewAllIncidents'`; groups Investigation Workspace → `can: 'investigationWorkspace'`, CAPA Operations → `'capaOperations'`, Analytics & Learning → `'viewAnalytics'`, Administration & Audit → `'administration'`. My Reports and Draft Reports have no `can` (always shown).
  - rename the static array to `allNavGroups` and add:

```js
const permissions = computed(() => page.props.auth?.can ?? {});
const allowed = (entry) => !entry.can || permissions.value[entry.can] === true;

const navGroups = computed(() =>
    allNavGroups
        .filter(allowed)
        .map((group) => ({ ...group, items: group.items.filter(allowed) }))
        .filter((group) => group.items.length > 0),
);
```

  The template's `v-for="group in navGroups"` keeps working (computed refs unwrap in templates).

- [ ] **Step 5: Run the test, full suite green, `npm run build` succeeds.**

- [ ] **Step 6: Commit** — `feat: show only the sidebar menus the user can use`

---

### Task 7: Documentation

**Files:** `docs/architecture.md`.

- [ ] **Step 1:** Add "§9k. Department Assessment & role-based sidebar (2026-09-25)" after §9j: the lifecycle (`Submitted` = assessment, `ForReview` = ready for review), who can do what (table from the spec §4–§5), department staff can view an incident only while it's in assessment, the 6-step reporter form, the new endpoints, `assessed_by/assessed_at/assessment_escalated_at`, the 72h assessment SLA and review SLA from `assessed_at`, the three notifications, the `auth.can` sidebar flags, and that hiding a menu is cosmetic (server authorization unchanged). Update §3.1 (incident status lifecycle) to mention the assessment stage. Keep it as short as §9i.
- [ ] **Step 2: Commit** — `docs: document department assessment and role-based sidebar`

---

## After Task 7 (controller session)

1. Holistic review of the whole diff.
2. Browser check (`php artisan serve`, Playwright): the 6-step wizard; the assessment panel for a Department Head (severity picker, Save, Complete, Return) and read-only after completion; the review panel at For Review; the sidebar for Staff vs Administrator. Real logins need the user's own credentials — use test sessions or ask the user to click through.
3. Update memory.
