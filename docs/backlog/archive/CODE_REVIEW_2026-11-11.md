# Code Review Record — 2026-11-11

## Standard B - CS-PLAN-090 WP-F feedback (interim)

**Outcome:** `PASS-WITH-FIXES`. Interim review of WP-F (FEAT-UX-067); the plan stays in `active/`.

### Scope and capture

- `/review-chorestrike` without arguments, tier Standard B, no subagents. The main checkout on
  `master` was clean; the only non-empty candidate was the pinned worktree `docs/release-a-plan`,
  whose uncommitted change set is WP-F.
- Base HEAD `3ed38cf6ff41f3b874dfc97d7982f94b302f4dcf` (the WP-A review commit), 2 ahead and
  0 behind `master` (`9872cbf`), pin matching the branch.
- Empty index, 50 modified tracked files, five untracked paths (`Version1034Date20261111000000.php`,
  `src/styles/feedback.css`, `AnimationDefaultStepTest.php`, `PreferenceDefaultsTest.php`,
  `tests/recovery/upgrade-fixture.php`). Scope fingerprint over the staged and unstaged binary
  diffs and the sorted untracked paths with their Git object hashes:
  `8fca7b237af0195bfe93b7c8cb7347ff2083ef3c5461ed3fe101e067726e007d`.
- Owning plan CS-PLAN-090; the other active plan, CS-PLAN-080, owns none of these files.
- Read: the migration step, the preference entity, service and ranking consent path, every
  reader of the preference store's effect switches (celebration, arcade, Settings, effect gates,
  confetti), the completion payload behind the snackbar's coins, the room-task store's mark and
  the two row templates, the new stylesheet, the title toast, the preservation runner's P4 proof
  and its fixture, the ownership rule, every changed test and the gate manifest, and the API,
  concept, guide, deployment, testing and release-test-plan diffs.

### Findings

| ID | Severity | Area | Issue | Resolution |
|---|---|---|---|---|
| RF-090-13 | minor | `effectGates.js`, `useGameEffects.js` | The effect gates read the store's initial `animationEnabled: true` before `/preferences/me` answered: a member who had switched the animation off saw confetti when completing a task while the read was pending or after it failed. Before WP-F an unknown state meant off, and the store documents unread preferences as fail-closed | Fixed (operator, D-090-32): `animationAllowed()` opens the animation only after an authoritative read, used by the celebration gate and the arcade's pulses; Jest cases for the unread state; `calm-core-ux.md` and the gamification skill |

Safe fixes without a finding ID: `README.md` and the gamification skill still said sound and
celebrations are off by default; the user guide implied coins on every completion; the P4
proof's `tests/Integration` link, a workaround for TECH-TEST-012, lacked its `HACK` marker and
removal condition; the P3 proof's archive list still named `css/`, which has been unbuilt and
gitignored since CS-PLAN-069, so P3 failed in a clean worktree (pre-existing, removed from the
list).

Checked without findings: the step is data only, sets one column and the version on every row
with a constant expression and a bound boolean, and logs only a row count. Nextcloud installs a
fresh app with schema steps only, so the step runs only on an update, and `createFor()` gives new
rows the same default; it marks every delivery field because the entity setter skips a value
equal to the property default. Both row-creating paths use it. The snackbar reads the viewer's
own share by account id from the completion's `participants`, which carry `userId` and
`coinMinor`. The row mark is cleared by its timer and by `reset()`. The stylesheet is global,
prefixed, follows reduced motion and has no competing `animation` declaration in the two row
components. Every new string goes through `t()` with real German. The P4 evidence of the
implementation (`c0900011`..`c0900014`) matches the reviewed `lib/` and `appinfo/` byte for byte.

### Questions and decisions

One question, answered by the operator with the recommended option on 2026-11-11: fix RF-090-13
in this round (recorded as D-090-32). D-090-28 to D-090-31 bind with this review. No question is
open.

### Gates

All on the reviewed tree with the fixes; the browser targets served the same bundle as the
build.

- `npm test` (162 suites, 2335 tests), `npm run lint`, stylelint, `l10n:check-keys`,
  `backlog:check`, `docs:encoding`, `agents:check`, `git diff --check`, `composer cs:check`
  (441 files) and `npm run build`: green. Preservation runner `-SelfTest`: 7 PASS.
- Canonical PHPUnit (Nextcloud 35.0.2, PHP 8.4.27, PostgreSQL 18.6, `Version1034` recorded):
  unit 1600 and integration 698, `--fail-on-skipped`.
- Runtime matrix `cs090-wpf-r1` on freshly staged targets: MariaDB 11.8.6, SQLite and the AIO
  row (Nextcloud 34.0.5, PostgreSQL, Redis) each unit 1600 and integration 698,
  `--fail-on-skipped`, all PASS.
- P4, upgrade from `0.8.0-alpha.4` (`-StampVersion 0.8.0-alpha.5`): `e090f001` PostgreSQL,
  `e090f002` MariaDB, `e090f003` SQLite on Nextcloud 35.0.2, `e090f004` the AIO row on
  Nextcloud 34.0.5, all PASS. 26 to 27 migrations, 4 preference rows on (3 switched) with their
  versions raised by one, the 30 other row sets identical (9 pause rows among them), second
  upgrade and postcheck clean.
- P3, whole-instance recovery: `e090f007` PASS (27 migrations, every row and file hash equal,
  the corrupted file and the wrong-version control refused, cleanup clean). `e090f005` failed
  on the stale `css/` entry above; `e090f006` passed its recovery but its wrong-version control
  refused the previous-patch image, built before the PHP 8.4.27 inventory change. The image was
  rebuilt from the inventory and verified; the failed runs stay as private evidence.
- Browser gate, 5 projects, 53 required specs, 361 tests each with none skipped, failed or
  flaky: the primary row (canonical stack, `run-1GWdf0`) and the AIO row (Nextcloud 34.0.5 with
  Redis, `run-b3hlTW`).

### Transition

None: an interim review. WP-B to WP-E and WP-G are open, and so is RF-090-12 (WP-G). FEAT-UX-067
keeps its row-feedback criterion open until WP-B (D-090-30). One scoped review commit; no merge,
push, tag or deployment.
