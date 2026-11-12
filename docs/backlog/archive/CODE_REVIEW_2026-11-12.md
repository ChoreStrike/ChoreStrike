# Code Review Record — 2026-11-12

## Standard B - CS-PLAN-090 WP-C to WP-E tasks, navigation and Settings, Insights (interim)

**Outcome:** `PASS-WITH-FIXES`. One interim review of WP-C (FEAT-UX-063), WP-D (FEAT-UX-064,
FEAT-UX-054) and WP-E (FEAT-UX-066), as the operator chose; the plan stays in `active/`.

### Scope and capture

- `/review-chorestrike` without arguments, tier Standard B, no subagents. The main checkout on
  `master` was clean; the only non-empty candidate was the pinned worktree `docs/release-a-plan`,
  whose uncommitted change set is WP-C to WP-E.
- Base HEAD `34a318913c5e16c758f34a38928ccdc9bda2f23f` (the WP-B review commit), 4 ahead and
  2 behind `master` (`1ef2975b`, two agent-tooling commits whose only overlap is
  `ACTIVE_TASKS.md`), pin matching the branch, never pushed.
- Index: three staged deletions (`Leaderboard.vue`, `RoomDetail.vue`, `RoomDetail.spec.js`) and
  two staged renames (`room-detail.spec.ts` to `task-grouping.spec.ts`, `Leaderboard.spec.js` to
  `RankingPanel.spec.js`); 96 modified tracked files; 29 untracked paths (the new task, Settings
  and Insights components, `SegmentedSwitch.vue`, `settings.css`, `taskFilters.js`,
  `taskGrouping.js`, three browser fixtures and eleven Jest specs). Scope fingerprint over the
  staged and unstaged binary diffs and the sorted untracked paths with their SHA-256:
  `bc40c8f38b783abcec15be3fd8efb02feda544b3d8d13e44f17f381ed90c93d1`.
- Owning plan CS-PLAN-090; the other active plan, CS-PLAN-080, owns none of these files. One PHP
  file changed (`ActivityService.php`, one serialized field); no `appinfo/`, migration or Docker
  file.
- Read: the task list (view, grouping and filter rules, grouped list, create dialog, pager, the
  *Date* entry of the expanded row) against the removed room page; the navigation and the
  router; Settings and each category component against the old page; the planning settings, the
  weekly plan's page and the calendar controls; Insights with the key figures, the fairness
  bars, the ranking panel against the old Leaderboard, the activity list and the correction
  review; the activity service and its unit and integration tests; the German catalogue; the
  scenario manifest against `HEAD` (327 to 329), the gate manifest, the browser fixtures, the
  package owner smoke and the screenshot action; and the concept, guide, API, architecture,
  testing and release-test-plan diffs.

### Findings

| ID | Severity | Area | Issue | Resolution |
|---|---|---|---|---|
| RF-090-22 | minor | `StructureSettings.vue` | A failed read of the floors left an endless spinner without error or retry; the old page and the sibling categories have one | Fixed: an error state with a retry in place, with a Jest case |
| RF-090-23 | minor | `DangerZoneSettings.vue` | When only the read after a successful reset failed, the category toasted "Failed to reset house" after the success toast | Fixed: the reads run after the reset's own failure path; Jest cases for both paths |
| RF-090-24 | minor | `InsightsView.vue` | `/leaderboard` redirected to `#ranking`, but hash routing cannot follow the anchor and the router has no scroll behaviour: an old bookmark landed at the top of Insights | Fixed: the view brings the ranking into view once rendered; Jest cases and a viewport check in the browser scenario |

Safe fixes without a finding ID: the size table of `architecture.md` (`InsightsView.vue`,
`TodayView.vue`, `TaskEditForm.vue` and `StructureSettings.vue` had stale counts), a comment in
`MedalBadge.vue` that still named the Leaderboard, and section 11 of `release-test-plan.md`, which
still opened Scheduling from the navigation, showed *Paused* in the capacity grid (*Absent* since
D-090-26) and described child requests as pending (acknowledged at once since ADR-020).

Checked without findings: the grouped list reads the house once, renders a group's rows only
when it opens and keeps one open group; the room filter narrows the rooms, a room deep link with
an unknown or malformed id opens nothing; a grouped list starts with everyone's tasks unless the
link names a scope. *Date* is offered only inside the managers' part of the expanded row and on
open work, and the server keeps its own role checks. The create dialog sends one aggregate
request and keeps the input on failure. Settings mounts a category only when it opens; a member
never gets the household area, also not by a link; the own requests stay reachable with either
catalog gate closed. A failed save of the own days or energy is reported at its form. The
activity list withholds the coins with the rest of a redacted completion, sums the shares of a
split, pays an undone line nothing and reads day and time in the household's zone; the server
adds `coinMinor` inside the existing redaction. The ranking panel has its own loading and error
state. Every new string goes through `t()` with real German. The scenario record holds: 327 to
329 pinned scenarios, each drop with its successor or reason in the plan, no minimum count
lowered.

### Questions and decisions

The operator chose the recommended option on all three questions (2026-11-12): fix RF-090-22
and RF-090-23 now; bring the ranking into view (RF-090-24); rebase the branch onto `master` after
this review commit, keeping both sides' entries in `ACTIVE_TASKS.md`. D-090-41 to D-090-56 bind
with this review. No question is open.

### Gates

On the final tree:

- `npm test` (169 suites, 2366 tests), `npm run lint`, stylelint, `l10n:check-keys` (1287 keys),
  `backlog:check`, `docs:encoding`, `agents:check`, `git diff --check`, the gate runner's
  self-test and `npm run build`: green.
- `composer cs:check` (0 of 441 files); PHPUnit unit 1600 / integration 698 on the canonical
  PostgreSQL stack and, with `--fail-on-skipped`, on the AIO row (`cs082-cs090wpcde-aio`,
  Nextcloud 34.0.5 with Redis).
- Browser gate, 5 projects, 52 required specs, 355 tests each with none skipped, failed or
  flaky: the primary row (canonical stack, `run-nZTwVa`) and the AIO row (`run-heRaa0`).
  RF-090-20 is closed. The first AIO run, `run-blNRHT`, failed one scenario:
  `family-rewards.spec.ts` "mobile targets, keyboard operation and real EN/DE copy work" tapped
  *Request* in the frame in which the wallet banner appeared above the catalog and moved the
  button; the trace shows the tap performed and no confirmation, the server log no error. The
  view and that step are outside this scope; routed to backlog TECH-TEST-013, then the complete
  gate was rerun.
- Not run: the package smoke, which builds from a clean committed HEAD and belongs to the
  qualification of WP-G, where the changed owner smoke runs; the MySQL and SQLite suites, because
  the one PHP change adds a serialized field without a query.

### Transition

None: an interim review. WP-G is open with RF-090-12, and so are the operator's GUI review and
the final Full review. One scoped review commit, then the rebase onto `master` the operator chose;
no merge, push, tag or deployment.
