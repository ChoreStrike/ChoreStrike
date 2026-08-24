# CS-PLAN-043: Scheduling UI Consolidation

> **Status:** Implemented + Standard-B reviewed (2026-08-24, PASS — see
> `## Review Outcome`); `active/ → done/` move is manual.
> **Effort:** L
> **Priority:** P3
> **Depends on:** none
> **Closes:** CR-016-03/04/05/06/08/13/14, UX-014, TECH-DRY-001
>
> **Implementation notes (deviations & decisions):**
> - Merged component named **`SchedulePlanner.vue`** with root testid
>   `schedule-planner`. The twin surfaces became one section, so the four E2E
>   assertions were updated to the new DOM (sanctioned by §4): preview
>   assertions target `schedule-planner`, and suggestions assertions now load
>   the planner first (`POST /scheduling/suggestions`) because the suggestions
>   block renders only after a successful load — matching the old empty-state
>   behavior of both predecessors.
> - The shared "composable" from §3 was **inlined** into SchedulePlanner: after
>   the merge there is exactly one consumer left, and a single-consumer
>   composable would violate KISS/rule-of-three.
> - Meeting the <300-line criterion for `EnergyBudgetSettings` required a second
>   extraction beyond the manifest: **`WeekBudgetGrid.vue`** (per-day slider/
>   input/indicator grid, delta-emitting). BudgetAnalysis alone moved only ~90
>   of 592 lines.
> - Apply semantics: per-day button applies the current user's fair share of the
>   suggested day's energy (`round(totalEnergy / availableMembers)`, clamped to
>   ENERGY_MIN/MAX), forces own availability on that day, persists via one bulk
>   self PUT; the resulting share is shown inline *before* persisting.
> - UX-014 default confirmed as planned: every member card in the owner grid has
>   Edit; non-self members save through the refined `updateMemberPreferences`
>   store action (`PUT /scheduling/members/{userId}`). E2E edit-button locators
>   scoped with `.first()` because the DOM now renders one button per card.

## 1. Problem Summary

The scheduling UI accumulated structural debt across three review rounds:
`AvailabilitySettings.vue` (734 lines) and `EnergyBudgetSettings.vue` (592)
exceed the 300-line guideline more than twofold (CR-016-03; counts re-verified
2026-08-23 post-CS-044, which edited these components in their current shape); `weekDays`,
energy bounds and member helpers are duplicated across five components
(CR-016-04) and the weekend fallback energy budget (mon-fri 10, sat/sun 15)
lives in three places — scheduling store `getCapacityForDay`,
`MemberFairnessTable.vue`, `getTotalEnergyForDay` (TECH-DRY-001);
SchedulePreview and SchedulingSuggestions duplicate suggestion loading/display
and suggestions carry no "apply" action (CR-016-05, UX-014);
ScheduleCalendar formats dates via bare system-locale-dependent
`toLocaleDateString()` (CR-016-06); dead code remains (unused store action,
oversized suggestion payload sending full task arrays though the backend only
counts them, dead backend service overload kept alive by its test,
CR-016-13); saves fire without changes and per-component loading states are
unreachable (CR-016-14); owners face two parallel editors for their own
availability while `PUT /scheduling/members/{id}` is unused (UX-014);
`SchedulingView.vue` alone still uses Options API (CR-016-08).

## 2. Strategy

Extraction-first, refactor-second: pull shared constants/helpers into
`src/constants/scheduling.js` + `src/utils/energyBudgets.js` so subsequent
component splits are mechanical. Merge the twin suggestion surfaces into one
canonical component whose suggestions gain a real Apply action writing via the
CS-PLAN-033 bulk preferences PUT. Split the two oversized components along
their natural modal/analysis seams. Delete dead code at both layers. Resolve
UX-014's redundancy: owners edit their own availability in the same
`SelfAvailabilitySettings` members use; the owner member-grid either gains a
working edit path (reuse the extracted editor for a target member via the
existing member-preferences PUT) or is dropped as read-only decoration —
default: wire the edit path and keep the endpoint.

## 3. Backend / Frontend Changes

- **Backend** (`lib/`): remove dead
  `SchedulingService::updateMemberAvailability(int...)` overload and its
  keeper test (CR-016-13a). Slim the `POST /scheduling/suggestions` contract:
  `SchedulingController::getSuggestions` +
  `SchedulingService::getSchedulingSuggestions` accept `{ taskCount }` instead
  of the full task array (the backend only ever used `count($tasks)`)
  (CR-016-13c backend half).
- **Frontend** (`src/`):
  - `src/constants/scheduling.js`: `weekDays`, `ENERGY_MIN`, `ENERGY_MAX`,
    `isMemberAvailable`, `getMemberEnergy` — single source replacing the
    five-component duplication (CR-016-04).
  - `src/utils/energyBudgets.js`: fallback budget map + weekday list,
    consumed by `getCapacityForDay`, `MemberFairnessTable.vue` fairness rows,
    and `getTotalEnergyForDay` (TECH-DRY-001).
  - Merge `SchedulePreview.vue` + `SchedulingSuggestions.vue` into one
    component (+ shared composable for loading); suggestions gain Apply ->
    bulk `PUT /scheduling/preferences` (preview shows the resulting
    distribution before persisting).
  - Extract `AvailabilityEditModal.vue` from `AvailabilitySettings.vue` and
    `BudgetAnalysis.vue` from `EnergyBudgetSettings.vue`; both parents under
    300 lines afterwards (CR-016-03).
  - `ScheduleCalendar.vue`: locale-aware formatting via Nextcloud's date
    formatter or explicit translated names from the shared constants
    (CR-016-06).
  - Save guards: `hasChanges` snapshot comparison before bulk saves; delete
    unreachable loading-state blocks (CR-016-14).
  - Slim `getSuggestions` request to `{ taskCount }`; remove the unused
    `updateMemberAvailability` store action (CR-016-13b/c).
  - `SchedulingView.vue` -> `<script setup>` (CR-016-08).

## 4. Tests

- **Jest**: constants/utils (weekend 10/15 preserved), merged suggestion
  component (load, display, apply payload), save-guard logic, calendar
  formatting pinned for de/en locales, slimmed request shape.
- **E2E**: `scheduling-owner` profile stays green (baseline 14/14 — re-run
  once before step 1, since CS-PLAN-044 edited scheduling components after the
  last scheduling E2E run) — update selectors only where DOM actually changes;
  keep `data-testid`s stable.
- **PHPUnit**: dead overload removal with its test; suggestions
  controller/service tests updated for the `{ taskCount }` contract; suite
  counts reconciled.

## 5. File Manifest

```
new:      src/constants/scheduling.js
new:      src/utils/energyBudgets.js
new:      src/components/Scheduling/AvailabilityEditModal.vue
new:      src/components/Scheduling/BudgetAnalysis.vue
new:      src/components/Scheduling/<merged Preview+Suggestions component> (name TBD)
deleted:  src/components/Scheduling/SchedulePreview.vue     (merged successor)
deleted:  src/components/Scheduling/SchedulingSuggestions.vue (merged successor)
modified: src/components/Scheduling/AvailabilitySettings.vue
modified: src/components/Scheduling/EnergyBudgetSettings.vue
modified: src/components/Scheduling/SelfAvailabilitySettings.vue
modified: src/components/Scheduling/ScheduleCalendar.vue
modified: src/components/Insights/MemberFairnessTable.vue
modified: src/views/SchedulingView.vue
modified: src/store/scheduling.js
modified: lib/Service/SchedulingService.php                 (dead overload removal + suggestions contract)
modified: lib/Controller/SchedulingController.php           (suggestions contract slimming)
modified: docs/api/README.md + docs/concepts/ui-use-cases.md (contract + UC-status updates)
modified: tests/...                                          (as above)
archived: -
```

## 6. Implementation Order

1. Constants + energy-budget extraction; all consumers switched; Jest green.
2. Dead-code sweep: backend overload, store action, payload slimming.
3. Save guards + unreachable loading-state removal.
4. Merge Preview/Suggestions + Apply action.
5. Component splits (edit modal, budget analysis).
6. Calendar locale-aware formatting.
7. `SchedulingView` -> `<script setup>`; UX-014 redundancy resolution
   (wiring the owner member-grid edit path re-introduces a store action for
   `PUT /scheduling/members/{userId}` — the action deleted in step 2 is dead
   only until then).
8. Full gate incl. Docker E2E scheduling profiles.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| E2E breakage from DOM restructuring | High | Stable `data-testid`s; extraction-first keeps templates intact initially |
| Apply-action semantics unclear (which day/member) | Medium | Apply persists exactly the suggested distribution; preview diff before PUT |
| Behavior drift when merging the twin components | Medium | Characterize current behavior with tests before merging |
| RTL/date-format regressions | Low | Unit tests pin formatter output for de/en locales |
| Split/merge regresses CS-044 a11y contracts | Low | Build `AvailabilityEditModal` on the shared `Modal.vue` (ESC, focus trap, stacked-modal discipline); re-verify keyboard access on touched surfaces |

## 8. Acceptance Criteria

- [x] `AvailabilitySettings` and `EnergyBudgetSettings` each under 300 lines
      after extraction; constants/helpers exist once (remaining over-guideline
      components `ScheduleCalendar` 489 / `SelfAvailabilitySettings` 311 are
      out of scope here) — verified post-review: 263 / 297 lines
- [x] Weekend fallback budget defined exactly once (Jest-covered 10/15)
- [x] Suggestions are actionable (Apply persists via bulk PUT); twin surfaces
      merged into one canonical component
- [x] Dates render locale-aware; no bare `toLocaleDateString()`
- [x] Dead overload/action/payload gone; saves no-op without changes
- [x] `scheduling-owner` E2E green; build gate green
- [x] New/changed user-visible strings (Apply action, modal/analysis labels)
      use `t()`; l10n de/en synced (`npm run l10n:check-keys`)
- [x] Docs updated in the same change: `docs/api/README.md` (slimmed
      suggestions payload), `docs/concepts/ui-use-cases.md` (member-PUT row
      wired — resolves its keep-or-prune marker — and merged suggestions
      component); other durable outcomes integrated per plan template
- [x] CR-016-03/04/05/06/08/13/14 + UX-014 + TECH-DRY-001 archived
- [x] `ACTIVE_TASKS.md` updated

## Review Outcome

**Standard-B review 2026-08-24 — PASS.** Scope: all uncommitted CS-043
changes (2 backend files, 11 frontend files, 6 new files, tests, docs, l10n).

Checklist: architecture clean (thin controller, logic in service, no SQL
touched); authorization preserved (`ensureHouseMember` on suggestions,
owner-only member PUT untouched); new input validated (`taskCount < 0` → 400,
matching the codebase's typed-scalar-param convention); logging via
`logger.js`/`AppLogger` patterns; i18n complete (6 new keys de/en synced);
test scope matches risk (unit specs for every new component/util, save-guard
regression, negative-count 400, de/en locale pins, E2E DOM updates).

Auto-fixes applied (3):

1. `AvailabilitySettings.vue` — removed dead `isCurrentUser` mapping +
   `currentUser`/`onMounted`/`resolveCurrentUser`/`logger` leftovers (UX-014
   moved the self-resolution into the modal; the sweep also closes the last
   trace of BUG-E2E-002's root cause).
2. `scheduling.js` `normalizeDaySuggestions` — replaced the sixth copy of the
   translated weekday-name map with the shared `weekDays` constant
   (CR-016-04 single-source intent).
3. `SchedulePlanner.vue` — corrected a stale "Null until the first load"
   comment on the boolean `hasLoaded` ref.

Deferred: **BUG-UI-001** logged (pre-existing week-total/analysis display
garbling while an energy input is empty/invalid — display-only, save is
validation-guarded). **BUG-E2E-002** closed as resolved by this plan →
`archive/2026-08.md`.

Gate re-verified after fixes: lint + stylelint 0/0, l10n clean, Jest 830/830
+ noise gate clean, webpack build green, Docker PHPUnit unit 795 + integration
190 [24 pre-existing skips].
