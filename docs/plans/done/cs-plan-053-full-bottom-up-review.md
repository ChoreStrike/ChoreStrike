# CS-PLAN-053: Full Bottom-to-Top Review — Modules, Docs, Orphans, Missing Implementations & Tests

> **Status:** Plan (not started)
> **Effort:** XL
> **Priority:** P2
> **Depends on:** none (last full review record: `docs/backlog/CODE_REVIEW_2026-08-11.md`; ~20 plans landed since)
> **Related issue:** —

## 1. Problem Summary

The codebase has grown to **104 PHP files (~23,700 lines)** + **95 frontend files**, 28 migrations, 110 routes, and a large docs tree — without a full-tree review since 2026-08-11. Recent plans were per-topic (i18n, observability, E2E, reward registry); structural drift accumulates *between* topics and nobody owns the whole picture:

- **Rule violations already visible:** `RewardConfigController::update` is `@AdminRequired` (violates "`@NoAdminRequired` only") with no house-owner gate and no frontend consumer; `RoomTaskController::filteredDaily` uses a `\OC::$server` service locator; 64 files exceed the ~300-line guideline (26 backend + 38 frontend; worst: `RoomTaskController` 1053, `TaskList.vue` 1094, `Settings.vue` 982).
- **Dead/orphaned code candidates:** `AvailabilitySettings` entity+mapper are effectively unused (scheduling moved to `house_user` JSON); `src/utils/mobile.js` + `src/utils/performance.js` have zero production imports (only their own Jest specs import them); several mapper/entity methods with no production callers; documented-dead legacy alias routes.
- **Test gaps:** `TestDataSeed` command untested; `MinigameScore`/`TaskAssignee` entity tests missing; no integration tests for the Checklist/Minigame/RewardConfig/TaskSync controllers; 8 frontend components without Jest specs.
- **Docs drift:** `docs/api/` defers the full route list to `routes.php` and documents *removed* endpoints (`phase5-smart-scheduling.md`); several concept docs describe features with no implementation (realtime updates, task-sync UI, global search UI); open backlog items have never been re-verified against the tree en masse.

Goal: a **single structured full review, bottom-to-top** (persistence → services → API → frontend → docs/backlog) that produces a verified, deduplicated findings list, closes small gaps inline, and routes everything else into `backlog.md` — so the *next* iteration of the project starts from a truthful map of what exists, what is dead, and what is missing.

## 2. Strategy

Run the review in **six phases**, strictly bottom-to-top so every layer review can rely on the layer below being already verified:

1. **Phase 0 — Baseline & freeze:** all gates green on a clean tree; inventory (from this plan's §9 appendix) re-verified and frozen; scope tables per phase.
2. **Phase 1 — Persistence:** entities, mappers, migrations.
3. **Phase 2 — Services:** all 22 services + background jobs + commands + notifier + title catalog.
4. **Phase 3 — API surface:** controllers, `routes.php`, `appinfo/`, cross-layer contracts.
5. **Phase 4 — Frontend:** stores, components, views, utils, router, composables.
6. **Phase 5 — Docs & backlog integrity:** every doc area checked against the verified tree; every open backlog item re-validated (still reproducible? still relevant? already fixed?).
7. **Phase 6 — Consolidation:** deduplicate findings, auto-fix per review-skill §5, backlog entries with `Source: CS-PLAN-053 full review`, review record `docs/backlog/CODE_REVIEW_YYYY-MM-DD.md`, knowledge integration.

Per phase, the **Full-tier pattern** from the review skill applies, scaled to the layer: an `explore` subagent refreshes the file/facts inventory cheaply, then up to **4 parallel `reviewer` subagents** cover the four perspectives (Correctness, Architecture/Ownership, Security/Auth/Data-flow, Performance/Quality) for that layer. The main agent orchestrates, consolidates, and owns every write. **This is a review plan: findings are logged, not feature-implemented** — except review-skill §5 auto-fixes and closing *test gaps* with regression tests where the plan lists them explicitly (Phase 6).

Security-relevant findings (e.g. `RewardConfigController` authorization) are **never auto-fixed**; they become explicit backlog items surfaced to the user.

## 3. Backend / Frontend Changes

**No product behavior changes are in scope.** Expected edits:

- **Backend (`lib/`)**: deletions of verified-dead code (Phase 2/3 decisions, each gated by a no-caller proof), docblock/PHPDoc fixes, `console.*`-equivalent logging corrections, small coding-standard fixes. No new features, no migrations, no API changes.
- **Frontend (`src/`)**: deletion of verified-dead utils/exports, missing Jest specs for the 8 uncovered components (Phase 6, only if the components themselves are verified correct — otherwise the gap is logged), hardcoded-string → `t()` fixes.
- **Cross-layer contracts**: none changed; drift between payload ↔ store ↔ component is *recorded*, not fixed here.
- **Docs (`docs/`)**: truth-repairs only where the review verified drift (api README gaps, superseded/dead-endpoint docs, concept docs describing unimplemented behavior — each gets a "status: not implemented" note or an archive move decision). Backlog edits per Phase 5/6.
- **Tests**: new regression/unit tests only for review-triggered fixes and the explicitly listed test-gap closures (§4).

## 4. Tests

- **Phase 0 baseline:** `npm test`, `npm run lint`, `npm run stylelint`, `npm run test:noise`, `npm run l10n:check-keys`, `npm run docs:encoding`, Docker PHPUnit (unit + integration), `composer cs:check` — all recorded as the reference state (known pre-existing skips documented, not silently accepted).
- **Per-phase gates:** after each auto-fix cluster, re-run the affected gate (`lint:fix` + `npm test` for JS, Docker PHPUnit for PHP) per review skill §5.
- **Test-gap closures in scope (Phase 6, log-only if the component/code under test is itself defective):**
  - PHPUnit entity tests: `MinigameScoreTest`, `TaskAssigneeTest`.
  - PHPUnit unit test for `Command/TestDataSeed.php`.
  - Integration tests (`*ApiTest.php`) for `ChecklistController`, `MinigameController` (`MinigameController` has unit only), `RewardConfigController`, `TaskSyncController`.
  - Jest specs for: `Insights/ActivityFeed`, `Insights/HouseStatsCards`, `Insights/MemberFairnessTable`, `Scheduling/WeekBudgetGrid`, `Tasks/RoomPicker`, `Minigames/BoostShop`, `Minigames/SnakeGame`, `Minigames/SnakeLeaderboard`.
- **Regression tests:** every bug fixed inline during the review gets a regression test in the same change (project-rules §2).
- **E2E:** not extended by this plan; the existing Playwright matrix is the safety net for any deletions that touch rendered behavior. Run the blocking chromium-shaped project if any `src/` deletion lands.

## 5. File Manifest

```
new:      docs/plans/new/cs-plan-053-full-bottom-up-review.md   (this plan)
new:      docs/backlog/CODE_REVIEW_<start-date>.md              (review record, Phase 6)
modified: docs/backlog/backlog.md                               (new findings + closed/stale items)
modified: docs/backlog/ACTIVE_TASKS.md                          (start/pause/complete entries)
modified: docs/api/README.md + removal/archive of verified-stale api docs (Phase 5)
modified: docs/concepts/*, docs/engineering/*                   (truth-repairs only where drift is verified)
modified: lib/**, src/**                                        (auto-fixes + verified dead-code deletions only)
new:      tests/unit/Db/MinigameScoreTest.php, tests/unit/Db/TaskAssigneeTest.php
new:      tests/unit/Command/TestDataSeedTest.php
new:      tests/integration/*ApiTest.php                         (Checklist/Minigame/RewardConfig/TaskSync, if closed)
new:      tests/unit/components/**.spec.js                      (8 component specs, if closed)
archived: archive/2026-08/<name>/                               (any removed docs/scripts, per §5 archive policy)
```

## 6. Implementation Order

1. **Phase 0 — Baseline (½ day).** Clean tree check (`git status`); run and record all gates (§4); freeze the inventory appendix (§9); write per-phase scope tables; update `ACTIVE_TASKS.md`.
2. **Phase 1 — Persistence (1 day).** Review all 13 entities, 13 mappers, 28 migrations. Verify the dead-code candidates: `AvailabilitySettings` + mapper (no production callers — decision: delete code + keep/flag migration), `HouseUser::updateStreakWithAbsence/getRoleLabel/getRank/incrementStreak`, `CompletedTask::isRecent`, `MedalMapper::findByWeek/findByUserAndWeek/getMedalCountsByType`, `MinigameScoreMapper::findByHouseAndGame`, entity `validate()` on `Task`/`TaskChecklistItem`. Check migration chain consistency (fresh-install only, per pre-release rule), `Version1017` raw-SQL exception, mapper SQL hygiene (`createNamedParameter`, no concatenation). Deliverable: findings table L1.
3. **Phase 2 — Services (1–1.5 days).** All 22 services + 3 background jobs + 2 commands + `ChoreStrikeNotifier` + `TitleCatalog`. Focus: layering violations (services reaching into `IDBConnection` directly — CR-SVC-40 context), god-service decomposition candidates (CR-SVC-15 map: `RoomTaskService` 945, `RoomService` 573, `GameLogicService` 571 — *map only, no refactor*), `TestDataService` unused injected mapper, `TaskSyncService::getRoomTask` (public, apparently uncalled), orphan-detection sweep for any service method with no route/controller/job/caller. Deliverable: findings table L2 + dead-code deletion list (each with no-caller proof).
4. **Phase 3 — API surface (1 day).** All 15 controllers, `routes.php`, `appinfo/` (info.xml jobs/notifier), `ControllerErrorFormatter`, logging trait. Verify: `RewardConfigController::update` `@AdminRequired` + missing owner gate + zero frontend consumers (→ security backlog item, never auto-fix); service locator in `RoomTaskController::filteredDaily`; legacy create-alias/createExplicit routes + POST→GET rewrite workarounds (BUG-ROOM-001) — deletion decision with E2E impact check; routes↔methods↔frontend-consumer matrix (build on `ui-use-cases.md` route ledger; flag the 56 no-consumer routes for a single prune decision); input validation at every boundary. Deliverable: findings table L3 + route disposition matrix.
5. **Phase 4 — Frontend (1–1.5 days).** All 13 stores, 50 components, 9 views, utils/composables/router/constants. Verify: `utils/mobile.js` + `utils/performance.js` zero-import orphans (delete + keep or archive specs), `TITLE_PACKS` unused export, dead store wrappers (FEAT-UX-028 context), oversized files vs ~300-line guideline (record, don't split), `v-html` audit, raw `console.*` audit, hardcoded-string audit vs `t()`, i18n key sync. Deliverable: findings table L4.
6. **Phase 5 — Docs & backlog integrity (1 day).** For every doc area: `docs/api/` (complete the route documentation gap; mark/remove `phase5-smart-scheduling.md` stale endpoints — archive decision), `docs/concepts/` (mark not-implemented status where concepts describe absent behavior: realtime updates, task-sync UI, global search UI, approval workflow, leave house), `docs/adr/` (ADR-005 superseded state verified), `docs/engineering/` + `docs/standards/` (spot-check code examples against real code — P9-DOC-005 precedent), `docs/plans/` folders (stale states), `info.xml` vs real jobs/sitemap. Then backlog sweep: every open item in `backlog.md` re-verified against the tree → keep / update / close-with-evidence; dedupe against review findings. Deliverable: findings table L5 + backlog disposition list.
7. **Phase 6 — Consolidation & closures (1–1.5 days).** Deduplicate L1–L5; apply §5 auto-fixes cluster-by-cluster with gate re-runs; write the security backlog items (`RewardConfigController`, anything new from L3); close the explicit test gaps (§4) where the code under test is verified; write `CODE_REVIEW_<date>.md`; integrate durable outcomes into permanent docs; move plan to `done/` recommendation + `ACTIVE_TASKS.md` update; propose prioritized next tasks.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| False-positive "dead code" deletions break hidden consumers (tests, dynamic calls, NC container wiring) | Medium | Every deletion needs a grep-proof across `lib/`, `src/`, `tests/`, `appinfo/` + Docker PHPUnit + Jest green; `\OC::$server`/container-registered classes get an extra `info.xml`/`Application.php` check |
| Review scope balloon — findings trigger feature work mid-review | High | Hard rule: this plan logs findings; implementation happens in follow-up plans. Only §5 auto-fixes and §4 test closures are done inline |
| Subagent findings overlap/duplicate across phases | High | Phase 6 dedup step; single findings taxonomy (severity × layer × file); main agent owns consolidation only |
| Backlog sweep invalidates items other agents/user consider active | Medium | Disposition list is *proposed* in the review record; item closures with evidence need user sign-off for anything P1/P2 |
| Deleting the 56 no-consumer routes is tempting but behavior-changing | Certain (if attempted) | Route disposition is a **decision matrix for the user**, not inline action; only documented-dead aliases are deletion candidates |
| Docs truth-repairs churn the docs tree | Medium | Only verified drift gets edited; archive (not delete) removed docs; `docs:encoding` gate after edits |
| Pre-existing 24 integration skips mask regressions during gate re-runs | Low | Baseline records exact skip counts in Phase 0; any *new* skip/fail is a blocker |
| Windows/PowerShell + Docker flakiness stalls gates | Medium | Project-rules §2: raise timeouts (≥300 s), diagnose `docker info`, never conclude "Docker absent" |

## 8. Acceptance Criteria

- [ ] All six phases executed; findings tables L1–L5 exist in the review record with severity, file:line, evidence, and disposition (fixed / backloged / accepted).
- [ ] Every verified-dead code deletion has a no-caller proof recorded and gates stayed green after deletion.
- [ ] Security findings (min. `RewardConfigController` authorization) are explicit backlog items — **not** silently auto-fixed.
- [ ] Route disposition matrix covers all 110 routes (consumer / no-consumer / dead-alias / test-only).
- [ ] Backlog sweep done: every previously-open item has a verified disposition (keep/update/close-with-evidence); stale items moved to archive.
- [ ] Docs drift repaired or explicitly status-marked; removed docs archived; `docs:encoding` green.
- [ ] §4 test gaps either closed with green tests or logged as backlog items with deferral reason and user approval.
- [ ] `docs/backlog/CODE_REVIEW_<date>.md` written (template per `review-playbook.md`); durable knowledge integrated into permanent docs.
- [ ] Build gate green (`npm test` + `npm run lint` + backend tests in Docker; no new skips/fails vs Phase 0 baseline).
- [ ] `docs/backlog/ACTIVE_TASKS.md` updated; prioritized follow-up tasks proposed.

## 9. Appendix — Frozen Inventory Baseline (2026-08-27, branch `feat/cs-plan-040-transparency-insights`, tree clean except this plan's drafting notes)

*To be re-verified in Phase 0; numbers are the explore-agent snapshot this plan was drafted against.*

- **Backend:** 104 PHP files, ~23,700 lines; 15 controllers (worst `RoomTaskController` 1053, `TaskController` 962); 22 services (worst `RoomTaskService` 945); 13 mappers (worst `CompletedTaskMapper` 671) + 13 entities (worst `RoomTask` 771); 28 migrations; 26 files >300 lines. All services/mappers/jobs unit-tested; controllers all unit-tested except `PageController` (deliberate); `TestController` is covered in `tests/integration/Controller/`.
- **Frontend:** 95 files; 13 Pinia stores (all tested); 50 components; 9 views; 8 components without Jest specs (list in §4); 38 files >300 lines (worst `TaskList.vue` 1094).
- **Tests:** 77 PHPUnit unit files, 35 integration files (24 pre-existing skips), ~81 Jest specs, 41 Playwright specs, 6 projects (incl. `setup`).
- **Orphan candidates (verify, don't trust):** `AvailabilitySettings` entity+mapper (no production callers), `TestDataService::$availabilityMapper` unused, `HouseUser` 4 methods, `CompletedTask::isRecent`, `MedalMapper` 3 methods, `MinigameScoreMapper::findByHouseAndGame`, entity `validate()` ×2, `TaskSyncService::getRoomTask`, `src/utils/mobile.js`, `src/utils/performance.js`, `TITLE_PACKS` export, legacy alias routes ×4.
- **Known rule deviations:** `RewardConfigController::update` `@AdminRequired` + no owner gate + no consumer; `RoomTaskController::filteredDaily` service locator; `RoomService`/`FloorService` direct `IDBConnection` (CR-SVC-40); BUG-ROOM-001 POST→GET workarounds.
- **Declared-but-unimplemented (docs vs tree):** realtime updates (`docs/operations/`), task-sync UI (6 backend-only routes), global search UI, approval workflow, leave house, onboarding/help, notification settings, P4 futures (FEAT-UX-019/025/026/027/029).
- **Docs:** `docs/api/` route-doc gap + SEC-AUDIT-001 open + `phase5-smart-scheduling.md` documents deleted endpoints; ADR-001…008 exist, ADR-005 superseded; backlog ~20 open + 3 release items.
- **Marker hygiene:** 1 TODO (`lib/Db/TaskMapper.php:238`), 0 FIXME, 0 HACK.
