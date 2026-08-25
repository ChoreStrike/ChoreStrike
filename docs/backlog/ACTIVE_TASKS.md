# Active Tasks Registry

> **Purpose:** Live coordination of work **in progress right now** plus a short
> rolling "Recently Completed" window. This is *not* a history log.
> **Update Rule:** every agent updates this file on start / pause / complete.
> **Housekeeping Rule:** move a completed entry to
> [`archive/YYYY-MM.md`](archive/) once it is older than ~2 weeks or beyond the
> ~10-entry rolling cap. **Open work** (bugs / features / debt / follow-ups)
> lives in [`backlog.md`](backlog.md), **not** here.

## In Progress

<!-- When you start work, add an entry here in this shape:
### CS-PLAN-NNN — Title
**Status**: In Progress · **Started**: YYYY-MM-DD · **Branch**: master
**Plan**: docs/plans/active/cs-plan-NNN-…md · **Blockers**: none
**Scope**: one line on what this turn is doing.
-->

## Recently Completed (rolling — last ~2 weeks / max 10)

- **CS-PLAN-046** — Backend Test Integrity & i18n Quick-Wins (closed **CR-016-10** + **CR-SVC-52** + **CR-SVC-37** + **CR-027-02**; D1=delete signed off): real-DB `CompletedTaskMapperEnergyTest` (zero/sum/day-boundary/cross-user, Docker family profile) for the fixed energy SQL; dispatch-after-commit ordering assertion in `RoomServiceTest` with mutation check (red→green verified); i18n sweep wrapping all client-visible literals (`TaskService::validateTaskData` ×10, `MinigameService::purchaseBoost` incl. printf-positional insufficient-coins message, `RoomController` ×7 VALIDATION_ERROR) with injected `IL10N`; dead `TemplateService::searchTemplates` + its 4 tests deleted (grep-verified; concept doc updated); l10n en/de/pot synced (pot fully regenerated). Gate: PHPUnit unit 834 / integration 200 [24 pre-existing skips] / Jest 831 / lint + stylelint 0/0 / l10n:check-keys clean. Standard-B review 2026-08-25: **PASS** — 1 auto-fix (`RoomServiceTest` `$callOrder` explicit initialization), **CR-SVC-62** logged (remaining client-visible English literals across the rest of the API surface — CR-SVC-37 remainder), gate re-verified (lint/stylelint/l10n clean, Jest 831, Docker PHPUnit 834/200 [24 pre-existing skips], `CompletedTaskMapperEnergyTest` 4/4). (2026-08-24, Done — plan moved to `done/`) → [`../plans/done/cs-plan-046-backend-test-integrity-i18n-quick-wins.md`](../plans/done/cs-plan-046-backend-test-integrity-i18n-quick-wins.md)

- **CS-PLAN-045** — Backend Hardening & Correctness Sweep (closed **BUG-SEC-002** + **CR-027-01** + **CR-016-15** + **CR-SVC-19/32/33**; D1=delete/D2=reject-400/D3=403+409 signed off): server-side role guards in `HouseService::updateUserRole` (self-demotion 403 `CANNOT_DEMOTE_SELF`, last-owner 409 `CANNOT_DEMOTE_LAST_OWNER` via new `ConflictException` + `countOwnersForHouse`; thin controller; real-DB `RoleGuardApiTest`); roomType catalog + length validation (400); single `assertValidEnergyBudget` source for all three bounds sites; reward-config clamping on read+write with `AppLogger` warnings; calendar-day `calculateDaysEarly` (fixed-date DST/midnight pins, injectable `$now`); dead reward-less `TaskService` completion pair deleted. Gate: PHPUnit unit 838 / integration 196 [24 pre-existing skips] / Jest 831 / lint + stylelint 0/0 / noise + l10n + cs:check clean / webpack green / E2E members-owner 6/6 (`--retries=0`). Standard-B review 2026-08-24: **PASS** — 0 code findings; 2 doc auto-fixes (stale plan-043 `active/`→`done/` links in ACTIVE_TASKS + archive), CR-SVC-37 scope note appended (unwrapped RoomController VALIDATION_ERROR messages, per-plan style decision); gate re-verified (lint clean, Jest 831, Docker PHPUnit 838/196 [24 pre-existing skips]). (2026-08-24, Done) → [`../plans/done/cs-plan-045-backend-hardening-correctness-sweep.md`](../plans/done/cs-plan-045-backend-hardening-correctness-sweep.md)

- **CS-PLAN-043** — Scheduling UI Consolidation (closed **CR-016-03/04/05/06/08/13/14** + **UX-014** + **TECH-DRY-001**): E2E baseline re-run first (scheduling-owner 28 passed / 3 skipped, post-CS-044); extracted `src/constants/scheduling.js` (translated `weekDays`, `ENERGY_MIN/MAX`, `isMemberAvailable`, `getMemberEnergy`) + `src/utils/energyBudgets.js` (canonical 10/15 fallback map — single source for `getCapacityForDay`/`getTotalEnergyForDay`/MemberFairnessTable/components, Jest-pinned); dead-code sweep (backend `updateMemberAvailability(int…)` overload + keeper test removed; store action re-created refined as `updateMemberPreferences`; suggestions contract slimmed to `{ taskCount }` both layers incl. negative-count 400); save guard via effective-week snapshot in the edit modal + unreachable per-component loading blocks deleted; SchedulePreview+SchedulingSuggestions merged into `SchedulePlanner.vue` with per-day Apply (fair-share preview inline before persisting, bulk self PUT); component splits: `AvailabilityEditModal.vue` (734→241 parent), `BudgetAnalysis.vue` + extra seam `WeekBudgetGrid.vue` (592→250 parent, manifest deviation recorded); calendar dates locale-aware (`getLocale()`+Intl range, Nextcloud `getDayNames()` headers, de/en shapes pinned); SchedulingView → `<script setup>`; UX-014 wired: every member card Edit → shared modal (self = bulk preferences PUT, others = member PUT), planner Apply persists suggested distribution; l10n de/en synced (6 new keys). E2E selector updates where DOM changed (planner testid, suggestions load-first) and the three racy edit-modal tests un-skipped → now real passes. Gate: Jest 830 / lint + stylelint 0/0 / noise clean / l10n clean / webpack build green / PHPUnit unit 795 + integration 190 [24 pre-existing skips] / Docker E2E scheduling-owner **31/31**, self-availability-member 4/4, insights-flow 3/3 (`--retries=0`). Standard-B review 2026-08-24: PASS — 3 auto-fixes (AvailabilitySettings dead `isCurrentUser`/`resolveCurrentUser` leftovers swept — closes the last trace of **BUG-E2E-002**, now archived as resolved; `normalizeDaySuggestions` sixth weekday-name map → shared `weekDays` constant; stale `hasLoaded` comment), **BUG-UI-001** logged (pre-existing display-only total garbling on empty/invalid energy input), gate re-verified (lint/stylelint/l10n/noise clean, Jest 830, webpack green, Docker PHPUnit 795/190 [24 pre-existing skips]). (2026-08-24, Done — plan file updated with implementation notes + `## Review Outcome`, moved to `done/`) → plan: [`../plans/done/cs-plan-043-scheduling-ui-consolidation.md`](../plans/done/cs-plan-043-scheduling-ui-consolidation.md)

> One-line entries only — full scope lives in the linked plan. Older completed
> work → [`archive/`](archive/) (e.g. [`archive/2026-07.md`](archive/2026-07.md)).

- **CS-PLAN-044** — A11y & Visual Consistency (closed **UX-007/010/012** + **TECH-LINT-004** + **FEAT-UX-030/031**): `Modal.vue` a11y contract (ESC, focus trap + restore, stacked-modal top-most discipline, `aria-modal`, body scroll lock) with OverdueClaimDialog migrated onto it; chip/weekday/toggle keyboard access restored via shared `.cs-visually-hidden` clip pattern + `:focus-within` rings (new `src/styles/a11y.css`); ≥44px targets + `aria-label`s across TaskList/FloorList/RoomDetail/CalendarView; unified vocabulary (High/Normal/Low, Flexible/Fixed schedule, Admins only) codified in design.md §7 with the child-surface decision recorded (no); token replacement in swept components + mobile.css dead-selector sweep (~90 lines, grep-verified); TECH-LINT-004 conversion audit closed the two real RTL regressions (toggle-knob `[dir='rtl']` override); shared `EmptyState.vue`/`ErrorState.vue` (offline via `navigator.onLine` + online/offline events) applied to Dashboard/TodayView/TaskList; l10n de/en synced. E2E infra root-fix: `dashboard.spec.ts` → `SEED_DEPENDENT_SPECS` (room-ID 404s under concurrent reseed; stash-verified pre-existing). Gate: Jest 806 / lint + stylelint 0/0 / webpack build green / l10n clean / E2E smoke + dashboard + today-view 24/24 (`--retries=0`); keyboard walkthrough verified live in the Docker instance. Standard-B review 2026-08-23: PASS — 6 auto-fixes (OverdueClaimDialog close-forwarding + 2 regression specs, 7 missing l10n keys en+de, OvercapacityWarning Medium→Normal vocabulary, completed-check `--color-success-text`, Modal ariaLabel JSDoc, Settings.spec logger mock restoring the noise gate), **UX-016/017** logged, gate re-verified (lint/stylelint/l10n/noise clean, Jest 808). (2026-08-23, Done) → [`../plans/done/cs-plan-044-a11y-visual-consistency.md`](../plans/done/cs-plan-044-a11y-visual-consistency.md)

- **CS-PLAN-042** — Structure Management & Role Gating (closed **UX-004/008/009/011** + **CR-SVC-61**): new `POST /api/v1/room-tasks/bulk-assign` (route + thin controller → batched `RoomTaskService::bulkAssignTasks`; real-DB integration tests for scoping/isolation/400/403); TaskList selection checkboxes + bulk-assign bar (one call, partial-success toast); Settings is the sole owner-gated structure surface (role-gated House/Floors/Danger/Members sections, floor up/down reorder via `POST /floors/reorder`, inline per-floor room add/delete) while the Dashboard FloorList is read-only; RoomDetail header gains owner-only room rename/delete (styled confirm); checklist editor unlocked for owners with the checklist store adapted to the backend contract (`label`/`sortOrder`, `itemOrders`, `{items, stats}` envelope); wizard: house-name step (`PUT /house` after apply), per-house persistent "Start empty" dismissal, single-member assignee-dropdown guard. Docs: architecture.md (bulk endpoints + single-surface rule), ui-use-cases.md (UC-01/02/03/04/08/09, gap index, route ledger → 110). Gate: PHPUnit unit 795 / integration 190 [24 pre-existing skips] / Jest 786 / lint + stylelint 0 errors / webpack build green / l10n clean / **Docker E2E 34/34 green (`--retries=0`; structure specs repointed to owner actor `mom`, serial-pooled, duplicate-floor race + two-level room-picker handling fixed)**. Standard-B review 2026-08-22: PASS — 3 auto-fixes (checklist `initializeProgress` payload-less-envelope TypeError + regression spec, plan manifest gained `checklist.js`, Settings.spec user-store mock noise), gate re-verified (lint/stylelint/l10n clean, Jest 787, PHPUnit 795/190 [24 pre-existing skips], BulkAssign 8/8) (2026-08-22, Done) → [`../plans/done/cs-plan-042-structure-management-role-gating.md`](../plans/done/cs-plan-042-structure-management-role-gating.md)

- **CS-PLAN-041** — UI Honesty & Feedback (closed **UX-005/006/013**): Leaderboard period filter removed (backend takes no period param); weekly medals store-driven (`checkMedals` pre-pass + `fetchMedals` on mount, celebrate-once guard kept); Dashboard binds QuickStats `toggle-private-filter` → TaskManager `?visibility=private` client-side scope; global `UndoSnackbar` (~5 s) + roomTasks `uncompleteRoomTask`/`undoRoomTaskCompletion` (uncomplete + conditional reactivate; rewards not reverted, accepted); shared `ConfirmDialog.vue` replaces native `confirm()` in TaskManager/RoomDetail/Settings/FloorList/TaskChecklist (+MemberList migration); Settings saves toast success/error; minigame store fetches `GET /minigames/boosts` catalog, `BOOST_COSTS` deleted, purchases blocked without it; l10n de/en updated. Gate: PHPUnit unit 795 / integration 179 [24 pre-existing skips] / Jest 764 / lint+stylelint 0 errors / l10n clean / E2E leaderboard 10/10 + dashboard/private-tasks 11/11 + task-completion 8/8 (incl. undo) + members-owner 6/6 + snake-game 15/15 + release-boost-shop 4/4. Standard-B review 2026-08-22: PASS — 1 auto-fix (FloorList delete-error toast + failure-path spec), UX-015 (undo slot) logged, gate re-verified (Jest 765, lint/stylelint/l10n/noise clean). (2026-08-22, Done) → [`../plans/done/cs-plan-041-ui-honesty-feedback.md`](../plans/done/cs-plan-041-ui-honesty-feedback.md)

- **CS-PLAN-040** — Transparency & Insights UI (closed **FEAT-UX-016/017/020/022** + **CR-SVC-31** + roomStats cross-house fix): new house-scoped `GET /house/activity` (`ActivityService`, batch null-safe enrichment) rendered by new `/insights` route (HouseStatsCards over the now-live `GET /house/stats`, capacity-normalized MemberFairnessTable with contribution/coins split, ActivityFeed); freeze badges on Leaderboard via newly serialized `lastFreezeDate`; overcapacity warning (`plannedEnergyForDay`/`overcapacityDays` getters → `OvercapacityWarning` + ScheduleCalendar markers); pruned dead `user#stats`/`userTasks`/`userStats` (+ orphaned `getUpcomingTasksForUser`/`RoomTaskMapper::findByUserId`), room-stats completionRate now counts real completions; RoomDetail stats card. Gate: PHPUnit unit 795 / integration 179 [24 pre-existing skips] / Jest 737 / lint+stylelint 0 errors / l10n clean / E2E insights-flow 3/3 + regression suites green. (2026-08-21, Done — Standard-B review 2026-08-21: checklist clean; 5 auto-fixes [corrupted mapper docblock, NotFoundException import, missing ActivityFeed l10n key, orphaned test docblock, cs:fix PHPDoc alignment]; weekend fallback-budget map triplication logged as **TECH-DRY-001**; gate re-verified green incl. Docker PHPUnit 795/179) → [`../plans/done/cs-plan-040-transparency-insights-ui.md`](../plans/done/cs-plan-040-transparency-insights-ui.md)

- **CS-PLAN-039** — UI Use-Case Inventory — Completion & Gap Registration (docs-only): route coverage appendix in `ui-use-cases.md` (all 111 routes accounted for exactly once: 91 UC rows + 19 appendix routes — create aliases/duplicates, index/show reads, unused room-task variants incl. `due-within-days`, `GET /checklists/complete`, 3 test-only — + `page#index` exclusion; scripted cross-check), uniform dead-store-wrapper annotations on all ❌/⚠️ rows (13 wrappers, grep-verified against `src/`), new *Whitespace* section (Group A: 7 documented-intent candidates, Group B: 9 review ideas, with decision tags + concept-doc sources), stale UX-011 text fixed (invite step shipped with CS-PLAN-038), and FEAT-UX-016…031 registered in `backlog.md` → new themed section with acceptance criteria. Gap index + *Last verified* footer refreshed. Links resolve; build gate unaffected. `active/ → done/` move executed. (2026-08-21, Done) → [`../plans/done/cs-plan-039-ui-use-case-inventory-completion.md`](../plans/done/cs-plan-039-ui-use-case-inventory-completion.md)

- **Backend-vs-Frontend Audit + UI Use-Case Inventory** (docs-only): verified all 111 routes against the frontend — 56 have no live consumer (27 never called, 29 behind dead store wrappers; logger-string false positives excluded, all load-bearing claims file-verified); findings captured neutrally (no prune decisions) in the new [`concepts/ui-use-cases.md`](../concepts/ui-use-cases.md) — 14 use cases (UC-01…UC-14) mapping every GUI action to entry point, endpoint, and status (✅/⚠️/❌/⛔) with a gap index into UX-001…014 / FEAT-UX-015 / BUG-SEC-002. Supersedes the "~35 endpoints" estimate of the 2026-08-20 usability review; also confirmed post-CS-PLAN-038 state (invite/role/remove now wired via `MemberList`, `filtered-daily` live via TodayView). (2026-08-21, Done)

- **CS-PLAN-038** — Core UX: Task Creation, Today View & Member Management (closed **UX-001/002/003** + **CR-016-09**; D1=Remove, D2=Archive, D3=My tasks signed off): unified task creation (TaskEditForm room picker + assignee chips, scope selector removed; `createTaskWithRoomTask` chain with partial-success toasts; RoomDetail custom tab + slim `RoomTaskEditModal` persisting due date + assignees), new `/today` TodayView (dedicated `dailyTasks` store slice, default My-tasks filter, recurring completions leave the list, Dashboard stat cards link in), owner-gated Members section (invite/role/remove + styled confirms, self-role guard) + wizard invite step 4; TaskForm/DailyTaskList archived. Logged **BUG-SEC-002** (updateRole self/last-owner guard — frontend guard only) + **BUG-E2E-003** (title-selection flake, stash-verified pre-existing); leave-house spun off as **FEAT-UX-015**. Gate: Jest 714 / lint + stylelint 0 errors / l10n clean / PHPUnit unit 785 / integration 175 [24 pre-existing skips] / E2E Release:* 21/21 + new specs 8/8 (gui-scratch-flow, task-completion, private-tasks migrated to unified flow). (2026-08-21, Done — Standard-B review 2026-08-21 clean; one auto-fix: overdue→Today section move on recurring completion + regression test; Overdue card links `?scope=all` instead of pre-filter, accepted) → [`../plans/done/cs-plan-038-core-ux-task-creation-today-members.md`](../plans/done/cs-plan-038-core-ux-task-creation-today-members.md)

- **Usability & UI Review** (docs-only, no code changes): documented all workflows (setup wizard → structure → task creation/assignment/completion → scheduling/gamification), verified UI vs. the 111 backend endpoints (~35 without UI consumer; member CRUD entirely button-less; TaskManager "New Task" creates invisible task definitions; floor drag-reorder never existed despite store support). 14 findings logged as **UX-001…UX-014** in `backlog.md`; full record in [`archive/USABILITY_REVIEW_2026-08-20.md`](archive/USABILITY_REVIEW_2026-08-20.md). (2026-08-20, Done)

- **CS-PLAN-037** — Bulk Write-Path Batching (closed **CR-SVC-60** + **CR-SVC-11 write remainder**; D1=YES/D2=DELETE signed off): `resetHouse` subquery UPDATE (3+N_f+N_r→3), orphan-free transactional delete cascades for `deleteRoom`/`deleteRoomTask`/`deleteFloor` (~constant, shared `RoomService::deleteRoomTaskDependents`; `FloorMapper::deleteWithRooms` architecture violation removed), `reorderFloors` CASE-WHEN bulk update (1+2N→2, no re-fetch), `bulkAssignTasks` batch pre-validation via `findByIdsScopedToHouse` (≈7N→3+N, per-item isolation preserved; non-member target now fails up front). Root-cause fix surfaced by the new real-DB suite: checklist mappers + `TestDataService` referenced table names that never existed (`chorestrike_room_task_checklist_progress`/`chorestrike_checklist_items` → migration-truth `chorestrike_chk_progress`/`chorestrike_chk_items`; ChecklistController would have 500'd). Write idioms 4–6 documented in `architecture.md`. Gate: PHPUnit unit 785 / integration 175 [24 pre-existing skips] / Jest 717 / lint + stylelint 0 errors + cs:check + l10n clean / E2E `release-*` 16/16. (2026-08-20, Done — Standard-B review 2026-08-20 clean, no findings/auto-fixes; moved to `done/`) → [`../plans/done/cs-plan-037-bulk-write-batching.md`](../plans/done/cs-plan-037-bulk-write-batching.md)

- **CS-PLAN-036** — Batch Query Optimization (closed **CR-SVC-50/55/56** + **CR-SVC-11 read-path**; split write-path remainder into **CR-SVC-60**): N+1 elimination across service read paths via the three in-repo batch idioms (IN-map `findByIds`/`findByUserIds`/`findByHouseIdAndNextcloudUserIds`, grouped aggregates `getUserStatsByHouse`/`getMaxRoomCompletionCountByHouse`, house-scoped JOIN `findPendingSyncsForHouse`); `handleTaskCompletion` takes the locked `RoomTask` entity + one merged pre-insert 10-min combo-window fetch; real-DB `BatchQueryRegressionTest` (skip-on-missing, cross-house isolation, limit/order, grouped-vs-per-user equivalence) — which exposed and fixed `getUserStats`/`getMaxRoomCompletionCountByUser` aggregates being quoted as columns (`createFunction` fix). Behavior-neutral; batch patterns documented in `docs/engineering/architecture.md`. Gate: PHPUnit unit 761 / integration 169 [24 pre-existing skips] / Jest + lint green. (2026-08-20, Done — Standard-B review 2026-08-20 clean, no auto-fixes; one minor scope note: `CalendarDayCell.spec.js` UTC-date fix to be committed as its own `test:` commit) → [`../plans/done/cs-plan-036-query-batch-optimization.md`](../plans/done/cs-plan-036-query-batch-optimization.md)

- **CS-PLAN-035** — AppLogger Migration (closed **CR-SVC-13**, split off **CR-SVC-59** for the ~16 logger-less services): the 5 raw-`LoggerInterface` services (`NotificationService`, 3 streak services, `TaskDueReminderService`) now inject `AppLogger` (required, non-nullable); failures route through `logException()` (notification dispatch `warning`→`error`, confirmed 2026-08-18), completed passes log `info` with stable `event`+`service` keys; tests mock `AppLogger` + 2 new failure-path tests. Gate green: PHPUnit unit 742 / integration 165 [24 pre-existing skips] / Jest 717 / lint clean; container wiring of `AppLogger` re-verified via `StreakFreezeIdempotencyTest`. Standard-B review (2026-08-19): implementation clean, no findings; repaired recurring `?` doc-encoding corruption + stale plan links in `archive/2026-08.md` → logged **TECH-DOCS-001**. (2026-08-19, Done) → [`../plans/done/cs-plan-035-applogger-migration.md`](../plans/done/cs-plan-035-applogger-migration.md)

- **CS-PLAN-034** — Jest axios-mock noise fix (closed **TECH-LINT-005** + umbrella **TECH-LINT-003**): canonical callable shared `@nextcloud/axios` mock with success envelope + named verbs, 3 inline overrides removed, CS-026 `console.error` silencing replaced by `@/utils/logger` mocks in deliberate error-path specs (incl. scheduling store + SelfAvailabilitySettings beyond the manifest, same workaround class), new `npm run test:noise` zero-noise gate; 108 noisy lines → 0, Jest 71 suites / 717 tests + lint green; conventions documented in `docs/standards/guidelines/testing.md`. (2026-08-19, Done) → [`../plans/done/cs-plan-034-jest-axios-mock-noise.md`](../plans/done/cs-plan-034-jest-axios-mock-noise.md)

- **CS-PLAN-033** — Batch Scheduling Saves (closed **CR-016-02**): one bulk `PUT /api/v1/scheduling/preferences` (sections optional, each provided section a complete week, neither → 400, section-granular merge, single-row JSON update, response = persisted preferences); per-day endpoints/actions/tests removed (pre-release, no BC); all three consumers migrated to one bulk call each (AvailabilitySettings ≤14→1, EnergyBudgetSettings 7→1 + snapshot/watcher workaround dropped, SelfAvailabilitySettings CS-033 adoption hook landed); root-cause fix beyond manifest: Modal.vue footer slot was never rendered → edit-modal Save/Cancel silently dropped, fixed + regression-covered. Post-implementation `/review` (2026-08-19, 6 tracks): security + deploy-safety clean; 1 WARNING + 7 suggestions fixed inline (inputs disabled during in-flight save in both components, weekend 10/15 energy fallback, `buildPreferencesPayload` extraction, E2E save-flow merged to one mutate→assert→restore test, doc links); the extraction exposed a null-preferences crash on fresh rows — caught by E2E, fixed + integration regression; triplicated bounds check logged as **CR-016-15**. Gate re-run green: Jest 717 / lint + stylelint 0 errors (27 TECH-LINT-004 warnings) / l10n + cs:check clean (0/258) / PHPUnit unit 740 / integration 165 [24 skips] / E2E scheduling-owner 14/14 + self-availability-member 4/4. (2026-08-19, Done) → [`../plans/done/cs-plan-033-batch-scheduling-saves.md`](../plans/done/cs-plan-033-batch-scheduling-saves.md)

## Planned (awaiting activation — drafts in `docs/plans/new/`)

> The `new/ → active/` move is **manual** (project-rules §7). These register intent only.

> CS-PLAN-036 was activated and implemented (2026-08-20) — see Recently Completed above.

> CS-PLAN-037 was activated and implemented (2026-08-20, D1=YES/D2=DELETE signed off) — see Recently Completed above.

> **CS-PLAN-038** was activated and implemented (2026-08-21, D1=Remove/D2=Archive/D3=My tasks signed off) — see Recently Completed above.

> **CS-PLAN-039** was activated and implemented (2026-08-21) — see Recently Completed above.

> **CS-PLAN-040** was activated and implemented (2026-08-21) — see Recently Completed above.

> **CS-PLAN-041** was activated and implemented (2026-08-22) — see Recently Completed above.

> **CS-PLAN-042** was activated and implemented (2026-08-22) — see Recently Completed above.

> **CS-PLAN-044** was activated and implemented (2026-08-23, ahead of the soft
> dependency CS-PLAN-043 — scheduling components were edited in their current
> shape) — see Recently Completed above.

> **CS-PLAN-041…044 drafted (2026-08-21)** — GUI debt split into four plans in
> `docs/plans/new/`: ~~**041** UI Honesty & Feedback (UX-005/006/013)~~
> (implemented 2026-08-22), ~~**042**
> Structure Management & Role Gating (UX-004/008/009/011 + CR-SVC-61 endpoint)~~
> (implemented 2026-08-22), **043** Scheduling UI Consolidation
> (CR-016-03/04/05/06/08/13/14 + UX-014 + TECH-DRY-001), ~~**044** A11y & Visual
> Consistency (UX-007/010/012 + TECH-LINT-004 + FEAT-UX-030/031)~~
> (implemented 2026-08-23). Recommended order remaining: 043. Decision-gated
> items stay in `backlog.md` (FEAT-UX-015/018/021, FEAT-UX-023/024/028, P4
> futures). BUG-USER-001 closed as already resolved (CS-PLAN-040 prune) →
> `archive/2026-08.md`.

> **CS-PLAN-042 plan review (2026-08-22)**: Standard A — **GO-WITH-CHANGES**,
> fixes applied inline to the plan (store manifest → `roomTasks.js`, test
> wording, risk rows, l10n gate); ready for manual `new/ → active/` move.

> **CS-PLAN-044 plan review (2026-08-22)**: Standard A — **GO-WITH-CHANGES**,
> fixes applied inline to the plan: TECH-LINT-004 premise corrected (logical
> conversions already landed via `c09c5c9`; step 6 is now an LTR/RTL
> verification pass — `npm run stylelint` verified at 0/0), mobile.css path
> `src/assets/` → `src/styles/`, stale-selector claim corrected
> (`.room-card`/`.task-item`/`.leaderboard-entry` still in use; only
> `.mobile-only`/`.desktop-only` dead), weekday-toggle owners +
> OverdueClaimDialog added to scope/manifest, i18n/l10n gate added,
> touch-target layout risk row added; ready for manual `new/ → active/` move
> (soft-after CS-PLAN-043).

> **CS-PLAN-043 plan review (2026-08-23)**: Standard A — **GO-WITH-CHANGES**,
> fixes applied inline to the plan: stale line counts corrected (694/584 →
> 734/592, re-verified post-CS-044), backend half of the `{ taskCount }`
> suggestions slimming added to §3/§4/manifest (`SchedulingController` +
> `SchedulingService` contract), merged Preview+Suggestions component +
> docs files added to the manifest, E2E baseline re-run before step 1
> (CS-044 edited scheduling components after the last scheduling E2E run),
> step 2→7 store-action churn clarified, CS-044 a11y-contract risk row added,
> 300-line acceptance criterion rescoped to the two split components,
> i18n/l10n + docs-integration criteria added. Two activation-time decisions
> noted to the user: confirm the UX-014 default (wire the owner member-grid
> edit path, keep `PUT /scheduling/members/{userId}`) and the scope call on
> the remaining over-guideline components (`ScheduleCalendar` 489,
> `SelfAvailabilitySettings` 311). Ready for manual `new/ → active/` move.

> **CS-PLAN-045 drafted (2026-08-24)** — Backend Hardening & Correctness
> Sweep in `docs/plans/new/`: bundles BUG-SEC-002 (server-side role guards),
> CR-027-01 (roomType validation), CR-016-15 (energy-bounds single source),
> CR-SVC-19 (reward config clamping), CR-SVC-32 (calendar-day diff),
> CR-SVC-33 (dead reward-less completion path). Activation decisions D1–D3
> flagged in the plan; ready for review + manual `new/ → active/` move.

> **CS-PLAN-046 was activated and implemented (2026-08-24, D1=delete signed
> off), Standard-B reviewed (PASS, 2026-08-25) and moved to `done/`** — see
> Recently Completed above.

> **CS-PLAN-045 plan review (2026-08-24)**: Standard A — **GO-WITH-CHANGES**,
> fixes applied inline to the plan: l10n artifact names corrected (`l10n/en.js`
> does not exist; artifacts are `l10n/en.json` + `l10n/de.json` + POT template),
> MemberList spec manifest path corrected (`tests/unit/components/MemberList.spec.js`,
> not `src/components/Members/`), integration test named per `*ApiTest.php`
> convention (`RoleGuardApiTest.php`), CR-SVC-19 clamping aligned with its own
> risk row + acceptance criterion (all numeric setters incl. getter/read-path
> clamp for previously persisted values), custom-code envelope mapping clarified
> (`ControllerErrorFormatter::forbiddenResponse()` is fixed to code `FORBIDDEN`
> and no 409 formatter exists, so `CANNOT_DEMOTE_SELF`/`CANNOT_DEMOTE_LAST_OWNER`
> envelopes are built inline per the `CANNOT_REMOVE_OWNER` precedent),
> knowledge-integration target corrected (architecture.md has no error-code
> section yet). All file:line refs, no-caller claims, and backlog mappings
> verified against the tree. D1–D3 remain activation-time sign-off items;
> note D3's 403/409 split deliberately deviates from BUG-SEC-002's "400/409"
> wording (mirrors `CANNOT_REMOVE_OWNER` semantics). Ready for manual
> `new/ → active/` move.

> **CS-PLAN-046 drafted (2026-08-24)** — Backend Test Integrity & i18n
> Quick-Wins in `docs/plans/new/`: bundles CR-016-10 (real-DB regression test
> for the fixed `getConsumedEnergyForUserOnDate` SQL — harness precondition now
> met), CR-SVC-52 (dispatch-after-commit ordering assertion), CR-SVC-37
> (service/controller i18n wrap + l10n artifacts), CR-027-02 (dead
> `searchTemplates`; D1 = remove vs wire flagged). All file:line refs and
> no-caller claims verified against the tree; ready for review + manual
> `new/ → active/` move.

> **CS-PLAN-046 plan review (2026-08-24)**: Standard A — **GO-WITH-CHANGES**,
> fixes applied inline to the plan: i18n parameter style corrected from named
> `{placeholder}` tokens to printf-style positional (`%1$d`/`%2$s`) — verified
> against Nextcloud stable31 `OC\L10N\L10NString` that `t()` runs parameters
> through `vsprintf()`, so named tokens would reach clients unsubstituted
> (`ChoreStrikeNotifier` precedent confirmed); `validateTaskData` literal count
> corrected (~9 → 10); RoomController line refs completed (229–230 block
> 'Room type must be at most 50 characters' was missing; 427 → 427–428);
> manifest gained three missed ctor-update sites (`TaskServiceWhitelistTest`,
> `RoomControllerTest`, `RoomControllerIntegrationTest`); identity-mock
> assertion semantics clarified (source text, not substituted output). All
> other file:line refs, no-caller claims, backlog mappings, precedents, and
> l10n artifact paths verified against the tree. D1 remains an activation-time
> sign-off. Noted to the user (not backlogged, per plan-review rules):
> additional unwrapped client-visible literals exist outside CR-SVC-37's scope
> (e.g. TaskController 'Validation failed', RoomController 'Room deleted
> successfully', RoomTaskController 'Invalid nextDue date format' /
> 'Assignee removed successfully', SchedulingController section messages).
> Ready for manual `new/ → active/` move.
