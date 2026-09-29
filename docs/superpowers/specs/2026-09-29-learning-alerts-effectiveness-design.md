# Effectiveness check, lessons learned and safety alerts — design

Agreed with the client/user on 2026-09-29. Builds on
`2026-09-29-client-roles-and-triage-design.md`.

## Effectiveness check (before closure)

Matches the client flow: Action Monitoring → Effectiveness Verification → Closure.

- When all CAPAs are verified (incident → Verified), `effectiveness_due_at` = now + `incident_workflow.effectiveness_wait_days` (30).
- From that date, the incident's Department Head answers "Did the actions work? Any recurrence?": **Effective** or **Not effective**, with required evidence notes.
- Effective → recorded; the Department Head can now request closure.
- Not effective → back to Corrective Action to add new CAPAs; once those are verified a new 30-day wait starts.
- Closure can only be requested after an Effective result. "No corrective action needed" (zero CAPAs) has nothing to check and is unaffected.
- The daily `incidents:check-overdue` run notifies the Department Head once when the check becomes due.

## Lessons learned

- Requesting closure (either way) requires a **Lessons learned** text from the Department Head.
- The CQI Office can edit it when giving its closure approval.
- When the incident closes, the lesson is published on the **Lessons Learned** page, readable by every logged-in user: incident types, department, severity, closed date and the lesson. No names, no link-through.

## Safety alerts

- The **CQI Office** issues an alert: title, message, urgency (Information / Warning / Critical), audience (all staff or selected departments), optionally linked to an incident.
- Recipients get a bell notification, a banner until they acknowledge, and a sidebar count. They click **I have read this**.
- The CQI Office sees, per alert, who has and hasn't acknowledged.
- The alert list shows each user the alerts addressed to them; the CQI Office sees all.
