# Public Guest Incident Reporting — Design

**Date:** 2026-09-25
**Status:** Approved in brainstorming
**Source:** client process flow point 1 (reporters can be outsiders — patients, relatives, visitors with no account). Memory `project-client-process-flow`, sub-project 2.
**Guiding rule:** keep it simple.

## 1. Decisions (user-confirmed)

- Public page, **no login**: `/report`.
- Anti-spam: **rate limit** (3 submissions per hour per IP) + **honeypot** field. No external service.
- **No attachments** from guests.
- Department: **optional** dropdown with "I don't know".
- After submit: **reference number only** (thank-you page). No status-check page.

## 2. Form

| Section | Field | Rule |
|---|---|---|
| About you | `guest_name` | required, ≤ 255 |
| | `guest_contact` (phone or email) | required, ≤ 255 |
| | `guest_relationship` | required, one of `patient`, `relative`, `visitor`, `other` |
| What happened | `incident_type_id` | required, active incident type |
| | `department_id` | optional, a selectable tdh section (`Department::selectableRule()`) |
| | `occurred_at` | required date, not in the future |
| | `location` | required, ≤ 255 |
| | `summary` | required |
| Declaration | `legal_attestation` | accepted |
| (hidden) | `website` | honeypot — must be empty (`prohibited`) |

## 3. Behaviour

- A guest report is created and **submitted immediately** (no drafts) via the existing `IncidentService::submit()` (incident number generation, `reported_at`, `IncidentSubmitted` event). It lands in `Submitted` = Department Assessment.
- Notifications: unchanged `IncidentReviewers::for()` — department Supervisors/Department Heads + QSO/Admin, or QSO/Admin only when no department was chosen (QSO/Admin can already set the department during assessment).
- Thank-you page shows the reference (incident number), passed via the session after redirect.
- Incident page shows "Guest reporter: {name} · {relationship} · {contact}" in place of the reporter. Guest contact details are visible to anyone who can already view the incident.
- **Return to Reporter is not available for guest reports** (no account to edit a draft): new policy ability `returnToReporter` = `completeAssessment` AND `reporter_id !== null`; used by `ReturnIncidentRequest` and the AssessmentPanel button.
- Login page gets a "Not hospital staff? Report an incident" link to `/report`.

## 4. Data (`incident_report`, forward migration)

- `incidents.reporter_id` → **nullable** (needs `doctrine/dbal` for `->change()` in Laravel 9 — installed with user approval).
- New nullable columns on `incidents`: `guest_name` (string), `guest_contact` (string), `guest_relationship` (string 20).

## 5. Out of scope

Attachments, CAPTCHA, status lookup, emailing the guest, editing a guest report.

## 6. Testing

Feature tests: guest can submit without login → `Submitted` incident with number, null reporter, guest fields; thank-you page shows the reference; validation (required fields, relationship values, future date, inactive/placeholder department, honeypot); throttle (4th POST within an hour → 429); notifications go to QSO/Admin when no department; `returnToReporter` false for guest incidents and `/return` is 403; show page renders guest reporter data. Browser check of `/report` and the thank-you page.
