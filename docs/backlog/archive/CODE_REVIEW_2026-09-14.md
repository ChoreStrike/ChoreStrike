# Code Review: CS-PLAN-068 WP9 (Final Standard B)

**Date:** 2026-09-14
**Reviewer:** Kilo, main-agent Standard B (no subagents)
**Outcome:** PASS-WITH-FIXES
**Owning plan:** [CS-PLAN-068](../../plans/done/cs-plan-068-wp9-deterministic-coverage-release-closure.md)
**Base:** `master` at `4d73c8827a457ef5c10a5e0c1769e0f37cb4f13e`
**Authorization:** explicit Standard B request; no `--no-commit` flag.

## Scope And Capture

The explicit active-plan path resolved ownership. Review covered the complete
WP9 manifest, acceptance criteria, required test owners, durable documents
and relevant WP0-WP8/activation history, not only a narrow working-tree diff.
The index was empty and stayed unchanged through review and verification.

- Initial scope: 68 tracked changes plus 13 untracked files, SHA-256
  `858b115b79ffec9cac1a9cccccb51ff4de2d94757362e1dcfbff4c3837122b32`.
- Final implementation capture: 100 changed paths (manifest below), SHA-256
  `1758233e0474ddedfb0f98ab819466cfd883f2bff6abac72762df7ba3a2120d7`.
- Final capture's unstaged binary diff SHA-256:
  `19d13709d6abf6b6c801f69c365a69a4cd13457e53845d6fbcb1f2e619d12f64`.
- Index-entry fingerprint (`git ls-files --stage -z`):
  `11a2722bc92ebbc8a40c786cf46c6499d9621f7716bbb42e5cd724574dc3ce9a`.
- Empty staged binary diff SHA-256:
  `e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855`.
- Captures hash staged/unstaged full-index binary diffs and sorted untracked
  paths with per-file hashes. Final captures also record SHA-256 for each
  changed file, including deletions. Branch, HEAD, index and source hashes
  were checked for unexpected drift; none occurred.
- Parent evidence-map and testing-document changes were the only declared
  metadata differences during the final browser pair (combined fingerprint
  `70c83fa249f4bf9c1ae23f634bee398ae5585256d45f1bef34f21ed16e599684`).
  Later changes are review-owned documentation, tracking and the plan move.

## Findings And Resolutions

Locations identify the retained fix/verification owner. The initial defects
were confirmed against the captured pre-fix state, not inferred from totals.

| ID | Severity | Location | Finding and verified resolution | Status |
|---|---|---|---|---|
| RF-068-02 | major | `scripts/run-e2e-gate.js:190` | Missing owner reports, empty/expected-failure attempts and null subprocess exits could pass. Reproduced with isolated VM inputs; removed suppression, require one passing attempt, fail spawn errors/signals and use fresh output directories. Pure and actual-main negative tests pass. | Fixed |
| RF-068-03 | major | `scripts/run-e2e-gate.js:134`, `tests/e2e/auth.setup.ts`, `tests/e2e/fixtures/database-fixtures.ts`, `.forgejo/workflows/release-gate.yml` | Weak scenario minima, best-effort setup, fixture skips and unguarded direct resets undermined closure. Exact reviewed inventory, mandatory setup, seed/reset entry guards, unconditional preconditions, CI assets/installed-container strict integration and retry-independent evidence now enforce it. | Fixed |
| RF-068-04 | major | `scripts/check-l10n-keys.js:43` | Missing POT, catalog/title keys, source/printf placeholders and dead keys could pass; regeneration/negative proofs were absent. Global explicit ownership, exact artifact sets, deterministic write mode, real German copy and negative/idempotence tests now cover 940 keys. | Fixed |
| RF-068-05 | minor | `src/components/Minigames/SnakeLeaderboard.vue:116` | The proposed `currentUser.uid` fix used another nonexistent field, mirrored by its mock. Uses actual `nextcloudUserId`, with real-shaped fixture and highlight assertion. | Fixed |
| RF-068-06 | major | `src/components/Tasks/TaskEditForm.vue:413`, `src/components/Tasks/RoomTaskEditModal.vue:95` | New mount reads rejected without UI recovery. Shared ErrorState retry preserves drafts/assignees and never submits the form; store remains the logging owner. Rejection/retry tests pass. | Fixed |
| RF-068-07 | major | `tests/unit/components/SnakeGame.spec.js` | A legal random food position broke the zero-score expectation; direction assertions did not prove direction. Seeded food, actual grid coordinates, pause/resume/unmount, initialization/save errors, leaderboard loading/empty/relative dates and other deferred component proofs pass. | Fixed |
| RF-068-08 | major | `tests/e2e/core-household-journey.spec.ts` | Host-local dates, conditional picker paths and incomplete preview/Calendar/checklist/reward assertions fell short. Real UI timezone selection, exact setup/template materialization, read-only preview, Calendar/My Day, receipt deltas and undo/reload are asserted unconditionally. | Fixed |
| RF-068-09 | major | `tests/integration/Service/SchedulePlanAggregateTest.php:420`, `tests/unit/components/ScheduleCalendar.spec.js` | Claimed weekday/midnight/DST proofs were missing and the start key was recomputed. Controlled weekday/store tests, explicit boundary providers and one shared preview/apply key now pass. | Fixed |
| RF-068-10 | major | `tests/integration/Controller/TestModeHttpTest.php:11`, `MinigameControllerIntegrationTest.php` | Annotation/fixture success was not anonymous/disabled-server wire evidence, and retained route negatives were incomplete. Real HTTP denial, every disabled method, enabled dispatch/invalid input, no-write seed profiles and all nine minigame route contracts pass. Static authenticated boost-catalog access is preserved, not silently tightened. | Fixed |
| RF-068-11 | major | WP9 acceptance/docs/archive evidence | Incomplete work was already labelled closed; current API/concepts, parent mapping and clean-install evidence were incomplete. Historical claims are explicitly superseded, all durable slices integrated, actual completion dated 09-14, parent acceptance mapped without claiming its Full review. | Fixed |
| RF-068-12 | minor | `src/components/Tasks/TaskTemplatePicker.vue:201` | The close target covered the level selector; mobile inspection also measured 34px controls. Approved reserved space and 44px sizing pass ordinary desktop/mobile clicks, close behavior and the retained canonical regression. | Fixed |
| RF-068-13 | minor | `composer.lock:7` | Strict validation exposed PHP platform metadata left at `^8.0` after the committed `^8.2` requirement. Lock-only refresh changed platform/content-hash only; no package versions/references changed. Strict validation passes. | Fixed |
| RF-068-14 | minor | `scripts/check-test-noise.js:31` | Shell-based npm launch emitted Node 24 DEP0190. Direct Node/npm CLI execution preserves the real lifecycle; launch/interruption regression and zero-noise gate pass without warning suppression. | Fixed |

No open plan-owned finding, deferred required test or unremediated security
finding remains. No production route, authorization, reward formula, migration,
request/query ceiling or dependency version was changed by the corrective review.
The pre-existing `purchaseBoost` selection and day-bucket fixes were reviewed,
adopted and kept with their regressions.

## Decisions

1. D8, **Adopt Fix Slice (Recommended)**: user adopted the five implementation
   production files and paired regressions into WP9 without changing reward policy.
2. D9, **Implement Fixes Now**: user authorized completing the identified
   runner/checker/frontend/fixture/CI fixes, required tests and durable docs.
   No required tests were rescoped or waived.
3. D10, **Fix Spacing (Recommended)**: user separately approved the discovered
   picker obstruction fix and real desktop/mobile verification, rather than
   forcing a click past a genuine UI defect. Shared ErrorState reuse and an
   explicit non-submit retry button keep D9's recovery within design rules.

All questions were answered before finalization. The parent's final Full review
was neither requested nor performed as part of this Standard B.

## Verification

| Gate | Final result |
|---|---|
| Disposable clean install | Canonical Compose `down -v` then family `up -d --wait`; fresh Nextcloud 31.0.6, app 0.1.1; no DB upgrade/backfill path introduced |
| Backend unit | 920 passing, 2864 assertions |
| Backend integration | 361 passing, 2802 assertions, `--fail-on-skipped`, zero skips; concurrency and unchanged query ceilings included |
| Jest | 100 suites, 1054 tests passing; required negative probes fail invalid evidence rather than being skipped |
| Noise | `npm run test:noise` passes; intentional CLI-negative test output is not a failing test or hidden domain log |
| Style/lint | `npm run lint:fix` within the reviewed write scope; final check-only lint and stylelint pass |
| Build | Production Webpack build passes on the final frontend state |
| Backend tooling | `composer cs:check`, `composer validate --strict --no-check-publish` pass |
| Localization | 940 exact source/catalog/POT/EN/DE keys; negative and byte-identical regeneration tests; real Nextcloud DE/EN E2E |
| Browser guards | `--self-test` plus `browserGate.spec.js`/`databaseFixtures.spec.js` negative cases pass |
| Browser pair | Both complete three-pool runs below pass with workers=1, retries=0, forbid-only; no invalid attempts |
| Desktop/mobile | Normal picker level and close clicks verified at 1280x720 and 390x844, both 44px targets; canonical E2E retains viewport/size regression |
| Documentation | Encoding, open-only backlog/link checks and whitespace checks pass; rechecked for lifecycle/record changes |

Earlier baseline runs and the full run before the last accessibility/shared
component fixes are not counted as the final pair. No implementation changed
between these two runs. Existing read-only UI recovery is not a test retry;
no mutation replay was added to obtain a pass.

### Final Browser Report Fingerprints

Raw reports/traces are local ignored diagnostics, not public artifacts. Each
report was independently checked for `expectedStatus=passed`, exactly one
`passed` result with `retry=0`, and the canonical journey in its owner pool.
CI uploads only sanitized file/title/status summaries.

| Run / project | Start (UTC) | Executions | Report SHA-256 |
|---|---|---:|---|
| `run-xh6Kvg` / chromium | 2026-09-14 18:16:26 | 100 | `86f0dcb083b866dc210fc470047fcfc675c6a2a52ba8a43a2fecf58e7370ba79` |
| `run-xh6Kvg` / serial DB | 2026-09-14 18:21:31 | 114 | `b8688ca55b59b2c98ddebe118e45b2d1b7b68197f8f715d1c030628754e10e88` |
| `run-xh6Kvg` / scheduling owner | 2026-09-14 18:30:25 | 38 | `1d97ba163e495f6e07d7131bef8787fe36a6ffc73084da42cf38084f72549af4` |
| `run-raA3R6` / chromium | 2026-09-14 18:33:57 | 100 | `8282b744dbe8fab38f8ed4040e453add1c01280a2400aea7ef2ea9758e1a59b8` |
| `run-raA3R6` / serial DB | 2026-09-14 18:39:26 | 114 | `ffc2f986fee6444ab9b3ed6395d64d3e765d0275ec77233bed59c098c7967386` |
| `run-raA3R6` / scheduling owner | 2026-09-14 18:49:18 | 38 | `5093876d96d5e941f7507425b3b526d524febc23fb646903089518e7cb2d5189` |

Each run: 243 non-setup scenarios + 9 setup executions = 252; 46 required
files with exact ownership; exactly one canonical journey. Zero skipped,
failed, flaky, retried, expected-failure or empty/missing-attempt evidence.

## Lifecycle And Commit

All WP9 acceptance criteria and review findings are complete. The eligible
child move is `active/ -> done/`; owning items are archived with the corrected
09-14 evidence, resolved stubs removed and ACTIVE_TASKS kept at ten entries.
CR-053-21's remaining named D6 work is complete. TECH-LINT-007 was already
fixed by WP8 (`4b99d9f`); its stale open stub was archived without a source edit.

CS-PLAN-058 remains active with a concrete acceptance-evidence map, awaiting
its separately requested final Full review. RELEASE-002 and its independent
security/hardening/release prerequisites are unchanged; unrelated structural
and dependency work remains in the backlog. No release, deployment, tag,
productive-use declaration, branch change, push or PR is authorized here.

The commit containing this record is the one authorized scoped conventional
review commit on `master`. The allowed set is the reviewed manifest below
plus this record and the eligible plan destination. Index/branch/HEAD/source
drift is rechecked before staging and committing; artifacts, auth state,
private/local archives and unrelated paths are excluded.

## Exact Reviewed Change Manifest

Paths at the final implementation capture, before lifecycle metadata (the
two removed tests retain local ignored archive copies). The active WP9 path
becomes `docs/plans/done/cs-plan-068-wp9-deterministic-coverage-release-closure.md`.
This record is the additional review-owned path. Unchanged required test owners
are enumerated by the plan and frozen scenario inventory, not staged as edits.

```text
.forgejo/workflows/release-gate.yml
README.md
composer.lock
docs/adr/ADR-003-task-scope-system.md
docs/adr/README.md
docs/api/README.md
docs/backlog/ACTIVE_TASKS.md
docs/backlog/archive/2026-09.md
docs/backlog/backlog.md
docs/concepts/gamification-and-rewards.md
docs/concepts/task-model-and-sync.md
docs/concepts/ui-use-cases.md
docs/engineering/architecture.md
docs/engineering/development.md
docs/engineering/testing.md
docs/plans/README.md
docs/plans/active/cs-plan-058-core-workflow-bottom-up-masterplan.md
docs/plans/active/cs-plan-068-wp9-deterministic-coverage-release-closure.md
docs/release/roadmap.md
docs/standards/ai/skills/i18n/SKILL.md
docs/standards/guidelines/i18n.md
docs/standards/guidelines/security.md
docs/standards/guidelines/testing.md
l10n/de.json
l10n/en.json
l10n/templates/chorestrike.pot
package.json
playwright.config.ts
scripts/check-l10n-keys.js
scripts/check-test-noise.js
scripts/run-e2e-gate.js
src/components/Common/ErrorState.vue
src/components/Minigames/SnakeLeaderboard.vue
src/components/Tasks/RoomTaskEditModal.vue
src/components/Tasks/TaskEditForm.vue
src/components/Tasks/TaskTemplatePicker.vue
src/store/minigame.js
src/utils/taskDisplay.js
tests/e2e/3-floor-house.spec.ts
tests/e2e/admin-only-tasks.spec.ts
tests/e2e/auth.setup.ts
tests/e2e/availability-settings-flow.spec.ts
tests/e2e/calendar.spec.ts
tests/e2e/core-functionality.spec.ts
tests/e2e/core-household-journey.spec.ts
tests/e2e/dashboard.spec.ts
tests/e2e/family-core-ux.spec.ts
tests/e2e/fast-core-tests.spec.ts
tests/e2e/fixtures/database-fixtures.ts
tests/e2e/fixtures/house-clock.js
tests/e2e/fixtures/test-fixtures.ts
tests/e2e/fixtures/visibility-fixtures.ts
tests/e2e/flex-window-scheduling.spec.ts
tests/e2e/gui-scratch-flow.spec.ts
tests/e2e/insights-flow.spec.ts
tests/e2e/leaderboard.spec.ts
tests/e2e/minimal-smoke-tests.spec.ts
tests/e2e/overdue-task-claiming.spec.ts
tests/e2e/private-tasks.spec.ts
tests/e2e/release-notifications.spec.ts
tests/e2e/release-task-lifecycle.spec.ts
tests/e2e/release-visibility-roles.spec.ts
tests/e2e/required-scenarios.json
tests/e2e/room-detail.spec.ts
tests/e2e/room-management.spec.ts
tests/e2e/scheduling-apply-flow.spec.ts
tests/e2e/scheduling.spec.ts
tests/e2e/self-availability-member.spec.ts
tests/e2e/settings.spec.ts
tests/e2e/snake-game.spec.ts
tests/e2e/task-checklist-and-features.spec.ts
tests/e2e/task-completion-full-flow.spec.ts
tests/e2e/task-completion.spec.ts
tests/e2e/task-manager.spec.ts
tests/e2e/teamwork-rewards.spec.ts
tests/e2e/title-selection.spec.ts
tests/integration/Controller/GameControllerIntegrationTest.php
tests/integration/Controller/MinigameControllerIntegrationTest.php
tests/integration/Controller/TestModeApiTest.php
tests/integration/Controller/TestModeHttpTest.php
tests/integration/FamilyIntegrationTest.php
tests/integration/Service/SchedulePlanAggregateTest.php
tests/integration/Service/TestDataSeedTest.php
tests/unit/components/ActivityFeed.spec.js
tests/unit/components/BoostShop.spec.js
tests/unit/components/HouseStatsCards.spec.js
tests/unit/components/MemberFairnessTable.spec.js
tests/unit/components/RoomPicker.spec.js
tests/unit/components/RoomTaskEditModal.spec.js
tests/unit/components/ScheduleCalendar.spec.js
tests/unit/components/SnakeGame.spec.js
tests/unit/components/SnakeLeaderboard.spec.js
tests/unit/components/TaskEditForm.spec.js
tests/unit/components/WeekBudgetGrid.spec.js
tests/unit/l10n/l10n-consistency.spec.js
tests/unit/scripts/browserGate.spec.js
tests/unit/scripts/databaseFixtures.spec.js
tests/unit/scripts/localizationGate.spec.js
tests/unit/store/minigame.spec.js
tests/unit/utils/taskDisplay.spec.js
```

---

# Final Full Review — CS-PLAN-058 Core Workflow Bottom-Up Masterplan (2026-09-14, evening)

Tier: **Full** (4 parallel read-only reviewer tracks + main-agent synthesis).
Owning plan: `docs/plans/active/cs-plan-058-core-workflow-bottom-up-masterplan.md`.
Outcome: **PASS-WITH-FIXES (non-visual scope)** — the `active/ → done/` move is
deferred solely to the delegated browser-gate pair on the fixed state (D-FR-04).

## Baseline

- `master` @ `f270e8e370c44e196d093d4bfe62f3e98960d9d0` (tree `7efb993`), 29 ahead
  of origin; index clean, no untracked files; only review-owned docs dirty at
  capture (ACTIVE_TASKS reservation + masterplan §12 preparation, both from the
  WP9 closure session earlier that day).
- Scope: whole core workflow domain — 15 controllers, 31 services, 25 Db files,
  26 migrations, 70 routes (appinfo/routes.php), 12 stores, 9 views, 54
  components, tests (unit/integration/e2e), l10n, ADR-009..012, engineering and
  API docs. Docker test environment exclusively reserved in ACTIVE_TASKS.md.

## Process (masterplan §12 checklist)

- FR-01 baseline + reservation; FR-02 evidence expansion: all 34 named evidence
  anchors verified present, l10n 940/940/940 parity, CR-WF-01..08 archived,
  RELEASE-002 blockers mapped; FR-03 four reviewer tracks ran in parallel and
  all delivered findings (each hit its step limit; the main agent closed the
  remainders: house-reset gate test verification, OCC command gates, error
  formatter audit, MinigameService whitelist/daily-cap, owner-only UI gating,
  store network/service-locator sweep, route-consumer spot checks, i18n
  attribute sweep, mapper bounds, deep-watcher/v-for audit); FR-04 main-agent
  journey tracing across the seams (incl. the timezone seam below).

## Findings (RF-058-01..22) and fixes

See masterplan §13 for the full table. Summary: 0 block, 4 major, 8 minor,
10 nit; 18 fixed in-round with regression tests, 4 nits user-rescoped to the
backlog (CR-PERF-01, CR-SEC-05, CR-SVC-64, FEAT-UX-032). Headline finding
RF-058-01 (major): three due-date writers tagged house-local `Y-m-d` labels at
00:00 UTC/server-TZ while all readers bucket by house-TZ instants — for houses
west of UTC (incl. the night-UTC `journeyClock()` Pacific/Honolulu window)
schedule-apply/due-date/create dates landed one local day early (overdue
instead of due-today). Fixed via `CalendarService::dayStartInstant()/isDayKey()`
with lazy tz resolution; relative/ISO inputs keep instant semantics.

## Decisions (user-ratified 2026-09-14)

- D-FR-01: fix all 4 majors in-round with regression tests (incl. western-tz
  providers).
- D-FR-02: fix the listed minors + auto-fixes; RF-058-14/15/16/17 rescoped to
  the backlog.
- D-FR-03: archive CR-053-19/CR-SVC-15/CR-SVC-44 (fold-in pointers recorded);
  re-section CR-SVC-63 → Core Hardening and FEAT-UX-015 → Deferred Add-ons;
  the Core Workflow Program section closes empty.
- D-FR-04: verdict PASS-WITH-FIXES for the non-visual gates; plan stays in
  `active/` until a separate LLM session delivers two consecutive complete
  retry-free browser-gate passes on the fixed state; a follow-up session then
  moves it to `done/` (all-gates rule preserved).

## Gates (fixed state, non-visual — all green)

PHPUnit unit **941 OK** (+21 new); integration **361 OK, zero skips,
`--fail-on-skipped`**; Jest **1056 OK** (+2 new, incl. full suite via
`test:noise` clean); ESLint/stylelint clean (scoped `--fix`, no unrelated
writes); webpack `build` green; `composer cs:check` 0 findings;
`l10n:check-keys` 941 keys EN/DE/POT exact (new key `pts` with real DE copy);
`docs:encoding` 725 files; `backlog:check` green; `node
scripts/run-e2e-gate.js --self-test` green; `git diff --check` clean.
**Delegated (pending):** `npm run test:e2e:gate` ×2 — browser pair on the fixed
state, executed by a separate LLM session per user instruction; it is the only
outstanding gate and the sole blocker for the plan's `done/` move.

## Changed files (review-owned, committed with the review)

Backend: `lib/Service/CalendarService.php`, `SchedulePlanService.php`,
`RoomTaskService.php`, `TaskCreationService.php`, `HouseService.php`,
`TaskService.php`, `SchedulingService.php`, `RoomService.php`,
`TitleService.php`, `RoomTaskReadService.php`;
`lib/Controller/RoomTaskController.php`, `UserController.php` (rewritten thin);
`lib/Db/CompletedTaskMapper.php`, `TaskMapper.php` (comment rationale).
Frontend: `src/store/roomTasks.js`, `src/views/Leaderboard.vue`,
`src/views/TodayView.vue`; `l10n/en.json`, `l10n/de.json`,
`l10n/templates/chorestrike.pot`.
Tests: `tests/unit/Service/{CalendarServiceTest,HouseServiceTest,RoomServiceTest,RoomTaskServiceTest,SchedulingServiceTest,TaskServiceTest,TaskCreationServiceTest,TitleServiceTest,HouseServiceLoggingTest,RoomTaskServiceLoggingTest}.php`,
`tests/unit/Controller/UserControllerTest.php`,
`tests/unit/Db/Mapper/CompletedTaskMapperTest.php`,
`tests/unit/store/roomTasks.spec.js`,
`tests/integration/Controller/{UserControllerIntegrationTest,RoomControllerIntegrationTest}.php`,
`tests/integration/Service/{TitleServiceIntegrationTest,SchedulingPreferencesIntegrationTest,SchedulePlanAggregateTest,RoomTaskServiceExtendedFeaturesTest}.php`,
`tests/integration/Db/WorkflowQueryBudgetTest.php`,
`tests/e2e/3-floor-house.spec.ts`.
Docs: `docs/api/README.md`, `docs/engineering/architecture.md`,
`docs/plans/active/cs-plan-058-core-workflow-bottom-up-masterplan.md`
(§5 filename fix, §11 outcome, §12 checklist, §13 findings),
`docs/backlog/backlog.md`, `docs/backlog/archive/2026-09.md`,
`docs/backlog/archive/CODE_REVIEW_2026-09-14.md` (this record),
`docs/backlog/ACTIVE_TASKS.md`.
