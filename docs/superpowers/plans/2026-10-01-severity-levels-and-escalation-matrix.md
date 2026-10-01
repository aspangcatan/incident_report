# Five Severity Levels & Escalation Matrix Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Split severity Level IV into Critical and Sentinel (five levels, each with its client meaning shown when choosing), and send notifications per the client's Escalation & Notification Matrix.

**Architecture:** `App\Enums\Severity` gains a fifth case plus `meaning()`/`isHighOrAbove()`; a forward data migration renames the old Level IV value. One support class, `App\Support\EscalationRecipients`, answers "who is told" for each trigger. A new `SendSeverityAlert` listener on the existing `IncidentAssessed`/`IncidentReviewed` events replaces `AlertOversightOfHighRiskIncident`. The daily `incidents:check-overdue` command gains due-soon reminders (two new Query classes, new `reminder_sent_at` columns) and sends RCA/CAPA escalations to the matrix recipients. Frontend severity data moves into one `resources/js/Utils/severities.js`.

**Tech Stack:** Laravel 9.52 (PHP 8.2), PHPUnit feature tests with `RefreshDatabase` + `Notification::fake()`, Vue 3 + Inertia, Tailwind 3.

**Spec:** `docs/superpowers/specs/2026-10-01-severity-levels-and-escalation-matrix-design.md`

## Ground rules for every task

- Run `php artisan config:clear` once before running tests. `php artisan test <path>` only runs the FIRST path given — run one path at a time, or the whole suite with no path.
- Commit straight to `master` (no branches). End every commit message with a blank line and `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- **Never stage these two user files:** `app/DataTransferObjects/Investigations/AddTeamMemberData.php` (entire file) and the line `'effectiveness_wait_days' => 0,` in `config/incident_workflow.php` (the user's local test value; the committed value is 30). Task 1 shows exactly how to commit config changes without that line. Never use `git add -A` or `git add .`.
- Users/departments come from the `tdh_user` schema; in tests use `User::factory()->create(['role' => Role::X, 'department_id' => $id])` and `Department::factory()->create()` as existing tests do. Leadership mapping: `DB::table('leadership_departments')->insert(['user_id' => ..., 'department_id' => ...])`.

## File map

| File | Responsibility |
|---|---|
| `app/Enums/Severity.php` (modify) | Five levels, label, numeral, meaning, `isSentinel`, `isHighOrAbove` |
| `database/migrations/2026_10_01_000001_split_critical_and_sentinel_severity.php` (create) | Rename stored `level_4_critical_sentinel` → `level_5_sentinel` |
| `config/incident_workflow.php` (modify) | SLA hours keyed by the five values |
| `app/Services/ApprovalService.php`, `app/Services/IncidentService.php` (modify) | Use `isHighOrAbove()` / `isSentinel()`; pass actor + previous severity to events |
| `app/Support/EscalationRecipients.php` (create) | The matrix: recipients per severity / overdue RCA / overdue CAPA |
| `app/Events/IncidentAssessed.php`, `app/Events/IncidentReviewed.php` (modify) | Carry `actor` (and `previousSeverity` for reviewed) |
| `app/Listeners/SendSeverityAlert.php` (create), `app/Notifications/SeverityAlertNotification.php` (create) | Immediate severity alert |
| `app/Listeners/AlertOversightOfHighRiskIncident.php`, `app/Notifications/HighRiskIncidentNotification.php` (delete) | Replaced |
| `database/migrations/2026_10_01_000002_add_reminder_sent_at_columns.php` (create) | `reminder_sent_at` on investigations + corrective_actions |
| `app/Queries/DueSoonInvestigationsQuery.php`, `app/Queries/DueSoonCorrectiveActionsQuery.php` (create) | Due within a day, not yet reminded |
| `app/Repositories/InvestigationRepository.php`, `app/Repositories/CorrectiveActionRepository.php` (modify) | `markReminded()` |
| `app/Notifications/DeadlineReminderNotification.php` (create) | "Due soon" reminder |
| `app/Console/Commands/CheckOverdueIncidents.php` (modify) | Reminders + matrix escalations |
| `resources/js/Utils/severities.js` (create) | Single frontend source for severity data |
| `resources/js/Composables/useIncidentStatus.js`, `resources/js/Utils/chartPalette.js`, `resources/js/Components/Analytics/MonthlySeverityChart.vue`, `resources/js/Components/Incidents/AssessmentPanel.vue`, `resources/js/Components/Incidents/WorkflowActionsPanel.vue`, `resources/js/Components/Incidents/ApprovalPanel.vue` (modify) | Read from `severities.js`; pickers show meanings |
| `docs/architecture.md` (modify) | Record the change |

---

### Task 1: Five-level Severity enum, data migration, config, backend rules

**Files:**
- Modify: `app/Enums/Severity.php`
- Create: `database/migrations/2026_10_01_000001_split_critical_and_sentinel_severity.php`
- Modify: `config/incident_workflow.php` (three `level_4_critical_sentinel` keys, lines ~21, ~33, ~45)
- Modify: `app/Services/ApprovalService.php` (`needsCommitteeSignOff`, ~line 102)
- Modify: `app/Services/IncidentService.php` (`markReviewed` ~line 113, `completeAssessment` ~line 224)
- Modify tests that use `Severity::Level4CriticalSentinel`: `tests/Feature/Approvals/ApprovalTest.php` (lines ~121, ~563), `tests/Feature/EscalationCommandTest.php` (~34, ~182, ~228, ~309), `tests/Feature/Incidents/CqiTriageTest.php` (~64, ~69), `tests/Feature/Incidents/DepartmentAssessmentTest.php` (~74, ~119), `tests/Feature/Incidents/IncidentReportingTest.php` (~591)
- Create test: `tests/Unit/SeverityTest.php`, `tests/Feature/SeverityMigrationTest.php`

- [ ] **Step 1: Write the failing enum test** — `tests/Unit/SeverityTest.php`

```php
<?php

namespace Tests\Unit;

use App\Enums\Severity;
use PHPUnit\Framework\TestCase;

class SeverityTest extends TestCase
{
    public function test_there_are_five_levels_in_order(): void
    {
        $this->assertSame(
            ['level_1_low', 'level_2_moderate', 'level_3_high', 'level_4_critical', 'level_5_sentinel'],
            array_map(fn (Severity $s) => $s->value, Severity::cases())
        );
    }

    public function test_labels_numerals_and_meanings(): void
    {
        $this->assertSame('Critical', Severity::Level4Critical->label());
        $this->assertSame('Level V', Severity::Level5Sentinel->romanNumeral());
        $this->assertSame('Temporary harm or intervention required', Severity::Level2Moderate->meaning());
        $this->assertSame('Death or serious permanent harm / other agency-defined sentinel event', Severity::Level5Sentinel->meaning());
    }

    public function test_only_level_five_is_sentinel(): void
    {
        $this->assertTrue(Severity::Level5Sentinel->isSentinel());
        $this->assertFalse(Severity::Level4Critical->isSentinel());
    }

    public function test_high_or_above(): void
    {
        $this->assertFalse(Severity::Level1Low->isHighOrAbove());
        $this->assertFalse(Severity::Level2Moderate->isHighOrAbove());
        $this->assertTrue(Severity::Level3High->isHighOrAbove());
        $this->assertTrue(Severity::Level4Critical->isHighOrAbove());
        $this->assertTrue(Severity::Level5Sentinel->isHighOrAbove());
    }
}
```

- [ ] **Step 2: Run it — expect FAIL**

Run: `php artisan test tests/Unit/SeverityTest.php`
Expected: FAIL (`Level4Critical` / `meaning()` undefined).

- [ ] **Step 3: Replace `app/Enums/Severity.php`**

```php
<?php

namespace App\Enums;

/**
 * The client's five-level Risk Triage scale (2026-10-01). meaning() is the
 * client's "Indicative Meaning" and is shown wherever a level is chosen.
 */
enum Severity: string
{
    case Level1Low = 'level_1_low';
    case Level2Moderate = 'level_2_moderate';
    case Level3High = 'level_3_high';
    case Level4Critical = 'level_4_critical';
    case Level5Sentinel = 'level_5_sentinel';

    public function label(): string
    {
        return match ($this) {
            self::Level1Low => 'Low',
            self::Level2Moderate => 'Moderate',
            self::Level3High => 'High',
            self::Level4Critical => 'Critical',
            self::Level5Sentinel => 'Sentinel',
        };
    }

    public function romanNumeral(): string
    {
        return match ($this) {
            self::Level1Low => 'Level I',
            self::Level2Moderate => 'Level II',
            self::Level3High => 'Level III',
            self::Level4Critical => 'Level IV',
            self::Level5Sentinel => 'Level V',
        };
    }

    public function meaning(): string
    {
        return match ($this) {
            self::Level1Low => 'Near miss / no harm or low-risk event',
            self::Level2Moderate => 'Temporary harm or intervention required',
            self::Level3High => 'Significant harm, prolonged hospitalization or high-risk event',
            self::Level4Critical => 'Permanent or life-threatening harm',
            self::Level5Sentinel => 'Death or serious permanent harm / other agency-defined sentinel event',
        };
    }

    public function isSentinel(): bool
    {
        return $this === self::Level5Sentinel;
    }

    /** High, Critical and Sentinel: formal investigation required, CQI Committee closure sign-off. */
    public function isHighOrAbove(): bool
    {
        return in_array($this, [self::Level3High, self::Level4Critical, self::Level5Sentinel], true);
    }
}
```

- [ ] **Step 4: Run the enum test — expect PASS**

Run: `php artisan test tests/Unit/SeverityTest.php` → PASS (4 tests).

- [ ] **Step 5: Use the helpers in services**

`app/Services/ApprovalService.php` — replace the body of `needsCommitteeSignOff`:

```php
    private function needsCommitteeSignOff(Incident $incident): bool
    {
        return $incident->severity?->isHighOrAbove() ?? false;
    }
```

`app/Services/IncidentService.php` — in `markReviewed` replace
`$incident->is_sentinel_event = $severity === Severity::Level4CriticalSentinel;` with
`$incident->is_sentinel_event = $severity->isSentinel();`
and in `completeAssessment` replace
`$incident->is_sentinel_event = $incident->severity === Severity::Level4CriticalSentinel;` with
`$incident->is_sentinel_event = $incident->severity?->isSentinel() ?? false;`
If `use App\Enums\Severity;` is still referenced elsewhere in the file keep it; otherwise remove the unused import.

`IncidentPolicy::skipInvestigation` (Low/Moderate only) needs no change.

- [ ] **Step 6: Config keys** — in `config/incident_workflow.php`, in each of `review_sla_hours`, `investigation_sla_hours` and `approval_sla_hours`, replace the single `'level_4_critical_sentinel' => N,` line with two lines carrying the same N:

```php
        'level_4_critical' => 24,
        'level_5_sentinel' => 24,
```
(`review_sla_hours`: 24/24, `investigation_sla_hours`: 72/72, `approval_sla_hours`: 24/24.) Do **not** touch `'effectiveness_wait_days' => 0,`.

- [ ] **Step 7: Write the failing migration test** — `tests/Feature/SeverityMigrationTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\IncidentType;
use App\Models\User;
use App\Services\IncidentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SeverityMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_critical_sentinel_rows_become_sentinel(): void
    {
        $incident = app(IncidentService::class)->createDraft(User::factory()->create(), [
            'department_id' => Department::factory()->create()->id,
            'incident_type_ids' => [IncidentType::factory()->create()->id],
            'occurred_at' => now(),
            'location' => 'ICU',
            'summary' => 'Legacy level.',
        ]);
        $type = IncidentType::factory()->create();
        DB::table('incidents')->where('id', $incident->id)->update(['severity' => 'level_4_critical_sentinel']);
        DB::table('incident_types')->where('id', $type->id)->update(['default_severity' => 'level_4_critical_sentinel']);

        $migration = require database_path('migrations/2026_10_01_000001_split_critical_and_sentinel_severity.php');
        $migration->up();

        $this->assertSame('level_5_sentinel', DB::table('incidents')->where('id', $incident->id)->value('severity'));
        $this->assertSame('level_5_sentinel', DB::table('incident_types')->where('id', $type->id)->value('default_severity'));
    }
}
```

- [ ] **Step 8: Run it — expect FAIL** (migration file missing)

Run: `php artisan test tests/Feature/SeverityMigrationTest.php`

- [ ] **Step 9: Create the migration** — `database/migrations/2026_10_01_000001_split_critical_and_sentinel_severity.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Level IV "Critical / Sentinel" splits into IV Critical and V Sentinel; existing rows become Sentinel (user's decision). */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('incidents')->where('severity', 'level_4_critical_sentinel')->update(['severity' => 'level_5_sentinel']);
        DB::table('incident_types')->where('default_severity', 'level_4_critical_sentinel')->update(['default_severity' => 'level_5_sentinel']);
    }

    public function down(): void
    {
        DB::table('incidents')->whereIn('severity', ['level_4_critical', 'level_5_sentinel'])->update(['severity' => 'level_4_critical_sentinel']);
        DB::table('incident_types')->whereIn('default_severity', ['level_4_critical', 'level_5_sentinel'])->update(['default_severity' => 'level_4_critical_sentinel']);
    }
};
```

- [ ] **Step 10: Run it — expect PASS**

- [ ] **Step 11: Update existing tests to the new cases**

In every test file listed under **Files**, replace `Severity::Level4CriticalSentinel` with `Severity::Level5Sentinel` (they test sentinel / highest-level behaviour). In `tests/Feature/Approvals/ApprovalTest.php` `test_due_at_uses_the_sla_hours_configured_for_the_incidents_own_severity`, make `$cases` all five:

```php
        $cases = [
            Severity::Level1Low,
            Severity::Level2Moderate,
            Severity::Level3High,
            Severity::Level4Critical,
            Severity::Level5Sentinel,
        ];
```

Add one test to `tests/Feature/Approvals/ApprovalTest.php` next to `test_the_committee_can_return_a_high_risk_closure_to_the_department`, proving Critical also needs the Committee (reuse that test's helpers `incidentReadyForApproval`, `headOf`):

```php
    public function test_a_critical_closure_also_needs_the_committee(): void
    {
        $incident = $this->incidentReadyForApproval(Severity::Level4Critical);
        $first = app(ApprovalService::class)->requestApproval($incident, $this->headOf($incident));
        app(ApprovalService::class)->approve($first, User::factory()->create(['role' => Role::QualitySafetyOfficer]), DecideApprovalData::fromArray(['comments' => 'OK.']));

        $this->assertSame(IncidentStatus::ForApproval, $incident->fresh()->status);
        $this->assertSame(2, $incident->approvals()->count());
    }
```

Note: `CqiTriageTest::test_high_risk_triage_alerts_executives_committee_and_the_departments_leadership` still references `HighRiskIncidentNotification`; leave it — Task 4 rewrites it.

- [ ] **Step 12: Run the whole suite — expect all PASS**

Run: `php artisan config:clear` then `php artisan test`
Expected: all green (≈ 410 + new tests).

- [ ] **Step 13: Commit (config without the user's line)**

```bash
git add app/Enums/Severity.php database/migrations/2026_10_01_000001_split_critical_and_sentinel_severity.php app/Services/ApprovalService.php app/Services/IncidentService.php tests/Unit/SeverityTest.php tests/Feature/SeverityMigrationTest.php tests/Feature/Approvals/ApprovalTest.php tests/Feature/EscalationCommandTest.php tests/Feature/Incidents/CqiTriageTest.php tests/Feature/Incidents/DepartmentAssessmentTest.php tests/Feature/Incidents/IncidentReportingTest.php
git diff config/incident_workflow.php > C:/tmp/cfg.patch
python - <<'EOF'
import re
s = open('C:/tmp/cfg.patch', encoding='utf-8').read()
head, *hunks = re.split(r'(?m)^(?=@@ )', s)
keep = [h for h in hunks if 'effectiveness_wait_days' not in h]
open('C:/tmp/cfg_mine.patch', 'w', encoding='utf-8', newline='\n').write(head + ''.join(keep))
EOF
git apply --cached --recount C:/tmp/cfg_mine.patch
git diff --cached config/incident_workflow.php   # must NOT show effectiveness_wait_days
git commit -m "feat: five severity levels - Critical and Sentinel split, with meanings"
git diff config/incident_workflow.php            # must still show only the user's 0-day line
```
If the severity hunks and the `effectiveness_wait_days` line land in the same hunk (they shouldn't — they're ~10 lines apart), stop and report instead of committing.

---

### Task 2: Frontend — one severity source, pickers show meanings

**Files:**
- Create: `resources/js/Utils/severities.js`
- Modify: `resources/js/Composables/useIncidentStatus.js` (lines 29-41, 51-57)
- Modify: `resources/js/Utils/chartPalette.js` (lines 24-38)
- Modify: `resources/js/Components/Analytics/MonthlySeverityChart.vue` (imports, lines 3-4)
- Modify: `resources/js/Components/Incidents/AssessmentPanel.vue` (lines 15-20 list; severity cards ~100-111)
- Modify: `resources/js/Components/Incidents/WorkflowActionsPanel.vue` (lines 12-17 list; triage block ~85-98)
- Modify: `resources/js/Components/Incidents/ApprovalPanel.vue` (~line 205 button label)

No JS test runner exists in this project; verification is `npx vite build` plus a grep that no old value remains.

- [ ] **Step 1: Create `resources/js/Utils/severities.js`**

```js
/**
 * The client's five-level Risk Triage scale - the single frontend source.
 * Must match App\Enums\Severity (value, label, numeral, meaning).
 * chart: one blue hue, light -> dark (steps of the sequential ramp in chartPalette.js).
 */
export const SEVERITIES = [
    { value: 'level_1_low', numeral: 'Level I', label: 'Low', meaning: 'Near miss / no harm or low-risk event', badge: 'bg-surface-container text-on-surface-variant', chart: '#b7d3f6' },
    { value: 'level_2_moderate', numeral: 'Level II', label: 'Moderate', meaning: 'Temporary harm or intervention required', badge: 'bg-amber-50 text-amber-900', chart: '#6da7ec' },
    { value: 'level_3_high', numeral: 'Level III', label: 'High', meaning: 'Significant harm, prolonged hospitalization or high-risk event', badge: 'bg-amber-200 text-amber-950', chart: '#2a78d6' },
    { value: 'level_4_critical', numeral: 'Level IV', label: 'Critical', meaning: 'Permanent or life-threatening harm', badge: 'bg-error-container text-on-error-container', chart: '#184f95' },
    { value: 'level_5_sentinel', numeral: 'Level V', label: 'Sentinel', meaning: 'Death or serious permanent harm / other agency-defined sentinel event', badge: 'bg-error text-on-error', chart: '#0d366b' },
];

export const severityOrder = SEVERITIES.map((severity) => severity.value);

export const HIGH_OR_ABOVE = ['level_3_high', 'level_4_critical', 'level_5_sentinel'];

export function findSeverity(value) {
    return SEVERITIES.find((severity) => severity.value === value) ?? null;
}
```

- [ ] **Step 2: `useIncidentStatus.js`** — delete the `SEVERITY_LABELS` and `SEVERITY_CLASSES` constants, add `import { findSeverity } from '@/Utils/severities';` at the top, and replace the two severity functions:

```js
export function severityLabel(severity) {
    const found = findSeverity(severity);
    return found ? `${found.numeral} – ${found.label}` : severity;
}

export function severityBadgeClasses(severity) {
    return findSeverity(severity)?.badge ?? 'bg-surface-container text-on-surface-variant';
}
```

- [ ] **Step 3: `chartPalette.js`** — replace the `severityRamp` / `severityOrder` block (keep the comment, update it to "five steps") with:

```js
import { SEVERITIES, severityOrder as orderedSeverities } from '@/Utils/severities';

export const severityRamp = {
    ...Object.fromEntries(SEVERITIES.map((severity) => [severity.value, severity.chart])),
    unassessed: '#c5c5d3',
};

export const severityOrder = orderedSeverities;
```
Put the `import` at the top of the file with any other imports. `MonthlySeverityChart.vue` keeps importing `severityOrder, severityRamp` from `chartPalette` — no change needed there unless the build complains.

- [ ] **Step 4: `AssessmentPanel.vue`** — replace the local `severities` array (lines 15-20) with `import { SEVERITIES as severities } from '@/Utils/severities';` (add to the imports), and change the severity card grid + card body to five cards with the meaning:

```vue
            <div v-if="editable && can.completeAssessment" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-2">
                <label
                    v-for="option in severities"
                    :key="option.value"
                    class="p-3 rounded-lg cursor-pointer flex flex-col gap-1"
                    :class="form.severity === option.value ? 'bg-amber-50 ring-2 ring-amber-500' : 'bg-surface-container-low hover:bg-surface-container'"
                >
                    <input v-model="form.severity" type="radio" :value="option.value" class="hidden" />
                    <span class="block font-label-sm text-body-sm text-outline">{{ option.numeral }}</span>
                    <span class="block font-body-md text-body-md text-on-surface font-semibold">{{ option.label }}</span>
                    <span class="block font-body-sm text-body-sm text-on-surface-variant">{{ option.meaning }}</span>
                </label>
            </div>
```
Directly under the "Severity" label span add a hint:
`<span v-if="editable && can.completeAssessment" class="font-body-sm text-body-sm text-outline">Choose the level that best matches the harm.</span>`

- [ ] **Step 5: `WorkflowActionsPanel.vue`** — replace the local `severities` array with `import { SEVERITIES as severities } from '@/Utils/severities';`. Replace the `<select id="triage_severity">…</select>` and its `<label for="triage_severity">` with a radio list in a fieldset:

```vue
        <fieldset class="flex flex-col gap-1.5">
            <legend class="font-label-md text-label-md text-on-surface font-semibold">Severity *</legend>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-2 mt-1">
                <label
                    v-for="option in severities"
                    :key="option.value"
                    class="p-3 rounded-lg cursor-pointer flex flex-col gap-1"
                    :class="reviewForm.severity === option.value ? 'bg-amber-50 ring-2 ring-amber-500' : 'bg-surface-container-low hover:bg-surface-container'"
                >
                    <input v-model="reviewForm.severity" type="radio" name="triage_severity" :value="option.value" class="sr-only" />
                    <span class="font-label-sm text-body-sm text-outline">{{ option.numeral }}</span>
                    <span class="font-body-md text-body-md text-on-surface font-semibold">{{ option.label }}</span>
                    <span class="font-body-sm text-body-sm text-on-surface-variant">{{ option.meaning }}</span>
                </label>
            </div>
            <span v-if="severityChanged" class="font-body-sm text-body-sm text-amber-900">This changes the severity the department set.</span>
            <span v-if="reviewForm.errors.severity" class="font-body-sm text-body-sm text-error">{{ reviewForm.errors.severity }}</span>
        </fieldset>
```
Change the triage intro paragraph to:
`Confirm the department's classification or change it. Changing it alerts the people the new level requires.`

- [ ] **Step 6: `ApprovalPanel.vue`** — add `import { HIGH_OR_ABOVE } from '@/Utils/severities';` and replace
`['level_3_high', 'level_4_critical_sentinel'].includes(incident.severity)` with `HIGH_OR_ABOVE.includes(incident.severity)`.

- [ ] **Step 7: Verify no old value remains and the build passes**

Run: `grep -rn "level_4_critical_sentinel\|Low Risk\|High Severity" resources/js` → no output.
Run: `npx vite build` → ends with `✓ built in`.

- [ ] **Step 8: Commit**

```bash
git add resources/js/Utils/severities.js resources/js/Composables/useIncidentStatus.js resources/js/Utils/chartPalette.js resources/js/Components/Analytics/MonthlySeverityChart.vue resources/js/Components/Incidents/AssessmentPanel.vue resources/js/Components/Incidents/WorkflowActionsPanel.vue resources/js/Components/Incidents/ApprovalPanel.vue
git commit -m "feat: severity pickers show five levels with their meanings"
```
(Only add `MonthlySeverityChart.vue` if you changed it.)

---

### Task 3: EscalationRecipients — the matrix in one place

**Files:**
- Create: `app/Support/EscalationRecipients.php`
- Test: `tests/Feature/EscalationRecipientsTest.php`

- [ ] **Step 1: Write the failing tests** — `tests/Feature/EscalationRecipientsTest.php`

```php
<?php

namespace Tests\Feature;

use App\Enums\CorrectiveActionPriority;
use App\Enums\Role;
use App\Enums\Severity;
use App\Models\CorrectiveAction;
use App\Models\Department;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\Investigation;
use App\Models\User;
use App\Services\IncidentService;
use App\Support\EscalationRecipients;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EscalationRecipientsTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;
    private User $head;
    private User $cqi;
    private User $leader;
    private User $executive;
    private User $committee;
    private User $otherHead;
    private User $otherLeader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->department = Department::factory()->create();
        $other = Department::factory()->create();
        $this->head = User::factory()->create(['role' => Role::DepartmentHead, 'department_id' => $this->department->id]);
        $this->cqi = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $this->leader = User::factory()->create(['role' => Role::Leadership]);
        DB::table('leadership_departments')->insert(['user_id' => $this->leader->id, 'department_id' => $this->department->id]);
        $this->executive = User::factory()->create(['role' => Role::Management]);
        $this->committee = User::factory()->create(['role' => Role::CqiCommittee]);
        $this->otherHead = User::factory()->create(['role' => Role::DepartmentHead, 'department_id' => $other->id]);
        $this->otherLeader = User::factory()->create(['role' => Role::Leadership]);
    }

    private function incident(?Severity $severity): Incident
    {
        $incident = app(IncidentService::class)->createDraft(User::factory()->create(), [
            'department_id' => $this->department->id,
            'incident_type_ids' => [IncidentType::factory()->create()->id],
            'occurred_at' => now(),
            'location' => 'Ward 3',
            'summary' => 'Matrix test.',
        ]);
        $incident->forceFill(['severity' => $severity])->save();

        return $incident->fresh();
    }

    private function ids($users): array
    {
        return $users->pluck('id')->sort()->values()->all();
    }

    private function expect(array $users): array
    {
        return collect($users)->pluck('id')->sort()->values()->all();
    }

    public function test_low_alerts_nobody(): void
    {
        $this->assertSame([], $this->ids(EscalationRecipients::forSeverity($this->incident(Severity::Level1Low))));
        $this->assertSame([], $this->ids(EscalationRecipients::forSeverity($this->incident(null))));
    }

    public function test_moderate_alerts_the_department_head_and_cqi(): void
    {
        $this->assertSame(
            $this->expect([$this->head, $this->cqi]),
            $this->ids(EscalationRecipients::forSeverity($this->incident(Severity::Level2Moderate)))
        );
    }

    public function test_high_adds_the_mapped_leadership(): void
    {
        $this->assertSame(
            $this->expect([$this->head, $this->cqi, $this->leader]),
            $this->ids(EscalationRecipients::forSeverity($this->incident(Severity::Level3High)))
        );
    }

    public function test_critical_and_sentinel_alert_cqi_leadership_and_executives(): void
    {
        foreach ([Severity::Level4Critical, Severity::Level5Sentinel] as $severity) {
            $this->assertSame(
                $this->expect([$this->cqi, $this->leader, $this->executive]),
                $this->ids(EscalationRecipients::forSeverity($this->incident($severity))),
                $severity->value
            );
        }
    }

    public function test_overdue_investigation_goes_to_the_lead_the_head_and_cqi(): void
    {
        $lead = User::factory()->create(['department_id' => $this->department->id]);
        $investigation = new Investigation();
        $investigation->setRelation('incident', $this->incident(Severity::Level2Moderate));
        $investigation->setRelation('leadInvestigator', $lead);

        $this->assertSame(
            $this->expect([$lead, $this->head, $this->cqi]),
            $this->ids(EscalationRecipients::forOverdueInvestigation($investigation))
        );
    }

    public function test_overdue_capa_goes_to_the_owner_the_head_and_cqi_and_critical_adds_executives(): void
    {
        $owner = User::factory()->create(['department_id' => $this->department->id]);
        $capa = new CorrectiveAction(['priority' => CorrectiveActionPriority::High]);
        $capa->setRelation('incident', $this->incident(Severity::Level2Moderate));
        $capa->setRelation('responsibleUser', $owner);

        $this->assertSame($this->expect([$owner, $this->head, $this->cqi]), $this->ids(EscalationRecipients::forOverdueCorrectiveAction($capa)));

        $capa->priority = CorrectiveActionPriority::Critical;
        $this->assertSame($this->expect([$owner, $this->head, $this->cqi, $this->executive]), $this->ids(EscalationRecipients::forOverdueCorrectiveAction($capa)));
    }

    public function test_a_missing_owner_is_skipped_and_people_are_not_listed_twice(): void
    {
        $capa = new CorrectiveAction(['priority' => CorrectiveActionPriority::Medium]);
        $capa->setRelation('incident', $this->incident(Severity::Level2Moderate));
        $capa->setRelation('responsibleUser', $this->head); // the head owns the action

        $this->assertSame($this->expect([$this->head, $this->cqi]), $this->ids(EscalationRecipients::forOverdueCorrectiveAction($capa)));

        $capa->setRelation('responsibleUser', null); // hard-deleted tdh user
        $this->assertSame($this->expect([$this->head, $this->cqi]), $this->ids(EscalationRecipients::forOverdueCorrectiveAction($capa)));
    }
}
```

- [ ] **Step 2: Run — expect FAIL** (`EscalationRecipients` not found)

Run: `php artisan test tests/Feature/EscalationRecipientsTest.php`

- [ ] **Step 3: Create `app/Support/EscalationRecipients.php`**

```php
<?php

namespace App\Support;

use App\Enums\CorrectiveActionPriority;
use App\Enums\Role;
use App\Enums\Severity;
use App\Models\CorrectiveAction;
use App\Models\Incident;
use App\Models\Investigation;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The client's Escalation & Notification Matrix (2026-10-01): who is told for
 * each severity and for overdue RCA/CAPA. Callers exclude the actor themselves.
 */
final class EscalationRecipients
{
    public static function forSeverity(Incident $incident): Collection
    {
        return self::merge(match ($incident->severity) {
            Severity::Level2Moderate => [IncidentReviewers::departmentHeads($incident), self::cqiOffice()],
            Severity::Level3High => [IncidentReviewers::departmentHeads($incident), self::cqiOffice(), self::leadership($incident)],
            Severity::Level4Critical, Severity::Level5Sentinel => [self::cqiOffice(), self::leadership($incident), self::executives()],
            default => [],
        });
    }

    public static function forOverdueInvestigation(Investigation $investigation): Collection
    {
        return self::merge([
            self::only($investigation->leadInvestigator),
            IncidentReviewers::departmentHeads($investigation->incident),
            self::cqiOffice(),
        ]);
    }

    public static function forOverdueCorrectiveAction(CorrectiveAction $correctiveAction): Collection
    {
        $groups = [
            self::only($correctiveAction->responsibleUser),
            IncidentReviewers::departmentHeads($correctiveAction->incident),
            self::cqiOffice(),
        ];

        if ($correctiveAction->priority === CorrectiveActionPriority::Critical) {
            $groups[] = self::executives();
        }

        return self::merge($groups);
    }

    private static function cqiOffice(): Collection
    {
        return User::active()->withRole(Role::QualitySafetyOfficer)->get();
    }

    private static function executives(): Collection
    {
        return User::active()->withRole(Role::Management)->get();
    }

    /** Medical/Nursing/Ancillary Leadership mapped to the incident's department (Admin → Leadership). */
    private static function leadership(Incident $incident): Collection
    {
        if ($incident->department_id === null) {
            return new Collection();
        }

        $ids = DB::table('leadership_departments')->where('department_id', $incident->department_id)->pluck('user_id');

        return User::active()->withRole(Role::Leadership)->whereIn('id', $ids)->get();
    }

    /** tdh_user hard-deletes users, so a stale id resolves to null. */
    private static function only(?User $user): Collection
    {
        return $user !== null && $user->is_active ? new Collection([$user]) : new Collection();
    }

    /** @param  array<Collection>  $groups */
    private static function merge(array $groups): Collection
    {
        return (new Collection(array_merge(...array_map(fn (Collection $group) => $group->all(), [new Collection(), ...$groups]))))
            ->unique('id')
            ->values();
    }
}
```

- [ ] **Step 4: Run — expect PASS** (7 tests). If `withRole(Role::Leadership)->whereIn('id', …)` ambiguity errors appear, qualify as `whereIn((new User())->qualifyColumn('id'), $ids)` — the existing `AlertOversightOfHighRiskIncident` uses plain `whereIn('id', …)` successfully, so it should not.

- [ ] **Step 5: Commit**

```bash
git add app/Support/EscalationRecipients.php tests/Feature/EscalationRecipientsTest.php
git commit -m "feat: EscalationRecipients holds the client's notification matrix"
```

---

### Task 4: Immediate severity alerts

**Files:**
- Modify: `app/Events/IncidentAssessed.php`, `app/Events/IncidentReviewed.php`
- Modify: `app/Services/IncidentService.php` (`markReviewed`, `completeAssessment`)
- Create: `app/Notifications/SeverityAlertNotification.php`, `app/Listeners/SendSeverityAlert.php`
- Delete: `app/Listeners/AlertOversightOfHighRiskIncident.php`, `app/Notifications/HighRiskIncidentNotification.php`
- Modify: `app/Providers/EventServiceProvider.php` (listener map, lines ~24-36)
- Test: `tests/Feature/SeverityAlertTest.php`; modify `tests/Feature/Incidents/CqiTriageTest.php` (remove the two `HighRiskIncidentNotification` tests + import)

- [ ] **Step 1: Write the failing tests** — `tests/Feature/SeverityAlertTest.php`

```php
<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\Severity;
use App\Models\Department;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\User;
use App\Notifications\SeverityAlertNotification;
use App\Services\IncidentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SeverityAlertTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;
    private User $head;
    private User $cqi;
    private User $leader;
    private User $executive;
    private User $committee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->department = Department::factory()->create();
        $this->head = User::factory()->create(['role' => Role::DepartmentHead, 'department_id' => $this->department->id]);
        $this->cqi = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $this->leader = User::factory()->create(['role' => Role::Leadership]);
        DB::table('leadership_departments')->insert(['user_id' => $this->leader->id, 'department_id' => $this->department->id]);
        $this->executive = User::factory()->create(['role' => Role::Management]);
        $this->committee = User::factory()->create(['role' => Role::CqiCommittee]);
    }

    private function assessedBy(User $assessor, Severity $severity): Incident
    {
        $incident = app(IncidentService::class)->createDraft(User::factory()->create(), [
            'department_id' => $this->department->id,
            'incident_type_ids' => [IncidentType::factory()->create()->id],
            'occurred_at' => now(),
            'location' => 'Ward 3',
            'summary' => 'Alert test.',
        ]);
        app(IncidentService::class)->submit($incident);
        app(IncidentService::class)->saveAssessment($incident->fresh(), ['severity' => $severity->value]);
        app(IncidentService::class)->completeAssessment($incident->fresh(), $assessor);

        return $incident->fresh();
    }

    public function test_a_moderate_assessment_alerts_cqi_but_not_the_head_who_set_it(): void
    {
        Notification::fake();
        $this->assessedBy($this->head, Severity::Level2Moderate);

        Notification::assertSentTo($this->cqi, SeverityAlertNotification::class);
        Notification::assertNotSentTo([$this->head, $this->leader, $this->executive], SeverityAlertNotification::class);
    }

    public function test_a_high_assessment_alerts_cqi_and_the_mapped_leadership(): void
    {
        Notification::fake();
        $this->assessedBy($this->head, Severity::Level3High);

        Notification::assertSentTo([$this->cqi, $this->leader], SeverityAlertNotification::class);
        Notification::assertNotSentTo([$this->head, $this->executive, $this->committee], SeverityAlertNotification::class);
    }

    public function test_a_sentinel_assessment_alerts_cqi_leadership_and_executives_not_the_committee(): void
    {
        Notification::fake();
        $this->assessedBy($this->head, Severity::Level5Sentinel);

        Notification::assertSentTo([$this->cqi, $this->leader, $this->executive], SeverityAlertNotification::class);
        Notification::assertNotSentTo([$this->head, $this->committee], SeverityAlertNotification::class);
    }

    public function test_a_low_assessment_alerts_nobody(): void
    {
        Notification::fake();
        $this->assessedBy($this->head, Severity::Level1Low);

        // The CQI Office still gets its "ready for triage" notice, just no severity alert.
        Notification::assertNothingSentTo([$this->leader, $this->executive]);
        Notification::assertNotSentTo($this->cqi, SeverityAlertNotification::class);
    }

    public function test_triage_that_keeps_the_level_sends_no_second_alert(): void
    {
        $incident = $this->assessedBy($this->head, Severity::Level3High);
        Notification::fake();

        app(IncidentService::class)->markReviewed($incident, $this->cqi, null, Severity::Level3High);

        Notification::assertNotSentTo([$this->leader, $this->head, $this->executive], SeverityAlertNotification::class);
    }

    public function test_triage_that_changes_the_level_alerts_the_new_levels_people_but_not_the_cqi_actor(): void
    {
        $incident = $this->assessedBy($this->head, Severity::Level2Moderate);
        Notification::fake();

        app(IncidentService::class)->markReviewed($incident, $this->cqi, null, Severity::Level4Critical);

        Notification::assertSentTo([$this->leader, $this->executive], SeverityAlertNotification::class);
        Notification::assertNotSentTo([$this->cqi, $this->head], SeverityAlertNotification::class);
    }

    public function test_the_message_names_the_level(): void
    {
        Notification::fake();
        $incident = $this->assessedBy($this->head, Severity::Level4Critical);

        Notification::assertSentTo($this->cqi, SeverityAlertNotification::class, function ($notification) use ($incident) {
            $data = $notification->toDatabase($this->cqi);

            return $data['incident_id'] === $incident->id && str_contains($data['message'], 'Level IV – Critical');
        });
    }
}
```

`assertNothingSentTo` exists in this project's Laravel 9 `NotificationFake` (verified).

- [ ] **Step 2: Run — expect FAIL** (`SeverityAlertNotification` not found)

Run: `php artisan test tests/Feature/SeverityAlertTest.php`

- [ ] **Step 3: Events carry the actor (and the previous level)**

`app/Events/IncidentAssessed.php`:

```php
<?php

namespace App\Events;

use App\Models\Incident;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

class IncidentAssessed
{
    use Dispatchable;

    public function __construct(public Incident $incident, public ?User $actor = null)
    {
    }
}
```

`app/Events/IncidentReviewed.php`:

```php
<?php

namespace App\Events;

use App\Enums\Severity;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

class IncidentReviewed
{
    use Dispatchable;

    /** $previousSeverity: the level before triage, so a severity alert fires only when the CQI Office changed it. */
    public function __construct(public Incident $incident, public ?User $actor = null, public ?Severity $previousSeverity = null)
    {
    }
}
```
(Keep whatever `use` lines the originals had for `Dispatchable`; check the original files' imports first.)

- [ ] **Step 4: Pass them from `IncidentService`**

In `markReviewed`, capture the level before the transaction and pass it:

```php
    public function markReviewed(Incident $incident, User $reviewer, ?string $comments, ?Severity $severity = null): Incident
    {
        $previousSeverity = $incident->severity;

        DB::transaction(function () use ($incident, $reviewer, $comments, $severity) {
            // ... unchanged ...
        });

        IncidentReviewed::dispatch($incident, $reviewer, $previousSeverity);
```

In `completeAssessment` change the dispatch to `IncidentAssessed::dispatch($incident, $assessor);`.

- [ ] **Step 5: Create `app/Notifications/SeverityAlertNotification.php`**

```php
<?php

namespace App\Notifications;

use App\Models\Incident;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** Immediate alert for Moderate and above, per the client's Escalation & Notification Matrix. */
class SeverityAlertNotification extends Notification
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
        $severity = $this->incident->severity;
        $department = $this->incident->department?->name ?? 'an unknown department';
        $number = $this->incident->incident_number ?? "#{$this->incident->id}";

        return [
            'incident_id' => $this->incident->id,
            'incident_number' => $this->incident->incident_number,
            'message' => "{$severity->label()} incident: {$number} ({$department}) was rated {$severity->romanNumeral()} – {$severity->label()}.",
        ];
    }
}
```

- [ ] **Step 6: Create `app/Listeners/SendSeverityAlert.php`**

```php
<?php

namespace App\Listeners;

use App\Events\IncidentAssessed;
use App\Events\IncidentReviewed;
use App\Models\User;
use App\Notifications\SeverityAlertNotification;
use App\Support\EscalationRecipients;
use Illuminate\Support\Facades\Notification;

/** Alerts the people the severity level requires, as soon as it is set (assessment) or changed (CQI triage). */
class SendSeverityAlert
{
    public function handle(IncidentAssessed|IncidentReviewed $event): void
    {
        $incident = $event->incident;

        if ($incident->severity === null) {
            return;
        }

        if ($event instanceof IncidentReviewed && $event->previousSeverity === $incident->severity) {
            return;
        }

        $recipients = EscalationRecipients::forSeverity($incident)
            ->reject(fn (User $user) => $event->actor !== null && $user->id === $event->actor->id);

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new SeverityAlertNotification($incident));
        }
    }
}
```

- [ ] **Step 7: Wire it, remove the old listener/notification**

In `app/Providers/EventServiceProvider.php` replace `\App\Listeners\AlertOversightOfHighRiskIncident::class,` with `\App\Listeners\SendSeverityAlert::class,` under `IncidentReviewed`, and add `\App\Listeners\SendSeverityAlert::class,` under `IncidentAssessed` (after `NotifyReviewersOfAssessedIncident`).

```bash
git rm app/Listeners/AlertOversightOfHighRiskIncident.php app/Notifications/HighRiskIncidentNotification.php
```
Then `grep -rn "HighRiskIncident\|AlertOversight" app tests resources` → only `tests/Feature/Incidents/CqiTriageTest.php` should match.

- [ ] **Step 8: Update `CqiTriageTest.php`** — delete `test_high_risk_triage_alerts_executives_committee_and_the_departments_leadership` and `test_low_or_moderate_triage_sends_no_high_risk_alert` (now covered by `SeverityAlertTest`) and the `use App\Notifications\HighRiskIncidentNotification;` line. Remove the `DB` / `Notification` imports only if nothing else in the file uses them.

- [ ] **Step 9: Run — expect PASS**

Run: `php artisan test tests/Feature/SeverityAlertTest.php` → 7 pass.
Run: `php artisan test` → whole suite green.

- [ ] **Step 10: Commit**

```bash
git add app/Events/IncidentAssessed.php app/Events/IncidentReviewed.php app/Services/IncidentService.php app/Notifications/SeverityAlertNotification.php app/Listeners/SendSeverityAlert.php app/Providers/EventServiceProvider.php tests/Feature/SeverityAlertTest.php tests/Feature/Incidents/CqiTriageTest.php
git commit -m "feat: immediate severity alerts per the escalation matrix"
```
(The `git rm` in Step 7 already staged the deletions.)

---

### Task 5: Reminder columns, due-soon queries, reminder notification

**Files:**
- Create: `database/migrations/2026_10_01_000002_add_reminder_sent_at_columns.php`
- Create: `app/Queries/DueSoonInvestigationsQuery.php`, `app/Queries/DueSoonCorrectiveActionsQuery.php`
- Modify: `app/Repositories/InvestigationRepository.php`, `app/Repositories/CorrectiveActionRepository.php` (add `markReminded`)
- Modify: `app/Models/Investigation.php`, `app/Models/CorrectiveAction.php` (add `'reminder_sent_at' => 'datetime'` to `$casts`)
- Create: `app/Notifications/DeadlineReminderNotification.php`
- Test: `tests/Feature/DueSoonQueriesTest.php`

- [ ] **Step 1: Write the failing tests** — `tests/Feature/DueSoonQueriesTest.php`. Build the investigation/CAPA the same way `tests/Feature/CorrectiveActions/CorrectiveActionEscalationTest.php::incidentReadyForCapa()` does (copy that helper into this test as `incidentReadyForCapa()`, and a smaller `startedInvestigation(Carbon $target)` that stops after `InvestigationService::start` with `'target_completion_at' => $target->toDateTimeString()`).

```php
    public function test_an_investigation_due_within_a_day_is_due_soon_until_reminded(): void
    {
        $soon = $this->startedInvestigation(now()->addHours(12));
        $later = $this->startedInvestigation(now()->addDays(3));
        $overdue = $this->startedInvestigation(now()->subHour());

        $ids = app(DueSoonInvestigationsQuery::class)->get()->pluck('id')->all();
        $this->assertSame([$soon->id], $ids);

        app(InvestigationRepository::class)->markReminded($soon);
        $this->assertSame([], app(DueSoonInvestigationsQuery::class)->get()->pluck('id')->all());
    }

    public function test_a_capa_due_tomorrow_and_not_done_is_due_soon_until_reminded(): void
    {
        $incident = $this->incidentReadyForCapa();
        $make = fn (string $due) => app(CorrectiveActionService::class)->create($incident, CorrectiveActionData::fromArray([
            'description' => 'x', 'action_type' => 'corrective', 'priority' => 'high', 'due_date' => $due,
        ]));
        $tomorrow = $make(now()->addDay()->toDateString());
        $make(now()->addDays(2)->toDateString());
        $done = $make(now()->addDay()->toDateString());
        $done->forceFill(['status' => CorrectiveActionStatus::ForVerification])->save();

        $this->assertSame([$tomorrow->id], app(DueSoonCorrectiveActionsQuery::class)->get()->pluck('id')->all());

        app(CorrectiveActionRepository::class)->markReminded($tomorrow);
        $this->assertSame([], app(DueSoonCorrectiveActionsQuery::class)->get()->pluck('id')->all());
    }
```

Imports needed: `App\DataTransferObjects\CorrectiveActions\CorrectiveActionData`, `App\DataTransferObjects\Investigations\{StartInvestigationData, FindingData, CompleteInvestigationData}`, `App\Enums\{CorrectiveActionStatus, InvestigationMethodology, Role, Severity}`, `App\Models\{Department, IncidentType, Investigation, User}`, `App\Queries\{DueSoonInvestigationsQuery, DueSoonCorrectiveActionsQuery}`, `App\Repositories\{InvestigationRepository, CorrectiveActionRepository}`, `App\Services\{IncidentService, InvestigationService, CorrectiveActionService}`, `Illuminate\Support\Carbon`, `Illuminate\Foundation\Testing\RefreshDatabase`, `Tests\TestCase`.

`startedInvestigation(Carbon $target): Investigation` = the first half of `incidentReadyForCapa()` (createDraft → submit → markReviewed → assignInvestigator) then
`return app(InvestigationService::class)->start($incident->fresh(), $investigator, StartInvestigationData::fromArray(['objective' => 'x', 'methodology' => InvestigationMethodology::FiveWhys->value, 'target_completion_at' => $target->toDateTimeString()]));`
(each call creates its own department/incident, so three calls give three investigations).

- [ ] **Step 2: Run — expect FAIL** (classes missing)

Run: `php artisan test tests/Feature/DueSoonQueriesTest.php`

- [ ] **Step 3: Migration** — `database/migrations/2026_10_01_000002_add_reminder_sent_at_columns.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** One "due soon" reminder per investigation / corrective action (escalated_at already limits escalations to one). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investigations', function (Blueprint $table) {
            $table->timestamp('reminder_sent_at')->nullable()->after('escalated_at');
        });
        Schema::table('corrective_actions', function (Blueprint $table) {
            $table->timestamp('reminder_sent_at')->nullable()->after('escalated_at');
        });
    }

    public function down(): void
    {
        Schema::table('investigations', fn (Blueprint $table) => $table->dropColumn('reminder_sent_at'));
        Schema::table('corrective_actions', fn (Blueprint $table) => $table->dropColumn('reminder_sent_at'));
    }
};
```
Add `'reminder_sent_at' => 'datetime',` to `$casts` in `app/Models/Investigation.php` and `app/Models/CorrectiveAction.php`.

- [ ] **Step 4: Queries**

`app/Queries/DueSoonInvestigationsQuery.php`:

```php
<?php

namespace App\Queries;

use App\Enums\InvestigationStatus;
use App\Models\Investigation;
use Illuminate\Database\Eloquent\Collection;

/** In-progress investigations due within the next 24 hours that haven't had their reminder. */
class DueSoonInvestigationsQuery
{
    public function get(): Collection
    {
        return Investigation::whereNull('reminder_sent_at')
            ->whereNull('escalated_at')
            ->where('status', InvestigationStatus::InProgress)
            ->whereNotNull('target_completion_at')
            ->whereBetween('target_completion_at', [now(), now()->addDay()])
            ->get();
    }
}
```

`app/Queries/DueSoonCorrectiveActionsQuery.php`:

```php
<?php

namespace App\Queries;

use App\Enums\CorrectiveActionStatus;
use App\Models\CorrectiveAction;
use Illuminate\Database\Eloquent\Collection;

/** Corrective actions due tomorrow that the owner hasn't finished and that haven't had their reminder. */
class DueSoonCorrectiveActionsQuery
{
    public function get(): Collection
    {
        return CorrectiveAction::whereNull('reminder_sent_at')
            ->whereNull('escalated_at')
            ->whereDate('due_date', now()->addDay()->toDateString())
            ->whereIn('status', [CorrectiveActionStatus::Open->value, CorrectiveActionStatus::InProgress->value])
            ->get();
    }
}
```

- [ ] **Step 5: Repository methods** — add to `InvestigationRepository` (after `markEscalated`):

```php
    public function markReminded(Investigation $investigation): void
    {
        $investigation->forceFill(['reminder_sent_at' => now()])->save();
    }
```
and to `CorrectiveActionRepository`:

```php
    public function markReminded(CorrectiveAction $correctiveAction): void
    {
        $correctiveAction->forceFill(['reminder_sent_at' => now()])->save();
    }
```

- [ ] **Step 6: `app/Notifications/DeadlineReminderNotification.php`**

```php
<?php

namespace App\Notifications;

use App\Models\Incident;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** "Due soon" reminder to the investigator / action owner, a day before the deadline. */
class DeadlineReminderNotification extends Notification
{
    use Queueable;

    public function __construct(private Incident $incident, private string $reason)
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
            'message' => "Reminder: {$this->incident->incident_number} — {$this->reason}.",
        ];
    }
}
```

- [ ] **Step 7: Run — expect PASS**

Run: `php artisan test tests/Feature/DueSoonQueriesTest.php` → 2 pass.

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_10_01_000002_add_reminder_sent_at_columns.php app/Queries/DueSoonInvestigationsQuery.php app/Queries/DueSoonCorrectiveActionsQuery.php app/Repositories/InvestigationRepository.php app/Repositories/CorrectiveActionRepository.php app/Models/Investigation.php app/Models/CorrectiveAction.php app/Notifications/DeadlineReminderNotification.php tests/Feature/DueSoonQueriesTest.php
git commit -m "feat: due-soon queries and reminder notification for RCA and CAPA"
```

---

### Task 6: Daily command — reminders and matrix escalations

**Files:**
- Modify: `app/Console/Commands/CheckOverdueIncidents.php`
- Modify tests: `tests/Feature/Investigations/InvestigationEscalationTest.php`, `tests/Feature/CorrectiveActions/CorrectiveActionEscalationTest.php` (add tests)

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/Investigations/InvestigationEscalationTest.php` (it already has `assignedIncident(User $investigator)`; note that helper creates the department internally — read `$incident->department_id` from the returned incident):

```php
    public function test_an_overdue_investigation_goes_to_the_investigator_and_the_department_head_too(): void
    {
        Notification::fake();
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $incident = $this->assignedIncident($investigator);
        $head = User::factory()->create(['role' => Role::DepartmentHead, 'department_id' => $incident->department_id]);
        app(InvestigationService::class)->start($incident, $investigator, StartInvestigationData::fromArray([
            'objective' => 'x', 'methodology' => 'five_whys', 'target_completion_at' => now()->subDay()->toDateTimeString(),
        ]));

        Artisan::call('incidents:check-overdue');

        Notification::assertSentTo([$investigator, $head, $qso], IncidentEscalationNotification::class);
    }

    public function test_the_investigator_gets_one_reminder_a_day_before_the_deadline(): void
    {
        Notification::fake();
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $incident = $this->assignedIncident($investigator);
        $investigation = app(InvestigationService::class)->start($incident, $investigator, StartInvestigationData::fromArray([
            'objective' => 'x', 'methodology' => 'five_whys', 'target_completion_at' => now()->addHours(10)->toDateTimeString(),
        ]));

        Artisan::call('incidents:check-overdue');

        Notification::assertSentTo($investigator, DeadlineReminderNotification::class);
        Notification::assertNotSentTo($investigator, IncidentEscalationNotification::class);
        $this->assertNotNull($investigation->fresh()->reminder_sent_at);

        Notification::fake();
        Artisan::call('incidents:check-overdue');
        Notification::assertNothingSent();
    }

    public function test_rca_escalation_still_runs_when_there_is_no_cqi_user(): void
    {
        Notification::fake();
        $investigator = User::factory()->create(['role' => Role::Investigator]);
        $incident = $this->assignedIncident($investigator);
        app(InvestigationService::class)->start($incident, $investigator, StartInvestigationData::fromArray([
            'objective' => 'x', 'methodology' => 'five_whys', 'target_completion_at' => now()->subDay()->toDateTimeString(),
        ]));

        Artisan::call('incidents:check-overdue');

        Notification::assertSentTo($investigator, IncidentEscalationNotification::class);
    }
```
Add `use App\Notifications\DeadlineReminderNotification;`.

Add to `tests/Feature/CorrectiveActions/CorrectiveActionEscalationTest.php` (it has `incidentReadyForCapa()`):

```php
    public function test_an_overdue_capa_goes_to_the_owner_and_the_department_head(): void
    {
        Notification::fake();
        $incident = $this->incidentReadyForCapa();
        $owner = User::factory()->create(['department_id' => $incident->department_id]);
        $head = User::factory()->create(['role' => Role::DepartmentHead, 'department_id' => $incident->department_id]);
        $executive = User::factory()->create(['role' => Role::Management]);
        app(CorrectiveActionService::class)->create($incident, CorrectiveActionData::fromArray([
            'description' => 'x', 'action_type' => 'corrective', 'priority' => 'high',
            'due_date' => now()->subDay()->toDateString(), 'responsible_user_id' => $owner->id,
        ]));

        Artisan::call('incidents:check-overdue');

        Notification::assertSentTo([$owner, $head], IncidentEscalationNotification::class);
        Notification::assertNotSentTo($executive, IncidentEscalationNotification::class);
    }

    public function test_an_overdue_critical_capa_also_goes_to_executives(): void
    {
        Notification::fake();
        $incident = $this->incidentReadyForCapa();
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);
        $executive = User::factory()->create(['role' => Role::Management]);
        app(CorrectiveActionService::class)->create($incident, CorrectiveActionData::fromArray([
            'description' => 'x', 'action_type' => 'corrective', 'priority' => 'critical',
            'due_date' => now()->subDay()->toDateString(),
        ]));

        Artisan::call('incidents:check-overdue');

        Notification::assertSentTo([$qso, $executive], IncidentEscalationNotification::class);
    }

    public function test_the_owner_gets_one_reminder_the_day_before(): void
    {
        Notification::fake();
        $incident = $this->incidentReadyForCapa();
        $owner = User::factory()->create(['department_id' => $incident->department_id]);
        $capa = app(CorrectiveActionService::class)->create($incident, CorrectiveActionData::fromArray([
            'description' => 'x', 'action_type' => 'corrective', 'priority' => 'medium',
            'due_date' => now()->addDay()->toDateString(), 'responsible_user_id' => $owner->id,
        ]));

        Artisan::call('incidents:check-overdue');

        Notification::assertSentTo($owner, DeadlineReminderNotification::class);
        $this->assertNotNull($capa->fresh()->reminder_sent_at);

        Notification::fake();
        Artisan::call('incidents:check-overdue');
        Notification::assertNotSentTo($owner, DeadlineReminderNotification::class);
    }
```
Add `use App\Notifications\DeadlineReminderNotification;`. If `CorrectiveActionService::create` rejects a `responsible_user_id` outside the department, the owner above is in the incident's department, so it should be accepted; if it validates via a different rule, read `CorrectiveActionService::create` and adjust the owner setup (do not weaken the service).

- [ ] **Step 2: Run — expect FAIL**

Run: `php artisan test tests/Feature/Investigations/InvestigationEscalationTest.php`, then `php artisan test tests/Feature/CorrectiveActions/CorrectiveActionEscalationTest.php`.

- [ ] **Step 3: Rewrite the command's constructor, `handle()` and the two RCA/CAPA sweeps**

Constructor — add the two new queries:

```php
    public function __construct(
        private OverdueInvestigationsQuery $overdueInvestigations,
        private DueSoonInvestigationsQuery $dueSoonInvestigations,
        private InvestigationRepository $investigations,
        private OverdueCorrectiveActionsQuery $overdueCorrectiveActions,
        private DueSoonCorrectiveActionsQuery $dueSoonCorrectiveActions,
        private CorrectiveActionRepository $correctiveActions,
        private OverdueApprovalsQuery $overdueApprovals,
        private ApprovalRepository $approvals,
    ) {
        parent::__construct();
    }
```

`handle()`:

```php
    public function handle(): int
    {
        $this->notifyDueEffectivenessChecks();

        // RCA and CAPA follow the client's matrix and don't depend on a CQI user existing.
        $this->remindDueSoonInvestigations();
        $this->remindDueSoonCorrectiveActions();
        $this->escalateOverdueInvestigations();
        $this->escalateOverdueCorrectiveActions();

        $recipients = User::active()->withRole(config('incident_workflow.escalation_recipient_roles'))->get();

        if ($recipients->isEmpty()) {
            $this->warn('No escalation recipients configured/found; skipping review, assignment and approval escalations.');

            return self::SUCCESS;
        }

        $this->escalateOverdueAssessments($recipients);
        $this->escalateOverdueReviews($recipients);
        $this->escalateOverdueAssignments($recipients);
        $this->escalateOverdueApprovals($recipients);

        return self::SUCCESS;
    }
```

Replace `escalateOverdueInvestigations` and `escalateOverdueCorrectiveActions` and add the two reminder methods:

```php
    private function remindDueSoonInvestigations(): void
    {
        $this->dueSoonInvestigations->get()->each(function (Investigation $investigation) {
            $lead = $investigation->leadInvestigator;
            if ($lead !== null && $lead->is_active) {
                $due = $investigation->target_completion_at->format('M j, Y g:i A');
                Notification::send($lead, new DeadlineReminderNotification($investigation->incident, "your investigation is due by {$due}"));
            }
            $this->investigations->markReminded($investigation);
        });
    }

    private function remindDueSoonCorrectiveActions(): void
    {
        $this->dueSoonCorrectiveActions->get()->each(function (CorrectiveAction $correctiveAction) {
            $owner = $correctiveAction->responsibleUser;
            if ($owner !== null && $owner->is_active) {
                Notification::send($owner, new DeadlineReminderNotification($correctiveAction->incident, "corrective action {$correctiveAction->capa_number} is due tomorrow"));
            }
            $this->correctiveActions->markReminded($correctiveAction);
        });
    }

    private function escalateOverdueInvestigations(): void
    {
        $this->overdueInvestigations->get()->each(function (Investigation $investigation) {
            $recipients = EscalationRecipients::forOverdueInvestigation($investigation);
            if ($recipients->isNotEmpty()) {
                Notification::send($recipients, new IncidentEscalationNotification($investigation->incident, 'Investigation SLA breached'));
            }
            $this->investigations->markEscalated($investigation);
        });
    }

    private function escalateOverdueCorrectiveActions(): void
    {
        $this->overdueCorrectiveActions->get()->each(function (CorrectiveAction $correctiveAction) {
            $recipients = EscalationRecipients::forOverdueCorrectiveAction($correctiveAction);
            if ($recipients->isNotEmpty()) {
                Notification::send(
                    $recipients,
                    new IncidentEscalationNotification($correctiveAction->incident, "Corrective action {$correctiveAction->capa_number} SLA breached")
                );
            }
            $this->correctiveActions->markEscalated($correctiveAction);
        });
    }
```

Add imports: `App\Notifications\DeadlineReminderNotification`, `App\Queries\DueSoonCorrectiveActionsQuery`, `App\Queries\DueSoonInvestigationsQuery`, `App\Support\EscalationRecipients`. Update `$description` to `'Send deadline reminders and escalate incidents that have breached an SLA'`.

- [ ] **Step 4: Run — expect PASS**

Run each escalation test file, then `php artisan test tests/Feature/EscalationCommandTest.php`, then the whole suite `php artisan test` → all green.

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/CheckOverdueIncidents.php tests/Feature/Investigations/InvestigationEscalationTest.php tests/Feature/CorrectiveActions/CorrectiveActionEscalationTest.php
git commit -m "feat: RCA/CAPA reminders and matrix escalations in the daily check"
```

---

### Task 7: Documentation

**Files:**
- Modify: `docs/architecture.md` — find the severity / escalation sections (`grep -n "Severity\|escalat" docs/architecture.md`) and add a dated section "§9x Five severity levels & escalation matrix (2026-10-01)".

- [ ] **Step 1: Add the section** summarising: five levels and meanings (table), the data migration (old Level IV → Sentinel), `EscalationRecipients` as the single place for the matrix, the severity-alert trigger rules (assessment; triage only when changed; actor excluded; Committee no longer alerted), the reminder/escalation table, `reminder_sent_at`, and that review/assignment/approval escalations still go to `escalation_recipient_roles`. Also note "sentinel-event pathway: not built — waiting on the client's definition".

- [ ] **Step 2: Commit**

```bash
git add docs/architecture.md
git commit -m "docs: five severity levels and the escalation matrix"
```

---

## Final holistic review (after all tasks)

- Whole suite green; `npx vite build` green; `grep -rn "level_4_critical_sentinel\|Level4CriticalSentinel" app config resources tests` shows only the migration.
- Trace end-to-end: Dept Head completes a Sentinel assessment → who gets which notifications (CQI: ready-for-triage + severity alert; Leadership; Executives; not the Head; not the Committee). CQI changes Sentinel → Moderate at triage → Dept Head + (CQI excluded as actor).
- Daily command order: a CAPA due tomorrow gets a reminder, not an escalation; the day after its due date gets an escalation, not a second reminder.
- Browser: assessment and triage pickers show five cards with meanings; badges for Critical and Sentinel render; Trends chart legend shows five levels.
- `git status` still shows only the user's two files (`AddTeamMemberData.php`, the 0-day config line).
