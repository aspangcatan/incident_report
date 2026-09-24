# Department Assessment & Role-Based Sidebar — Design

**Date:** 2026-09-24
**Status:** Approved in brainstorming (parts 1–2 + sidebar), pending spec review
**Source:** client process flow (memory `project-client-process-flow`), points 2–5, plus "only show menus available to that user".
**Guiding rule from the user:** keep it simple — implement exactly what's asked, no extra guards.

## 1. What changes, in one paragraph

The reporter no longer sets severity, contributing factors, immediate actions or recommendations. After submission the incident sits in a new **Department Assessment** stage (status `Submitted`) where staff of the incident's department fill in Immediate Actions and Recommendations, and the Department Head sets severity and completes the assessment (→ `For Review`). Review then works exactly as today (Supervisor/Department Head of the department, or QSO/Admin). The sidebar shows only the menus the logged-in user can use.

## 2. Lifecycle

```
Draft ──submit──► Submitted (Dept Assessment) ──complete──► For Review ──review──► Reviewed ──► Assign … (unchanged)
                     │ return to reporter                     │ return to department
                     ▼                                         ▼
                   Draft                                     Submitted
```

- `ForReview` already exists in `App\Enums\IncidentStatus` (currently never written). No new statuses.
- Everything from `Reviewed` onward is unchanged.

## 3. Reporter form (wizard): 8 steps → 6

- **Removed:** severity picker (step 2), Contributing Factors checklist (step 5), step 6 "Actions Taken", step 7 "Recommendations".
- **Kept:** 1 Reporter Info, 2 Incident Details (no severity), 3 People Involved, 4 Witnesses & Police, 5 Description & Evidence (no contributing factors), 6 Review & Submit (no severity row).
- Validation (`ValidatesIncidentData`): drop `severity`, `recommendations`, `actions_taken.*`, `contributing_factor_ids.*` rules. Because `IncidentService::syncChildRecords()` only touches child records whose key is present, the wizard can no longer overwrite department-entered actions (important when an incident is returned to the reporter).
- `submit()` no longer sets `is_sentinel_event` (severity is unknown at that point).
- Existing data is kept: contributing-factor links and reporter-entered actions/recommendations on older incidents still display on the detail page.

## 4. Department Assessment (status `Submitted`)

| Action | Who (all scoped to the incident's department; QSO/Admin always allowed) |
|---|---|
| Save Immediate Actions (add/edit/remove) + Recommendations | any active user whose `department_id` = incident's `department_id` |
| Set severity | Department Head of that department |
| **Complete Assessment** → `ForReview` (requires severity) | Department Head of that department |
| **Return to Reporter** → `Draft`, with comment | Department Head of that department |
| Change the incident's department | QSO/Admin only (needed when the reporter picked the wrong one) |

On complete: set `is_sentinel_event` from severity, `assessed_by`, `assessed_at` (new nullable columns on `incidents`), `review_escalated_at = null`.

Immediate Actions keep the existing `incident_actions` table and fields (description, responsible_name, performed_at, status).

## 5. Review (status `ForReview`) — same people as today

- `IncidentPolicy::review()` now requires status `ForReview` (was `Submitted`/`ForReview`). Who may review is unchanged (Supervisor/Department Head of the department, or QSO/Admin).
- **Mark Reviewed** → `Reviewed` (unchanged).
- **Return** now sends the incident back to the **department** (`ForReview` → `Submitted`, comment kept in the audit trail), not to the reporter — the reporter can't edit actions/severity any more. Returning to the reporter is done from the assessment stage.

## 6. Deadlines & escalation

- New `config/incident_workflow.php` key `assessment_sla_hours` = 72 (fixed — severity is unknown during assessment).
- New sweep in `incidents:check-overdue`: `Submitted` incidents with `reported_at + 72h` in the past and `assessment_escalated_at` null → notify escalation recipients (existing `IncidentEscalatedNotification`-style notification), set `assessment_escalated_at` (new nullable column). Reset on submit.
- Existing review sweep: now looks at `ForReview` incidents and counts `review_sla_hours[severity]` from `assessed_at` (was `Submitted` from `reported_at`).

## 7. Notifications

- On submit: notify the incident department's Department Heads and Supervisors, plus QSO/Admin (the existing `NotifyReviewersOfSubmittedIncident` recipients — unchanged; the message text changes to "awaiting department assessment").
- On assessment complete: notify the same reviewer set that the incident is ready for review (new event `IncidentAssessed` + listener reusing that recipient query).
- On return to department: notify the department's Department Heads (reuse the same recipient query filtered to Department Head).

## 8. Incident page UI

A **Department Assessment** panel at the top of the Overview tab (`Incidents/Show.vue`, new component `Components/Incidents/AssessmentPanel.vue`):

- **While `Submitted`:** editable Immediate Actions table (Add/Remove), Recommendations textarea, Severity picker (editable only for users with the set-severity ability, otherwise read-only), and buttons **Save**, **Complete Assessment**, **Return to Reporter** — each shown only when the user has that ability (`can.*` flags from the controller, like the existing panels).
- **After:** the same panel read-only with "Assessed by {name} on {date}".
- QSO/Admin additionally see a department dropdown while `Submitted`.
- Incidents with no severity yet show a neutral "Pending assessment" badge wherever `SeverityBadge` is used.
- Existing Review buttons in `WorkflowActionsPanel` appear at `ForReview` (the "Return" button label becomes "Return to Department").

## 9. Role-based sidebar

Only menus the user can use are shown. Visibility is decided **server-side** from the same checks the pages use, shared as `auth.can` via `HandleInertiaRequests`, and `AuthenticatedLayout.vue` filters nav items/groups on those flags (a group with no visible items is hidden).

| Flag | Rule (mirrors existing policies/roles) | Sidebar items |
|---|---|---|
| (always) | any logged-in user | Dashboard, New Report, My Reports, Draft Reports |
| `viewAllIncidents` | `IncidentPolicy::viewAny` (not Staff) | All Incidents, Pending Review, Under Investigation, Corrective Actions, Resolved / Closed |
| `investigationWorkspace` | Investigator, Supervisor, Department Head, QSO, Admin | Investigation Workspace group |
| `capaOperations` | Supervisor, Department Head, QSO, Admin | CAPA Operations group |
| `viewAnalytics` | `IncidentPolicy::viewAnalytics` | Analytics & Learning group |
| `administration` | Administrator | Administration & Audit group |

Hiding a menu is cosmetic; server-side authorization is unchanged. Placeholder (`#`) items stay placeholders.

## 10. Data changes (`incident_report`, forward migration only)

- `incidents.assessed_by` (unsigned bigint, nullable, indexed — no FK, users live in tdh_user), `incidents.assessed_at` (datetime nullable), `incidents.assessment_escalated_at` (datetime nullable).
- No data migration needed (no real incidents exist).

## 11. Testing

Feature tests for: wizard no longer accepts/overwrites severity/actions/recommendations/contributing factors; assessment save (dept member allowed, other dept denied, QSO allowed); set severity / complete / return-to-reporter (Dept Head allowed, Supervisor & staff denied); complete requires severity and sets sentinel/assessed fields; review only at `ForReview`, same reviewers as before; reviewer return → `Submitted`; assessment escalation sweep and review sweep timing from `assessed_at`; notifications on submit/complete/return; shared `auth.can` flags per role. Browser check of the wizard (6 steps), the assessment panel, and the sidebar per role.

## 12. Out of scope

Public guest reporting (next sub-project), Work Queues (after that), any change to investigation/CAPA/approval stages.
