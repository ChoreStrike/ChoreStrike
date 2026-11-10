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
