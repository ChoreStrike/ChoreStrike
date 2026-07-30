# Code Review — 2026-07-28 (CS-016 Scheduling UI)

> **Tier:** Full Review. **Scope:** CS-PLAN-016 changeset — 6 new Vue files,
> `src/store/scheduling.js`, `src/router.js`, `src/App.vue`, repaired
> `SchedulingControllerTest.php`, `phpunit.xml`, 6 new Jest specs, 3 new E2E
> specs, `l10n/{en,de}.json`, docs.
> **Method:** 4 parallel `reviewer` subagents (correctness, architecture,
> security/auth, perf/quality) + 1 `explore` (security/auth inventory) + **first-
> hand orchestrator verification** of every block/major finding.
> **Source:** Review of CS-016 (`docs/plans/active/cs-plan-016-scheduling-ui.md`).

## Summary

| Severity | Count | Status |
|----------|-------|--------|
| Block | 2 | Both fixed inline (security route removal + data-loss) |
| Major | 7 | 6 fixed inline; 1 deferred (.pot regeneration) |
| Minor | ~10 | Fixed inline or logged below |
| Nit | ~6 | Fixed inline or logged below |

**Quality gate after fixes:** Jest 575/575 (+5 behavioral/regression), ESLint 0
errors (68 pre-existing JSDoc warnings, all in untouched files), `l10n:check-keys`
green. **PHPUnit + E2E acceptance still require Docker** (pending, as before the
review). See the plan's `## Review Outcome (Full Review)` note for the per-item
resolutions.

## Fixed inline (not repeated here — see plan §Review Outcome)

Blocks #1 (smart-schedule route removal, security) and #2 (EnergyBudgetSettings
data-loss watcher); majors #3 (suggestions data), #4 (`int $userId`), #5 (weekday
validation), #6 (init/error handling), #9 (silent energy skip), #10 (shallow
specs), #11 (calendar week-start); minor/nit auto-fixes (#15 JSON fallback, slider
marks, `.week-range` testid, describe label).

## Deferred backlog items

### [P2] CR-016-01 — Regenerate `l10n/templates/chorestrike.pot`
**Status**: Planned · **Effort**: S · **Source**: Review of CS-016 (#7)
The `.pot` template was not regenerated (~90 scheduling keys missing; POT-Creation-Date predates the work). The runtime `en/de.json` ARE updated, but the translator pipeline is broken.
- **AC**: A `l10n:extract` script (or documented Nextcloud translation-tool invocation) exists; `chorestrike.pot` contains every `t()` string in `src/`; `npm run l10n:check-keys` still green.
- **Notes**: `package.json` has only `l10n:check-keys` — no extract step. Decide on the tool (Nextcloud `translationtool` / `l10n` extractor) before estimating.

### [P2] CR-016-02 — Batch the scheduling save endpoints
**Status**: Planned · **Effort**: M · **Source**: Review of CS-016 (#8)
`AvailabilitySettings.saveAvailability` (up to 14 calls) and `EnergyBudgetSettings.saveEnergyBudget` (7 calls) save per-day sequentially. Flicker is mitigated, but the N+1 remains.
- **AC**: A bulk `PUT` endpoint accepts a full week of availability + energy in one request; the two components call it once; loading flicker gone; regression tests updated.

### [P3] CR-016-03 — Split oversized scheduling components
**Status**: Planned · **Effort**: M · **Source**: Review of CS-016 (#12)
`AvailabilitySettings.vue` (694 lines) and `EnergyBudgetSettings.vue` (584 lines) exceed the 300-line guideline (>2×).
- **AC**: Edit modal and budget-analysis extracted into child components; each file ≤ ~300 lines; Jest specs still green.

### [P3] CR-016-04 — DRY scheduling constants & helpers
**Status**: Planned · **Effort**: S · **Source**: Review of CS-016 (#13)
`weekDays` array, `ENERGY_MIN/MAX` (1/20) magic numbers, `isMemberAvailable`/`getMemberEnergy`, and template definitions are duplicated across the 5 components.
- **AC**: Shared `src/constants/scheduling.js` (+ a `weekDays` constant) + a small composable/helpers module; no duplicated weekday/template/energy-range logic.

### [P3] CR-016-05 — Merge SchedulePreview + SchedulingSuggestions
**Status**: Planned · **Effort**: M · **Source**: Review of CS-016 (#14)
The two components have near-identical suggestion-loading + display logic (both now correctly pass `tasksStore.activeTasks`).
- **AC**: One canonical suggestions component (or a shared composable) drives both the preview and the suggestions sections; no duplicated suggestion rendering.

### [P3] CR-016-06 — Locale-aware date formatting in ScheduleCalendar
**Status**: Planned · **Effort**: S · **Source**: Review of CS-016 (#17 remainder)
`weekRange` uses bare `toLocaleDateString()` (system-locale dependent, inconsistent with the app locale). The UTC `isToday` drift and Monday-start are already fixed.
- **AC**: `weekRange` uses Nextcloud's date formatter or explicit translated month/day names; deterministic across locales.

### [P3] CR-016-07 — Archive orphaned SmartScheduler surface
**Status**: Planned · **Effort**: M · **Source**: Review of CS-016 (#1 follow-up)
The 7 `smart-schedule#*` routes were removed (security), so `SmartSchedulerController` + `SmartSchedulerService` + `SmartSchedulerControllerTest`/`AnnotationsTest` + `AvailabilityApiTest` now exercise a route-less surface (dead code). `RouteMatrixTest` still passes (declared→method only).
- **AC**: Decide archive-vs-keep; if archived, move the controller/service/tests to `archive/2026-MM/` and remove the now-orphaned `AvailabilityApiTest` mock dispatcher; if re-added later, gate with `AuthorizationService`.

### [P4] CR-016-08 — Convert SchedulingView to `<script setup>`
**Status**: Planned · **Effort**: S · **Source**: Review of CS-016 (#16)
`SchedulingView` uses Options API while all 5 children use `<script setup>`.
- **AC**: `SchedulingView.vue` rewritten in `<script setup>`; specs green.

### [P3] CR-016-09 — DailyTaskList.vue tech debt (pre-existing)
**Status**: Planned · **Effort**: S · **Source**: Review of CS-016 (#24, observed — not part of this changeset)
`src/components/Scheduling/DailyTaskList.vue` bypasses the API service (raw `axios`), has a dead ternary `task.allowTeamwork ? 'checkbox' : 'checkbox'`, an empty `rescheduleTask` stub, and missing `data-testid`s (CS-015 violation).
- **AC**: Route calls through the store/API service; fix/remove the dead ternary and stub; add testids.

### [P2] CR-016-10 — Regression test for `getConsumedEnergyForUserOnDate` SQL fix
**Status**: Planned · **Effort**: S · **Source**: Docker gate of CS-016 (2026-07-28)
`CompletedTaskMapper::getConsumedEnergyForUserOnDate` used a mangled `select('COALESCE(SUM(...)) AS …')` → SQL 1054; fixed to `selectAlias(createFunction(...), ...)`. Surfaced because the scheduling store had zero consumers before CS-016. The mock-based `CompletedTaskMapperTest` (excluded from the unit suite due to an `IQueryBuilder` mock-setup issue) cannot catch SQL errors; the durable regression needs a real-DB mapper/integration test.
- **AC**: Add a DB-backed test asserting `getConsumedEnergyForUserOnDate` returns the summed `energy_cost` (0 with no completions; >0 with seeded completions). Unblock the integration harness first (`AvailabilityApiTest` `Class OC not found` — same root cause blocking all `tests/integration`).
- **Notes**: Current regression coverage = the E2E "loads scheduling view" spec (fails if `/scheduling/preferences` 500s).

### [P1] CR-016-11 — Scheduling E2E owner-context project
**Status**: Resolved by CS-PLAN-018 (Phase 3b + Phase 7 acceptance, 2026-07-29) · **Effort**: S · **Source**: Docker gate of CS-016 (2026-07-28)
The reintroduced scheduling E2E runs under the `chromium` project as `admin`, who is a lone `member` (house 418) — but 2 specs assert the owner-only member-availability grid (`availability-slot`). Owners are `dad`/`mom` (house 420). So 2/30 specs fail on missing owner data, not on a code defect.
- **AC**: Add a Playwright project (or per-spec `storageState` override) that runs the owner-scoped scheduling specs as `dad`/`mom` (`.playwright/.auth/dad.json`); the 2 specs pass; the `admin`-scoped specs keep passing (25). Re-tick the plan's cross-browser E2E AC.
- **Notes**: Ties into CS-015b's `storageState` auth infra. After this, run chromium/firefox/webkit `--retries=0` to satisfy the AC. **Ownership transfer (2026-07-29):** resolved inside CS-PLAN-018's quality sweep (Phase 3b adds the owner-context project; Phase 4 F4 is the same root cause, fix direction corrected from "seed for admin" to "run as owner"). CS-018's Phase 7 acceptance also re-ticks CS-016's E2E AC.

### [P2] CR-016-12 — Non-owner availability editing surface
**Status**: Planned · **Effort**: M · **Source**: Re-review of CS-016 (2026-07-28)
The member-availability grid is owner-only (`getMemberAvailability` requires `requireHouseOwner`), and the edit button only renders on the current user's member card (`v-if="member.isCurrentUser"`). Non-owners therefore see "No Members Found" and have **no UI to toggle their own weekday availability** — only their energy budget (via `EnergyBudgetSettings`). Plan AC #2 ("a user can view + edit their weekday availability") only holds for owners today.
- **AC**: Non-owners can view and edit their own weekday availability (e.g. a self-availability section driven by `getPreferences` data + the self-service `updateAvailability` endpoint); E2E coverage for the non-owner path.
- **Notes**: Same root cause as CR-016-11 (owner-scoped data). Decide UX before implementing (self-section vs. self-scoped member fetch).

### [P3] CR-016-13 — Scheduling dead code + oversized suggestion payload
**Status**: Planned · **Effort**: S · **Source**: Re-review of CS-016 (2026-07-28)
(a) `SchedulingService::updateMemberAvailability(int $houseId, int $targetUserId, …)` is no longer called by any controller (superseded by `updateMemberAvailabilityByNextcloudUserId`) — dead code kept alive only by its unit test. (b) The store action `updateMemberAvailability` has no UI consumer (the edit modal is self-only and uses the self-service endpoints). (c) `getSuggestions`/`getSchedulingSuggestions` send the full client task array although the backend only uses `count($tasks)`.
- **AC**: Remove or `@deprecated` the int-based service method (+ test); decide whether the store action stays (future owner-edit UX, see CR-016-12) or goes; send `{ taskCount }` instead of full tasks (controller/service signature change).

### [P3] CR-016-14 — AvailabilitySettings save guard + unreachable loading states
**Status**: Planned · **Effort**: S · **Source**: Re-review of CS-016 (2026-07-28)
(a) `AvailabilitySettings` has no `hasChanges` guard — Save with no edits fires up to 14 API calls (`EnergyBudgetSettings` already has one). (b) The component-level `loading` computeds in `AvailabilitySettings`/`EnergyBudgetSettings`/`ScheduleCalendar` read `schedulingStore.loading`, which is now only true during initial load — but the components only mount inside the view's `v-else` (loading=false), so their `loading-state` blocks are unreachable dead UI.
- **AC**: `hasChanges` disables the save button; the unreachable per-component loading blocks are removed (or driven by a meaningful local state).
