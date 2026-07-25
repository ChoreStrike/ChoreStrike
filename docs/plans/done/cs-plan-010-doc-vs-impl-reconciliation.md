# CS-PLAN-010: Documentation-vs-Implementation Reconciliation & Dead-Code Cleanup

> **Status:** Done (2026-07-24) — implemented; `active/ → done/` move is manual
> **Effort:** L
> **Priority:** P2
> **Depends on:** none — but ideally the uncommitted 2026-07-24 review changes (`lib/Db/TaskMapper.php`, `src/store/game.js`, `src/store/user.js`, `docs/backlog/*`) are committed first so the doc rewrite describes a stable tree.
> **Related issue:** none
> **Decisions confirmed at activation:** A = document medal reality + backlog · B = align combo docs to the implemented ×2 personal combo · C = drop the stale sparks columns.

## 1. Problem Summary

A full doc-vs-impl reconciliation (2026-07-24, 4 parallel `explore` agents) found the documentation has drifted several plans behind the implementation, and uncovered a cluster of dead code and stale residue from the Sparks/Monsters removal (CS-PLAN-002) and dead-code cleanup (CS-PLAN-003) that was never fully closed.

The user requested **full cleanup** — documentation corrections **plus** removal of the stale Sparks residue, dead services/controllers, and a schema migration to drop the obsolete columns. Findings source: reconciliation summary in `docs/backlog/CODE_REVIEW_2026-07-24.md` (to be created in Phase 0) and the four agent reports.

Concrete gaps:

- **`docs/engineering/architecture.md`** — directory tree lists archived files (`TaskClaim`, `TaskParticipant`, `TaskScope`, `SchedulingView`); DB-schema section is the most stale: `difficulty` (real: `energy_cost`), `total_points` (real: `coins`), PascalCase table names (real: lowercase), `monster_count` still documented (feature removed), ~9 tables and dozens of columns undocumented; data-flow example references nonexistent `TaskItem.vue`/`RoomView.vue`; `GameStore.checkCombo()` (real: `checkForCombo()`).
- **`docs/concepts/design.md`** — medal names ("The Tank/Scout/Specialist/Teamplayer/Streak Keeper") do not match the implementation (`team_anchor/flow_keeper/room_guardian/team_player/streak_keeper`); only 2 of 5 medal types are actually awarded.
- **`docs/concepts/gamification-and-rewards.md` / `energy-and-balancing.md`** — several implemented reward tokens undocumented (streak freezes, combo bonus freeze, teamwork bonus, weekly goal target); combo multiplier documented as ×1.5 multi-user, implemented as ×2 personal.
- **`docs/engineering/testing.md`** — E2E spec count says 20, real is 32; internal phpunit-command inconsistency (`phpunit.xml tests/integration/` vs `phpunit.integration.xml`).
- **`docs/api/README.md`** — documents `PUT /room-tasks/{id}` with no route; ~96 routed endpoints undocumented.
- **Code residue** — `HouseUser` still serialises `currentSparks`/`totalSparksEarned` + dead `addSparks()`/`spendSparks()`; `TitleCatalog` defines unused `SIGNAL_TOTAL_SPARKS_EARNED` with misleading comment; `MedalService` is fully dead (only its own test references it); `UserStatsController` has 8 public methods and 0 routes; `RoomController::addTask()`/`removeTask()` have no routes; DB still carries `current_sparks`/`total_sparks_earned` columns.

## 2. Strategy

Split into **low-risk documentation phases** (1–4) and **higher-risk code/schema phases** (5–7), executed in reviewable order. Two open product semantics are surfaced as **Decision Points** with a proposed safe default (align docs to current behaviour) so activation (`new/ → active/`) can confirm or redirect without guessing.

- Rewrite the two stalest docs (architecture schema/tree, design.md medals) against the real migrations/files.
- Register the reconciliation as a tracked backlog record, then close each finding by either fixing the doc, removing the dead code, or logging a deferral.
- Sparks removal is done as a **new migration `Version1026`** that drops the columns *after* the `Version1025` backfill has run (version ordering guarantees this), plus entity/catalog cleanup. No behaviour change — the fields were never incremented post-CS-PLAN-002. **Critical dependency:** `Version1025::signalColumn()` references `TitleCatalog::SIGNAL_TOTAL_SPARKS_EARNED` in a `match` arm (line 160). That arm is provably dead (no pack uses the signal — Cozy Home uses `SIGNAL_COINS`), so Phase 5 removes the dead match arm from `Version1025` *before* removing the constant from `TitleCatalog`. The `total_sparks_earned` column in the `Version1025` SELECT (line 106) is also removed since no code path reads it after the match arm is gone.
- Dead services/controllers are archived (per project-rules §5), not deleted, and their tests + `RouteMatrixTest` mapping updated.
- Phases 1–4 (doc-only) are independently committable from phases 5–7 (code + schema), allowing incremental delivery and separate review.

## 3. Backend / Frontend Changes

- **Backend (`lib/`):**
  - `Db/HouseUser.php` — remove `currentSparks`/`totalSparksEarned` fields, `@method` docs, `addType()` calls, `jsonSerialize()` keys, dead `addSparks()`/`spendSparks()`.
  - `Titles/TitleCatalog.php` — remove `SIGNAL_TOTAL_SPARKS_EARNED` constant + its getter mapping; fix the misleading "driven by totalSparksEarned" comment (Cozy Home uses `SIGNAL_COINS`).
  - `Migration/Version1025Date20260719000000.php` — remove the dead `SIGNAL_TOTAL_SPARKS_EARNED` match arm from `signalColumn()` (line 160) and the `total_sparks_earned` column from the SELECT (line 106). No pack uses this signal, so the arm never executes; removing it is safe and required before the constant can be deleted from `TitleCatalog`.
  - `Service/TitleService.php` — drop `totalSparksEarned` from the docblock signal list.
  - `Migration/Version1026Date20260724000000.php` (new) — `DROP COLUMN current_sparks`, `DROP COLUMN total_sparks_earned` on `chorestrike_house_users`. Runs after `Version1025` backfill by version ordering.
  - `Service/MedalService.php` — **archive** (dead; only its own test references it; `GameLogicService::awardMedal*` is the live award path).
  - `Controller/UserStatsController.php` — **archive** (8 public actions, 0 routes). Remove the `'user_stats' => 'UserStatsController'` mapping in `tests/unit/RouteMatrixTest.php`.
  - `Controller/RoomController.php` — remove `addTask()` (`:464`) and `removeTask()` (`:502`) dead actions.
- **Frontend (`src/`):** none (no store/component consumes `currentSparks`/`totalSparksEarned` from payloads — verify in Phase 5).
- **Cross-layer contracts:** the user-serialization payload shrinks by two keys (`currentSparks`, `totalSparksEarned`). These are not read by any store/component (to be verified). No route contract change.

## 4. Tests

- **Unit (PHPUnit):**
  - Update `tests/unit/Titles/TitleCatalogTest.php` — remove `SIGNAL_TOTAL_SPARKS_EARNED` assertions; add regression test that the catalog validates without the removed signal.
  - Archive `tests/unit/Service/MedalServiceTest.php` with `MedalService`.
  - Update `tests/unit/RouteMatrixTest.php` — drop `user_stats` mapping.
  - Update any `HouseUser` entity tests that assert sparks fields.
  - Add a migration smoke test for `Version1026` (column-drop idempotency) if the pattern exists in `tests/unit/Migration/`.
- **Unit (Jest):** none expected (frontend doesn't consume the removed keys); add a regression assertion in `apiContract.spec.js` only if a store is found to reference sparks.
- **Integration (PHPUnit, Docker):** run the existing suite to confirm no integration test depends on `current_sparks`/`total_sparks_earned` or `UserStatsController`.
- **E2E (Playwright):** none.
- **i18n / l10n:** no user-visible strings change; no `l10n/` artifact update needed.
- **Documentation gate:** markdown relative-link integrity grep (pattern from CS-PLAN-009 Phase 5/Review) must report 0 broken links after the rewrites.

## 5. File Manifest

```
new:      docs/plans/new/cs-plan-010-doc-vs-impl-reconciliation.md   (this plan)
modified: docs/backlog/CODE_REVIEW_2026-07-24.md                      (extend with reconciliation findings; file exists as untracked from the 2026-07-24 review)
new:      lib/Migration/Version1026Date20260724000000.php             (drop sparks columns, Phase 5)

modified: docs/engineering/architecture.md          (directory tree + DB schema + data-flow)
modified: docs/concepts/design.md                   (medal names + status)
modified: docs/concepts/gamification-and-rewards.md (reward tokens, combo, freezes, teamwork, weekly goal)
modified: docs/concepts/energy-and-balancing.md     (reward tokens cross-ref)
modified: docs/engineering/testing.md               (E2E table 20→32, phpunit command consistency)
modified: docs/api/README.md                        (remove phantom PUT /room-tasks/{id}; document route groups)
modified: docs/standards/ai/skills/gamification/SKILL.md (medal award reality, reward tokens)
modified: lib/Db/HouseUser.php                      (remove sparks fields/methods)
modified: lib/Titles/TitleCatalog.php               (remove dead signal + comment)
modified: lib/Migration/Version1025Date20260719000000.php (remove dead match arm + unused SELECT column)
modified: lib/Service/TitleService.php              (docblock)
modified: lib/Controller/RoomController.php         (remove dead addTask/removeTask)
modified: lib/Service/RoomService.php               (review follow-up: remove orphaned addTaskToRoom/removeTaskFromRoom)
modified: tests/unit/RouteMatrixTest.php            (drop user_stats mapping)
modified: tests/unit/Titles/TitleCatalogTest.php    (remove sparks signal)
modified: tests/unit/Service/RoomServiceTest.php    (review follow-up: drop addTaskToRoom/removeTaskFromRoom cases)
modified: docs/backlog/ACTIVE_TASKS.md              (register CS-PLAN-010, then Done)
modified: docs/backlog/phase-9-code-quality.md      (mark MedalService/UserStatsController/Sparks residue resolved)

archived: archive/2026-07/dead-code/lib/Service/MedalService.php
archived: archive/2026-07/dead-code/tests/Service/MedalServiceTest.php
archived: archive/2026-07/dead-code/lib/Controller/UserStatsController.php
archived: archive/2026-07/dead-code/lib/Service/RoomService-removed-methods.php  (review follow-up)
```

## 6. Implementation Order

1. **Phase 0 — Record & register.** Extend the existing untracked `docs/backlog/CODE_REVIEW_2026-07-24.md` with the four reconciliation findings condensed. Register CS-PLAN-010 in `docs/backlog/ACTIVE_TASKS.md` under "Currently Active". Confirm the 2026-07-24 uncommitted review changes are committed (recommend a separate commit) so doc rewrites describe a stable tree.
2. **Phase 1 — `architecture.md` rewrite (high drift).** (a) Directory tree: remove `TaskClaim`/`TaskParticipant`/`TaskScope`/`SchedulingView`; add `lib/BackgroundJob/`, `lib/Notification/`, `lib/Exception/`, `lib/Templates/`, `lib/AppInfo/`; add `src/composables/`, `src/styles/`, `src/constants/`, `src/services/`; add `confetti.js`/`sound.js` to utils; add `HouseholdGoalBar.vue`; note `Version1000…Version1025` (non-contiguous, no 1014). (b) DB schema: switch to lowercase table names; `energy_cost` not `difficulty`; `coins` not `total_points`; add `contribution_score`/`longest_streak`/`streak_freezes`/`coin_multiplier`/`selected_title_pack`/`unlocked_titles`/`weekly_goal_notified_week` to `house_users`; document the 9 missing tables; mark `monster_count` as legacy-retained-but-unused. (c) Data-flow: replace `TaskItem.vue`/`RoomView.vue` → `TaskList.vue`/`RoomDetail.vue`; `checkCombo()` → `checkForCombo()`; fix `HouseStore` example.
3. **Phase 2 — `design.md` + concept reward docs.** Update `design.md` medal section to the real enum and mark medal types not-yet-awarded per Decision Point A. Add the undocumented reward tokens (streak freezes, combo bonus freeze, teamwork bonus, weekly goal target, title thresholds) to `gamification-and-rewards.md` / `energy-and-balancing.md` / gamification SKILL. Resolve combo multiplier per Decision Point B.
4. **Phase 3 — `testing.md`.** Update E2E table to all 32 specs (add the 12 missing); fix the `phpunit.xml tests/integration/` → `phpunit.integration.xml` inconsistency in the "PHPUnit Unit and Integration Tests" section.
5. **Phase 4 — `api/README.md`.** Remove the phantom `PUT /room-tasks/{id}`. Add stub sections for the undocumented route groups (`room_task` assignees/claims/filtered-daily, `scheduling`, `smart_scheduler`, `template`, `checklist`, `task_sync`, `minigame`, `reward_config`, `user` extras, `house`/`floor`/`room` extras) — or add a single "Full route list: see `appinfo/routes.php`" pointer if full coverage is deferred. Fix `GET /test/seed` path-param note.
6. **Phase 5 — Sparks residue removal (code + migration).** Verify no `src/` **or `lib/`** consumer reads `currentSparks`/`totalSparksEarned` (grep both trees; known readers: `HouseUser` itself, `TitleCatalog` getter mapping, `TitleService` docblock, `Version1025` SELECT + match arm). Edit `Version1025` first (remove dead match arm + unused SELECT column), then `TitleCatalog.php` (remove constant + mapping), then `HouseUser.php`, then `TitleService.php` docblock. Create `Version1026` drop migration. Update `TitleCatalogTest.php` + HouseUser tests. Confirm `Version1025` backfill still reads the remaining columns (`contribution_score`, `longest_streak`, `coins`) at its run time.
7. **Phase 6 — Dead-code removal.** Archive `MedalService` (+ test) and `UserStatsController`; update `RouteMatrixTest.php` (drop `user_stats` mapping); remove `RoomController::addTask()`/`removeTask()`. **Review follow-up:** also archive the now-orphaned `RoomService::addTaskToRoom()`/`removeTaskFromRoom()` (their only callers were the removed controller actions) + drop their `RoomServiceTest` cases.
8. **Phase 7 — Medal award gap (Decision Point A).** Execute the chosen direction: **default** = correct docs/SKILL to "only `team_anchor` and `streak_keeper` are awarded; `flow_keeper`/`room_guardian`/`team_player` are catalogued but not yet awarded" + log a backlog item for the missing award paths. Alternative = implement the 3 missing award checks in `GameLogicService::checkAndAwardMedals` (separate feature effort — split into its own plan if chosen).
9. **Phase 8 — Verify & close.** Markdown link-integrity grep (0 broken). Gate: `npm run lint`, `npm test`, `composer validate --strict`, PHPUnit unit + integration (Docker) green. Update `phase-9-code-quality.md` (mark resolved) and `ACTIVE_TASKS.md` (Done). Propose `/review` of the diff.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| Frontend/store reads `currentSparks`/`totalSparksEarned` from payload → silent `undefined` after drop | Low (CS-PLAN-002 migrated UI to coins) | Grep `src/` in Phase 5 before editing; if any reader found, migrate it first and defer the column drop |
| `Version1026` drop breaks a fresh install if `Version1025` backfill ordering is wrong | Low | Version ordering is enforced by Nextcloud; `Version1025` strictly precedes `Version1026`. Add a migration smoke test; run the integration suite in Docker |
| Removing `SIGNAL_TOTAL_SPARKS_EARNED` from `TitleCatalog` breaks `Version1025::signalColumn()` match arm | High (if not addressed) | Phase 5 removes the dead match arm from `Version1025` **before** removing the constant. The arm is provably dead (no pack uses the signal). Order: edit migration → edit catalog → edit entity |
| Dropping `total_sparks_earned` loses historical contribution data | Medium | The column is never incremented post-CS-PLAN-002 (verified: `GameLogicService` calls `addCoins()`), so values are frozen/stale. Back up via the existing `Version1025` backfill already having captured title state. If preservation is required, archive values to a log instead of dropping — **confirm in Decision Point C** |
| `RouteMatrixTest` / DI container still references `UserStatsController` after archival | Low | Update `RouteMatrixTest.php` mapping in the same phase; `Application.php` does not register it (verify) |
| Doc rewrite introduces broken relative links | Medium | Re-run the CS-PLAN-009 link-integrity grep sweep after Phases 1–4 |
| Medal/Combo "Decision Points" implemented in a direction the user did not intend | Medium | Defaults are conservative (align docs to reality). Confirm Direction at activation; Phase 7 is isolated so it can be deferred without blocking the rest |

## 8. Decision Points (confirm at activation)

- **A — Medal award gap:** (default) document reality "2 of 5 awarded" + backlog the missing paths · (alt) implement the 3 missing award checks (split into a new plan).
- **B — Combo multiplier:** (default) align docs to code (×2 personal combo) · (alt) implement the documented cooperative ×1.5 multi-user combo (code change, new feature).
- **C — Sparks column data:** (default) drop `current_sparks`/`total_sparks_earned` (values stale/zero since CS-PLAN-002) · (alt) keep columns, only stop serialising them (smaller blast radius, but leaves residue).

## 9. Acceptance Criteria

- [x] `CODE_REVIEW_2026-07-24.md` exists with the four findings.
- [x] `architecture.md` directory tree and DB-schema match the real tree + migrations (no archived files referenced; lowercase tables; `energy_cost`/`coins`; sparks-free schema).
- [x] `design.md` medal names match `Medal.php`; concept docs list all implemented reward tokens.
- [x] `testing.md` E2E table lists 32 specs; phpunit command is consistent (`phpunit.integration.xml`).
- [x] `api/README.md` no longer documents the phantom `PUT /room-tasks/{id}`; undocumented groups addressed.
- [x] `HouseUser` no longer serialises sparks fields; `TitleCatalog` has no dead signal; `MedalService`/`UserStatsController` archived; `RoomController` dead actions removed.
- [x] `Version1026` migration drops the sparks columns; integration suite green.
- [x] Markdown link-integrity grep reports 0 broken links.
- [x] Build gate green (`npm run lint` + `npm test` + PHPUnit unit/integration). `composer validate --strict` not run locally (no composer binary; `composer.json`/`composer.lock` unchanged).
- [x] `docs/backlog/ACTIVE_TASKS.md` + `phase-9-code-quality.md` updated.

> **Deviations from plan:** (1) Phase 5 also removed the dead `MinigameService::getUserSparks()`
> (a `getCurrentSparks()` reader the plan's known-readers list missed — only test-referenced,
> so its removal is consistent with the cleanup intent). (2) Phase 6 (review follow-up) also
> archived the now-orphaned `RoomService::addTaskToRoom()`/`removeTaskFromRoom()` + their
> `RoomServiceTest` cases, since the only callers were the removed `RoomController` actions.
> (3) A fresh-install schema gap (`Version1018`–`1020` not persisting
> `task_tier`/`flex_days`/`ideal_due_date`/`coin_multiplier`) was reproduced during validation
> and logged as a `[P2]` `bugs.md` entry for a dedicated plan (out of scope here).
