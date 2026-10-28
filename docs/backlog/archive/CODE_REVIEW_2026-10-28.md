# Code Review Record — 2026-10-28

## Interim Standard B - CS-PLAN-088 RF-088-11 AIO v14.4.2 runtime promotion

**Outcome:** `PASS-WITH-FIXES` (interim; the plan stays in `active/`).

### Scope and capture

- Branch `chore/aio-14.4.2-runtime` in its pinned worktree, base HEAD
  `afec524d46c555d416657441521f2c24fbc7fb4d` (equal to `master`).
- Captured working tree: index empty, 15 modified tracked paths, no untracked paths
  (the ignored `private/` proof evidence aside), unstaged binary diff SHA-256 prefix
  `94d90c789d0e8bd8`. Content: the drift-check parser with its Jest fixture, hashes and
  drift cases; `docker/test-runtime.json`; `Dockerfile.test` defaults; three compose
  files; stage and target spec pins; current-runtime statements in README, docker
  README, development, deployment and testing docs and the docker-dev skill.
- Owning plan: [CS-PLAN-088](../../plans/active/cs-plan-088-alpha3-feedback-round.md)
  (RF-088-11). No app code, schema or migration in scope.
- Added in this round: the R088-11-01 fix in `scripts/test-runtime-preservation.ps1`,
  its sentence in `testing.md`, plan evidence and outcome, `ACTIVE_TASKS.md`, this record.

### Findings

| ID | Severity | File | Issue | Resolution |
|---|---|---|---|---|
| R088-11-01 | minor | `scripts/test-runtime-preservation.ps1` | A passing P1/P2 tore its project down with the matrix compose file only. On the AIO row the Redis peer comes from the stage script's stdin overlay, so compose treated it as an orphan, left it and the project network running ("Resource is still in use"), still exited 0, and the proof reported `cleanupSucceeded: true`. Found by running P2 on the AIO row; the script predates this branch. | Fixed: the teardown passes `--remove-orphans`, then `Get-ProjectResidue` queries containers, volumes and networks by project label and fails the proof when any remain; new self-test case; `testing.md` updated. The rerun `cs082-ecb43565` left nothing; the first run's leftovers were removed. |

### Verified non-findings

- The parser builds its patterns from fixed argument names, never from input, matches a
  whole `ARG` line, and counts a pin only while the tarball URL that consumes it is
  present. Removing either pin or either download line fails closed (Jest). Against the
  real v14.4.2 sources the live check reads APCu 5.1.28 and phpredis 6.3.0 and reports
  `current`.
- The new digests are the registry index and linux/amd64 digests that
  `test:runtime:check` compares. `Dockerfile.test` defaults equal the generated primary
  build arguments (Jest pin) and carry the new selection hash.
- PHP is one inventory field, so the primary row moved with the AIO row. All four images
  (current and previous patch per row) were rebuilt; `verify-image` reports
  `frozen-image-identity-match`.
- The AIO `lcobucci/clock` limitation is the same reviewed failure on PHP 8.4.27, which
  AIO v14.4.2 ships itself; no allowlist entry was broadened, no diagnostic suppressed.
- Drift fixtures moved past the new inventory (`34.0.6`, `8.4.28`), so every drift label
  stays exercised; no assertion was weakened.
- Historical records (deployment records, done plans, review archives, the trial record
  in the release test plan, `CHANGELOG.md`) keep their versions deliberately.
- The package builder keeps its separate reproducible-build PHP pin; it is not a runtime row.
- Deployment consequence recorded in the plan: the pre-deployment checklist already
  requires the production AIO to run the inventory's AIO Nextcloud (now 34.0.5).

### Gates

- `test:runtime:check` `current` (upstream 35.0.2, AIO 14.4.2 / NC 34.0.5, no drift);
  `test-runtime.js validate` `valid` / `qualified`.
- Canonical stack recreated (`test:runtime:canonical` match, PHP 8.4.27): PHPUnit unit
  1521 / integration 646 with `--fail-on-skipped`; full browser gate `run-1tN7fk`.
- AIO row `cs082-rf08811-aio` (NC 34.0.5.1 / PHP 8.4.27 / PostgreSQL 18.6 / Redis 8.10.2
  for sessions, cache and locking): unit 1521 / integration 646; full browser gate
  `run-anL2IM`.
- Both gates passed on the first attempt: chromium 103, chromium-serial-db 183,
  chromium-scheduling-owner 39, mobile-chromium 15, mobile-webkit 15; zero skipped,
  failed or flaky.
- P2 on the AIO row: 34.0.4.1 → 34.0.5.1 on populated synthetic facts, 26 migrations
  unchanged, 30 app table snapshots equal, post-update read/mutation pass; rerun after
  the fix with an empty residue check.
- Preservation self-test, Jest 159 suites / 2240 tests, ESLint, `backlog:check`,
  `docs:encoding`, `agents:check`, `git diff --check` green.

### Outcome

**PASS-WITH-FIXES (interim).** One minor finding fixed in this round, none open. The plan
stays active; next are the local merge (operator) and WP-D.

## Pre-candidate Full review - CS-PLAN-088 `0.8.0-alpha.3` release delta

**Outcome:** `PASS-WITH-FIXES` (interim, pre-candidate; the plan stays in `active/`).

### Scope and capture

- Branch `chore/release-0.8.0-alpha.3` in its pinned worktree, base HEAD `dccafeb`
  (equal to `master`, the RF-088-11 merge), after the early freeze (D-088-22).
- Reviewed delta: everything the round changed since the qualified `0.8.0-alpha.2`
  candidate, `fcb65cf..dccafeb` over `lib/`, `appinfo/`, `src/` and `css/` (153 files,
  +4185 / −1755; binary diff SHA-256 prefix `d3a6587c99ddb015`), with the round's 126
  changed test files as context. At capture the working tree held only the WP-D
  candidate preparation (version, changelog, README, screenshots), outside those paths.
- Four parallel read-only reviewers on the captured patches: architecture, security,
  performance, correctness. Owning plan:
  [CS-PLAN-088](../../plans/active/cs-plan-088-alpha3-feedback-round.md).

### Findings

| ID | Severity | Area | Issue | Resolution |
|---|---|---|---|---|
| RF-088-12 | minor | FEAT-UX-044 room rename | Re-saving a room's unchanged name renumbered a legacy duplicate ("Bath" → "Bath 2") and logged it as a change, against "existing duplicates stay". Found by the correctness and performance tracks. | Fixed (D-088-23): an unchanged trimmed name skips the numbering; `RoomServiceTest`. |
| RF-088-13 | minor | FEAT-UX-045 last-done day | An impossible day (`2025-02-29`) passed the pattern check and returned an untranslated internal message; the controller also returned every other `InvalidArgumentException` message verbatim as a 400. | Fixed (D-088-23): strict calendar parse; the curated message is a `ValidationException` (400), anything else a 500 with the safe message; controller and integration tests. |
| RF-088-14 | minor | FEAT-UX-039 flex cap | A repeat value above about 3·10^17 overflowed the period product into a `TypeError` (500) for the caller's own request. | Fixed (D-088-23): the repeat value is clamped at 2 × the sanity bound, where every cap is already the bound, so no result changes; `PHP_INT_MAX` cases. |
| RF-088-15 | minor | FEAT-UX-045 replay | An entry recorded after a house reset never reached "Last cleaned": the replay starts at the reset and the entry's day lies before it. | Fixed (D-088-24, operator): the newest entry per room task recorded after the latest reset counts (one indexed query; the reset-bounded query budget is now 3); unit and integration tests with a real reset; API, ADR-014 and architecture docs. |
| RF-088-16 | minor | structure / performance | Open-window rows derived their selections twice and before the visibility filter; flex-window widening read open occurrences once per room task; a direct room switch refetched every task definition; `getFilteredDailyTasksForUser` far above the method-size guideline; the flex-cap input list duplicated in `TaskService`; small readability issues. | Fixed (D-088-25, behavior-neutral; details in the plan's §9); the `RoomDetail` spec pins the switch. The pre-existing file size of `RoomTaskReadService` (928 lines before the round, 993 now) went to CR-SVC-65 (operator). |

### Accepted without change

- The room-state version bump under the house lock repeats the pattern of
  `CompletionService` and `RoomService`; a shared helper waits for a third variant.
- `RoomTaskLastDoneService` reuses `RoomTaskReadService::timeZoneForHouse()` instead of
  duplicating the house time-zone lookup.
- `TaskService` reads the stored fixed weekdays for the flex-cap comparison in static mode
  whatever the task's mode, on purpose: a changed day list is re-checked rather than missed.
- The security track found nothing exploitable; RF-088-13 and RF-088-14 were its findings.

### Gates

- `test:runtime:check` `current`; production build; Jest 159 suites / 2241 tests, ESLint,
  stylelint and `composer cs:check` (435 files) green.
- Canonical stack recreated (`test:runtime:canonical` match): PHPUnit unit 1531 /
  integration 647 with `--fail-on-skipped`.
- Matrix run `a3c` (SQLite and MySQL on NC 35.0.2, the AIO row on NC 34.0.5 / PostgreSQL
  18.6 / Redis): unit 1531 and integration 647 on each, every suite reported.
- AIO-row browser gate `run-hlouAh` passed on the first attempt: chromium 103,
  chromium-serial-db 183, chromium-scheduling-owner 39, mobile 15 + 15; zero skipped,
  failed or flaky.
- Primary browser gate: the first run `run-8CHJRU` was invalid. During a host slowdown
  (the same specs ran 3–8× slower than on the AIO row, e.g. 16 s for a 2 s dashboard
  test) one request ended in a client-side `socket hang up` the server never logged, one
  seed call in `fetch failed`, and one room view rendered its correct tasks just after
  the 5 s assertion window. No assertion failed on content. The complete rerun
  `run-ECyo8u` on the unchanged candidate passed on the first attempt with the same
  counts as the AIO row; zero skipped, failed or flaky.
- Tested runtime and test inputs: 22 non-documentation paths against `dccafeb`, binary
  diff SHA-256 prefix `0aeebff1e13d3a85`, no untracked paths; only documentation changed
  after the qualification started.

### Outcome

**PASS-WITH-FIXES (interim, pre-candidate).** Five minor findings, all fixed in this round
with regression tests; the file-size follow-up went to CR-SVC-65 by operator decision; none
open. The plan stays active for the package build and smoke, the export, the deployment
and the final completion review.
