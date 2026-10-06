# Workflow durations settings — design (2026-10-06)

## Goal
Let the IT/System Admin change the workflow time limits from a screen instead of
`config/incident_workflow.php`. The five severity levels (Level I Low … Level V Sentinel)
are fixed; only the durations change.

## Page
`/admin/workflow-durations`, IT Admin only (gate `manageWorkflowDurations`), sidebar link
"Workflow Durations" under Administration & Audit.

- Table, one row per fixed level, three whole-number hour fields:
  Review (from department assessment to CQI review), Investigation (from assignment to
  target closure), Closure approval (from request to decision). 1–8760 hours.
- Department assessment: hours from submission, 1–8760.
- Effectiveness check wait: days after every CAPA is verified, 0–365.
- Each field: label, plain hint, own error. One Save button.
- Note on the page: changes apply to deadlines set after saving; deadlines already set stay.
  Review and assessment are checked daily against the current values, so they also apply
  to incidents already waiting.

## Storage
Table `workflow_settings` (`key` unique, `value` unsigned int). Keys are the config paths,
e.g. `review_sla_hours.level_1_low`, `assessment_sla_hours`, `effectiveness_wait_days`.
A missing key falls back to `config('incident_workflow.<key>')`, so nothing changes until saved.

`App\Support\WorkflowDurations::get($key)` replaces the seven `config('incident_workflow.…')`
duration reads (escalation recipients stay in config).

## Tests
Only the IT Admin can view/save; out-of-range values rejected; saved values are used for
new deadlines; config defaults used when nothing is saved.
