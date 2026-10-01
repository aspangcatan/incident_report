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

**(superseded 2026-09-24, see §9j)** The original design below (a local `users`/`departments` pair with an `external_user_id` deferred-integration column) was never built out this way. As of 2026-09-24, `users` and `departments` are **live, read-only reads of the hospital's shared `tdh_user` database** — no local copy, no sync:

- **users** — `App\Models\User` binds to `tdh_user.users` via the `user` connection (`config/tdh.php`). No local table; the row is read live on every request.
  - `role` — resolved from `tdh_user.user_priv.level` where `syscode = 'IR'` (this system's static code); values are the `App\Enums\Role` backing values (`staff, supervisor, investigator, department_head, quality_safety_officer, administrator, management`). No IR row, or an unrecognised level, resolves to `Role::Staff` (fails closed). Levels are matched case- and surrounding-space-insensitively (`strtolower(trim())` in PHP), mirroring how the `latin1_swedish_ci` column compares in SQL. `user_priv` has a live `UNIQUE KEY (user_id, syscode)`, so a user has at most one IR row.
  - `department_id` — `users.section` (an integer column on the tdh row; not a foreign key).
  - `designation` — `designation_title` accessor, from the `designation` FK's `description` (tdh `designation` table) falling back to `other_designation`.
  - `is_active` — `status === '1'`.
  - Login is tdh username + password (`Auth::attempt(['username' => …, 'password' => …, 'status' => '1'])`); no remember-me; throttled to 5 failed attempts per minute per lowercased username + IP. A session ends on the next request once the account is deactivated in tdh (`EnsureTdhUserIsActive`).
- **departments** — `App\Models\Department` binds to `tdh_user.section` via the same connection. `name` is an accessor over `description`; there's no `is_active` column, so every section is selectable except placeholder rows whose `description` is `'-'` (excluded from dropdowns via `scopeSelectable()`).
- `incident_report` keeps its `*_id` reference columns (`reporter_id`, `department_id`, `assigned_investigator_id`, etc.) as plain indexed unsigned integers, but they carry **no foreign-key constraints** to users/departments — MySQL cannot enforce FKs across databases, and referential integrity to tdh ids is an application concern. The local `users`, `departments`, `password_resets`, and `personal_access_tokens` tables have been dropped.

**incident_types**
- `id`, `name`, `category` (enum: `clinical_safety, medication, facility_biomed, security, occupational, other`), `default_severity` (nullable), `is_active`
- Managed under Administration → Incident Types, per the brief.

### 2.2 Incident core

**incidents**
- `incident_number` (string, unique, format `IR-{YYYY}-{000000}`, assigned atomically on submit — not on draft creation)
- `reporter_id` (FK users)
- `department_id` (FK departments — clinical unit where it occurred)
- `incident_type_id` (FK incident_types)
- `severity` — enum `level_1_low, level_2_moderate, level_3_high, level_4_critical, level_5_sentinel` (five levels since 2026-10-01, see §9o; originally four, with Level IV `level_4_critical_sentinel`)
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
- **(2026-09-25, see §9k)** `submitted` and `for_review` now carry distinct meanings: `submitted` is the **Department Assessment** stage (the incident's own department fills in actions/recommendations/severity), `for_review` means the assessment is complete and the incident is ready for the Supervisor/Department Head/QSO/Admin review step. Both status values already existed in the enum before §9k — this only changed which stage writes/reads which value; no new status was added.

### 3.2 Corrective action status

`open, in_progress, completed, for_verification, verified` — stored. `overdue` is **never stored**; it's `due_date < today && status != verified`, exposed as an `isOverdue()` accessor / query scope, per the brief's explicit instruction that overdue must be "determined logically rather than manually entered."

### 3.3 Severity

`level_1_low, level_2_moderate, level_3_high, level_4_critical, level_5_sentinel` — the client's five-level Risk Triage scale (Level I–V). Originally four levels (Level IV was "Critical / Sentinel"); split 2026-10-01, see §9o.

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

## 9b. Phase 3 sidebar wiring

`Report an Incident`, `All Incidents`, `My Reports`, and `Draft Reports` in the sidebar now link to real routes (`/incidents/create`, `/incidents?scope=all|my-reports|drafts`). Every other sidebar item (Investigation Workspace, CAPA Operations, Analytics & Learning, Administration & Audit groups) intentionally still points at `href: '#'` — those modules don't exist until later phases. Nav item counts (e.g. the "142" badge shown next to "All Incidents" in the original Stitch mockup) remain unwired — no per-request count query has been added, since none is justified yet at this data volume; revisit in Phase 8 (Analytics) or if it becomes a real UX complaint.

**Exception, added in Phase 4:** the header's notification bell *does* carry a real per-request count (`HandleInertiaRequests` shares `unreadNotificationsCount` via `$user->unreadNotifications()->count()`, evaluated on effectively every authenticated page load). This is a deliberate, scoped exception to the stance above, not a reversal of it — one indexed lookup (`notifications` table's `morphs('notifiable')` index) backing a live, user-facing feature is a different cost/value trade than N speculative counts across unrelated sidebar items with no established product need. If a second or third such per-request count is ever proposed, that's the point to revisit whether this exception is still holding.

## 9c. Decisions confirmed 2026-09-16

1. **User management is out of scope for this app.** A separate system of record already exists (`tdh_user.users`, covering all hospital and non-hospital staff). No Admin → Users CRUD is being built here. **(superseded 2026-09-24, see §9j):** at the time this was written the app ran its own local `users` table and hand-rolled auth as a stand-in, with real `tdh_user` integration deferred; that integration has since been built — see §9j.
2. **Workflow SLA rules & escalation recipients**: config file (`config/incident_workflow.php`), not database tables — confirmed, ships faster, promotable later if needed.
3. Icon mapping: Font Awesome doesn't have a 1:1 equivalent for every Material Symbol used in the mockups (e.g. `crisis_alert`, `manage_search`) — the closest FA icon will be picked per case during conversion rather than blocking on an exhaustive mapping table now.

## 9d. Known Phase 3 limitation — existing attachments invisible on re-edit

`Incidents/Wizard.vue`'s `useForm()` only tracks newly-staged `File` objects for the current editing session (`attachments: []`); it does not hydrate from `props.incident.attachments` (files already uploaded on a previous save). No data loss occurs — `IncidentController::storeAttachments()` runs unconditionally on every `store()`/`update()` call regardless of `action`, so previously uploaded files stay attached to the incident server-side — but a user re-opening an in-progress draft has no way to see or remove what they already uploaded from within the wizard (they can still see them once the incident reaches `Incidents/Show.vue`'s Attachments tab, post-submission). Fixing this properly needs a way to list + remove existing attachments mid-draft, which needs a `DELETE /attachments/{id}` route that doesn't exist yet. Deferred rather than built speculatively into Task 12 — revisit if this becomes a real user complaint.

## 9e. Phase 4 complete — Workflow (Review, Assignment, Notifications, Escalation, Audit Trail)

Implemented per `docs/superpowers/plans/2026-09-16-workflow.md` (all 15 tasks), via subagent-driven development with spec-compliance + code-quality review per task, plus a final Playwright-driven end-to-end verification of the full review → return-for-revision → resubmit → review → assign → notify flow (zero console errors, 63 PHPUnit tests passing).

- **Lifecycle:** `IncidentService::markReviewed()`, `returnForRevision()` (submitted → draft, requires a comment, reuses the same `incident_number` on resubmission), and `assignInvestigator()` (sets investigator + status `Assigned`, computes `target_closure_date` from `config('incident_workflow.investigation_sla_hours')` when not supplied) — added alongside the existing `createDraft`/`updateDraft`/`submit` rather than a second service class (same kind of guarded-transition-plus-persistence work).
- **Audit trail:** new `audit_logs` table + `AuditLog` model (immutable — `booted()` guards throw on `update()`/`delete()`), populated automatically by `IncidentObserver` on `created` and on `updated` (independent checks for `status` and `assigned_investigator_id` changes, not mutually exclusive — a single `assignInvestigator()` save produces both an `assigned` and a `status_changed` row). Rendered in `Show.vue`'s Audit Trail tab (Timeline was not built as a separate tab, per the confirmed decision — merged here). `IncidentController::show()` fetches the relation explicitly ordered `->latest()->latest('id')`: `created_at` alone has only second-level precision and ties (same-second multi-row writes) need `id` as a secondary key to render true chronological order — caught via visual Playwright verification, not by the test suite, which only asserted presence/absence of rows before this was added.
- **Authorization:** `IncidentPolicy::review()`/`assign()`, department-scoped the same way `view()` already is; Management has read-only access (`view()` only), not operational access.
- **Notifications:** database-channel only (no email — `MAIL_MAILER` unconfirmed). Four domain events (`IncidentSubmitted`, `IncidentReviewed`, `IncidentReturnedForRevision`, `IncidentAssigned`) each with one listener dispatching one Laravel Notification. The notification bell in `AuthenticatedLayout.vue` now shows a real unread count (see the §9b exception note) and links to `/notifications` (list + mark-as-read, paginated).
- **Escalation:** `incidents:check-overdue` (scheduled daily in `Kernel.php`) sweeps for submitted-but-unreviewed incidents past `review_sla_hours` and assigned-but-open incidents past `target_closure_date`, notifying `config('incident_workflow.escalation_recipient_roles')`. Uses **two** one-shot flags — `review_escalated_at` and `assignment_escalated_at` — not one shared column; a single flag was the original plan's design and was wrong (see below).
- **Fixed during review, not by original plan/implementer error:**
  - *Escalation flag design flaw* (found in Task 9 code review): a single shared `escalated_at` column meant an incident escalated at the review stage could never later be escalated at the assignment stage, and a returned-then-resubmitted incident could never be re-escalated at review (nothing cleared the flag). Reworked into the two separate columns above, with resets added to `submit()` and `assignInvestigator()`.
  - *App-wide UTC timezone bug*: `config/app.php` had hardcoded `'timezone' => 'UTC'` since Phase 1, unnoticed until Phase 4's SLA-hour math made it visible. Fixed to `env('APP_TIMEZONE', 'Asia/Manila')` (`.env`/`.env.example` updated to match) — affects every `now()`/Carbon call app-wide, not just this phase's code.
  - *Audit trail same-second ordering* (§ above) — found during this phase's final Playwright verification pass itself, after the plan's own tasks and reviews had all passed.
- **Deferred, unchanged from the plan:** real email delivery, `investigations`/`corrective_actions`/`approvals` tables and their `Show.vue` tabs (still placeholders), sidebar nav badge counts, an admin-wide audit log browser (only the per-incident view exists).

## 9f. Phase 5 complete — Investigation (RCA workbench)

Implemented per `docs/superpowers/plans/2026-09-18-investigation.md` (12 tasks), via subagent-driven development with spec-compliance + code-quality review per task, plus a final holistic cross-task review (96 PHPUnit tests passing, `npm run build` clean). **Browser/Playwright verification could not be performed in this session** — no headless-browser driver (`chromium-cli` or equivalent) was available in this environment, unlike Phases 2–4 where it was. This is a real gap, not a formality: visually confirm the RCA workbench (methodology toggle absence, findings chain rendering, team staffing, completion flow) in a browser before treating this phase as fully done, the same way earlier phases caught Inertia-protocol and audit-ordering bugs that no automated check would have surfaced.

- **A mid-phase architecture pivot, scoped to this phase only.** After Tasks 1–3 (migrations, enums, models) and an initial array-based `InvestigationService` were already committed, the user explicitly requested a fuller layered architecture — DTOs (`app/DataTransferObjects/Investigations/`), Repositories (`app/Repositories/`, one per model — `InvestigationRepository`/`InvestigationTeamMemberRepository`/`InvestigationFindingRepository`), a Query object (`app/Queries/OverdueInvestigationsQuery.php`), Actions (`app/Actions/Investigations/`, one invokable class per use case, thin — they call into the Service, the Service isn't replaced by them), and Resources (`app/Http/Resources/`, doubling as "Api Resources" per the user's own clarification — one JsonResource layer, not two). **Phases 1–4 were deliberately NOT retrofitted** — `IncidentService`/`IncidentController`/`IncidentWorkflowController` keep their original Service+Policy+FormRequest shape. The codebase now has two coexisting architectural styles by area; this is intentional, not drift. Ask at the start of Phase 6 whether the layered pattern should extend there too — don't assume either way.
- **Lifecycle:** `InvestigationService::start()` (assigned → under_investigation), `addTeamMember()`/`removeTeamMember()`, `addFinding()`/`updateFinding()`/`deleteFinding()`, `complete()` (under_investigation → corrective_action). `start()` seeds the lead investigator as a team member automatically (deduplicated against any explicit team list). `addFinding()` auto-assigns `sequence` server-side, but only for `five_whys` methodology (never client-settable — the ordering-bug lesson from Phase 4's audit trail applied proactively here); `deleteFinding()` renumbers remaining `five_whys` findings contiguously so the UI's numbered chain never shows a gap.
- **Audit trail:** two new explicit `AuditLog::record()` actions (`investigation_started`, `investigation_completed`, plus `finding_added` per finding) — written by the Service, not the Phase 4 `IncidentObserver` (which only fires on `Incident` attribute changes, not `Investigation` row changes). All render correctly ordered on the existing per-incident timeline with no new wiring needed, since that ordering fix was already action-agnostic.
- **Authorization:** `IncidentPolicy::start()` (gated on the incident, alongside `review`/`assign`, since the ability's subject is an `Incident`) plus a new `InvestigationPolicy` (`manageTeam`/`recordFindings`/`complete`, gated on the `Investigation` instance — Laravel resolves a policy by the class of the object passed to `can()`, so an ability whose subject is an `Investigation` cannot live on `IncidentPolicy`). `InvestigationController`'s nested child-record routes (`removeTeamMember`, `updateFinding`, `deleteFinding`) carry explicit `abort_unless($child->investigation_id === $investigation->id, 404)` ownership guards — Laravel's implicit route-model-binding resolves nested route params independently, so without these a valid investigation id plus a different investigation's child-record id would still both resolve. Verified load-bearing during review by temporarily removing each guard and confirming the corresponding IDOR test fails, then restoring it.
- **Escalation:** a third sweep, `CheckOverdueIncidents::escalateOverdueInvestigations()`, added alongside Phase 4's two (review SLA, assignment SLA) — reuses the existing `IncidentEscalationNotification`, no new Event/Listener/Notification classes (escalation in this app is a direct sweep-and-notify, not event-driven). Backed by a new `investigations.escalated_at` column, one-shot per investigation, mirroring Phase 4's `review_escalated_at`/`assignment_escalated_at` pattern (a per-stage flag, not one shared column — that was the exact design flaw Phase 4 had to fix).
- **Fixed during review, not by original plan/implementer error:**
  - *Stale inherited SLA deadline* (found in the phase's final holistic review, after every individual task had already passed its own review — same pattern as Phase 4's audit-ordering bug): `start()` originally defaulted a new investigation's `target_completion_at` to the incident's `target_closure_date`, a value set once at assignment time and never advanced. An investigator starting late (after the assignment SLA had already lapsed) got a brand-new investigation that was already "overdue" the instant it was created, triggering a false escalation on the very next sweep. Fixed to compute the default from `now()->addHours(investigation_sla_hours)` — the same pattern `assignInvestigator()` already uses for `target_closure_date` — so the deadline always counts from when the investigation actually starts. Also added `after:today` validation on an explicitly-supplied `target_completion_at`, closing the same hole reachable directly from the UI.
  - *Eloquent relation-caching leak* (found during Task 10's own review): `$incident->investigation?->load([...])` was assumed sufficient to keep the investigation data out of the raw `incident` Inertia prop (which should only carry it via the new Resource-shaped top-level `investigation` prop) — but accessing `$incident->investigation` at all caches the relation on the model regardless of access path, so it re-serialized raw and duplicated (unfiltered, with nested user data) inside `incident` too. Fixed with an explicit `$incident->unsetRelation('investigation')` right after reading it, locked in by a `->missing('incident.investigation')` test assertion.
  - *Laravel-9-incompatible Resource signature* (found during Task 10 implementation): the plan's reference code typed `toArray(Request $request): array`, which is a fatal "declaration incompatible" error against this project's actual Laravel 9.52.22 base `JsonResource::toArray($request)` (untyped). All three new Resources use the untyped signature instead.
- **Deferred, unchanged from the plan:** CAPA (`corrective_actions` table, CAPA tab), Approvals/Closure (`approvals` table, Approvals tab), analytics/"Learn" dashboards. Repository *interfaces* — the user asked for Repository classes, not swappable implementations behind contracts, and there's one persistence backend, so an interface layer would be pure ceremony.

## 9g. Phase 6 complete — CAPA (Corrective & Preventive Actions)

Implemented per `docs/superpowers/plans/2026-09-18-capa.md` (13 tasks), via subagent-driven development with spec-compliance + code-quality review per task, plus a final holistic cross-task review and — closing the gap Phase 5 had to leave open — a real Playwright browser verification (132 PHPUnit tests passing, `npm run build` clean, zero browser console errors end-to-end).

- **Architecture: continues Phase 5's layered pattern**, per explicit user confirmation this phase should not revert to the simpler Phase 1–4 style — now two phases running the layered pattern in a row (DTOs under `app/DataTransferObjects/CorrectiveActions/`, `CorrectiveActionRepository`, `OverdueCorrectiveActionsQuery`, `CorrectiveActionService` holding all business logic, thin invokable Actions under `app/Actions/CorrectiveActions/`, `CorrectiveActionPolicy`, `CorrectiveActionResource`).
- **Lifecycle:** `CorrectiveActionService::create()` (race-safe `CAPA-{year}-{000}` numbering, mirroring `IncidentService`'s incident-number pattern, retried on unique-constraint collision), `update()`, `markInProgress()` (`open → in_progress`), `complete()` (`open`/`in_progress → for_verification` in one step — the schema's `completed` status value is kept for fidelity but deliberately never written, same treatment Phase 5 gave `InvestigationStatus::NotStarted`), `verify()` (`for_verification → verified`).
- **Automatic incident-status rollup** (the phase's riskiest piece of new logic): once every CAPA on an incident reaches `for_verification` or later, the incident advances `corrective_action → for_verification`; once every CAPA reaches `verified`, the incident advances `for_verification → verified`. This is load-bearing, not cosmetic — nothing else in the app moves an incident past `corrective_action`. Guarded on the incident's *current* status so it only fires from the expected starting point, and self-corrects around out-of-order creation: a CAPA added after the existing set has already reached `for_verification` does not get the incident stuck, because `create()` never touches incident status and `complete()`/`verify()` always re-check the full current set. Confirmed both by reasoning and by a dedicated regression test added during the holistic review (see below).
- **Never-self-verification**, enforced by checking `completed_by` specifically (not just role) in `CorrectiveActionPolicy::verify()` — a QSO/Administrator who completed a CAPA on someone else's behalf still cannot verify their own completion; a *different* Supervisor/DepartmentHead/QSO/Administrator can.
- **Authorization:** `CorrectiveActionPolicy` — `create()` is QSO/Administrator-only and gated on `Incident.status === CorrectiveAction`, invoked as `$user->can('create', [CorrectiveAction::class, $incident])` (class-string + extra context arg, Laravel's documented pattern for creation abilities); `update()` locked out once a CAPA reaches `for_verification`/`verified`; `progress()`/`complete()` open to the responsible person or QSO/Administrator; `verify()` open to Supervisor/DepartmentHead/QSO/Administrator, minus the completer.
- **Escalation:** a fourth sweep, `CheckOverdueIncidents::escalateOverdueCorrectiveActions()`, added alongside Phase 4/5's three — same one-shot `escalated_at` pattern, same reused `IncidentEscalationNotification`, no new Event/Listener classes.
- **UI:** `CapaPanel.vue` (mounted in `Show.vue`'s `capa` tab) — CAPA cards with per-item `can` flags from `CorrectiveActionResource` driving which of Start Work / Mark Complete / Verify Action / Edit render per viewer per card, since different CAPAs on the same incident can have different responsible people and statuses. Deliberately has no `ConfirmationDialog` — Mark Complete and Verify Action already require typing notes into an inline panel before a second Submit click, which is its own two-step confirmation.
- **Holistic review findings (all PASS, no bugs found — a first for this project's phase-review history):**
  - Incident-status rollup traced exhaustively including the 3-CAPA out-of-order-creation scenario described above; confirmed correct by a new test, `test_a_third_capa_created_after_the_first_two_reach_for_verification_does_not_get_stuck`.
  - `create()`'s `[CorrectiveAction::class, $incident]` policy-dispatch convention confirmed to hit the real `CorrectiveActionPolicy::create()` method (not a Gate fallback) at both the unit level and the HTTP `FormRequest::authorize()` level — already proven by existing role/status-varying test cases, not just reasoning.
  - `root_cause_finding_id` cross-incident leakage checked: `Rule::exists('investigation_findings', 'id')->where('investigation_id', ...)` in both Create/Update requests correctly rejects a finding ID belonging to a different incident's investigation — previously untested; added `test_root_cause_finding_id_cannot_reference_a_different_incidents_investigation` to close the gap.
  - Audit trail ordering (`->latest()->latest('id')`, the Phase 4 fix) confirmed still action-agnostic and correctly interleaves this phase's `action_created`/`action_completed`/`action_verified` entries with pre-existing incident/investigation entries.
- **Browser verification (closes Phase 5's open item too):** a headless Chromium (Playwright) was available this session, unlike Phase 5. Drove the full flow end-to-end against `php artisan serve`: logged in as the lead investigator to visually confirm the RCA workbench (methodology, findings chain, team) — the exact check Phase 5 had to leave open — then as Administrator created two CAPAs, as each responsible person progressed and completed their own item, and as Administrator (having completed neither) verified both. Incident status badge correctly ended at "Verified"; zero browser console errors throughout. Both Phase 5 and Phase 6 are now browser-verified.
- **Deferred, unchanged from the plan:** Approvals/Closure (`approvals` table, Approvals tab — Phase 7), analytics/"Learn" dashboards (Phase 8), the cross-incident CAPA queue page (`CorrectiveActions/Index.vue`), `CorrectiveActionOverdue`/`VerificationRequired` as dedicated domain Events (escalation stays sweep-based), Repository interfaces.

## 9h. Phase 7 complete — Approvals & Closure

Implemented per `docs/superpowers/plans/2026-09-18-approvals.md` (13 tasks), via subagent-driven development with spec-compliance + code-quality review per task, a final holistic cross-task review, and a real Playwright browser verification of both closure paths (161 PHPUnit tests passing, `npm run build` clean, zero real browser console errors).

- **Architecture: continues Phases 5 & 6's layered pattern**, per explicit user confirmation at the start of this phase — third phase area running it in a row (`App\Enums\ApprovalStatus`, `App\Models\Approval`, DTOs under `app/DataTransferObjects/Approvals/`, `ApprovalRepository`, `OverdueApprovalsQuery`, `ApprovalService` holding all business logic, thin invokable Actions under `app/Actions/Approvals/`, `ApprovalResource`). **One deliberate exception to "one policy per model":** `requestApproval`/`markNoCorrectiveActionNeeded`/`approveClosure`/`returnFromApproval` all live on the existing `IncidentPolicy`, not a new `ApprovalPolicy` — they gate on the *incident's* status and role first, the same way `IncidentPolicy::start()` (which kicks off an Investigation) already lives outside `InvestigationPolicy`.
- **Closes a real gap left open since Phase 1's original design.** Architecture originally assumed low-severity incidents could skip investigation/CAPA entirely and close directly — never built in Phases 3–6, so until this phase an incident could only ever reach `Verified` (and thus be closeable) by going through at least one verified CAPA. This phase adds a narrow, well-scoped fix: once an incident's investigation is complete (`status === CorrectiveAction`) with **zero corrective actions ever created on it**, a QSO/Administrator can mark it as needing no corrective action (with a required written justification) and send it straight into the approval queue. The broader "skip investigation/CAPA per severity, config-driven" feature stays deferred — this only closes the specific dead-end, not the whole aspirational design.
- **Lifecycle:** `ApprovalService::requestApproval()` (from `Verified`) and `markNoCorrectiveActionNeeded()` (from `CorrectiveAction` with zero CAPAs) both funnel into one private `createPendingApproval()` — the DB operations are identical either way; only the human-readable audit description differs. `approve()` closes the incident in one step (`closed_by`/`closed_at` set), mirroring CAPA's `complete()` going straight to `for_verification` rather than a separate resting state. `returnForRevision()` sends the incident back to `CorrectiveAction` regardless of which path led to `ForApproval`. A returned incident's next request produces a **new** `Approval` row rather than reusing the old one — the incident's approval history is a list of decision records, not a single mutable one.
- **Requester/approver role split with a never-self-decide guard.** QSO/Administrator can request; Department Head (own department only, same scoping as `hasReviewOrAssignAccess()`), Management, or Administrator can approve/return — but never the specific user who made *that* request, even if their role would otherwise qualify (an Administrator can both request and ordinarily approve).
- **A real authorization gap found and fixed within the phase, not before shipping.** The first pass of `approveClosure`/`returnFromApproval` only checked the *incident's* status (`ForApproval`) — never which specific `Approval` row was being decided. Since a resubmission cycle (`Returned → CorrectiveAction → Verified → ForApproval`) produces a second `Approval` row while the first, already-`Returned` row still exists, the old row stayed permanently "decidable" by anyone eligible to decide the new one — reachable both through the per-item Resource `can` flags and directly via `POST /approvals/{old-id}/approve`. Caught by Task 10's code-quality review, not by an implementer. Fixed by having both abilities take the `Approval` row alongside the `Incident` (`$user->can('approveClosure', [$incident, $approval])`, the same context-array idiom `CorrectiveActionPolicy::create()` uses), requiring `$approval->status === Pending`, and checking `$approval->requested_by` directly instead of re-querying "the" pending approval. Locked in by a regression test reproducing the exact two-row scenario, and re-verified live in the browser (the stale row visibly renders with no action buttons while the current row does).
- **Escalation:** a fifth sweep, `CheckOverdueIncidents::escalateOverdueApprovals()`, added alongside Phase 4–6's four — same one-shot `escalated_at` pattern, same reused `IncidentEscalationNotification`, no new Event/Listener classes (consistent with Phases 5 & 6, not Phase 4's event-driven notifications). A new `approval_sla_hours` config entry mirrors `review_sla_hours`'s per-severity values, and `due_at` is computed once at request time (mirroring `Investigation.target_completion_at`/`CorrectiveAction.due_date`) rather than recomputed.
- **Schema deviations from `docs/architecture.md` §2.4's original design**, each documented in the migration: `decision_comments` instead of a bare `comments` (a second free-text column next to `request_comments` on the same row would have been ambiguous about which actor wrote it — caught in Task 1's own code-quality review); `due_at`/`escalated_at` additions for the same reasons Phases 5–6 added their own SLA/escalation columns.
- **UI:** `ApprovalPanel.vue` (mounted in `Show.vue`'s `approvals` tab) — page-level "Request Approval"/"No Corrective Action Needed" buttons gated by page-level `can` flags, plus a history list of approval cards each with their own per-item `can.approve`/`can.return` flags. No `ConfirmationDialog`, same rationale as `CapaPanel.vue` — typing required comments into an inline panel before a second Submit click is already a two-step confirmation.
- **Holistic review findings:** the row-scoping fix above (re-confirmed solid); `markNoCorrectiveActionNeeded` re-entrancy after a return (previously untested, confirmed correct, now covered by `test_marking_no_corrective_action_needed_can_be_used_again_after_a_return_with_still_zero_capas`); `due_at`'s per-severity config lookup (previously only exercised for one of four `Severity` cases, now covered by `test_due_at_uses_the_sla_hours_configured_for_the_incidents_own_severity` across all four); audit trail ordering confirmed still action-agnostic. No new bugs found beyond the one already fixed mid-phase.
- **Browser verification:** drove two fresh incidents end-to-end against `php artisan serve` — one through the ordinary CAPA-verified path to a QSO's "Request Approval" and Management's "Approve & Close" (ending `Closed`), and one through "No Corrective Action Needed" → a Management "Return for Revision" → a second "No Corrective Action Needed" resubmission → "Approve & Close" (also ending `Closed`), visually confirming the full history renders correctly and the stale-row fix holds live, not just in tests. Zero real console errors (one expected 403 appeared when deliberately testing a Department Head outside the incident's department, confirming the pre-existing department-scoped `IncidentPolicy::view()` still works, not a Phase 7 defect).
- **Forward-looking notes raised during review, deliberately not acted on mid-phase:** the incident-setup test helper (`incidentThroughInvestigation()`/`incidentReadyForApproval()`) is now duplicated a 4th time across `ApprovalTest`/`CorrectiveActionTest`/`CorrectiveActionEscalationTest` — worth a shared trait before a 5th copy appears; `CheckOverdueIncidents` now has 5 sweeps and 6 constructor dependencies, still readable but the natural point to consider a small "escalator" abstraction if a 6th is ever added; `InvestigationResource`/`CorrectiveActionResource`'s `public static $wrap = null;` comments are misleading — `AppServiceProvider::boot()` already calls `JsonResource::withoutWrapping()` globally, making those declarations redundant (harmless, just inaccurate documentation); `IncidentController::show()` now assembles props/can-flags for four concerns (Overview/Investigation/CorrectiveAction/Approval) and is at the edge of unwieldy — a builder/DTO would help if a fifth concern arrives, but per the standing instruction, ask before extending the layered pattern to a new kind of class rather than doing it unprompted.
- **Deferred, unchanged from the plan:** the broader config-driven "skip investigation/CAPA per severity" feature (see above), analytics/"Learn" dashboards (Phase 8), a cross-incident Approvals queue page, new domain Events for approval-stage notifications, an `ApprovalPolicy` class.

## 9i. Phase 8 complete — Analytics & Organizational Learning

Implemented per `docs/superpowers/plans/2026-09-18-analytics.md` (9 tasks), via subagent-driven development with spec-compliance + code-quality review per task, a final holistic cross-task review, and a real Playwright browser verification of both the hospital-wide and department-scoped views (182 PHPUnit tests passing, `npm run build` clean, zero real browser console errors).

- **Architecture: a deliberately leaner slice of the layered pattern**, confirmed with the user before writing the plan. This phase is 100% read-only — no migration, no new Eloquent model, no DTOs, no Repository, no Actions, since those layers exist specifically for writes and there are none here. What applies: one `AnalyticsService` (all aggregation logic), the existing `IncidentPolicy` (one new `viewAnalytics()` ability — no new Policy class, since the ability doesn't gate on a specific model instance), and a thin `AnalyticsController`. No `Resource` either — the Service returns plain arrays (there's no Eloquent model instance to shape), passed directly as Inertia props.
- **Scope was deliberately narrowed from the Stitch mockup, not expanded to match it.** The mockup's "Learn" dashboard includes an "AI Heuristics" pattern-clustering panel with invented confidence percentages, DOH/Philhealth regulatory compliance-certification badges, a live "428 admissions evaluated" hospital census, and an "Export DOH CQI Report" button — none derivable from this system's actual data (no ML pipeline, no admissions/census table, no externally-issued certification). All dropped, not approximated with placeholders, since rendering them would misrepresent the app's real capabilities. What shipped instead, all backed by real queries: five KPI stat tiles (mean time to review/investigate, CAPA adoption rate, sentinel recurrence, near-miss velocity), a root-cause distribution (horizontal stacked bar — the `dataviz` skill's documented default for part-to-whole data, not the mockup's donut), a per-department "Safety Index" & CAPA-compliance table, an incident-volume-by-hour-of-day chart split by shift, and a plain "Recurring Pattern Alerts" list (grouped by department + incident type, no invented confidence score, explicitly not labeled "AI").
- **Access:** QSO/Administrator/Management see hospital-wide data; Supervisor/DepartmentHead see only their own department's data, reusing `Incident::scopeVisibleTo()` exactly as `IncidentController::index()` already does (plus an explicit `status !== Draft` exclusion `visibleTo()` doesn't apply on its own, matching that same existing convention); Staff/Investigator get a 403. One sidebar item, "Executive Overview", is wired to `/analytics`; the other three "Analytics & Learning" items stay `href: '#'`, deferred like every other still-unbuilt sidebar destination in this app.
- **"Safety Index" and "recurring pattern" are this project's own invented, documented heuristics, not validated instruments** — Safety Index is `100 - (% of the department's ever-created CorrectiveActions/Approvals currently overdue)`, thresholded into Exemplary/Optimal/Compliant/Needs Attention; a recurring pattern is "≥3 incidents, same department + incident type, within 90 days." Both are flagged in code comments for the same reason Phase 1's config-file decisions were — reasonable defaults, worth confirming with a real patient-safety officer before anyone treats the exact numbers as authoritative.
- **Chart implementation:** hand-built inline SVG/HTML per the `dataviz` skill's guidance, no new npm chart library. The skill's validated default categorical palette is used unmodified (no re-validation needed) and factored into one shared `resources/js/Utils/chartPalette.js` module — added during review specifically so the hourly-volume chart's day/night colors wouldn't become a third hardcoded copy of the same hex values already used by the root-cause chart.
- **Real bugs and gaps caught and fixed during review, all within the phase, none shipped unfixed:**
  - *Sub-hour truncation bias*: `meanDaysToInvestigate()` originally used `diffInHours()->/24`, which floors to a whole hour before dividing, systematically undercounting any investigation whose span wasn't an exact multiple of 24 hours. Fixed to `diffInMinutes()->/60/24`, matching how `meanHoursToReview()` already avoided the same class of bug.
  - *N+1 query growth*: `departmentSafety()`'s first draft ran 6 queries per department in a loop, unbounded by any time window — would scale linearly with department count. Rewritten to fetch everything in a small constant number of queries and aggregate in PHP via each model's own `isOverdue()`, deliberately not a raw SQL `CASE`/`NOW()` aggregation (this project's tests run on SQLite, production runs MySQL, and `NOW()` isn't portable between them).
  - *Missing visibility-scoping test coverage*: neither `rootCauseDistribution()` nor `departmentSafety()` had a test proving their documented "safe because departmentIds was already scoped upstream" claim held for an actual Supervisor/DepartmentHead caller — closed with a real multi-department test, plus a further holistic-review test specifically for the null-`department_id` edge case (mirroring the exact bug Phase 3's own holistic review once caught in `scopeVisibleTo()` itself).
  - *Static copy drift risk*: `Analytics/Index.vue` hardcoded "90 days"/"3+" as copy in four places, independent of the `AnalyticsService` constants that actually define those numbers. Closed by surfacing `windowDays`/`recurringPatternWindowDays`/`recurringPatternMinCount` through `overview()` and interpolating them in the Vue copy instead.
  - *Minor UI gaps*: a dropped `<Head>` title tag, a 4-tier backend status collapsed to 3 visual treatments in the department table (gave "Exemplary" its own tier), and a stray `{{ 3 }}` Vue-interpolated literal that should have just been static text.
- **Browser verification:** seeded incidents across two departments (Emergency Medicine, Biomedical Engineering) with a mix of severities, contributing factors, and a same-type recurring cluster, then drove `php artisan serve` end-to-end: as Administrator (hospital-wide), all five sections rendered real, correct numbers, including the recurring-pattern alert and CAPA adoption rate; as the Biomedical DepartmentHead, the same page correctly showed only that one department's data (1 incident, 1/1 CAPA, "Equipment" as the sole root cause, zero recurring patterns, zero leakage of Emergency Medicine's 8 incidents) — a live confirmation of the department-scoping regression test, not just a passing assertion; as Staff, a direct visit to `/analytics` returned 403 as expected, and the sidebar link itself was confirmed still visible (this app has no role-based sidebar filtering anywhere) rather than hidden client-side. Zero real console errors throughout.
- **Deferred, unchanged from the plan:** the other three "Analytics & Learning" sidebar pages (Trends & Sentinels, Unit & Severity Heatmap, Resolution Times); a real ML/clustering pipeline; the broader config-driven "skip investigation/CAPA per severity" feature (still open since Phase 1); sidebar active-link highlighting (doesn't exist anywhere in this app).

## 9j. tdh_user live integration (2026-09-24)

Implemented per `docs/superpowers/specs/2026-09-24-tdh-user-integration-design.md`, superseding the local `users`/`departments` stand-in from Phase 0–2 (§2.1, §9c). Out of scope and left paused behind this: the Department Assessment workflow, the public guest form, Work Queues.

- **Why read-live, not synced.** User decision (2026-09-24): no local copy of tdh_user data is kept, `incident_report` may be wiped at any time (it holds no real incidents yet), and `tdh_user` — shared by ~20 other hospital systems — is never migrated by this app. `users` and `departments` (`App\Models\User`, `App\Models\Department`) bind to `tdh_user.users`/`tdh_user.section` via the `user` connection (`config/database.php`, `USER_*` env vars) and are read fresh on every request; `config/tdh.php` holds the connection name and this system's static `syscode` (`IR`) used to filter `tdh_user.user_priv`.
- **Read-only enforcement is two-layer**, in `App\Models\Concerns\ReadOnlyTdhModel` + `ReadOnlyTdhBuilder`, both throwing `LogicException` (never failing silently):
  - Model layer: `performInsert`/`performUpdate`/`performDeleteOnModel`/`incrementOrDecrement` overrides catch `save`/`create`/`update`/`delete`/`increment`/`decrement` even via `saveQuietly`/`deleteQuietly`/`withoutEvents()`; `saving`/`deleting` event listeners are kept as redundant belt-and-braces.
  - Query-builder layer: `newEloquentBuilder()` returns a `ReadOnlyTdhBuilder` overriding `update`/`delete`/`forceDelete`/`increment`/`decrement`/`upsert`/`truncate`/`touch`/`insert`/`insertOrIgnore`/`insertGetId`/`insertUsing` (the last four are otherwise forwarded straight to the base query builder by Eloquent's `$passthru`, bypassing model hooks), plus a `__call()` deny-list for `updateOrInsert`/`incrementEach`/`decrementEach`/`updateFrom` (no method of their own on `Eloquent\Builder`, not in `$passthru` either, so Eloquent's own `__call()` would otherwise forward them unguarded).
  - The exemption requires **both** `app()->environment('testing')` **and** the connection's driver being `sqlite`, so factories can seed the in-memory test replica but a misconfigured connection can't silently write to a real database.
  - **Gap:** raw `DB::connection('user')->...` query-builder statements are *not* intercepted by any of this. The plan forbids issuing them; the real enforcement boundary in production is that `USER_USERNAME` must be a SELECT-only MySQL account (`.env.example` ships `ir_readonly`). The local dev `.env` currently still uses `root` — fine for a dev box, not a template to copy into production.
- **The `remember_token` hazard.** `tdh_user.users.remember_token` is shared with other systems logging into the same account; rotating or nulling it from this app would break "remember me" elsewhere. `User::getRememberTokenName()` returns `''`, so Laravel's `SessionGuard` never reads or writes it, and the login form never passes a `remember` flag. A hypothetical `remember=true` login would attempt to save the token and simply throw in production (the read-only guard), rather than silently corrupting shared state.
- **The notifications gotcha.** Eloquent's `newRelatedInstance()` on a `MorphMany`/relation copies the *parent* model's connection, so `User::notifications()` would otherwise resolve to `tdh_user.notifications`. `App\Models\DatabaseNotification` overrides `getConnectionName()` to pin notifications to `config('database.default')` (this app's own database) regardless of which model relates to them.
- **User serialization is a whitelist**, not a blacklist: `User::$visible = ['id', 'name', 'role', 'department_id', 'designation_title']`. tdh rows carry `password` (bcrypt hash), `api_token`, `security_pin`, `signature`, `picture` — none of which may ever reach an Inertia prop. A global `tdhColumns` select scope additionally limits which columns are *loaded* to the ones this app needs (skipping the longtext `signature`/`picture` blobs) — but `fresh()`/`refresh()` bypass global scopes and `select *`, so that scope is a memory optimization only; `$visible` is the actual safety boundary. `IncidentController::show()` sends only minimal, purpose-built user lists to the panels that need them (`id, name, label` for investigator/responsible-user pickers; `id, name, role, label` for the investigation-team picker, where `label` is `"{name} — {section}"`, falling back to the designation, then `#id`, because tdh has 100+ duplicate active first+last names), and only to viewers whose `can` flags say they need that list at all — never the full `User` shape, and never to a viewer who doesn't need it.
- **Integer casts, strict comparison.** `users.designation`/`users.section` and the `incident_report` FK columns are cast to `integer` so they compare strictly (`===`) against `$user->id`/`$user->department_id` in policies and scopes, rather than relying on loose `==` across a mix of int/string sources.
- **Tests never touch live tdh_user.** The `user` connection is a *separate* in-memory SQLite database in tests (`phpunit.xml`: `USER_CONNECTION=sqlite`, `USER_DATABASE=:memory:`), built from a replica schema in `tests/Support/TdhTestSchema.php` that mirrors tdh's real column names/types for `users`/`user_priv`/`section`/`designation`. `TdhTestSchema::create()` refuses to run at all unless the `user` connection's driver is `sqlite` and its database is `:memory:` — a cached config or bad `phpunit.xml` can't make it create tables in, or write to, the live database. `UserFactory` writes tdh-shaped columns directly and maps the ergonomic factory attributes call sites already use (`role` → an `IR` `user_priv` row, `department_id` → `section`). `DB_FOREIGN_KEYS=false` in `phpunit.xml` disables **all** FK enforcement in tests, including incident-internal cascades (e.g. investigation-findings → corrective-actions nulling, restrict-on-delete for lead investigators) — verified no existing test depends on that enforcement.
- **The detach migration is one-way.** `database/migrations/2026_09_24_000001_detach_local_users_and_departments.php` drops the FK constraints on every `incident_report` column that used to point at local `users`/`departments` (columns themselves are kept, as plain indexed integers) and drops the `users`, `departments`, `password_resets`, and `personal_access_tokens` tables. Laravel 9 cannot drop foreign keys on SQLite, so the migration skips the FK-drop step entirely under that driver (tests rely on `DB_FOREIGN_KEYS=false` instead, matching production MySQL's post-migration state). `down()` throws — this migration is not meant to be rolled back.
- **Rule: never cross-database `whereHas`/`join` between `incident_report` tables and `users`/`section`** — they live in different physical databases now. Validation that used to be `Rule::exists('users', 'id')` is now `User::activeRule()` (`exists` on `<tdh>.users` with `status = '1'`) and `Department::selectableRule()` (`exists` on `<tdh>.section`, excluding `'-'` placeholders), used across `ValidatesIncidentData`, `AddTeamMemberRequest`, `StartInvestigationRequest`, and the corrective-action create/update requests (`AssignIncidentRequest` additionally requires the IR investigator level). The CAPA update rule also accepts the action's *current* responsible user even if since deactivated, so unrelated edits still save.
- **Run tests only after `php artisan config:clear`.** A cached config ignores `phpunit.xml`'s env overrides, so `RefreshDatabase` would run `migrate:fresh` against the local `incident_report` MySQL database *before* `TdhTestSchema`'s in-memory-SQLite guard gets a chance to refuse. (The guard still protects live `tdh_user`; it cannot protect `incident_report` from that ordering.)
- **Hard-deleted users.** tdh deletes user rows outright and nothing here has an FK to them, so every stored user id may resolve to `null`: Resources return `null` for a missing user, the reporter/investigator notification listeners skip a missing recipient, and the Vue panels use `?.`.
- **Data-quality caveats found in live tdh_user (2026-09-24):** 78 usernames start with a space (53 of them are "twins" of an otherwise identical username). Laravel's `TrimStrings` strips the typed space before the lookup, so the 25 without a twin cannot log in at all and the 53 twins log in as their trimmed twin — a tdh data-cleanup item (hris behaves the same); `status` is `'1'`/`'0'` plus one `'JO'` row, which is treated as inactive (only `'1'` is active); a `TESTING SECTION` (id 106) is a real `section` row and so appears in department dropdowns; 5 users point at nonexistent sections (14, 75), so their `department` is `null`. Name columns contain placeholders (`'-'`, `'.'`, `N/A`, `None`) and leading spaces, which `User::getNameAttribute()` drops, and `title` holds either an honorific (`Dr.`, prefixed) or post-nominals (`MD, FPSGS`, appended after a comma); `scopeOrderByName()` sorts on `TRIM(lname), TRIM(fname)`.
- **Sample accounts** used for browser verification: tdh user **1116** (no IR `user_priv` row → defaults to `Role::Staff`) and user **1** (`Role::DepartmentHead`, once an `IR`/`department_head` row exists in `user_priv` — inserted by hand with user consent, not by a seeder). Both are in section 21 (IMISS). `DevUserSeeder`/`DepartmentSeeder` are deleted; `DatabaseSeeder` now only seeds `IncidentTypeSeeder`/`ContributingFactorSeeder`.
- **Open follow-ups, not acted on:** `IncidentController::show()`'s investigator list filters the full active-user directory (~1600 people) in PHP by `role === Role::Investigator` when no other list on that page already needs the full directory — a minor perf cost, not a correctness issue, worth a targeted query if it's ever measured as slow. (Multiple `IR` rows per user can't happen: the live `UNIQUE KEY (user_id, syscode)` prevents it, and the test replica mirrors that key.)

## 9k. Department Assessment & role-based sidebar (2026-09-25)

Implemented per `docs/superpowers/specs/2026-09-24-department-assessment-design.md`. The reporter form dropped severity/contributing-factors/actions/recommendations (8 steps → 6: Reporter Info, Incident Details, People Involved, Witnesses & Police, Description & Evidence, Review & Submit); `submit()` no longer sets `is_sentinel_event` since severity is unknown at that point. Existing `severity`/`recommendations`/`actions_taken` rules were dropped from `ValidatesIncidentData`, and because `IncidentService::syncChildRecords()` only touches child records whose key is present in the request, the wizard can no longer clobber department-entered actions on an incident that's cycled back to `Draft`.

- **Lifecycle:** `Submitted` is now the **Department Assessment** stage; `ForReview` (already in the enum, previously unused) means the assessment is done and the incident awaits Supervisor/Department Head/QSO/Admin review. `IncidentPolicy::review()` now requires `ForReview` exactly (was `Submitted` or `ForReview`); `WorkflowActionsPanel` only renders at `for_review` (was `submitted`/`for_review`). No new status values, no new migration column for status — see `IncidentStatus` and §3.1.
- **Who can do what, all scoped to the incident's own department (QSO/Admin always allowed), enforced in `IncidentPolicy`:**
  - `assess` (save Immediate Actions + Recommendations while `Submitted`) — any active user whose `department_id` matches the incident's.
  - `completeAssessment` (set severity, **Complete Assessment** → `ForReview`, **Return to Reporter** → `Draft`) — Department Head of that department, or QSO/Admin. `ReturnIncidentRequest::authorize()` was repointed from `review` to `completeAssessment` — "return to reporter" is the same `POST /incidents/{incident}/return` endpoint as before §9k, just re-gated; it's the reviewer's "Return" (see below) that got a *new* route.
  - `changeDepartment` — QSO/Admin only, while `Submitted` (for when the reporter picked the wrong department).
  - `IncidentPolicy::view()` gained a `Submitted`-only branch: any user in the incident's department can open it purely to work the assessment, even though they're not the reporter, the assigned investigator, or a Supervisor/Department Head/QSO/Admin/Management. `Incident::scopeVisibleTo()` grew the matching `orWhere` (status `Submitted` + matching `department_id`) for consistency with `view()`. In practice only Investigator-role users gain list visibility from it (Staff are blocked from `scope=all` by `viewAny`, and "My Reports" shows only their own reports), so a department staff member reaches an incident by direct link until Work Queues add an "awaiting assessment" queue.
  - Reviewer **Return** (`WorkflowActionsPanel`, still gated on `review`) now posts to a new route, `POST /incidents/{incident}/return-to-department` → `IncidentService::returnToDepartment()`, which sends `ForReview` back to `Submitted` instead of to `Draft`. The reporter can no longer be sent an incident to fix directly from review — only the department (via its own "Return to Reporter") can send it back that far. Labels changed accordingly: "Return for Revision" → "Return to Department".
- **New columns on `incidents`** (`assessed_by` unsigned bigint nullable indexed — no FK, points at a `tdh_user` id; `assessed_at` datetime nullable; `assessment_escalated_at` datetime nullable), added forward-only in `2026_09_25_000001_add_assessment_columns_to_incidents_table.php`. `IncidentService::completeAssessment()` sets `assessed_by`/`assessed_at`, derives `is_sentinel_event` from severity (moved here from `submit()`), and clears `review_escalated_at`.
- **Two SLA clocks, not one:** a new fixed `assessment_sla_hours` = 72 (`config/incident_workflow.php`) measures `Submitted` from `reported_at` — fixed because severity, which the existing per-severity `review_sla_hours` table keys on, isn't known yet. `CheckOverdueIncidents::escalateOverdueAssessments()` sweeps `Submitted` incidents past that deadline with `assessment_escalated_at` still null, notifies, and stamps `assessment_escalated_at`. The pre-existing review sweep (`escalateOverdueReviews()`) was retimed to match: it now filters on `ForReview` (was `Submitted`) and counts `review_sla_hours[severity]` from `assessed_at` (was `reported_at`), guarding `$incident->severity?->value` since a return-to-department can theoretically leave severity unset again.
- **`assessment_escalated_at` is reset only in `submit()`**, not in `returnToDepartment()`. A reviewer's "Return to Department" moves `ForReview` → `Submitted` without touching that column, so an incident that was already escalated once during its first assessment pass will never re-escalate on a second pass through assessment — the sweep's `whereNull('assessment_escalated_at')` guard skips it permanently until the *reporter* resubmits from `Draft`. Deliberate or not, it's a real gap worth knowing about, not a fixed timer: worth revisiting if returned-to-department incidents turn out to sit for a long second assessment pass in practice.
- **Three notifications**, all reusing `IncidentReviewers` (a new `App\Support\IncidentReviewers` helper: `for()` = QSO/Admin plus that department's Supervisors/Department Heads; `departmentHeads()` = just that department's Department Heads) rather than duplicating the recipient query per listener: submit (`IncidentSubmittedNotification`, copy now says "awaiting department assessment"), assessment complete (new `IncidentAssessed` event → `NotifyReviewersOfAssessedIncident` → `IncidentReadyForReviewNotification`, same recipient set as submit), and return-to-department (new `IncidentReturnedToDepartment` event → `NotifyDepartmentHeadsOfReturn` → `IncidentReturnedToDepartmentNotification`, sent only to that department's Department Heads).
- **`ActionStatus` only has two members**, `Pending` and `Completed` — there's no `in_progress`/`removed` state for an immediate action; `AssessmentPanel.vue`'s status `<select>` and `SaveAssessmentRequest`'s `Enum(ActionStatus::class)` rule both reflect exactly those two values.
- **`AssessmentPanel.vue`** (new, top of the Overview tab in `Incidents/Show.vue`, ahead of the narrative summary) renders unconditionally regardless of status — it's the one place Immediate Actions/Recommendations/Severity now display, replacing the two blocks `Show.vue` used to render inline. It's editable only while `incident.status === 'submitted' && can.assess`; the severity picker within that is further gated on `can.completeAssessment` (a plain department member sees a read-only `SeverityBadge` even while everything else is editable), and the department `<select>` only appears for `can.changeDepartment`. Outside `Submitted`, or for a viewer without `assess`, the whole panel is read-only, and once `assessed_at` is set it shows "Assessed by {name} on {date}" (`incident.assessor` — the new `assessed_by` belongsTo). `SeverityBadge` was changed to accept a `null` severity (was `required: true`) and render a neutral "Pending assessment" badge, since an incident sitting in `Submitted` genuinely has none yet.
- **`saveAssessment()` and `completeAssessment()` (service) are one call each; the controller composes them for "Complete"**: `IncidentWorkflowController::completeAssessment()` calls `saveAssessment()` then `completeAssessment()` in sequence, so clicking "Complete Assessment" also saves whatever's currently in the actions/recommendations/severity fields rather than requiring a separate "Save" first. `SaveAssessmentRequest::assessmentData()` strips `severity` unless the caller passes `completeAssessment`, and strips `department_id` unless the caller passes `changeDepartment` — the same validated payload is filtered per-ability rather than having three separate request shapes.
- **Sidebar (`AuthenticatedLayout.vue`) now filters both groups and items** on a new `auth.can` object shared by `HandleInertiaRequests` (`viewAllIncidents` from `IncidentPolicy::viewAny`, `investigationWorkspace`/`capaOperations` from a role allow-list, `viewAnalytics` from `IncidentPolicy::viewAnalytics`, `administration` for `Administrator` only). An item or group with no `can` key is always shown (e.g. Dashboard, My Reports, Draft Reports); a group whose items are all filtered out disappears entirely rather than showing an empty header. This is purely cosmetic client-side hiding — every one of these abilities is (and was already) enforced server-side by the same policies/route middleware; hiding the menu item changes nothing about what a direct visit to the URL would do.
- **Not done / explicitly deferred**, per the spec's own out-of-scope list: public guest reporting, Work Queues, any change to investigation/CAPA/approval stages. Existing contributing-factor links and reporter-entered actions/recommendations on incidents created before this change still display (nothing was backfilled or hidden) since `IncidentService::syncChildRecords()` leaves child records alone when the wizard no longer submits that key.

## 9l. Public guest reporting (2026-09-25)

Patients, relatives and visitors (no tdh account) can report an incident at **`/report`** — public, no login; the login page links to it ("Not hospital staff? Report an incident"). Spec: `docs/superpowers/specs/2026-09-25-guest-reporting-design.md`.

- **Form (one page):** name, contact (phone or email), relationship (`patient`/`relative`/`visitor`/`other`), incident type, department (optional — "I don't know"), date/time (not in the future), location, description, truthfulness declaration. **No attachments.**
- **Flow:** `GuestReportController::store()` → `IncidentService::submitGuestReport()` creates the incident with `reporter_id = null` + `guest_name`/`guest_contact`/`guest_relationship`, then reuses `submit()` (incident number, `reported_at`, `IncidentSubmitted`). It lands in Department Assessment (`Submitted`) like any report. With no department chosen, `IncidentReviewers::for()` notifies only QSO/Admin, who set the department during assessment.
- **Thank-you page** `/report/submitted` shows the incident number (passed via a one-time session flash; without it the page redirects back to `/report`). No status-check page.
- **Abuse controls:** `throttle:3,60` on `POST /report` (per IP for guests; counts every POST), plus a hidden honeypot field `website` validated `prohibited`. No CAPTCHA/external service.
- **Guest reports can't be returned to a reporter** (no account to edit a draft): `IncidentPolicy::returnToReporter()` = `reporter_id !== null && completeAssessment()`; used by `ReturnIncidentRequest` and the AssessmentPanel. The incident page shows "{guest_name} (Guest · relationship · contact)" in the reporter slot; contact details are visible to anyone who can already view the incident.
- **Schema:** `incidents.reporter_id` made nullable via `->change()`, which in Laravel 9 needs `doctrine/dbal` (added as ^3, resolved 3.6.7). Installing it required pinning `carbonphp/carbon-doctrine-types:^1.0` (Carbon's shim; 3.x conflicts with dbal 3). `laravel/framework` unchanged.

## 9m. Work Queues (2026-09-25)

The sidebar's Incident Management, Investigation Workspace and CAPA Operations items are now real, visibility-scoped list pages with badge counts and active-link highlighting (supersedes the "sidebar badge counts unwired" notes in §9b/§9e). Spec: `docs/superpowers/specs/2026-09-25-work-queues-design.md`. Queues are **status views** (what you may see at that stage), not action inboxes.

- **Registries (one definition per queue):** `app/Queries/IncidentQueueQuery.php` (`/incidents?scope=…`) and `app/Queries/CorrectiveActionQueueQuery.php` (`/corrective-actions?queue=…`), each with `QUEUES` (title, description, has-badge), `exists()`, `allowed()`, `builder()`. The pages, the shared `queueCounts` badges (`HandleInertiaRequests`, lazy, zero counts omitted) and the `auth.can` sidebar flags all use them, so a badge always equals its page's total and a visible menu always opens.
- **Incident queues** (all non-draft, `visibleTo($user)`): `awaiting-assessment` (Submitted — open to everyone, so department staff can find assessments), `pending-review` (ForReview), `under-investigation`, `corrective-actions` (CorrectiveAction + ForVerification), `resolved` (Verified + ForApproval + Closed) — these four need `viewAny`; `investigation-queue` (Reviewed + Assigned), `assigned-to-me`, `investigation-history` (investigation completed) — Investigator/Supervisor/Department Head/QSO/Admin.
- **CAPA queues:** `open` (Open + InProgress), `for-verification`, `overdue` (same `CorrectiveAction::overdue()` scope as escalation), `completed` (Verified). Rows = CAPAs whose incident is visible to the user **or** that they're responsible for. Access (`capaOperations` flag) = Supervisor/Department Head/QSO/Admin **or** responsible for at least one CAPA.
- **Access fix:** `IncidentPolicy::view()` and `Incident::scopeVisibleTo()` now also admit investigation team members and CAPA responsible users (both could act on the incident but got 403). In `scopeVisibleTo` these are `id IN (subquery)` clauses on incident_report tables — never a join to tdh_user.
- **Badges** on Awaiting Assessment, Pending Review, Under Investigation, Corrective Actions, Investigation Queue, Assigned to Me, Open Actions, For Verification, Overdue (red); none on Resolved, History, Completed Archive (they only grow).
- **Active highlighting:** `AuthenticatedLayout::isActive()` compares path + `scope`/`queue` (`/incidents` without a scope = My Reports); `#` placeholders (Analytics sub-pages, Administration) are never active.
- **Cost:** up to ~12 `COUNT` queries per full page load for QSO/Admin — fine at hospital scale; revisit (cache or partial reloads) only if it shows up.

## 9n. Follow-ups (2026-09-25)

- **Flash banner:** `Components/FlashBanner.vue` (mounted in `AuthenticatedLayout`) shows the shared `flash.success` / `flash.error` after every visit — top-right, auto-hides after 5 s, dismissible. Before this, success messages were set by controllers but never displayed.
- **Analytics "Mean time to review"** now measures the review step only: `assessed_at` → `supervisor_reviewed_at` (incidents without `assessed_at` are excluded). The department assessment is tracked by its own 72 h SLA.
- **Known gap, not changed:** the "Root cause distribution" chart groups by incident contributing factors, which reporters no longer enter (removed from the form per the client). It will stay empty for new incidents unless it is re-based on investigation findings.
- **RCA methodology removed (2026-09-25, user request "don't overcomplicate for the user").** Starting an investigation asks only for an objective, target date and team; every investigation is `InvestigationMethodology::Simple` (`StartInvestigationData` default; the request no longer accepts a methodology). Findings are plain text with a "Mark as root cause" tick (still used to link CAPAs to a root cause). The old enum cases stay only so any older rows still load; the UI no longer shows or offers them.
- **CAPA stage and closure approval (2026-09-25, supersedes the earlier "CAPA ownership" rule): the department runs the CAPA stage; the Quality office / Management come in only at approval.** Create/edit CAPAs and assign each to a staff member: the incident department's **Department Head** only (QSO/Admin can no longer create or edit). Responsible person: must be **active staff of the incident's department** (`User::canBeResponsibleFor()`, shared by the `potentialResponsibleUsers` picker and both CAPA form requests; an edit may keep the CAPA's *current* responsible person even if since deactivated/moved). Mark in progress / complete: the **assigned responsible person only** (no QSO/Admin override). Verify: a **Supervisor or Department Head of the incident's department**, never the completer (QSO/Admin removed). "No corrective action needed" (zero CAPAs, written justification still required) and "Request closure approval" (incident `Verified`): the incident department's **Department Head** (was QSO/Admin). Approve / return closure: **QSO, Management or Administrator**, never the user who requested that Approval row (Department Heads no longer approve, not even for their own department). Rationale: the department that had the incident does and signs off its own fixes, and closure approval is the independent check by people outside that department. QSO/Admin still *see* the CAPA queues (`CorrectiveActionQueueQuery::allowed()`, sidebar `capaOperations`) read-only. No approval-request notifications exist; overdue-approval escalation recipients are unchanged. Implemented in `CorrectiveActionPolicy` and `IncidentPolicy` (`requestApproval`, `markNoCorrectiveActionNeeded`, `hasApprovalAuthority`); the UI buttons follow the existing `can.*` flags.

## 9o. Five severity levels & escalation matrix (2026-10-01)

Spec: `docs/superpowers/specs/2026-10-01-severity-levels-and-escalation-matrix-design.md`. Supersedes the four-level scale in §2.2/§3.3 and the "High-risk alert to Executives/Committee/Leadership after triage" behaviour (the CQI Committee is no longer alerted).

- **Levels** (`App\Enums\Severity`, mirrored by `resources/js/Utils/severities.js` — keep both in sync; `meaning()` is shown wherever a level is picked):

  | Level | Value | Meaning |
  |---|---|---|
  | I Low | `level_1_low` | Near miss / no harm or low-risk event |
  | II Moderate | `level_2_moderate` | Temporary harm or intervention required |
  | III High | `level_3_high` | Significant harm, prolonged hospitalization or high-risk event |
  | IV Critical | `level_4_critical` | Permanent or life-threatening harm |
  | V Sentinel | `level_5_sentinel` | Death or serious permanent harm / other agency-defined sentinel event |

  `isSentinel()` is true only for Level V; `isHighOrAbove()` covers III–V. Per-severity config tables (`review_sla_hours`, `approval_sla_hours`, etc.) have five keys.
- **Data migration** `2026_10_01_000001_split_critical_and_sentinel_severity.php`: existing `level_4_critical_sentinel` rows in `incidents.severity` and `incident_types.default_severity` become `level_5_sentinel` (decision: existing rows are treated as Sentinel). `down()` maps both new levels back.
- **`App\Support\EscalationRecipients`** is the single place for the client's matrix. Callers exclude the acting user themselves.
  - `forSeverity()`: Low none; Moderate = Department Head + CQI; High = Department Head + CQI + Leadership; Critical and Sentinel = CQI + Leadership + Executives (the Department Head is not included). CQI = `QualitySafetyOfficer`, Executives = `Management`; Leadership = users with the `Leadership` role mapped to the incident's department via `leadership_departments`.
  - `forOverdueInvestigation()`: lead investigator + Department Head + CQI.
  - `forOverdueCorrectiveAction()`: responsible person + Department Head + CQI, plus Executives when the CAPA priority is `critical`.
- **Severity alert:** `SendSeverityAlert` (one listener on `IncidentAssessed` and `IncidentReviewed`) sends `SeverityAlertNotification` as soon as severity is set at department assessment, and at CQI triage only when triage changed it (`IncidentReviewed::$previousSeverity`). The actor is excluded, so a Department Head who completes the assessment is not alerted about their own call; the CQI Committee is not a recipient.
- **Daily command** (`incidents:check-overdue`) now runs, in order: effectiveness-check notices; **due-soon reminders** for investigations and CAPAs (`DueSoonInvestigationsQuery`, `DueSoonCorrectiveActionsQuery`; `DeadlineReminderNotification` to the lead investigator / responsible person only, stamped once in `reminder_sent_at`); **overdue escalations** for investigations and CAPAs via `EscalationRecipients` (still one-shot `escalated_at`; these run even if no CQI user exists); then the role-config sweeps. A CAPA due tomorrow gets a reminder, one past its due date gets an escalation - never both in the same run. Completed/for-verification CAPAs aren't reminded (the owner's part is done) but are still escalated when overdue.
- **Migration** `2026_10_01_000002_add_reminder_sent_at_columns.php`: nullable `reminder_sent_at` on `investigations` and `corrective_actions`.
- **Unchanged:** assessment, review, assignment and approval escalations still go to `config('incident_workflow.escalation_recipient_roles')`.
- **Not built:** a sentinel-event pathway (distinct workflow for Level V) — waiting on the client's definition. `is_sentinel_event` is still derived from severity.

## 9p. Sentinel Event Pathway (2026-10-01)

Spec/plan: `docs/superpowers/specs/2026-10-01-sentinel-event-pathway-design.md`, `docs/superpowers/plans/2026-10-01-sentinel-event-pathway.md`.

For incidents rated **Level V – Sentinel** (`is_sentinel_event = true`) the incident page shows a red "Sentinel Event" banner and a **Sentinel Event Pathway** panel (`SentinelPathwayPanel.vue`) at the top of the Overview tab. Its six steps, derived from existing data:

| Step | Done when |
|---|---|
| 1 Immediate patient safety / clinical response | guidance only, no status |
| 2 Records, equipment and evidence preserved | `incidents.evidence_preserved_at` set (who: `evidence_preserved_by`) |
| 3 Designated leadership notified | always done on a sentinel incident - the §9o severity alert goes out when the level is set |
| 4 Formal investigation / RCA assigned | `assigned_investigator_id` set |
| 5 Corrective actions & governance review | status `closed` (closure of High+ needs verified CAPAs and CQI Committee approval) |
| 6 Learning and prevention documented | `lessons_learned` recorded (published at closure) |

Step 2 is the only manual step: `POST /incidents/{incident}/evidence-preserved` (`IncidentWorkflowController::confirmEvidencePreserved` → `IncidentService::confirmEvidencePreserved`, audit action `evidence_preserved`). `IncidentPolicy::confirmEvidencePreserved`: sentinel, not yet confirmed, not closed, user is the **Focal Person (supervisor) or Department Head of the incident's department**. It is **tracking only** - nothing is blocked by it (clinical response comes first). RCA tools stay optional for sentinel events (the formal investigation is already mandatory for High+).
