# Work Queues — Design

**Date:** 2026-09-25
**Status:** Approved (brainstorming 2026-09-24, parts 1–2), updated for the Department Assessment stage and role-based sidebar built since.
**Guiding rule:** keep it simple.

## 1. Goal

Wire the sidebar's placeholder (`#`) items in Incident Management, Investigation Workspace and CAPA Operations to real, visibility-scoped list pages, with badge counts and active-link highlighting. Analytics sub-pages and Administration stay `#`.

## 2. Queue definitions (status views, not action inboxes)

All incident queues exclude drafts and are filtered by `Incident::visibleTo($user)`.

| Sidebar item | Route | Definition | Who can open (sidebar flag) | Badge |
|---|---|---|---|---|
| **Awaiting Assessment** (new) | `/incidents?scope=awaiting-assessment` | `Submitted` | everyone (list is visibility-filtered; dept staff see their department's) | ✓ |
| Pending Review | `?scope=pending-review` | `ForReview` | `viewAllIncidents` | ✓ |
| Under Investigation | `?scope=under-investigation` | `UnderInvestigation` | `viewAllIncidents` | ✓ |
| Corrective Actions | `?scope=corrective-actions` | `CorrectiveAction`, `ForVerification` | `viewAllIncidents` | ✓ |
| Resolved / Closed | `?scope=resolved` | `Verified`, `ForApproval`, `Closed` | `viewAllIncidents` | — |
| Investigation Queue | `?scope=investigation-queue` | `Reviewed`, `Assigned` | `investigationWorkspace` | ✓ |
| Assigned to Me | `?scope=assigned-to-me` | `assigned_investigator_id = me` and `Assigned`/`UnderInvestigation` | `investigationWorkspace` | ✓ |
| History & Findings | `?scope=investigation-history` | has an investigation with status completed | `investigationWorkspace` | — |
| Open Actions | `/corrective-actions?queue=open` | CAPA `Open`, `InProgress` | `capaOperations` | ✓ |
| For Verification | `?queue=for-verification` | CAPA `ForVerification` | `capaOperations` | ✓ |
| Overdue Actions | `?queue=overdue` | `CorrectiveAction::overdue()` (same as escalation) | `capaOperations` | ✓ (red) |
| Completed Archive | `?queue=completed` | CAPA `Verified` | `capaOperations` | — |

- CAPA row visibility: CAPAs whose incident is `visibleTo($user)` **or** where `responsible_user_id = me`.
- `capaOperations` flag becomes: Supervisor/Department Head/QSO/Admin **or** the user is responsible for at least one CAPA (so responsible staff see the CAPA menus they can open).
- Opening a queue the user has no flag for → 403 (server-side, same rule as the flag).
- Existing scopes `all`, `my-reports`, `drafts` unchanged; unknown scope → `my-reports`.

## 3. Access fixes (approved 2026-09-24)

`IncidentPolicy::view()` and `Incident::scopeVisibleTo()` also grant access when the user is an **investigation team member** of the incident or the **responsible person on any of its CAPAs** — both can already act there but currently get 403.

## 4. One queue registry

- `app/Queries/IncidentQueueQuery.php`: `QUEUES` definitions; `builder(string $queue, User $user): Builder`; `allowed(string $queue, User $user): bool`.
- `app/Queries/CorrectiveActionQueueQuery.php`: same shape for CAPA queues.
- Pages and badges use the same builders, so a badge always equals the page's total.
- `HandleInertiaRequests` shares lazy `queueCounts` (`{ 'awaiting-assessment': 2, overdue: 1, … }`) — only for badge queues the user may open; zero counts omitted.

## 5. UI

- `Incidents/Index.vue`: the All / My Reports / Drafts tab switcher shows only on those three scopes; queue scopes show a title + one-line description instead. Table, pagination and empty state reused (queue-specific empty text).
- New `CorrectiveActions/Index.vue`: paginated table — CAPA number, description (truncated), incident number (links to the incident's CAPA tab), responsible person, priority, due date (red when overdue), status.
- Sidebar: items get real hrefs and a `queue` key; badge = `queueCounts[queue]`; Overdue badge uses the error colour.
- Active highlighting: an item is active when the current path matches and its `scope`/`queue` query value matches (Dashboard and Executive Overview included); active style = primary text + tinted background, like the existing Dashboard item.

## 6. Testing

Per queue: statuses included/excluded; visibility (incl. null-department edge); 403 without the flag; badge count equals page total. Access fixes: team member and CAPA responsible can view; unrelated staff still 403. Sidebar flags: `capaOperations` true for a staff member responsible for a CAPA. Browser check of each wired item, badges and highlighting.
