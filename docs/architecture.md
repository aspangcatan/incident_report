# Architecture — Electronic Incident Report Management System

Status: **Phase 1 draft, pending review**. Nothing in this document has been implemented yet. This is the design to be validated before Phase 2 (foundation) begins.

Confirmed stack decisions (2026-09-16): Font Awesome for icons (not the Material Symbols used in the Stitch mockups), Vite replacing Laravel Mix, hand-rolled authentication (no Breeze).

---

## 1. Guiding constraints carried into every decision below

- The Laravel app is a blank Laravel 9.52.22 skeleton; the configured database doesn't exist yet. No destructive-migration risk exists today, but every migration from here on is written as if it will one day run against real incident data (additive, reversible, no silent column repurposing).
- Avoid overengineering: several "obvious" tables from the original brief (`workflow_configurations`, `escalation_rules`, a generic `roles` table) are deliberately deferred in favor of enums/config files, with an explicit upgrade path noted where relevant.
- Vue 3 + Inertia is the primary frontend. jQuery is not part of this stack unless a specific third-party widget forces it later.

---

## 2. Database schema

### 2.1 Identity & org structure

**users** (extends Laravel default)
- `role` — enum: `staff, supervisor, investigator, department_head, quality_safety_officer, administrator, management`. Default `staff`. "Reporter" is not a role — every authenticated `staff`-and-above user can file a report; the role only governs review/investigation/admin permissions.
- `department_id` (FK → departments, nullable)
- `designation` (string, free text — e.g. "Senior Attending Physician")
- `employee_no` (string, nullable, unique)
- `prc_license_no` (string, nullable — for clinicians, shown on the report form)
- `is_active` (bool, default true)
- `external_user_id` (string, nullable, unique) — reserved for the future link to the hospital's real user-management system (`tdh_user.users`, a separate database holding all hospital and non-hospital staff). **Not wired up yet.** For now this app keeps its own local `users` table and hand-rolled auth so development can proceed; no Admin → Users CRUD screens are being built against it. When the integration is scoped, the plan is either (a) point this app's DB connection at `tdh_user` directly, or (b) keep a local table synced/linked via `external_user_id` — that decision is deferred, not this column.

**departments**
- `id`, `name`, `code` (unique), `parent_department_id` (FK self, nullable — supports divisions like "ICU" under "Nursing Service"), `head_user_id` (FK → users, nullable), `is_active`

**incident_types**
- `id`, `name`, `category` (enum: `clinical_safety, medication, facility_biomed, security, occupational, other`), `default_severity` (nullable), `is_active`
- Managed under Administration → Incident Types, per the brief.

### 2.2 Incident core

**incidents**
- `incident_number` (string, unique, format `IR-{YYYY}-{000000}`, assigned atomically on submit — not on draft creation)
- `reporter_id` (FK users)
- `department_id` (FK departments — clinical unit where it occurred)
- `incident_type_id` (FK incident_types)
- `severity` — enum `level_1_low, level_2_moderate, level_3_high, level_4_critical_sentinel` (matches the Stitch form's Level I–IV cards)
- `status` — enum, see §3
- `occurred_at`, `reported_at` (datetime)
- `location` (string — precise location/bed, per form Section 2)
- `summary` (text — "Executive Narrative Summary")
- `is_sentinel_event` (bool — defaults from severity, can be overridden)
- `police_notified` (bool) + `police_station`, `police_officer_in_charge`, `police_blotter_no`, `police_notified_at` — kept as columns on `incidents` rather than a separate 1:1 table; it's a small, always-optional block, not a distinct aggregate.
- `assigned_investigator_id` (FK users, nullable — denormalized pointer to the lead; source of truth is `investigations.lead_investigator_id` / `investigation_team_members`)
- `supervisor_reviewed_by`, `supervisor_reviewed_at`, `supervisor_comments`
- `closed_by`, `closed_at`, `target_closure_date` (nullable — computed at assignment time from the SLA config in §5)
- `legal_attestation_at` (timestamp of the Section-1 certification checkbox)
- `deleted_at` (soft delete — only ever used for a reporter discarding their own **draft**; policy blocks deleting anything past `submitted`)

**incident_individuals** (People Involved roster, form Section 3)
`incident_id`, `person_type` (enum: `patient, staff, visitor, other`), `name`, `identifier` (HRN or employee no), `role_description`, `department_id` (nullable), `details` (text — age/sex/diagnosis or shift context)

**incident_witnesses**
`incident_id`, `name`, `designation`, `address` (nullable), `contact_number` (nullable), `statement` (text, nullable)

**incident_actions** (Immediate containment actions, form Section 5)
`incident_id`, `description`, `responsible_user_id` (nullable), `responsible_name` (free-text fallback), `performed_at`, `status` (enum: `completed, pending`)

**incident_narrative_events** (reporter-entered "Sequence of Events," form Section 4 — distinct from the system lifecycle timeline in §2.5)
`incident_id`, `occurred_at` (time), `description`, `sort_order`

**contributing_factors** (lookup, admin-managed) + **incident_contributing_factor** (pivot)
Replaces hardcoded checkbox text in the form with a configurable list (`High Patient Surge`, `Shift Handover Interruption`, …), grouped by `category`.

### 2.3 Investigation

**investigations** (1:1 with incidents for now — a re-opened incident gets a new investigation row only if we later need investigation history; not needed at launch)
`incident_id`, `lead_investigator_id`, `objective` (text), `methodology` (enum: `five_whys, fishbone, hfacs, contributing_factors`), `started_at`, `target_completion_at`, `completed_at` (nullable), `conclusion` (text, nullable), `status` (enum: `not_started, in_progress, completed`)

**investigation_team_members**
`investigation_id`, `user_id`, `role_in_team` (string — "Lead Investigator", "Biomedical Safety Officer", "Nursing Service Rep", matching the mockup's 3-person team block)

**investigation_findings** — one flexible table instead of three methodology-specific tables:
`investigation_id`, `sequence` (int, nullable — Why #1–5 ordering), `category` (string, nullable — Fishbone/HFACS bucket, e.g. "Environmental & Ergonomic"), `question` (string, nullable — the "Why…?" prompt), `finding` (text), `is_root_cause` (bool)

### 2.4 Corrective & Preventive Actions (CAPA)

**corrective_actions**
`capa_number` (unique, `CAPA-{YYYY}-{000}`), `incident_id`, `investigation_id` (nullable), `description`, `root_cause_finding_id` (FK → investigation_findings, nullable), `action_type` (enum: `corrective, preventive, both`), `responsible_user_id` (nullable), `responsible_department_id` (nullable), `priority` (enum: `low, medium, high, critical`), `due_date`, `status` (enum: `open, in_progress, completed, for_verification, verified` — **no stored `overdue` value**, see §3.2), `completion_notes`, `completed_by`, `completed_at`, `verification_comments`, `verified_by`, `verified_at`

### 2.5 Cross-cutting

**attachments** (polymorphic — replaces a per-module attachments table)
`attachable_type`, `attachable_id`, `uploaded_by`, `disk_path`, `original_filename`, `mime_type`, `size`, `category` (enum: `evidence, photo, document, report, other`), `description` (nullable)
Stored on a private disk; served only via an authorized `GET /attachments/{attachment}` route gated by policy — never a public URL.

**approvals**
`incident_id`, `requested_by`, `approver_id` (nullable), `status` (enum: `pending, approved, returned`), `comments` (nullable), `decided_at` (nullable) — backs the "Return for Revision" flow at the closure gate.

**audit_logs** (immutable — no update/delete path exposed anywhere, including to Administrators)
`auditable_type`, `auditable_id`, `actor_id` (nullable, for system/scheduled actions), `action` (string — `created, submitted, status_changed, investigation_started, finding_added, action_completed, action_verified, approved, returned, closed`, …), `description` (nullable), `old_values` (json, nullable), `new_values` (json, nullable), `created_at` only.
This single table serves **both** the Administration → Audit Trail module **and** the incident detail page's "Timeline" tab (the timeline is a filtered, presentation-friendly view over audit log rows for that incident — not a separately maintained table). Written automatically via model observers, not scattered manual calls.

**notifications** — Laravel's built-in table via the `Notifiable` trait. No custom notifications table.

### 2.6 Deliberately deferred (config-first, not a table)

- **Roles**: an enum column on `users`, not a `roles` table. Promote to a real roles/permissions table (or the `spatie/laravel-permission` package) only if the hospital needs custom roles beyond the 7 in the brief — not needed to ship Phase 1–7.
- **Workflow stage requirements & SLA hours per severity**: `config/incident_workflow.php`, keyed by severity → required stages + SLA hours. Editable by a developer today; promotable to a `workflow_configurations` table + admin UI later if the hospital needs to change SLAs without a deploy.
- **Escalation recipients**: same config file, mapping severity/stage → role or department-head chain to notify on breach. Same promotion path as above.

These two config-file items are the only place the brief's "should be configurable" requirement is met with code instead of a database row, and it's the one place I'd like explicit sign-off on, since it trades day-one DB-configurability for not shipping two speculative tables with no admin UI behind them yet.

---

## 3. Status lifecycles

### 3.1 Incident status (PHP 8.2 backed enum, `App\Enums\IncidentStatus`)

```
draft → submitted → for_review → reviewed → assigned → under_investigation
      → corrective_action → for_verification → verified → for_approval → closed
```

- Not every incident visits every stage — a Level I near-miss can skip straight from `reviewed` to `closed` without a formal investigation. Which stages a given severity requires lives in `config/incident_workflow.php` (§2.6), consulted by `IncidentService::nextStage()`.
- "Return for revision" is **not** a new status value. It's an `audit_logs` entry (`action = returned`) plus moving `status` back to the appropriate prior stage (e.g. `for_approval` → `corrective_action`). Modeling it as a logged transition instead of a dedicated status keeps the state machine linear and keeps the "why did this go backward" story in the audit trail where it belongs.

### 3.2 Corrective action status

`open, in_progress, completed, for_verification, verified` — stored. `overdue` is **never stored**; it's `due_date < now() && status not in (completed, verified)`, exposed as an `isOverdue()` accessor / query scope, per the brief's explicit instruction that overdue must be "determined logically rather than manually entered."

### 3.3 Severity

`level_1_low, level_2_moderate, level_3_high, level_4_critical_sentinel` — matches the Stitch form's Level I–IV cards exactly.

---

## 4. Roles & authorization

Seven-role enum from §2.6. Enforcement is via Laravel **Policies**, never client-side button-hiding alone:

- `IncidentPolicy` — `view` (owner, department members, assigned investigator, supervisor/dept-head of that department, QSO, admin, management); `update` (owner while `draft`, or assigned reviewer at the review stage); `submit`, `review`, `assign`, `approve`, `close` gated per stage + role.
- `InvestigationPolicy` — `update` limited to team members of that investigation + QSO/admin.
- `CorrectiveActionPolicy` — `complete` limited to `responsible_user_id`; `verify` limited to a role above the responsible person (supervisor/QSO/dept-head), never self-verification.
- `UserPolicy`, `DepartmentPolicy`, `IncidentTypePolicy` — administrator-only writes.

Vue only reads exposed `can` flags (passed via Inertia shared props) to hide/show controls — every action is re-checked server-side regardless of what the UI shows.

---

## 5. Workflow, SLA & notifications

- SLA targets and required stages per severity live in `config/incident_workflow.php` (§2.6).
- A daily scheduled command (`incidents:check-overdue`) evaluates open incidents/CAPAs against their SLA and dispatches escalation notifications — no cron-per-record, one sweep.
- Notification triggers are domain **events** (`IncidentSubmitted`, `IncidentAssigned`, `InvestigationOverdue`, `CorrectiveActionOverdue`, `VerificationRequired`, `ApprovalRequired`, `IncidentClosed`, …) with **listeners** that call `Notification::send()`. Keeps controllers thin and keeps "who gets notified" logic in one place (the listener), not duplicated across controllers.
- Recipients resolve through the same config as escalation (role or explicit department-head chain), never a hardcoded user ID.

---

## 6. Vue / Inertia structure

```
resources/js/
  Pages/
    Auth/Login.vue                    (hand-rolled; no Stitch mockup exists for this — new design following DESIGN.md tokens)
    Dashboard/Index.vue
    Incidents/Index.vue               (scoped via ?scope=my-reports|drafts|pending-review|... matching the sidebar counts)
    Incidents/Create.vue              (multi-step wizard shell; 8 steps per the reporting-form spec)
    Incidents/Show.vue                (tabs: Overview, Investigation, CAPA, Attachments, Approvals, Audit Trail — via ?tab=)
    Incidents/Edit.vue                (draft owner only)
    Investigations/Index.vue          (Queue / Assigned to Me / History — scoped like Incidents/Index)
    CorrectiveActions/Index.vue       (Open / For Verification / Overdue / Completed — scoped)
    Analytics/Index.vue               ("Learn" dashboard)
    Notifications/Index.vue
    Admin/Users/Index.vue, Admin/Departments/Index.vue, Admin/IncidentTypes/Index.vue
  Layouts/
    AuthenticatedLayout.vue           (header + sidebar shell, matches Stitch main_dashboard chrome)
    GuestLayout.vue
  Components/
    StatusBadge, SeverityBadge, IncidentCard, StatCard, DataTable, FilterBar, Modal,
    ConfirmationDialog, Timeline, FileUploader, Stepper, FormSection, EmptyState,
    LoadingState, Pagination, NotificationItem, RcaWorkbench, CapaCard
  Composables/
    useIncidentStatus (label/color mapping), usePagination, useConfirm
  Utils/
    formatDate, formatIncidentNumber
```

Sidebar nav item counts and groupings are lifted directly from the Stitch mockup's `data-path` attributes (Incident Management / Investigation Workspace / CAPA Operations / Analytics & Learning / Administration & Audit).

---

## 7. Routing (conceptual — finalized during Phase 2/3)

```
/dashboard
/incidents                          ?scope=...
/incidents/create
/incidents/{incident}               ?tab=...
/incidents/{incident}/edit
POST /incidents/{incident}/submit
POST /incidents/{incident}/review
POST /incidents/{incident}/assign
POST /incidents/{incident}/approve
POST /incidents/{incident}/close
/incidents/{incident}/investigation (nested: start, update, findings CRUD)
/incidents/{incident}/actions       (CAPA create/update, /complete, /verify)
/investigations                     ?scope=...
/actions                            ?scope=...  (cross-incident CAPA queues)
/notifications
/analytics
/admin/users, /admin/departments, /admin/incident-types
GET /attachments/{attachment}       (authorized file serving)
```

---

## 8. Services & cross-cutting concerns

- `IncidentService` — create/submit/transition/incident-number generation.
- `InvestigationService`, `CorrectiveActionService` — the "complete → for_verification → verify" flow lives here, not in controllers.
- `AuditLogger` — a model observer applied to `Incident`, `Investigation`, `CorrectiveAction`, `Approval` that writes `audit_logs` rows automatically on relevant lifecycle events, so no controller has to remember to log anything.
- Form Requests for every write endpoint; validation rules mirror the required/optional fields visible in the Stitch form sections.

---

## 9a. Implementation note — Inertia version ceiling

`inertiajs/inertia-laravel` on Packagist tops out at `v1.3.4` for a Laravel 9 app (the v2/v3 server adapter lines require Laravel 10+). That v1.x server adapter renders the initial page payload as a `<div id="app" data-page="...">` attribute. The JS ecosystem has since moved to Inertia 3 (`@inertiajs/vue3` latest is 3.x), which changed the DOM contract to a `<script type="application/json" data-page="...">` tag instead — installing the npm "latest" therefore produced a hard runtime crash (`Cannot read properties of null (reading 'component')`) because the two halves speak different protocols. Fixed by pinning `@inertiajs/vue3` to the `^2.0` line, which matches what v1.3.4 of the server adapter emits. If Laravel is ever upgraded to 10+, both sides can be bumped together (inertia-laravel v3 + `@inertiajs/vue3` v3) to pick up v3-only features (polling, prefetching, history encryption UI). Caught by driving the actual app in a browser (Playwright) during Phase 2 verification, not by the build or test suite alone — `npm run build` and PHPUnit both stayed green through this breakage since neither renders a real page in a browser.

## 9b-2. Phase 3 sidebar wiring

`Report an Incident`, `All Incidents`, `My Reports`, and `Draft Reports` in the sidebar now link to real routes (`/incidents/create`, `/incidents?scope=all|my-reports|drafts`). Every other sidebar item (Investigation Workspace, CAPA Operations, Analytics & Learning, Administration & Audit groups) intentionally still points at `href: '#'` — those modules don't exist until later phases. Nav item counts (e.g. the "142" badge shown next to "All Incidents" in the original Stitch mockup) remain unwired, per the note already recorded in §9b.

## 9. Decisions confirmed 2026-09-16

1. **User management is out of scope for this app.** A separate system of record already exists (`tdh_user.users`, covering all hospital and non-hospital staff). No Admin → Users CRUD is being built here. For now this app runs its own local `users` table and hand-rolled auth purely so development can proceed; real integration with `tdh_user` (shared DB connection vs. synced/linked local table) is a deferred decision, not implemented in Phase 1–2.
2. **Workflow SLA rules & escalation recipients**: config file (`config/incident_workflow.php`), not database tables — confirmed, ships faster, promotable later if needed.
3. Icon mapping: Font Awesome doesn't have a 1:1 equivalent for every Material Symbol used in the mockups (e.g. `crisis_alert`, `manage_search`) — the closest FA icon will be picked per case during conversion rather than blocking on an exhaustive mapping table now.

## 9c. Known Phase 3 limitation — existing attachments invisible on re-edit

`Incidents/Wizard.vue`'s `useForm()` only tracks newly-staged `File` objects for the current editing session (`attachments: []`); it does not hydrate from `props.incident.attachments` (files already uploaded on a previous save). No data loss occurs — `IncidentController::storeAttachments()` runs unconditionally on every `store()`/`update()` call regardless of `action`, so previously uploaded files stay attached to the incident server-side — but a user re-opening an in-progress draft has no way to see or remove what they already uploaded from within the wizard (they can still see them once the incident reaches `Incidents/Show.vue`'s Attachments tab, post-submission). Fixing this properly needs a way to list + remove existing attachments mid-draft, which needs a `DELETE /attachments/{id}` route that doesn't exist yet. Deferred rather than built speculatively into Task 12 — revisit if this becomes a real user complaint.
