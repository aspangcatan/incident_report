# Sentinel Event Pathway — Design

Date: 2026-10-01 · Status: approved by the user in conversation

## Why

The client's Sentinel Event Pathway: for events meeting the approved sentinel-event
criteria (here: severity **Level V – Sentinel**, `incidents.is_sentinel_event = true`),
the system activates a dedicated workflow, shows an immediate alert, and notifies
designated leadership and Patient Safety/CQI. Steps:

1. Immediate patient safety / clinical response takes priority over documentation
2. Preserve relevant records, equipment and evidence according to policy
3. Initiate designated leadership notification
4. Assign formal investigation / RCA
5. Track corrective actions and required governance review
6. Document learning and prevention measures

Most steps already exist (alerts from the escalation matrix, mandatory investigation for
High+, CAPA, CQI Committee closure approval, lessons learned at closure). What's missing is
a **visible dedicated pathway** and a record that **evidence was preserved**.

## Decisions (user, 2026-10-01)

- The Dept Head **or** Focal Person (`supervisor`) of the incident's department confirms
  "records, equipment and evidence preserved".
- **Track only** — the confirmation blocks nothing (clinical response comes first).
- RCA tools stay optional for Sentinel (formal investigation is already mandatory).

## Design

**Data:** nullable `incidents.evidence_preserved_at` (timestamp) and
`incidents.evidence_preserved_by` (unsigned big int, indexed, no FK — users live in
`tdh_user`). `Incident::evidencePreservedBy()` BelongsTo User.

**Policy** `IncidentPolicy::confirmEvidencePreserved(User, Incident)`: true when the
incident is a sentinel event, not yet confirmed, not closed, and the user is a
`supervisor` or `department_head` whose department is the incident's department.

**Action:** `POST /incidents/{incident}/evidence-preserved` →
`IncidentWorkflowController::confirmEvidencePreserved` → `IncidentService::confirmEvidencePreserved`
sets both fields and writes an audit log row (action `evidence_preserved`). Redirects back
to the incident with a success flash.

**Incident page** (`Show.vue`), only when `incident.is_sentinel_event`:
- A red banner under the header: "Sentinel Event — Immediate patient safety and clinical
  response takes priority over documentation. Follow the Sentinel Event Pathway below."
- A `SentinelPathwayPanel.vue` at the top of the Overview tab with the six steps, each ✓/○:

| Step | Done when (from existing data) |
|---|---|
| 1 Immediate response | guidance text, no status |
| 2 Evidence preserved | `evidence_preserved_at` set — shows who/when; button "Confirm evidence preserved" when `can.confirmEvidencePreserved` |
| 3 Leadership notified | always ✓ on a sentinel incident (alerts are sent when the level is set) |
| 4 Investigation / RCA assigned | `assigned_investigator` present — shows the name |
| 5 Corrective actions & governance review | ✓ when status is `closed`; otherwise shows the current status label |
| 6 Learning documented | `lessons_learned` filled — "published" once `lessons_published_at` is set |

`IncidentController::show` loads `evidencePreservedBy` and adds `can.confirmEvidencePreserved`.

## Testing

Feature tests: head and focal person of the department can confirm; staff, another
department's head, CQI, a non-sentinel incident, a second confirmation and a closed incident
are refused (403); fields + audit row saved; show page exposes the flag and the
`evidence_preserved_by` relation. Browser check of the banner and panel.
