# CS-PLAN-043: Scheduling UI Consolidation

> **Status:** Plan (not started)
> **Effort:** L
> **Priority:** P3
> **Depends on:** none
> **Closes:** CR-016-03/04/05/06/08/13/14, UX-014, TECH-DRY-001

## 1. Problem Summary

The scheduling UI accumulated structural debt across three review rounds:
`AvailabilitySettings.vue` (694 lines) and `EnergyBudgetSettings.vue` (584)
exceed the 300-line guideline more than twofold (CR-016-03); `weekDays`,
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
  keeper test (CR-016-13a).
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
- **E2E**: `scheduling-owner` profile stays green (baseline 14/14) — update
  selectors only where DOM actually changes; keep `data-testid`s stable.
- **PHPUnit**: dead overload removal with its test; suite counts reconciled.

## 5. File Manifest

```
new:      src/constants/scheduling.js
new:      src/utils/energyBudgets.js
new:      src/components/Scheduling/AvailabilityEditModal.vue
new:      src/components/Scheduling/BudgetAnalysis.vue
deleted:  src/components/Scheduling/SchedulePreview.vue     (merged successor)
deleted:  src/components/Scheduling/SchedulingSuggestions.vue (merged successor)
modified: src/components/Scheduling/AvailabilitySettings.vue
modified: src/components/Scheduling/EnergyBudgetSettings.vue
modified: src/components/Scheduling/SelfAvailabilitySettings.vue
modified: src/components/Scheduling/ScheduleCalendar.vue
modified: src/components/Insights/MemberFairnessTable.vue
modified: src/views/SchedulingView.vue
modified: src/store/scheduling.js
modified: lib/Service/SchedulingService.php                 (dead overload removal)
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
7. `SchedulingView` -> `<script setup>`; UX-014 redundancy resolution.
8. Full gate incl. Docker E2E scheduling profiles.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| E2E breakage from DOM restructuring | High | Stable `data-testid`s; extraction-first keeps templates intact initially |
| Apply-action semantics unclear (which day/member) | Medium | Apply persists exactly the suggested distribution; preview diff before PUT |
| Behavior drift when merging the twin components | Medium | Characterize current behavior with tests before merging |
| RTL/date-format regressions | Low | Unit tests pin formatter output for de/en locales |

## 8. Acceptance Criteria

- [ ] No scheduling component exceeds 300 lines; constants/helpers exist once
- [ ] Weekend fallback budget defined exactly once (Jest-covered 10/15)
- [ ] Suggestions are actionable (Apply persists via bulk PUT); twin surfaces
      merged into one canonical component
- [ ] Dates render locale-aware; no bare `toLocaleDateString()`
- [ ] Dead overload/action/payload gone; saves no-op without changes
- [ ] `scheduling-owner` E2E green; build gate green
- [ ] CR-016-03/04/05/06/08/13/14 + UX-014 + TECH-DRY-001 archived
- [ ] `ACTIVE_TASKS.md` updated
