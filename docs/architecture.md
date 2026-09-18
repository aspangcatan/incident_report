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

## 9b. Phase 3 sidebar wiring

`Report an Incident`, `All Incidents`, `My Reports`, and `Draft Reports` in the sidebar now link to real routes (`/incidents/create`, `/incidents?scope=all|my-reports|drafts`). Every other sidebar item (Investigation Workspace, CAPA Operations, Analytics & Learning, Administration & Audit groups) intentionally still points at `href: '#'` — those modules don't exist until later phases. Nav item counts (e.g. the "142" badge shown next to "All Incidents" in the original Stitch mockup) remain unwired — no per-request count query has been added, since none is justified yet at this data volume; revisit in Phase 8 (Analytics) or if it becomes a real UX complaint.

**Exception, added in Phase 4:** the header's notification bell *does* carry a real per-request count (`HandleInertiaRequests` shares `unreadNotificationsCount` via `$user->unreadNotifications()->count()`, evaluated on effectively every authenticated page load). This is a deliberate, scoped exception to the stance above, not a reversal of it — one indexed lookup (`notifications` table's `morphs('notifiable')` index) backing a live, user-facing feature is a different cost/value trade than N speculative counts across unrelated sidebar items with no established product need. If a second or third such per-request count is ever proposed, that's the point to revisit whether this exception is still holding.

## 9c. Decisions confirmed 2026-09-16

1. **User management is out of scope for this app.** A separate system of record already exists (`tdh_user.users`, covering all hospital and non-hospital staff). No Admin → Users CRUD is being built here. For now this app runs its own local `users` table and hand-rolled auth purely so development can proceed; real integration with `tdh_user` (shared DB connection vs. synced/linked local table) is a deferred decision, not implemented in Phase 1–2.
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
