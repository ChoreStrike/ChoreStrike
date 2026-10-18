# Code Review: CS-PLAN-067 WP8 — Batching, Decomposition, and Performance Hardening (final Standard B)

**Review Date**: 2026-09-13
**Reviewer**: Kilo agent (Standard B final code review)
**Mode**: Standard B (final code review over the whole owning plan)
**Scope**: Complete WP8 implementation package — 44 modified tracked + 19 new files, captured as the uncommitted working tree on `master`; final-manifest, acceptance-criteria, test-evidence, durable-doc and implementation-history depth per the review skill §3
**Base Commit**: `0cc51dae46e23e1c6050b18ce70d107dbeb58ac6` (Standard A activation)
**Owning Plan**: [`docs/plans/done/cs-plan-067-wp8-batching-decomposition-performance.md`](https://github.com/ChoreStrike/ChoreStrike/blob/c03e940d071251ed62cedbe9ef4a7a2640d0f294/docs/plans/done/cs-plan-067-wp8-batching-decomposition-performance.md) (moved `active/ → done/` by this review)

---

## Executive Summary

### Outcome
- PASS-WITH-FIXES

### What Was Reviewed
- Backend D1: `RoomTaskController::index` selector precedence (scopeId → roomId → house) with server-side house resolution, foreign-roomId 404, scopeId intersect-with-visibility (200 + local subset), one shared flat serializer with batched canonical assignees; `RoomTaskMapper::findByHouse` on the existing house-scope join with deterministic `next_due, created_at, id` order; `RoomTaskReadService` as the batched read/enrichment owner (empty-set short-circuits, visibility via `TaskAccessPolicy`, tz resolution, `updateDueDate` in-transaction response).
- Backend D2: `CompletionService` keeps transaction/locks/gates; `CompletionEffectService` (snapshot/restore, LIFO titles, checklist rewrite) and `CompletionPostCommitService` (log-and-degrade boundary, verbatim move with unchanged log events) extracted; `MedalService` (five predicates, race-safe award, read-triggered timing) with `GameController`/`GameLogicService` repointed; `handleTaskCompletion` ledger REQUIRED (self-inserting branch deleted, pinned "never inserts").
- Frontend D1/D3: `fetchHouseRoomTasks` (house generation, drop-when-superseded-by-room-read, house loaded/stale metadata, reset invalidation); TaskManager/SchedulingView one-request init; TaskManager `refreshAfterCreate` + RoomDetail add/template/save re-fetches removed; unused `fetchAssignees` action removed; TaskList decomposed into `TaskListItem`/`TaskListBulkBar`/`TaskListDetails` + `utils/taskDisplay.js` (pure, explicit tz); controlled `AssigneeChips`; store DAG acyclicity pinned (`tests/unit/store/dependencies.spec.js`).
- Budgets D4: `QueryCounter` (DBAL 3.10.4 SQLLogger seam, reflection documented test-only, detach in finally) + `WorkflowQueryBudgetTest` (literal ceilings: house 3 / empty 1 / updateDueDate 8 / complete 27 / undo 32, exact-ID assertions, statement-order proof, lock counts) + `requestCounts.spec.js` + `workflow-request-budgets.spec.ts` (both views exactly one house-wide request).
- Durable docs: API selector contract, decomposition idiom, budget methodology, D6 size-exception inventory; backlog routing (BUG-E2E-006 recorded in round 1).

### What Was Fixed Immediately
1. `tests/unit/services/coreWorkflowLocalization.spec.js`: the four extracted TaskList units joined `CORE_SURFACES` — moved `t()` calls had silently escaped source-coverage verification (§4.3 requirement).
2. `l10n/templates/chorestrike.pot`: stale dead-key msgid block (`roomId or scopeId is required`) removed to match en/de.
3. Scoped `composer cs:fix`: 4 reviewed files (`CompletionEffectService.php`, `WorkflowQueryBudgetTest.php`, `CompletionUndoStateEqualityTest.php`, `RoomTaskServiceDailyTest.php`) — `cs:check` red at review start despite the round-1 green claim; both PHP suites re-run green after.
4. `src/components/Common/NotAuthorizedState.vue`: pre-existing stylelint `use-logical` warnings collapsed to `margin-inline: auto;` (verified safe fix outside WP8 scope).
5. D6 size-exception inventory authored into `docs/engineering/architecture.md` (55 files: 3 named, 50 accepted with individual reasons, 2 remainders owned by CR-SVC-15) per user decision D8.

### Main Risks Remaining
1. None plan-owned. CR-SVC-15 keeps two inventoried decomposition remainders (`RoomTaskService` range/stats projection, `TaskService` 24-param `createTask`); CR-SVC-44 keeps the D5 streak-batching remainder (owned by CR-053-11); both tracked, not hidden.
2. WP9-owned: BUG-E2E-005 (Sunday-dependent insights-flow overcapacity-marker assertion, 2026-09-13 fold-in recorded) and BUG-E2E-006 (date-of-day dependent stale-timezone integration test); global l10n extraction remainder (CR-053-13).

---

## Package Results

### Package 0: Scope and Diff Mapping
- Inputs: `git status` (44 modified tracked, 19 untracked), full diffs for `lib/`, `src/`, `tests/`, `docs/`, `l10n/`; scope fingerprint captured at review start — empty index (staged diff SHA-1 `e69de29bb2d1d6434b8b29ae775ad8c2e48c5391`), unstaged binary diff SHA-1 `0b47b7fc98e5d3bf56622c80c259057e7c4afe4f`, untracked SHA-256 16-hex prefixes as captured (listed in the plan §12); HEAD `0cc51da` on `master`, no unrelated changes, no concurrent edits.
- Findings: scope unambiguous (explicit owning plan; single active child plan); §5 manifest reconciliation performed by this review (proposal entries not needed vs. actually-changed files joined — see plan §5 final-review reconciliation note).

### Package 1: Authorization, Security, Data Flow
- `@NoAdminRequired` only across touched controllers (grep-verified, no `@PublicPage`); membership asserted before every selector mode; per-instance ADR-010 visibility through `TaskAccessPolicy` inside the batched read (no duplicated matrix); named parameters only (`findByHouse`, `IQueryBuilder::PARAM_BOOL`); no `v-html`/`console.*` in new units; logs use `AppLogger`/`logger.js` with stable keys, no sensitive payloads; no stack traces in responses.
- Findings: none.

### Package 2: Correctness & Architecture
- Decomposition verified as verbatim cohesive moves preserving ADR-009..012 boundaries; aggregate owner retains transaction lifetime, lock order and occurrence/actor gates; collaborators never open transactions; no forwarding shims left in `RoomTaskService`; `handleTaskCompletion` null-guard accepted as nit (tests pin the contract).
- Store race guards: house read drops when superseded by newer room reads; full-house result replaces the instance set, room result updates only its room; mutation/reset invalidation extended to the house projection.
- Findings: none open (see §9 of the plan for the resolved in-round items).

### Package 3: Tests & Budgets
- Full §4.4 gate re-run green on the fixed state (below). 10 documented pre-existing integration skips inventoried (FamilyIntegrationTest mocked-mapper/formula scenarios, GameControllerIntegrationTest legacy scenarios); no new skips. Budget tests assert exact IDs/state together with counts; counter detached in `finally`.
- Findings: localization-coverage gap (fixed, §1 above); cs:check red (fixed, §3 above).

### Package 4: Docs, Plan & Lifecycle
- Plan §4.3/§5 reconciled (read-only budget spec stays in the default pool — user decision D11; no `playwright.config.ts` change); §8 all criteria checked; §9 findings recorded and resolved; §10 review-round gate record; §12 outcome. Masterplan WP8 row complete (WP9 eligible, RELEASE-002 still blocked by WP9); `docs/plans/README.md` repointed; `ACTIVE_TASKS.md` rolled (3 oldest entries archived).
- Backlog: CR-WF-06/CR-SVC-40/CR-SVC-41 archived to `archive/2026-09.md`; CR-SVC-15 WP8-slice annotation; CR-SVC-44 D5 annotation (updateDueDate re-fetch resolved; streak batching stays CR-053-11); CR-WF-05 dependency repointed (satisfied); BUG-E2E-005 fold-in update recorded.

### User Decisions (batched structured question, 2026-09-13)
- D8: review authors the D6 size-exception inventory (executed).
- D9: automated accessibility evidence accepted for AC-11 in lieu of a manual child-persona screen-reader pass (recorded rescope).
- D10: BUG-E2E-005 fold-in with static attribution (review's duplicate "BUG-E2E-007" deduplicated).
- D11: read-only budget spec stays in the default Playwright pool.
- D12: complete this round — `active/ → done/`.

### Gates (final state, after fixes)
`npm run lint` 0 problems · `npm run stylelint` clean · `npm test` 939/939 · `npm run test:noise` clean · `npm run build` green · `npm run l10n:check-keys` green · `npm run docs:encoding` green · `npm run backlog:check` green · `git diff --check` clean · PHP unit 920/920 · PHP integration 320 (10 documented pre-existing skips, 0 failures) · `composer cs:check` green · Playwright chromium 94 passed / 7 pool-excluded skips · chromium-serial-db 106 passed (one first-run flake in `release-notifications` did not reproduce across two full runs; sole failure = pre-existing Sunday-dependent BUG-E2E-005 overcapacity-marker assertion, statically attributed WP8-independent — WP8 touched neither `ScheduleCalendar` nor `plannedEnergyForDay`, the overcapacity warning itself passed, WP7 pools were green on Saturday 09-12) · chromium-scheduling-owner 38/38.

---

# Code Review: CS-PLAN-068 WP9 - Deterministic Coverage and Release-Quality Closure (Standard A)

**Review Date**: 2026-09-13
**Reviewer**: Kilo agent (Standard A plan review)
**Mode**: Standard A (plan review before `new/ -> active/`)
**Scope**: the single regular plan file `docs/plans/new/cs-plan-068-wp9-deterministic-coverage-release-closure.md` (untracked draft) plus its five draft-owned companion modifications (ACTIVE_TASKS, backlog.md, archive/2026-09.md, plans/README.md, masterplan) - verified as one package, no unrelated drift
**Base Commit**: `4b99d9ff79b95a1fe64ebec657cd25a00c27aafb` (post-WP8, clean index); untracked plan content hash `9611ac75565091fc9fb2e01491981cc3df74312c`
**Owning Plan**: the reviewed plan itself (moved `new/ -> active/` by this review)

## Outcome: PASS-WITH-FIXES

## Findings

| Severity | Location | Issue | Status |
|---|---|---|---|
| minor | plan section 3.5 (MinigameControllerIntegrationTest row) | "All seven registered minigame routes" understates the tree: `appinfo/routes.php` + `MinigameController` register nine (omitted `minigame#userRank`, `minigame#recentScores`) | Fixed in round (RF-068-01; enumeration corrected to nine) |

No security findings. No unanswered questions after the batched decision request.

## Inventory verification (spot checks, all confirmed)

- Three Chromium pools in `playwright.config.ts`; `.forgejo/workflows/release-gate.yml` omits `chromium-scheduling-owner` from its blocking E2E step.
- Exact unconditional integration skip census: 8x `FamilyIntegrationTest` + 2x `GameControllerIntegrationTest`; every other skip is a MySQL engine guard on a concurrency suite.
- Legacy `tests/e2e/fixtures/test-fixtures.ts` targets removed routes (`api/reset-test-data`, `api/seed-minimal`) and returns false; live test routes are `/api/v1/test/{profiles,seed,reset}`.
- `Request failed`, `Boost Shop`, `This Week's Medals` absent from both `l10n/en.json` and `l10n/de.json`; `scripts/check-l10n-keys.js` validates only de<->en parity (no POT/source extraction).
- CR-053-22 deferred list matches the tree: `MinigameControllerIntegrationTest` + `TestDataSeedTest` absent; all 8 named component suites absent while all 8 components exist under `src/components/`.
- BUG-E2E-005/006 mechanisms code-grounded: `SchedulePlanAggregateTest` seeds `todayKey` in UTC (line 52) and switches the house to `Europe/Berlin` (line 366); `SchedulePlanService` buckets windows by house timezone; `task-completion-full-flow.spec.ts` swallows the rewards-toast expectation (line 68) and contains the `toBeGreaterThanOrEqual(0)` combo tautology (line 114).
- All 15 referenced backlog IDs resolve; parent D5 scratch-replacement ratification verified; `emptyhouse` profile + runtime test-mode contract (`NEXTCLOUD_TEST_MODE`, `@NoAdminRequired`/`@NoCSRFRequired`) verified; README tower-defense/Sparks/"v1.0.0 Release Candidate" claims and `appinfo/info.xml` `0.1.1` metadata verified.

## User Decisions (batched structured question, 2026-09-13)

- D1: sequential three-project browser gate via `scripts/run-e2e-gate.js` + `npm run test:e2e:gate`, scheduling-owner added to the blocking release workflow, legacy no-op fixture removed, fail-not-skip on missing fixtures. Ratified as recommended.
- D2: UI-driven `emptyhouse` canonical journey `core-household-journey.spec.ts` in `chromium-serial-db`; no app-data mutation API or store-patch progression. Ratified as recommended.
- D3: close the complete still-live CR-053-22 remainder without product-rule changes. Ratified as recommended.
- D4: in-place `check-l10n-keys.js` extension, finite dynamic-key ownership, opt-in regeneration, dead keys hard-gated after inventory. Ratified as recommended.
- D5: retain the runtime-gated test-tooling contract (incl. its `@NoCSRFRequired` exception) with enabled/disabled-mode regression proof and accurate release instructions. Ratified as recommended (security-relevant authorization asked explicitly).
- D6: enumerated CR-053-21 core/testing truth slice + README rewrite; ADR-003 annotated via ADR-008/current scope, no invented ADR-013. Ratified as recommended.
- D7: child final Standard B, then a separate final Full review across the whole CS-PLAN-058 journey; only RELEASE-002's program prerequisite is satisfied. Ratified as recommended.

## Gates (documentation-scope draft gates)

`npm run docs:encoding` green (706 tracked files) | `npm run backlog:check` green | `git diff --check` clean | untracked-file `git diff --no-index --check` clean (zero whitespace errors; exit 1 = expected differences-found code). Application gates remain plan section 4 implementation work.

## Transition

Plan moved `new/ -> active/`; CR-WF-05, CR-053-13, CR-053-18-WP9, CR-053-21 (D6 slice), CR-053-22, BUG-E2E-005, BUG-E2E-006 set In Progress; BUG-E2E-006 owning corrected to CS-PLAN-068 (provenance retained); inbound links (ACTIVE_TASKS, plans/README.md, masterplan WP9 row) repointed; one scoped activation commit.
