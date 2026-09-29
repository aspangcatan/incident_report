# Client roles, CQI triage and two-step closure — design

Agreed with the client/user on 2026-09-29. Supersedes the review/approval roles in
`docs/architecture.md` §9m–§9n where they differ.

## Roles

`tdh_user.user_priv.level` values stay the same so existing rows keep working;
only the labels change, plus two new levels.

| Client role | level | Access |
|---|---|---|
| Reporter | (no row) = `staff` | Report; own reports; assessment actions/recommendations in own department |
| Department Safety Focal Person | `supervisor` | Department incidents; initial review, immediate actions, recommendations, **recommended investigator** |
| Department/Service Head | `department_head` | Department incidents; severity, complete assessment, runs CAPA |
| Patient Safety/CQI Office | `quality_safety_officer` | Everything; **triage** (confirm/change severity), assign investigator or **skip investigation**, change department, RCA/CAPA monitoring, first closure approval |
| Medical/Nursing/Ancillary Leadership | `leadership` (new) | Read-only: incidents of the departments mapped to them (`leadership_departments`); high-risk alerts for them; analytics for them |
| Patient Safety/CQI Committee | `cqi_committee` (new) | Read-only: everything + analytics; **second closure approval for High/Sentinel** |
| Hospital Executive | `management` | Read-only: everything + analytics; High/Sentinel alerts; **no closure approval** |
| IT/System Administrator | `administrator` | Technical only: Leadership ↔ department mapping page. **No incidents, analytics, approvals, CAPA or investigations** beyond what any user has (own reports) |

`investigator` keeps working (can be assigned) but is no longer needed — any department staff can investigate.

## Flow

1. Report (unchanged).
2. Department Assessment (Submitted): staff/Focal Person add immediate actions and recommendations; Focal Person / Department Head may set a **recommended investigator** (department staff); Department Head sets severity and completes.
3. **CQI triage** (For Review, CQI Office only): confirm or change severity, comments → Reviewed. Or return to the department. When the triaged severity is **High or Sentinel**, Executives, the CQI Committee and Leadership mapped to the department are notified.
4. Reviewed (CQI Office only): **assign an investigator** (department's recommendation pre-selected) **or "No investigation needed"** with a required reason — allowed only for Low/Moderate; the incident goes straight to Corrective Action.
5. Investigation → CAPA → verification (unchanged).
6. **Closure**: CQI Office approves (not the requester). For **High/Sentinel** a second, Committee approval is created; the incident closes only when the Committee approves. Either stage can return it to the department.

Out of scope this round: lessons learned, safety alerts, effectiveness checks.
