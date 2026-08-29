# Code Review — CS-PLAN-053 Full Bottom-to-Top Review (2026-08-27)

> **Scope:** Whole tree — persistence → services → API surface → frontend →
> docs/backlog. First full-tree review since `CODE_REVIEW_2026-08-11.md`
> (~20 plans landed since).
> **Tier:** Full (per-layer: 1 `explore` inventory refresh + up to 4 parallel
> `reviewer` subagents: correctness / architecture-ownership / security-auth /
> perf-quality), six phases per `docs/plans/active/cs-plan-053-full-bottom-up-review.md`.
> **Tree reviewed:** commit `0bb95b9` on `feat/cs-plan-040-transparency-insights`
> (clean except ACTIVE_TASKS.md update + the plan's move to `docs/plans/active/`).
> **Status:** COMPLETE (2026-08-27) — Phases 0–6 executed; 22 follow-up items
> registered as `CR-053-01…22` in `backlog.md`; plan → `done/` recommendation pending user move.

---

## Executive summary

**Scope:** whole tree (104 PHP files / ~22.8k lines, 95 frontend files, 28 migrations, 110
routes, full docs + backlog) — first full review since 2026-08-11. **Tier:** Full, six phases,
each layer covered by 4 parallel `reviewer` subagents + explore inventories; every
block/major claim re-verified by the orchestrator against source (3 reviewer claims
disproven and recorded as such: the Version1018 "install blocker", the completion-path
visibility "block", and the duplicate-house_users-rows claim).

**Outcome:** the tree is functionally sound on its actual test surface (all gates green
throughout; fresh install verified empirically + runner source) and the backlog survived a
full re-verification with **zero** stale items. But the review surfaced: **5 verified
security gaps** (checklist BOLA, room-task index IDOR, floor/room delete owner gap, taskTier
reward inflation, task-sync replay — CR-053-01..05/07/08), 1 real recurrence correctness bug
(once/one_time), a large **verified-dead layer** (~40 dead store members + a dead store
barrel, ~10 dead service/mapper methods, an orphaned entity+mapper+table cluster, dead
migration steps — all with recorded proofs, CR-053-15), a **42-route disposition decision**
for the user (CR-053-14), a systemic 404/500 mapping gap, a 141-key l10n artifact gap
invisible to the current checker, and heavy marketing-era drift in the root README.
All findings are logged; nothing security-relevant was auto-fixed.

**Known deviations re-confirmed (never auto-fixed):** `RewardConfigController::update`
`@AdminRequired` + no owner gate + test-only consumers (CR-053-05); `filteredDaily`
service locator + mapper threading; `IDBConnection` in 7 services (CR-SVC-40 scope 1→7);
POST→GET creates (BUG-ROOM-001 updated); server-TZ calendar semantics (CR-053-12).

---

## Phase 0 — Baseline (2026-08-27)

### Gate results (reference state)

| Gate | Result |
|------|--------|
| Jest (`npm test`, incl. `test:noise` run) | **82 suites / 847 tests passed**, 0 failed |
| `npm run test:noise` | clean (0 error-level log noise) |
| `npm run lint` (ESLint src + e2e) | clean |
| `npm run stylelint` | clean |
| `npm run l10n:check-keys` | pass (de/en synchronized) |
| `npm run docs:encoding` | pass (664 files) |
| PHPUnit unit (Docker) | **886 tests / 2437 assertions OK, 0 skips** |
| PHPUnit integration (Docker, MariaDB) | **200 tests / 582 assertions, 24 skipped (pre-existing set)** |
| `composer cs:check` (Docker, no cache) | **0 fixable** (after 1 auto-fix, see below) |
| E2E (Playwright) | not re-run (baseline; per plan §4 only blocking chromium-shaped project if `src/` deletions land) |

### Phase 0 auto-fixes (review-skill §5, coding standard)

| File | Fix | Gate after |
|------|-----|-----------|
| `tests/integration/Controller/HouseControllerIntegrationTest.php:8-9` | import order (`ActivityService` before `AppLogger`) — pre-existing `cs:check` violation on the incoming tree (1/267 → 0) | cs:check re-run clean |

### Phase 0 environment findings

| # | Sev | Where | Finding | Disposition |
|---|-----|-------|---------|-------------|
| ENV-001 | minor | `composer.lock` | content-hash stale vs `composer.json` (`composer validate`: "lock file is not up to date") — every container start warns; harmless at runtime | → Phase 6 disposition |
| ENV-002 | minor | `docker/test-entrypoint.sh` | non-idempotent against a used DB volume when the NC container layer is recreated: `occ status` check misses the installed state, `maintenance:install` fails with "The Login is already being used" → entrypoint `exit 1` (container restart loop). Canonical `down -v` + `up -d` recovers; cost: one avoidable clean reinstall. | → Phase 6 disposition |
| ENV-003 | note | `lib/Migration/` file set | inventory shows **no Version1014** while 1004 and 1013 each appear twice (one date-named variant each, e.g. `Version20260228131728`). Needs Phase 1 chain-consistency check (may be benign naming, must be verified). | Phase 1 |

### Frozen inventory baseline (re-verified 2026-08-27, corrects plan §9)

**Backend:** 104 PHP files in `lib/`, **22,845 lines**; Controller dir = 15 controllers + 2 support
(`ControllerErrorFormatter` 92, `ControllerLoggingTrait` 75); worst `RoomTaskController` **1053** ✓,
`TaskController` **962** ✓. 22 services, worst `RoomTaskService` **945** ✓ (then RoomService 573,
GameLogicService 571, RewardConfigService 538, TestDataService 446). 13 mappers (worst
`CompletedTaskMapper` **671** ✓), 13 entities (worst `RoomTask` **771** ✓). 28 migration files.
**26 files >300 lines** (8 controllers, 12 services, 6 Db).

**Frontend:** 95 files in `src/` (92 js/vue + 3 css); components **50**, views **9**,
store dir is `src/store/` with 13 files = **11 Pinia stores** (+ barrel `index.js` +
`userAutoInit.js` plugin) — plan §9 said "13 stores"; corrected.
**38 files >300 lines** (18 components, 7 stores, 6 views, 1 util — `icons.js` 582);
worst `TaskList.vue` **1094** ✓, `Settings.vue` **982** ✓.

**Tests:** 77 PHPUnit unit files; 35 integration files (34 test classes + base);
82 Jest specs (81 under `tests/unit/` + `src/router.spec.js`); 41 Playwright specs.

**Orphan candidates — all 16 verified orphans (0 production callers each):**
`AvailabilitySettings` + mapper (only `TestDataService` injects the mapper, never calls it —
`TestDataService.php:11,73,84,94`); `src/utils/mobile.js` (test-only import);
`src/utils/performance.js` (test-only import; **also carries the 1 raw `console.log`** at
`performance.js:116`); `TITLE_PACKS` (`src/constants/titles.js:13`, zero importers);
`HouseUser::{updateStreakWithAbsence, getRoleLabel, getRank, incrementStreak}`;
`CompletedTask::isRecent`; `MedalMapper::{findByWeek, findByUserAndWeek, getMedalCountsByType}`
(`findByUserAndWeek`: zero callers even in tests); `MinigameScoreMapper::findByHouseAndGame`
(test-only); `TaskSyncService::getRoomTask` (`:349`, zero callers anywhere — the live sibling is
`getRoomTaskForHouse`); `Task::validate()` + `TaskChecklistItem::validate()` (test-only).

**Known deviations re-confirmed:** `RewardConfigController::update` `@AdminRequired`
(line 49), no `requireHouseOwner`, no frontend consumer; `RoomTaskController::filteredDaily`
`\OC::$server` at `:1022`; `RoomService` + `FloorService` inject `IDBConnection` directly;
legacy alias routes ×4 (`floor#createAlias` :14, `room#createAlias` :15,
`floor#createExplicit` :20, `room#createExplicit` :32). Routes total **110** ✓.
`info.xml` registers 3 background jobs; notifier registered programmatically
(`Application.php:36`, documented in info.xml). Marker hygiene: 1 TODO
(`lib/Db/TaskMapper.php:238`), 0 FIXME, 0 HACK.

---

## Phase 1 — Persistence (2026-08-27)

Method: 4 parallel `reviewer` subagents (correctness / architecture+migrations / security /
perf-quality) over all 13 entities + 13 mappers + 28 migrations; main agent re-verified every
major/block claim against source before recording (including the NC migration runner in the
test container).

### L1-1. Migration chain — VERIFIED WORKING, with a dead upgrade-path cluster

The suspected "fresh install breaks" defect is **disproven empirically and by runner source**:
all 28 migrations executed clean on the fresh `down -v` volume (oc_migrations 28/28).
Root cause of the reviewer's alarm: `Installer.php:105` calls `migrate('latest', !$previousVersion)`
→ on fresh install `migrateSchemaOnly()` runs — it merges all `changeSchema()` outputs, applies once,
**never calls pre/postSchemaChange**, and marks everything executed. Consequences, verified:

| ID | Sev | File:line | Finding | Disposition |
|----|-----|-----------|---------|-------------|
| CR-P1-01 | minor | `lib/Migration/Version1018Date20260321000000.php:30-79` | preSchemaChange adds `task_tier` (discarded by runner), postSchemaChange UPDATEs it (never runs on fresh install). Entire step is a no-op on fresh installs; would abort only on an upgrade path, which pre-release rules exclude. | delete step (Phase 6 candidate, gates: fresh-install replay) |
| CR-P1-02 | minor | `Version1017Date20260321000000.php` | Raw-SQL rename step, all three branches guarded no-ops on fresh install (coins created in 1004; targets never exist). Plus `\OC::$server` service locator at :44. | delete step |
| CR-P1-03 | minor | `Version1019Date…` / `Version1020Date…` | `coin_multiplier` / `flex_days`+`ideal_due_date` added in preSchemaChange → discarded; canonically re-created by Version1027 changeSchema. No-op steps. | delete both steps |
| CR-P1-04 | minor | `Version1024…:86-97` / `Version1025…:96` | postSchemaChange backfills (visibility, titles) never execute on fresh install; pure upgrade-path code (+ `is_private = 1` integer-vs-boolean compare inside 1024's dead backfill — would fail on PostgreSQL if it ever ran). | delete backfill blocks, keep changeSchema |
| CR-P1-05 | minor | `Version1002…:39` / `Version1003…` / `Version1010…` / `Version1015…:159,174` | Dead forward-reference guard blocks (table created later or never): 1002 completed_tasks block skipped; 1003 whole step no-op (1004 creates the column); 1010 duplicate conflicting house_users create (guard no-op; documents wrong schema incl. is_active); 1015 two guard blocks for table names that never exist. | delete dead blocks/steps |
| CR-P1-06 | minor | migration naming | Duplicate `1004` prefix (two files) + identical date parts `Date20260301210000` on 1010/1013; no Version1014. Runner sorts version-then-date (verified in oc_migrations order) — legal but invites ordering mistakes. | record convention in docs; optional renumber (pre-release allowed) |

### L1-2. Correctness / security majors (verified by main agent)

| ID | Sev | File:line | Finding | Disposition |
|----|-----|-----------|---------|-------------|
| CR-P1-07 | **major** | `lib/Db/RoomTask.php:255,609` vs `lib/Service/TaskService.php:346` | Recurrence vocabulary divergence: RoomTask branches on `'one_time'`, TaskService validates `['recurring','once','bounded']`. A task stored as `once` (validation-accepted) never hits the completion deactivate branch → silently rescheduled as recurring forever; `one_time` values would be rejected on update. | backlog (behavior change; needs vocabulary decision + data check) |
| CR-P1-08 | **major** | `lib/Db/TaskMapper.php:90,108` (reached via `TaskService.php:260,270` → `TaskController.php:571,612`) | Visibility-model bypass: `findByCategory` + `searchByName` have no private/admin-only predicates (contrast `findVisibleByHouse`:230 used by the main list at TaskController:67). Category/search endpoints expose ownerUserId/instructions of other users' private tasks to any house member. | security backlog (never auto-fix); consumer check + endpoint decision in Phase 3 |
| CR-P1-09 | **major** | `lib/Db/Task.php:138-141,205-206` + `TaskSyncService.php:238,248` | Schema drift: entity fields reminderType/reminderMinutesBefore have **no columns in any migration** (grepped all 28). Hydration reads null silently; any `set`+flush path would 500 on all engines (TaskSyncServiceTest:320 exercises exactly that against mocks only). | backlog: add columns or delete fields (feature is dead) |
| CR-P1-10 | major | `lib/Controller/ChecklistController.php:63,109,150,181,224` (+ mapper-level) | Checklist item CRUD has house-membership gate but no task-visibility gate — member/child can read/mutate checklist items of owner/admin-only tasks; `toggleItem` (:298) additionally doesn't validate itemId belongs to the room task's task (progress-row corruption). | security backlog (Phase 3 re-verification; never auto-fix) |

### L1-3. Correctness / robustness minors

| ID | Sev | File:line | Finding | Disposition |
|----|-----|-----------|---------|-------------|
| CR-P1-11 | minor | `lib/Db/CompletedTaskMapper.php:115,155` | `SUM(CASE WHEN was_combo = 1 …)` compares doctrine-boolean column to integer — PostgreSQL would error (`boolean = integer`); fine on MariaDB/SQLite. Docblock claims portability. | backlog (portability cluster incl. CR-P1-04's compare) |
| CR-P1-12 | minor | `lib/Db/RoomTask.php:365` + `:400-417` | Weekly-static next-due skips the imminent target day when completed on its eve (`findNextWeekday` requires target > current day while `$from` already sits on next day); diverges from `projectWeeklyWeekdays` inclusive anchor (:724). Upcoming occurrence lost. | backlog |
| CR-P1-13 | minor | `lib/Db/CompletedTaskMapper.php:120,161,399,442,488,571,662` | Inclusive `lte` end bound vs the codebase's `[start, end)` day-window convention: completion exactly at next Monday 00:00 counts in both weeks' medal windows. | backlog (behavior change, regression tests required) |
| CR-P1-14 | minor | `lib/Db/RoomTaskChecklistProgressMapper.php:86-103` | `toggle()` unlocked read-modify-write (lost toggle) + insert race against uniq_prog_item (500). Codebase precedent: lockForUpdate. | backlog |
| CR-P1-15 | minor | `lib/Db/HouseUserMapper.php:133` | `createOrGet` check-then-insert race on uniq_house_user → loser gets unhandled UniqueConstraintViolationException. | backlog |
| CR-P1-16 | minor | `lib/Db/FloorMapper.php:93` | `create()` hand-rolls INSERT + re-select by (house,name,sort_order) ORDER BY id DESC instead of `$this->insert($entity)` — wrong-row window + diverges from all sibling mappers. | backlog (auto-fixable-ish; needs test) |
| CR-P1-17 | minor | `lib/Db/MinigameScoreMapper.php:126` + `:53,:36` | `getUserRank` streams unbounded rows into PHP (grows with every play; backs MinigameController:233); no tiebreaker anywhere → arbitrary ranks. | backlog |
| CR-P1-18 | minor | `lib/Db/Task.php:308` + `TaskMapper.php:162` + schema | Dead `$isPrivate` param on create(); `setIsPrivate` hydration shim (pre-release rule forbids shims); is_private column stays 0 for new rows while visibility_type='private' — divergent denormalized state. | backlog: drop column+field+shim in one migration cleanup |
| CR-P1-19 | minor | `lib/Db/Task.php:355` | `validate()` never cross-checks estimatedMinutesMin ≤ Max (inverted range renders "30-10 min"). | backlog (with CR-P1-24 decision) |
| CR-P1-20 | minor | `lib/Db/Room.php:44,85` | Phantom API field: `$description` has no column; jsonSerialize always emits ''. | backlog (delete field or add column — API contract check) |
| CR-P1-21 | minor | `lib/Db/HouseUserMapper.php:218` | `findActiveByHouse()` queries `is_active`, which final house_users schema never had → would throw if ever called; currently 0 callers. | delete with orphan cluster (below) |
| CR-P1-22 | minor | `lib/Db/TaskMapper.php:230` + `AvailabilitySettingsMapper.php:37` | `findVisibleByHouse($houseId)` never uses $houseId (misleading dead param); AvailabilitySettingsMapper catches `\Exception` instead of DoesNotExistException (swallows DB errors → duplicate-INSERT path). Latter moot if orphan cluster deleted. | param: backlog; catch: auto-fixable only if class kept |

### L1-4. Verified-dead code (no-caller proofs by 2 independent agents; deletion list for Phase 6)

Cluster A — **AvailabilitySettings** (entity 255 + mapper 122 + `chorestrike_availability` table via Version1013 + TestDataService injection `:11,73,84,94` (never called) + fixture `tests/fixtures/TestDataFixtures.php:244` + RESET_TABLES entry): 0 production callers. Docs stale (ADR-005, architecture.md:301) → Phase 5.
Cluster B — entity/mapper helpers: `HouseUser::{updateStreakWithAbsence:219, getRoleLabel:254, getRank:266, incrementStreak:283}`, `CompletedTask::isRecent:135`, `RoomTaskChecklistProgress::wasCheckedRecently:70`, `Medal::{getDescription:85, getRarity:99}`, `MedalMapper::{findByWeek:94, findByUserAndWeek:112, getMedalCountsByType:149}` (findByUserAndWeek: zero callers even in tests), `MinigameScoreMapper::findByHouseAndGame:29`, `Task::validate:340`, `TaskChecklistItem::validate:63` (each: test-only or zero callers).
Cluster C — mapper methods (newly flagged; **verify-before-delete in Phase 6**): `CompletedTaskMapper::{findByUserId:46, findByRoomTaskId:83, getUserStats:107, findInDateRange:213, getMaxRoomCompletionCountByUser:390}` (findInDateRange additionally unscoped across houses), `HouseUserMapper::{getActiveUsers:189, findByHouse:208, findByHouseAndRole:237}` + alias chain findByNextcloudUserId→findByHouseAndUser→findByHouseIdAndNextcloudUserId, `HouseMapper::findAll:60` / `RoomMapper::findAll:125` no-op overrides, `RoomTaskMapper::findByScopeId:246` (permanently-empty pathway: scope writer never existed).
Cluster D — orphaned tables: `chorestrike_task_scopes` (V1001), `chorestrike_task_parts` (V1008), `chorestrike_claims` (V1009) — no entity/mapper/writer anywhere.

### L1-5. Architecture / quality notes (record-only)

- `RoomTask` (771 lines) embeds a ~500-line scheduling engine (markCompleted, recurrence projection, ideal-due) — service-extraction candidate, mapped into the CR-SVC-15 god-service family; **no refactor in this review**.
- Calendar-day logic uses server default TZ everywhere (RoomTask:185-221, RoomTaskMapper:143,146,360,393, CompletedTaskMapper day boundaries) — architectural decision needed (user-TZ threading), backlog.
- Copy-paste clusters (auto-fixable, rule-of-three exceeded): day-boundary preamble ×6 (`CompletedTaskMapper:187,308,331,358` + `RoomTaskMapper:360,393`); rooms→floors house-scope JOIN ×3 inside CompletedTaskMapper (RoomTaskMapper::applyHouseScope:552 is the precedent).
- Per-quality nits: `RoomTaskChecklistProgressMapper::getProgressStats:141` double COUNT round-trip; `RoomTaskMapper::findByNextcloudTaskId:227` findEntities+[0]??null pattern; missing @param blocks (TaskMapper::create:138+, MinigameScoreMapper:62,126, AvailabilitySettingsMapper ×4); stale docblock `Room::updateCleanliness:98`; oversized-file record: RoomTask 771, CompletedTaskMapper 671, RoomTaskMapper 641, Task 406, HouseUser 343, HouseUserMapper 336.
- API/UX note deferred to L3: LIKE-wildcard injection in searchByName (`%`/`_` unescaped — enumeration aid on top of CR-P1-08, no SQL injection).

**Phase 1 verdict:** persistence layer functionally sound on MariaDB (unit+integration green; fresh install verified), but the chain carries a large dead upgrade-path cluster (L1-1), two verified security-relevant gaps reach the API surface (CR-P1-08/10 → Phase 3), one real recurrence correctness bug (CR-P1-07), and ~20 verified-dead code/table items ready for the Phase 6 deletion pass.

## Phase 2 — Services (2026-08-27)

Method: 4 parallel `reviewer` subagents (gamification correctness / architecture+orphan sweep /
security / perf-quality) over all 22 services + 3 jobs + 2 commands + notifier + title catalog.
Main agent re-verified every major claim against source (all ✅ below were personally confirmed;
one reviewer claim **corrected**: duplicate house_users rows are impossible — `uniq_house_user`
exists at `Version1004:50`; the loser of the bootstrap race gets an unhandled 500, not a dup row).

### L2-1. Security (verified — all auto_fixable=false, → backlog)

| ID | Sev | File:line | Finding (verified) | Disposition |
|----|-----|-----------|--------------------|-------------|
| CR-P2-01 | **major** | `lib/Controller/RoomTaskController.php:113-131` | IDOR: `index()` passes raw client `roomId`/`scopeId` to `getRoomTasks`/`getScopeTasks` with only the caller's-house membership gate — cross-house room-task reads incl. assignees by scanning ids (roomStats() got the fix in CS-PLAN-040, index() didn't). | backlog (P1 security) |
| CR-P2-02 | **major** | `lib/Controller/FloorController.php:365-372` + `RoomController.php:389-396` | Floor/room deletion gated only by `ensureHouseMember` + same-house ownership — no `requireHouseOwner`, although security.md RBAC and the CS-PLAN-042 owner-only Settings UI both intend owner-only. Any member (child role) can cascade-delete structure. | backlog (P1 security) |
| CR-P2-03 | **major** | `lib/Service/TaskService.php:38` (`MUTABLE_FIELDS`) + `GameLogicService.php:232,528` | `taskTier` mass-assignable via updateTask with no closed-set check and no owner gate (TaskController gates only ownerUserId) → any member can set `gold` on any public task = 2.0× coins via `getTaskTierMultiplier` on every completion. Self-benefiting reward inflation. | backlog (P1 security) |
| CR-P2-04 | **major** | `lib/Service/AuthorizationService.php:135-146` + `HouseService.php:166-177` + `Version1000:39` | Bootstrap races: `idx_house_inst` is a NON-unique index → concurrent first requests can create two houses per instance and split users; `ensureHouseMember` computes `role='owner'` when `count==0` → two *distinct* users first-touching concurrently both become owner. (Correction: house_users rows themselves are protected by `uniq_house_user`.) | backlog (P1 security; unique index + race-safe owner assignment) |
| CR-P2-05 | **major** | `lib/Service/TaskSyncService.php:140-188` | `syncCompletionFromNextcloud` replay: conflict check only skips when local completion is NEWER, so re-POSTing a marginally newer timestamp re-runs `markCompleted` + cleanliness bump per call (next_due fast-forward, permanent 100 cleanliness, bypasses the canonical per-day duplicate guard); 3 dependent writes without a transaction. | backlog (P1 security/integrity) |
| CR-P2-06 | major | `lib/Service/RoomTaskService.php:415-458` | `setAssignees`/`addAssignee`/`removeAssignee` never validate the room task belongs to `$houseId` (plain `find()`), unlike `assignTask:323` — contract violation (ADR-006); currently saved only by controller preambles (:390/427/475), so latent, not HTTP-exploitable today. | backlog (defense-in-depth) |
| CR-P2-07 | minor | `lib/Controller/RoomController.php:420-437` | PUT cleanliness lets any member apply arbitrary −100..+100 repeatedly, bypassing the completion-based cleanliness economy. | backlog (Phase 3 re-check: consumer + gate) |
| CR-P2-08 | minor | `lib/Controller/FloorController.php:51-71` + `RoomController.php:56-88` | GET performs creates (the documented POST→GET rewrite workarounds, BUG-ROOM-001 family) — NC CSRF only guards POST/PUT/DELETE → cross-site forged GET creates structure. | Phase 3 route disposition (alias/legacy routes already deletion candidates) |
| CR-P2-09 | minor | `lib/Controller/TaskController.php:405-407` | `ownerUserId` transfer accepts any string — ownership of a private task can be moved to a non-member (permanently orphaned). | backlog |
| CR-P2-10 | minor | `lib/Controller/SetupController.php:107-115` | hasFloors() checked outside applySetup's transaction → concurrent applies duplicate the structure. | backlog |

### L2-2. Correctness (verified)

| ID | Sev | File:line | Finding (verified) | Disposition |
|----|-----|-----------|--------------------|-------------|
| CR-P2-11 | **major** | `lib/Service/MinigameService.php:163` | `new \DateTime('monday this week')` resolves to NEXT Monday on Sundays → weekly leaderboard empty/wrong all Sunday. Sibling `GameLogicService::getWeekStart:495` does it correctly. | backlog (small fix + test) |
| CR-P2-12 | **major** | `lib/Service/GameLogicService.php:131` + `lib/Db/HouseUser.php:294-300` | Completion-path freeze consumption never stamps the covered day (`consumeFreeze()` just decrements) → a missed day already paid for by StreakFreezeJob can consume a second freeze at next completion. Job path got CR-SVC-03 idempotency, completion path none. | backlog |
| CR-P2-13 | **major** | `lib/Service/StreakAtRiskService.php:75` + job wiring | At-risk pass assumes it runs BEFORE the freeze pass, but DailyNotificationJob and StreakFreezeJob are independent TimedJobs with undefined relative order → the core at-risk notification is suppressed whenever the freeze job runs first. | backlog (merge passes / define order) |
| CR-P2-14 | **major** | systemic (`StreakFreezeService:43`, `HouseUser:176`, `RoomTaskMapper:393`, `GameLogicService:261`…) | All calendar-day semantics bucket at server-TZ midnight (NC = UTC) — freezes, at-risk, due-today, streak days flip around local midnight for any non-UTC household. Same family as L1 TZ note (CR-P1 record). | backlog (needs a per-house TZ design decision) |
| CR-P2-15 | minor | `lib/Db/HouseUser.php:308-310` | Weekly restock SETS `min(perWeek, cap)` instead of topping up → users with earned combo-bonus freezes are knocked DOWN every week (contradicts awardBonusFreeze). | backlog (small fix + test) |
| CR-P2-16 | minor | `lib/BackgroundJob/StreakFreezeJob.php:31` (+ siblings) | TimedJob semantics: skipped cron days are never processed (only 'yesterday'), and interval-based runs can't pin '06:00'/'Monday 00:05' as comments claim. | backlog |
| CR-P2-17 | minor | `lib/Notification/ChoreStrikeNotifier.php:85` | Rich param 'claimer' built from display NAME but typed as user ID → avatar/profile resolution degrades. | backlog |
| CR-P2-18 | minor | `lib/Service/NotificationService.php:103` | Medal notification dedup key (user, 'medal', weekStart) collapses multiple medals earned in the same weekly pass into one bell. | backlog (objectId + medalType) |
| CR-P2-19 | minor | `lib/Service/TaskDueReminderService.php:169` + `:77` | One orphaned room_task id aborts the ENTIRE daily reminder pass (no per-task isolation, unlike per-user precedent CR-SVC-30); also N+1 task lookups vs batch idiom. | backlog |
| CR-P2-20 | minor | `lib/Db/MinigameScoreMapper.php:53,117` | Leaderboards order by score only — tied scores shuffle / LIMIT cutoff arbitrary (separate call sites from the Phase-1 getUserRank finding). | backlog (tiebreaker) |

### L2-3. Architecture (verified)

- **IDBConnection injections beyond the known two (CR-SVC-40 family, record-only):** `HouseService:44`, `RoomTaskService:40`, `HouseSetupService:47`, `MinigameService:45`, `TestDataService:65` (plus known `RoomService:63/79`, `FloorService:38/29`).
- **Wrong-direction dependency:** `RoomTaskController::filteredDaily:1022` pulls `HouseUserMapper` from `\OC::$server` and passes it INTO `RoomTaskService::getFilteredDailyTasksForUser:913` as a method parameter although the service already constructor-injects the same mapper (`:37`).
- **Controller bypasses service:** `UserController::update:382` / `invite:175` mutate+persist `HouseUser` via the mapper directly.
- **God-service map (CR-SVC-15, record-only):** RoomTaskService 945 = ownership 79-111 · reads 119-166/538-588/669-753/776-838 · creation+template 187-296 · assignees 323-458/608-661 · lifecycle 465-531/846-892 · child filtering 909-944. RoomService 573 = CRUD+cascades 106-205/287 · stats 228-279 · **completeRoomTask monolith 317-572** (lock→gates→takeover→recurrence→cleanliness→teamwork→3 deferred notifications). GameLogicService 571 = rewards/streak/freeze/titles 82-206 · scoring math 217-284 · game state/medals 291-389/545-569 · teamwork 515.
- **Duplication clusters (≥3×, refactor candidates):** availability-skip check `StreakAtRisk:66`/`StreakFreeze:70`/`TaskDueReminder:181`; floor-chain room-task ownership `RoomTaskService:79-97` ≈ `TaskSyncService:378-396`; assignee merge `TaskDueReminder:101-114` ≈ `RoomTaskService:388-401`; visibility matrix re-implemented ×3 in TaskController (`:100-113,:350-364,:501-514`); hand-rolled membership validation ×4 (`RoomTaskService:353-365,:218-224`, `HouseSetupService:265-274`, `ActivityService:78-85`); `getInstanceId+getOrCreateHouse+ensureHouseMember` preamble ×~23 across controllers (Phase 3 note).
- No service→service cycles found.

### L2-4. Verified-dead service methods (grep-proofed; Phase 6 deletion list)

`TaskService::{getAllTasks:57, skipTask:391, rescheduleTask:404}` (routes use RoomTaskService siblings), `RoomTaskService::{getTasksAssignedToUser:687, getUnassignedTasks:697}`, `RoomService::getRoom:116`, `FloorService::getFloor:82`, `RewardConfigService::getTierMultiplier:188`, `AuthorizationService::{isAuthenticated:52, isHouseMember:86}` (0 lib callers — **check tests before deletion**), `TaskSyncService::getPendingSyncs:269` (private; kept alive only by the CR-SVC-21 reflection-guard test).

### L2-5. Quality / observability

- **Unwrapped IAE → 500 cluster** (controllers lack `InvalidArgumentException` → validation() mapping; `safeMessage` hides the message): `TaskService:162` (docblock actively claims 400), `RoomTaskService:874`, `FloorService:137`, `TaskSyncService:394` (also missing securityWarning log, unlike sibling validators), `RewardConfigService:529`. → backlog (behavior-affecting status mapping).
- Nullable-shim: `GameLogicService:46` `?TitleService = null` + null-guards `:170,:486` (pre-release rule: make required). → Phase 6 auto-fix candidate (ctor + tests).
- `TemplateService::loadJsonFile:105` silently returns [] on corrupt catalog (violates no-silent-workarounds). → backlog (needs AppLogger injection).
- Log `event`-key gaps (auto-fixable): `HouseService:275-278`, `RewardConfigService:125-130`, `RoomTaskService:619-624,:632-637`.
- Docblock fixes (auto-fixable): `MinigameService:309` (Sparks→Coins), `TitleCatalog:25` (per-house → instance-global claim).
- Perf notes: `assignTask` reads the same row 4×/request (`RoomTaskService:316-330` + controller preamble); `HouseService::hasFloors:303` hydrates all floors for a boolean; unwrapped literals `HouseService:141,152`, `TitleService:92` (nit, invisible to clients today).

**Phase 2 verdict:** the service layer's core completion path is sound (row lock, membership re-check under lock, duplicate guard), but the review surfaced **5 verified exploitable-or-integrity security gaps** (CR-P2-01..05 — all backlog, never auto-fix), 3 correctness majors in the streak/leaderboard passes, a large verified-dead method set, and a precise CR-SVC-15 decomposition map for future planning.

## Phase 3 — API surface (2026-08-27)

Method: 3 `reviewer` subagents (validation/correctness, security, architecture/routes) + 1
`explore` agent building the route↔consumer matrix. Main agent re-verified the one block-level
claim — **disproven** (see CR-P3-02 "verified clean"); all other majors verified or cross-confirmed
by ≥2 agents.

### L3-0. Route disposition matrix (109 API routes + `page#index` shell)

Consumer trace: `src/services/api.js` → store → component/view (logger-string refs excluded).
**Totals: 67 production-consumed (61%) · 30 no-consumer · 12 test-only.** The CS-PLAN-039
ledger (2026-08-21, "56 no-consumer") is now 42; route set itself unchanged since 2026-08-21
(no adds/removes; pruned stats routes still gone, enforced by `RouteMatrixTest:189`).

| Disposition group | Routes | Evidence |
|---|---|---|
| **Documented-dead aliases (deletion candidates, zero consumers incl. tests)** | `POST /floor-create`, `POST /room-create`, `POST /floors/create`, `POST /rooms/create` (the BUG-ROOM-001 POST→GET workaround family ×4) | matrix: none/none/none/none |
| **REST-completeness reads, no consumer** | `GET /floors`, `GET /floors/{id}`, `GET /rooms`, `GET /tasks/{id}`, `GET /room-tasks/{id}`; `GET /rooms/{id}` is test-only (release-task-templates.spec:112 — prune needs spec update) | matrix |
| **Dead room-task variants** | `GET /due-within-days`, `POST /{id}/assign` + `/unassign` (dead wrappers), `POST|DELETE /{id}/assignees` (no wrapper), `GET /overdue-claims` (no wrapper) | matrix |
| **Dead task wrappers** | `GET /tasks/category/{c}`, `GET /tasks/search`, `GET /tasks/categories`, `GET /tasks/my-private`, `DELETE /tasks/{id}`, `GET /users/{id}` (updateUserProfile), `GET /rooms/needing-cleaning` | matrix |
| **Dead checklist routes** | `GET /checklists/complete/{roomTaskId}` (no wrapper/test/UI) + `POST /checklists/initialize/{roomTaskId}` (store action never called — **not flagged in the ledger**, 10th dead route) | matrix |
| **task_sync surface ×6 — all none** (`complete`, `sync-from-nextcloud`, `reminders`, `pending`, `mark-synced`, `link`) | backend-only integration surface for the (unimplemented) task-sync UI; CR-P2-05 replay issue lives here | matrix + §9 declared-unimplemented |
| **Keep — test harness** | `/test/profiles`, `/test/seed/{profile}`, `/test/reset` (E2E seed/reset mechanism); `reward_config` GET/PUT (test-only — the @AdminRequired decision rides on this) | matrix |
| **Keep — production** | remaining 67 routes | matrix |

**Ledger corrections found (→ Phase 5 doc repair):** `GET /game/leaderboard` ✅→**test-only**
(UC-11 row wrong — Leaderboard.vue uses `GET /users`; `gameStore.fetchLeaderboard` reachable
only via dead `initializeStores()`); `deactivate`, `skip`, `reschedule`, `complete`, `daily`,
reward-config ×2 ❌→**test-only**. New: `initializeStores()` in `src/store/index.js` is dead code
(Phase 4). Stale test: `tests/integration/AvailabilityApiTest.php` targets routes removed
2026-07-28 (Phase 6 deletion candidate).

### L3-1. Security findings (all auto_fixable=false → backlog)

| ID | Sev | File:line | Finding | Disposition |
|----|-----|-----------|---------|-------------|
| CR-P3-01 | **major** | `lib/Controller/ChecklistController.php:57-241` (merges Phase-1 CR-P1-10) | Checklist endpoints validate house membership but never the task/item's house OR visibility: member of house A can read/create/relabel/delete/reorder items of ANY other house's tasks; within the house, owner/admin-only task items are exposed; `reorderTaskItems:208` passes a raw array whose KEYS become item ids (corruption vector); `toggleItem:298` doesn't check itemId belongs to the room task. | backlog (P1 security; ChecklistService with ownership gate) |
| CR-P3-02 | ~~block~~ **disproven** | `RoomTaskController::complete` → `RoomService::completeRoomTask:371-378` | Reviewer claimed completion lacks private/admin-only gates. **Verified false**: the canonical service path enforces private→assignee-only (:371-373), admin-only→owner (:376-378), duplicate guard (:383-390) — under the row lock. Recorded to prevent re-flagging. | verified clean |
| CR-P3-03 | minor | `TaskController::uncomplete:812`, `skip:852`, `reschedule:892` (+ `RoomTaskService` — zero isPrivate/admin-only checks, grep-verified) | Task lifecycle endpoints DON'T enforce the visibility matrix (unlike complete): any member can uncomplete/skip/reschedule another member's private task or an admin-only task. No reward theft (completion path gated), but private-task integrity. | backlog |
| CR-P3-04 | minor | `lib/Controller/TestController.php:37` + `routes.php:153-155` | Test seed/reset routes registered unconditionally with `@NoCSRFRequired`: on any deployment with NEXTCLOUD_TEST_MODE set, any authenticated user (or cross-site forged POST) can wipe the DB — guard is env-only, controller-level. | backlog (test-mode hardening: drop @NoCSRFRequired, role-gate) |
| CR-P3-05 | minor | `lib/Controller/MinigameController.php:105` (+ `MinigameService:96`) | saveScore accepts arbitrary gameId strings (no catalog whitelist) with no per-user/day cap → coin farming loop at max score bound. | backlog |
| CR-P3-06 | minor | `lib/Controller/UserController.php:418` (+ `RoomController:466` from Phase 1) | `ensureHouseMember` (auto-join primitive) used with target-derived house ids (`titles()`; `needingCleaning`) — probing ids silently ENROLLS the caller in a foreign house instead of denying. Read paths must use `assertHouseMember`. | backlog |
| CR-P3-07 | minor | `lib/Controller/UserController.php:378` | user#update persists client `schedulingPreferences` verbatim — no schema validation, unlike SchedulingController's exact 7-weekday typed schema; arbitrary JSON blobs land in house_users. | backlog |
| CR-P3-08 | minor | `lib/Controller/UserController.php:417` | Anti-enumeration inconsistent: update/selectTitle mask foreign-house ids as 404; titles/remove/updateRole surface 403 (id existence oracle). | backlog |
| CR-P3-09 | minor | `MinigameController:40,72,322` + `TaskSyncController:181` | `limit` params unvalidated (negative → SQL error 500; huge → table dump); precedent clamp exists (`ActivityService:71-72` clamps [1,200]). | backlog (boundary clamp) |
| CR-P3-10 | minor | `lib/Controller/RewardConfigController.php:41-55` | Bare DataResponse — no envelope, no try/catch, inconsistent with all 16 sibling controllers (distinct from the known @AdminRequired/owner-gate issue). | backlog (bundle with the RewardConfig decision) |

### L3-2. Correctness / validation

| ID | Sev | File:line | Finding | Disposition |
|----|-----|-----------|---------|-------------|
| CR-P3-11 | **major** | systemic: `TaskController:119,459,538`; `RoomTaskController` ×12 (166,276,328,359,390,427,475,519,550,581,621,658); `FloorController:109,287,370`; `RoomController` (all entity endpoints); `ChecklistController:181` | NotFound→404 mapping missing: endpoints catch app `NotFoundException` only where services throw it, but services/mappers throw OCP `DoesNotExistException` unconverted → nonexistent/foreign ids return **500** instead of 404; contract diverges within single controllers (fromTemplate/roomStats do map it). | backlog (one pattern: wrap in service validators + per-controller catch) |
| CR-P3-12 | **major** | `FloorController:58-70`, `RoomController:66-87` | Untyped JSON-body branches pass raw values into strictly-typed `create()` params under strict_types → malformed body raises **TypeError** (an Error, bypasses every catch) → framework 500 outside the error envelope. | backlog (fold into the GET-create removal / route disposition) |
| CR-P3-13 | minor | `TaskService:353-355` via `TaskController::update` | Malformed date strings throw unguarded `\DateTime` parse exceptions → 500 instead of 400 (create() pre-validates the same fields; siblings diverge). | backlog |
| CR-P3-14 | minor | `RoomController:474` + `RoomMapper:106-118` | `needingCleaning` without floorId queries unscoped (no house filter — safe only via the single-house-per-instance invariant). | backlog |
| CR-P3-15 | minor | `appinfo/routes.php:23+` | No `requirements` on any route parameter → garbage ids hit typed controller signatures (TypeError→500) instead of route-level 404. | backlog (mechanical) |
| CR-P3-16 | nit | `SchedulingController:285` vs `:264-268` | Weekday case handling diverges between the two validators of the same stored preferences. | backlog |

### L3-3. Architecture / quality

- **Silent exception swallowing in 10/15 controllers** (major — violates CS-PLAN-052's AppLogger contract and `ControllerErrorFormatter:21`'s "must be logged at the call site"): RoomTaskController (all 15 endpoints), Checklist, Floor, Minigame, Page, Room, TaskSync, Test, User (+ SetupController's weaker hand-rolled variant logging only `get_class($e)`). Only Task/Game/House/RewardConfig/Scheduling use the trait. → backlog (trait adoption sweep).
- **Thin-controller map (record for future extraction):** `TaskController::create:146-329` (~184 lines: triplicated date parsing, visibility whitelist, admin-only gate, owner assignment, isPrivate shim, 24-positional-arg service call); `update:336-479` (visibility gate ×3 verbatim copies `:100-113/:345-364/:501-514`, transition gates); `complete:742-805` (matrix that the service already enforces — candidate for deletion once CR-P3-03 centralizes); `mapDailyTaskForApi:942-961` (derived domain state in controller); `RoomTaskController::show:161-206` (flex-window computation) + `bulkAssign:689-751` (4th copy of the userId validation block); `ChecklistController` injects mappers directly and orchestrates progress logic (:104-107, :340-347, :393-408); `FloorController:214-275` ≈ `RoomController:280-341` (60 lines verbatim param-resolver duplication).
- **Pre-release shim violations:** isPrivate→visibilityType mapping in `TaskController:247-251,366-377` ("frontend sends both") — pre-release rule says fix the frontend, delete the shim. Ties CR-P1-18.
- Dead (auto-fixable): `getRequestMethod()` ×2 (`FloorController:206`, `RoomController:272`); `ControllerLoggingTrait::{logControllerWarning:46, logControllerInfo:57}` zero call sites; `Application.php:30` inaccurate NC-version comment; inline `\OCA\...\Task::` FQCNs ×~15 in TaskController.
- File-size record: 8/15 controllers >300 lines (RoomTaskController 1053, TaskController 962, UserController 526, RoomController 492, ChecklistController 437, FloorController 431, MinigameController 347, SchedulingController 320).
- Routes hygiene: `house#get` vs sibling `show` naming; task#complete PUT vs room_task#complete POST verb policy drift; `scheduling#getSuggestions` POST name mismatch; checklist `taskId` as undeclared query param on routes declaring only `{roomTaskId}` (`routes.php:137-138`, pairs with the dead initializeProgress route).
- `info.xml:40` `php max-version="8.4"` gap: NC 31 supports PHP 8.4, app caps at 8.3 → decision item.

**Phase 3 verdict:** the route surface is over-provisioned (42 non-production routes incl. a fully unconsumed task-sync surface), the checklist controller is the security weak spot (CR-P3-01), the 404/500 mapping cluster is systemic, and one scary block claim was disproven by source (completion gates are enforced in-service).

## Phase 4 — Frontend (2026-08-27)

Method: 4 parallel `reviewer` subagents (store correctness / security / architecture+dead code /
i18n+perf+quality); main agent re-verified the surprising majors (l10n missing keys sampled,
weekly-goal mechanism read, constants/utils liveness re-grepped).

### L4-1. Correctness

| ID | Sev | File:line | Finding (verified) | Disposition |
|----|-----|-----------|--------------------|-------------|
| CR-P4-01 | major | `src/store/game.js:143-153` | Weekly-goal false celebration: `previousAchieved` reads the pre-update state, so the first fetch after app load replays the false→true transition; `setWeeklyGoal` emits `chorestrike:weekly-goal-reached` (toast+confetti+sound) and the subsequent `dismissed` flag only mutates an unrendered store row — "Weekly goal reached!" fires on every Dashboard mount while the goal is achieved. | backlog (celebrate only on in-session transitions) |
| CR-P4-02 | major | `src/store/minigame.js:397` | `initialize()` sets loading=true then awaits `Promise.all` where fetchBoostCatalog rethrows while the others swallow → any catalog failure leaves loading stuck true (Arcade view permaloading until reload). | backlog (try/finally) |
| CR-P4-03 | major | `src/store/roomTasks.js:484` (+ `user.js:244-301`) | `completeRoomTask` leaves rooms cleanliness, houseHealth, roomStats and user coins/streak stale; the user-store update actions that would reconcile (`addCoins/addContributionScore/updateStreak`) have **zero call sites** (server rewards payload unused). Note: this collides with the dead-code list (L4-4) — the decision is *wire the actions or delete them*, not both. | backlog (needs product decision) |
| CR-P4-04 | minor | `src/store/roomTasks.js:584` + `:431-434` + `:486-489` | Undo path updates roomTasks+dailyTasks asymmetrically vs reactivate/complete (slice drift after undo of one-time tasks). | backlog |
| CR-P4-05 | minor | `src/store/house.js:26` | `houseHealth ?? 100` written as `\|\| 100` → legitimate 0 (critical house) renders as 100/Excellent. | backlog (one-char fix + spec) |
| CR-P4-06 | minor | `src/store/roomTasks.js:37+` | Date getters: `new Date(null)` → epoch counted as overdue (createTaskWithRoomTask posts nextDue:null) and date-only strings parsed as UTC → due-today buckets shift by timezone. | backlog |
| CR-P4-07 | minor | `src/store/roomTasks.js:138,153` vs `constants/scheduling.js` localDateKey | Two coexisting calendar-day conventions (UTC slice vs local key) — TaskCalendar and Scheduling overcapacity can assign the same task to different days for non-UTC users. | backlog (align on localDateKey; same TZ family as CR-P2-14) |
| CR-P4-08 | minor | `src/store/roomTasks.js:827`, `house.js:106` | No request sequencing on fetchRangeTasks/fetchActivity — out-of-order responses overwrite newer state (calendar prev/next). | backlog |
| CR-P4-09 | minor | `src/store/user.js:133` | inviteUser pushes without id dedup → duplicate member row on retried invite. | backlog |
| CR-P4-10 | minor | `src/store/index.js:71`, `rooms.js:415` | resetAllStores omits the setup store; rooms reset() keeps expandedFloors (stale pre-expansion after reset). | auto-fixable cluster (Phase 6) |

### L4-2. Security

**v-html audit: CLEAN — zero v-html usages in all 95 src/ files**; the only innerHTML sinks are
static (`main.js:56` static error string; `utils/icons.js:94` build-time Fluent SVG assets).
All user content renders via escaped interpolation. Recorded to prevent re-auditing.

| ID | Sev | File:line | Finding | Disposition |
|----|-----|-----------|---------|-------------|
| CR-P4-11 | nit | `src/main.js:50` | `window.chorestrike = { app, pinia, router }` exposed in production builds (debug handle for any injected script). | backlog (gate behind NODE_ENV) |
| CR-P4-12 | nit | `src/utils/icons.js:94`, `Emoji.vue:254` | Latent footguns only ($attrs spread could override innerHTML; Emoji interpolates name into URL/class) — no current sink; keep clean-by-construction note. | record |

### L4-3. Dead code — verified deletion candidates (grep-proven; Phase 6 sweep, pairs with L3 route disposition)

- **`src/store/index.js` is dead as a whole** (barrel + `initializeStores:35` + `resetAllStores:71` + `clearAllStoreErrors:96` + `getAnyStoreLoading:121` + `getAnyStoreError:148` + `getStoreSummary:175` + re-exports): only consumer is its own spec; `main.js` boots `userAutoInit.js` directly. Also transitively kills `houseStore.initializeHouse:163` (only caller was initializeStores).
- **game.js**: getters userMedals/topContributors/hasActiveCombos/householdTotalCoins/householdTotalContribution/teamStreak/weeklyGoalProgress/isWeeklyGoalAchieved/pendingCelebrations/userStreakFreezes; actions addCombo/removeCombo/clearCombos/calculateContributionScore/updateHouseholdProgress/updateTeamStreak; **transitively dead** `initializeGame:452` + `fetchLeaderboard:166` + `leaderboard` state slice (matches L3: route test-only).
- **user.js**: getters getTopContributors/activeUsers/userDisplayName/isHouseMember/contributionScore (coins/currentStreak: verify); actions updateUserStats/addContributionScore/addCoins/updateStreak (see CR-P4-03 tension); getUserStats/canPerformAction/initializeUsers/updateUserProfile.
- **rooms.js**: actions fetchFloors/fetchRooms/fetchFloorWithRooms/fetchRoomsNeedingCleaning; state currentFloor/currentRoom + setCurrentFloor/setCurrentRoom; getter roomsNeedingCleaning.
- **tasks.js**: actions deleteTask/searchTasks/fetchTasksByCategory/fetchTaskCategories/initializeTasks; getters tasksByCategory/highPriorityTasks/mediumPriorityTasks/lowPriorityTasks.
- **roomTasks.js**: getters getRoomTaskById/activeRoomTasks/inactiveRoomTasks/overdueRoomTasks/dueTodayRoomTasks/dueSoonRoomTasks/roomTasksByUser/unassignedRoomTasks/assignedRoomTasks/totalActiveTasks; actions fetchRoomTaskWithDetails/assignRoomTask/unassignRoomTask/deactivateRoomTask/fetchDueWithinDaysTasks/fetchMyPrivateTasks (mirror the dead backend routes).
- **checklist.js**: initializeProgress + getCompletionPercent/isItemCompleted. **scheduling.js**: 9 getters superseded by `utils/energyBudgets.js` (CS-PLAN-043 canonicalization) incl. availableWeekdays/unavailableWeekdays/isAvailableOn/getEnergyForDay/todayRemainingEnergy/getMemberAvailability/getAvailableMembersForDay/getTotalEnergyForDay/averageDailyEnergy.
- **minigame.js**: canAffordBoost/selectedBoostsCost/isBoostActive/affordableBoosts/toggleLeaderboard+showLeaderboard/clearPurchasedBoosts.
- **utils**: mobile.js + performance.js (whole files, Phase 0 confirmed; performance.js also carries the one raw console.log); `TITLE_PACKS`; `localDateKey` export keyword; `roomIcons.js` internal `getRoomIconOption`/`ROOM_ICON_OPTIONS` (verify-before-delete — file itself alive via iconForRoomType/resolveRoomIcon).
- **ALIVE (recorded so future sweeps stop re-flagging):** router 9/9 views live; constants scheduling.js + taskVisibility.js + titles.js (TIER_STYLES/titleLabel) live; composables live.

### L4-4. i18n (major — verified by sampling)

| ID | Sev | Finding | Disposition |
|----|-----|---------|-------------|
| CR-P4-13 | **major** | **141 distinct t() keys used in src/ are missing from en.json AND de.json** (de/en mutual sync is perfect — the checker only tests that). Sampled & confirmed: 'All Tasks' (TaskManager:23), 'Danger Zone' (Settings:187), 'Arcade', energyLabels 'Quick'/'Easy'/… (TaskEditForm:323 via :53). German users get English fallback across TaskManager filters, Settings floor management, Arcade, minigames. | backlog (regenerate artifacts + extend `check-l10n-keys.js` with src-usage diff) |
| CR-P4-14 | minor | 8 case-variant key mismatches — German translation exists under different casing and never renders ('Edit floor' vs 'Edit Floor', 'completed' vs 'Completed', 'Just now'/'just now', 'Play Again'/'Play again', 'week/month' vs 'Week/Month' + missing plurals). | auto-fixable cluster (align casing + re-export) |
| CR-P4-15 | minor | ~340 dead translation entries (template.* ×25 + ~90 task-template instruction texts + legacy singles); exception: 12 `titles.*` keys are server-driven (TitleCatalog labelKeys) — whitelist, don't delete. | backlog (prune + checker whitelist) |
| CR-P4-16 | nit | de.json register drift: two formal 'Sie' strings (:464, :346) in an informal-du app. | auto-fixable |

### L4-5. Quality notes

- Hardcoded-string template audit: **clean** — zero unwrapped text nodes / static aria-labels; all 67 toast calls wrapped. The i18n gap is artifact-side only (CR-P4-13).
- Console audit: confirmed exactly 1 raw call (`performance.js:116`), inside a deletion-candidate file.
- `mobile.js:118` preventDoubleTapZoom adds an un-removed document listener (moot if file deleted).
- Perf spot-checks clean: SnakeGame interval/listener cleanup correct, Modal/ErrorState listeners paired, minigame error paths log.
- File sizes re-confirmed (Phase 0 inventory): TaskList.vue 1094, Settings.vue 982, RoomDetail 845, roomTasks.js 847, TaskEditForm 738, SnakeGame 704, SetupWizard 702 — record-only.

**Phase 4 verdict:** security-wise the frontend is in good shape (no v-html, disciplined t() usage); the real debt is (a) a large verified-dead store layer mirroring the dead backend routes, (b) the 141-key l10n artifact gap invisible to the current checker, and (c) stale-cache behavior after task completion with a wire-or-delete decision on the user-store reward actions.

## Phase 5 — Docs & backlog integrity (2026-08-27)

Method: 2 `explore` agents — (a) full docs-tree truth check against the L1–L4-verified code
state, (b) re-verification of every open backlog item. Main agent corrected one agent claim
(duplicate `Version1004` is NOT shadowed — `oc_migrations` lists both as executed; the key is
the full class name, confirming CR-P1-06's "legal but confusing" severity).

### L5-1. Docs drift (verified, with dispositions)

| File | Drift (doc claim → verified truth) | Disposition |
|------|-------------------------------------|-------------|
| `docs/api/README.md:426` | "111 routes" → **110** | truth-repair |
| `docs/api/README.md:534-538` | `GET /game/leaderboard` documents a `period` param that `GameController::leaderboard()` doesn't accept (removed CS-PLAN-041); endpoint is test-only | truth-repair + status note |
| `docs/api/phase5-smart-scheduling.md` | DEPRECATED banner correct; overstates "all endpoints removed" (the `/tasks/daily`, `/tasks/{id}/complete…` sections still exist) | keep marked; footnote or archive-move decision |
| `docs/concepts/design.md:44` | Task-approval-workflow bullet unmarked (unimplemented, FEAT-UX-018) | add status marker |
| `docs/concepts/energy-and-balancing.md:92+` | Points at orphan `AvailabilitySettings`; "Balancing Strategy MVP" describes the deleted smart-scheduler without marker | truth-repair + removal marker |
| `docs/concepts/gamification-and-rewards.md` | Task Pass section unmarked (post-MVP); "Cooperative Features" maps to dead `teamStreak` getter | add markers |
| `docs/concepts/task-model-and-sync.md:54-80` | Reminders + completion-sync described in present tense with **no unimplemented marker** (6 backend-only routes, no CalDAV client) | add per-section markers |
| `docs/concepts/ui-use-cases.md:11` | Header still "111 routes / 56 no-consumer" → 110 / **42** (2026-08-27 audit); UC-11 leaderboard row stale | refresh from L3-0 |
| `docs/adr/ADR-003` | **Contradicts code**: documents scope enum `garden/home/outside/work` that never existed; code validates `room/floor/house` (`TaskService:338`, `Task.php:368` adds `user`); not marked superseded although the scope selector was removed | add superseded note / fix enum |
| `docs/engineering/architecture.md:57,301` | Documents orphan `AvailabilitySettings` + `chorestrike_availability` as live | remove/mark with cluster deletion |
| `architecture.md:85-86,421` | Fictional `TaskForm`/`TemplateSelector` components; `fetchHouseStats()` → real name `fetchInsights()` | truth-repair |
| `architecture.md:125` | Backlog tree lists forbidden `bugs.md`/`phase-*.md` (project-rules §4) | truth-repair |
| `architecture.md:799` | Error-tracking example uses raw `console.error` (violates logging rule) | replace with logger.js |
| `architecture.md:60-61` + `development.md:113-140` | Migration docs lack the conventions this review established (version-then-date order, pre/post hooks never run on fresh install, dead upgrade-path steps) | add "Migration conventions" subsection (Phase 6) |
| `docs/engineering/development.md:218,288-290` | Fictional `Task/TaskItem.vue` example (P9-DOC-005 class) | truth-repair |
| `docs/engineering/testing.md:445,453` | References archived `TaskForm.vue` | truth-repair |
| `docs/standards/guidelines/security.md` | Example cites pruned `userTasks`/`userStats` endpoints | reword |
| `docs/standards/guidelines/i18n.md` | Workflow correct, but its gate (`check-l10n-keys.js`) only diffs de↔en — exactly why CR-P4-13 (141 missing keys) passed | document src-usage diff + extend checker |
| root `README.md` | **Heavy drift**: tower-defense/Dust marketing (layer removed), Sparks currency (now Coins), Task Approval / Custom Collections / AI Suggestions / DB Task Templates (removed or never built), "✅ v1.0.0 Release Candidate" (actually 0.1.1 pre-release), NC 28+ (info.xml: 27) | rewrite to shipped feature set or add historical banner |
| `docs/plans/` | new/ empty, active/ = this plan, done/ 017+022–052 all consistent; `onhold/` dir absent (nothing on hold) | clean; structural note |

### L5-2. Backlog sweep — 24 items re-verified: **20 KEEP · 4 UPDATE · 0 CLOSE · 0 STALE**

The open backlog is healthy — no false keeps, no hidden closures (resolved markers in
`archive/2026-08.md` all cross-check). Dispositions to apply in Phase 6:

| Item | Verified state | Action |
|------|----------------|--------|
| **BUG-ROOM-001** | The POST→GET "workaround" IS a live GET-create surface (`RoomController:55-88`) — CSRF-relevant (CR-P3-08/CR-P3-12) | UPDATE + merge with the GET-creates/alias-route disposition |
| **SEC-AUDIT-001** | Rate limiting + audit logging still absent; docs bullet partially done (`api/README.md:836-838`) | UPDATE (scope note) |
| **CR-SVC-40** | Pattern grown to **7 services** injecting IDBConnection (Room, Floor, House, HouseSetup, Minigame, RoomTask, TestData) | UPDATE scope 1→7 |
| **CR-SVC-44** | 3 of 6 sub-claims obsolete (clusters deleted by CS-PLAN-027/CR-SVC-08); live remainder: streak per-user writes + RoomTaskService re-fetch pattern; `getAllTasks` now caller-less | UPDATE (drop dead bullets) |
| All others (BUG-SEC-001, TECH-LINT-006, CR-SVC-15, CR-SVC-41, FEAT-UX-015/018-029, UX-015/017, RELEASE-002/003, milestone criteria) | Re-verified genuinely open with today's evidence | KEEP |

Dedup decisions: FEAT-UX-021 ↔ CR-P3-0 route disposition (task-sync ×6 = expose-or-prune one
decision); FEAT-UX-028 ↔ route disposition + dead store action; CR-SVC-15 — new decomposition
map recorded in L2-3. **CR-SVC-37/62 stay closed** — the 141-key gap (CR-P4-13) is a NEW
frontend-side item, not a backend regression.

### L5-3. ACTIVE_TASKS.md housekeeping (apply in Phase 6)

Five stale lines in the "Planned" section: stale "recommended order/follow-up" pointers
(043-order :104; after-047 :202-204; after-049 :276-278; after-050 :320-322 — their targets
since closed by 048/049/050/052) and the CS-PLAN-053 "ready for manual move" note (:434, now
moved+in-progress). Nothing in Planned awaits activation.

**Phase 5 verdict:** docs carry real drift concentrated in root README (marketing-era copy),
architecture.md examples, and unimplemented-feature framing in concepts; the backlog itself
survived a full re-verification with zero closures needed — every open item is genuinely open.

## Phase 6 — Consolidation & closures (2026-08-27)

### 6.1 Deduplication

L1–L5 consolidated into a single taxonomy (`CR-P1-*…CR-P5-*` per layer, severity × file ×
disposition) during recording; cross-layer merges applied: checklist security findings
(CR-P1-10 + CR-P3-01), TZ semantics (CR-P1 note + CR-P2-14 + CR-P4-07), i18n gap
(CR-P4-13/14/15), route disposition + dead-store layer (L3-0 + L4-3). Three reviewer claims
disproven during verification: Version1018 install-blocker (fresh installs use
`migrateSchemaOnly`, hooks never run), RoomTaskController::complete "block" (gates enforced
in `RoomService:371-378`), duplicate house_users rows (`uniq_house_user` exists).

### 6.2 Auto-fixes applied (review-skill §5 clusters) + gate re-runs

| Cluster | Files | Change |
|---------|-------|--------|
| Bug fix | `src/store/house.js:26` | `houseHealth \|\| 100` → `?? 100` (CR-P4-05) + regression pin in `tests/unit/store/house.spec.js` (critical house shows 0, not 100) |
| Dead private methods | `FloorController:206`, `RoomController:272` | deleted uncalled `getRequestMethod()` ×2 |
| Dead export | `src/constants/titles.js` | deleted `TITLE_PACKS` + the 3 pack-id consts (only consumer was the array; zero src/test importers) + orphaned SYNC comment |
| Dead export keyword | `src/store/scheduling.js:23` | `localDateKey` un-exported (module-internal only; tests use local helpers) |
| Comment/docblock truth | `lib/AppInfo/Application.php:30` (NC-version claim), `lib/Db/Room.php:96` (monster-count claim), `lib/Service/MinigameService:309` (Sparks→Coins), `lib/Titles/TitleCatalog.php:24-25` (per-house→instance-global) | corrected |
| Test-gap closure (§4) | new `tests/unit/Db/Entity/MinigameScoreTest.php` (4 tests), `tests/unit/Db/Entity/TaskAssigneeTest.php` (3 tests) | entity suites closed |

**Gates after the cluster:** `lint:fix` + `stylelint:fix` clean · Jest **848/848** (+1 pin) ·
`test:noise` clean · Docker `cs:check` **0/269** · Docker PHPUnit unit **893** (+7) OK ·
Docker integration **200 [24 pre-existing skips]** unchanged · `docs:encoding` 664 green.

### 6.3 Test-gap dispositions (§4/§8)

- **Closed:** `MinigameScoreTest`, `TaskAssigneeTest` (§4 entity list).
- **Deferred → `backlog.md` CR-053-22 with reason, user approval pending:** integration
  suites for Checklist/Minigame/RewardConfig/TaskSync controllers, `TestDataSeedTest`, and
  the 8 component Jest specs. Deferral reason: mechanical-but-large additions beyond this
  session's consolidation window; the Checklist suite is the explicit companion of CR-053-01.
- **Not extended by design (§4):** E2E matrix; no `src/` deletion touched rendered behavior
  (only dead exports/store members whose consumers don't exist), so the blocking chromium
  gate was not required.

### 6.4 Backlog & docs deltas

- `backlog.md`: new section "Review Follow-ups — CS-PLAN-053" with **CR-053-01…22**
  (P1 ×4 security, P2 ×10, P3 ×8); 4 UPDATEs applied (BUG-ROOM-001 rewritten as live
  GET-create/CSRF surface, SEC-AUDIT-001 scope note, CR-SVC-40 scope 1→7 services,
  CR-SVC-44 re-scoped with obsolete bullets dropped).
- `ACTIVE_TASKS.md`: stale "Planned" recommendation lines crossed out with closure pointers;
  CS-PLAN-053 draft note marked activated.
- Doc truth-repairs applied: `api/README.md` (111→110 routes; `/game/leaderboard` fictional
  `period` param removed + test-only status), `concepts/ui-use-cases.md` (audit header
  refreshed to the 2026-08-27 matrix; UC-11 leaderboard row corrected).
- Remainder of L5-1 doc repairs (root README rewrite, ADR-003, architecture/development/
  testing examples, migration-conventions subsection, unimplemented-feature markers)
  → **CR-053-21** (deliberate: batch the docs work; root README rewrite needs a product call).

### 6.5 Durable knowledge integration status

Route-consumer matrix + audit date now live in `ui-use-cases.md` (refreshed); review record
holds the migration-runner semantics (schemaOnly fresh installs, hook non-execution) and the
full god-service/decomposition map for CR-SVC-15 planning; the migration-conventions
subsection and README rewrite are explicitly scheduled via CR-053-21 so the permanent docs
absorb them once written (not left only in this record).

### 6.6 Acceptance criteria check (plan §8)

| Criterion | State |
|-----------|-------|
| Six phases executed; L1–L5 tables with severity/file:line/evidence/disposition | ✅ (this record) |
| Verified-dead deletions with no-caller proofs + green gates | ✅ for the inline cluster; the full sweep is proof-recorded and scheduled (CR-053-15) — deletions beyond auto-fix scale are behavior-changing and gated on user go |
| Security findings as explicit backlog items, not auto-fixed | ✅ CR-053-01…08 (+ CR-053-05 for `RewardConfigController`) |
| Route disposition matrix covering all 110 routes | ✅ (L3-0; decision pending user, CR-053-14) |
| Backlog sweep: verified dispositions; stale items moved | ✅ 24 items: 20 KEEP / 4 UPDATE / 0 CLOSE / 0 STALE (archive untouched — nothing qualified) |
| Docs drift repaired or status-marked; `docs:encoding` green | ✅ for mechanical repairs + status marks; batch remainder scheduled (CR-053-21) |
| §4 test gaps closed or logged with reason + approval | ✅ 2 closed; remainder logged (CR-053-22) — **approval pending** |
| Review record written; knowledge integrated | ✅ this file (+ pointers above) |
| Build gate green, no new skips/fails vs Phase 0 | ✅ (unit 886→893, integration unchanged, Jest 847→848, all other gates clean) |
| ACTIVE_TASKS updated; follow-ups proposed | ✅ (entry updated; proposals below) |

### 6.7 Proposed prioritized next tasks

1. **CS-PLAN-054 (P1, S/M): Security quick-closes** — CR-053-02 (index IDOR), CR-053-03
   (owner gates), CR-053-04 (taskTier whitelist), CR-053-06 (lifecycle visibility matrix):
   all four are small, pattern-established fixes with existing test seams.
2. **CS-PLAN-055 (P1, M): ChecklistService** — CR-053-01 + its deferred integration suite
   (CR-053-22 partial): the one new-service extraction the API needs now.
3. **User decision block:** CR-053-14 (route disposition incl. task-sync expose-or-prune and
   `RewardConfigController` authorization shape), CR-053-12 (timezone design), CR-053-22
   (test-gap deferral approval), CR-053-21 (README rewrite vs banner).
4. **CS-PLAN-056 (P2, M): Streak/leaderboard correctness cluster** — CR-053-11 (five small
   fixes, each testable in isolation).
5. **CS-PLAN-057 (P3, M): Dead-code sweep** — CR-053-15, proofs recorded; execute per
   cluster with gates, deciding CR-P4-03 wire-or-delete together with CR-053-20.

**Phase 6 verdict:** review complete. The codebase's real debt is now mapped, deduplicated,
and decision-ready: 4 P1 security fixes are immediately actionable, the route/dead-code/timezone
decisions are packaged for the user, and every open thread ends in exactly one backlog item.
