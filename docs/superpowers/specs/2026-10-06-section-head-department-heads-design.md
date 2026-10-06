# Department Heads from `tdh_user.section.head` — Design

Date: 2026-10-06

## Purpose

The Department/Service Head is no longer an IR privilege level. A user is the
Department Head of every section whose `tdh_user.section.head` is their user id.
The hospital already keeps that column up to date, so no one has to maintain a
second `user_priv` row (decided by the user 2026-10-06).

## Rules

1. **Who is a head:** user U heads section S when `section.head = U.id`. A user
   can head several sections, including sections other than their own
   `users.section` (live data: 60 heads over 101 sections; e.g. user 1260 is in
   section 19 and heads 6 EFM sections).
2. **Scope:** head powers apply to incidents (and recurrence reviews) whose
   `department_id` is a section they head — not to their own `users.section`
   unless they head it too.
3. **Combined with IR levels:** head powers are *added to* whatever IR level the
   user has (Staff, Supervisor, Leadership, CQI Office, …). E.g. user 1 is
   Leadership (mapped departments) and Head of the 5 sections they head.
4. **The `department_head` IR level is retired.** `Role::DepartmentHead` is
   removed; a `user_priv` row with that level is treated as an unknown level
   (Staff + log warning), as today. No such rows exist (user 1 was changed to
   `leadership` on 2026-10-06).
5. **Unchanged:** the Department Safety Focal Person (`supervisor`) stays an IR
   level scoped to the user's own `users.section`; "anyone in the department can
   help assess a Submitted incident" stays based on `users.section`; who can be
   an investigator / CAPA owner (department staff) is unchanged.
6. **Inactive heads:** a head whose account is inactive (`status != '1'`) cannot
   log in and is skipped as a notification recipient, as for every other user.

## What a head can do (unchanged powers, new test for "who")

Everything `Role::DepartmentHead` + same-department could do before, now gated
by "heads the incident's department":

- See incidents and recurrence reviews of headed sections (lists, detail,
  dashboard, analytics, trends — all go through `visibleTo()`).
- Department Assessment: assess, recommend investigator, set severity /
  complete assessment, return to reporter.
- Sentinel pathway: confirm records/equipment/evidence preserved.
- CAPA: create/edit CAPAs, verify (with Focal Person), mark "no corrective
  action needed", request closure.
- Sidebar/queues: incident lists (`viewAny`), analytics (`viewAnalytics`),
  investigation workspace, CAPA operations, badge counts.
- Notifications that used to go to "Department Heads of the incident's
  department" now go to **the** head of that section (one person, if active):
  new-report reviewers, return-to-department, severity alerts (Moderate/High),
  overdue RCA/CAPA escalations.
- Recurrence reviews: the CQI Office may assign a review to the section's head
  or to a Focal Person of that section.

## Design

### `App\Models\User`

```php
/** Sections whose tdh_user.section.head is this user (memoized per instance). */
public function headedDepartmentIds(): array
public function isDepartmentHead(): bool              // headedDepartmentIds() !== []
public function isHeadOf(?int $departmentId): bool   // non-null and in headedDepartmentIds()
```

`headedDepartmentIds()` queries `Department::where('head', $this->id)->pluck('id')`
once per User instance (same memo style as `leadershipDepartmentIds()`).

### Call sites

| Where | Change |
|---|---|
| `IncidentPolicy::viewAny`, `viewAnalytics` | also true when `isDepartmentHead()` |
| `IncidentPolicy::view` | Supervisor: own section (unchanged); head: `isHeadOf(incident dept)`; Leadership unchanged |
| `IncidentPolicy::assess`, `recommendInvestigator`, `completeAssessment`, `confirmEvidencePreserved`, `isHeadOfIncidentDepartment` | `isHeadOf(incident dept)` replaces `role === DepartmentHead && same department`; Supervisor same-department checks stay |
| `CorrectiveActionPolicy` | `isHeadOfIncidentDepartment` → `isHeadOf`; `verify` = Supervisor of the dept OR head of the dept |
| `Incident::scopeVisibleTo`, `RecurrenceReview::scopeVisibleTo` | add `orWhereIn('department_id', headedDepartmentIds())`; Supervisor branch keeps own section; Staff "Submitted in my section" clause unchanged |
| `IncidentQueueQuery::investigationWorkspace`, `CorrectiveActionQueueQuery::allowed`/`builder` | heads count like the old Department Head role |
| `IncidentReviewers::for` | CQI Office + Focal Persons of the section + the section's head |
| `IncidentReviewers::departmentHeads` | the section's head (active) — a 0/1-item collection |
| `StoreRecurrenceReviewRequest::assignees` | the section's head (active) + active Focal Persons of the section |
| `Role` enum | remove `DepartmentHead` |

`EscalationRecipients` needs no change (it already calls `IncidentReviewers::departmentHeads`).

### Tests

- `UserFactory` gets a `headOf(Department|int ...$departments)` state that sets
  `section.head` for those sections after creating the user.
- Every test that used `'role' => Role::DepartmentHead` switches to `headOf(...)`.
  Where a test had two Department Heads of one section, it now has one (a
  section has one head).
- New tests: a Staff-level user who heads a section other than their own can
  assess/complete/request closure there and not in their own section; a
  Leadership user who also heads a section gets both scopes; the head of a
  section receives the department-head notifications and a non-head in the same
  section does not; `department_head` level resolves to Staff.

## Out of scope

- Editing section heads in this app (they stay in `tdh_user`).
- Any change to the Focal Person, Leadership mapping, investigators or CAPA owners.
