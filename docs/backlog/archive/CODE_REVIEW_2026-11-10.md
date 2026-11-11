# Code Review Record — 2026-11-10

## Standard A - CS-PLAN-090 simplified core, Release A

**Outcome:** `PASS-WITH-FIXES`. Documentation-only plan review; no implementation.

### Scope and capture

- Requested tier: Standard A, no subagents. Sole regular non-symlink candidate:
  `docs/plans/new/cs-plan-090-simplified-core-release-a.md`; destination absent.
- Base HEAD `9872cbf9af1bcfc677290a93499df69b6719d50d` on branch `docs/release-a-plan`, in its
  pinned worktree, 0 ahead and 0 behind `master`.
- Empty index. Unstaged drafts in seven files (`docs/README.md`, `ACTIVE_TASKS.md`, `backlog.md`,
  `simplified-core.md`, `docs/plans/README.md`, `release-strategy.md`, `roadmap.md`), binary diff
  SHA-256 prefix `7672362ef4cccd86`. One untracked path, the plan, SHA-256
  `40d9b46a894b3e98edcea09aadf04abf54c4f4ca61a78e0f0bcb33e2c4fcb296`, a regular file.
- Context inspected: the owning backlog items and RELEASE-002; the active CS-PLAN-080 and its
  open real-device checklist; the pause service, projection, mapper and entity; the preference
  service, entity and the two paths that create preference rows; the activity service and work
  shares; routes, router, navigation, the task list's paging and the Settings sections; every
  PHPUnit, Jest and browser file the plan names and the 44 browser files that reference a moved
  surface; the deployment, testing and retention rules. No production access and no exchange
  read.

### Findings

| ID | Severity | Area | Issue | Resolution |
|---|---|---|---|---|
| RF-090-01 | major | WP-A, D-090-07 | Retained `cancelled` rows already mean something else (pending requests a departure cancelled in place, Q-073-03); the new governing rule would reread them, unstated and untested | Operator decision D-090-21 (one rule for all); projection, integration and upgrade tests, risk row, criterion |
| RF-090-02 | minor | WP-A | Deleting a pending request or an ended absence was undefined; deleting an ended one would hide the record of an excuse that stays | Operator decision D-090-22 (not yet ended; pending is withdrawn; ended answers 409); tests, criterion |
| RF-090-03 | major | WP-F, tests | "Populated upgrade" named no harness; the preservation runner proves same-build recreation and Nextcloud updates, not an app update that runs a new step | Real app-upgrade proof from `0.8.0-alpha.4` on three providers in the preservation runner, pause rows in the populated state, PHPUnit test of the step; manifest and `testing.md` |
| RF-090-04 | minor | WP-F, criteria | Pinning the 27th step after the deployment and the guide's *expected data change* paragraph were not in the criteria | Added |
| RF-090-05 | minor | CS-PLAN-080 | Its open real-device checklist (D1, D6, D8) and `mobile-core.spec.ts` name screens this release removes | Risk row: rows and scenarios move with their surfaces here; run, record and closure stay with CS-PLAN-080 |
| RF-090-06 | minor | Tests | Several affected browser specs were not named | Shared inventory names them and routes each to the package that removes its page |
| RF-090-07 | nit | D-090-10, WP-A | D-089-09 also covered the cleanliness route; the digest for a deleted household absence was unstated | Corrected |

All findings are resolved at plan level; none claims a delivered fix. The rest of the plan
checked out against the code: the view sizes, the route pin (97, so 98 with the delete route),
the optional reason code, the in-place departure cancellation, the client-side paging, the two
row-creation paths and the column default of the preference flags, the frozen `coinMinor` on
work shares, and the anchors the plan links. Architecture, security and retention rules stay
binding: the new route is `@NoAdminRequired` with membership and ownership checks in the
service, and the one migration step changes data only. No out-of-scope issue arose.

### Questions and decisions

D-090-01..05 and D-090-16..19 from the operator; D-090-06..15 and D-090-20 are plan positions
that bind with this review. The two review questions were answered with the recommended option on
2026-11-10: D-090-21 (every cancelled revision is read alike, departure cancellations included)
and D-090-22 (only absences that have not ended can be deleted). No question is open.

### Gates

Jest 162 suites / 2302 tests, `npm run lint`, `backlog:check`, `docs:encoding`, `agents:check`,
`git diff --check`. Documentation only, so Docker backend, browser, build and package gates were
not required and did not run; they stay binding for the implementation.

### Transition

`new/` to `active/`; FEAT-UX-062..067 and FEAT-UX-054 `In Progress` under CS-PLAN-090; RELEASE-002
assigns the plan its alpha.5 slice; CONCEPT-002 names the active plan. One scoped review commit;
no merge, push, tag or deployment.

## Standard B - CS-PLAN-090 WP-A absence (interim)

**Outcome:** `PASS-WITH-FIXES`. Interim review of WP-A (FEAT-UX-065); the plan stays in `active/`.

### Scope and capture

- `/review-chorestrike` without arguments, tier Standard B, no subagents. The main checkout on
  `master` was clean; the only non-empty candidate was the pinned worktree `docs/release-a-plan`,
  whose uncommitted change set is WP-A.
- Base HEAD `c34f6e3349a8200427da7b15447e79ebaa39dcf4` (the Standard A activation), 1 ahead and
  0 behind `master` (`9872cbf`), pin matching the branch.
- Two staged deletions (`PauseSettings.vue` and its spec), 57 modified tracked files, five
  untracked paths (ADR-020, `AbsenceView.vue` and its spec, `AbsenceOwnerPanel.vue`,
  `AbsenceRangeForm.vue`). Scope fingerprint over the staged and unstaged binary diffs and the
  sorted untracked paths with their SHA-256:
  `4debce0ecf331e27545697e7523749a80471d9d5906038d905bbeb4f2d851e9c`.
- Owning plan CS-PLAN-090; the other active plan, CS-PLAN-080, owns none of these files.
- Read: every changed PHP file and every caller of the projection (suspension effects, capacity,
  the planner fingerprint, digest relevance and content), the mapper's revision, decision and
  departure paths, controller and route, store, API client, router and navigation, the three
  Absence components, Settings, help and planner wording, the l10n diff, every changed test, the
  three browser specs and the gate manifest, ADR-020, the API reference and the concept, guide
  and engineering diffs.

### Findings

| ID | Severity | Area | Issue | Resolution |
|---|---|---|---|---|
| RF-090-08 | minor | `PauseService`, `AbsenceView.vue` | A child's shortening declined before the update (`acknowledged` r1, `rejected` r2) leaves r1 in force, but the list returned r2: the view hid the absence from everyone and deletion answered 409, against D-090-22. The view also offered deletion on a pending shortening of an ended absence, which the server refuses (D-090-25) | Fixed (operator, D-090-27): the list and the delete command use the revision a group stands for; each entry carries the server's `deletable` for its viewer, which the view uses. Unit, all-provider integration and Jest cases; ADR-020 and the API reference |
| RF-090-09 | nit | `AbsenceView.vue` | Third copy of the day-key label (`TodayView.vue`, `SchedulePlanner.vue`) | Fixed (operator's choice): `formatDayKey()` in `src/utils/dateKeys.js` with its spec, used by all three |
| RF-090-10 | nit | `src/store/pauses.js` | The `pendingRequests` getter had no reader left | Removed with its spec cases |
| RF-090-11 | minor | `layout-clearance.spec.ts` | The browser gate failed on desktop and phone: the reachability probe relied on a member's Settings ending with the short pause section; the notification preferences are taller than the frame. `#/absence` was missing from the routed-view checks | Fixed: the probe aligns the section's end with the scroller (a clipped frame, BUG-UX-009, still fails it); the Absence view joins the heading, gutter and sideways-scroll checks |

Out of scope: in the first browser run, `mobile-core` on `mobile-webkit` did not see Today within
5 s of a Dashboard tile tap, although the failure screenshot shows Today rendered. No WP-A code is
on that path, `mobile-chromium` passed the same scenario and the next complete gate passed. Folded
into TECH-TRANSPORT-001 as unattributed.

Checked without findings: the route is `@NoAdminRequired`; membership is checked in the
controller and the service, subject-or-owner both before and under the house lock; a foreign
house answers 404, a stale revision and an ended absence 409. `DELETE` reads its fields through
`IRequest::getParams()`, from the query or the body (D-090-23). Every coverage reader goes through
`excusedWalletsOn()`, which re-checks the acknowledgment after the governing selection, so a
governing cancelled revision covers nothing in suspension effects, capacity, planner and digest
content; digest relevance already accepted `cancelled`, and the fingerprint covers the state.
Audit, digest recipients and receipt replay match ADR-020. Every new string goes through `t()`
with real German; *Absent* (Abwesenheit) stays apart from *Away* (Abwesend), D-090-26.
`PauseService.php` grows to 914 lines, a named accepted exception whose count `architecture.md`
now carries.

### Questions and decisions

Two questions, both answered by the operator with the recommended option on 2026-11-10: fix
RF-090-08 in this round (recorded as D-090-27) and extract the day label now (RF-090-09).
D-090-23 to D-090-26 bind with this review. No question is open.

### Gates

On the final tree: Jest 162 suites / 2319 tests, `npm run lint`, stylelint, `l10n:check-keys`,
`backlog:check`, `docs:encoding`, `agents:check`, `git diff --check`, `composer cs:check` (0 of
437 files), `npm run build`. PHPUnit on the canonical stack (NC 35.0.2, PHP 8.4.27, PostgreSQL
18.6): unit 1590, integration 691 (1585 / 690 before the fixes). Matrix run `cs090-wpa-r1` with
`--fail-on-skipped` on staged targets: NC 35.0.2 with MySQL/MariaDB 11.8.6 and with SQLite, and
the AIO row (NC 34.0.5, PostgreSQL, Redis), each unit 1590 and integration 691. Browser gate on
the primary row: `run-YXOhYK` failed (RF-090-11 and the folded webkit timing); after the fix
`run-G53HP8` passed with 361 tests in five projects and no skipped, failed or flaky result.
Browser gate on the AIO row: `run-BjeJTR` passed with the same 361 tests and no skipped, failed or
flaky result. Reports and traces stay under the gitignored `build/e2e-gate/`.

### Transition

None: an interim review. WP-B to WP-G are open. FEAT-UX-065 has every criterion checked and stays
in `backlog.md` until the plan's final review. One scoped review commit; no merge, push, tag or
deployment.
