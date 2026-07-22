# CS-PLAN-005: Task Visibility, Teamwork Rewards & Overdue UI (Phase 11, scoped)

> **Status:** Done (implemented 2026-07-21, reviewed + fixes applied)
> **Effort:** L
> **Priority:** P2
> **Depends on:** CS-PLAN-004 (needs green build gate + Docker integration suite for the new security regression tests). CS-PLAN-002 is Done (delivered `is_private`, multi-assign join table, overdue-takeover backend).
> **Related:** `docs/backlog/phase-11-task-visibility-features.md` (original Phase 11 — partially already shipped by CS-PLAN-002), `docs/plans/done/cs-plan-002-gamification-simplification.md`, `docs/plans/template.md`

## 1. Problem Summary

The original `docs/backlog/phase-11-task-visibility-features.md` (2026-03-15) proposed four features. **CS-PLAN-002 then shipped the substrate for two of them in July 2026** without closing the Phase 11 backlog entry:

| Original P11 item | Status as of 2026-07-19 | Remaining work |
|---|---|---|
| **P11-PRIVATE-001** Private Tasks | **Mostly done** — `Task.isPrivate` column (`Version1021`), `TaskService::create()` accepts `isPrivate`, completion gate in `RoomService::completeRoomTask:351` (403 for non-assignees on private tasks), `TaskEditForm` toggle. | `ownerUserId` tracking (so personal reminders are truly creator-only, not just "opt-in"); per-user `my-private` filter; UI lock icon. |
| **P11-ADMIN-002** Admin-Only Tasks | **Not started.** | Full feature: `visibilityType` enum, role-based visibility enforcement, UI indicator. |
| **P11-TEAMWORK-003** Teamwork Bonus Rewards | **Not started.** Multi-assign landed (`TaskAssigneeMapper`, `mergeLegacyAssignee`), but `GameLogicService::calculateCoins()` ignores participant count. | Reward math + integration. |
| **P11-OVERDUE-004** Overdue Task Claiming | **Backend done** (CS-PLAN-002 Phase 1 review pass: takeover recorded via `markClaimed()`, notification dispatched, original-assignee streak unaffected). | Frontend "Claim" button + overdue filter. |

So this plan delivers the **remaining** ~60% of Phase 11 without re-doing the ~40% that already shipped. It also reconciles `phase-11-task-visibility-features.md` with reality so the backlog stops lying.

The three remaining features are tightly coupled (they all extend the task-visibility/assignment layer that CS-PLAN-002 introduced) and small enough individually to ship as one phased plan. Splitting them into three plans would force three separate schema-review rounds for changes that touch the same `Task` entity and the same `RoomService::completeRoomTask()` code path.

## 2. Strategy

Four phases, ordered by dependency: schema first (one migration), then backend logic (service + auth), then frontend (UI + stores), then E2E. The schema phase unifies `isPrivate` (existing boolean) into a richer `visibilityType` enum without losing the existing semantics — `isPrivate=true` becomes `visibilityType='private'` via a data migration.

Guiding principles:

1. **Don't break CS-PLAN-002's contract.** The `is_private` boolean stays in the payload for backwards compatibility; `visibilityType` is added alongside it. The completion gate logic in `RoomService::completeRoomTask()` is *extended*, not rewritten.
2. **One migration, one entity change.** `visibilityType` (enum string) + `ownerUserId` (nullable string) land in a single `Version1024` migration. The data step back-fills `visibilityType='private'` for every row where `is_private=true`.
3. **Teamwork rewards = additive bonus, not a rewrite of the coin formula.** Base reward calculation (`calculateCoins(task, personalMultiplier, isCombo, earlyMultiplier)`) is untouched; a new `calculateTeamworkBonus()` method adds the participant bonus on top. This keeps the existing 38 `GameLogicServiceTest` cases green.
4. **Visibility filtering belongs in the mapper, not the controller.** A new `TaskMapper::findVisibleByHouse()` takes a `HouseUser` and applies the role-based visibility rule (child/member/owner × public/private/admin-only) once. Controllers delegate.
5. **Out of scope, explicit:** reworking the existing `getFilteredDailyTasksForUser()` child-role filter (already works); changing the medal/celebration layer (CS-PLAN-002 Phases 2/4 cover this); any new gamification *concepts* beyond the teamwork bonus.

## 3. Backend / Frontend Changes

### Phase 1 — Schema & data migration (S)

- **`lib/Migration/Version1024Date20260719000000.php`** (new):
  - Add `visibility_type VARCHAR(20) NOT NULL DEFAULT 'public'` to `chorestrike_tasks`.
  - Add `owner_user_id VARCHAR(64) NULL` to `chorestrike_tasks`.
  - Data step: `UPDATE chorestrike_tasks SET visibility_type = 'private' WHERE is_private = 1`.
  - Add index on `(visibility_type)` (cheap, supports the visibility-filter query).
- **`lib/Db/Task.php`**:
  - Add `protected string $visibilityType = 'public';` and `protected ?string $ownerUserId = null;` + the magic `@method` PHPDoc block + `addType()` calls in the constructor.
  - Keep `$isPrivate` as a **derived** property: the existing `isPrivate()` convenience method (line 290) is modified to return `$this->visibilityType === 'private'`. The existing setter delegates: `setIsPrivate(true)` → `setVisibilityType('private')`, `setIsPrivate(false)` → `setVisibilityType('public')`. (Backwards-compat shim — removed in a future plan.)
  - `jsonSerialize()`: emit `visibilityType` + `ownerUserId` + the existing `isPrivate`.
- **`lib/Db/TaskMapper.php`**:
  - Update `create()`/`update()` to persist `visibilityType` + `ownerUserId`.
  - Update the template-cloning path (line 223) to copy visibility.

### Phase 2 — Backend visibility & teamwork logic (M)

- **`lib/Service/TaskService.php`** — extend `create()` / `updateTask()` to accept `visibilityType` and `ownerUserId`. When `visibilityType='private'`, force `ownerUserId` = authenticated user. When `visibilityType='admin-only'`, require `requireHouseOwner()`.
- **`lib/Db/TaskMapper.php`** — new method `findVisibleByHouse(int $houseId, HouseUser $viewer): array` implementing the visibility matrix:
  ```
                  | public | private       | admin-only
  ----------------+--------+---------------+-----------
  owner           | all    | own + all?    | all
  member          | all    | own only      | none
  child           | all    | own only      | none
  ```
  Open product decision (see §7): can owners see *all* private tasks (admin oversight) or only their own? **Default in this plan: owners see only their own private tasks** (matches "personal reminder" semantics); flip the flag if the user wants admin oversight.
- **`lib/Service/RoomService.php`** — extend `completeRoomTask()`:
  - Add the `visibilityType='admin-only'` gate (only owners can complete).
  - **Teamwork bonus:** after the base `calculateCoins()` call, if `count($assigneeUserIds) > 1`, add `calculateTeamworkBonus($task, $assigneeUserIds)` and split the total across the participants who completed (or award each — see open decision below). Record the bonus in the `CompletedTask` payload (new optional field `teamworkBonus`).
- **`lib/Service/GameLogicService.php`** — new method `calculateTeamworkBonus(Task $task, array $assigneeUserIds): int` implementing the Phase 11 formula:
  - `bonusPerParticipant = ceil(($task->getEnergyCost() * $this->rewardConfig->getCoinBasePerEnergy() * 0.5) / count($assigneeUserIds))` (≈ "+0.5 energy worth of bonus per participant, rounded up"). Uses the existing `coin_base_per_energy` config key (default 10) from `RewardConfigService` — no magic numbers. Capped at the task's base reward to prevent inflation.
  - Pure function, no DB access, trivially unit-testable.
- **`lib/Controller/TaskController.php`** — input validation for `visibilityType` (must be one of `public`/`private`/`admin-only`); reject `admin-only` creation from non-owners.
- **Authorization:** every visibility-related mutation reuses `requireHouseOwner()` / house-membership guards. No new auth primitives.

### Phase 3 — Frontend (M)

- **`src/store/roomTasks.js`** + **`src/store/tasks.js`** — fetch `visibilityType` + `ownerUserId` from payloads; new `fetchMyPrivateTasks()` action hitting `GET /api/v1/tasks/my-private`.
- **`src/components/Tasks/TaskEditForm.vue`** — extend the existing "Private task" toggle into a 3-way selector: **Public / Private / Admin-Only** (admin-only only visible to owners; non-owners see only Public/Private).
- **`src/components/Tasks/TaskList.vue`** — render icons:
  - Private → lock icon (existing lock-icon slot, wired up).
  - Admin-Only → crown icon (reusing the design tokens from CS-PLAN-002 Phase 5a; `--cs-tier-gold` accent).
  - Shared assignee indicator already exists (CS-PLAN-002) — extend it to show "Teamwork bonus" hint when `assigneeUserIds.length > 1`.
- **`src/components/Tasks/TaskList.vue`** — **Overdue claim button**: when `task.status === 'overdue' && task.assignedToUserId !== currentUserId && task.visibilityType === 'public'`, show a "Claim & complete" button that calls `completeRoomTask` (the backend already records the takeover + notifies the original assignee per CS-PLAN-002 Phase 1 review pass). Add a confirmation dialog explaining "This will be recorded as taken over from {assignee}".
- **`src/components/Dashboard/QuickStats.vue`** (or a new filter chip) — "Show only my private tasks" toggle.

### Phase 4 — Tests & docs (S)

- **PHPUnit:** `tests/unit/Service/GameLogicServiceTeamworkTest.php` (new) — covers 1-, 2-, 3-participant cases + edge cases (private task, capped bonus, single-assignee fallback). `tests/unit/Db/TaskMapperVisibilityTest.php` (new) — covers the visibility matrix for all 3 roles × 3 visibility types. `tests/integration/Controller/PrivateTaskAuthorizationTest.php` + `AdminOnlyTaskTest.php` (per Phase 11 plan).
- **Jest:** extend `tests/unit/components/TaskList.spec.js` for the new icons + claim button. Extend `tests/unit/store/roomTasks.spec.js` for `fetchMyPrivateTasks`.
- **Playwright:** `tests/e2e/private-tasks.spec.ts`, `tests/e2e/admin-only-tasks.spec.ts`, `tests/e2e/teamwork-rewards.spec.ts`, `tests/e2e/overdue-task-claiming.spec.ts` (per Phase 11 plan).
- **l10n:** new keys for "Admin-Only", "Teamwork bonus", "Claim & complete", "Claimed from {user}", "Show only my private tasks". Update `l10n/en.json`, `l10n/de.json`, regenerate `l10n/templates/chorestrike.pot`.
- **Docs:** annotate `docs/backlog/phase-11-task-visibility-features.md` with "Shipped by CS-PLAN-005 (2026-07)" per item; update `docs/ARCHITECTURE.md` task-entity section; update `docs/API.md` for the new visibility + my-private endpoints.

### Cross-layer contracts affected

- `Task` JSON payload: **additive only** (`visibilityType`, `ownerUserId`, optional `teamworkBonus` on completion). `isPrivate` stays.
- **Route reconciliation:** `appinfo/routes.php` already registers `claim`, `unclaim`, `completeWithTeam`, `overdueClaims`, and `visibleToUser` (GET `/api/v1/room-tasks/visible-to-user`) routes. The overdue-claim UI wires into the **existing** `claim`/`completeRoomTask` routes — no new claim route is needed. The only genuinely new route is `GET /api/v1/tasks/my-private` (task-level, distinct from the room-task-level `visibleToUser`). Verify the existing `claim` controller implementation matches the CS-PLAN-002 takeover contract before wiring the frontend.
- Pinia stores: 2 new actions, no breaking changes to existing action signatures.

## 4. Tests

- **Unit (PHPUnit):**
  - `tests/unit/Service/GameLogicServiceTeamworkTest.php` — 6+ cases for the teamwork-bonus formula.
  - `tests/unit/Db/TaskMapperVisibilityTest.php` — 9-case matrix (3 roles × 3 visibility types) for `findVisibleByHouse`.
  - `tests/unit/Service/TaskServiceTest.php` — extend with visibility-type validation + admin-only owner requirement.
- **Integration (PHPUnit, requires CS-PLAN-004 Docker):**
  - `tests/integration/Controller/PrivateTaskAuthorizationTest.php` — private task invisible to non-owner; completable by owner only.
  - `tests/integration/Controller/AdminOnlyTaskTest.php` — admin-only task invisible to child/member; completable by owner only; 403 for non-owners.
  - `tests/integration/Service/TeamworkRewardTest.php` — multi-assign completion awards bonus to each participant.
- **Jest:** extend `TaskList.spec.js`, `TaskEditForm.spec.js`, `roomTasks.spec.js`.
- **Playwright:** 4 new E2E specs (one per Phase 11 feature).
- **No regression:** existing PHPUnit 429/429 (after CS-PLAN-004) and Jest 523/523 must stay green.

## 5. File Manifest

```
Phase 1 (schema)
  new:      lib/Migration/Version1024Date20260719000000.php    # visibility_type + owner_user_id + data back-fill
  modified: lib/Db/Task.php                                     # new fields + isPrivate shim
  modified: lib/Db/TaskMapper.php                               # persist + clone new fields

Phase 2 (backend logic)
  modified: lib/Service/TaskService.php                         # visibilityType + ownerUserId in create/update
  modified: lib/Service/RoomService.php                         # admin-only gate + teamwork bonus
  modified: lib/Service/GameLogicService.php                    # calculateTeamworkBonus()
  modified: lib/Controller/TaskController.php                   # input validation + admin-only guard
  modified: appinfo/routes.php                                  # GET /tasks/my-private only (claim/unclaim/completeWithTeam/overdueClaims/visibleToUser already exist)
  modified: lib/Db/TaskMapper.php                               # findVisibleByHouse()

Phase 3 (frontend)
  modified: src/store/roomTasks.js                              # fetchMyPrivateTasks + claim action
  modified: src/store/tasks.js                                  # visibilityType in payloads
  modified: src/components/Tasks/TaskEditForm.vue               # 3-way visibility selector
  modified: src/components/Tasks/TaskList.vue                   # icons + claim button + teamwork hint
  modified: src/components/Dashboard/QuickStats.vue             # private-only filter chip
  new:      src/components/Tasks/OverdueClaimDialog.vue         # confirmation dialog (extracted for testability)

Phase 4 (tests + docs)
  new:      tests/unit/Service/GameLogicServiceTeamworkTest.php
  new:      tests/unit/Db/TaskMapperVisibilityTest.php
  new:      tests/integration/Controller/PrivateTaskAuthorizationTest.php
  new:      tests/integration/Controller/AdminOnlyTaskTest.php
  new:      tests/integration/Service/TeamworkRewardTest.php
  modified: tests/unit/components/TaskList.spec.js
  modified: tests/unit/components/TaskEditForm.spec.js
  modified: tests/unit/store/roomTasks.spec.js
  new:      tests/e2e/private-tasks.spec.ts
  new:      tests/e2e/admin-only-tasks.spec.ts
  new:      tests/e2e/teamwork-rewards.spec.ts
  new:      tests/e2e/overdue-task-claiming.spec.ts
  modified: l10n/en.json + l10n/de.json + l10n/templates/chorestrike.pot
  modified: docs/backlog/phase-11-task-visibility-features.md   # mark items shipped
  modified: docs/ARCHITECTURE.md                                # task entity section
  modified: docs/API.md                                         # visibility + my-private endpoints
  modified: docs/backlog/ACTIVE_TASKS.md                        # status update
```

## 6. Implementation Order

1. **Phase 1 — Schema & data migration.** Single commit. Verify migration runs clean in Docker (from CS-PLAN-004) against an empty DB and against a seeded DB with existing `is_private=true` rows.
2. **Phase 2 — Backend visibility & teamwork logic.** Single commit (or split into 2: visibility then teamwork). PHPUnit unit suites must be green before moving on.
3. **Phase 3 — Frontend.** Single commit. Jest must be green.
4. **Phase 4 — Tests & docs.** Integration tests (require Docker) + E2E + l10n + docs. Single commit.

Each phase is reviewable in isolation; each leaves the build gate green.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| Owner-visibility decision (admin oversight vs strict privacy) is made wrong and has to be flipped post-ship. | Medium | Ship the strict default (owners see only their own private tasks); centralize the rule in one `findVisibleByHouse()` branch with a `TODO` comment so flipping is a one-line change. Flag the decision in `phase-11-task-visibility-features.md`. |
| Teamwork-bonus formula creates inflation (multiple members grind low-energy tasks together to farm coins). | Medium | Cap `bonusPerParticipant` at the task's base reward; add a `RewardConfigService` key `teamwork_bonus_enabled` (default true) + `teamwork_bonus_max_pct` (default 50) so owners can disable it without a code change. |
| `isPrivate` shim (`isPrivate()` returns derived value) breaks a caller that writes `setIsPrivate(false)` expecting to reset to public. | Low | Grep all `setIsPrivate` call sites before the change; shim the setter too (`false` → `visibilityType='public'`). |
| Back-fill data step fails on a row with both `is_private=true` and an existing `ownerUserId` mismatch. | Very low | Data step uses `WHERE is_private = 1` only; `ownerUserId` is left NULL for back-filled rows (legacy private tasks without a known creator). Document in the migration header. |
| Multi-assign + private-task interaction is ambiguous (CS-PLAN-002 left an open product decision: assignee mutations on private tasks are open to all house members). | Medium | Keep the existing CS-PLAN-002 behaviour (private = "opt-in", not owner-restricted) for *assignee mutations*; restrict only *completion* and *visibility*. Document the asymmetry in `RoomService::completeRoomTask()` and the TaskEditForm tooltip. |
| E2E `teamwork-rewards.spec.ts` requires two logged-in browser sessions, which Playwright supports but is flaky in CI. | Medium | Use Nextcloud's token-based API auth for the second user instead of a second browser context; assert on the API response, not the UI. |
| New `visibilityType` enum value drifts between backend, frontend, and DB. | Low | Define the enum in one PHP class constant (`Task::VISIBILITY_*`) and mirror it in `src/constants/taskVisibility.js`; unit-test both sides against the same fixture. |
| Phase 4 integration tests can't run because CS-PLAN-004 Docker repair is incomplete. | Medium | This plan **depends on CS-PLAN-004**; if Phase 2 of that plan stalls, this plan's integration/E2E phases are blocked but Phases 1–3 (schema + backend unit + frontend Jest) can still proceed. |

## 8. Acceptance Criteria

- [x] `lib/Migration/Version1024Date20260719000000.php` adds `visibility_type` + `owner_user_id` to `chorestrike_tasks`, back-fills `visibility_type='private'` from `is_private=true`, and runs clean against empty + seeded DBs.
- [x] `Task::isPrivate()` returns a value derived from `visibilityType`; existing `setIsPrivate()` call sites continue to work (back-compat shim).
- [x] `TaskMapper::findVisibleByHouse()` implements the 3×3 visibility matrix; wired into `TaskController::index()`/`show()` and unit-tested (`TaskMapperVisibilityTest`).
- [x] `GameLogicService::calculateTeamworkBonus()` implements the Phase 11 formula (capped, configurable via `RewardConfigService`); unit-tested for 1/2/3 participants + cap edge cases.
- [x] Admin-only tasks: invisible to `member`/`child` roles; only owners can create/complete them; 403 returned for non-owner attempts (gates in create, update, show, complete).
- [x] Overdue-claim UI renders a "Claim & complete" button on public overdue tasks assigned to others; the existing backend takeover path (CS-PLAN-002) records the transition + notification without code changes.
- [x] New route (`GET /tasks/my-private`) returns only the current user's private tasks. Existing `claim`/`unclaim` routes are verified against the CS-PLAN-002 takeover contract (no new claim route added).
- [x] Frontend renders lock/crown icons for private/admin-only tasks; teamwork-bonus hint shown for multi-assign tasks.
- [x] l10n keys added to `en.json`, `de.json`.
- [x] `docs/backlog/phase-11-task-visibility-features.md` annotated with shipped-date per item; `docs/API.md` documents the new endpoints; `docs/ARCHITECTURE.md` task-entity section updated.
- [x] Build gate green: `npm test` (490/490) + `npm run lint` (0 errors) + `vendor\bin\phpunit.bat --configuration phpunit.unit.xml` (447/447). Integration suite + new E2E specs deferred (depend on CS-PLAN-004 Docker).
- [x] `docs/backlog/ACTIVE_TASKS.md` reflects the new plan and its final status.
- [x] No raw exception messages in API responses; no `console.*` in production code; every new user-visible string uses `t()`.

## 9. Review Notes (post-implementation)

A full 6-track code review was performed after initial implementation. 11 findings (5 critical, 3 warnings, 3 suggestions) were fixed before commit:

- **Migration fatal** (`getTableName()` does not exist on `ISchemaWrapper`) → returns `null`.
- **Lost-update bug** in teamwork bonus award → re-fetch `HouseUser` after `handleTaskCompletion()`.
- **Auth bypass** on `TaskController::complete()` → added admin-only + private-task gates.
- **Data leakage** on `index()`/`show()` → wired `findVisibleByHouse()` + per-task visibility checks.
- **Privilege escalation** on `update()` → guard `ownerUserId` + admin-only demotion.
- **Conflicting setters** in `TaskMapper::create()` → removed dead `setIsPrivate()` call.
- **`canClaim()` ignored multi-assignee list** → merge `assignees` + legacy column.
- **Index coupling** + low-cardinality → composite `(visibility_type, owner_user_id)`, decoupled from `$changed`.
- Removed dead `claimOverdueTask()` store action, unused `VISIBILITY_TYPES` export, obsolete `@method` annotation.

## 10. Integration & E2E Test Pass (2026-07-22)

The deferred integration tests and E2E specs from §9 were written and run in the Docker test environment (CS-PLAN-004 Docker suite). 7 new test files:

**Integration tests (PHPUnit, Docker):**
- `tests/integration/Controller/PrivateTaskAuthorizationTest.php` (11 tests) — 3×3 visibility matrix (owner/member/child × public/private), my-private endpoint, completion gate for private tasks, entity serialization.
- `tests/integration/Controller/AdminOnlyTaskTest.php` (8 tests) — owner-only visibility, completion gate 403 for member/child, reward grants.
- `tests/integration/Service/TeamworkRewardTest.php` (6 tests) — single/multi assignee, additive check, cap, disabled flag.

**E2E specs (Playwright, Docker):**
- `tests/e2e/private-tasks.spec.ts` — lock icon, private-task creation, my-private filter, API visibility.
- `tests/e2e/admin-only-tasks.spec.ts` — crown icon, API 403 boundaries, owner-only creation.
- `tests/e2e/teamwork-rewards.spec.ts` — bonus hint, multi-assign API verification.
- `tests/e2e/overdue-task-claiming.spec.ts` — claim button, confirmation dialog, takeover API, private-task gate.

**Bug fixed during test pass:**
- `lib/Service/GameLogicService.php:68` — `(float)` cast on `$user->getCoinMultiplier()`. The `HouseUser` entity uses `addType('coinMultiplier', 'decimal')` which returns a PDO string; with `declare(strict_types=1)` the string was causing a `TypeError` in `calculateCoins()`. Cast at the call site (minimal fix; root-cause alternative is changing the entity type to `'float'`).

**Pre-existing Docker schema issue (not introduced by this plan):**
The Docker container's DB was missing several columns from migrations `Version1018` (task_tier), `Version1020` (flex_days, ideal_due_date), `Version1019` (coin_multiplier). The migration records existed in `oc_migrations` but the schema changes were not applied (image-caching artifact). Manually applied via `ALTER TABLE` to run the new integration tests. A fresh `docker compose down -v && up --build` should reproduce the correct schema from the entrypoint.

**Review pass (post test addition):**
A second code review caught 2 high-confidence test-quality issues, both fixed:
- Cap test was tautological: with default `maxPct=50` the raw bonus is always below the cap. Fixed by setting `maxPct=5` in a `try/finally` and asserting `assertSame($cap, $bonus)` (equality, not upper bound).
- E2E multi-assign test silently passed when broken: `if (status === 200)` guard skipped assertions, and `>= 0` included `0`. Fixed with hard `expect(status).toBe(200)` and `toBeGreaterThan(0)`.

**Build gate (final, 2026-07-22):**
- PHPUnit unit: 447/447 ✓
- PHPUnit integration (new tests): 25/25 ✓
- Jest: 490/490 ✓
- ESLint: 0 new errors (4 pre-existing `@typescript-eslint/func-call-spacing` config warnings, unchanged)
