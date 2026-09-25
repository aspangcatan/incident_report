# Public Guest Incident Reporting Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let patients, relatives and visitors report an incident without logging in, feeding straight into the Department Assessment stage.

**Architecture:** A public `/report` page (Inertia, `GuestLayout`) posts to a small `GuestReportController`, which calls a new `IncidentService::submitGuestReport()` that creates the incident with a null reporter and guest contact columns, then reuses the existing `submit()`. Rate limit via route throttle, honeypot via a `prohibited` rule.

**Tech Stack:** Laravel 9.52 (PHP 8.2), Inertia v1 + @inertiajs/vue3 v2, Vue 3, Tailwind v3, PHPUnit (SQLite in-memory).

**Spec:** `docs/superpowers/specs/2026-09-25-guest-reporting-design.md`.

---

## Ground rules

- Keep it simple — exactly what the task says.
- Users/departments are live read-only `tdh_user` (`docs/architecture.md` §9j). Never write to it.
- Only Task 1 runs one forward `php artisan migrate --force` on `incident_report`. Never migrate:fresh/reset/rollback/db:wipe/db:seed.
- `php artisan config:clear` before tests. Full suite `php artisan test` (276 passing at start).
- Commit after each task with trailer `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

---

### Task 1: Schema + service

**Files:** `composer.json`/`composer.lock` (doctrine/dbal), migration `database/migrations/2026_09_25_000002_allow_guest_reporters_on_incidents.php`, `app/Models/Incident.php`, `app/Services/IncidentService.php`, `tests/Feature/Incidents/GuestReportTest.php` (create).

- [ ] **Step 1: Install doctrine/dbal** (user-approved; Laravel 9 needs it for `->change()`): `composer require doctrine/dbal:^3.0`. Confirm it resolves without upgrading laravel/framework (`git diff composer.json` shows only the new require line).

- [ ] **Step 2: Failing test** `tests/Feature/Incidents/GuestReportTest.php`:

```php
<?php

namespace Tests\Feature\Incidents;

use App\Enums\IncidentStatus;
use App\Models\Department;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Services\IncidentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuestReportTest extends TestCase
{
    use RefreshDatabase;

    private function data(array $extra = []): array
    {
        return array_merge([
            'guest_name' => 'Maria Santos',
            'guest_contact' => '0917 123 4567',
            'guest_relationship' => 'relative',
            'incident_type_id' => IncidentType::factory()->create()->id,
            'department_id' => Department::factory()->create()->id,
            'occurred_at' => now()->subHour()->format('Y-m-d H:i'),
            'location' => 'Ward 3, bed 12',
            'summary' => 'My mother fell while walking to the bathroom.',
        ], $extra);
    }

    public function test_the_service_submits_a_guest_report_without_a_reporter(): void
    {
        $incident = app(IncidentService::class)->submitGuestReport($this->data());

        $incident->refresh();
        $this->assertNull($incident->reporter_id);
        $this->assertSame(IncidentStatus::Submitted, $incident->status);
        $this->assertNotNull($incident->incident_number);
        $this->assertNotNull($incident->legal_attestation_at);
        $this->assertSame('Maria Santos', $incident->guest_name);
        $this->assertSame('relative', $incident->guest_relationship);
        $this->assertTrue($incident->isGuestReport());
    }
}
```

- [ ] **Step 3: Run — FAIL.** `php artisan test --filter=GuestReportTest`

- [ ] **Step 4: Migration** `database/migrations/2026_09_25_000002_allow_guest_reporters_on_incidents.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public guest reports (docs/superpowers/specs/2026-09-25-guest-reporting-design.md):
 * guests have no account, so reporter_id may be null and their contact
 * details live on the incident.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->unsignedBigInteger('reporter_id')->nullable()->change();
            $table->string('guest_name')->nullable()->after('reporter_id');
            $table->string('guest_contact')->nullable()->after('guest_name');
            $table->string('guest_relationship', 20)->nullable()->after('guest_contact');
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropColumn(['guest_name', 'guest_contact', 'guest_relationship']);
        });
    }
};
```

Check on SQLite (tests) that `->change()` works with doctrine/dbal and that other indexes on `incidents` (e.g. `status, reporter_id`) survive the table rebuild — the full suite will show it.

- [ ] **Step 5: Model** — `Incident`: add

```php
    public function isGuestReport(): bool
    {
        return $this->reporter_id === null;
    }
```

(`guest_*` are set via `forceFill` in the service, so no `$fillable` change.)

- [ ] **Step 6: Service** — add to `IncidentService` (import `Illuminate\Support\Arr` if not already):

```php
    /**
     * Public guest report (no account): created and submitted in one step,
     * landing in Department Assessment like any other submission.
     */
    public function submitGuestReport(array $data): Incident
    {
        $incident = DB::transaction(function () use ($data) {
            $incident = new Incident($this->onlyIncidentColumns($data));
            $incident->forceFill(Arr::only($data, ['guest_name', 'guest_contact', 'guest_relationship']));
            $incident->reporter_id = null;
            $incident->status = IncidentStatus::Draft;
            $incident->save();

            return $incident;
        });

        return $this->submit($incident);
    }
```

- [ ] **Step 7: Run the test, full suite green, then** `php artisan config:clear && php artisan migrate --force` (exactly this one migration).

- [ ] **Step 8: Commit** — `feat: allow guest reporters on incidents` (include composer.json/composer.lock).

---

### Task 2: Public endpoints, validation, throttle, policy

**Files:** create `app/Http/Controllers/GuestReportController.php`, `app/Http/Requests/Incidents/StoreGuestReportRequest.php`; modify `routes/web.php`, `app/Policies/IncidentPolicy.php`, `app/Http/Requests/Incidents/ReturnIncidentRequest.php`, `app/Http/Controllers/IncidentController.php` (`show()` can flags); tests in `GuestReportTest`.

- [ ] **Step 1: Failing tests** — append to `GuestReportTest` (add imports `App\Enums\Role`, `App\Models\User`, `App\Notifications\IncidentSubmittedNotification`, `Illuminate\Support\Facades\Notification`):

```php
    private function post_(array $extra = [])
    {
        return $this->post('/report', array_merge($this->data(), ['legal_attestation' => true], $extra));
    }

    public function test_the_report_page_is_public(): void
    {
        $this->get('/report')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Guest/Report')
            ->has('incidentTypes')
            ->has('departments'));
    }

    public function test_a_guest_can_submit_and_sees_the_reference_number(): void
    {
        $response = $this->post_();

        $incident = Incident::latest('id')->first();
        $response->assertRedirect('/report/submitted');
        $this->assertSame(IncidentStatus::Submitted, $incident->status);
        $this->assertNull($incident->reporter_id);

        $this->get('/report/submitted')->assertInertia(fn ($page) => $page
            ->component('Guest/Submitted')
            ->where('reference', $incident->incident_number));
    }

    public function test_the_submitted_page_without_a_reference_goes_back_to_the_form(): void
    {
        $this->get('/report/submitted')->assertRedirect('/report');
    }

    public function test_the_department_is_optional_and_then_qso_admin_are_notified(): void
    {
        Notification::fake();
        $qso = User::factory()->create(['role' => Role::QualitySafetyOfficer]);

        $this->post_(['department_id' => null])->assertRedirect('/report/submitted');

        $this->assertNull(Incident::latest('id')->first()->department_id);
        Notification::assertSentTo($qso, IncidentSubmittedNotification::class);
    }

    public function test_validation_rules(): void
    {
        // More than 3 POSTs in one test — take the throttle out of the picture here.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $this->post('/report', ['website' => ''])->assertSessionHasErrors([
            'guest_name', 'guest_contact', 'guest_relationship', 'incident_type_id',
            'occurred_at', 'location', 'summary', 'legal_attestation',
        ]);
        $this->post_(['guest_relationship' => 'doctor'])->assertSessionHasErrors('guest_relationship');
        $this->post_(['occurred_at' => now()->addDay()->format('Y-m-d H:i')])->assertSessionHasErrors('occurred_at');
        $this->post_(['department_id' => Department::factory()->create(['name' => '-'])->id])->assertSessionHasErrors('department_id');
    }

    public function test_the_honeypot_rejects_bots(): void
    {
        $this->post_(['website' => 'http://spam.example'])->assertSessionHasErrors('website');
        $this->assertSame(0, Incident::count());
    }

    public function test_submissions_are_rate_limited(): void
    {
        foreach (range(1, 3) as $i) {
            $this->post_()->assertRedirect('/report/submitted');
        }

        $this->post_()->assertStatus(429);
    }

    public function test_guest_reports_cannot_be_returned_to_a_reporter(): void
    {
        $head = User::factory()->create(['role' => Role::DepartmentHead, 'department_id' => ($dept = Department::factory()->create())->id]);
        $incident = app(IncidentService::class)->submitGuestReport($this->data(['department_id' => $dept->id]));

        $this->assertFalse($head->can('returnToReporter', $incident));
        $this->actingAs($head)->post("/incidents/{$incident->id}/return", ['comments' => 'x'])->assertForbidden();
        $this->actingAs($head)->get("/incidents/{$incident->id}")->assertInertia(fn ($page) => $page
            ->where('can.returnToReporter', false)
            ->where('incident.guest_name', 'Maria Santos'));
    }
```

- [ ] **Step 2: Run — FAIL.**

- [ ] **Step 3: Request** `app/Http/Requests/Incidents/StoreGuestReportRequest.php`:

```php
<?php

namespace App\Http\Requests\Incidents;

use App\Models\Department;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGuestReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'website' => ['prohibited'], // honeypot: hidden from people, filled by bots
            'guest_name' => ['required', 'string', 'max:255'],
            'guest_contact' => ['required', 'string', 'max:255'],
            'guest_relationship' => ['required', Rule::in(['patient', 'relative', 'visitor', 'other'])],
            'incident_type_id' => ['required', Rule::exists('incident_types', 'id')->where('is_active', true)],
            'department_id' => ['nullable', Department::selectableRule()],
            'occurred_at' => ['required', 'date', 'before_or_equal:now'],
            'location' => ['required', 'string', 'max:255'],
            'summary' => ['required', 'string'],
            'legal_attestation' => ['accepted'],
        ];
    }
}
```

(`prohibited` fails when the field is present and non-empty; an empty string passes.)

- [ ] **Step 4: Controller** `app/Http/Controllers/GuestReportController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\Incidents\StoreGuestReportRequest;
use App\Models\Department;
use App\Models\IncidentType;
use App\Services\IncidentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Public incident reporting for patients, relatives and visitors (no login). */
class GuestReportController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Guest/Report', [
            'incidentTypes' => IncidentType::where('is_active', true)->get(['id', 'name']),
            'departments' => Department::options(),
        ]);
    }

    public function store(StoreGuestReportRequest $request, IncidentService $incidents): RedirectResponse
    {
        $incident = $incidents->submitGuestReport($request->validated());

        return redirect()->route('guest-report.submitted')->with('guest_reference', $incident->incident_number);
    }

    public function submitted(Request $request): Response|RedirectResponse
    {
        $reference = $request->session()->get('guest_reference');

        if ($reference === null) {
            return redirect()->route('guest-report.create');
        }

        return Inertia::render('Guest/Submitted', ['reference' => $reference]);
    }
}
```

- [ ] **Step 5: Routes** — in `routes/web.php`, outside both the `guest` and `auth` groups (public for everyone):

```php
Route::get('/report', [GuestReportController::class, 'create'])->name('guest-report.create');
Route::post('/report', [GuestReportController::class, 'store'])->middleware('throttle:3,60')->name('guest-report.store');
Route::get('/report/submitted', [GuestReportController::class, 'submitted'])->name('guest-report.submitted');
```

(import `App\Http\Controllers\GuestReportController`). Note: `throttle:3,60` counts every POST including failed validations — acceptable and simple. If the default throttle key for guests is the IP, this is per-IP; confirm in `ThrottleRequests::resolveRequestSignature`.

- [ ] **Step 6: Policy** — in `IncidentPolicy` add:

```php
    /** Guests have no account, so their reports can't go back to them as a draft. */
    public function returnToReporter(User $user, Incident $incident): bool
    {
        return $incident->reporter_id !== null && $this->completeAssessment($user, $incident);
    }
```

`ReturnIncidentRequest::authorize()` → `can('returnToReporter', ...)`. In `IncidentController::show()` `can` array add `'returnToReporter' => $user->can('returnToReporter', $incident),`.

- [ ] **Step 7: Guest fields in the show payload** — `incident` is serialized raw; confirm `guest_name`, `guest_contact`, `guest_relationship` appear (Incident has no `$visible`/`$hidden` restricting them). If hidden, expose them.

- [ ] **Step 8: Full suite green. Commit** — `feat: public guest report endpoints with throttle and honeypot`

---

### Task 3: Frontend

**Files:** create `resources/js/Pages/Guest/Report.vue`, `resources/js/Pages/Guest/Submitted.vue`; modify `resources/js/Layouts/GuestLayout.vue`, `resources/js/Pages/Auth/Login.vue`, `resources/js/Pages/Incidents/Show.vue`, `resources/js/Components/Incidents/AssessmentPanel.vue`.

- [ ] **Step 1: GuestLayout width** — add a prop so the report form can be wider than the login card:

```vue
<script setup>
import AppLogo from '@/Components/AppLogo.vue';

defineProps({
    wide: { type: Boolean, default: false },
});
</script>
```

and on the card div replace `max-w-md` with `:class="wide ? 'max-w-2xl' : 'max-w-md'"` (keep the other classes static).

- [ ] **Step 2: `Pages/Guest/Report.vue`** — one form, sections "About you", "What happened", declaration, submit. Use `useForm({ guest_name:'', guest_contact:'', guest_relationship:null, incident_type_id:null, department_id:null, occurred_at:'', location:'', summary:'', legal_attestation:false, website:'' })`, `form.post('/report')`. Department select has a first option `:value="null"` labelled `I don't know`. Relationship select options Patient/Relative/Visitor/Other (values patient/relative/visitor/other). `occurred_at` is `type="datetime-local"` with `:max` = now. Honeypot: `<div class="hidden" aria-hidden="true"><label for="website">Website</label><input id="website" v-model="form.website" type="text" tabindex="-1" autocomplete="off" /></div>`. Every field shows `form.errors.<field>`; show a friendly message when the response is 429 (`onError` won't fire for 429 — use `router`'s `invalid`/`onError`? simplest: wrap with `form.post('/report', { onError: ... })` and additionally show a static note "You can send up to 3 reports per hour." under the button). Heading "Report an Incident", intro "For patients, relatives and visitors. Hospital staff should sign in instead." with a link to `/login`. Match the Tailwind tokens/classes used in `Pages/Auth/Login.vue` and the incident wizard steps. `<Head title="Report an Incident" />`. Use `<GuestLayout wide>`.

- [ ] **Step 3: `Pages/Guest/Submitted.vue`** — `<GuestLayout>`; heading "Thank you"; text "Your report has been received by the hospital's Quality & Patient Safety team."; the reference in a large mono style: `{{ reference }}` under "Your reference number"; "Please keep this number if you need to contact the hospital about your report."; link "Submit another report" → `/report`. Prop `reference: String` required.

- [ ] **Step 4: Login link** — in `Pages/Auth/Login.vue`, below the form: `Not hospital staff? <Link href="/report">Report an incident</Link>` (import `Link` from `@inertiajs/vue3`), styled like the page's small text links.

- [ ] **Step 5: Show.vue reporter** — where the reporter name is rendered (`incident.reporter?.name ?? '—'`, around line 79), show guest details for guest reports:

```vue
<span v-if="incident.reporter_id === null" class="font-body-md text-body-md text-on-surface font-semibold">
    {{ incident.guest_name }} <span class="font-body-sm text-body-sm text-outline">(Guest · {{ incident.guest_relationship }} · {{ incident.guest_contact }})</span>
</span>
<span v-else class="font-body-md text-body-md text-on-surface font-semibold">{{ incident.reporter?.name ?? '—' }}</span>
```

- [ ] **Step 6: AssessmentPanel** — the "Return to reporter" block's `v-if` uses `can.returnToReporter` instead of `can.completeAssessment` (keep the `editable &&` part).

- [ ] **Step 7:** `npm run build` succeeds; full suite still green. **Commit** — `feat: public guest report page and thank-you page`

---

### Task 4: Documentation

- [ ] Add "§9l. Public guest reporting (2026-09-25)" to `docs/architecture.md`: `/report` (public, no login), fields, throttle 3/hour/IP + honeypot, no attachments, reference-only thank-you page, null `reporter_id` + `guest_*` columns, `returnToReporter` ability, notifications (QSO/Admin when no department), doctrine/dbal added for the column change. Commit `docs: document public guest reporting`.

---

## After Task 4 (controller session)

Holistic review; browser check of `/report` → thank-you page → the incident visible to the Department Head (user 1) with guest details and no "Return to Reporter"; update memory.
