# Suggested severity from the incident type — design (2026-10-06)

## Goal
Use each incident type's `default_severity` (stored since 13db67b, read by nothing) as a
starting point for the Department / Service Head's severity decision. The human decision
stays the only thing that sets `incidents.severity`, so alerts, deadlines and the Sentinel
pathway are unchanged.

## Rule
- An incident with no severity yet has a suggestion = the **highest** `default_severity`
  among its chosen incident types. Types without a default are ignored.
- No type has a default (or only "Other" was chosen) → no suggestion; the card looks as today.
- Once `incidents.severity` is set, no suggestion is shown.

## Changes
- `Incident::suggestedSeverity()` returns `['severity' => Severity, 'type' => name]` or null.
- `IncidentController::show()` passes it as the `suggestedSeverity` prop
  (`{ value, label, type }` or null).
- `AssessmentPanel.vue`: when severity is empty and a suggestion exists, the suggested level
  is pre-selected for the Head and a plain hint reads
  "Suggested from the incident type: High (Medication error). Change it if the harm was different."
  People who cannot set severity see the same hint as text.
- Nothing is saved until the Head clicks Save or Complete.

## Not changed
Reporter wizard, CQI triage, alerts, deadlines, the database.

## Tests
Highest default wins; no suggestion without defaults; no suggestion once severity is set;
the Show page passes the prop.
