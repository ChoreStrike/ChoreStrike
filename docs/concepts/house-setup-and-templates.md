# House Setup & Cleaning Templates

> How a new ChoreStrike household gets a usable cleaning plan in ~5 minutes,
> and how per-room tasks are added from the template catalog. Implemented in
> CS-PLAN-027.

## 1. Goals

- **First-run onboarding:** a brand-new house (no floors) is greeted by a setup
  wizard that generates floors, rooms, tasks, and assignments in one step.
- **Template-based cleaning plans:** a curated, room-type-based catalog with two
  cleaning levels so households can scale the plan to their “cleaning attitude”.
- **German-first, translatable content:** casual, kid-friendly copy via the
  standard Nextcloud `t()` mechanism.

## 2. Room types as the organizing principle

Templates are scoped to **room types**, not individual rooms. A template lists
the room types it applies to (e.g. *vacuum* → bedroom, living, kids, office,
generic). The v1 room-type set:

| ID | Default name (DE) | Icon | Hosts |
|----|-------------------|------|-------|
| `kitchen` | Küche | Utensils | kitchen tasks |
| `bathroom` | Bad | Bath | bathroom tasks |
| `bedroom` | Schlafzimmer | Bed | bedroom tasks |
| `living` | Wohnzimmer | Sofa | living tasks |
| `kids` | Kinderzimmer | Baby | kids tasks |
| `office` | Büro | Briefcase | office tasks |
| `hallway` | Flur & Treppenhaus | DoorOpen | **floor-wide** tasks (one per floor) |
| `household` | Haushalt & Co. | WashingMachine | **house-wide** tasks (one per house) |
| `basement` | Keller | Package | basement tasks |
| `outdoor` | Garten & Balkon | Trees | outdoor tasks |
| `generic` | Zimmer | Home | minimal generic set |

Room-type definitions live in `lib/Templates/RoomTypes.json` (id, defaultName,
icon, ordered template IDs). Rooms carry a `room_type` column (default
`generic`).

**Floor/house-wide chores need no new scope columns** (decision
[ADR-008](../adr/ADR-008-room-type-template-materialization.md)): floor-wide
chores live in one `hallway` room per floor, house-wide chores in one
`household` room. Materialization stays 100% room-based.

## 3. Cleaning levels

| Key | Label | Semantics |
|-----|-------|-----------|
| `putzteufel` | Putzteufel | All applicable tasks, standard frequencies; includes daily micro-tasks and monthly deep cleans. |
| `putzmuffel` | Putzmuffel | ~60% of tasks (essentials only), frequencies roughly halved, shorter times. Daily micro-tasks and most deep cleans are omitted. |

Each template carries per-level scheduling data:
`"levels": { "putzteufel": { frequencyType, frequencyValue, estimatedMinutes },
"putzmuffel": { ... } | null }`. `null` means the task is not part of the
relaxed plan. Putzmuffel therefore produces strictly fewer tasks than
Putzteufel for the same house.

## 4. The catalog (v2 schema)

`lib/Templates/TaskTemplates.json` ships ~48 leveled templates (English source
text). A template:

```json
{
  "id": "generic_vacuum",
  "name": "Vacuum the room",
  "description": "Vacuum or sweep the floor.",
  "instructions": "...",
  "category": "cleaning",
  "energyCost": 2,
  "priority": 2,
  "roomTypes": ["generic"],
  "levels": {
    "putzteufel": { "frequencyType": "weekly", "frequencyValue": 1, "estimatedMinutes": 15 },
    "putzmuffel": { "frequencyType": "weekly", "frequencyValue": 2, "estimatedMinutes": 12 }
  },
  "tags": ["weekly", "vacuum", "floor"]
}
```

### Localization (i18n)
Catalog strings are **English source text** in the JSON; `TemplateService`
resolves them at runtime via `IL10N::t()`. German translations live in
`l10n/de.json` and are complete and casual/kid-friendly; English works via the
source-text fallback. The `.pot` is regenerated as an explicit step. There is
no static `t()` call inside the data files — the process is documented here and
in [i18n-and-content-policy.md](i18n-and-content-policy.md).

## 5. Backend

- **`TemplateService`** — `getCatalog()`, `getTemplatesForRoomType()` (a
  template matches when any of its `roomTypes[]` equals the requested type),
  `searchTemplates()`, `materializeTemplate(templateId, level, overrides)`
  (returns a Task-ready payload; whitelisted overrides only). Localization via
  `t()`.
- **`HouseSetupService`** — validates the wizard payload (1–6 floors, 1–12
  rooms/floor, valid room types, assignees must be members) and creates floors →
  rooms (with `room_type`) → tasks (one Task per template, memoized so a shared
  template is created once) → `RoomTask` links with per-room assignees, all in a
  single DB transaction. Auto-creates the toggleable `household` and per-floor
  `hallway` rooms. Bulk materialization never swallows failures (BUG-TEMPLATE-001):
  every skipped template is logged with its id and reported in the result.
- **`SetupController`** — `GET /api/v1/setup/catalog` and
  `POST /api/v1/setup/apply` (`@NoAdminRequired`, `ensureHouseMember`). The
  first-run gate (the house must have no floors) bounds the blast radius of
  `apply`; an already-set-up house gets `409 HOUSE_ALREADY_SET_UP`.
- **Per-room picker** — `POST /api/v1/room-tasks/from-template`
  `{ roomId, templateId, level?, overrides?, assigneeUserId? }`. The `roomId` is
  resolved server-side and validated against the caller’s house; `assigneeUserId`
  is checked for membership.

## 6. Frontend

- **Setup wizard** (`src/components/Setup/SetupWizard.vue`, `src/store/setup.js`):
  4 steps — *Quick Start* (home type, floors, basement, rooms/floor, level) →
  *Room List* (editable name, room type, person; household/hallway toggles) →
  *Preview* → apply → **Invite Family** (CS-PLAN-038): optional email list
  (add/remove rows) inviting existing Nextcloud accounts as members; skippable
  and non-blocking. The Dashboard opens the wizard automatically on a
  first-run house (no floors); „Leer starten" skips it. A manual entry point
  (Dashboard header button) stays available while the house has no floors.
- **Per-room template picker** (`src/components/Tasks/TaskTemplatePicker.vue`):
  the room add-task flow defaults to the picker (templates filtered by the
  room’s type + house-wide), with a level toggle, an „Anpassen" override form,
  and a switch to the custom-task form (which reuses the unified
  `TaskEditForm` with the room preset+locked — see
  [`task-model-and-sync.md`](task-model-and-sync.md) §Creation Model).

## 7. Authorization

- Wizard `apply` and `catalog` require house membership (`ensureHouseMember`),
  consistent with floor/room/room-task creation. No owner-only gate is added in
  v1 — the first-run gate bounds the blast radius.
- The from-template endpoint validates room ownership server-side and rejects a
  cross-house `roomId` before creating any task, and rejects a non-member
  assignee.
- **Member management** (CS-PLAN-038): invite/role-change/remove are
  owner-gated (`requireHouseOwner`) in the backend; the Settings Members
  section mirrors the gate via `userStore.isHouseOwner`. The wizard invite step
  also runs post-apply for the (first-run) owner. Known gap: `updateRole` has
  no self/last-owner guard yet (**BUG-SEC-002**); the UI disables changing
  one's own role as mitigation.

## 8. Legacy removal (decision D7)

The pre-CS-027 codebase had two dead template systems: a JSON
`TemplateController` (`/api/v1/templates/*`) and a DB-backed `is_template`
mechanism (`/api/v1/tasks/templates`, `createDefaultTemplates`). Both were
**removed** (pre-release, no backward-compat requirement — project-rules §1):
the `is_template` column/entity field, the `TaskMapper::findTemplates`/
`createFromTemplate`, the `TaskService` template methods, the
`TaskController` template endpoints, and the `TemplateController` (archived).
Their room-type filtering is generalized and relocated into `SetupController`
catalog + the from-template endpoint. The misnamed `TemplateSelector.vue` (a
recurrence-preset picker) was renamed `RecurrencePresetSelector.vue`.
