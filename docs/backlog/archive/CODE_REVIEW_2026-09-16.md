# Code Review: CS-PLAN-069 (Final Standard B)

**Date:** 2026-09-16
**Reviewer:** Kilo, main-agent Standard B (no subagents)
**Outcome:** PASS-WITH-FIXES
**Owning plan:** [CS-PLAN-069](https://github.com/ChoreStrike/ChoreStrike/blob/c03e940d071251ed62cedbe9ef4a7a2640d0f294/docs/plans/done/cs-plan-069-repo-root-slimming-and-orphan-cleanup.md)
**Base:** `master` at `d76595636163dd8cd615287f991f4eba549f5695`
**Authorization:** explicit Standard B request (user accepted the review
suggestion after implementation); no `--no-commit` flag.

## Scope And Capture

Review covered the complete working-tree changeset (staged + unstaged; no
untracked files), which equals the plan's full §5 manifest plus the documented
§10 extensions — tooling, CI, docs and tests only, zero product code. The plan
itself is the owning plan (exactly one plausible candidate in `docs/plans/active/`).

- 45 changed paths, staged binary diff SHA-256
  `81891d0ff004c2ba8a9ba7cd8a29ea985269cb3aef270a44c1191a9c6eedca64`,
  unstaged binary diff SHA-256
  `de94ba6c3796a0e05debdfc73d006689f8e8de97f50106fe883c1a3b468e2466`,
  combined scope fingerprint (sorted status lines + per-file content hashes)
  `8f4a5a6de3ef00b9d30b25441c41ecf6e9c602853e23b1921ca43cfd854c22c1`
  at capture time, before review-owned fixes and finalization edits below.
- Index carried the implementation's `git rm`/`git mv` staging (orphans,
  `css/chorestrike-main.css`, `phpunit.xml`, compose + community-doc moves);
  it stayed unchanged through review.

## Findings And Resolutions

| Sev | Location | Issue | Status |
|-----|----------|-------|--------|
| minor | `docs/release/roadmap.md:462` | Dead link `[CONTRIBUTING.md](../../CONTRIBUTING.md)` — target moved to `docs/` | Fixed in review (`../CONTRIBUTING.md`) |
| minor | `docs/standards/guidelines/security.md:286` | Dead link `[SECURITY.md](../../../SECURITY.md)` — target moved to `docs/` | Fixed in review (`../../SECURITY.md`) |
| nit | `.forgejo/workflows/test.yml` upload step | `playwright-results` artifact upload always warns: the list reporter never produces the HTML report dir | Fixed in review (`if-no-files-found: ignore`, mirrors `full-matrix.yml` pattern) |

No security, authorization, architecture, or product-code findings (the
changeset touches no `lib/`, `src/`, or `templates/` code). The implementation
round itself had already surfaced and fixed two defects (Jest `.kilo/`
worktree pollution; `PLAYWRIGHT_HTML_OUTPUT_DIR` for CLI `--reporter` runs) —
both recorded in the plan's §10.

## Review Verification

- **Compose move (highest risk):** relative-path re-basing verified against
  `docker/Dockerfile.test` COPY expectations, then proven end to end through
  `docker compose -f docker/compose.test.yml up -d --build` → PHPUnit unit
  (941 tests, 2760 assertions, OK) → PHPUnit integration (361 tests, 1577
  assertions, OK, `--fail-on-skipped`) → teardown `down -v`.
- **Playwright redirect:** live E2E smoke (setup + chromium,
  `fast-core-tests.spec.ts`, 9 passed) proved auth storage states and test
  output land under `build/`; root `test-results/`, `playwright-report/`,
  `.playwright/` absent; HTML reporter output folder verified via config run
  and pinned via env for CI CLI-flag runs.
- **Frontend/static gates:** Jest 100 suites / 1056 tests green (after
  excluding the gitignored `.kilo/worktrees/` leftover that had polluted
  discovery with 6 failing duplicate suites); `npm run lint`,
  `php-cs-fixer fix --dry-run --diff` (0/295), `npm run backlog:check`,
  `npm run docs:encoding` (718 files), `npm run l10n:check-keys`,
  `run-e2e-gate.js --self-test`, `git diff --check` — all green; rerun green
  after the three review fixes.
- **Residue check:** `git grep` for the old compose path and root artifact
  dirs returns only the plan's own old→new move description and the archived
  backlog item's checklist line — no live-path hits.
- **Root census:** exactly 20 tracked root files, matching acceptance
  criterion 1.
- **Knowledge integration:** `docs/engineering/development.md` (compose
  commands + `/js/`+`/css/` build prerequisite), `docs/engineering/architecture.md`
  (root tree + docs/ community files), `docs/README.md` (Community & Meta),
  docker-dev skill (tree + commands), project-rules §2 — updated.
- **Backlog:** TECH-STRUCT-001 moved to `docs/backlog/archive/2026-09.md`
  (CS-PLAN-069 closure section); `ACTIVE_TASKS.md` updated.

## Decisions

None required — the plan's four user decisions were pre-approved at activation
(phpunit.xml delete, community docs → `docs/` root, `download-emojis.js`
archive, historical records verbatim, tool dotfile caches stay at root per
RF-069-01), and all three review findings were safely auto-fixable.

## Outcome

PASS-WITH-FIXES. All acceptance criteria verified (final-review criterion
satisfied by this record), RF-069-01..03 remain resolved, plan moved
`active/ → done/`, one scoped review commit on `master`.

---

## Family-First Concept Review

**Date:** 2026-09-16
**Scope:** user-requested concept review, clarification draft and documentation
alignment; no runtime implementation.
**Method:** four read-only domain tracks (rhythm/correctness, reward contracts,
family roles/data visibility, calm UX/notification load), consolidated by the main
agent. This is not a full security audit, a final code review or a Standard A
activation review of the newly created plan.
**Outcome:** `BLOCKED` for concept approval/implementation readiness. The requested
analysis and draft are delivered, but product decisions and runtime findings are
open. No automatic commit or lifecycle move.
**Owning draft:** [CS-PLAN-070](../../plans/done/cs-plan-070-family-rhythm-and-reward-clarification.md)
(`CONCEPT-001`); no matching active plan existed at capture.

### Source Capture

- Branch/base: `master`, `1946166618d591eba54bd42d1d338030389e994e`.
- Index was empty; the only initial worktree changes were unrelated deletions of
  `docs/plans/{new,active,onhold}/.gitkeep`. They remain untouched and excluded.
- Reviewed source had no staged/unstaged changes or untracked source files at
  capture. Both empty scoped binary diffs have SHA-256
  `e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855`.
- Git tree fingerprints at that base: concepts
  `7dd4078383387a4b60137dce10a8ba8a99595aaa`; ADRs
  `73d47f6f6a3de4b89fe963baa0ddeb93ff8d3db1`; backend
  `0038e363efd991382f6d7021ce314e0ae4a6fa3b`; frontend
  `41c9e31e979c4fd0894812b34ae3f33d503f0b16`; tests
  `cc2077a9b2ad20aa411d5c8a22f91a98d48e8aba`.
- Documentation edits are listed in CS-PLAN-070 section 5. The fingerprints
  identify the source baseline, not the later documentation patch.

### Bounded Evidence Manifest

The following are the central source paths examined; the tree hashes do not
imply an exhaustive review of every file in those directories.

| Track | Primary evidence |
|---|---|
| Rhythm/planning | `lib/Db/RoomTask.php`, `lib/Db/HouseUser.php`, `lib/Service/SchedulingService.php`, `lib/Service/SchedulePlanService.php`, `lib/Service/StreakFreezeService.php`, `lib/Service/StreakAtRiskService.php`; `tests/unit/Db/Entity/RoomTaskTest.php`, `tests/unit/Service/SchedulePlanServiceTest.php`, `tests/unit/Service/StreakFreezeServiceTest.php`, `tests/integration/Service/StreakFreezeIdempotencyTest.php` |
| Reward/history | `lib/Db/Task.php`, `lib/Service/GameLogicService.php`, `lib/Service/CompletionService.php`, `lib/Service/CompletionEffectService.php`, `lib/Service/MedalService.php`, `lib/Service/HouseService.php`, `lib/Service/RewardConfigService.php`, `lib/Service/MinigameService.php`; `tests/unit/Service/HouseServiceTest.php`, `tests/unit/Service/RewardConfigServiceTest.php`, `tests/unit/Service/MinigameServiceTest.php`, `tests/integration/Service/CompletionUndoStateEqualityTest.php` |
| Family roles | `lib/Service/AuthorizationService.php`, `lib/Service/HouseService.php`, `lib/Service/RoomTaskReadService.php`, `src/views/TodayView.vue`, `src/components/Setup/SetupWizard.vue`, `src/components/Members/MemberList.vue`, `src/store/user.js`; `tests/integration/RoleGuardApiTest.php`, `tests/integration/Controller/PrivateTaskAuthorizationTest.php`, `tests/unit/components/MemberList.spec.js`, `tests/unit/store/user.spec.js` |
| Calm UX | `lib/Service/NotificationService.php`, `lib/Service/TaskDueReminderService.php`, `lib/BackgroundJob/DailyNotificationJob.php`, `lib/Notification/ChoreStrikeNotifier.php`, `src/views/Dashboard.vue`, `src/components/Dashboard/ArcadeSection.vue`, `src/utils/sound.js`, `src/utils/confetti.js`; `README.md`, current concept docs and the historical ADR-004/design baseline |

### Consolidated Findings

The draft's section 9 is the live checklist; this record is the evidence snapshot.
Severity is relative to the requested family-rhythm product, not a claim that
every missing optional feature is a release-blocking code defect.

| Severity | Baseline evidence | Finding | Disposition |
|---|---|---|---|
| major | `HouseUser.php:194-212`; `StreakFreezeService.php:84-113` | Available-day activity is not reliable fulfillment of planned chores. | RF-070-01/05; D3/D4; retained freeze correctness stays in CR-053-11. |
| major | `RoomTask.php:342-393,447-469` | Fixed weekday boundary errors; recurrence can choose unavailable dates; flex does not define on-time grace. | RF-070-02/04; D1/D4. |
| major | `SchedulePlanService.php:549-582` | Override can bypass legal dates; shared placement accepts the whole set after only one member fits. | RF-070-03; D1/D6. |
| major | `CompletionEffectService.php:121-131` | Snapshot undo can overwrite independent later room/contribution/wallet changes. | RF-070-08; D2; safety prerequisite for redeemable rewards. |
| major | `CompletionService.php:230-259`; `GameLogicService.php:115,261` | Early bonus reads advanced due date; stored tier configuration is not payout authority. | RF-070-07; no silent formula or architecture change. |
| major | `HouseService.php:466-482`; `MedalService.php:124-128` | Weekly goal counts distinct tasks, medals reward raw activity/personal combos rather than the agreed rhythm. | RF-070-06/09; D5-D7. |
| major | `TaskDueReminderService.php:198`; `StreakAtRiskService.php:78-113` | Individual task notifications and loss-framed streak messages, without app preferences/budget/quiet hours. | RF-070-10; existing FEAT-UX-024/025. |
| major | `Dashboard.vue:45-58`; `src/store/user.js:53-55`; `RoomTaskReadService.php:418-451` | No coherent optional calm/ranking mode; child limits do not bound all overdue/optional work. | RF-070-11; D6/D10. |
| major | `AuthorizationService.php:59-61`; `SetupWizard.vue:683`; `CompletionService.php:396-405` | Existing NC accounts/roles are not local child profiles or guardian approval; task completion and redemption approval must be separate. | RF-070-12; existing FEAT-UX-018/023. |
| major | `MinigameService.php:149-164,307-355` | Mixed arcade/chores currency, boost-only spending, no family redemption contract; non-Snake score saves unsupported. | RF-070-13; existing FEAT-UX-026/019 and D7/D8/D11. |
| minor | Current concept/README/skill statements | Stale sync, energy, role guards, reward/goal/config/undo, digest and arcade descriptions. | RF-070-14: corrected as documentation, not runtime fixes. |

### Validation and Limits

- `npm test -- --runInBand`: 100 suites / 1056 tests passed. Duplicate manual-mock
  warnings from `.kilo/worktrees/` remain; out-of-scope tooling follow-up
  TECH-JEST-001 records the evidence and deferral reason. No warnings suppressed.
- `npm run lint`: passed.
- `docker compose -f docker/compose.test.yml up -d --wait --wait-timeout 300`:
  healthy; PHPUnit unit `--fail-on-skipped`: 941 tests / 2760 assertions;
  integration `--fail-on-skipped`: 361 tests / 1577 assertions, both passed.
  The review-started test environment was shut down afterwards.
- Read-only PHP entity reproduction (no file edits): static Tue/Sat task due
  2026-09-19 completed 2026-09-17 yields next due 2026-09-19; completed
  2026-09-18 yields next due 2026-09-22. Code inspection verifies advancement
  ignores the original occurrence when choosing those next weekdays.
- `npm run backlog:check`, `npm run docs:encoding`, `git diff --check`: passed.
  Both unchanged JavaScript gates were also run with their Git inventory
  supplemented in memory by the untracked draft path: 23 link-scoped files and
  720 encoding-scoped paths, passed. No scanner rules were bypassed or changed.
  `git diff --no-index --check -- NUL docs/plans/done/cs-plan-070-family-rhythm-and-reward-clarification.md`
  passed for the untracked plan without staging it.
- No runtime files changed. No new behavior-regression suites, browser pass or
  family usability trial was executed; these remain requirements of the approved
  follow-up, not satisfied by this baseline run.

### Decisions and Handoff

The user established family-first planning, free app access, custom-rhythm
motivation and low-pressure behavior. Detailed D1-D11 remain deliberately open
because the requested deliverable is a plan of what still needs clarification.
Recognition/shared goals with an optional family reward catalog are recommendations,
not consent to implement or commit them. Product approval and the later Standard A
review are the next gates; the plan stays in `new/` and no commit is made.
Existing add-on owners are linked rather than duplicated; runtime findings stay
in the owning draft. The earlier CS-PLAN-069 review record above is unchanged.

### Supplemental Reward Finding

The final reward-track deliverable added one finding not included in its earlier
interim evidence. Main-agent source verification confirms that
`TitleService::getCurrentTitle/getUnlockedTitles` recomputes qualification from
live values (`lib/Service/TitleService.php:57-74,198-210`) and the Coins signal is
the current wallet (`lib/Titles/TitleCatalog.php:145-149`). Ordinary spending can
therefore downgrade/remove Cozy Home recognition at the next computation; the
unlock cache only deduplicates celebration events.

Tracked as **RF-070-15, major**, owned by CS-PLAN-070 D2/D7. The concept now
describes current behavior accurately and recommends separating earned
recognition from spendable balance, with actual completion corrections handled
separately. The plan adds the required earn/spend/reload and undo/reset regression
cases. This is documentation and static verification only, not a behavior fix or
new runtime test result; the overall `BLOCKED` readiness outcome is unchanged.
The unchanged backlog/encoding gates, including the untracked draft inventory,
and `git diff --check` passed again after this supplement; runtime diff is empty.
