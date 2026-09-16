# Phase 3: Incident Reporting — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [x]`) syntax for tracking.

**Goal:** Let any authenticated hospital staff member create, save-as-draft, edit, and submit an incident report through an 8-step Vue/Inertia wizard, then browse a scoped incident list and view a submitted report's detail page — all backed by real database records, server-side validation, and policy-enforced authorization.

**Architecture:** Additive migrations for `incidents` and its child tables (individuals, witnesses, actions, narrative events, contributing factors, polymorphic attachments); a thin `IncidentService` owns incident-number generation and the draft→submit transition; two Form Requests (store/update) share one validation-rules trait that branches on an `action` field (`draft` = partial, `submit` = full); `IncidentPolicy` gates every read/write; the Vue side is one `Wizard.vue` page (reused for create and edit) composed of 8 small step components sharing one Inertia `useForm`, plus `Index.vue` (scoped list) and `Show.vue` (detail with an Overview tab built now and placeholder shells for the Investigation/CAPA/Attachments/Approvals/Audit tabs that later phases fill in).

**Tech Stack:** Laravel 9 (PHP 8.2 native enums), MySQL (InnoDB forced per `config/database.php`), Inertia v1.3.4 server / `@inertiajs/vue3` v2.x client (pinned per `docs/architecture.md` §9a — do not bump to v3 without also upgrading `inertiajs/inertia-laravel`), Tailwind v3 with the Stitch design tokens already in `tailwind.config.js`, Font Awesome via `@fortawesome/vue-fontawesome`.

**Deliberately out of scope for this phase** (belongs to later phases per `docs/architecture.md` and the original brief's phase breakdown — do not build these now):
- `config/incident_workflow.php`, SLA/escalation logic, `audit_logs` table and the AuditLogger observer, the Timeline tab's real content — Phase 4 (Workflow).
- `investigations`, `corrective_actions`, `approvals` tables and their tabs' real content — Phases 5–7.
- Deleting a draft, sidebar nav badge counts, dashboard KPI wiring — nice-to-haves, not required by the Phase 3 brief; adding them now would be scope creep.

---

## File Structure

**Backend — new files:**
- `database/migrations/2026_09_17_000001_create_incidents_table.php`
- `database/migrations/2026_09_17_000002_create_incident_individuals_table.php`
- `database/migrations/2026_09_17_000003_create_incident_witnesses_table.php`
- `database/migrations/2026_09_17_000004_create_incident_actions_table.php`
- `database/migrations/2026_09_17_000005_create_incident_narrative_events_table.php`
- `database/migrations/2026_09_17_000006_create_contributing_factors_table.php`
- `database/migrations/2026_09_17_000007_create_incident_contributing_factor_table.php`
- `database/migrations/2026_09_17_000008_create_attachments_table.php`
- `app/Enums/IncidentStatus.php`, `app/Enums/PersonType.php`, `app/Enums/ActionStatus.php`, `app/Enums/AttachmentCategory.php`
- `app/Models/Incident.php`, `IncidentIndividual.php`, `IncidentWitness.php`, `IncidentAction.php`, `IncidentNarrativeEvent.php`, `ContributingFactor.php`, `Attachment.php`
- `database/seeders/ContributingFactorSeeder.php` (+ one line added to `DatabaseSeeder.php`)
- `app/Services/IncidentService.php`
- `app/Http/Requests/Concerns/ValidatesIncidentData.php`, `app/Http/Requests/Incidents/StoreIncidentRequest.php`, `app/Http/Requests/Incidents/UpdateIncidentRequest.php`
- `app/Policies/IncidentPolicy.php` (+ registration in `app/Providers/AuthServiceProvider.php`)
- `app/Http/Controllers/IncidentController.php`, `app/Http/Controllers/AttachmentController.php`
- `routes/web.php` (modify — add incident + attachment routes)
- `tests/Feature/Incidents/IncidentReportingTest.php`

**Frontend — new files:**
- `resources/js/Composables/useIncidentStatus.js`
- `resources/js/Components/StatusBadge.vue`, `resources/js/Components/SeverityBadge.vue`, `resources/js/Components/ConfirmationDialog.vue`
- `resources/js/Pages/Incidents/Wizard.vue`
- `resources/js/Components/Incidents/Step1ReporterInfo.vue` … `Step8Review.vue` (8 files)
- `resources/js/Pages/Incidents/Index.vue`
- `resources/js/Pages/Incidents/Show.vue`
- `resources/js/Layouts/AuthenticatedLayout.vue` (modify — wire 4 sidebar links to real routes)

---

### Task 1: Database schema

**Files:** the 8 migration files listed above.

- [x] **Step 1: Generate the migration stubs**

```bash
cd C:\wamp64\projects\incident-report
php artisan make:migration create_incidents_table
php artisan make:migration create_incident_individuals_table
php artisan make:migration create_incident_witnesses_table
php artisan make:migration create_incident_actions_table
php artisan make:migration create_incident_narrative_events_table
php artisan make:migration create_contributing_factors_table
php artisan make:migration create_incident_contributing_factor_table
php artisan make:migration create_attachments_table
```

Rename each generated file's timestamp prefix to match the exact filenames listed under File Structure above, in that order (so they run in that order — `incidents` before every table that has `incident_id`, `contributing_factors` before the pivot table).

- [x] **Step 2: Write `create_incidents_table`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->string('incident_number')->nullable()->unique();
            $table->foreignId('reporter_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('incident_type_id')->nullable()->constrained('incident_types')->nullOnDelete();
            $table->string('severity')->nullable();
            $table->string('status')->default('draft');
            $table->dateTime('occurred_at')->nullable();
            $table->dateTime('reported_at')->nullable();
            $table->string('location')->nullable();
            $table->text('summary')->nullable();
            $table->text('recommendations')->nullable();
            $table->boolean('is_sentinel_event')->default(false);
            $table->boolean('police_notified')->default(false);
            $table->string('police_station')->nullable();
            $table->string('police_officer_in_charge')->nullable();
            $table->string('police_blotter_no')->nullable();
            $table->dateTime('police_notified_at')->nullable();
            $table->foreignId('assigned_investigator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('supervisor_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('supervisor_reviewed_at')->nullable();
            $table->text('supervisor_comments')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('closed_at')->nullable();
            $table->date('target_closure_date')->nullable();
            $table->dateTime('legal_attestation_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down()
    {
        Schema::dropIfExists('incidents');
    }
};
```

Note: `recommendations` (text, nullable) is an addition beyond what `docs/architecture.md` §2.2 listed — the original paper-form brief has a "Recommendations / Preventive Measures" section (wizard step 7) that the architecture doc's incidents column list omitted. Adding it here; Task 15 updates the doc to match.

- [x] **Step 3: Write `create_incident_individuals_table`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('incident_individuals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
            $table->string('person_type');
            $table->string('name');
            $table->string('identifier')->nullable();
            $table->string('role_description')->nullable();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->text('details')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('incident_individuals');
    }
};
```

- [x] **Step 4: Write `create_incident_witnesses_table`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('incident_witnesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
            $table->string('name');
            $table->string('designation')->nullable();
            $table->string('address')->nullable();
            $table->string('contact_number')->nullable();
            $table->text('statement')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('incident_witnesses');
    }
};
```

- [x] **Step 5: Write `create_incident_actions_table`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('incident_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
            $table->text('description');
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('responsible_name')->nullable();
            $table->dateTime('performed_at')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('incident_actions');
    }
};
```

- [x] **Step 6: Write `create_incident_narrative_events_table`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('incident_narrative_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
            $table->string('occurred_at')->nullable();
            $table->text('description');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('incident_narrative_events');
    }
};
```

`occurred_at` is a free-text string (e.g. "22:30 PST"), not a strict time column — reporters write approximate/relative times here, matching the Stitch mockup's timeline entries.

- [x] **Step 7: Write `create_contributing_factors_table`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('contributing_factors', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->string('category')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('contributing_factors');
    }
};
```

- [x] **Step 8: Write `create_incident_contributing_factor_table`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('incident_contributing_factor', function (Blueprint $table) {
            $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
            $table->foreignId('contributing_factor_id')->constrained('contributing_factors')->cascadeOnDelete();
            $table->primary(['incident_id', 'contributing_factor_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('incident_contributing_factor');
    }
};
```

- [x] **Step 9: Write `create_attachments_table`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->string('attachable_type');
            $table->unsignedBigInteger('attachable_id');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disk_path');
            $table->string('original_filename');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('category')->default('evidence');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->index(['attachable_type', 'attachable_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('attachments');
    }
};
```

- [x] **Step 10: Run migrations and verify**

```bash
php artisan migrate
```

Expected: all 8 new migrations show `DONE`, no errors. This is against the real dev DB (`laravel` on the WAMP MySQL instance) — safe, since it's additive and the DB currently only holds Phase 2 seed data.

- [x] **Step 11: Commit**

```bash
git add database/migrations
git commit -m "feat: add incident reporting database schema"
```

---

### Task 2: Enums

**Files:**
- Create: `app/Enums/IncidentStatus.php`, `app/Enums/PersonType.php`, `app/Enums/ActionStatus.php`, `app/Enums/AttachmentCategory.php`

- [x] **Step 1: Write `IncidentStatus`**

```php
<?php

namespace App\Enums;

enum IncidentStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case ForReview = 'for_review';
    case Reviewed = 'reviewed';
    case Assigned = 'assigned';
    case UnderInvestigation = 'under_investigation';
    case CorrectiveAction = 'corrective_action';
    case ForVerification = 'for_verification';
    case Verified = 'verified';
    case ForApproval = 'for_approval';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Submitted',
            self::ForReview => 'For Review',
            self::Reviewed => 'Reviewed',
            self::Assigned => 'Assigned',
            self::UnderInvestigation => 'Under Investigation',
            self::CorrectiveAction => 'Corrective Action',
            self::ForVerification => 'For Verification',
            self::Verified => 'Verified',
            self::ForApproval => 'For Approval',
            self::Closed => 'Closed',
        };
    }

    public function isDraft(): bool
    {
        return $this === self::Draft;
    }
}
```

- [x] **Step 2: Write `PersonType`**

```php
<?php

namespace App\Enums;

enum PersonType: string
{
    case Patient = 'patient';
    case Staff = 'staff';
    case Visitor = 'visitor';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Patient => 'Patient',
            self::Staff => 'Staff',
            self::Visitor => 'Visitor',
            self::Other => 'Other',
        };
    }
}
```

- [x] **Step 3: Write `ActionStatus`**

```php
<?php

namespace App\Enums;

enum ActionStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Completed => 'Completed',
        };
    }
}
```

- [x] **Step 4: Write `AttachmentCategory`**

```php
<?php

namespace App\Enums;

enum AttachmentCategory: string
{
    case Evidence = 'evidence';
    case Photo = 'photo';
    case Document = 'document';
    case Report = 'report';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Evidence => 'Evidence',
            self::Photo => 'Photo',
            self::Document => 'Document',
            self::Report => 'Report',
            self::Other => 'Other',
        };
    }
}
```

- [x] **Step 5: Commit**

```bash
git add app/Enums
git commit -m "feat: add incident domain enums"
```

---

### Task 3: Models

**Files:** the 7 model files listed under File Structure.

- [x] **Step 1: Write `Incident`**

```php
<?php

namespace App\Models;

use App\Enums\IncidentStatus;
use App\Enums\Severity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Incident extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'department_id',
        'incident_type_id',
        'severity',
        'occurred_at',
        'location',
        'summary',
        'recommendations',
        'police_notified',
        'police_station',
        'police_officer_in_charge',
        'police_blotter_no',
        'police_notified_at',
    ];

    protected $casts = [
        'severity' => Severity::class,
        'status' => IncidentStatus::class,
        'is_sentinel_event' => 'boolean',
        'police_notified' => 'boolean',
        'occurred_at' => 'datetime',
        'reported_at' => 'datetime',
        'police_notified_at' => 'datetime',
        'supervisor_reviewed_at' => 'datetime',
        'closed_at' => 'datetime',
        'target_closure_date' => 'date',
        'legal_attestation_at' => 'datetime',
    ];

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function incidentType(): BelongsTo
    {
        return $this->belongsTo(IncidentType::class);
    }

    public function assignedInvestigator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_investigator_id');
    }

    public function individuals(): HasMany
    {
        return $this->hasMany(IncidentIndividual::class);
    }

    public function witnesses(): HasMany
    {
        return $this->hasMany(IncidentWitness::class);
    }

    public function actions(): HasMany
    {
        return $this->hasMany(IncidentAction::class);
    }

    public function narrativeEvents(): HasMany
    {
        return $this->hasMany(IncidentNarrativeEvent::class)->orderBy('sort_order');
    }

    public function contributingFactors(): BelongsToMany
    {
        return $this->belongsToMany(ContributingFactor::class, 'incident_contributing_factor');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
```

- [x] **Step 2: Write `IncidentIndividual`**

```php
<?php

namespace App\Models;

use App\Enums\PersonType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentIndividual extends Model
{
    protected $fillable = [
        'person_type', 'name', 'identifier', 'role_description', 'department_id', 'details',
    ];

    protected $casts = [
        'person_type' => PersonType::class,
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
```

- [x] **Step 3: Write `IncidentWitness`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentWitness extends Model
{
    protected $fillable = ['name', 'designation', 'address', 'contact_number', 'statement'];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }
}
```

- [x] **Step 4: Write `IncidentAction`**

```php
<?php

namespace App\Models;

use App\Enums\ActionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentAction extends Model
{
    protected $fillable = ['description', 'responsible_user_id', 'responsible_name', 'performed_at', 'status'];

    protected $casts = [
        'status' => ActionStatus::class,
        'performed_at' => 'datetime',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }
}
```

- [x] **Step 5: Write `IncidentNarrativeEvent`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentNarrativeEvent extends Model
{
    protected $fillable = ['occurred_at', 'description', 'sort_order'];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }
}
```

- [x] **Step 6: Write `ContributingFactor`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ContributingFactor extends Model
{
    protected $fillable = ['label', 'category', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function incidents(): BelongsToMany
    {
        return $this->belongsToMany(Incident::class, 'incident_contributing_factor');
    }
}
```

- [x] **Step 7: Write `Attachment`**

```php
<?php

namespace App\Models;

use App\Enums\AttachmentCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Attachment extends Model
{
    protected $fillable = [
        'uploaded_by', 'disk_path', 'original_filename', 'mime_type', 'size', 'category', 'description',
    ];

    protected $casts = [
        'category' => AttachmentCategory::class,
        'size' => 'integer',
    ];

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
```

- [x] **Step 8: Verify with tinker**

```bash
php artisan tinker --execute="echo App\Models\Incident::class . ' OK';"
```

Expected: no fatal errors, prints `App\Models\Incident OK`.

- [x] **Step 9: Commit**

```bash
git add app/Models
git commit -m "feat: add incident reporting Eloquent models"
```

---

### Task 4: Contributing factors seeder

**Files:**
- Create: `database/seeders/ContributingFactorSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`

- [x] **Step 1: Write the seeder**

```php
<?php

namespace Database\Seeders;

use App\Models\ContributingFactor;
use Illuminate\Database\Seeder;

class ContributingFactorSeeder extends Seeder
{
    public function run(): void
    {
        $factors = [
            ['label' => 'High Patient Surge / ER Saturation', 'category' => 'staffing'],
            ['label' => 'Shift Handover Interruption', 'category' => 'staffing'],
            ['label' => 'Omitted Dual Verification', 'category' => 'procedure'],
            ['label' => 'Device Screen Dimming Fault', 'category' => 'equipment'],
            ['label' => 'Ambient Noise / Environmental Distraction', 'category' => 'environment'],
            ['label' => 'Workstation / System Downtime', 'category' => 'equipment'],
            ['label' => 'Inadequate Labeling or Documentation', 'category' => 'procedure'],
            ['label' => 'Communication Breakdown Between Units', 'category' => 'staffing'],
        ];

        foreach ($factors as $factor) {
            ContributingFactor::firstOrCreate(['label' => $factor['label']], $factor);
        }
    }
}
```

- [x] **Step 2: Register it in `DatabaseSeeder`**

In `database/seeders/DatabaseSeeder.php`, change:

```php
        $this->call([
            DepartmentSeeder::class,
            IncidentTypeSeeder::class,
            DevUserSeeder::class,
        ]);
```

to:

```php
        $this->call([
            DepartmentSeeder::class,
            IncidentTypeSeeder::class,
            DevUserSeeder::class,
            ContributingFactorSeeder::class,
        ]);
```

- [x] **Step 3: Run it**

```bash
php artisan db:seed --class=ContributingFactorSeeder
```

Expected: `DONE`, no errors.

- [x] **Step 4: Commit**

```bash
git add database/seeders
git commit -m "feat: seed contributing factors"
```

---

### Task 5: IncidentService

**Files:**
- Create: `app/Services/IncidentService.php`
- Test: `tests/Feature/Incidents/IncidentReportingTest.php` (created here, extended in later tasks)

- [x] **Step 1: Write the failing test file**

```php
<?php

namespace Tests\Feature\Incidents;

use App\Enums\IncidentStatus;
use App\Enums\Severity;
use App\Models\Department;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\User;
use App\Services\IncidentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidentReportingTest extends TestCase
{
    use RefreshDatabase;

    private function makeReporter(): User
    {
        return User::factory()->create();
    }

    public function test_create_draft_creates_an_incident_owned_by_the_reporter(): void
    {
        $reporter = $this->makeReporter();
        $service = app(IncidentService::class);

        $incident = $service->createDraft($reporter, [
            'location' => 'Emergency Department, Bay 3',
        ]);

        $this->assertDatabaseHas('incidents', [
            'id' => $incident->id,
            'reporter_id' => $reporter->id,
            'status' => IncidentStatus::Draft->value,
            'location' => 'Emergency Department, Bay 3',
        ]);
        $this->assertNull($incident->incident_number);
    }

    public function test_submit_assigns_a_sequential_incident_number_and_changes_status(): void
    {
        $reporter = $this->makeReporter();
        $department = Department::factory()->create();
        $incidentType = IncidentType::factory()->create();
        $service = app(IncidentService::class);

        $first = $service->createDraft($reporter, [
            'department_id' => $department->id,
            'incident_type_id' => $incidentType->id,
            'severity' => Severity::Level2Moderate->value,
            'occurred_at' => now(),
            'location' => 'Ward 3',
            'summary' => 'Patient fall near the nurses station.',
        ]);
        $service->submit($first);

        $second = $service->createDraft($reporter, [
            'department_id' => $department->id,
            'incident_type_id' => $incidentType->id,
            'severity' => Severity::Level1Low->value,
            'occurred_at' => now(),
            'location' => 'Ward 4',
            'summary' => 'Near miss with medication cart.',
        ]);
        $service->submit($second);

        $year = now()->year;
        $this->assertSame("IR-{$year}-000001", $first->fresh()->incident_number);
        $this->assertSame("IR-{$year}-000002", $second->fresh()->incident_number);
        $this->assertSame(IncidentStatus::Submitted, $second->fresh()->status);
        $this->assertNotNull($second->fresh()->reported_at);
    }

    public function test_submit_marks_level_4_incidents_as_sentinel_events(): void
    {
        $reporter = $this->makeReporter();
        $service = app(IncidentService::class);

        $incident = $service->createDraft($reporter, [
            'severity' => Severity::Level4CriticalSentinel->value,
            'occurred_at' => now(),
            'location' => 'ICU Bed 2',
            'summary' => 'Sentinel event.',
        ]);
        $service->submit($incident);

        $this->assertTrue($incident->fresh()->is_sentinel_event);
    }
}
```

- [x] **Step 2: Run it to see it fail**

```bash
php artisan test --filter=IncidentReportingTest
```

Expected: FAIL — `Class "App\Services\IncidentService" not found` (or similar), since factories for `Department`/`IncidentType` and the service don't exist yet.

- [x] **Step 3: Add factories the test needs**

```bash
php artisan make:factory DepartmentFactory --model=Department
php artisan make:factory IncidentTypeFactory --model=IncidentType
```

Edit `database/factories/DepartmentFactory.php`:

```php
<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class DepartmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->company() . ' Department',
            'code' => strtoupper($this->faker->unique()->lexify('???')),
            'is_active' => true,
        ];
    }
}
```

Edit `database/factories/IncidentTypeFactory.php`:

```php
<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class IncidentTypeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->sentence(3),
            'category' => 'clinical_safety',
            'is_active' => true,
        ];
    }
}
```

Add `use HasFactory;` and `use Illuminate\Database\Eloquent\Factories\HasFactory;` to `app/Models/Department.php` and `app/Models/IncidentType.php` if not already present (`Department` already has it from Phase 2; `IncidentType` already has it too — confirm, don't duplicate the trait use line).

- [x] **Step 4: Write `IncidentService`**

```php
<?php

namespace App\Services;

use App\Enums\IncidentStatus;
use App\Enums\Severity;
use App\Models\ContributingFactor;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class IncidentService
{
    public function createDraft(User $reporter, array $data): Incident
    {
        $incident = new Incident($this->onlyIncidentColumns($data));
        $incident->reporter_id = $reporter->id;
        $incident->status = IncidentStatus::Draft;
        $incident->save();

        $this->syncChildRecords($incident, $data);

        return $incident;
    }

    public function updateDraft(Incident $incident, array $data): Incident
    {
        $incident->fill($this->onlyIncidentColumns($data));
        $incident->save();

        $this->syncChildRecords($incident, $data);

        return $incident;
    }

    public function submit(Incident $incident): Incident
    {
        DB::transaction(function () use ($incident) {
            $incident->incident_number = $this->generateIncidentNumber();
            $incident->status = IncidentStatus::Submitted;
            $incident->reported_at = now();
            $incident->legal_attestation_at = now();
            $incident->is_sentinel_event = $incident->severity === Severity::Level4CriticalSentinel;
            $incident->save();
        });

        return $incident;
    }

    private function generateIncidentNumber(): string
    {
        $year = now()->year;
        $prefix = "IR-{$year}-";

        $count = Incident::whereNotNull('incident_number')
            ->where('incident_number', 'like', $prefix . '%')
            ->lockForUpdate()
            ->count();

        return $prefix . str_pad((string) ($count + 1), 6, '0', STR_PAD_LEFT);
    }

    private function onlyIncidentColumns(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'department_id', 'incident_type_id', 'severity', 'occurred_at', 'location',
            'summary', 'recommendations', 'police_notified', 'police_station',
            'police_officer_in_charge', 'police_blotter_no', 'police_notified_at',
        ]));
    }

    private function syncChildRecords(Incident $incident, array $data): void
    {
        if (array_key_exists('individuals', $data)) {
            $incident->individuals()->delete();
            $incident->individuals()->createMany($data['individuals'] ?? []);
        }

        if (array_key_exists('witnesses', $data)) {
            $incident->witnesses()->delete();
            $incident->witnesses()->createMany($data['witnesses'] ?? []);
        }

        if (array_key_exists('actions_taken', $data)) {
            $incident->actions()->delete();
            $incident->actions()->createMany($data['actions_taken'] ?? []);
        }

        if (array_key_exists('narrative_events', $data)) {
            $incident->narrativeEvents()->delete();
            $events = collect($data['narrative_events'] ?? [])->values()->map(fn ($event, $i) => [
                ...$event,
                'sort_order' => $i,
            ])->all();
            $incident->narrativeEvents()->createMany($events);
        }

        if (array_key_exists('contributing_factor_ids', $data)) {
            $incident->contributingFactors()->sync(
                ContributingFactor::whereIn('id', $data['contributing_factor_ids'] ?? [])->pluck('id')
            );
        }
    }
}
```

- [x] **Step 5: Run tests to see them pass**

```bash
php artisan test --filter=IncidentReportingTest
```

Expected: `3 passed`.

- [x] **Step 6: Commit**

```bash
git add app/Services database/factories app/Models/Department.php app/Models/IncidentType.php tests/Feature/Incidents
git commit -m "feat: add IncidentService with draft/submit and incident-number generation"
```

---

### Task 6: Form Requests

**Files:**
- Create: `app/Http/Requests/Concerns/ValidatesIncidentData.php`, `app/Http/Requests/Incidents/StoreIncidentRequest.php`, `app/Http/Requests/Incidents/UpdateIncidentRequest.php`

- [x] **Step 1: Write the shared rules trait**

```php
<?php

namespace App\Http\Requests\Concerns;

use App\Enums\ActionStatus;
use App\Enums\PersonType;
use App\Enums\Severity;
use Illuminate\Validation\Rules\Enum;

trait ValidatesIncidentData
{
    protected function incidentRules(): array
    {
        $submitting = $this->input('action') === 'submit';
        $required = $submitting ? 'required' : 'nullable';

        return [
            'action' => ['required', 'in:draft,submit'],
            'incident_type_id' => [$required, 'exists:incident_types,id'],
            'department_id' => [$required, 'exists:departments,id'],
            'severity' => [$required, new Enum(Severity::class)],
            'occurred_at' => [$required, 'date'],
            'location' => [$required, 'string', 'max:255'],
            'summary' => [$required, 'string'],
            'recommendations' => ['nullable', 'string'],
            'legal_attestation' => [$submitting ? 'accepted' : 'nullable'],

            'police_notified' => ['boolean'],
            'police_station' => ['nullable', 'string', 'max:255'],
            'police_officer_in_charge' => ['nullable', 'string', 'max:255'],
            'police_blotter_no' => ['nullable', 'string', 'max:255'],
            'police_notified_at' => ['nullable', 'date'],

            'individuals' => ['array'],
            'individuals.*.person_type' => ['required_with:individuals', new Enum(PersonType::class)],
            'individuals.*.name' => ['required_with:individuals', 'string', 'max:255'],
            'individuals.*.identifier' => ['nullable', 'string', 'max:255'],
            'individuals.*.role_description' => ['nullable', 'string', 'max:255'],
            'individuals.*.department_id' => ['nullable', 'exists:departments,id'],
            'individuals.*.details' => ['nullable', 'string'],

            'witnesses' => ['array'],
            'witnesses.*.name' => ['required_with:witnesses', 'string', 'max:255'],
            'witnesses.*.designation' => ['nullable', 'string', 'max:255'],
            'witnesses.*.address' => ['nullable', 'string', 'max:255'],
            'witnesses.*.contact_number' => ['nullable', 'string', 'max:50'],
            'witnesses.*.statement' => ['nullable', 'string'],

            'narrative_events' => ['array'],
            'narrative_events.*.occurred_at' => ['nullable', 'string', 'max:50'],
            'narrative_events.*.description' => ['required_with:narrative_events', 'string'],

            'actions_taken' => ['array'],
            'actions_taken.*.description' => ['required_with:actions_taken', 'string'],
            'actions_taken.*.responsible_name' => ['nullable', 'string', 'max:255'],
            'actions_taken.*.performed_at' => ['nullable', 'date'],
            'actions_taken.*.status' => ['nullable', new Enum(ActionStatus::class)],

            'contributing_factor_ids' => ['array'],
            'contributing_factor_ids.*' => ['exists:contributing_factors,id'],

            'attachments' => ['array'],
            'attachments.*' => ['file', 'max:25600', 'mimes:pdf,png,jpg,jpeg,doc,docx'],
        ];
    }
}
```

- [x] **Step 2: Write `StoreIncidentRequest`**

```php
<?php

namespace App\Http\Requests\Incidents;

use App\Http\Requests\Concerns\ValidatesIncidentData;
use App\Models\Incident;
use Illuminate\Foundation\Http\FormRequest;

class StoreIncidentRequest extends FormRequest
{
    use ValidatesIncidentData;

    public function authorize(): bool
    {
        return $this->user()->can('create', Incident::class);
    }

    public function rules(): array
    {
        return $this->incidentRules();
    }
}
```

- [x] **Step 3: Write `UpdateIncidentRequest`**

```php
<?php

namespace App\Http\Requests\Incidents;

use App\Http\Requests\Concerns\ValidatesIncidentData;
use Illuminate\Foundation\Http\FormRequest;

class UpdateIncidentRequest extends FormRequest
{
    use ValidatesIncidentData;

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('incident'));
    }

    public function rules(): array
    {
        return $this->incidentRules();
    }
}
```

- [x] **Step 4: Commit**

```bash
git add app/Http/Requests
git commit -m "feat: add incident store/update form requests"
```

---

### Task 7: IncidentPolicy

**Files:**
- Create: `app/Policies/IncidentPolicy.php`
- Modify: `app/Providers/AuthServiceProvider.php`
- Modify: `tests/Feature/Incidents/IncidentReportingTest.php` (add authorization tests)

- [x] **Step 1: Add failing authorization tests**

Append to `tests/Feature/Incidents/IncidentReportingTest.php`, inside the class:

```php
    public function test_owner_can_view_and_update_their_own_draft(): void
    {
        $reporter = $this->makeReporter();
        $incident = app(IncidentService::class)->createDraft($reporter, ['location' => 'ER']);

        $this->assertTrue($reporter->can('view', $incident));
        $this->assertTrue($reporter->can('update', $incident));
    }

    public function test_other_staff_cannot_view_or_update_someone_elses_draft(): void
    {
        $reporter = $this->makeReporter();
        $other = $this->makeReporter();
        $incident = app(IncidentService::class)->createDraft($reporter, ['location' => 'ER']);

        $this->assertFalse($other->can('view', $incident));
        $this->assertFalse($other->can('update', $incident));
    }

    public function test_owner_cannot_update_after_submission(): void
    {
        $reporter = $this->makeReporter();
        $incident = app(IncidentService::class)->createDraft($reporter, [
            'occurred_at' => now(), 'location' => 'ER', 'summary' => 'x',
        ]);
        app(IncidentService::class)->submit($incident);

        $this->assertFalse($reporter->can('update', $incident->fresh()));
        $this->assertTrue($reporter->can('view', $incident->fresh()));
    }

    public function test_supervisor_can_view_submitted_incidents_from_any_department_but_not_others_drafts(): void
    {
        $reporter = $this->makeReporter();
        $supervisor = User::factory()->create(['role' => \App\Enums\Role::Supervisor]);

        $draft = app(IncidentService::class)->createDraft($reporter, ['location' => 'ER']);
        $this->assertFalse($supervisor->can('view', $draft));

        $submitted = app(IncidentService::class)->createDraft($reporter, [
            'occurred_at' => now(), 'location' => 'ER', 'summary' => 'x',
        ]);
        app(IncidentService::class)->submit($submitted);
        $this->assertTrue($supervisor->can('view', $submitted->fresh()));
    }
```

- [x] **Step 2: Run to see the new tests fail**

```bash
php artisan test --filter=IncidentReportingTest
```

Expected: FAIL — `Call to undefined method ... can()` resolves fine (that's Laravel core), but assertions fail because no policy is registered yet, so `can()` returns `false` for everything including the owner checks.

- [x] **Step 3: Write `IncidentPolicy`**

```php
<?php

namespace App\Policies;

use App\Enums\IncidentStatus;
use App\Enums\Role;
use App\Models\Incident;
use App\Models\User;

class IncidentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role !== Role::Staff;
    }

    public function view(User $user, Incident $incident): bool
    {
        if ($incident->reporter_id === $user->id) {
            return true;
        }

        if ($incident->status === IncidentStatus::Draft) {
            return false;
        }

        if ($incident->assigned_investigator_id === $user->id) {
            return true;
        }

        return in_array($user->role, [
            Role::Supervisor,
            Role::DepartmentHead,
            Role::QualitySafetyOfficer,
            Role::Administrator,
            Role::Management,
        ], true);
    }

    public function create(User $user): bool
    {
        return $user->is_active;
    }

    public function update(User $user, Incident $incident): bool
    {
        return $incident->reporter_id === $user->id && $incident->status === IncidentStatus::Draft;
    }

    public function delete(User $user, Incident $incident): bool
    {
        return $this->update($user, $incident);
    }
}
```

- [x] **Step 4: Register the policy**

In `app/Providers/AuthServiceProvider.php`, change:

```php
    protected $policies = [
        // 'App\Model' => 'App\Policies\ModelPolicy',
    ];
```

to:

```php
    protected $policies = [
        \App\Models\Incident::class => \App\Policies\IncidentPolicy::class,
    ];
```

- [x] **Step 5: Run tests to see them pass**

```bash
php artisan test --filter=IncidentReportingTest
```

Expected: `7 passed`.

- [x] **Step 6: Commit**

```bash
git add app/Policies app/Providers/AuthServiceProvider.php tests/Feature/Incidents
git commit -m "feat: add IncidentPolicy and authorization tests"
```

---

### Task 8: Controllers and routes

**Files:**
- Create: `app/Http/Controllers/IncidentController.php`, `app/Http/Controllers/AttachmentController.php`
- Modify: `routes/web.php`
- Modify: `tests/Feature/Incidents/IncidentReportingTest.php` (HTTP-level tests)

- [x] **Step 1: Add failing HTTP-level tests**

Append to the test class:

```php
    public function test_a_staff_member_can_save_a_draft_via_http(): void
    {
        $reporter = $this->makeReporter();

        $response = $this->actingAs($reporter)->post('/incidents', [
            'action' => 'draft',
            'location' => 'ER Bay 1',
        ]);

        $incident = Incident::first();
        $response->assertRedirect("/incidents/{$incident->id}/edit");
        $this->assertSame('ER Bay 1', $incident->location);
        $this->assertTrue($incident->status->isDraft());
    }

    public function test_submitting_without_required_fields_fails_validation(): void
    {
        $reporter = $this->makeReporter();

        $response = $this->actingAs($reporter)->post('/incidents', [
            'action' => 'submit',
        ]);

        $response->assertSessionHasErrors(['incident_type_id', 'department_id', 'severity', 'occurred_at', 'location', 'summary', 'legal_attestation']);
        $this->assertDatabaseCount('incidents', 0);
    }

    public function test_a_staff_member_can_submit_a_complete_report_via_http(): void
    {
        $reporter = $this->makeReporter();
        $department = Department::factory()->create();
        $incidentType = IncidentType::factory()->create();

        $response = $this->actingAs($reporter)->post('/incidents', [
            'action' => 'submit',
            'department_id' => $department->id,
            'incident_type_id' => $incidentType->id,
            'severity' => Severity::Level2Moderate->value,
            'occurred_at' => now()->toDateTimeString(),
            'location' => 'ICU',
            'summary' => 'Full incident summary.',
            'legal_attestation' => true,
        ]);

        $incident = Incident::first();
        $response->assertRedirect("/incidents/{$incident->id}");
        $this->assertSame(IncidentStatus::Submitted, $incident->fresh()->status);
        $this->assertNotNull($incident->fresh()->incident_number);
    }

    public function test_a_user_cannot_update_someone_elses_draft_via_http(): void
    {
        $reporter = $this->makeReporter();
        $other = $this->makeReporter();
        $incident = app(IncidentService::class)->createDraft($reporter, ['location' => 'ER']);

        $this->actingAs($other)
            ->patch("/incidents/{$incident->id}", ['action' => 'draft', 'location' => 'hacked'])
            ->assertForbidden();
    }

    public function test_incident_index_scopes_to_the_current_users_reports_by_default(): void
    {
        $reporter = $this->makeReporter();
        $other = $this->makeReporter();
        app(IncidentService::class)->createDraft($reporter, ['location' => 'mine']);
        app(IncidentService::class)->createDraft($other, ['location' => 'not mine']);

        $response = $this->actingAs($reporter)->get('/incidents?scope=drafts');

        $response->assertInertia(fn ($page) => $page
            ->component('Incidents/Index')
            ->has('incidents.data', 1)
        );
    }
```

- [x] **Step 2: Run to confirm they fail**

```bash
php artisan test --filter=IncidentReportingTest
```

Expected: FAIL — routes don't exist yet (404s).

- [x] **Step 3: Write `IncidentController`**

```php
<?php

namespace App\Http\Controllers;

use App\Enums\IncidentStatus;
use App\Http\Requests\Incidents\StoreIncidentRequest;
use App\Http\Requests\Incidents\UpdateIncidentRequest;
use App\Models\ContributingFactor;
use App\Models\Department;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Services\IncidentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class IncidentController extends Controller
{
    public function __construct(private IncidentService $incidents)
    {
    }

    public function index(Request $request): Response
    {
        $scope = $request->string('scope', 'my-reports')->toString();
        $user = $request->user();

        $query = Incident::query()->with(['incidentType', 'department', 'reporter']);

        if ($scope === 'drafts') {
            $query->where('reporter_id', $user->id)->where('status', IncidentStatus::Draft);
        } elseif ($scope === 'all') {
            $this->authorize('viewAny', Incident::class);
            $query->where('status', '!=', IncidentStatus::Draft);
        } else {
            $scope = 'my-reports';
            $query->where('reporter_id', $user->id)->where('status', '!=', IncidentStatus::Draft);
        }

        return Inertia::render('Incidents/Index', [
            'incidents' => $query->latest('id')->paginate(15)->withQueryString(),
            'scope' => $scope,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Incident::class);

        return Inertia::render('Incidents/Wizard', [
            'incident' => null,
            'incidentTypes' => IncidentType::where('is_active', true)->get(['id', 'name']),
            'departments' => Department::where('is_active', true)->get(['id', 'name']),
            'contributingFactors' => ContributingFactor::where('is_active', true)->get(['id', 'label', 'category']),
        ]);
    }

    public function store(StoreIncidentRequest $request): RedirectResponse
    {
        $incident = $this->incidents->createDraft($request->user(), $request->validated());
        $this->storeAttachments($incident, $request);

        if ($request->input('action') === 'submit') {
            $this->incidents->submit($incident);

            return redirect()->route('incidents.show', $incident)->with('success', 'Incident report submitted successfully.');
        }

        return redirect()->route('incidents.edit', $incident)->with('success', 'Draft saved.');
    }

    public function edit(Incident $incident): Response
    {
        $this->authorize('update', $incident);

        $incident->load(['individuals', 'witnesses', 'actions', 'narrativeEvents', 'contributingFactors', 'attachments']);

        return Inertia::render('Incidents/Wizard', [
            'incident' => $incident,
            'incidentTypes' => IncidentType::where('is_active', true)->get(['id', 'name']),
            'departments' => Department::where('is_active', true)->get(['id', 'name']),
            'contributingFactors' => ContributingFactor::where('is_active', true)->get(['id', 'label', 'category']),
        ]);
    }

    public function update(UpdateIncidentRequest $request, Incident $incident): RedirectResponse
    {
        $this->incidents->updateDraft($incident, $request->validated());
        $this->storeAttachments($incident, $request);

        if ($request->input('action') === 'submit') {
            $this->incidents->submit($incident);

            return redirect()->route('incidents.show', $incident)->with('success', 'Incident report submitted successfully.');
        }

        return redirect()->route('incidents.edit', $incident)->with('success', 'Draft saved.');
    }

    public function show(Request $request, Incident $incident): Response
    {
        $this->authorize('view', $incident);

        $incident->load([
            'reporter', 'department', 'incidentType', 'assignedInvestigator',
            'individuals', 'witnesses', 'actions', 'narrativeEvents', 'contributingFactors', 'attachments',
        ]);

        return Inertia::render('Incidents/Show', [
            'incident' => $incident,
            'tab' => $request->string('tab', 'overview')->toString(),
        ]);
    }

    private function storeAttachments(Incident $incident, Request $request): void
    {
        foreach ($request->file('attachments', []) as $file) {
            $path = $file->store("incidents/{$incident->id}", 'local');

            $incident->attachments()->create([
                'uploaded_by' => $request->user()->id,
                'disk_path' => $path,
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'category' => 'evidence',
            ]);
        }
    }
}
```

- [x] **Step 4: Write `AttachmentController`**

```php
<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use Illuminate\Support\Facades\Storage;

class AttachmentController extends Controller
{
    public function show(Attachment $attachment)
    {
        $this->authorize('view', $attachment->attachable);

        return Storage::disk('local')->download($attachment->disk_path, $attachment->original_filename);
    }
}
```

- [x] **Step 5: Add routes**

In `routes/web.php`, add these imports at the top alongside the existing ones:

```php
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\IncidentController;
```

Then inside the existing `Route::middleware('auth')->group(function () { ... })` block (alongside the current `dashboard` and `logout` routes), add:

```php
    Route::get('/incidents', [IncidentController::class, 'index'])->name('incidents.index');
    Route::get('/incidents/create', [IncidentController::class, 'create'])->name('incidents.create');
    Route::post('/incidents', [IncidentController::class, 'store'])->name('incidents.store');
    Route::get('/incidents/{incident}', [IncidentController::class, 'show'])->name('incidents.show');
    Route::get('/incidents/{incident}/edit', [IncidentController::class, 'edit'])->name('incidents.edit');
    Route::patch('/incidents/{incident}', [IncidentController::class, 'update'])->name('incidents.update');
    Route::get('/attachments/{attachment}', [AttachmentController::class, 'show'])->name('attachments.show');
```

- [x] **Step 6: Run the full test file**

```bash
php artisan test --filter=IncidentReportingTest
```

Expected: all tests pass (12 total: 3 service + 4 policy + 5 HTTP).

- [x] **Step 7: Run the whole suite to check nothing else broke**

```bash
php artisan test
```

Expected: all green (should be 12 + the 7 from `AuthenticationTest` + 1 `ExampleTest` = 20).

- [x] **Step 8: Commit**

```bash
git add app/Http/Controllers routes/web.php tests/Feature/Incidents
git commit -m "feat: add incident CRUD routes and controllers"
```

---

### Task 9: Vue — status/severity badges and composable

**Files:**
- Create: `resources/js/Composables/useIncidentStatus.js`, `resources/js/Components/StatusBadge.vue`, `resources/js/Components/SeverityBadge.vue`

- [x] **Step 1: Write the composable**

```js
const STATUS_LABELS = {
    draft: 'Draft',
    submitted: 'Submitted',
    for_review: 'For Review',
    reviewed: 'Reviewed',
    assigned: 'Assigned',
    under_investigation: 'Under Investigation',
    corrective_action: 'Corrective Action',
    for_verification: 'For Verification',
    verified: 'Verified',
    for_approval: 'For Approval',
    closed: 'Closed',
};

const STATUS_CLASSES = {
    draft: 'bg-surface-container text-on-surface-variant',
    submitted: 'bg-blue-100 text-blue-900',
    for_review: 'bg-amber-100 text-amber-900',
    reviewed: 'bg-blue-100 text-blue-900',
    assigned: 'bg-blue-100 text-blue-900',
    under_investigation: 'bg-primary-container text-on-primary',
    corrective_action: 'bg-amber-100 text-amber-900',
    for_verification: 'bg-secondary-container text-on-secondary-container',
    verified: 'bg-secondary-container text-on-secondary-container',
    for_approval: 'bg-amber-100 text-amber-900',
    closed: 'bg-emerald-100 text-emerald-900',
};

const SEVERITY_LABELS = {
    level_1_low: 'Low Risk',
    level_2_moderate: 'Moderate Risk',
    level_3_high: 'High Severity',
    level_4_critical_sentinel: 'Critical / Sentinel',
};

const SEVERITY_CLASSES = {
    level_1_low: 'bg-surface-container text-on-surface-variant',
    level_2_moderate: 'bg-amber-50 text-amber-900',
    level_3_high: 'bg-amber-200 text-amber-950',
    level_4_critical_sentinel: 'bg-error-container text-on-error-container',
};

export function statusLabel(status) {
    return STATUS_LABELS[status] ?? status;
}

export function statusBadgeClasses(status) {
    return STATUS_CLASSES[status] ?? 'bg-surface-container text-on-surface-variant';
}

export function severityLabel(severity) {
    return SEVERITY_LABELS[severity] ?? severity;
}

export function severityBadgeClasses(severity) {
    return SEVERITY_CLASSES[severity] ?? 'bg-surface-container text-on-surface-variant';
}
```

- [x] **Step 2: Write `StatusBadge.vue`**

```vue
<script setup>
import { computed } from 'vue';
import { statusLabel, statusBadgeClasses } from '@/Composables/useIncidentStatus';

const props = defineProps({
    status: { type: String, required: true },
});

const label = computed(() => statusLabel(props.status));
const classes = computed(() => statusBadgeClasses(props.status));
</script>

<template>
    <span :class="classes" class="px-2.5 py-0.5 rounded-full font-label-sm text-body-sm font-semibold whitespace-nowrap">
        {{ label }}
    </span>
</template>
```

- [x] **Step 3: Write `SeverityBadge.vue`**

```vue
<script setup>
import { computed } from 'vue';
import { severityLabel, severityBadgeClasses } from '@/Composables/useIncidentStatus';

const props = defineProps({
    severity: { type: String, required: true },
});

const label = computed(() => severityLabel(props.severity));
const classes = computed(() => severityBadgeClasses(props.severity));
</script>

<template>
    <span :class="classes" class="px-2.5 py-0.5 rounded-full font-label-sm text-body-sm font-semibold whitespace-nowrap">
        {{ label }}
    </span>
</template>
```

- [x] **Step 4: Commit**

```bash
git add resources/js/Composables resources/js/Components/StatusBadge.vue resources/js/Components/SeverityBadge.vue
git commit -m "feat: add status/severity badge components"
```

---

### Task 10: Vue — ConfirmationDialog

**Files:**
- Create: `resources/js/Components/ConfirmationDialog.vue`

- [x] **Step 1: Write it**

```vue
<script setup>
defineProps({
    show: { type: Boolean, default: false },
    title: { type: String, required: true },
    message: { type: String, required: true },
    confirmLabel: { type: String, default: 'Confirm' },
    processing: { type: Boolean, default: false },
});

const emit = defineEmits(['confirm', 'cancel']);
</script>

<template>
    <div v-if="show" class="fixed inset-0 z-[100] flex items-center justify-center px-space-md">
        <div class="absolute inset-0 bg-inverse-surface/60 backdrop-blur-sm" @click="emit('cancel')" />
        <div class="relative w-full max-w-md rounded-xl bg-surface-container-lowest shadow-xl p-space-lg">
            <h3 class="font-title-lg text-title-lg text-on-surface font-semibold">{{ title }}</h3>
            <p class="mt-2 font-body-sm text-body-sm text-on-surface-variant">{{ message }}</p>
            <div class="mt-space-lg flex justify-end gap-2">
                <button
                    type="button"
                    class="px-4 py-2 rounded-lg bg-surface-container text-on-surface font-label-md text-label-md"
                    @click="emit('cancel')"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    :disabled="processing"
                    class="px-4 py-2 rounded-lg bg-primary text-on-primary font-label-md text-label-md font-semibold disabled:opacity-60"
                    @click="emit('confirm')"
                >
                    {{ confirmLabel }}
                </button>
            </div>
        </div>
    </div>
</template>
```

- [x] **Step 2: Commit**

```bash
git add resources/js/Components/ConfirmationDialog.vue
git commit -m "feat: add reusable ConfirmationDialog component"
```

---

### Task 11: Vue — the 8 wizard step components

**Files:** the 8 files under `resources/js/Components/Incidents/`.

Every step component receives one prop, `form` (the Inertia form object from `useForm`, passed down by `Wizard.vue` in Task 12), and mutates its nested fields directly — this works because `form` is the same reactive object throughout, not a copy.

- [x] **Step 1: `Step1ReporterInfo.vue`**

```vue
<script setup>
import { usePage } from '@inertiajs/vue3';

defineProps({
    form: { type: Object, required: true },
});

const user = usePage().props.auth.user;
</script>

<template>
    <div class="flex flex-col gap-space-md">
        <h2 class="font-title-lg text-title-lg text-primary font-bold">Section 1: Reporter Information</h2>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-space-md">
            <div class="flex flex-col gap-1">
                <span class="font-label-md text-label-md text-on-surface-variant">Reported By</span>
                <div class="p-2.5 rounded-lg bg-surface-container-low text-on-surface font-title-sm text-title-sm font-semibold">
                    {{ user.name }}
                </div>
            </div>
            <div class="flex flex-col gap-1">
                <span class="font-label-md text-label-md text-on-surface-variant">Designation</span>
                <div class="p-2.5 rounded-lg bg-surface-container-low text-on-surface font-body-md text-body-md">
                    {{ user.designation ?? 'Not set' }}
                </div>
            </div>
        </div>

        <label class="p-space-md rounded-lg bg-surface-container-low flex items-start gap-3 cursor-pointer">
            <input v-model="form.legal_attestation" type="checkbox" class="mt-1 w-4 h-4 rounded accent-primary" />
            <span class="font-body-sm text-body-sm text-on-surface">
                <span class="font-semibold text-primary">Electronic Acknowledgment:</span>
                I certify that the observations and statements in this report are true, factual, and based on
                firsthand verification. This attestation is required before final submission.
            </span>
        </label>
        <span v-if="form.errors.legal_attestation" class="font-body-sm text-body-sm text-error">
            {{ form.errors.legal_attestation }}
        </span>
    </div>
</template>
```

- [x] **Step 2: `Step2IncidentDetails.vue`**

```vue
<script setup>
defineProps({
    form: { type: Object, required: true },
    incidentTypes: { type: Array, required: true },
    departments: { type: Array, required: true },
});

const severities = [
    { value: 'level_1_low', label: 'Low Risk', numeral: 'Level I' },
    { value: 'level_2_moderate', label: 'Moderate Risk', numeral: 'Level II' },
    { value: 'level_3_high', label: 'High Severity', numeral: 'Level III' },
    { value: 'level_4_critical_sentinel', label: 'Critical / Sentinel', numeral: 'Level IV' },
];
</script>

<template>
    <div class="flex flex-col gap-space-lg">
        <h2 class="font-title-lg text-title-lg text-primary font-bold">Section 2: Incident Details & Risk Level</h2>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-space-md">
            <div class="flex flex-col gap-1.5">
                <label class="font-label-md text-label-md text-on-surface font-semibold">Incident Type *</label>
                <select v-model="form.incident_type_id" class="w-full p-3 rounded-lg bg-surface-container-low">
                    <option :value="null" disabled>Select a type</option>
                    <option v-for="type in incidentTypes" :key="type.id" :value="type.id">{{ type.name }}</option>
                </select>
                <span v-if="form.errors.incident_type_id" class="font-body-sm text-body-sm text-error">{{ form.errors.incident_type_id }}</span>
            </div>

            <div class="flex flex-col gap-1.5">
                <label class="font-label-md text-label-md text-on-surface font-semibold">Department / Clinical Unit *</label>
                <select v-model="form.department_id" class="w-full p-3 rounded-lg bg-surface-container-low">
                    <option :value="null" disabled>Select a department</option>
                    <option v-for="dept in departments" :key="dept.id" :value="dept.id">{{ dept.name }}</option>
                </select>
                <span v-if="form.errors.department_id" class="font-body-sm text-body-sm text-error">{{ form.errors.department_id }}</span>
            </div>

            <div class="flex flex-col gap-1.5">
                <label class="font-label-md text-label-md text-on-surface font-semibold">Date & Time of Incident *</label>
                <input v-model="form.occurred_at" type="datetime-local" class="w-full p-3 rounded-lg bg-surface-container-low" />
                <span v-if="form.errors.occurred_at" class="font-body-sm text-body-sm text-error">{{ form.errors.occurred_at }}</span>
            </div>

            <div class="flex flex-col gap-1.5">
                <label class="font-label-md text-label-md text-on-surface font-semibold">Precise Location *</label>
                <input v-model="form.location" type="text" placeholder="Building, floor, room / bed number" class="w-full p-3 rounded-lg bg-surface-container-low" />
                <span v-if="form.errors.location" class="font-body-sm text-body-sm text-error">{{ form.errors.location }}</span>
            </div>
        </div>

        <div class="flex flex-col gap-space-sm">
            <label class="font-label-md text-label-md text-on-surface font-semibold">Severity & Harm Level *</label>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-space-sm">
                <label
                    v-for="option in severities"
                    :key="option.value"
                    class="flex flex-col p-3 rounded-lg cursor-pointer transition-colors"
                    :class="form.severity === option.value ? 'bg-amber-50 ring-2 ring-amber-500' : 'bg-surface-container-low hover:bg-surface-container'"
                >
                    <input v-model="form.severity" type="radio" :value="option.value" class="hidden" />
                    <span class="font-label-sm text-body-sm font-bold text-outline">{{ option.numeral }}</span>
                    <span class="font-title-sm text-title-sm text-on-surface font-bold">{{ option.label }}</span>
                </label>
            </div>
            <span v-if="form.errors.severity" class="font-body-sm text-body-sm text-error">{{ form.errors.severity }}</span>
        </div>
    </div>
</template>
```

- [x] **Step 3: `Step3PeopleInvolved.vue`**

```vue
<script setup>
defineProps({
    form: { type: Object, required: true },
});

const personTypes = ['patient', 'staff', 'visitor', 'other'];

function addIndividual(form) {
    form.individuals.push({ person_type: 'patient', name: '', identifier: '', role_description: '', details: '' });
}

function removeIndividual(form, index) {
    form.individuals.splice(index, 1);
}
</script>

<template>
    <div class="flex flex-col gap-space-md">
        <div class="flex items-center justify-between">
            <h2 class="font-title-lg text-title-lg text-primary font-bold">Section 3: People Involved</h2>
            <button type="button" class="font-label-sm text-label-sm font-bold text-primary" @click="addIndividual(form)">
                + Add Person
            </button>
        </div>

        <div v-if="form.individuals.length === 0" class="p-space-md rounded-lg bg-surface-container-low text-center font-body-sm text-body-sm text-outline">
            No individuals added yet.
        </div>

        <div v-for="(person, index) in form.individuals" :key="index" class="p-space-md rounded-lg bg-surface-container-low flex flex-col gap-space-sm">
            <div class="flex justify-between items-center">
                <span class="font-label-sm text-label-sm uppercase text-outline">Person {{ index + 1 }}</span>
                <button type="button" class="text-error font-label-sm text-body-sm" @click="removeIndividual(form, index)">Remove</button>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-space-sm">
                <select v-model="person.person_type" class="p-2.5 rounded-lg bg-surface-container-lowest">
                    <option v-for="type in personTypes" :key="type" :value="type">{{ type }}</option>
                </select>
                <input v-model="person.name" type="text" placeholder="Full name" class="p-2.5 rounded-lg bg-surface-container-lowest" />
                <input v-model="person.identifier" type="text" placeholder="HRN / Employee No." class="p-2.5 rounded-lg bg-surface-container-lowest" />
                <input v-model="person.role_description" type="text" placeholder="Role / designation" class="p-2.5 rounded-lg bg-surface-container-lowest" />
                <textarea v-model="person.details" placeholder="Additional details" class="p-2.5 rounded-lg bg-surface-container-lowest md:col-span-2" rows="2" />
            </div>
        </div>
    </div>
</template>
```

- [x] **Step 4: `Step4WitnessesPolice.vue`**

```vue
<script setup>
defineProps({
    form: { type: Object, required: true },
});

function addWitness(form) {
    form.witnesses.push({ name: '', designation: '', address: '', contact_number: '', statement: '' });
}

function removeWitness(form, index) {
    form.witnesses.splice(index, 1);
}
</script>

<template>
    <div class="flex flex-col gap-space-lg">
        <div class="flex flex-col gap-space-md">
            <div class="flex items-center justify-between">
                <h2 class="font-title-lg text-title-lg text-primary font-bold">Section 4: Witnesses</h2>
                <button type="button" class="font-label-sm text-label-sm font-bold text-primary" @click="addWitness(form)">
                    + Add Witness
                </button>
            </div>

            <div v-if="form.witnesses.length === 0" class="p-space-md rounded-lg bg-surface-container-low text-center font-body-sm text-body-sm text-outline">
                No witnesses recorded.
            </div>

            <div v-for="(witness, index) in form.witnesses" :key="index" class="p-space-md rounded-lg bg-surface-container-low flex flex-col gap-space-sm">
                <div class="flex justify-between items-center">
                    <span class="font-label-sm text-label-sm uppercase text-outline">Witness {{ index + 1 }}</span>
                    <button type="button" class="text-error font-label-sm text-body-sm" @click="removeWitness(form, index)">Remove</button>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-space-sm">
                    <input v-model="witness.name" type="text" placeholder="Full name" class="p-2.5 rounded-lg bg-surface-container-lowest" />
                    <input v-model="witness.designation" type="text" placeholder="Designation" class="p-2.5 rounded-lg bg-surface-container-lowest" />
                    <input v-model="witness.address" type="text" placeholder="Address" class="p-2.5 rounded-lg bg-surface-container-lowest" />
                    <input v-model="witness.contact_number" type="text" placeholder="Contact number" class="p-2.5 rounded-lg bg-surface-container-lowest" />
                    <textarea v-model="witness.statement" placeholder="Statement" class="p-2.5 rounded-lg bg-surface-container-lowest md:col-span-2" rows="2" />
                </div>
            </div>
        </div>

        <div class="p-space-md rounded-xl bg-surface-container-low flex flex-col gap-space-sm">
            <label class="flex items-center gap-2">
                <input v-model="form.police_notified" type="checkbox" class="w-4 h-4 rounded accent-primary" />
                <span class="font-label-md text-label-md text-on-surface font-semibold">
                    Philippine National Police (PNP) or external agency notified?
                </span>
            </label>
            <div v-if="form.police_notified" class="grid grid-cols-1 md:grid-cols-2 gap-space-sm pt-2">
                <input v-model="form.police_station" type="text" placeholder="Police station / precinct" class="p-2.5 rounded-lg bg-surface-container-lowest" />
                <input v-model="form.police_officer_in_charge" type="text" placeholder="Officer-in-charge" class="p-2.5 rounded-lg bg-surface-container-lowest" />
                <input v-model="form.police_blotter_no" type="text" placeholder="Blotter reference no." class="p-2.5 rounded-lg bg-surface-container-lowest" />
                <input v-model="form.police_notified_at" type="datetime-local" class="p-2.5 rounded-lg bg-surface-container-lowest" />
            </div>
        </div>
    </div>
</template>
```

- [x] **Step 5: `Step5Description.vue`**

```vue
<script setup>
defineProps({
    form: { type: Object, required: true },
    contributingFactors: { type: Array, required: true },
});

function addEvent(form) {
    form.narrative_events.push({ occurred_at: '', description: '' });
}

function removeEvent(form, index) {
    form.narrative_events.splice(index, 1);
}

function onFilesSelected(form, event) {
    form.attachments.push(...Array.from(event.target.files));
    event.target.value = '';
}

function removeAttachment(form, index) {
    form.attachments.splice(index, 1);
}
</script>

<template>
    <div class="flex flex-col gap-space-lg">
        <h2 class="font-title-lg text-title-lg text-primary font-bold">Section 5: Description & Evidence</h2>

        <div class="flex flex-col gap-1.5">
            <label class="font-label-md text-label-md text-on-surface font-semibold">Executive Narrative Summary *</label>
            <textarea v-model="form.summary" rows="4" class="w-full p-3 rounded-lg bg-surface-container-low" />
            <span v-if="form.errors.summary" class="font-body-sm text-body-sm text-error">{{ form.errors.summary }}</span>
        </div>

        <div class="flex flex-col gap-space-sm">
            <div class="flex items-center justify-between">
                <label class="font-label-md text-label-md text-on-surface font-semibold">Chronological Sequence of Events</label>
                <button type="button" class="font-label-sm text-label-sm font-bold text-primary" @click="addEvent(form)">+ Add Event</button>
            </div>
            <div v-for="(event, index) in form.narrative_events" :key="index" class="flex items-start gap-2 bg-surface-container-low p-2.5 rounded-lg">
                <input v-model="event.occurred_at" type="text" placeholder="Time" class="w-32 p-2 rounded bg-surface-container-lowest" />
                <input v-model="event.description" type="text" placeholder="What happened" class="flex-1 p-2 rounded bg-surface-container-lowest" />
                <button type="button" class="text-error" @click="removeEvent(form, index)">✕</button>
            </div>
        </div>

        <div class="flex flex-col gap-2">
            <label class="font-label-md text-label-md text-on-surface font-semibold">Contributing Factors</label>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-2">
                <label v-for="factor in contributingFactors" :key="factor.id" class="flex items-center gap-2 p-2.5 rounded-lg bg-surface-container-low cursor-pointer">
                    <input v-model="form.contributing_factor_ids" type="checkbox" :value="factor.id" class="accent-primary rounded" />
                    <span class="font-body-sm text-body-sm text-on-surface">{{ factor.label }}</span>
                </label>
            </div>
        </div>

        <div class="flex flex-col gap-space-sm">
            <label class="font-label-md text-label-md text-on-surface font-semibold">Evidence & Attachments</label>
            <label class="p-space-lg rounded-xl bg-surface-container-low border-2 border-dashed border-outline-variant flex flex-col items-center justify-center text-center gap-2 cursor-pointer">
                <FontAwesomeIcon icon="cloud-arrow-up" class="text-title-lg text-primary" />
                <span class="font-title-sm text-title-sm text-primary font-bold">Upload photos, documents, or reports</span>
                <span class="font-body-sm text-body-sm text-outline">PDF, PNG, JPG, DOC — max 25MB per file</span>
                <input type="file" multiple class="hidden" @change="onFilesSelected(form, $event)" />
            </label>
            <div v-for="(file, index) in form.attachments" :key="index" class="flex items-center justify-between p-2.5 rounded-lg bg-surface-container-low">
                <span class="font-body-sm text-body-sm text-on-surface truncate">{{ file.name }}</span>
                <button type="button" class="text-error" @click="removeAttachment(form, index)">
                    <FontAwesomeIcon icon="trash" />
                </button>
            </div>
        </div>
    </div>
</template>
```

- [x] **Step 6: `Step6ActionsTaken.vue`**

```vue
<script setup>
defineProps({
    form: { type: Object, required: true },
});

function addAction(form) {
    form.actions_taken.push({ description: '', responsible_name: '', performed_at: '', status: 'completed' });
}

function removeAction(form, index) {
    form.actions_taken.splice(index, 1);
}
</script>

<template>
    <div class="flex flex-col gap-space-md">
        <div class="flex items-center justify-between">
            <h2 class="font-title-lg text-title-lg text-primary font-bold">Section 6: Immediate Actions Taken</h2>
            <button type="button" class="font-label-sm text-label-sm font-bold text-primary" @click="addAction(form)">+ Add Action</button>
        </div>

        <div v-if="form.actions_taken.length === 0" class="p-space-md rounded-lg bg-surface-container-low text-center font-body-sm text-body-sm text-outline">
            No immediate actions recorded yet.
        </div>

        <div v-for="(action, index) in form.actions_taken" :key="index" class="p-space-md rounded-lg bg-surface-container-low flex flex-col gap-space-sm">
            <div class="flex justify-between items-center">
                <span class="font-label-sm text-label-sm uppercase text-outline">Action {{ index + 1 }}</span>
                <button type="button" class="text-error font-label-sm text-body-sm" @click="removeAction(form, index)">Remove</button>
            </div>
            <textarea v-model="action.description" placeholder="Intervention taken" rows="2" class="p-2.5 rounded-lg bg-surface-container-lowest" />
            <div class="grid grid-cols-1 md:grid-cols-3 gap-space-sm">
                <input v-model="action.responsible_name" type="text" placeholder="Responsible officer" class="p-2.5 rounded-lg bg-surface-container-lowest" />
                <input v-model="action.performed_at" type="datetime-local" class="p-2.5 rounded-lg bg-surface-container-lowest" />
                <select v-model="action.status" class="p-2.5 rounded-lg bg-surface-container-lowest">
                    <option value="pending">Pending</option>
                    <option value="completed">Completed</option>
                </select>
            </div>
        </div>
    </div>
</template>
```

- [x] **Step 7: `Step7Recommendations.vue`**

```vue
<script setup>
defineProps({
    form: { type: Object, required: true },
});
</script>

<template>
    <div class="flex flex-col gap-space-md">
        <h2 class="font-title-lg text-title-lg text-primary font-bold">Section 7: Recommendations & Preventive Measures</h2>
        <div class="flex flex-col gap-1.5">
            <label class="font-label-md text-label-md text-on-surface font-semibold">
                Recommended corrective / preventive measures
            </label>
            <textarea v-model="form.recommendations" rows="6" class="w-full p-3 rounded-lg bg-surface-container-low" placeholder="What should change to prevent this from happening again?" />
        </div>
    </div>
</template>
```

- [x] **Step 8: `Step8Review.vue`**

```vue
<script setup>
import SeverityBadge from '@/Components/SeverityBadge.vue';

defineProps({
    form: { type: Object, required: true },
    incidentTypes: { type: Array, required: true },
    departments: { type: Array, required: true },
});

function typeName(incidentTypes, id) {
    return incidentTypes.find((t) => t.id === id)?.name ?? '—';
}

function departmentName(departments, id) {
    return departments.find((d) => d.id === id)?.name ?? '—';
}
</script>

<template>
    <div class="flex flex-col gap-space-lg">
        <h2 class="font-title-lg text-title-lg text-primary font-bold">Section 8: Review & Submit</h2>

        <div class="p-space-md rounded-lg bg-surface-container-low flex flex-col gap-2">
            <div class="flex items-center gap-2">
                <span class="font-label-sm text-body-sm text-outline">Type:</span>
                <span class="font-body-md text-body-md text-on-surface">{{ typeName(incidentTypes, form.incident_type_id) }}</span>
            </div>
            <div class="flex items-center gap-2">
                <span class="font-label-sm text-body-sm text-outline">Department:</span>
                <span class="font-body-md text-body-md text-on-surface">{{ departmentName(departments, form.department_id) }}</span>
            </div>
            <div class="flex items-center gap-2">
                <span class="font-label-sm text-body-sm text-outline">Severity:</span>
                <SeverityBadge v-if="form.severity" :severity="form.severity" />
            </div>
            <div class="flex items-center gap-2">
                <span class="font-label-sm text-body-sm text-outline">Location:</span>
                <span class="font-body-md text-body-md text-on-surface">{{ form.location || '—' }}</span>
            </div>
            <div class="flex flex-col gap-1">
                <span class="font-label-sm text-body-sm text-outline">Summary:</span>
                <span class="font-body-md text-body-md text-on-surface">{{ form.summary || '—' }}</span>
            </div>
            <div class="flex items-center gap-2">
                <span class="font-label-sm text-body-sm text-outline">People involved:</span>
                <span class="font-body-md text-body-md text-on-surface">{{ form.individuals.length }}</span>
            </div>
            <div class="flex items-center gap-2">
                <span class="font-label-sm text-body-sm text-outline">Witnesses:</span>
                <span class="font-body-md text-body-md text-on-surface">{{ form.witnesses.length }}</span>
            </div>
            <div class="flex items-center gap-2">
                <span class="font-label-sm text-body-sm text-outline">Attachments:</span>
                <span class="font-body-md text-body-md text-on-surface">{{ form.attachments.length }}</span>
            </div>
        </div>

        <p class="font-body-sm text-body-sm text-outline">
            Review every section before submitting. Once submitted, this report can no longer be edited and enters
            the review workflow.
        </p>
    </div>
</template>
```

- [x] **Step 9: Commit**

```bash
git add resources/js/Components/Incidents
git commit -m "feat: add 8 incident report wizard step components"
```

---

### Task 12: Vue — Wizard.vue page

**Files:**
- Create: `resources/js/Pages/Incidents/Wizard.vue`

- [x] **Step 1: Write it**

```vue
<script setup>
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ConfirmationDialog from '@/Components/ConfirmationDialog.vue';
import Step1ReporterInfo from '@/Components/Incidents/Step1ReporterInfo.vue';
import Step2IncidentDetails from '@/Components/Incidents/Step2IncidentDetails.vue';
import Step3PeopleInvolved from '@/Components/Incidents/Step3PeopleInvolved.vue';
import Step4WitnessesPolice from '@/Components/Incidents/Step4WitnessesPolice.vue';
import Step5Description from '@/Components/Incidents/Step5Description.vue';
import Step6ActionsTaken from '@/Components/Incidents/Step6ActionsTaken.vue';
import Step7Recommendations from '@/Components/Incidents/Step7Recommendations.vue';
import Step8Review from '@/Components/Incidents/Step8Review.vue';

const props = defineProps({
    incident: { type: Object, default: null },
    incidentTypes: { type: Array, required: true },
    departments: { type: Array, required: true },
    contributingFactors: { type: Array, required: true },
});

const steps = [
    { title: 'Reporter Info', component: Step1ReporterInfo },
    { title: 'Incident Details', component: Step2IncidentDetails },
    { title: 'People Involved', component: Step3PeopleInvolved },
    { title: 'Witnesses & Police', component: Step4WitnessesPolice },
    { title: 'Description', component: Step5Description },
    { title: 'Actions Taken', component: Step6ActionsTaken },
    { title: 'Recommendations', component: Step7Recommendations },
    { title: 'Review & Submit', component: Step8Review },
];

const currentStep = ref(1);
const showConfirm = ref(false);

const form = useForm({
    action: 'draft',
    incident_type_id: props.incident?.incident_type_id ?? null,
    department_id: props.incident?.department_id ?? null,
    severity: props.incident?.severity ?? null,
    occurred_at: props.incident?.occurred_at?.slice(0, 16) ?? '',
    location: props.incident?.location ?? '',
    summary: props.incident?.summary ?? '',
    recommendations: props.incident?.recommendations ?? '',
    legal_attestation: !!props.incident?.legal_attestation_at,
    police_notified: props.incident?.police_notified ?? false,
    police_station: props.incident?.police_station ?? '',
    police_officer_in_charge: props.incident?.police_officer_in_charge ?? '',
    police_blotter_no: props.incident?.police_blotter_no ?? '',
    police_notified_at: props.incident?.police_notified_at?.slice(0, 16) ?? '',
    individuals: props.incident?.individuals ?? [],
    witnesses: props.incident?.witnesses ?? [],
    narrative_events: props.incident?.narrative_events ?? [],
    actions_taken: props.incident?.actions ?? [],
    contributing_factor_ids: props.incident?.contributing_factors?.map((f) => f.id) ?? [],
    attachments: [],
});

const isLastStep = computed(() => currentStep.value === steps.length);

function next() {
    if (currentStep.value < steps.length) currentStep.value += 1;
}

function back() {
    if (currentStep.value > 1) currentStep.value -= 1;
}

function targetUrl() {
    return props.incident ? `/incidents/${props.incident.id}` : '/incidents';
}

function submitAs(action) {
    form.action = action;
    const options = { preserveScroll: true, onFinish: () => (showConfirm.value = false) };

    if (props.incident) {
        form.transform((data) => ({ ...data, _method: 'patch' })).post(targetUrl(), options);
    } else {
        form.post(targetUrl(), options);
    }
}

function saveDraft() {
    submitAs('draft');
}

function confirmSubmit() {
    showConfirm.value = true;
}
</script>

<template>
    <Head title="Report an Incident" />

    <AuthenticatedLayout>
        <div class="flex items-center gap-2 overflow-x-auto pb-1">
            <button
                v-for="(step, index) in steps"
                :key="step.title"
                type="button"
                class="px-3 py-2 rounded-lg font-label-sm text-body-sm whitespace-nowrap"
                :class="currentStep === index + 1 ? 'bg-primary text-on-primary font-semibold' : 'bg-surface-container text-on-surface-variant'"
                @click="currentStep = index + 1"
            >
                {{ index + 1 }}. {{ step.title }}
            </button>
        </div>

        <div class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm">
            <component
                :is="steps[currentStep - 1].component"
                :form="form"
                :incident-types="incidentTypes"
                :departments="departments"
                :contributing-factors="contributingFactors"
            />
        </div>

        <div class="flex items-center justify-between">
            <button
                type="button"
                :disabled="currentStep === 1"
                class="px-4 py-2 rounded-lg bg-surface-container text-on-surface font-label-md text-label-md disabled:opacity-40"
                @click="back"
            >
                Back
            </button>

            <div class="flex items-center gap-2">
                <button
                    type="button"
                    :disabled="form.processing"
                    class="px-4 py-2 rounded-lg bg-surface-container-high text-primary font-label-md text-label-md disabled:opacity-60"
                    @click="saveDraft"
                >
                    Save as Draft
                </button>
                <button
                    v-if="!isLastStep"
                    type="button"
                    class="px-4 py-2 rounded-lg bg-primary text-on-primary font-label-md text-label-md font-semibold"
                    @click="next"
                >
                    Next
                </button>
                <button
                    v-else
                    type="button"
                    :disabled="form.processing"
                    class="px-4 py-2 rounded-lg bg-primary text-on-primary font-label-md text-label-md font-semibold disabled:opacity-60"
                    @click="confirmSubmit"
                >
                    Submit Report
                </button>
            </div>
        </div>

        <ConfirmationDialog
            :show="showConfirm"
            title="Submit incident report?"
            message="Once submitted, this report can no longer be edited and enters the review workflow. Make sure the legal attestation in Section 1 is checked."
            confirm-label="Submit Report"
            :processing="form.processing"
            @cancel="showConfirm = false"
            @confirm="submitAs('submit')"
        />
    </AuthenticatedLayout>
</template>
```

- [x] **Step 2: Commit**

```bash
git add resources/js/Pages/Incidents/Wizard.vue
git commit -m "feat: add incident report wizard page"
```

---

### Task 13: Vue — Incidents/Index.vue

**Files:**
- Create: `resources/js/Pages/Incidents/Index.vue`

- [x] **Step 1: Write it**

```vue
<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import SeverityBadge from '@/Components/SeverityBadge.vue';

const props = defineProps({
    incidents: { type: Object, required: true },
    scope: { type: String, required: true },
});

const scopes = [
    { value: 'my-reports', label: 'My Reports' },
    { value: 'drafts', label: 'Draft Reports' },
    { value: 'all', label: 'All Incidents' },
];

function switchScope(value) {
    router.get('/incidents', { scope: value }, { preserveState: true });
}
</script>

<template>
    <Head title="Incidents" />

    <AuthenticatedLayout>
        <div class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-space-md">
            <div class="flex items-center justify-between flex-wrap gap-2">
                <h1 class="font-headline-sm text-headline-sm text-on-surface">Incidents</h1>
                <Link href="/incidents/create" class="px-4 py-2 rounded-lg bg-secondary text-on-secondary font-label-md text-label-md font-semibold">
                    + Report an Incident
                </Link>
            </div>

            <div class="inline-flex p-1 rounded-lg bg-surface-container-low w-fit">
                <button
                    v-for="option in scopes"
                    :key="option.value"
                    type="button"
                    class="px-3 py-1.5 rounded-md font-label-sm text-body-sm"
                    :class="scope === option.value ? 'bg-surface-container-lowest text-primary font-semibold shadow-sm' : 'text-outline'"
                    @click="switchScope(option.value)"
                >
                    {{ option.label }}
                </button>
            </div>

            <div v-if="incidents.data.length === 0" class="p-space-lg text-center font-body-sm text-body-sm text-outline">
                No incidents found in this view.
            </div>

            <div v-else class="overflow-x-auto rounded-lg bg-surface-container-low">
                <table class="w-full text-left">
                    <thead>
                        <tr class="bg-surface-container text-on-surface-variant font-label-sm text-label-sm uppercase tracking-wider">
                            <th class="p-3">Incident No.</th>
                            <th class="p-3">Type</th>
                            <th class="p-3">Department</th>
                            <th class="p-3">Severity</th>
                            <th class="p-3">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-surface-container">
                        <tr v-for="incident in incidents.data" :key="incident.id" class="bg-surface-container-lowest hover:bg-surface-container-low">
                            <td class="p-3">
                                <Link :href="incident.status === 'draft' ? `/incidents/${incident.id}/edit` : `/incidents/${incident.id}`" class="font-code-tabular text-body-sm text-primary font-semibold">
                                    {{ incident.incident_number ?? `Draft #${incident.id}` }}
                                </Link>
                            </td>
                            <td class="p-3 font-body-sm text-body-sm text-on-surface">{{ incident.incident_type?.name ?? '—' }}</td>
                            <td class="p-3 font-body-sm text-body-sm text-on-surface">{{ incident.department?.name ?? '—' }}</td>
                            <td class="p-3">
                                <SeverityBadge v-if="incident.severity" :severity="incident.severity" />
                                <span v-else class="font-body-sm text-body-sm text-outline">—</span>
                            </td>
                            <td class="p-3"><StatusBadge :status="incident.status" /></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
```

- [x] **Step 2: Commit**

```bash
git add resources/js/Pages/Incidents/Index.vue
git commit -m "feat: add scoped incident list page"
```

---

### Task 14: Vue — Incidents/Show.vue

**Files:**
- Create: `resources/js/Pages/Incidents/Show.vue`

- [x] **Step 1: Write it**

```vue
<script setup>
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import SeverityBadge from '@/Components/SeverityBadge.vue';

const props = defineProps({
    incident: { type: Object, required: true },
    tab: { type: String, required: true },
});

const tabs = [
    { value: 'overview', label: 'Overview & Case Summary' },
    { value: 'investigation', label: 'Investigation & Root Cause' },
    { value: 'capa', label: 'Corrective & Preventive Actions' },
    { value: 'attachments', label: 'Evidence & Attachments' },
    { value: 'approvals', label: 'Approvals' },
    { value: 'audit', label: 'Audit Trail' },
];

const activeTab = ref(props.tab);

function switchTab(value) {
    activeTab.value = value;
    router.get(`/incidents/${props.incident.id}`, { tab: value }, { preserveState: true, preserveScroll: true });
}

const notYetAvailable = computed(() => !['overview', 'attachments'].includes(activeTab.value));
</script>

<template>
    <Head :title="incident.incident_number ?? `Incident #${incident.id}`" />

    <AuthenticatedLayout>
        <div class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-space-sm">
            <div class="flex flex-wrap items-center gap-space-sm">
                <StatusBadge :status="incident.status" />
                <SeverityBadge v-if="incident.severity" :severity="incident.severity" />
                <span class="font-code-tabular text-body-sm text-outline">{{ incident.incident_number ?? `Draft #${incident.id}` }}</span>
            </div>
            <h1 class="font-headline-md text-headline-md text-primary tracking-tight">
                {{ incident.summary || 'Incident report' }}
            </h1>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 bg-surface-container-low p-space-md rounded-xl">
            <div class="flex flex-col gap-0.5">
                <span class="font-label-sm text-body-sm text-outline">Incident Time</span>
                <span class="font-code-tabular text-body-md text-on-surface font-semibold">{{ incident.occurred_at ?? '—' }}</span>
            </div>
            <div class="flex flex-col gap-0.5">
                <span class="font-label-sm text-body-sm text-outline">Reported</span>
                <span class="font-code-tabular text-body-md text-on-surface font-semibold">{{ incident.reported_at ?? '—' }}</span>
            </div>
            <div class="flex flex-col gap-0.5">
                <span class="font-label-sm text-body-sm text-outline">Clinical Unit</span>
                <span class="font-title-sm text-title-sm text-primary font-semibold">{{ incident.department?.name ?? '—' }}</span>
            </div>
            <div class="flex flex-col gap-0.5">
                <span class="font-label-sm text-body-sm text-outline">Reporter</span>
                <span class="font-body-md text-body-md text-on-surface font-semibold">{{ incident.reporter?.name ?? '—' }}</span>
            </div>
            <div class="flex flex-col gap-0.5">
                <span class="font-label-sm text-body-sm text-outline">Lead Investigator</span>
                <span class="font-body-md text-body-md text-secondary font-semibold">{{ incident.assigned_investigator?.name ?? 'Not yet assigned' }}</span>
            </div>
            <div class="flex flex-col gap-0.5">
                <span class="font-label-sm text-body-sm text-outline">Type</span>
                <span class="font-body-md text-body-md text-on-surface font-semibold">{{ incident.incident_type?.name ?? '—' }}</span>
            </div>
        </div>

        <div class="flex items-center gap-2 overflow-x-auto pb-1">
            <button
                v-for="tabOption in tabs"
                :key="tabOption.value"
                type="button"
                class="px-4 py-2.5 rounded-lg font-label-md text-label-md whitespace-nowrap"
                :class="activeTab === tabOption.value ? 'bg-primary text-on-primary font-semibold shadow-sm' : 'text-on-surface-variant hover:bg-surface-container'"
                @click="switchTab(tabOption.value)"
            >
                {{ tabOption.label }}
            </button>
        </div>

        <div v-if="activeTab === 'overview'" class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-space-lg">
            <div class="flex flex-col gap-1">
                <span class="font-label-sm text-body-sm uppercase text-outline font-semibold">Executive Narrative Summary</span>
                <p class="font-body-md text-body-md text-on-surface">{{ incident.summary || '—' }}</p>
            </div>

            <div v-if="incident.individuals?.length" class="flex flex-col gap-2">
                <span class="font-label-sm text-body-sm uppercase text-outline font-semibold">People Involved</span>
                <div v-for="person in incident.individuals" :key="person.id" class="p-3 rounded-lg bg-surface-container-low flex flex-col">
                    <span class="font-title-sm text-title-sm text-on-surface font-semibold">{{ person.name }} ({{ person.person_type }})</span>
                    <span class="font-body-sm text-body-sm text-on-surface-variant">{{ person.role_description }}</span>
                </div>
            </div>

            <div v-if="incident.witnesses?.length" class="flex flex-col gap-2">
                <span class="font-label-sm text-body-sm uppercase text-outline font-semibold">Witnesses</span>
                <div v-for="witness in incident.witnesses" :key="witness.id" class="p-3 rounded-lg bg-surface-container-low flex flex-col">
                    <span class="font-title-sm text-title-sm text-on-surface font-semibold">{{ witness.name }}</span>
                    <span class="font-body-sm text-body-sm text-on-surface-variant">{{ witness.statement }}</span>
                </div>
            </div>

            <div v-if="incident.actions?.length" class="flex flex-col gap-2">
                <span class="font-label-sm text-body-sm uppercase text-outline font-semibold">Immediate Actions Taken</span>
                <div v-for="action in incident.actions" :key="action.id" class="p-3 rounded-lg bg-surface-container-low flex flex-col">
                    <span class="font-body-md text-body-md text-on-surface">{{ action.description }}</span>
                    <span class="font-body-sm text-body-sm text-on-surface-variant">{{ action.responsible_name }} — {{ action.status }}</span>
                </div>
            </div>

            <div v-if="incident.recommendations" class="flex flex-col gap-1">
                <span class="font-label-sm text-body-sm uppercase text-outline font-semibold">Recommendations / Preventive Measures</span>
                <p class="font-body-md text-body-md text-on-surface">{{ incident.recommendations }}</p>
            </div>
        </div>

        <div v-else-if="activeTab === 'attachments'" class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm flex flex-col gap-2">
            <div v-if="!incident.attachments?.length" class="text-center font-body-sm text-body-sm text-outline p-space-lg">
                No attachments uploaded.
            </div>
            <a
                v-for="file in incident.attachments"
                :key="file.id"
                :href="`/attachments/${file.id}`"
                class="p-3 rounded-lg bg-surface-container-low flex items-center justify-between hover:bg-surface-container"
            >
                <span class="font-body-sm text-body-sm text-on-surface">{{ file.original_filename }}</span>
                <FontAwesomeIcon icon="eye" class="text-primary" />
            </a>
        </div>

        <div v-else-if="notYetAvailable" class="rounded-xl bg-surface-container-lowest p-space-lg shadow-sm text-center">
            <FontAwesomeIcon icon="circle-info" class="text-primary text-title-lg mb-2" />
            <p class="font-body-md text-body-md text-on-surface-variant">
                This tab will be available once the corresponding module ships in a later phase.
            </p>
        </div>
    </AuthenticatedLayout>
</template>
```

- [x] **Step 2: Commit**

```bash
git add resources/js/Pages/Incidents/Show.vue
git commit -m "feat: add incident detail page with Overview and Attachments tabs"
```

---

### Task 15: Wire sidebar links and update the architecture doc

**Files:**
- Modify: `resources/js/Layouts/AuthenticatedLayout.vue`
- Modify: `docs/architecture.md`

- [x] **Step 1: Wire the CTA and three relevant sidebar links**

In `resources/js/Layouts/AuthenticatedLayout.vue`, change the "Report an Incident" CTA from:

```html
<a
    href="#"
    class="flex items-center justify-center gap-space-xs w-full py-2.5 px-space-md rounded-lg bg-secondary text-on-secondary hover:bg-on-secondary-container font-label-md text-label-md transition-all shadow-[0_1px_3px_rgba(0,106,97,0.2)]"
>
```

to:

```html
<Link
    href="/incidents/create"
    class="flex items-center justify-center gap-space-xs w-full py-2.5 px-space-md rounded-lg bg-secondary text-on-secondary hover:bg-on-secondary-container font-label-md text-label-md transition-all shadow-[0_1px_3px_rgba(0,106,97,0.2)]"
>
```

(and its closing `</a>` to `</Link>`).

In the `navGroups` array's `'Incident Management'` group, change the `href: '#'` values for `All Incidents`, `My Reports`, and `Draft Reports` to real URLs:

```js
            { label: 'All Incidents', icon: 'kit-medical', href: '/incidents?scope=all', count: null },
            { label: 'My Reports', icon: 'user', href: '/incidents?scope=my-reports', count: null },
            { label: 'Draft Reports', icon: 'pen-to-square', href: '/incidents?scope=drafts', count: null },
```

Leave every other nav item's `href: '#'` as-is — they belong to later phases.

- [x] **Step 2: Update `docs/architecture.md`**

Add a new subsection right after "## 9a. Implementation note — Inertia version ceiling":

```markdown
## 9b. Phase 3 implementation notes

- `incidents.recommendations` (text, nullable) was added during implementation — the original §2.2 column list omitted the "Recommendations / Preventive Measures" field from the paper-form brief (wizard step 7). Schema above is now the source of truth; this note just records the discrepancy.
- The Vue wizard collects `individuals`/`witnesses`/`narrative_events`/`actions_taken` as plain arrays and `IncidentService` does a delete-and-recreate on every save rather than diffing by ID. Simple and correct for Phase 3's "reporter fills out their own form" use case; would need to change to an upsert-by-id strategy if these child records ever need edit history or if anyone other than the reporter edits them.
- Sidebar nav item counts (e.g. "142" next to "All Incidents") are intentionally still not wired up — would need either a per-request count query in `HandleInertiaRequests::share()` or a caching strategy, neither of which is justified yet at this data volume. Revisit in Phase 8 (Analytics) or if it becomes a real UX complaint.
```

- [x] **Step 3: Commit**

```bash
git add resources/js/Layouts/AuthenticatedLayout.vue docs/architecture.md
git commit -m "docs: wire incident sidebar links, record Phase 3 schema notes"
```

---

### Task 16: Full verification pass

- [x] **Step 1: Run the full PHPUnit suite**

```bash
php artisan test
```

Expected: all tests pass (Phase 2's 7 + Phase 3's 12 = 19, plus the pre-existing `ExampleTest` unit test = 20 total).

- [x] **Step 2: Production build**

```bash
npm run build
```

Expected: builds with no errors.

- [x] **Step 3: Browser-drive the full flow with Playwright**

Reuse the scratchpad Playwright setup from Phase 2 verification (`playwright@1.48.2` + downloaded Chromium, already present under the session's scratchpad `pw/` directory). Start both dev servers:

```bash
php artisan serve --port=8123 &
npm run dev &
```

Write and run a script that:
1. Logs in as `staff@hopss.test` / `password`.
2. Navigates to `/incidents/create`, fills Step 2 (type, department, severity, date, location) and Step 5 (summary), clicks "Save as Draft".
3. Confirms redirect to `/incidents/{id}/edit` and that the draft's fields are pre-filled.
4. Navigates through all 8 steps, checks the legal attestation box in Step 1, clicks "Submit Report" on Step 8, confirms in the dialog.
5. Confirms redirect to `/incidents/{id}` showing a real `IR-2026-000001`-style incident number and `Submitted` status badge.
6. Visits `/incidents?scope=my-reports` and confirms the submitted incident appears in the table.
7. Checks `console --errors` / `page.on('pageerror')` is empty throughout.

Take a screenshot after step 5 (submitted incident detail) and step 6 (incident list) and view them to visually confirm the Stitch-derived styling renders correctly (cards, badges, table).

- [x] **Step 4: Report results**

If everything passes, this phase is done. If Playwright surfaces a runtime error the test suite didn't catch (as happened in Phase 2 with the Inertia version mismatch), fix it, re-run the whole verification pass from Step 1, and update `docs/architecture.md` §9a/§9b if the fix reveals another non-obvious gotcha worth recording.

---

## Self-review notes

**Spec coverage check** — every Phase 3 bullet from the brief maps to a task: Create incident → Tasks 5+8+12; Save/edit draft → Tasks 5+8+12; Submit → Tasks 5+8+12; Validation → Task 6; Incident number → Task 5; Attachments → Tasks 6+8+11(Step5)+14; Incident list → Task 13; Incident detail → Task 14; Status tracking → `IncidentStatus` enum (Task 2) surfaced via `StatusBadge` everywhere (Tasks 9, 13, 14).

**Type consistency check** — `IncidentService::createDraft`/`updateDraft`/`submit` signatures match what `IncidentController` calls in Task 8. The `action`/`individuals`/`witnesses`/`narrative_events`/`actions_taken`/`contributing_factor_ids`/`attachments` field names are identical across the Form Request trait (Task 6), the service's `syncChildRecords` (Task 5), and every Vue step component + `Wizard.vue`'s `useForm()` (Tasks 11–12) — cross-checked field-by-field while writing this plan.
