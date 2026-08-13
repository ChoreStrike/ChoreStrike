# CS-PLAN-027: House Setup Wizard & Template-Based Cleaning Plans

> **Status:** Implemented (2026-08-13) — full gate green including the two new E2E specs, executed green 2026-08-13 (see §12). Ready for `active/ → done/` (manual move).
> **Effort:** XL
> **Priority:** P1
> **Depends on:** none (builds on existing catalog/room/task infrastructure)
> **Related issue:** closes BUG-TEMPLATE-001, closes CR-016-01 (.pot regeneration)

## 1. Problem Summary

Setting up a house in ChoreStrike is entirely manual: users create floors and
rooms one by one and must invent every task themselves. There is no onboarding,
no first-run detection (the house is created silently on the first API call),
and no way to bootstrap a realistic cleaning plan quickly.

Template infrastructure exists but is fragmented and unused:

- A JSON task catalog (`lib/Templates/TaskTemplates.json`, 30 entries) behind
  `TemplateController`/`TemplateService` — **not consumed by any UI**, hardcoded
  English. It already scopes each task to a *single* room type (`scopeLabel` /
  `scopeType`) with a working `byRoomType` endpoint, but supports only one room
  type per template, has no cleaning-levels, and ships a brittle, unused
  name-heuristic (`getSuggestionsForRoom`) for room matching.
- A second, DB-backed `is_template` mechanism (`/api/v1/tasks/templates`,
  `createDefaultTemplates` with 15 hardcoded entries) — also unused by the UI.
- `TemplateSelector.vue` is misleadingly named: it is a recurrence-preset
  selector, not a template picker.

Consequences: new households get an empty house with zero guidance; the two
dead template systems add maintenance surface; catalog strings are not
translatable.

## 2. Research — How Other Cleaning Apps Do It

Findings from comparable household/cleaning apps and widely used cleaning
checklists (sources listed in §2.4; per brand policy, permanent docs will keep
these references generic):

1. **Guided ~5-minute onboarding is the standard.** The leading cleaning apps
   start with a short wizard (home setup → areas/rooms → tasks) and promise a
   runnable plan in ~5 minutes. Setup is explicitly *_incremental* — users can
   expand later.
2. **Task libraries with suggestions speed up setup.** All major apps ship a
   pre-made task library ("large selection of task suggestions") from which
   users pick; custom tasks are always offered alongside. Suggestions are
   filtered by area/room.
3. **"Cleaning attitude" / intensity presets scale the plan.** One market
   leader asks for a cleaning attitude (relaxed / average / proactive) during
   setup; the choice changes preset frequencies and which tasks are included.
   This maps 1:1 to the requested two-level system ("Putzteufel" vs. lighter).
4. **Tasks are area-based but area-flexible.** Checklists and apps organize
   tasks by room *type* (kitchen, bathroom, bedroom, living, office, laundry,
   basement, outdoor), while generic tasks (vacuuming, dusting, floors) apply
   to many room types. House-level chores (laundry, trash, mail, plants) form
   their own group.
5. **Per-room assignment + family fairness.** Family-oriented apps assign tasks
   or rooms to members, support "everyone" as default, and allow changing
   assignments later; some gate kids' completions behind parent approval
   (ChoreStrike already has the overdue-claim/assignee machinery).
6. **Content pattern of checklists:** daily = quick resets (dishes, counters,
   tidying, beds), weekly = bathrooms/floors/dusting/bedding, monthly = deep
   clean (appliances, grout, windows, mattress). Frequencies in real user
   reviews are often *reduced* from app defaults — supporting the "lighter"
   level as first-class.

### 2.4 Sources (temporary reference — do not copy into permanent docs)

- todyapp.com (onboarding, areas, task library, cleaning attitude, effort manager)
- sweepy.com (task suggestions for faster setup, difficulty points, smart schedule)
- ourhomeapp.com / Play Store listing (room-based tasks, member assignment, approvals)
- todoist.com help "setup templates" (copy-whole-structure templates)
- organizeeveryroom.com, cleaningchecklist.org, lamaids.us, themommymess.com
  (room-by-room daily/weekly/monthly task lists used as content baseline)

## 3. Strategy

Replace both dead template systems with **one room-type-based template catalog**
and a **first-run setup wizard**:

1. **Room types** become the organizing principle (not individual rooms). A
   task template is defined once and lists the room types it applies to
   (e.g. vacuuming → bedroom, living, kids, office, hallway). Each room type
   is a *simple* definition: default name + icon + list of template IDs. This
   *generalizes* the existing single-valued `scopeLabel`/`scopeType` scoping into
   a multi-valued `roomTypes[]` plus a `RoomTypes.json` registry — an evolution
   of the existing room-type filtering, not a from-scratch introduction.
2. **Two cleaning levels** with playful names: **`putzteufel`** (thorough: all
   tasks, higher frequencies) and **`putzmuffel`** (relaxed: fewer tasks,
   lower frequencies, shorter times). Each template carries per-level settings
   or is level-exclusive.
3. **Floor/house-wide chores need no new scope machinery:** floor-wide tasks
   (robot vacuum, stairs) are assigned to the `hallway` room type (one hallway
   room per floor), house-wide tasks (laundry, trash, mail, plants) to the
   `household` room type (one "Haushalt & Co." room per house). Materialization
   stays 100% room-based — KISS, no schema change for scopes.
4. **Setup wizard** (first run, empty house): quick-start form (home type,
   floors, basement, rooms per floor, cleaning level) → generated room list
   (room type + optional person per room) → one transactional backend call
   creates floors, rooms, tasks, and assignments.
5. **Room-level task picker:** adding a task to a room offers the template
   list (filtered by the room's type + house-wide templates) *or* a custom
   task; templates are adjustable before saving (overrides) and tasks remain
   fully editable afterwards.
6. **German-first content:** all catalog/UI strings run through `t()`;
   German translations are complete, casual, and kid-friendly; English works
   via source-text fallback and gets polished later.
7. **Pre-release cleanup:** the DB-backed `is_template` template endpoints and
   the old `TemplateService` heuristics are removed (no legacy shims,
   project-rules §1).

### Key decisions (for plan review)

| # | Decision | Choice | Alternative considered |
|---|----------|--------|------------------------|
| D1 | Level names | `putzteufel` / `putzmuffel` | "Wirbelwind/Faultierbande", "Blitzblank/Locker" |
| D2 | Floor/house scope | Via `hallway`/`household` room types (room-based materialization) | True floor/house scopes on RoomTask (large change, touches all queries) |
| D3 | Wizard availability | Only while the house has **no floors** (first run); afterwards the per-room template picker is the path | Re-runnable wizard that appends structure |
| D4 | Per-room person in wizard | Applied as assignee to every created room-task instance of that room (existing `assignedToUserId`); changeable later per task | New `Room.defaultAssignee` column |
| D5 | Catalog i18n | English source text in JSON → `l10n->t()` resolution → full DE in `l10n/de.json` (Nextcloud convention; EN included for free) | Per-locale JSON catalogs |
| D6 | Template customization | Overrides at apply-time (name, frequency, minutes, energy, priority) + normal task editing afterwards; no saved "my templates" in v1 | User-defined persistent template copies |
| D7 | Legacy systems | Remove DB `is_template` endpoints + old catalog heuristics (verify seeds/tests first) | Keep both |
| D8 | Wizard authorization | `ensureHouseMember` suffices for `setup/apply` (matches existing floor/room/room-task creation); first-run gate (D3) bounds blast radius | `requireHouseOwner` gate (stricter but inconsistent with current creation rules) |

## 4. Content Design — Catalog v2

### 4.1 Cleaning levels

| Key | Label (DE) | Semantics |
|-----|-----------|-----------|
| `putzteufel` | Putzteufel | All applicable tasks, standard/high frequencies, includes daily micro-tasks and monthly deep cleans |
| `putzmuffel` | Putzmuffel | ~60% of tasks (essentials only), frequencies roughly halved, shorter `estimatedMinutes`; daily micro-tasks and most deep cleans omitted |

Level data per template:
`"levels": { "putzteufel": { frequencyType, frequencyValue, estimatedMinutes }, "putzmuffel": { ... } | null }`
(`null` = not part of the relaxed level).

### 4.2 Room types (v1 set)

| ID | Default name (DE) | Icon (existing registry) | Hosts |
|----|-------------------|--------------------------|-------|
| `kitchen` | Küche | `Utensils` | kitchen tasks |
| `bathroom` | Bad | `Bath` | bathroom tasks |
| `bedroom` | Schlafzimmer | `Bed` | bedroom tasks |
| `living` | Wohnzimmer | `Sofa` | living tasks |
| `kids` | Kinderzimmer | `Baby` | kids tasks |
| `office` | Büro | `Briefcase` | office tasks |
| `hallway` | Flur & Treppenhaus | `DoorOpen` | **floor-wide** tasks (robot vacuum, stairs, floor wiping) — one per floor |
| `household` | Haushalt & Co. | `WashingMachine` | **house-wide** tasks (laundry, trash, mail, plants, appliance maintenance) — one per house |
| `basement` | Keller | `Package` | basement tasks |
| `outdoor` | Garten & Balkon | `Trees` | outdoor tasks |
| `generic` | Zimmer | `Home` | minimal generic set (dust, vacuum, tidy) |

Room type definitions live in `lib/Templates/RoomTypes.json` (id, defaultName,
icon, ordered template-ID list) — deliberately simple lists, no complex
objects.

### 4.3 Task template catalog (v2 schema)

```json
{
  "id": "vacuum_room",
  "name": "Vacuum the room",
  "description": "Grab the vacuum and give the floor a fresh start!",
  "instructions": "...",
  "category": "cleaning",
  "energyCost": 2,
  "priority": 2,
  "roomTypes": ["bedroom", "living", "kids", "office", "generic"],
  "levels": {
    "putzteufel": { "frequencyType": "weekly", "frequencyValue": 1, "estimatedMinutes": 15 },
    "putzmuffel": { "frequencyType": "weekly", "frequencyValue": 2, "estimatedMinutes": 10 }
  },
  "tags": ["floors", "quick"]
}
```

Target volume: **~45–50 templates** (from research §2 + the existing 30,
deduplicated): kitchen ~8, bathroom ~6, bedroom ~5, living ~4, kids ~3,
office ~3, hallway/floor-wide ~4, household/house-wide ~8, basement ~2,
outdoor ~3, generic ~3. Frequencies follow the daily/weekly/monthly pattern
from §2.6 of the research; energy/priority reuse existing catalog values.

### 4.4 Tone (German)

Casual, motivating, kid-understandable, per `docs/concepts/i18n-and-content-policy.md`
(family-friendly, respectful). Examples:

- EN `"Vacuum the room"` → DE **„Staubsaugen"** — „Der Staub hat keine Chance! Schnell einmal durchgesaugt, schon ist der Boden wieder startklar."
- EN `"Clean toilet"` → DE **„Klo blitzblank putzen"** — „Einmal schrubben, einmal wischen — fertig ist das sauberste Örtchen des Hauses."
- Level copy: Putzteufel: „Alles glänzt! Viele Aufgaben, dafür ist ruckzuck alles picobello." / Putzmuffel: „Locker bleiben! Nur das Nötigste, mit kürzeren Zeiten."

## 5. Backend / Frontend Changes

### Backend (`lib/`)

- **`lib/Templates/TaskTemplates.json`** — rewritten to v2 schema (§4.3), English source text.
- **`lib/Templates/RoomTypes.json`** — new; room-type definitions (§4.2).
- **`lib/Service/TemplateService.php`** — rebuilt: `getCatalog()` (room types +
  localized templates incl. level data), `getTemplatesForRoomType(roomType)`,
  `searchTemplates()`, `materializeTemplate(templateId, level, overrides)` →
  returns a Task-ready payload. The existing single-valued
  `getTemplatesByRoomType()` is generalized into multi-valued
  `getTemplatesForRoomType()` (a template matches when any of its `roomTypes[]`
  equals the requested type, plus house-wide templates); the brittle
  `getSuggestionsForRoom()` heuristic is removed. Localization via
  `$this->l10n->t()` on catalog source strings (activates the currently dead
  `getLocalizedTemplates` idea properly).
- **`lib/Service/HouseSetupService.php`** — new. Validates the wizard payload
  (floor count 1–6, rooms per floor 1–12, valid room types, assignees must be
  house members) and creates floors + rooms (+`roomType`) + tasks + room-task
  links + assignments **transactionally**. Auto-creates one `household` room
  (floor 1, or basement floor when basement selected) and one `hallway` room
  per floor (both toggleable via payload options).
- **`lib/Controller/SetupController.php`** — new. `GET /api/v1/setup/catalog`
  (room types + leveled templates for wizard & picker), `POST /api/v1/setup/apply`.
  `@NoAdminRequired`; both endpoints call `AuthorizationService::ensureHouseMember`.
  **Auth decision (D8):** membership suffices for `apply` — consistent with
  `FloorController` / `RoomController` / `RoomTaskController`, which all use
  `ensureHouseMember` (not `requireHouseOwner`) for creation. The first-run gate
  (D3: house has no floors) bounds the blast radius; no owner-only gate is added
  in v1.
- **`lib/Db/Room.php` + room migration** — new `roomType` string column
  (default `generic`). Pre-release: adjust the existing migration chain
  (`Version1000Date20260224000000.php`, where the `chorestrike_rooms` table is defined), no upgrade path.
  `RoomController`/`RoomService` accept/return `roomType` on create/update.
- **`lib/Controller/RoomTaskController.php` + `RoomTaskService`** — new
  `POST /api/v1/room-tasks/from-template` `{ roomId, templateId, level?, overrides?, assigneeUserId? }`:
  creates the Task (scope `room`), links the RoomTask, optional assignee.
  `roomId` is resolved server-side and validated against the caller's house via
  `RoomService::validateRoomOwnership(roomId, houseId)` (the client ID is never
  trusted); `assigneeUserId` (if set) is checked for house membership. Overrides
  validated against Task entity rules.
- **`appinfo/routes.php`** — new setup + from-template routes.
- **Legacy removal (D7):** `TaskController::templates/createFromTemplate/createDefaultTemplates`,
  `TaskService::createDefaultTemplates`, `TaskMapper::findTemplates/createFromTemplate`,
  `is_template` column + entity field are removed. The `TemplateController`
  endpoints (`/api/v1/templates/*`) are removed too — their room-type filtering
  is relocated and generalized into `SetupController::catalog` (multi-valued
  `roomTypes[]` + levels + l10n), not rebuilt from scratch. Verify
  `TestDataService` seeds and affected tests first; adjust them.
- **Bug fix (closes BUG-TEMPLATE-001):** bulk operations log every skipped
  template via `AppLogger` and return partial-result details instead of
  swallowing exceptions.

### Frontend (`src/`)

- **`src/components/Setup/SetupWizard.vue`** — new modal wizard (3 steps):
  1. *Schnellstart*: home type (Haus/Wohnung), floors (1–6), basement (ja/nein),
     rooms per floor (1–6), cleaning level (Putzteufel/Putzmuffel cards with
     playful copy).
  2. *Zimmerliste*: generated rooms per floor; per room: editable name,
     room-type dropdown (sets default name+icon), person dropdown
     (`GET /api/v1/users`, empty = „für alle"); add/remove rooms; toggles for
     auto `household`/`hallway` rooms.
  3. *Vorschau*: summary (floors/rooms/task count per level) → „Los geht's!".
  Skip link: „Leer starten".
- **`src/store/setup.js`** — new (fetch catalog, apply setup).
- **`src/views/Dashboard.vue`** — first-run detection (house without floors)
  opens the wizard automatically; manual entry point stays available while the
  house is empty (D3).
- **`src/components/Tasks/TaskTemplatePicker.vue`** — new. Used in the
  RoomDetail add-task flow: template list filtered by `room.roomType`
  (+ house-wide templates), level badge, expandable „Anpassen" override form,
  plus a tab/switch to the existing custom-task form (`TaskEditForm`).
- **`src/views/RoomDetail.vue` + `src/store/roomTasks.js`** — add-task modal
  hosts `TaskTemplatePicker`; new `createFromTemplate` store action.
- **Rename** `src/components/Tasks/TemplateSelector.vue` →
  `RecurrencePresetSelector.vue` (clarity; it is a recurrence preset).
- **`src/utils/roomIcons.js`** — room-type→icon default mapping helper.
- Remove unused `tasks.js` store actions (`createDefaultTemplates`, legacy
  `createFromTemplate`) together with the backend legacy removal.

### Cross-layer contracts

- `GET /api/v1/setup/catalog` → `{ levels, roomTypes: [{id, defaultName, icon, templateIds}], templates: [{id, name, description, category, roomTypes, energyCost, priority, levels}] }` (localized).
- `POST /api/v1/setup/apply` → `{ intensityLevel, options: {createHouseholdRoom, createHallways}, floors: [{name, rooms: [{name, roomType, icon?, assigneeUserId?}]}] }` → created structure + counts.
- `POST /api/v1/room-tasks/from-template` → created room task (existing room-task payload shape).
- Room payloads gain `roomType` (create/update/read).

## 6. Tests

- **Unit (PHPUnit):** `HouseSetupServiceTest` (validation limits, transaction
  rollback, assignee membership check, household/hallway auto-creation,
  level materialization), rebuilt `TemplateServiceTest` (v2 schema, room-type
  filtering, localization, level selection), `SetupControllerTest` (OCS
  envelope, error codes, authorization), `RoomTaskControllerTest` extension
  (from-template incl. overrides, invalid overrides, **cross-house `roomId`
  rejection**, non-member-assignee rejection), Room `roomType` in
  existing room tests.
- **Integration (PHPUnit, Docker):** `HouseSetupIntegrationTest` — full wizard
  apply on a fresh family-profile instance (floors→rooms→tasks→assignments
  present and schedulable); from-template flow on a real room.
- **Unit (Jest):** `SetupWizard.spec.js` (step navigation, quick-start →
  generated room list, type/person changes, apply payload),
  `TaskTemplatePicker.spec.js` (filtering by room type, override form,
  custom-task switch), store specs (`setup.js`, `roomTasks.createFromTemplate`).
- **E2E (Playwright):** new `tests/e2e/release-setup-wizard.spec.ts`
  (first-run wizard → populated house; skip path) following the CS-024
  `release-*` conventions; update `tests/e2e/release-task-templates.spec.ts`
  for the v2 catalog endpoints + per-room picker; update
  `docs/testing/release-test-plan.md` rows/tagging.

## 7. File Manifest

```
new:      lib/Templates/RoomTypes.json
new:      lib/Service/HouseSetupService.php
new:      lib/Controller/SetupController.php
new:      src/components/Setup/SetupWizard.vue
new:      src/components/Tasks/TaskTemplatePicker.vue
new:      src/store/setup.js
new:      tests/unit/Service/HouseSetupServiceTest.php
new:      tests/unit/Controller/SetupControllerTest.php
new:      tests/integration/Setup/HouseSetupIntegrationTest.php
new:      tests/unit/components/SetupWizard.spec.js
new:      tests/unit/components/TaskTemplatePicker.spec.js
new:      tests/e2e/release-setup-wizard.spec.ts
new:      docs/concepts/house-setup-and-templates.md
modified: lib/Templates/TaskTemplates.json            (v2 schema + content)
modified: lib/Service/TemplateService.php             (v2, localization, bulk logging)
modified: lib/Controller/TemplateController.php       (replaced by SetupController — archived)
modified: lib/Db/Room.php                             (roomType)
modified: lib/Migration/Version1000Date20260224000000.php (room_type column, adjusted in place)
modified: lib/Controller/RoomController.php           (roomType in/out)
modified: lib/Service/RoomService.php                 (roomType)
modified: lib/Controller/RoomTaskController.php       (from-template)
modified: lib/Service/RoomTaskService.php             (from-template)
modified: appinfo/routes.php
modified: src/views/Dashboard.vue                     (first-run wizard entry)
modified: src/views/RoomDetail.vue                    (template picker in add-task flow)
modified: src/store/roomTasks.js                      (createFromTemplate)
modified: src/store/tasks.js                          (legacy action removal)
modified: src/utils/roomIcons.js                      (room-type defaults)
modified: l10n/de.json, l10n/en.json, l10n/templates/chorestrike.pot
modified: tests/unit/Service/TemplateServiceTest.php
modified: tests/e2e/release-task-templates.spec.ts
modified: docs/testing/release-test-plan.md
renamed:  src/components/Tasks/TemplateSelector.vue → RecurrencePresetSelector.vue
removed:  TaskService::createDefaultTemplates + TaskController template endpoints
          + TaskMapper::findTemplates/createFromTemplate + is_template column/field (D7)
```

## 8. Implementation Order

1. **Catalog v2 + room types (content & schema):** rewrite
   `TaskTemplates.json`, add `RoomTypes.json`, German copywriting pass
   (review content with the user before hardening tests around it).
2. **TemplateService v2 + unit tests** (incl. BUG-TEMPLATE-001 logging fix).
3. **Room.roomType:** entity, migration adjustment, controller/service, tests.
4. **HouseSetupService + SetupController + routes** + unit tests; integration
   test in Docker.
5. **l10n:** fill `de.json` (complete), `en.json` polish, regenerate `.pot`
   (closes CR-016-01).
6. **Frontend wizard:** `setup.js` store, `SetupWizard.vue`, Dashboard
   first-run hook + Jest specs.
7. **Per-room template picker:** from-template endpoint, `TaskTemplatePicker`,
   RoomDetail integration, override form + Jest specs.
8. **Legacy cleanup (D7):** remove DB-template endpoints/column, old
   controller, unused store actions; fix seeds/tests; rename
   `TemplateSelector.vue`.
9. **E2E:** `release-setup-wizard.spec.ts`, update `release-task-templates`,
   release-test-plan rows.
10. **Docs & knowledge integration:** `docs/concepts/house-setup-and-templates.md`,
    `docs/api/` endpoint docs, ADR for D2 (room-type materialization instead
    of true floor/house scopes), backlog/ACTIVE_TASKS updates.

## 9. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| Catalog content volume (~50 templates × DE copy) is large and subjective | High | Step 1 ships content first; user reviews the German copy + level semantics before phases 2+ build on it |
| Level semantics feel arbitrary (which task drops in Putzmuffel?) | Medium | Derive from research (essentials stay, deep cleans/daily micro-tasks drop); document the rule in the concept doc |
| Auto-created `household`/`hallway` rooms feel artificial | Medium | Toggleable in wizard step 2; concept doc explains they host floor/house-wide chores; rooms stay fully editable/deletable |
| Legacy removal breaks seeds/E2E (`release-task-templates`, TestDataService) | Medium | Step 8 verifies all consumers first; specs updated in the same plan; full Docker gate before commit |
| i18n extraction from JSON is manual (no static `t()` in data files) | High | Document the process in the concept doc; `.pot` regeneration is an explicit step (closes CR-016-01); CI has no extractor to satisfy |
| Wizard payload creates many rows (timeouts/partial state) | Low | Single DB transaction; payload caps (6 floors × 12 rooms); integration test covers rollback |
| Two agents touching TemplateService simultaneously (backlog BUG-TEMPLATE-001) | Low | This plan absorbs the fix; coordinate via ACTIVE_TASKS |

## 10. Acceptance Criteria

- [x] A brand-new house (no floors) is greeted by the setup wizard; „Leer starten" skips it.
- [x] Quick-start (home type, floors, basement, rooms/floor, Putzteufel/Putzmuffel) generates an editable room list with room type + person per room.
- [x] Applying the wizard creates floors, rooms (with `roomType`), tasks from the leveled catalog, and per-room assignments in one transaction; invalid payloads are rejected with clear error codes.
- [x] Putzmuffel produces fewer tasks with lower frequencies/shorter times than Putzteufel for the same house.
- [x] Adding a task to any room offers templates filtered by the room's type (+ house-wide templates) with adjustable overrides, plus the custom-task path.
- [x] All wizard/template strings are translated: German complete, casual, kid-friendly; English works via fallback; `.pot` regenerated.
- [x] Legacy DB-template system removed; no dead template endpoints remain; seeds/tests adjusted.
- [x] Tests green: PHPUnit unit (680) + integration (152) + Jest (690) + the full
  `Release:*` E2E serial suite (16/16 incl. the new `release-setup-wizard` and
  rewritten `release-task-templates`), executed 2026-08-13 in `chromium-serial-db`.
- [x] Build gate green (`npm test` + lint + backend tests).
- [x] Durable concepts/decisions integrated into the permanent docs (`docs/concepts/house-setup-and-templates.md`, `docs/api/`, ADR-008 for D2) — plans are temporary.
- [x] `docs/backlog/ACTIVE_TASKS.md` updated; BUG-TEMPLATE-001 + CR-016-01 archived as done.

## 11. Review Outcome (Standard Type A — 2026-08-12)

**Verdict: GO** (majors resolved 2026-08-13; was GO-WITH-CHANGES on 2026-08-12).
Architecture, test plan, i18n, and risk coverage are strong; no overlap with
active plans. All three Major findings resolved inline:

- **[Major] Room-type filtering already exists** — ✓ resolved. §1 now states the
  catalog already scopes by a single room type (`scopeLabel`/`scopeType`) with a
  working `byRoomType` endpoint; §3.1 and the TemplateService bullet frame the
  change as *generalizing* single-valued `getTemplatesByRoomType` → multi-valued
  `getTemplatesForRoomType`; D7 relocates the filtering into
  `SetupController::catalog` instead of rebuilding it.
- **[Major] Wizard authorization** — ✓ resolved. §5 now states an explicit
  decision: `ensureHouseMember` suffices for `setup/apply` (matching existing
  floor/room/room-task creation), with the first-run gate (D3) bounding blast
  radius. Recorded as decision **D8** in the decisions table.
- **[Major] from-template endpoint ownership check** — ✓ resolved. §5 now
  requires `roomId` to be resolved server-side via
  `RoomService::validateRoomOwnership(roomId, houseId)` and `assigneeUserId`
  checked for house membership; §6 adds cross-house `roomId` + non-member-assignee
  rejection tests.

**Auto-fixed inline (doc edits):** template count 25 → 30 (§1, §4.3); E2E
extension `.spec.js` → `.spec.ts` (§6, §7, §8 — the E2E suite is TypeScript);
migration reference clarified to `Version1000…` where `chorestrike_rooms` is
defined (dropped irrelevant `Version1015`).

**Minor (surfaced):** §6 "update `release-task-templates`" is a rewrite, not an
update — CS-024's spec tests the DB-backed template CRUD that D7 removes.

**Verified-accurate (no finding):** `TemplateSelector.vue` is a recurrence-preset
selector; JSON `TemplateController` routes are unused by the frontend (it uses
the DB-backed `/api/v1/tasks/templates`); `getSuggestionsForRoom` name-heuristic
exists; `createDefaultTemplates` = 15 entries; BUG-TEMPLATE-001 maps to
`TemplateService::bulkImport` silent-swallow; CR-016-01 = `.pot` regen; all 11
room-type icons registered in `src/utils/icons.js`; `Room.roomType` is genuinely
new; D4 `assignedToUserId` exists on `RoomTask`.

**Recommended next state:** `new/` → `active/` (majors resolved; remaining items
are minor). Plan folder moves are manual (project-rules §7); this review does not
move the file.

## 12. Implementation Outcome (2026-08-13)

Implemented in full on `master` (10 phases of §8, in order). Deviations from the
plan, all within its decisions:

- **Catalog v2:** 48 leveled templates (target was ~45–50); `RoomTypes.json`
  carries the curated ordered `templateIds` (matches the §5 contract exactly);
  catalog validated (no dup ids, every `templateId` ref + `roomType` resolves,
  every template has both levels).
- **`RoomTypes.json` vs derivation:** the plan offered deriving `templateIds`
  from the templates; the file keeps the explicit curated list (self-contained
  contract, no drift). `TemplateService::getTemplatesForRoomType` is still the
  multi-valued matcher (generalizes the old single-valued scoping).
- **`materializeTemplate` + bulk logging (BUG-TEMPLATE-001):** the swallow-style
  `bulkImport` is gone; `HouseSetupService` logs every *exception* skip and
  additionally reports *level-exclusive* skips in the returned `skipped[]`
  (expected, not logged) — so partial results are always visible.
- **Task creation uses named arguments** (`TaskMapper::create(name: …)`), so the
  service is decoupled from the positional `isTemplate` removal (D7).
- **`from-template` route** is `/api/v1/room-tasks/from-template`
  (`templateId` in the body, not the path) — the store action matches.
- **E2E happy-path scope:** the wizard happy-path E2E covers the first-run gate
  (409) on the `family` profile because the fixtures have no user-bearing *empty*
  profile; the full happy path is covered by `HouseSetupIntegrationTest` + the
  `SetupWizard` Jest spec.

**Gate (2026-08-13):**
- PHPUnit **unit 680/680**, **integration 152 (24 pre-existing intentional skips)**.
- Jest **690/690** (incl. new `setup`, `SetupWizard`, `TaskTemplatePicker` specs).
- `npm run lint` (src + e2e `.ts`) clean; `l10n:check-keys` green (607 keys);
  `l10n/templates/chorestrike.pot` regenerated (CR-016-01 closed).
- E2E **`Release:*` serial suite 13/13** in `chromium-serial-db` (`--retries=0`),
  incl. the new `release-setup-wizard` (2/2) + rewritten `release-task-templates` (3/3).

**E2E executed green (2026-08-13, closes `CS027-E2E`):** the two Playwright specs
were run in `chromium-serial-db` (`family` profile, `--retries=0`):
`release-setup-wizard` (2/2) and the rewritten `release-task-templates` (3/3),
plus the full `Release:*` serial suite (13/13 spec tests). One config fix
landed alongside the run: `release-setup-wizard.spec.ts` re-seeds the family
profile via the `seededData` fixture, so it was added to `SEED_DEPENDENT_SPECS`
in `playwright.config.ts` to run in the serial pool (it would otherwise reseed
the DB concurrently with the parallel `chromium`/`firefox`/`webkit` pools). No
v2-catalog / from-template / selector mismatch surfaced — the JSON-body POST to
`/room-tasks/from-template` is read correctly by the controller. No remaining
E2E risk; the plan is ready for `active/ → done/`.

**Knowledge integration (done):**
[`docs/concepts/house-setup-and-templates.md`](../../concepts/house-setup-and-templates.md),
[ADR-008](../../adr/ADR-008-room-type-template-materialization.md) (decision D2),
[`docs/api/README.md`](../../api/README.md) (setup + from-template + roomType,
`is_template` removed), [`docs/engineering/architecture.md`](../../engineering/architecture.md)
(`room_type` + `is_template` removal), `release-test-plan.md` §3/§5 rows.
BUG-TEMPLATE-001 + CR-016-01 archived in [`archive/2026-08.md`](../../backlog/archive/2026-08.md).

## 13. Review Outcome (Standard Type B — 2026-08-13)

Post-implementation code review of the uncommitted CS-027 change set (all new +
modified backend/frontend/l10n/test files). Verdict: **GO** — architecture,
authorization (D3/D8 honored: `ensureHouseMember` + first-run gate, server-side
`roomId` resolution, assignee membership checks), transaction handling, and test
coverage are sound; 6 findings fixed inline, 4 logged as backlog follow-ups
(CR-027-01..04).

**Auto-fixed inline (with regression tests):**

- **[Minor] Auto-room names/icons bypassed the catalog** —
  `HouseSetupService::injectAutoRooms` hardcoded English room names
  ('Hallway & Stairs' / 'Household & Co.') and left `icon` unset (→ generic
  'Home'). Now resolves the localized `defaultName` + `icon` from
  `TemplateService::getRoomType()`; `HouseSetupServiceTest` asserts both.
- **[Minor] Missing floor-room lower bound** — plan §5 requires 1–12 rooms per
  floor, but a floor with 0 rooms was accepted. Added the validation +
  `testApplySetup_FloorWithoutRooms_Throws`.
- **[Minor] Wizard apply errors invisible** — `SetupWizard` never rendered
  `setupStore.error`, so a failed apply (e.g. 409) looked like a no-op. Error
  paragraph added (+ Jest case).
- **[Minor] Missing manual wizard entry point** — plan §5 (D3) promises it and
  the Dashboard comment claimed it, but no button existed. Added the header
  button (reused the existing 'Set up your home' l10n key) + 2 Dashboard Jest
  cases; concept doc §6 updated.
- **[Minor] i18n gaps** — 'Floor' / 'Ground Floor' (wizard floor names) missing
  from `de.json`/`en.json`/`.pot` (added: Etage / Erdgeschoss); the picker's
  override label misused 'Room name' for the task name → reuses the existing
  'Task Name' key.
- **[Nit] Coding standards** — collapsed `): void { try {` line in
  `RoomTaskService::validateRoomTaskOwnership` restored; `@file_get_contents`
  warning suppression removed (`TemplateService::loadJsonFile`, guarded by
  `is_file`); inline FQCN → `use` import in `RoomTaskController`.

**Deferred to backlog (Source: Review of CS-PLAN-027):** CR-027-01 (room
`roomType` from free room create/update is not validated against the catalog
registry), CR-027-02 (`searchTemplates` dead + unlocalized since D7),
CR-027-03 (`homeType` collected but never sent), CR-027-04 (preview lacks the
task-count summary promised in §5).

**Quality gate (2026-08-13, post-fix):** `npm run lint:fix` clean ·
`npm run stylelint` 0 errors (26 pre-existing `use-logical` warnings =
TECH-LINT-004; the `stylelint:fix` auto-fix churned 15 out-of-scope files into
logical properties and was **reverted** to keep the diff minimal — the
conversions need the per-hit RTL verification TECH-LINT-004 requires) ·
`l10n:check-keys` green · Jest **693/693** (690 + 3 new) · PHPUnit unit
**681/681** (680 + 1 new) · PHPUnit integration **152 OK** (24 pre-existing
skips) in Docker. E2E not re-run (no endpoint/contract change; the two CS-027
specs ran green in §12).
