# Active Tasks Registry

> **Purpose:** Live coordination of work **in progress right now** plus a short
> rolling "Recently Completed" window. This is *not* a history log.
> **Update Rule:** every agent updates this file on start / pause / complete.
> **Housekeeping Rule:** move a completed entry to
> [`archive/YYYY-MM.md`](archive/) once it is older than ~2 weeks or beyond the
> ~10-entry rolling cap. **Open work** (bugs / features / debt / follow-ups)
> lives in [`backlog.md`](backlog.md), **not** here.

## In Progress

<!-- (empty — CS-PLAN-055 implemented 2026-08-29, Standard-B reviewed PASS
     2026-08-30 + /review-uncommitted second pass; plan moved to done/) -->

<!-- When you start work, add an entry here in this shape:
### CS-PLAN-NNN — Title
**Status**: In Progress · **Started**: YYYY-MM-DD · **Branch**: master
**Plan**: docs/plans/active/cs-plan-NNN-…md · **Blockers**: none
**Scope**: one line on what this turn is doing.
-->

## Recently Completed (rolling — last ~2 weeks / max 10)

- **CS-PLAN-055** — Route Pruning & Dead-Code Sweep U2-A: 39 no-consumer/test-only routes pruned (110→**71**; `RouteMatrixTest` count pin + CS-055 reintro guard), GET-create branches + alias routes gone (**BUG-ROOM-001**, CSRF class closed), `TaskSyncController/Service` + `RewardConfigController` surfaces deleted (**CR-053-05/08/14/21-slices**), full backend cascade (13 controller classes thinned, ~25 service methods, entity/mapper clusters A–D incl. `AvailabilitySettings` + orphan tables, reminder/`Room::description` schema-drift fields **CR-053-10**, 7 dead migration steps + dead guard blocks, chain 28→21), frontend dead-store layer (~40 members across 9 stores, barrel + `utils/{mobile,performance}.js`, `roomIcons` option pair — **CR-053-15/20** dead half, CR-P4-03 decided: delete), test consumers reworked first (reward-config knob → family-seed `FAMILY_REWARD_CONFIG_OVERRIDES`, leaderboard→`GET /users`, release-task-lifecycle re-scoped onto surviving routes). Durable truth integrated into `docs/api` + `ui-use-cases` (prune ledger) + `task-model-and-sync`/`energy-and-balancing`/`architecture`/`security`/ADR-005. Gate: Jest **754** · PHPUnit unit **757** / integration **198** [21 pre-existing skips] · lint/stylelint/cs:check 0 · noise · webpack build · fresh-install migration replay (`down -v`+`up -d`, 21 steps, no orphan tables) · blocking E2E 177 passed/5 flaky-recovered/11 skipped. Found en route: `TestDataService` used `IConfig` for app-value writes that only exist on the mock (fixed via app-value API + mock `deleteAppValue`), `getAllTasks` mock left wired in an integration test, `MedalMapper::findByUserId`/leaderboard query chain newly-orphaned (recorded inline). Standard-B review 2026-08-30: **PASS** — 39-route disposition + 71-pin verified mechanically against HEAD, GET-mutation authz class closed, full no-caller census of deleted symbols clean, seed knob on the real registry key with symmetric reset; 3 inline auto-fixes for newly-orphaned leftovers the WP-D gates missed (`RoomTaskMapper::findByNextcloudTaskId`, `RoomService::calculateRoomStats`, `RoomService::getRoomsNeedingCleaning` + `RoomMapper::findNeedingCleaning` + 2 self-tests; re-gate unit **755**/Jest 754/integration 198 green); deferrals **CR-055-03/04** logged; no standalone route-disposition ADR (rationale in plan §8). `/review uncommitted` second pass 2026-08-30: 6 findings all fixed — weekly-goal bell check made stale-row-proof via dismiss-first (`notification_id`), seed knob now references the registry const, 4th newly-orphaned leftover deleted (`CompletedTaskMapper::findLatestByRoomTask`), Version1024 dead `$changed` + Version1027/1026 stale `@see`/comments repaired; re-gate unit 755 / integration 198 [21 skips] / Jest 754 / E2E serial 92 pass (1 fail = pre-existing Sunday date-boundary → **BUG-E2E-003**, 4 skips). (2026-08-29, Done + reviewed 2026-08-30; plan moved to `done/`) → [`../plans/done/cs-plan-055-route-dead-code-pruning.md`](../plans/done/cs-plan-055-route-dead-code-pruning.md)

- **CS-PLAN-054** — P1 Security Quick-Closes (closed **CR-053-01/02/03/04** + **CR-053-22** Checklist remainder; D1=owner-gate+closed-set / D2=house-filter-now signed off): new `ChecklistService` owning the checklist guard (room-chain house linkage + visibility matrix, denials → 404 anti-enumeration) with `ChecklistController` thinned to preamble+delegate+envelope (437→316 lines), reorder payload validation (int ids of one accessible task → int orders, else 400) and item↔task linkage checks on toggle/update/delete; `GET /room-tasks` house scoping (`validateRoomOwnership` on the roomId branch → 404, new `getScopeTasksScopedToHouse` skip-on-missing intersection on the scopeId branch); owner-only floor/room delete via `requireHouseOwner` (403); `taskTier` out of `MUTABLE_FIELDS` into an explicit owner-gate + `['bronze','silver','gold']` closed set (member → 403 routed around the inner Forbidden→404 catch via controller pre-check, invalid → 400; service gate kept as defense-in-depth); `ControllerErrorFormatter` gained shared `notFoundResponse`/`serverErrorResponse`; mapper additions `RoomTaskMapper::findHouseIdsForTask` + `TaskChecklistItemMapper::findByIds`; l10n en/de/pot synced (5 keys); auth notes in `docs/api/README.md` (incl. new Checklists section). Gate: Jest 848 · PHPUnit unit 914 / integration 233 [24 pre-existing skips] · lint/stylelint/cs:check 0/272 · noise/l10n/encoding clean. Standard-B review 2026-08-29: **PASS** — guard placement, 404-before-403 gate ordering, reorder hardening, visibility matrix and thin-controller split verified against the code; 1 auto-fix (`index()` 404 catch → shared `ControllerErrorFormatter::notFoundResponse`); gate re-verified green; review record in plan §11. (2026-08-29, Done — plan moved to `done/`) → [`../plans/done/cs-plan-054-p1-security-quick-closes.md`](../plans/done/cs-plan-054-p1-security-quick-closes.md)

- **CS-PLAN-053** — Full Bottom-to-Top Review (modules, docs, orphans, missing implementations & tests; record: [`CODE_REVIEW_2026-08-27.md`](CODE_REVIEW_2026-08-27.md)): six phases, per-layer 4 reviewer subagents + explore inventories, every block/major claim orchestrator-verified (3 reviewer claims disproven & recorded: Version1018 "install blocker" — fresh installs run `migrateSchemaOnly` so pre/post hooks never execute; completion-path visibility "block" — gates live in `RoomService:371-378`; duplicate house_users rows — `uniq_house_user` exists). Findings: 5 verified security gaps (checklist BOLA, room-task index IDOR, floor/room delete owner gap, taskTier mass-assignment, task-sync replay), once/one_time recurrence bug, 42-route disposition decision (67 prod / 30 none / 12 test-only), ~60 grep-proven dead code items (store barrel + ~40 store members, AvailabilitySettings cluster, service/mapper methods, dead migration steps), 141-key l10n artifact gap, systemic 404/500 mapping, silent swallowing in 10/15 controllers, server-TZ calendar semantics; backlog sweep 24 items → 20 KEEP / 4 UPDATE / 0 stale; 22 new backlog items **CR-053-01…22**. Inline closures (§5/§4): houseHealth `??` fix + pin, dead methods/exports, 4 docblock truth-fixes, api-README + ui-use-cases truth-repairs, `MinigameScoreTest` + `TaskAssigneeTest` (7 tests); deferred test gaps → CR-053-22 (approval pending). Gate: Jest 848 · PHPUnit unit 893 / integration 200 [24 pre-existing skips] · lint/stylelint/cs:check 0/269 · noise/l10n/encoding clean. (2026-08-27, Done — plan moved to `done/`) → [`../plans/done/cs-plan-053-full-bottom-up-review.md`](../plans/done/cs-plan-053-full-bottom-up-review.md)

- **CS-PLAN-052** — Observability Hardening: AppLogger Adoption & Encoding-Gate C0 Signature (closed **TECH-DOCS-002** + **CR-SVC-59** + **CR-SVC-41 nullable-AppLogger half**; D1=failures-only / D2=required non-nullable / D3=TestDataService excluded / D4=non-TAB/LF/CR C0 + lone-CR signature): `scripts/check-docs-encoding.js` now flags forbidden C0 bytes (`0x00–0x08, 0x0B, 0x0C, 0x0E–0x1F`) + lone CR byte-level before UTF-8 decode (self-test 7/7 incl. BEL/VT/ESC + lone-CR flagged, TAB/CRLF clean; TAB/LF word-eating documented residual; 660 files green); all 9 nullable `?AppLogger` sites + `ControllerLoggingTrait` flipped to required non-nullable (defaults/null-guards/`?->` removed); bundled `RewardConfigController` silent-no-op defect fixed (trait used, logger never initialized — now required param + `initializeControllerLogger`); WP-C survey frozen in plan §10 before edits — adopted `Floor/Minigame/RoomService` rollback boundaries (`logException` with stable `event`+`service` keys) + `GameLogicService` race-swallow `warning` (`medal_award_race_lost`); excluded with reasons: Activity (expected-condition translation), TestData (D3), Scheduling/Task/TaskSync/Template/Title (no failure path); failure-path assertions for all 5 adopted sites incl. red→green proof; `GameLogicService` ctor reordered (`AppLogger` before optional `TitleService`) to clear a PHP implicit-optional deprecation; 31 test ctor-wiring sites updated. Conventions integrated into `logging.md` + `testing.md`. Gate: lint + stylelint 0/0 · Jest 847 · PHPUnit unit **886** (+1 rollback-logging test) / integration 200 [24 pre-existing skips] · `docs:encoding` 660 green · noise clean. CR-SVC-41 remainder stays open in `backlog.md`. (2026-08-27, Done — Standard-B review **PASS** 2026-08-27: implementation verified against plan, gates re-run green (Jest 847, unit 886/0 skips, integration 200 [24 pre-existing skips], encoding self-test 7/7 + 660 files, noise clean); 1 docblock auto-fix (`awardMedalIfNew` race-loser wording); no new findings; moved to `done/` 2026-08-27) → [`../plans/done/cs-plan-052-observability-applogger-encoding-gate.md`](../plans/done/cs-plan-052-observability-applogger-encoding-gate.md)

- **CS-PLAN-051** — E2E Full-Matrix Stabilization (closed **BUG-E2E-004**; D1=ErrorState+retry / D2=API fixtures / D3=test-side determinism / D4=scheduled CI, all signed off 2026-08-26): `InsightsView.vue` renders the shared retryable `ErrorState` for **both** failed fetches (stats → `insights-error` + `retryInsights()`, members → fairness section + `retryHouseUsers()`; `Promise.allSettled` instead of the error-swallowing `Promise.all`); icon specs root cause was a **product packaging bug** — `icons.js` fluent components used a runtime `template:` string (no compiler in the esm-bundler build) and the webpack `asset/inline` rule double-handled the Fluent SVGs (base64 of the loader's CJS output) — both fixed (`h()` render + asset-rule exclude), fluent icons render again in production bundles; D2 landed on top: `visibility-lock-icon` testid in `TaskList.vue`, both icon specs de-vacuoused onto API-created fixture rows (`tests/e2e/fixtures/visibility-fixtures.ts`, `test.skip` only on non-200 creation) with `networkidle` preambles removed, Jest pins for lock testid + insights failure/retry/both-fail paths (Jest 847); snake pause fixed test-side only (start click + Space keydown dispatched in one renderer task + bounded 3-attempt self-consistent retry — no product auto-pause, D3 escalation not needed); `task-checklist-and-features.spec.ts` moved to `SEED_DEPENDENT_SPECS` after a cross-pool reseed race under matrix load; new non-blocking `.forgejo/workflows/full-matrix.yml` (weekly cron + `workflow_dispatch`, `--retries=0`, report artifact) + `release-gate.yml` pointer comment; full-matrix runbook in `testing.md`. Gate: full matrix `--retries=0` green in two consecutive runs (382 passed / 25 pre-existing skips each; runs 2/4/5 green, runs 3/4 saw two single-occurrence flakes in untouched specs — calendar firefox, scheduling-owner — solo-green, recorded in plan §9); blocking chromium-shaped gate green (184 passed / 11 pre-existing skips); lint + stylelint + noise + l10n + docs:encoding clean (1 encoding repair: plan-review note in this file carried the literal mojibake removed from the gate script in CS-PLAN-050); webpack build green; Docker PHPUnit unit 885 / integration 200 [24 pre-existing skips]. (2026-08-26, Done — Standard-B review PASS 2026-08-27; moved to `done/` 2026-08-27) → [`../plans/done/cs-plan-051-e2e-full-matrix-stabilization.md`](../plans/done/cs-plan-051-e2e-full-matrix-stabilization.md)
  **Standard-B review 2026-08-27: PASS** — webpack exclusion verified against the shared `RULE_ASSETS` (old `/\.svg$/` finder never matched), store retry contracts and e2e strict-mode safety verified; 1 auto-fix: 8 control-byte corruptions + 1 pre-existing BEL repaired in `archive/2026-08.md` (escape-style `\r`/TAB/LF/BEL/VT ate word-initial letters); deferred **TECH-DOCS-002** (docs:encoding gate blind to C0 control bytes); gate re-verified (lint:fix clean, Jest 847, docs:encoding green; no PHP changed). Plan review record: `## Review Outcome` in the plan.

- **CS-PLAN-050** — E2E Long-Tail Integrity Sweep & Docs-Encoding Gate (closed **P9-E2E-002b** + **TECH-DOCS-001**; D1=delete smoke-tests / D2=delete daily-tasks-flow / D3=in scope / D4=blocking CI, all signed off 2026-08-26): deleted `smoke-tests.spec.ts` + `daily-tasks-flow.spec.ts` with recorded coverage maps (fast-core + lean smoke; today-view; dead selectors Skip/Reschedule/monster-sprite never existed in `src/`); new shared fixture `tests/e2e/fixtures/dashboard-floors.ts` (`expandAllFloors`/`expandUntilRoomVisible`) replacing the 5× floor-expand loops; migrations across `multi-user-interactions` (unconditional leaderboard assertions), `room-management` (3 sleeps → auto-wait, honest skip reasons), `room-detail` (expand helper + explicit skips, real `add-task-picker` assertion), `task-checklist-and-features` (owner-actor toggle test creating a sub-task via the editor and asserting checkbox flip + `is-completed` + line-through, absorbing the wrong-selector strikethrough test); `fast-core-tests` de-vacuoused (exact-match `Flexible` fix); `minimal-smoke-tests` rewritten as the lean smoke layer (console listener before `goto`, hash deep-links). De-vacuousing exposed 2 locator defects fixed inline (`cleanliness-value` 3× strict violation; hidden checklist input → label click). New `npm run docs:encoding` gate (strict UTF-8 + cp1252-mojibake signatures, bare `?` never flagged, `--self-test` with Buffer-planted fixtures) wired blocking into `release-gate.yml`; the single live mojibake hit (room-management comments) repaired. Gate: 0 `waitForTimeout(` sites; structural specs 3× standalone green; full matrix run twice — all swept specs green in every browser; 6 remaining failures sit in untouched specs (pre-existing per diff, standalone-green, logged as **BUG-E2E-004**); Jest 842 / lint + stylelint 0/0 / webpack green / Docker PHPUnit unit 885 / integration 200 [24 pre-existing skips]. Standard-B review 2026-08-26: **PASS** — all selector/testid claims verified live in `src/`; UTF-8 validator + mojibake signatures reviewed byte-level; 3 auto-fixes (`room-detail.spec.ts` cleanliness test now asserts `room-detail-view` and scopes the locator into it — the `.first()` fix could have read a dashboard room card; 4 stale deleted-spec references in `docs/testing/release-test-plan.md` re-pointed per the coverage map, recurrence row downgraded to `partial`; `release-task-lifecycle.spec.ts` docblock recurrence pointer re-pointed to `today-view`); noted without action: the checklist toggle test still runs the default-page `beforeEach` nav (harmless overhead); gates re-verified after fixes (lint:fix clean, Jest 842, `docs:encoding` green on 659 tracked files + self-test 4/4). (2026-08-26, Done — plan moved to `done/`) → [`../plans/done/cs-plan-050-e2e-long-tail-integrity-encoding-gate.md`](../plans/done/cs-plan-050-e2e-long-tail-integrity-encoding-gate.md)

- **CS-PLAN-049** — RewardConfig Registry & Docs Quick-Wins (closed **CR-SVC-16** + **P9-DOC-005**; partial **CR-SVC-15** + **CR-SVC-41**; D1=thin delegates / D2=AppLogger required signed off): single private `REGISTRY` const in `RewardConfigService` (15 scalar keys × type/default/clamp/apiField) drives generic `readSpec`/`writeSpec`, the now-delegate typed getters/setters (public API byte-identical), and loop-based `getAllConfig`/`updateConfig` (isset skip-`null` + unknown-ignore semantics preserved; title-threshold paths untouched); ctor takes required non-nullable `AppLogger`; 689 → 538 lines. New table-driven specs (per-key default + clamp on read AND write, bool round-trips, updateConfig parity, 16-field getAllConfig shape pin incl. order); existing ~750-line behavior suite green with only ctor-arg test changes. `development.md` fictional `calculateNextDue` example → real `RoomTaskService::updateDueDate`; raw `axios.post` example → shared `api` client. Registry idiom documented in architecture.md ("Service Idiom: Config Value Registry"). Gate: Docker PHPUnit unit 885 / integration 200 [24 pre-existing skips] / Jest 842 / cs:check 0/267 / lint clean / noise clean (l10n untouched — no user-visible strings). Standard-B review 2026-08-26: **PASS** — behavior parity verified line-by-line (defaults, clamp read+write, isset skip-`null`/unknown-ignore, 16-field order, unknown-tier 1.0 fallback); 2 minor doc auto-fixes (post-refactor line count corrected 489 → 538 in plan/ACTIVE_TASKS/archive/backlog, backlog.md blank line before the CR-SVC-16 heading restored); gates re-verified (lint:fix/noise clean, Jest 842, Docker PHPUnit unit 885 / integration 200 [24 pre-existing skips], cs:check 0/267). (2026-08-26, Done — plan moved to `done/`) → [`../plans/done/cs-plan-049-reward-config-registry-docs-quickwins.md`](../plans/done/cs-plan-049-reward-config-registry-docs-quickwins.md)

- **CS-PLAN-048** — Modal A11y Names, Energy Display Guard & E2E Integrity Sweep (closed **UX-016** + **BUG-E2E-003** + **BUG-E2E-001** + **BUG-UI-001**; D1=per-site aria-label / D2=serial-gate green state confirmed / D3=coerce to 0): all 16 `<Modal>` call sites named via translated `aria-label` reusing heading/trigger keys (ConfirmDialog forwards its `title` — one line for all consumers; AvailabilityEditModal stray `:title` → `:aria-label`); 5 reused keys missing from l10n artifacts synced to en/de/pot (`New Task`, `Snake`, `Add New Floor`, `Reset House?`, `Edit Sub-Task`); design.md §6 records the accessible-name requirement; energy totals coerce non-finite day values to numeric 0 in EnergyBudgetSettings + BudgetAnalysis (peak/low semantics preserved) with Jest pins for both paths; BUG-E2E-003 root-caused as *not a race* — successful selection closes the picker, so the spec now asserts the durable outcome (PUT ok → picker closed → reopened picker shows Active tag) plus catalog-render wait, green 3× standalone `--retries=0`; BUG-E2E-001 not reproducible on the current tree (10/10 standalone + full blocking `chromium-serial-db` project green: 86 passed / 4 pre-existing data-state skips / 0 failed — D2 green-state sign-off met). Gate: Docker PHPUnit unit 834 / integration 200 [24 pre-existing skips] / Jest 842 / lint + stylelint 0 errors / noise clean / l10n:check-keys clean / webpack build green. Standard-B review 2026-08-25: **PASS** — 3 auto-fixes (`2048` l10n key synced + mangled `"0"` artifact key removed from en/de/pot, title-selection disabled-button skip made honest via authenticated `/users/current` probe with fail-loud transport errors, redundant inline stubs dropped from the new ArcadeSection test); gates re-verified after fixes; 1 out-of-manifest gate repair (ScheduleCalendar de-shape week-range regex was date-dependent, broke on "Sept." weeks — stash-verified pre-existing, relaxed to 3–4-letter months); Standard-B re-review 2026-08-26 clean (2 minor doc auto-fixes: UX-016 dangling checklist lines + archive indent churn; lint/Jest 842/noise/l10n re-verified green). Plan ready for manual `active/ → done/` move. (2026-08-25, Done)

- **CS-PLAN-047** — API i18n Completion Sweep (closed **CR-SVC-62**; D1=YES / D2=exception signed off): scripted inventory audit over `lib/` (all three exception types + `'message' =>`, classified per catching controller) froze the wrap list before edits; required non-nullable `IL10N` injected into 8 controllers + 8 services; all 62 controller `'message'` literals wrapped; curated service IAEs wrapped (`HouseSetupService` ×11 printf-converted, `SchedulingService:45`, `RoomTaskService` ×4, `MinigameService::saveScore`, audit-promoted `TemplateService::materializeTemplate` ×3); D1 sweep wrapped all 24 NotFound/Forbidden literals (`Authorization/Room/Task/RoomTask/House/Floor/Activity` services); audit reclassifications recorded in plan §2a (`TaskService:200`, `FloorService:130`, `HouseService:137`, `RoomTaskService:870` hidden by `safeMessage()` catch-alls or curated responses → unwrapped); D2 fallback policy in formatter docblock + `i18n.md` backend-error contract; l10n en/de/pot synced (pot fully regenerated from en.json, 890 msgids, 91 unsynced backend keys backfilled). Gate: Docker PHPUnit unit 834 / integration 200 [24 pre-existing skips] / Jest 831 / lint clean / cs:check 0/267 / noise + l10n:check-keys clean / E2E grep no hits. Standard-B review 2026-08-25: **PASS** — 2 auto-fixes (l10n artifacts: newly wrapped `Template "%1$s" is not available for level "%2$s"` + notifier rich-subject backfill gap `Your task "{task}" was taken over by {claimer}` added to en/de/pot → 892 keys), §2a catch-path claims all re-verified, gate re-verified (lint/l10n/noise clean, Jest 831, Docker PHPUnit 834/200 [24 pre-existing skips], cs:check 0/267, E2E grep no hits). (2026-08-25, Done — plan moved to `done/`) → [`../plans/done/cs-plan-047-api-i18n-completion-sweep.md`](../plans/done/cs-plan-047-api-i18n-completion-sweep.md)

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
> (implemented 2026-08-23). ~~Recommended order remaining: 043~~ (043 landed
> 2026-08-24). Decision-gated
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

> **CS-PLAN-047 was activated and implemented (2026-08-25, D1=YES/D2=exception
> signed off)** — see Recently Completed above.

> **CS-PLAN-047 drafted (2026-08-25)** — API i18n Completion Sweep in
> `docs/plans/new/`: closes **CR-SVC-62** fully — 52 controller `'message'`
> literals across 9 controllers + the curated service `InvalidArgumentException`
> set surfaced via `validation()` (counts re-verified against the tree;
> backlog estimates corrected). Decisions **signed off 2026-08-25**:
> **D1=YES** (the 24 `NotFoundException`/`ForbiddenException` literals
> surfaced verbatim by `safeMessage()` are in scope), **D2=documented
> exception** (the `safeMessage` generic fallback stays untranslated; policy
> recorded in `i18n.md` + formatter docblock). Frontend untouched (error text
> flows through toasts). Ready for review + manual `new/ → active/` move.
> **CS-PLAN-048 drafted (2026-08-25)** — Modal A11y Names, Energy Display
> Guard & E2E Integrity Sweep in `docs/plans/new/`: closes **UX-016** (15 of 16
> Modal call sites without accessible name; ConfirmDialog forwards its `title`
> prop), **BUG-E2E-003** + **BUG-E2E-001** (title-selection flake +
> 3-floor-house serial-pool failures, diagnose-first, re-add to blocking gate),
> **BUG-UI-001** (energy-total coercion while a day input is empty/invalid).
> All counts/file:line refs verified against the tree. Activation decisions:
> **D1** aria-label-per-site vs heading fallback (recommendation: per-site),
> **D2** serial-gate re-inclusion (recommendation: yes), **D3** invalid-input
> display semantics (recommendation: coerce to 0). Ready for review + manual
> `new/ → active/` move.
>
> Recommended follow-ups after 047: UX-016 (Modal accessible names, S), then
> BUG-E2E-001/003 (E2E integrity) or CR-SVC-16 (P2, RewardConfigService
> registry). ~~All three since closed by CS-PLAN-048/049.~~

> **CS-PLAN-047 plan review (2026-08-25)**: Standard A — **GO-WITH-CHANGES**,
> fixes applied inline to the plan: controller inventory corrected 52 → 62
> literal sites (re-grep verified — draft missed 8 `RoomTaskController` sites:
> 61/67 fromTemplate guards, 124 scope guard, 708/720 bulk-assign validations,
> 867/880/935 stats range validations; and 2 `TaskSyncController` completedAt
> validations: 93/103), §3/§6 counts updated accordingly;
> `tests/unit/Service/MinigameServiceTest.php` added to the manifest (assertion
> for saveScore line 85 promised by §4); sibling wrap ref corrected
> (`MinigameService` 267 → 266). All D1 (24 NotFound/Forbidden) and curated IAE
> counts, IL10N-injection claims, audit-dependent refs, exclusions, l10n
> artifact paths, npm/composer gates, Jest baseline, and every other manifest
> path verified against the tree. Ready for manual `new/ → active/` move.

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

> **CS-PLAN-048 plan review (2026-08-25)**: Standard A — **GO-WITH-CHANGES**,
> fixes applied inline to the plan: serial-pool test count corrected 13 → 10
> (the backlog's "13" predates the CS-PLAN-042 spec rewrite; re-counted
> against the tree), and the "outside the blocking gate / re-inclusion"
> premise corrected — `3-floor-house.spec.ts` is registered in
> `SEED_DEPENDENT_SPECS` (`chromium-serial-db`) since CS-PLAN-018 and was
> never removed (git-verified), and `release-gate.yml` runs that project
> blocking; WP-C therefore restores the gate to green (no config change), D2
> renamed to green-state sign-off, and the moot CI-slowdown risk row
> rewritten. WP-A label sources switched to reuse-first: 12 of 15 sites have
> an existing heading/trigger key (verified), "~12 new keys" → at most ~2
> ("Edit Task"), incl. the `AvailabilityEditModal` stray `:title` →
> `:aria-label` correction (Modal has no `title` prop). All other file:line
> refs (16 `<Modal>` sites incl. line numbers, `EnergyBudgetSettings:102`,
> `BudgetAnalysis:45`, `title-selection.spec.ts:39`), backlog mappings
> (UX-016 P3/S, BUG-E2E-003 P3/S, BUG-E2E-001 P3/M, BUG-UI-001 P4/S), l10n
> artifact paths, manifest files, and the design.md §6 *Dialogs* target
> verified against the tree. D1–D3 remain activation-time sign-offs. Noted
> to the user (not backlogged, per plan-review rules): `SnakeLeaderboard.vue`
> hand-rolls an overlay without `role="dialog"`/accessible name — outside
> WP-A's `<Modal>` scope, but a design.md §6 violation candidate. Ready for
> manual `new/ → active/` move.

> **CS-PLAN-049 drafted (2026-08-26)** — RewardConfig Registry & Docs
> Quick-Wins in `docs/plans/new/`: closes **CR-SVC-16** (single
> `key => [type, default, clamp, apiField]` registry driving
> get/set/`getAllConfig`/`updateConfig`; public API unchanged — all 16 setter
> call sites verified internal, no frontend consumer of the endpoints) and
> **P9-DOC-005** (`development.md:186` fictional `calculateNextDue` +
> `:380` raw `axios.post` → shared `api` client); partial closes of
> **CR-SVC-15** (RewardConfigService 689 lines) and **CR-SVC-41**
> (nullable `?AppLogger` in this service). Activation decisions:
> **D1** keep individual setters as thin delegates (recommended) vs delete,
> **D2** `AppLogger` non-nullable now (recommended) vs defer to a full
> CR-SVC-41 plan. All file:line refs and no-caller claims verified against
> the tree; ready for review + manual `new/ → active/` move.
>
> Recommended follow-ups after 049: P9-E2E-002b (P2, E2E `waitForTimeout`
> long-tail) or CR-SVC-59 (P3, AppLogger adoption remainder); decision-gated
> UX-015 (undo single-slot: queue vs accept) needs a product call.
> ~~Both closed since by CS-PLAN-050/052; UX-015 still decision-gated.~~

> **CS-PLAN-049 plan review (2026-08-26)**: Standard A — **GO-WITH-CHANGES**,
> fixes applied inline to the plan: the P9-DOC-005 replacement target was
> fictional — `TaskSchedulerService`/`SmartSchedulerService`/`lib/Service/Scheduling/`
> do not exist; corrected to the real owners (`SchedulingService` in
> `lib/Service/SchedulingService.php`, due-date logic in
> `RoomTask::calculateIdealDueDate()` / `RoomTaskService::updateDueDate()`) in
> both §1 and step 5, and the AppLogger-mock line ref corrected 723–741 →
> 716–743. All other claims verified against the tree: 689-line file + ctor
> line 42 + `getAllConfig()` 537–556 + `updateConfig()` 563–612, 15 `KEY_*`
> scalar consts / 16 `getAllConfig()` fields, 16 internal setter call sites
> (15 `updateConfig` branches + `applyTitleThresholds`), 5 getter-only
> consumers, no frontend consumer of `/api/v1/reward-config` (routes 169–170),
> `development.md:186`/`:380` drifts, CR-SVC-15's "607" bullet, test/integration
> wiring claims (`RewardConfigServiceTest` 744 lines, `TeamworkRewardTest` +
> `TitleServiceIntegrationTest` via `\OC::$server`, all other consumers use
> `createMock` and are ctor-change-immune). Notes to the user (not backlogged,
> per plan-review rules): CR-SVC-41's "3 services" count is stale (tree has 5
> nullable-`?AppLogger` services + `ControllerLoggingTrait` + 6 controllers),
> and the post-refactor file lands ~350–400 lines, still over the ~300-line
> guideline (plan risk row 6 + acceptance criterion already record this).
> D1–D2 remain activation-time sign-offs. Ready for manual `new/ → active/` move.

> **CS-PLAN-050 drafted (2026-08-26)** — E2E Long-Tail Integrity Sweep &
> Docs-Encoding Gate in `docs/plans/new/`: closes **P9-E2E-002b** (re-verified
> 7 specs / 23 `waitForTimeout` calls + vacuous `if (count > 0)` /
> try-catch-swallow patterns; `daily-tasks-flow` tests the archived
> DailyTaskList) and **TECH-DOCS-001** (deterministic `npm run docs:encoding`
> mojibake gate, wired blocking into `release-gate.yml`). Activation
> decisions: **D1** delete `smoke-tests.spec.ts` (rec: delete; covered by
> `fast-core-tests` + rewritten lean smoke), **D2** delete
> `daily-tasks-flow.spec.ts` (rec: delete; superseded by `today-view.spec.ts`),
> **D3** de-vacuous the two `fast-core-tests` try/catch tests (rec: in scope),
> **D4** wire `docs:encoding` blocking into the release gate (rec: yes). All
> counts/line refs/selector-liveness claims verified against the tree; ready
> for review + manual `new/ → active/` move.
>
> **CS-PLAN-050 was activated and implemented (2026-08-26, D1=delete
> smoke-tests / D2=delete daily-tasks-flow / D3=in scope / D4=blocking CI
> signed off)** — see Recently Completed above.

> Recommended follow-ups after 050: ~~CR-SVC-59~~ (closed by CS-PLAN-052) or
> CR-SVC-15 (P2, God-service decomposition — larger); UX-015 /
> FEAT-UX-015 / FEAT-UX-018 need product calls first.

> **CS-PLAN-050 plan review (2026-08-26)**: Standard A — **GO-WITH-CHANGES**,
> fixes applied inline to the plan: multi-user floor-expand loop count
> corrected 6× → 5× (the 6th sleep, line 77, is a standalone single-floor
> toggle), §3 testid attributions corrected (`FloorList.vue:47/68/81`
> conflated testids with CSS classes — real sites are `FloorList.vue:46`,
> `Settings.vue:98/111`, `RoomCard.vue:10`; `TaskList.vue:178` carries no
> testid; the dashboard floor-toggle hook is its aria-label role, recorded),
> and the encoding gate made tree-green-achievable: scan scope pinned to all
> git-tracked text files, the single known live hit
> (`room-management.spec.ts:22/27/121` em-dash mojibake, found by a full-tree
> scan) scheduled for repair in steps 3/4b, signature citations switched to
> escape/byte-hex notation (the plan's own literal mojibake quotes fixed),
> `testing.md` vs `i18n.md` documentation sites corrected. All 23
> `waitForTimeout` line refs, vacuous-pattern claims, dead-UI selectors,
> helper locality, Playwright project registrations, release-gate shape,
> baselines (Jest 842 / PHPUnit unit 885 / integration 200 [24 skips]), and
> backlog mappings verified against the tree. **Decisions signed off
> 2026-08-26: D1=delete smoke-tests, D2=delete daily-tasks-flow, D3=in scope,
> D4=blocking in CI.** Ready for manual `new/ → active/` move.

> **CS-PLAN-051 drafted (2026-08-26)** — E2E Full-Matrix Stabilization in
> `docs/plans/new/`: closes **BUG-E2E-004** (all four clusters: insights
> `fetchHouseUsers` swallow → misleading `fairness-empty` hint, verified root
> mechanism `InsightsView.vue:117-129/35-37`; conditional/vacuous icon specs
> with load-fragile `networkidle` preambles + missing lock-icon testid
> `TaskList.vue:111-113`; snake pause survivable-window race ≈1.4–1.5 s,
> `SnakeGame.vue:70/161-166`; full-matrix re-verification + optional
> scheduled non-blocking Forgejo matrix job, gate stays chromium-shaped per
> CS-PLAN-020 D3). All file:line refs and locator-liveness claims verified
> against the tree. Activation decisions: **D1** ErrorState+retry for the
> fairness section (recommended), **D2** API-created fixture rows for the two
> icon specs (recommended), **D3** snake test-side determinism first (product
> `document.hidden` auto-pause only as escalation), **D4** scheduled weekly
> full-matrix workflow (recommended). Ready for review + manual
> `new/ → active/` move.
>
> Selection rationale: only P2 open code item is CR-SVC-15 (L, large
> decomposition); BUG-E2E-004 is the freshest item (logged from CS-PLAN-050's
> own full-matrix verification) and the only blocker for a verifiable
> "full matrix green" state, including one real product-honesty defect.

> **CS-PLAN-051 was activated and implemented (2026-08-26, D1=ErrorState+retry
> / D2=API fixtures / D3=test-side determinism / D4=scheduled CI signed
> off)** — see Recently Completed above. Standard-B review PASS + move to
> `done/` 2026-08-27; implementation record in plan §9.

> **CS-PLAN-051 plan review (2026-08-26)**: Standard A — **GO-WITH-CHANGES**,
> fixes applied inline to the plan: `INITIAL_SPEED` ref corrected
> (`SnakeGame.vue:138-140,161-166` — the constant is defined at :140),
> release-gate deferral ref corrected `:150-151` → `:149-151`, the icon-spec
> assertion analysis tightened (crown is double-count-guarded, private's inner
> `expect` is unconditional once a row matches — standalone vacuous-green
> confirmed against the 12-task family seed, but a sibling-created room-linked
> 'My Private Reminder' can hard-fail the lock assertion under matrix load
> because `Icon.vue:85` renders `icon--lock` with no `data-icon`), and a
> release-gate bring-up drift risk row added to §7. Out-of-plan auto-fix
> (red-gate defect found during review): `scripts/check-docs-encoding.js:177`
> self-test comment planted the literal em-dash mojibake (U+00E2 U+20AC U+201D)
> and self-flagged the
> gate (committed in `e2c39e6`) — rewritten to byte-hex/char-code notation;
> gate green again (660 files scanned, self-test 4/4). All other file:line
> refs (InsightsView 117-129/35-37/23-25, TaskList 68-72/111-113/114-120,
> SnakeGame :70, snake-game :69/:74, admin-only :24/:28/:33, private-tasks
> :23/:30/:38/:56, insights-flow :72/:92/:112-137, auth-contexts :19,
> playwright.config :13-78 registrations, release-visibility-roles :112-121
> precedent, ErrorState retry+offline-aware, user.js/house.js error contracts,
> TaskList.spec :173), the BUG-E2E-004 mapping (P3/M, all four clusters + the
> CI-job sub-item), baselines (Jest 842 / PHPUnit 885/200 [24 skips] —
> same-day per the CS-PLAN-049/050 reviews, not re-run), and every manifest
> path verified against the tree; `docs/plans/active/` is empty (no overlap)
> and `full-matrix.yml` is confirmed new. D1–D4 remain activation-time
> sign-offs. Ready for manual `new/ → active/` move.

> **CS-PLAN-052 drafted (2026-08-27)** — Observability Hardening: AppLogger
> Adoption & Encoding-Gate C0 Signature in `docs/plans/new/`: closes
> **TECH-DOCS-002** (C0 + lone-CR signatures + self-test fixtures in
> `check-docs-encoding.js`; TAB/LF word-eating subclass documented as an
> undetectable residual), **CR-SVC-59** (survey-first AppLogger adoption in
> the 11 logger-less services — pre-scan: Floor/GameLogic/Minigame/Room have
> unlogged rollback/race-swallow sites; Scheduling/Task/TaskSync/Template/
> Title have no failure path → stay logger-free, reason recorded), and the
> **nullable-`?AppLogger` half of CR-SVC-41** (4 services + 5 controllers +
> `ControllerLoggingTrait` → required non-nullable, CS-PLAN-049 precedent).
> Bundled root-cause find: `RewardConfigController` uses
> `ControllerLoggingTrait` but never initializes the logger — its `logDebug`
> calls (:39/:49) are silent no-ops today. All file:line refs, catch-site
> counts, trait users, test-file paths, and baselines verified against the
> tree. Activation decisions D1–D4 (failures-only scope / required
> non-nullable / TestDataService out / gate signature scope) flagged in plan
> §9; ready for review + manual `new/ → active/` move.
>
> Selection rationale: freshest deferred item (TECH-DOCS-002, logged by the
> CS-PLAN-051 review the same day) + the twice-recommended CR-SVC-59 (after
> 049 and 050). The only higher-priority open item, CR-SVC-15 (P2/L,
> God-service decomposition), is deliberately left for its own dedicated
> plan; decision-gated UX-015 / FEAT-UX-015 / FEAT-UX-018 still need product
> calls first.

> **CS-PLAN-053 drafted (2026-08-27)** — Full Bottom-to-Top Review (modules,
> docs, orphans, missing implementations & tests) in `docs/plans/new/`: six
> phases bottom-to-top (persistence → services → API surface → frontend →
> docs/backlog integrity → consolidation), Full-tier reviewer pattern per
> layer, log-don't-implement discipline (only review-skill §5 auto-fixes +
> explicit test-gap closures inline). Drafted from a fresh full-tree explore
> inventory (frozen in plan §9): known leads include the
> `RewardConfigController` `@AdminRequired` rule violation, effectively-dead
> `AvailabilitySettings` entity+mapper, orphaned `src/utils/mobile.js` +
> `performance.js`, 56 routes without frontend consumers, missing
> integration/entity/command tests, and docs drift
> (`phase5-smart-scheduling.md` documents deleted endpoints). Ready for
> review + manual `new/ → active/` move.
>
> Selection rationale: ~20 plans landed since the last full review record
> (2026-08-11) without a whole-tree pass; this plan produces the verified
> findings map that prioritizes the follow-up plans (incl. the CR-SVC-15
> decomposition decision data).

> **CS-PLAN-053 plan review (2026-08-27)**: Standard A — **GO**, fixes
> applied inline to the plan: §9 baseline drift corrected against the tree —
> migrations 27 → 28, entities 14 → 13, controllers 17 → 15 (the 17 counted
> `ControllerErrorFormatter` + `ControllerLoggingTrait`), routes ~111 → 110,
> frontend >300-line files ~27 → 38 (26 backend + 38 frontend = 64 total in
> §1), integration files 36 → 35, Playwright 43 specs/5 projects → 41
> specs/6 projects (incl. `setup`); `RewardConfigController` annotation
> precision (`::update` is `@AdminRequired`; `index` is `@NoAdminRequired`)
> in §1/§6/§9; `TestController` coverage clarified (lives in
> `tests/integration/Controller/`, not unit); mobile.js/performance.js
> "zero imports" → "zero production imports" (own Jest specs import them);
> "tree clean" → "tree clean except this plan's drafting notes". All other
> claims verified against the tree: 104 PHP files / 17 controllers-dir files
> / 22 services / 13 mappers / 95 frontend files / 13 stores / 50 components
> / 9 views, worst-offender line counts (RoomTaskController 1053,
> TaskController 962, RoomTaskService 945, TaskList.vue 1094, Settings.vue
> 982), `\OC::$server` in `RoomTaskController::filteredDaily` (:1022) +
> `Version1017` raw-SQL exception, all §9 orphan candidates exist
> (`AvailabilitySettings` only referenced by mapper/migration/TestDataService
> injection; `TaskSyncService::getRoomTask` no callers; `TITLE_PACKS`
> export-only), no frontend `reward-config` consumer, the 8 spec-gap
> components exist without specs, no Checklist/Minigame/RewardConfig/TaskSync
> `*ApiTest.php`, all Phase-0 gate scripts exist, ADR-001…008 + ADR-005
> superseded state, `TaskMapper.php:238` single TODO, SEC-AUDIT-001 open.
> Notes to the user (not backlogged, per plan-review rules): the §9 baseline
> had drifted on 8 counts — Phase 0's re-verify-and-freeze step is load-bearing;
> the `RewardConfigController::update` authorization fix will need a product
> call (owner-gated `@NoAdminRequired` vs keep admin-only) when its backlog
> item is prioritized. ~~Ready for manual `new/ → active/` move.~~ (Activated
> 2026-08-27 — see In Progress above.)

> **CS-PLAN-054 drafted (2026-08-29)** — P1 Security Quick-Closes in
> `docs/plans/new/`: bundles **all four P1 findings** from CS-PLAN-053 —
> CR-053-02 (`GET /room-tasks` index IDOR, mirrors the roomStats fix),
> CR-053-03 (floor/room delete `requireHouseOwner` gate), CR-053-04
> (`taskTier` owner-gate + closed set), CR-053-01 (`ChecklistService`
> extraction with house/visibility gates, incl. the deferred Checklist
> integration suite = CR-053-22 partial). Scope deviates deliberately from
> review §6.7 (which proposed CR-053-01 as a separate plan; CR-053-06 stays
> P2 follow-up). Backend-only, no frontend changes. All file:line refs
> verified against the tree; activation decisions **D1** (taskTier
> owner-gate+closed-set vs full removal) + **D2** (scopeId branch
> house-filter now vs defer to CR-053-14) flagged in plan §2. Plan §9
> carries the **user-decision block** for CR-053-12/14/21/22 — **answered
> 2026-08-29, all four recommendations accepted** (per-house timezone ·
> prune routes now · README rewrite · test-gap deferral approved) and
> mirrored into the backlog items.
> Ready for review + manual `new/ → active/` move.

> **CS-PLAN-055/056/057 drafted (2026-08-29)** — the three post-decision plans
> from the CS-PLAN-054 user-decision block, in recommended execution order:
> **055** Route & Dead-Code Pruning (U2-A: CR-053-14 **prune** decision ×39
> routes + CR-053-15 dead-store/entity/migration sweep + CR-053-20 dead-action
> half + BUG-ROOM-001 GET-create; moots CR-053-05/08/10 + FEAT-UX-021; test
> consumers reworked first — 2 reward-config E2E specs verified load-bearing,
> moved to seed profiles), **056** Task Lifecycle Visibility Matrix (CR-053-06,
> S; depends on 055 — `task#uncomplete` is the surviving production endpoint of
> the flagged trio, skip/reschedule are pruned; guard also covers
> `updateDueDate`/`reactivate`), **057** Per-House Timezone (U1-A: CR-053-12;
> `houses.timezone` + `CalendarService` bounds threading + one frontend
> `dateKeys.js` convention; ADR-009; optional fold-in of the CR-P2-11 Sunday
> leaderboard fix = D3). All file:line refs, route keep/prune lists, and
> test-consumer claims verified against the tree; ready for review + manual
> `new/ → active/` move.

> **CS-PLAN-055 plan review (2026-08-29)**: Standard A — **GO-WITH-CHANGES**,
> fixes applied inline to the plan: phantom `user#show` (never existed in
> `routes.php`/`UserController`, git-verified) corrected to the real
> no-consumer wrapper route **`user#update`** (PUT `/users/{id}`, sole consumer
> = dead `updateUserProfile` action) in §3.1/§3.2/§8 — 39/71 arithmetic now
> closes; §3.4/§4 corrected — `RouteMatrixTest` has no count/expected-set
> assertion today, the plan now *adds* the 71-pin + pruned-names guard; CS-054
> cascade gap closed (`ChecklistService::{initializeProgress,getCompleteChecklist}`
> → `RoomTaskChecklistProgressMapper::initializeProgress` become caller-less with
> the route and join §3.2); manifest path/spec-name repairs (`src/utils/roomIcons.js`
> not `constants/`, `storeBarrel.spec.js` not `store/index.spec.js`, added
> sole-importer specs `utils/{mobile,performance}.spec.js` + TaskSync/Availability
> unit-suite deletions + surviving-suite trim list); ENV-003 parenthetical
> corrected (exactly one Version1013 file, which creates `chorestrike_availability`
> at :17-68; the record's "twice" claim was Version1004 ×2 / 1010↔1013 same-date
> confusion; V1015's availability-index block added to the edit list). Verified
> against the tree: 110-route recount, all other 38 prune names exist, GET-create
> refs (FloorController:51-71, RoomController:55-88), keep-list lines
> (roomTasks.js:554/:584), every §3.4 test-consumer ref incl.
> release-notifications:48/57 and time-travel:49, no e2e/integration consumer of
> `game/leaderboard` besides the named spec, `TaskSyncService` lib-caller = its
> controller only, `$nextcloudTaskId` pass-through = TaskSync only,
> `getTierMultiplier`/`isAuthenticated`/`isHouseMember` test-only (integration
> caller named), `RewardConfigService` service consumers + retention correct,
> orphan-table creation sites V1001/1008/1009, migration count 28, baselines
> (Jest 848, integration 233/24 skips), CR-053/CR-P4-03/FEAT-UX-021/BUG-ROOM-001
> mappings, and CS-PLAN-056's reciprocal dependency. Open WP-C grep-gate notes
> (recorded in the plan's `## Review Outcome`, not blockers): `room_task#assignees`
> GET/PUT "production" label lacks an `src/` call site, and the review record's
> L3-0 per-bucket labels inherited the same phantom — totals are only
> re-derivable from the new RouteMatrix guard. Ready for manual
> `new/ → active/` move (055 before 056/057 as drafted).
