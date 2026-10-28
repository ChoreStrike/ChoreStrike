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
