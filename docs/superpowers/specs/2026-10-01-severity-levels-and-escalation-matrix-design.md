# Five Severity Levels & Escalation/Notification Matrix — Design

Date: 2026-10-01 · Status: approved by the user in conversation

## Why

The client's **Automated Risk Triage** table has five levels with an indicative
meaning and a suggested action each, and their **Escalation & Notification
Matrix** says who is told, and when, for each trigger. The system has four
levels (Critical and Sentinel merged), shows no meanings, alerts High+ only
after CQI triage, and sends overdue RCA/CAPA escalations to the CQI Office only.

Triage stays **manual** (Department Head sets the level, the CQI Office confirms
or changes it) — the client chose human triage on 2026-09-29. The meanings help
the person choose; they don't replace them.

Out of scope: a sentinel-event *pathway* (waiting on the client to define it).

## 1. Five severity levels

`App\Enums\Severity`:

| Case | Value | label() | romanNumeral() | meaning() |
|---|---|---|---|---|
| Level1Low | `level_1_low` | Low | Level I | Near miss / no harm or low-risk event |
| Level2Moderate | `level_2_moderate` | Moderate | Level II | Temporary harm or intervention required |
| Level3High | `level_3_high` | High | Level III | Significant harm, prolonged hospitalization or high-risk event |
| Level4Critical | `level_4_critical` | Critical | Level IV | Permanent or life-threatening harm |
| Level5Sentinel | `level_5_sentinel` | Sentinel | Level V | Death or serious permanent harm / other agency-defined sentinel event |

- `Level4CriticalSentinel` is removed. `isSentinel()` is true for Level5Sentinel only;
  `is_sentinel_event` is set from it (both in `completeAssessment` and `markReviewed`).
- New helper `isHighOrAbove(): bool` (High, Critical, Sentinel). Every current
  "High or Sentinel" check uses it:
  `ApprovalService` (CQI Committee closure approval), `IncidentPolicy::skipInvestigation`
  (Low/Moderate only — unchanged in meaning), `ApprovalPanel.vue` button label.
- `config/incident_workflow.php`: `review_sla_hours`, `investigation_sla_hours` and
  `approval_sla_hours` each get `level_4_critical` and `level_5_sentinel` keys with the
  old Level IV value (24 / 72 / 24); the old key is removed. Note: the user has an
  uncommitted local edit in this file (`effectiveness_wait_days` = 0) — edit only the
  severity keys and never commit that line.

**Data migration** (forward-only, additive): update `level_4_critical_sentinel` →
`level_5_sentinel` in `incidents.severity` and `incident_types.default_severity`.
`down()` maps `level_5_sentinel` **and** `level_4_critical` back to the old value.
Audit logs only store status changes; severity changes live in comment text, so no
history rewrite is needed.

**Frontend — one source of truth.** New `resources/js/Utils/severities.js` exporting
the ordered list `{ value, label, numeral, meaning }`, plus badge classes and chart
colours keyed by value. `useIncidentStatus.js`, `chartPalette.js`,
`AssessmentPanel.vue`, `WorkflowActionsPanel.vue`, `ApprovalPanel.vue` and
`MonthlySeverityChart.vue` import from it instead of keeping their own copies.
Badge colours: Critical takes the current Level IV error colour; Sentinel a
stronger error style (e.g. `bg-error text-on-error`). Chart ramp gains a fifth step.

**Severity pickers show the meaning:** the Department Assessment cards
(5 cards; grid adapts) show numeral, label and meaning; CQI triage changes from a
plain `<select>` to the same cards (or radio list) with meanings, so both roles
choose against the same definitions.

## 2. Severity alerts (immediate)

**When:** on `IncidentAssessed` (the Department Head completed the assessment with a
level), and on `IncidentReviewed` **only if the CQI Office changed the level**.
`IncidentReviewed` gains a `?Severity $previousSeverity` constructor argument
(default null); `markReviewed` passes the level before the change, and the listener
alerts only when `previousSeverity !== incident->severity`. Both events also carry
the acting user (`?User $actor`, default null) so the actor can be excluded.

**Who** (`App\Support\EscalationRecipients::forSeverity(Incident)`):

| Level | Recipients |
|---|---|
| Low | nobody |
| Moderate | Dept Head(s) of the incident's department + CQI Office |
| High | Dept Head(s) + CQI Office + Leadership mapped to the department |
| Critical | CQI Office + mapped Leadership + Executives (`management`) |
| Sentinel | same as Critical |

- "Dept Head of the department" = active users with role `department_head` and
  `section` = incident `department_id`. "Mapped Leadership" = active `leadership`
  users listed in `leadership_departments` for that department.
- The **actor** (the person who set/changed the level) is excluded.
- Recipients are de-duplicated.
- One notification class, `SeverityAlertNotification` (replaces
  `HighRiskIncidentNotification`), message e.g.
  "Critical incident: IR-2026-000012 (IMISS) was rated Level IV – Critical."
- `AlertOversightOfHighRiskIncident` is replaced by a `SendSeverityAlert` listener
  on both events. The CQI Committee is no longer alerted (client's matrix omits it;
  it still has read-all access and approves High+ closures).
- The existing "ready for triage" notice to the CQI Office is unchanged, so on a
  High+ assessment the CQI Office receives both (different meanings, kept on purpose).

## 3. Overdue reminders & escalations (daily `incidents:check-overdue`)

| Trigger | Reminder (1 day before due, once) | Escalation (once overdue, once) |
|---|---|---|
| RCA — investigation `in_progress` | lead investigator | lead investigator + Dept Head(s) + CQI Office |
| CAPA — not verified | responsible person | responsible person + Dept Head(s) + CQI Office |
| CAPA with priority `critical` | responsible person | the above + Executives |

- "1 day before": RCA when `target_completion_at` is within the next 24 hours;
  CAPA when `due_date` is tomorrow. Overdue keeps today's definitions
  (`OverdueInvestigationsQuery`, `CorrectiveAction::scopeOverdue`).
- New nullable columns `investigations.reminder_sent_at` and
  `corrective_actions.reminder_sent_at` (additive migration) so each reminder is sent once.
  Existing `escalated_at` keeps escalations to once.
- New `DueSoonInvestigationsQuery` / `DueSoonCorrectiveActionsQuery` (layered style,
  like the existing overdue queries) and repository `markReminded()` methods.
- New `DeadlineReminderNotification` ("Reminder: CAPA-2026-004 on IR-… is due tomorrow.").
  Escalations keep `IncidentEscalationNotification`.
- Recipients come from `EscalationRecipients` (`forOverdueInvestigation`,
  `forOverdueCorrectiveAction`); hard-deleted users (null relations) are skipped.
- The RCA/CAPA sweeps no longer depend on `escalation_recipient_roles`; the command
  must not skip them when no CQI user exists (today it returns early). Review,
  assignment and approval escalations keep going to the CQI Office as now.

## 4. Unchanged

New-report notice (Focal Person + Dept Head + CQI already matches the matrix),
investigator-assigned notice, review/assignment/approval overdue escalations,
effectiveness-check notice.

## Testing

- Enum: five cases, labels/meanings, `isSentinel`, `isHighOrAbove`.
- Migration: old value becomes `level_5_sentinel` on incidents and incident types.
- Severity alerts: one test per level (exact recipients), actor excluded,
  other-department heads/unmapped leadership excluded, Committee not alerted,
  triage with unchanged level sends nothing, triage that changes level alerts the new level's recipients.
- Overdue: reminder sent once to the right person, not re-sent; escalation recipients for
  RCA, CAPA and critical CAPA; sweeps still run with no CQI users.
- Existing High/Sentinel rules (Committee closure approval, no-skip) hold for Critical and Sentinel.
- Browser check: assessment and triage pickers show five levels with meanings; badges render.
