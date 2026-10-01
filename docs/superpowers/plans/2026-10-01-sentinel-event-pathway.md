# Sentinel Event Pathway Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** On Sentinel incidents, show a red banner and a six-step Sentinel Event Pathway panel, and let the department's Head/Focal Person confirm that records, equipment and evidence were preserved.

**Architecture:** Two nullable columns on `incidents`, one policy ability, one route → `IncidentWorkflowController` → `IncidentService` (Phase 1-4 style: Service + Policy, no DTO/Action). The panel derives every other step from data the page already has.

**Tech Stack:** Laravel 9.52 / PHP 8.2, PHPUnit feature tests, Vue 3 + Inertia, Tailwind 3.

**Spec:** `docs/superpowers/specs/2026-10-01-sentinel-event-pathway-design.md`

## Ground rules for every task

- `php artisan config:clear` before tests. `php artisan test <path>` runs only the first path. Full suite ≈ 5-8 min.
- Commit straight to `master`; every message ends with a blank line and `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- **Never stage** `app/DataTransferObjects/Investigations/AddTeamMemberData.php` or `config/incident_workflow.php` (user's uncommitted local edits). Never `git add -A` / `git add .`.
- Tests create users with `User::factory()->create(['role' => Role::X, 'department_id' => $id])` and departments with `Department::factory()->create()`.

---

### Task 1: Backend — columns, policy, route, service, show props

**Files:**
- Create: `database/migrations/2026_10_01_000003_add_evidence_preserved_to_incidents.php`
- Modify: `app/Models/Incident.php` (casts ~line 57-82; add relation next to `effectivenessCheckedBy()` ~line 127)
- Modify: `app/Policies/IncidentPolicy.php` (add ability; use existing `Role`, `IncidentStatus` imports)
- Modify: `app/Services/IncidentService.php` (add method near `recordEffectiveness` ~line 148)
- Modify: `app/Http/Controllers/IncidentWorkflowController.php` (add action near `recordEffectiveness` ~line 33)
- Modify: `routes/web.php` (next to `incidents.effectiveness` ~line 48)
- Modify: `app/Http/Controllers/IncidentController.php` (`show()`: add `'evidencePreservedBy'` to the `load([...])` list ~line 138; add `'confirmEvidencePreserved' => $user->can('confirmEvidencePreserved', $incident),` to the `can` array ~line 215)
- Test: `tests/Feature/Incidents/SentinelPathwayTest.php`

- [ ] **Step 1: Write the failing tests** — `tests/Feature/Incidents/SentinelPathwayTest.php`

```php
<?php

namespace Tests\Feature\Incidents;

use App\Enums\IncidentStatus;
use App\Enums\Role;
use App\Enums\Severity;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\User;
use App\Services\IncidentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SentinelPathwayTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->department = Department::factory()->create();
    }

    private function assessed(Severity $severity): Incident
    {
        $incident = app(IncidentService::class)->createDraft(User::factory()->create(), [
            'department_id' => $this->department->id,
            'incident_type_ids' => [IncidentType::factory()->create()->id],
            'occurred_at' => now(),
            'location' => 'ICU',
            'summary' => 'Sentinel pathway test.',
        ]);
        app(IncidentService::class)->submit($incident);
        app(IncidentService::class)->saveAssessment($incident->fresh(), ['severity' => $severity->value]);
        app(IncidentService::class)->completeAssessment($incident->fresh(), $this->head());

        return $incident->fresh();
    }

    private function head(): User
    {
        return User::factory()->create(['role' => Role::DepartmentHead, 'department_id' => $this->department->id]);
    }

    private function focalPerson(): User
    {
        return User::factory()->create(['role' => Role::Supervisor, 'department_id' => $this->department->id]);
    }

    public function test_the_department_head_confirms_evidence_preserved(): void
    {
        $incident = $this->assessed(Severity::Level5Sentinel);
        $head = $this->head();

        $this->actingAs($head)->post("/incidents/{$incident->id}/evidence-preserved")->assertRedirect();

        $incident->refresh();
        $this->assertNotNull($incident->evidence_preserved_at);
        $this->assertSame($head->id, $incident->evidence_preserved_by);
        $this->assertTrue(AuditLog::where('auditable_id', $incident->id)->where('action', 'evidence_preserved')->exists());
    }

    public function test_the_focal_person_can_confirm(): void
    {
        $incident = $this->assessed(Severity::Level5Sentinel);

        $this->actingAs($this->focalPerson())->post("/incidents/{$incident->id}/evidence-preserved")->assertRedirect();

        $this->assertNotNull($incident->fresh()->evidence_preserved_at);
    }

    public function test_others_cannot_confirm(): void
    {
        $incident = $this->assessed(Severity::Level5Sentinel);
        $others = [
            User::factory()->create(['department_id' => $this->department->id]), // staff
            User::factory()->create(['role' => Role::DepartmentHead, 'department_id' => Department::factory()->create()->id]),
            User::factory()->create(['role' => Role::QualitySafetyOfficer]),
        ];

        foreach ($others as $user) {
            $this->actingAs($user)->post("/incidents/{$incident->id}/evidence-preserved")->assertForbidden();
        }
        $this->assertNull($incident->fresh()->evidence_preserved_at);
    }

    public function test_only_sentinel_incidents_and_only_once(): void
    {
        $critical = $this->assessed(Severity::Level4Critical);
        $this->actingAs($this->head())->post("/incidents/{$critical->id}/evidence-preserved")->assertForbidden();

        $sentinel = $this->assessed(Severity::Level5Sentinel);
        $this->actingAs($this->head())->post("/incidents/{$sentinel->id}/evidence-preserved")->assertRedirect();
        $this->actingAs($this->head())->post("/incidents/{$sentinel->id}/evidence-preserved")->assertForbidden();
    }

    public function test_a_closed_incident_cannot_be_confirmed(): void
    {
        $incident = $this->assessed(Severity::Level5Sentinel);
        $incident->forceFill(['status' => IncidentStatus::Closed])->save();

        $this->actingAs($this->head())->post("/incidents/{$incident->id}/evidence-preserved")->assertForbidden();
    }

    public function test_the_incident_page_exposes_the_pathway_data(): void
    {
        $incident = $this->assessed(Severity::Level5Sentinel);
        $head = $this->head();

        $this->actingAs($head)->get("/incidents/{$incident->id}")
            ->assertInertia(fn (Assert $page) => $page->where('can.confirmEvidencePreserved', true));

        $this->actingAs($head)->post("/incidents/{$incident->id}/evidence-preserved");

        $this->actingAs($head)->get("/incidents/{$incident->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.confirmEvidencePreserved', false)
                ->where('incident.evidence_preserved_by.id', $head->id));
    }
}
```

Note: if the serialized relation key is `evidence_preserved_by` (snake_case relation name `evidencePreservedBy` → `evidence_preserved_by`), it **collides** with the column `evidence_preserved_by` (the integer). Laravel's `toArray()` merges relations over attributes, so the key will hold the relation object once loaded. That is what the last assertion relies on; the integer stays available as `$incident->evidence_preserved_by` in PHP. If the existing `effectivenessCheckedBy`/`effectiveness_checked_by` pair is rendered in `ApprovalPanel.vue` as `incident.effectiveness_checked_by?.name`, this is the established pattern — follow it.

- [ ] **Step 2: Run — expect FAIL** (404 route / missing columns)

Run: `php artisan config:clear` then `php artisan test tests/Feature/Incidents/SentinelPathwayTest.php`

- [ ] **Step 3: Migration** — `database/migrations/2026_10_01_000003_add_evidence_preserved_to_incidents.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Sentinel Event Pathway: who confirmed records, equipment and evidence were preserved, and when. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->timestamp('evidence_preserved_at')->nullable();
            $table->unsignedBigInteger('evidence_preserved_by')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropIndex(['evidence_preserved_by']);
            $table->dropColumn(['evidence_preserved_at', 'evidence_preserved_by']);
        });
    }
};
```

- [ ] **Step 4: Model** — in `app/Models/Incident.php` add to `$casts`:

```php
        'evidence_preserved_at' => 'datetime',
        'evidence_preserved_by' => 'integer',
```
and the relation after `effectivenessCheckedBy()`:

```php
    public function evidencePreservedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evidence_preserved_by');
    }
```

- [ ] **Step 5: Policy** — add to `app/Policies/IncidentPolicy.php` (near `checkEffectiveness`):

```php
    /** Sentinel Event Pathway: the department's Head or Focal Person confirms records/equipment/evidence were preserved. */
    public function confirmEvidencePreserved(User $user, Incident $incident): bool
    {
        return $incident->is_sentinel_event
            && $incident->evidence_preserved_at === null
            && $incident->status !== IncidentStatus::Closed
            && in_array($user->role, [Role::Supervisor, Role::DepartmentHead], true)
            && $incident->department_id !== null
            && $incident->department_id === $user->department_id;
    }
```

- [ ] **Step 6: Service** — add to `app/Services/IncidentService.php` (add `use App\Models\AuditLog;` if missing):

```php
    /** Sentinel Event Pathway step 2. Tracking only - it blocks nothing. */
    public function confirmEvidencePreserved(Incident $incident, User $confirmedBy): Incident
    {
        DB::transaction(function () use ($incident, $confirmedBy) {
            $incident->evidence_preserved_at = now();
            $incident->evidence_preserved_by = $confirmedBy->id;
            $incident->save();

            AuditLog::record($incident, 'evidence_preserved', 'Records, equipment and evidence confirmed preserved (Sentinel Event Pathway).');
        });

        return $incident;
    }
```
(If `evidence_preserved_*` aren't in `$fillable`, direct property assignment as above still works.)

- [ ] **Step 7: Controller + route**

`app/Http/Controllers/IncidentWorkflowController.php`:

```php
    public function confirmEvidencePreserved(Request $request, Incident $incident): RedirectResponse
    {
        $this->authorize('confirmEvidencePreserved', $incident);

        $this->incidents->confirmEvidencePreserved($incident, $request->user());

        return redirect()->route('incidents.show', $incident)->with('success', 'Recorded: records, equipment and evidence are preserved.');
    }
```
(add `use Illuminate\Http\Request;` if missing; confirm the controller uses `AuthorizesRequests` — base `Controller` does in Laravel 9.)

`routes/web.php`, next to `incidents.effectiveness`:

```php
    Route::post('/incidents/{incident}/evidence-preserved', [IncidentWorkflowController::class, 'confirmEvidencePreserved'])->name('incidents.evidence-preserved');
```

- [ ] **Step 8: Show props** — in `IncidentController::show()` add `'evidencePreservedBy'` to the `load([...])` array and `'confirmEvidencePreserved' => $user->can('confirmEvidencePreserved', $incident),` to `can`.

- [ ] **Step 9: Run — expect PASS**, then the whole suite

Run: `php artisan test tests/Feature/Incidents/SentinelPathwayTest.php` → 6 pass. Then `php artisan test` → all green.

- [ ] **Step 10: Commit**

```bash
git add database/migrations/2026_10_01_000003_add_evidence_preserved_to_incidents.php app/Models/Incident.php app/Policies/IncidentPolicy.php app/Services/IncidentService.php app/Http/Controllers/IncidentWorkflowController.php routes/web.php app/Http/Controllers/IncidentController.php tests/Feature/Incidents/SentinelPathwayTest.php
git commit -m "feat: sentinel pathway - confirm records, equipment and evidence preserved"
```

---

### Task 2: Incident page — Sentinel banner and pathway panel

**Files:**
- Create: `resources/js/Components/Incidents/SentinelPathwayPanel.vue`
- Modify: `resources/js/Pages/Incidents/Show.vue` (banner after the header card ~line 126; panel at the top of the Overview `<template v-if="activeTab === 'overview'">` ~line 178)

Style tokens used across the app: card `rounded-xl bg-surface-container-lowest p-space-lg shadow-sm`; section title `font-title-lg text-title-lg text-primary font-bold`; label `font-label-md text-label-md text-on-surface font-semibold`; hint `font-body-sm text-body-sm text-outline`; primary button `px-4 py-2 rounded-lg bg-primary text-on-primary font-label-md text-label-md font-semibold`. Icons are Font Awesome components (`<FontAwesomeIcon icon="..." />`, globally registered; `triangle-exclamation` is already used in Wizard.vue; check `resources/js/app.js` for which icons are registered and register `circle-check` / `circle` there if missing, following the existing pattern).

- [ ] **Step 1: Create `SentinelPathwayPanel.vue`**

```vue
<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { formatDate } from '@/Utils/formatDate';
import { statusLabel } from '@/Composables/useIncidentStatus';

const props = defineProps({
    incident: { type: Object, required: true },
    can: { type: Object, required: true },
});

const confirmForm = useForm({});

function confirmPreserved() {
    confirmForm.post(`/incidents/${props.incident.id}/evidence-preserved`, { preserveScroll: true });
}

const steps = computed(() => {
    const i = props.incident;
    return [
        {
            key: 'response',
            title: 'Immediate patient safety and clinical response',
            detail: 'Takes priority over documentation. Make the patient and area safe first.',
            done: null,
        },
        {
            key: 'evidence',
            title: 'Records, equipment and evidence preserved',
            detail: i.evidence_preserved_at
                ? `Confirmed by ${i.evidence_preserved_by?.name ?? 'a former user'} on ${formatDate(i.evidence_preserved_at)}.`
                : 'Keep the records, equipment and other evidence according to policy. The department Head or Focal Person confirms it here.',
            done: !!i.evidence_preserved_at,
        },
        {
            key: 'leadership',
            title: 'Designated leadership notified',
            detail: 'Patient Safety/CQI, the department\'s leadership and the executives were alerted when the incident was rated Sentinel.',
            done: true,
        },
        {
            key: 'investigation',
            title: 'Formal investigation / RCA assigned',
            detail: i.assigned_investigator ? `Investigator: ${i.assigned_investigator.name}.` : 'Waiting for the Patient Safety/CQI Office to assign an investigator.',
            done: !!i.assigned_investigator,
        },
        {
            key: 'governance',
            title: 'Corrective actions and governance review',
            detail: i.status === 'closed'
                ? 'Corrective actions verified and closure approved by the CQI Committee.'
                : `Current stage: ${statusLabel(i.status)}. Closure needs verified corrective actions and CQI Committee approval.`,
            done: i.status === 'closed',
        },
        {
            key: 'learning',
            title: 'Learning and prevention documented',
            detail: i.lessons_published_at
                ? 'Lessons learned published for all staff.'
                : (i.lessons_learned ? 'Lessons learned recorded; published when the incident closes.' : 'Recorded when the department requests closure.'),
            done: !!i.lessons_learned,
        },
    ];
});
</script>

<template>
    <section class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-space-md border-l-4 border-error">
        <div class="flex flex-col gap-1">
            <h2 class="font-title-lg text-title-lg text-error font-bold">Sentinel Event Pathway</h2>
            <span class="font-body-sm text-body-sm text-outline">The steps every sentinel event must go through.</span>
        </div>

        <ol class="flex flex-col gap-2">
            <li v-for="(step, index) in steps" :key="step.key" class="p-3 rounded-lg bg-surface-container-low flex items-start gap-3">
                <span
                    class="mt-0.5 w-6 h-6 shrink-0 rounded-full flex items-center justify-center font-label-sm text-body-sm font-bold"
                    :class="step.done === true ? 'bg-emerald-600 text-white' : step.done === false ? 'bg-surface-container-high text-on-surface-variant' : 'bg-error text-on-error'"
                    :aria-label="step.done === true ? 'Done' : step.done === false ? 'Not done yet' : 'Guidance'"
                >
                    <template v-if="step.done === true">✓</template>
                    <template v-else>{{ index + 1 }}</template>
                </span>
                <div class="flex flex-col gap-1 flex-1">
                    <span class="font-label-md text-label-md text-on-surface font-semibold">{{ step.title }}</span>
                    <span class="font-body-sm text-body-sm text-on-surface-variant">{{ step.detail }}</span>
                    <button
                        v-if="step.key === 'evidence' && can.confirmEvidencePreserved"
                        type="button"
                        :disabled="confirmForm.processing"
                        class="self-start mt-1 px-4 py-2 rounded-lg bg-primary text-on-primary font-label-md text-label-md font-semibold disabled:opacity-60"
                        @click="confirmPreserved"
                    >
                        Confirm evidence preserved
                    </button>
                </div>
            </li>
        </ol>
    </section>
</template>
```
(Uses plain ✓/numbers rather than icons, so no icon registration is needed.)

- [ ] **Step 2: Banner + panel in `Show.vue`**

Add `import SentinelPathwayPanel from '@/Components/Incidents/SentinelPathwayPanel.vue';` with the other component imports.

Right after the header card's closing `</div>` (the card that holds the badges, headline and "What happened"), add:

```vue
        <div v-if="incident.is_sentinel_event" role="alert" class="rounded-lg bg-error text-on-error p-space-md flex items-start gap-3">
            <FontAwesomeIcon icon="triangle-exclamation" class="mt-1" />
            <div class="flex flex-col gap-0.5">
                <span class="font-title-sm text-title-sm font-bold">Sentinel Event</span>
                <span class="font-body-md text-body-md">Immediate patient safety and clinical response takes priority over documentation. Follow the Sentinel Event Pathway on the Overview tab.</span>
            </div>
        </div>
```

As the first child inside `<template v-if="activeTab === 'overview'">`, add:

```vue
            <SentinelPathwayPanel v-if="incident.is_sentinel_event" :incident="incident" :can="can" />
```

- [ ] **Step 3: Check the data the panel reads is on the `incident` prop** — `assigned_investigator` (loaded as `assignedInvestigator`), `evidence_preserved_by` (Task 1), `lessons_learned`, `lessons_published_at`, `status`, `is_sentinel_event`. Confirm `Incident` has no `$hidden` that strips `lessons_learned` (grep `hidden` in `app/Models/Incident.php`). If `lessons_learned` is hidden, show step 6 from `lessons_published_at` only and say so in the report.

- [ ] **Step 4: Build** — `npx vite build` → `✓ built in`.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Components/Incidents/SentinelPathwayPanel.vue resources/js/Pages/Incidents/Show.vue
git commit -m "feat: sentinel banner and pathway panel on the incident page"
```
(Add `resources/js/app.js` only if you had to register an icon.)

---

### Task 3: Docs

- [ ] Add a short dated section after §9o in `docs/architecture.md`: "§9p Sentinel Event Pathway (2026-10-01)" — the six steps and how each is derived, the two columns, the policy rule (Head/Focal Person of the department, sentinel, not confirmed, not closed), track-only, RCA tools optional. Commit: `docs: sentinel event pathway`.

## Final review

- Whole suite green, build green.
- Browser: a Sentinel incident shows the banner + panel; the Head sees and uses "Confirm evidence preserved", which then shows who/when; a Critical incident shows neither.
