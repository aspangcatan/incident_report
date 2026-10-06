# Incident Types Settings Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An IT Admin-only settings page to add, edit (name, category, default severity, active) and delete (only when unused) incident types.

**Architecture:** Trimmed layered pattern — FormRequests → `IncidentTypeData` DTO → `IncidentTypeService`, guarded by `IncidentTypePolicy`, shaped by `IncidentTypeResource`, served by a thin `Admin\IncidentTypeController`, rendered by `Admin/IncidentTypes.vue`. No migration, no Repository/Action/Query.

**Tech Stack:** Laravel 9.52 (PHP 8.2), Inertia (inertia-laravel 1.3.4 / @inertiajs/vue3 2.x), Vue 3, Tailwind 3, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-10-06-incident-types-settings-design.md`

**Project rules for every task:**
- Run `php artisan config:clear` before `php artisan test`.
- `php artisan test a b` only runs the first path — run one path at a time.
- Never commit `config/incident_workflow.php` or `app/DataTransferObjects/Investigations/AddTeamMemberData.php` (the user's uncommitted local changes). Always `git add` explicit paths.
- Laravel 9 `JsonResource::toArray($request)` is **untyped** — do not add `Request $request): array` types.
- `JsonResource::withoutWrapping()` is global, so a resource collection serialises as a plain array.
- Commit messages end with the line `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

---

## File map

| File | Responsibility |
|---|---|
| `app/Models/IncidentType.php` (modify) | `CATEGORIES`, usage relations, `isInUse()` |
| `app/Policies/IncidentTypePolicy.php` (create) | who may view/create/update/delete |
| `app/Providers/AuthServiceProvider.php` (modify) | register the policy |
| `app/Http/Middleware/HandleInertiaRequests.php` (modify) | `auth.can.manageIncidentTypes` |
| `app/DataTransferObjects/IncidentTypes/IncidentTypeData.php` (create) | validated input → attributes |
| `app/Http/Requests/IncidentTypes/StoreIncidentTypeRequest.php` (create) | create validation |
| `app/Http/Requests/IncidentTypes/UpdateIncidentTypeRequest.php` (create) | edit validation |
| `app/Services/IncidentTypeService.php` (create) | create / update / delete |
| `app/Http/Resources/IncidentTypeResource.php` (create) | row shape for the page |
| `app/Http/Controllers/Admin/IncidentTypeController.php` (create) | index / store / update / destroy |
| `routes/web.php` (modify) | 4 routes |
| `resources/js/Pages/Admin/IncidentTypes.vue` (create) | the page |
| `resources/js/Layouts/AuthenticatedLayout.vue` (modify) | sidebar link |
| `tests/Feature/Admin/IncidentTypeSettingsTest.php` (create) | feature tests |
| `tests/Feature/SidebarPermissionsTest.php` (modify) | new flag |
| `docs/architecture.md` (modify) | short section |

---

### Task 1: Model — categories, usage relations, `isInUse()`

**Files:**
- Modify: `app/Models/IncidentType.php`
- Test: `tests/Feature/Admin/IncidentTypeSettingsTest.php` (create)

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Admin/IncidentTypeSettingsTest.php`:

```php
<?php

namespace Tests\Feature\Admin;

use App\Enums\RecurrenceReviewStatus;
use App\Enums\Role;
use App\Models\Department;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\RecurrenceReview;
use App\Models\User;
use App\Services\IncidentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IncidentTypeSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => Role::Administrator]);
    }

    /** A draft incident that uses $type through the incident_incident_type pivot. */
    private function incidentUsing(IncidentType $type): Incident
    {
        return app(IncidentService::class)->createDraft(User::factory()->create(), [
            'department_id' => Department::factory()->create()->id,
            'incident_type_ids' => [$type->id],
            'occurred_at' => now(),
            'location' => 'Ward 3',
            'summary' => 'Test incident.',
        ]);
    }

    private function recurrenceReviewUsing(IncidentType $type): RecurrenceReview
    {
        $user = User::factory()->create(['role' => Role::QualitySafetyOfficer]);

        return RecurrenceReview::create([
            'department_id' => Department::factory()->create()->id,
            'incident_type_id' => $type->id,
            'incident_count' => 3,
            'status' => RecurrenceReviewStatus::Open,
            'assigned_to' => $user->id,
            'due_date' => now()->addDays(14),
            'cqi_notes' => 'Pattern.',
            'created_by' => $user->id,
        ]);
    }

    public function test_a_new_type_is_not_in_use(): void
    {
        $this->assertFalse(IncidentType::factory()->create()->isInUse());
    }

    public function test_a_type_on_an_incident_is_in_use(): void
    {
        $type = IncidentType::factory()->create();
        $this->incidentUsing($type);

        $this->assertTrue($type->fresh()->isInUse());
    }

    public function test_a_type_on_the_legacy_incident_column_is_in_use(): void
    {
        $type = IncidentType::factory()->create();
        $incident = $this->incidentUsing(IncidentType::factory()->create());
        DB::table('incidents')->where('id', $incident->id)->update(['incident_type_id' => $type->id]);

        $this->assertTrue($type->fresh()->isInUse());
    }

    public function test_a_type_on_a_recurrence_review_is_in_use(): void
    {
        $type = IncidentType::factory()->create();
        $this->recurrenceReviewUsing($type);

        $this->assertTrue($type->fresh()->isInUse());
    }
}
```

Before running, check `RecurrenceReview`'s table: if `create()` fails because a NOT NULL column is missing from the array above, add that column with a plausible value (look at `database/migrations/2026_09_29_000010_create_recurrence_reviews_table.php`).

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan config:clear && php artisan test tests/Feature/Admin/IncidentTypeSettingsTest.php`
Expected: FAIL — `Call to undefined method App\Models\IncidentType::isInUse()`.

- [ ] **Step 3: Implement**

Replace `app/Models/IncidentType.php` with:

```php
<?php

namespace App\Models;

use App\Enums\Severity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IncidentType extends Model
{
    use HasFactory;

    /** The client's categories (value => label). */
    public const CATEGORIES = [
        'injury' => 'Injury',
        'clinical' => 'Clinical',
        'exposure' => 'Exposure',
        'security' => 'Security',
        'property' => 'Property',
        'environment' => 'Environment',
        'conduct' => 'Conduct',
    ];

    /** Counts that decide whether a type may be deleted. */
    public const USAGE_COUNTS = ['incidents', 'legacyIncidents', 'recurrenceReviews'];

    protected $fillable = [
        'name',
        'category',
        'default_severity',
        'is_active',
    ];

    protected $casts = [
        'default_severity' => Severity::class,
        'is_active' => 'boolean',
    ];

    public function incidents(): BelongsToMany
    {
        return $this->belongsToMany(Incident::class);
    }

    /** Old single-type column (incidents.incident_type_id), no longer written. */
    public function legacyIncidents(): HasMany
    {
        return $this->hasMany(Incident::class, 'incident_type_id');
    }

    public function recurrenceReviews(): HasMany
    {
        return $this->hasMany(RecurrenceReview::class);
    }

    /** Used by any incident or recurrence review — such a type can only be switched off. */
    public function isInUse(): bool
    {
        if (! array_key_exists('incidents_count', $this->attributes)) {
            $this->loadCount(self::USAGE_COUNTS);
        }

        return $this->incidents_count + $this->legacy_incidents_count + $this->recurrence_reviews_count > 0;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan config:clear && php artisan test tests/Feature/Admin/IncidentTypeSettingsTest.php`
Expected: 4 passed.

- [ ] **Step 5: Commit**

```bash
git add app/Models/IncidentType.php tests/Feature/Admin/IncidentTypeSettingsTest.php
git commit -m "feat: incident types know their categories and whether they are in use

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Policy and the `manageIncidentTypes` sidebar flag

**Files:**
- Create: `app/Policies/IncidentTypePolicy.php`
- Modify: `app/Providers/AuthServiceProvider.php` (the `$policies` array, ~line 18-22)
- Modify: `app/Http/Middleware/HandleInertiaRequests.php` (the `'can' =>` array, ~line 50-56)
- Modify: `tests/Feature/SidebarPermissionsTest.php`

- [ ] **Step 1: Update the failing sidebar test**

In `tests/Feature/SidebarPermissionsTest.php`, change the `$flags` closure and the data rows so every role has a sixth flag. Only `administrator` gets `true`:

```php
        $flags = fn (bool $all, bool $inv, bool $capa, bool $analytics, bool $admin, bool $types = false) => [
            'viewAllIncidents' => $all,
            'investigationWorkspace' => $inv,
            'capaOperations' => $capa,
            'viewAnalytics' => $analytics,
            'administration' => $admin,
            'manageIncidentTypes' => $types,
        ];
```

and change the administrator row to:

```php
            'administrator' => [Role::Administrator, $flags(false, false, false, false, true, true)],
```

(all other rows stay as they are — they default `$types` to `false`).

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan config:clear && php artisan test tests/Feature/SidebarPermissionsTest.php`
Expected: FAIL — `auth.can` lacks `manageIncidentTypes`.

- [ ] **Step 3: Implement**

Create `app/Policies/IncidentTypePolicy.php`:

```php
<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\IncidentType;
use App\Models\User;

/** Incident type settings are technical configuration: IT/System Admin only. */
class IncidentTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === Role::Administrator;
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, IncidentType $type): bool
    {
        return $this->viewAny($user);
    }

    /** A type that reports already use can only be switched off, never deleted. */
    public function delete(User $user, IncidentType $type): bool
    {
        return $this->viewAny($user) && ! $type->isInUse();
    }
}
```

In `app/Providers/AuthServiceProvider.php` add to `$policies`:

```php
        \App\Models\IncidentType::class => \App\Policies\IncidentTypePolicy::class,
```

In `app/Http/Middleware/HandleInertiaRequests.php`, inside the `'can' => $user ? [ ... ]` array, after `'administration' => ...`, add:

```php
                    'manageIncidentTypes' => $user->can('viewAny', IncidentType::class),
```

and add `use App\Models\IncidentType;` to the imports at the top of the file.

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan config:clear && php artisan test tests/Feature/SidebarPermissionsTest.php`
Expected: 9 passed.

- [ ] **Step 5: Commit**

```bash
git add app/Policies/IncidentTypePolicy.php app/Providers/AuthServiceProvider.php app/Http/Middleware/HandleInertiaRequests.php tests/Feature/SidebarPermissionsTest.php
git commit -m "feat: incident type policy - IT Admin only, delete only when unused

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: List and create (DTO, requests, service, resource, controller, routes)

**Files:**
- Create: `app/DataTransferObjects/IncidentTypes/IncidentTypeData.php`
- Create: `app/Http/Requests/IncidentTypes/StoreIncidentTypeRequest.php`
- Create: `app/Services/IncidentTypeService.php`
- Create: `app/Http/Resources/IncidentTypeResource.php`
- Create: `app/Http/Controllers/Admin/IncidentTypeController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Admin/IncidentTypeSettingsTest.php`

- [ ] **Step 1: Write the failing tests**

Add `use App\Enums\Severity;` to the test file's imports, then append these methods to `IncidentTypeSettingsTest`:

```php
    public function test_only_the_it_admin_can_open_the_page(): void
    {
        $this->actingAs($this->admin())->get('/admin/incident-types')->assertOk();

        foreach ([Role::QualitySafetyOfficer, Role::DepartmentHead, Role::Staff, Role::Management] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->get('/admin/incident-types')->assertForbidden();
        }
    }

    public function test_the_page_lists_types_with_usage_and_options(): void
    {
        $used = IncidentType::factory()->create(['name' => 'Falls', 'category' => 'injury', 'default_severity' => Severity::Level3High]);
        $this->incidentUsing($used);
        IncidentType::factory()->create(['name' => 'Spills', 'category' => 'environment', 'is_active' => false]);

        $this->actingAs($this->admin())->get('/admin/incident-types')
            ->assertInertia(fn ($page) => $page->component('Admin/IncidentTypes')
                ->has('types', 2)
                ->where('types.0.name', 'Falls')
                ->where('types.0.category_label', 'Injury')
                ->where('types.0.default_severity', 'level_3_high')
                ->where('types.0.usage_count', 1)
                ->where('types.0.can_delete', false)
                ->where('types.1.name', 'Spills')
                ->where('types.1.is_active', false)
                ->where('types.1.default_severity', null)
                ->where('types.1.can_delete', true)
                ->has('categories', 7)
                ->where('categories.0', ['value' => 'injury', 'label' => 'Injury']));
    }

    public function test_the_it_admin_adds_a_type(): void
    {
        $this->actingAs($this->admin())->post('/admin/incident-types', [
            'name' => 'Elopement',
            'category' => 'security',
            'default_severity' => 'level_2_moderate',
            'is_active' => true,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $type = IncidentType::where('name', 'Elopement')->first();
        $this->assertSame('security', $type->category);
        $this->assertSame(Severity::Level2Moderate, $type->default_severity);
        $this->assertTrue($type->is_active);
    }

    public function test_adding_a_type_without_a_default_severity(): void
    {
        $this->actingAs($this->admin())->post('/admin/incident-types', [
            'name' => 'Elopement',
            'category' => 'security',
            'default_severity' => null,
            'is_active' => true,
        ])->assertSessionHasNoErrors();

        $this->assertNull(IncidentType::where('name', 'Elopement')->first()->default_severity);
    }

    public function test_adding_a_type_is_validated(): void
    {
        IncidentType::factory()->create(['name' => 'Falls']);

        $this->actingAs($this->admin())->post('/admin/incident-types', [
            'name' => 'Falls',
            'category' => 'not-a-category',
            'default_severity' => 'level_9',
            'is_active' => true,
        ])->assertSessionHasErrors(['name', 'category', 'default_severity']);

        $this->actingAs($this->admin())->post('/admin/incident-types', [
            'name' => '',
            'category' => '',
        ])->assertSessionHasErrors(['name', 'category']);

        $this->assertSame(1, IncidentType::count());
    }

    public function test_other_roles_cannot_add_a_type(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::QualitySafetyOfficer]))
            ->post('/admin/incident-types', ['name' => 'Elopement', 'category' => 'security', 'is_active' => true])
            ->assertForbidden();

        $this->assertSame(0, IncidentType::count());
    }
```

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan config:clear && php artisan test tests/Feature/Admin/IncidentTypeSettingsTest.php`
Expected: the new tests FAIL with 404 (route not defined); the Task 1 tests still pass.

- [ ] **Step 3: Implement**

Create `app/DataTransferObjects/IncidentTypes/IncidentTypeData.php`:

```php
<?php

namespace App\DataTransferObjects\IncidentTypes;

use App\Enums\Severity;

final class IncidentTypeData
{
    public function __construct(
        public readonly string $name,
        public readonly string $category,
        public readonly ?Severity $defaultSeverity,
        public readonly bool $isActive,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            category: $data['category'],
            defaultSeverity: Severity::tryFrom($data['default_severity'] ?? ''),
            isActive: (bool) $data['is_active'],
        );
    }

    public function toAttributes(): array
    {
        return [
            'name' => $this->name,
            'category' => $this->category,
            'default_severity' => $this->defaultSeverity,
            'is_active' => $this->isActive,
        ];
    }
}
```

Create `app/Http/Requests/IncidentTypes/StoreIncidentTypeRequest.php`:

```php
<?php

namespace App\Http\Requests\IncidentTypes;

use App\DataTransferObjects\IncidentTypes\IncidentTypeData;
use App\Enums\Severity;
use App\Models\IncidentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreIncidentTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', IncidentType::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', $this->uniqueName()],
            'category' => ['required', Rule::in(array_keys(IncidentType::CATEGORIES))],
            'default_severity' => ['nullable', new Enum(Severity::class)],
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Enter a name for the incident type.',
            'name.unique' => 'An incident type with this name already exists.',
            'category.required' => 'Choose a category.',
            'category.in' => 'Choose a category from the list.',
            'default_severity.Illuminate\Validation\Rules\Enum' => 'Choose a severity level from the list.',
        ];
    }

    protected function uniqueName(): \Illuminate\Validation\Rules\Unique
    {
        return Rule::unique('incident_types', 'name');
    }

    public function toDto(): IncidentTypeData
    {
        return IncidentTypeData::fromArray($this->validated());
    }
}
```

Note: the store test that omits `is_active` (`test_adding_a_type_is_validated`, second post) only asserts errors on name/category, so `is_active` being required is fine there. `test_other_roles_cannot_add_a_type` sends `is_active` and expects 403 — `authorize()` runs before validation, so it returns 403.

Create `app/Services/IncidentTypeService.php`:

```php
<?php

namespace App\Services;

use App\DataTransferObjects\IncidentTypes\IncidentTypeData;
use App\Models\IncidentType;

class IncidentTypeService
{
    public function create(IncidentTypeData $data): IncidentType
    {
        return IncidentType::create($data->toAttributes());
    }

    public function update(IncidentType $type, IncidentTypeData $data): IncidentType
    {
        $type->update($data->toAttributes());

        return $type;
    }

    /** Callers must check the 'delete' policy first: a type in use can't be deleted. */
    public function delete(IncidentType $type): void
    {
        $type->delete();
    }
}
```

Create `app/Http/Resources/IncidentTypeResource.php`:

```php
<?php

namespace App\Http\Resources;

use App\Models\IncidentType;
use Illuminate\Http\Resources\Json\JsonResource;

/** One row on the Incident Types settings page. Expects IncidentType::USAGE_COUNTS loaded. */
class IncidentTypeResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'category' => $this->category,
            'category_label' => IncidentType::CATEGORIES[$this->category] ?? $this->category,
            'default_severity' => $this->default_severity?->value,
            'is_active' => $this->is_active,
            'usage_count' => $this->incidents_count,
            'can_delete' => $request->user()->can('delete', $this->resource),
        ];
    }
}
```

Create `app/Http/Controllers/Admin/IncidentTypeController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\IncidentTypes\StoreIncidentTypeRequest;
use App\Http\Resources\IncidentTypeResource;
use App\Models\IncidentType;
use App\Services\IncidentTypeService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/** IT Admin settings: the incident type list reporters choose from. */
class IncidentTypeController extends Controller
{
    public function __construct(private readonly IncidentTypeService $service)
    {
    }

    public function index(): Response
    {
        $this->authorize('viewAny', IncidentType::class);

        return Inertia::render('Admin/IncidentTypes', [
            'types' => IncidentTypeResource::collection(
                IncidentType::withCount(IncidentType::USAGE_COUNTS)->orderBy('name')->get()
            ),
            'categories' => collect(IncidentType::CATEGORIES)
                ->map(fn ($label, $value) => ['value' => $value, 'label' => $label])
                ->values(),
        ]);
    }

    public function store(StoreIncidentTypeRequest $request): RedirectResponse
    {
        $type = $this->service->create($request->toDto());

        return back()->with('success', "Incident type \"{$type->name}\" added.");
    }
}
```

In `routes/web.php` add the import near the other controller imports:

```php
use App\Http\Controllers\Admin\IncidentTypeController;
```

and, right after the two `/admin/leadership` routes inside the `auth` group, add:

```php
    Route::get('/admin/incident-types', [IncidentTypeController::class, 'index'])->name('admin.incident-types.index');
    Route::post('/admin/incident-types', [IncidentTypeController::class, 'store'])->name('admin.incident-types.store');
```

Inertia's `assertInertia` will fail with "page component file does not exist" if the testing config checks for Vue files. If that happens, create a minimal placeholder `resources/js/Pages/Admin/IncidentTypes.vue` containing `<template><div /></template>` (Task 6 replaces it). Check `config/inertia.php` → `testing.ensure_pages_exist` first.

- [ ] **Step 4: Run to verify they pass**

Run: `php artisan config:clear && php artisan test tests/Feature/Admin/IncidentTypeSettingsTest.php`
Expected: 10 passed.

- [ ] **Step 5: Commit**

```bash
git add app/DataTransferObjects/IncidentTypes app/Http/Requests/IncidentTypes app/Services/IncidentTypeService.php app/Http/Resources/IncidentTypeResource.php app/Http/Controllers/Admin routes/web.php tests/Feature/Admin/IncidentTypeSettingsTest.php
# plus resources/js/Pages/Admin/IncidentTypes.vue if a placeholder was created
git commit -m "feat: IT Admin lists and adds incident types

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Edit

**Files:**
- Create: `app/Http/Requests/IncidentTypes/UpdateIncidentTypeRequest.php`
- Modify: `app/Http/Controllers/Admin/IncidentTypeController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Admin/IncidentTypeSettingsTest.php`

- [ ] **Step 1: Write the failing tests**

Append to `IncidentTypeSettingsTest`:

```php
    private function editPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Falls',
            'category' => 'injury',
            'default_severity' => null,
            'is_active' => true,
        ], $overrides);
    }

    public function test_the_it_admin_edits_a_type(): void
    {
        $type = IncidentType::factory()->create(['name' => 'Fall', 'category' => 'injury']);

        $this->actingAs($this->admin())
            ->put("/admin/incident-types/{$type->id}", $this->editPayload([
                'name' => 'Falls',
                'category' => 'clinical',
                'default_severity' => 'level_4_critical',
                'is_active' => false,
            ]))->assertSessionHasNoErrors()->assertRedirect();

        $type->refresh();
        $this->assertSame('Falls', $type->name);
        $this->assertSame('clinical', $type->category);
        $this->assertSame(Severity::Level4Critical, $type->default_severity);
        $this->assertFalse($type->is_active);
    }

    public function test_editing_can_clear_the_default_severity_and_keep_the_same_name(): void
    {
        $type = IncidentType::factory()->create(['name' => 'Falls', 'category' => 'injury', 'default_severity' => Severity::Level3High]);

        $this->actingAs($this->admin())
            ->put("/admin/incident-types/{$type->id}", $this->editPayload())
            ->assertSessionHasNoErrors();

        $this->assertNull($type->fresh()->default_severity);
    }

    public function test_editing_rejects_another_types_name(): void
    {
        IncidentType::factory()->create(['name' => 'Spills']);
        $type = IncidentType::factory()->create(['name' => 'Falls', 'category' => 'injury']);

        $this->actingAs($this->admin())
            ->put("/admin/incident-types/{$type->id}", $this->editPayload(['name' => 'Spills']))
            ->assertSessionHasErrors('name');

        $this->assertSame('Falls', $type->fresh()->name);
    }

    public function test_a_type_in_use_can_still_be_edited_and_switched_off(): void
    {
        $type = IncidentType::factory()->create(['name' => 'Falls', 'category' => 'injury']);
        $this->incidentUsing($type);

        $this->actingAs($this->admin())
            ->put("/admin/incident-types/{$type->id}", $this->editPayload(['is_active' => false]))
            ->assertSessionHasNoErrors();

        $this->assertFalse($type->fresh()->is_active);
    }

    public function test_other_roles_cannot_edit_a_type(): void
    {
        $type = IncidentType::factory()->create(['name' => 'Falls', 'category' => 'injury']);

        $this->actingAs(User::factory()->create(['role' => Role::QualitySafetyOfficer]))
            ->put("/admin/incident-types/{$type->id}", $this->editPayload(['name' => 'Changed']))
            ->assertForbidden();

        $this->assertSame('Falls', $type->fresh()->name);
    }

    public function test_an_inactive_type_is_not_offered_in_the_report_wizard(): void
    {
        $active = IncidentType::factory()->create(['name' => 'Falls']);
        $inactive = IncidentType::factory()->create(['name' => 'Spills', 'is_active' => false]);

        $this->actingAs(User::factory()->create(['role' => Role::Staff]))->get('/incidents/create')
            ->assertInertia(fn ($page) => $page->has('incidentTypes', 1)->where('incidentTypes.0.id', $active->id));
    }
```

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan config:clear && php artisan test tests/Feature/Admin/IncidentTypeSettingsTest.php`
Expected: the edit tests FAIL (405/404 — no PUT route). The wizard test should already pass (existing behaviour); that is fine, it locks the behaviour in.

- [ ] **Step 3: Implement**

Create `app/Http/Requests/IncidentTypes/UpdateIncidentTypeRequest.php`:

```php
<?php

namespace App\Http\Requests\IncidentTypes;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class UpdateIncidentTypeRequest extends StoreIncidentTypeRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('incidentType'));
    }

    protected function uniqueName(): Unique
    {
        return Rule::unique('incident_types', 'name')->ignore($this->route('incidentType'));
    }
}
```

In `StoreIncidentTypeRequest`, change the `uniqueName()` return type to the imported short name: add `use Illuminate\Validation\Rules\Unique;` and write `protected function uniqueName(): Unique`.

In `IncidentTypeController` add the import `use App\Http\Requests\IncidentTypes\UpdateIncidentTypeRequest;` and the method:

```php
    public function update(UpdateIncidentTypeRequest $request, IncidentType $incidentType): RedirectResponse
    {
        $this->service->update($incidentType, $request->toDto());

        return back()->with('success', "Incident type \"{$incidentType->name}\" saved.");
    }
```

In `routes/web.php`, after the store route, add:

```php
    Route::put('/admin/incident-types/{incidentType}', [IncidentTypeController::class, 'update'])->name('admin.incident-types.update');
```

- [ ] **Step 4: Run to verify they pass**

Run: `php artisan config:clear && php artisan test tests/Feature/Admin/IncidentTypeSettingsTest.php`
Expected: 16 passed.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Requests/IncidentTypes app/Http/Controllers/Admin/IncidentTypeController.php routes/web.php tests/Feature/Admin/IncidentTypeSettingsTest.php
git commit -m "feat: IT Admin edits incident types (name, category, default severity, active)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Delete (only when unused)

**Files:**
- Modify: `app/Http/Controllers/Admin/IncidentTypeController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Admin/IncidentTypeSettingsTest.php`

- [ ] **Step 1: Write the failing tests**

Append to `IncidentTypeSettingsTest`:

```php
    public function test_the_it_admin_deletes_an_unused_type(): void
    {
        $type = IncidentType::factory()->create();

        $this->actingAs($this->admin())->delete("/admin/incident-types/{$type->id}")->assertRedirect();

        $this->assertModelMissing($type);
    }

    public function test_a_type_used_by_an_incident_cannot_be_deleted(): void
    {
        $type = IncidentType::factory()->create();
        $incident = $this->incidentUsing($type);

        $this->actingAs($this->admin())->delete("/admin/incident-types/{$type->id}")->assertForbidden();

        $this->assertModelExists($type);
        $this->assertSame([$type->id], $incident->fresh()->incidentTypes->pluck('id')->all());
    }

    public function test_a_type_on_the_legacy_column_cannot_be_deleted(): void
    {
        $type = IncidentType::factory()->create();
        $incident = $this->incidentUsing(IncidentType::factory()->create());
        DB::table('incidents')->where('id', $incident->id)->update(['incident_type_id' => $type->id]);

        $this->actingAs($this->admin())->delete("/admin/incident-types/{$type->id}")->assertForbidden();

        $this->assertModelExists($type);
    }

    public function test_a_type_used_by_a_recurrence_review_cannot_be_deleted(): void
    {
        $type = IncidentType::factory()->create();
        $this->recurrenceReviewUsing($type);

        $this->actingAs($this->admin())->delete("/admin/incident-types/{$type->id}")->assertForbidden();

        $this->assertModelExists($type);
    }

    public function test_other_roles_cannot_delete_a_type(): void
    {
        $type = IncidentType::factory()->create();

        $this->actingAs(User::factory()->create(['role' => Role::QualitySafetyOfficer]))
            ->delete("/admin/incident-types/{$type->id}")->assertForbidden();

        $this->assertModelExists($type);
    }
```

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan config:clear && php artisan test tests/Feature/Admin/IncidentTypeSettingsTest.php`
Expected: the delete tests FAIL (405 — no DELETE route).

- [ ] **Step 3: Implement**

In `IncidentTypeController` add:

```php
    public function destroy(IncidentType $incidentType): RedirectResponse
    {
        $this->authorize('delete', $incidentType);

        $this->service->delete($incidentType);

        return back()->with('success', "Incident type \"{$incidentType->name}\" deleted.");
    }
```

In `routes/web.php`, after the update route, add:

```php
    Route::delete('/admin/incident-types/{incidentType}', [IncidentTypeController::class, 'destroy'])->name('admin.incident-types.destroy');
```

- [ ] **Step 4: Run to verify they pass**

Run: `php artisan config:clear && php artisan test tests/Feature/Admin/IncidentTypeSettingsTest.php`
Expected: 21 passed.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Admin/IncidentTypeController.php routes/web.php tests/Feature/Admin/IncidentTypeSettingsTest.php
git commit -m "feat: IT Admin deletes incident types no report uses

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: The page and the sidebar link

**Files:**
- Create/replace: `resources/js/Pages/Admin/IncidentTypes.vue`
- Modify: `resources/js/Layouts/AuthenticatedLayout.vue` (the "Administration & Audit" group, ~line 83-91)

UI rules (user's standing preference): every field has a visible label, a plain hint, and its own error message. Match the look of `resources/js/Pages/Admin/Leadership.vue` (same Tailwind tokens).

- [ ] **Step 1: Write the page**

`resources/js/Pages/Admin/IncidentTypes.vue`:

```vue
<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SeverityBadge from '@/Components/SeverityBadge.vue';
import { SEVERITIES, findSeverity } from '@/Utils/severities';

const props = defineProps({
    types: { type: Array, required: true },
    categories: { type: Array, required: true },
});

const blank = { name: '', category: '', default_severity: null, is_active: true };
const form = useForm({ ...blank });
const editing = ref(null); // the type being edited, or null when adding
const formOpen = ref(false);

const chosenSeverity = computed(() => findSeverity(form.default_severity));

function openAdd() {
    editing.value = null;
    form.defaults({ ...blank });
    form.reset();
    form.clearErrors();
    formOpen.value = true;
}

function openEdit(type) {
    editing.value = type;
    form.defaults({
        name: type.name,
        category: type.category,
        default_severity: type.default_severity,
        is_active: type.is_active,
    });
    form.reset();
    form.clearErrors();
    formOpen.value = true;
}

function closeForm() {
    formOpen.value = false;
    editing.value = null;
}

function save() {
    const options = { preserveScroll: true, onSuccess: closeForm };
    if (editing.value) {
        form.put(`/admin/incident-types/${editing.value.id}`, options);
    } else {
        form.post('/admin/incident-types', options);
    }
}

function destroy(type) {
    if (!window.confirm(`Delete "${type.name}"? This cannot be undone.`)) return;
    router.delete(`/admin/incident-types/${type.id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Incident Types" />

    <AuthenticatedLayout>
        <div class="flex flex-wrap items-end justify-between gap-space-md">
            <div class="flex flex-col gap-1">
                <h1 class="font-headline-sm text-headline-sm text-on-surface">Incident Types</h1>
                <p class="font-body-sm text-body-sm text-outline">
                    The list reporters choose from. A type already used by a report can't be deleted — switch it off to hide it from new reports.
                </p>
            </div>
            <button
                v-if="!formOpen"
                type="button"
                class="px-4 py-2 rounded-lg bg-primary text-on-primary font-label-md text-label-md font-semibold"
                @click="openAdd"
            >
                Add incident type
            </button>
        </div>

        <form
            v-if="formOpen"
            class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-space-md max-w-2xl"
            @submit.prevent="save"
        >
            <h2 class="font-title-lg text-title-lg text-primary font-bold">
                {{ editing ? `Edit "${editing.name}"` : 'Add incident type' }}
            </h2>

            <div class="flex flex-col gap-1.5">
                <label for="type_name" class="font-label-md text-label-md text-on-surface font-semibold">Name</label>
                <input id="type_name" v-model="form.name" type="text" maxlength="255" class="w-full p-3 rounded-lg bg-surface-container-low" />
                <span class="font-body-sm text-body-sm text-outline">Shown to reporters in the incident form.</span>
                <span v-if="form.errors.name" class="font-body-sm text-body-sm text-error">{{ form.errors.name }}</span>
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="type_category" class="font-label-md text-label-md text-on-surface font-semibold">Category</label>
                <select id="type_category" v-model="form.category" class="w-full p-3 rounded-lg bg-surface-container-low">
                    <option value="" disabled>Choose a category</option>
                    <option v-for="category in categories" :key="category.value" :value="category.value">{{ category.label }}</option>
                </select>
                <span class="font-body-sm text-body-sm text-outline">Groups similar types together.</span>
                <span v-if="form.errors.category" class="font-body-sm text-body-sm text-error">{{ form.errors.category }}</span>
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="type_severity" class="font-label-md text-label-md text-on-surface font-semibold">Default severity</label>
                <select id="type_severity" v-model="form.default_severity" class="w-full p-3 rounded-lg bg-surface-container-low">
                    <option :value="null">No default</option>
                    <option v-for="severity in SEVERITIES" :key="severity.value" :value="severity.value">
                        {{ severity.numeral }} – {{ severity.label }}
                    </option>
                </select>
                <span class="font-body-sm text-body-sm text-outline">
                    {{ chosenSeverity ? chosenSeverity.meaning : 'Pre-selected level for this type. Leave as No default if it varies.' }}
                </span>
                <span v-if="form.errors.default_severity" class="font-body-sm text-body-sm text-error">{{ form.errors.default_severity }}</span>
            </div>

            <div class="flex flex-col gap-1.5">
                <label class="flex items-center gap-2 font-label-md text-label-md text-on-surface font-semibold">
                    <input v-model="form.is_active" type="checkbox" />
                    Active
                </label>
                <span class="font-body-sm text-body-sm text-outline">Off hides this type from new reports. Past reports keep it.</span>
                <span v-if="form.errors.is_active" class="font-body-sm text-body-sm text-error">{{ form.errors.is_active }}</span>
            </div>

            <div class="flex gap-space-sm">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="px-4 py-2 rounded-lg bg-primary text-on-primary font-label-md text-label-md font-semibold disabled:opacity-60"
                >
                    Save
                </button>
                <button type="button" class="px-4 py-2 rounded-lg bg-surface-container font-label-md text-label-md text-on-surface" @click="closeForm">
                    Cancel
                </button>
            </div>
        </form>

        <div class="rounded-xl bg-surface-container-lowest shadow-sm overflow-x-auto">
            <table class="w-full text-left">
                <thead class="bg-surface-container-low font-label-md text-label-md text-on-surface-variant">
                    <tr>
                        <th class="px-space-md py-3">Name</th>
                        <th class="px-space-md py-3">Category</th>
                        <th class="px-space-md py-3">Default severity</th>
                        <th class="px-space-md py-3">Status</th>
                        <th class="px-space-md py-3">Used by</th>
                        <th class="px-space-md py-3"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="font-body-md text-body-md text-on-surface">
                    <tr v-if="!types.length">
                        <td colspan="6" class="px-space-md py-4 text-outline">No incident types yet.</td>
                    </tr>
                    <tr v-for="type in types" :key="type.id" class="border-t border-outline-variant">
                        <td class="px-space-md py-3 font-semibold">{{ type.name }}</td>
                        <td class="px-space-md py-3">{{ type.category_label }}</td>
                        <td class="px-space-md py-3">
                            <SeverityBadge v-if="type.default_severity" :severity="type.default_severity" />
                            <span v-else class="text-outline">No default</span>
                        </td>
                        <td class="px-space-md py-3">
                            <span
                                :class="type.is_active ? 'bg-emerald-100 text-emerald-900' : 'bg-surface-container text-on-surface-variant'"
                                class="px-2.5 py-0.5 rounded-full font-label-sm text-body-sm font-semibold"
                            >
                                {{ type.is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </td>
                        <td class="px-space-md py-3">{{ type.usage_count }} {{ type.usage_count === 1 ? 'incident' : 'incidents' }}</td>
                        <td class="px-space-md py-3">
                            <div class="flex items-center justify-end gap-space-sm">
                                <button type="button" class="px-3 py-1.5 rounded-lg bg-surface-container font-label-md text-label-md text-on-surface" @click="openEdit(type)">
                                    Edit
                                </button>
                                <button
                                    v-if="type.can_delete"
                                    type="button"
                                    class="px-3 py-1.5 rounded-lg bg-error text-on-error font-label-md text-label-md"
                                    @click="destroy(type)"
                                >
                                    Delete
                                </button>
                                <span v-else class="font-body-sm text-body-sm text-outline max-w-[14rem]">In use — can't be deleted. Switch it off to hide it instead.</span>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 2: Add the sidebar link**

In `resources/js/Layouts/AuthenticatedLayout.vue`, in the "Administration & Audit" group's `items`, after the Leadership Coverage line, add:

```js
            { label: 'Incident Types', icon: 'tags', href: '/admin/incident-types', can: 'manageIncidentTypes' },
```

Check how the layout maps `icon` strings to Font Awesome icons (search the file for `icon` / `library.add` / an icon map, and `resources/js/app.js`). If `tags` isn't registered, register `faTags` from `@fortawesome/free-solid-svg-icons` the same way the other sidebar icons are registered.

- [ ] **Step 3: Build**

Run: `npm run build`
Expected: build succeeds with no errors.

- [ ] **Step 4: Run the full suite**

Run: `php artisan config:clear && php artisan test`
Expected: all tests pass (previous total + 21 new).

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/Admin/IncidentTypes.vue resources/js/Layouts/AuthenticatedLayout.vue
# plus resources/js/app.js (or wherever icons are registered) if changed
git commit -m "feat: Incident Types settings page and sidebar link for the IT Admin

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Docs

**Files:**
- Modify: `docs/architecture.md` (append a new section at the end, following the numbering style of the existing §9 subsections)

- [ ] **Step 1: Append a short section**

Add a section titled "Incident Types settings (2026-10-06)" covering: IT Admin only (`IncidentTypePolicy`, `auth.can.manageIncidentTypes`); routes `/admin/incident-types` (index/store/update/destroy); fields (name unique, category from `IncidentType::CATEGORIES`, default severity optional, active); delete only when unused (pivot, legacy `incidents.incident_type_id`, or recurrence reviews) otherwise switch off; `default_severity` is stored only — nothing in the workflow reads it yet; trimmed layered pattern (FormRequests, DTO, Service, Policy, Resource; no Repository/Action/Query).

- [ ] **Step 2: Commit**

```bash
git add docs/architecture.md
git commit -m "docs: incident types settings

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

## After all tasks (controller, not a subagent)

- Holistic review of the whole diff against the spec.
- Browser check (scratch SQLite, `php -S` from `public/` with env overrides — see project memory): as an Administrator add a type, edit it (set severity, switch off), delete an unused type, confirm an in-use row shows no Delete; confirm a CQI user doesn't see the sidebar link and gets 403 on the URL.
