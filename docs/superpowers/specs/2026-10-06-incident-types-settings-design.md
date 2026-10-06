# Incident Types Settings (IT Admin) — Design

Date: 2026-10-06

## Purpose

The IT/System Admin can manage the hospital's incident type list without a
developer: add types, edit them (name, category, default severity, active), and
delete types that no report has used. `incident_types.default_severity` has
existed since Phase 1 but nothing fills or uses it; this page lets the IT Admin
fill it.

**Out of scope:** using the default severity anywhere in the workflow (the
separate "suggested severity on submit" feature). After this change the value
is stored only; incidents are not affected.

## Access

- `App\Policies\IncidentTypePolicy` (registered for `IncidentType`):
  `viewAny`, `create`, `update`, `delete` — all `role === Role::Administrator`.
  `delete` also requires the type to be unused (see Delete).
- CQI Office, Dept Head, Staff and every other role get 403 on every route.
- Shared Inertia prop `auth.can.manageIncidentTypes` (= `can('viewAny', IncidentType::class)`).
- Sidebar: new item **Incident Types** (icon `tags`) under "Administration & Audit",
  with `can: 'manageIncidentTypes'`, so only the IT Admin sees it (the CQI Office
  still sees the group for Leadership Coverage).

## Routes

| Method | URI | Name |
|---|---|---|
| GET | `/admin/incident-types` | `admin.incident-types.index` |
| POST | `/admin/incident-types` | `admin.incident-types.store` |
| PUT | `/admin/incident-types/{incidentType}` | `admin.incident-types.update` |
| DELETE | `/admin/incident-types/{incidentType}` | `admin.incident-types.destroy` |

All inside the existing `auth` route group. Store/update/destroy redirect back
with a `success` flash message.

## Fields and validation

| Field | Rules | Hint shown on the form |
|---|---|---|
| Name | required, string, max 255, unique in `incident_types.name` (ignoring the type being edited), trimmed | "Shown to reporters in the incident form." |
| Category | required, one of `injury, clinical, exposure, security, property, environment, conduct` | "Groups similar types together." |
| Default severity | nullable, a `Severity` value | "Pre-selected level for this type. Leave as No default if it varies." (+ the chosen level's meaning) |
| Active | boolean (create defaults to on) | "Off hides this type from new reports. Past reports keep it." |

The category list lives in one place: `IncidentType::CATEGORIES` (value → label),
used by both FormRequests and passed to the page.

## Delete

- A type is **in use** when any incident (any status, drafts included) refers to
  it through `incident_incident_type`, or through the legacy
  `incidents.incident_type_id`, or any `recurrence_reviews` row refers to it.
- Unused → delete permanently (after a confirm prompt in the UI).
- In use → no Delete button; the row shows "In use — can't be deleted. Switch it
  off to hide it instead." The server also refuses: the policy `delete` ability
  returns false → 403.

## Code (trimmed layered pattern)

- `App\Http\Requests\IncidentTypes\StoreIncidentTypeRequest`, `UpdateIncidentTypeRequest`
- `App\DataTransferObjects\IncidentTypes\IncidentTypeData` (`name`, `category`,
  `?Severity defaultSeverity`, `isActive`; `fromArray()`)
- `App\Services\IncidentTypeService`: `create(IncidentTypeData)`,
  `update(IncidentType, IncidentTypeData)`, `delete(IncidentType)`
- `IncidentType::isInUse(): bool` and a `usageCount` (number of incidents) used by
  the policy and the resource.
- `App\Http\Resources\IncidentTypeResource`: `id, name, category, category_label,
  default_severity, is_active, usage_count, can_delete` (the page builds severity
  labels with `SeverityBadge` / `resources/js/Utils/severities.js`)
- `App\Http\Controllers\Admin\IncidentTypeController` (thin: authorize →
  request → DTO → service → redirect)
- `resources/js/Pages/Admin/IncidentTypes.vue`

No Repository, Action or Query classes — one simple table, they would only pass
calls through. No migration needed.

## Page

`Admin/IncidentTypes.vue` in `AuthenticatedLayout`, styled like `Admin/Leadership.vue`.

- **Table** of all types ordered by name: Name, Category, Default severity
  (`SeverityBadge`, or "No default"), Status (Active / Inactive), Used by
  (N incidents), actions **Edit** and **Delete** (Delete only when `can_delete`).
- **Add incident type** button opens the form; **Edit** opens the same form
  filled in. Every field has a visible label, plain hint and its own error
  message. Save / Cancel.
- Severity dropdown options show "Level III – High" style labels (roman numeral +
  label from the `Severity` enum, as on the assessment cards).

## Effects elsewhere

- Renaming changes the label everywhere, including past reports and analytics.
- Inactive types already drop out of the staff wizard, the guest form and the
  Trends filter (they filter on `is_active`); no change needed there.

## Tests

- IT Admin can open the page; CQI Office, Dept Head and Staff get 403 on index,
  store, update and destroy.
- Create: works; rejects duplicate name, missing name/category, invalid category,
  invalid severity.
- Update: rename (unique ignores itself), set and clear default severity,
  toggle active.
- Delete: unused type is removed; type used by an incident (pivot), by the
  legacy column, or by a recurrence review is refused with 403 and still exists.
- An inactive type is not in the wizard's `incidentTypes` prop.
- Resource gives correct `usage_count` / `can_delete`.
- Browser check: add, edit, toggle, delete-unused, and the in-use row shows no Delete.
