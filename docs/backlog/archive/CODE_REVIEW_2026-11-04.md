# Code Review Record — 2026-11-04

## Standard A - CS-PLAN-089 alpha4 trial defects and everyday fixes

**Outcome:** `PASS-WITH-FIXES`. Documentation-only plan review; no implementation.

### Scope and capture

- Requested tier: Standard A, no subagents. Sole regular non-symlink candidate:
  `docs/plans/new/cs-plan-089-alpha4-trial-defects-and-everyday-fixes.md`; destination absent.
- Base HEAD `8b5248ab9727c1117d50182d6840efa1f73f0d25` on branch
  `docs/alpha3-closure-alpha4-plan`, in its pinned worktree.
- Empty index. Unstaged drafts `docs/backlog/backlog.md` and `docs/plans/README.md`, binary diff
  SHA-256 prefix `543a3cd27606d769`. One untracked path, the plan, SHA-256
  `6e65157c0cb093db35b69782a04a7b67557613fd7524fc1103194dcb40ce3042`, mode 644.
- Context inspected: the owning backlog items; active CS-PLAN-080 and CS-PLAN-088; the task,
  completion, occurrence and logging sources; every test file the plan names; the Nextcloud 35.0.2
  core CSS and layout template from the test image; the `NcContent` CSS; release and retention
  rules. No production access; the private exchange was read for the requested reports only.

### Findings

| ID | Severity | Area | Issue | Resolution |
|---|---|---|---|---|
| RF-089-01 | major | BUG-UX-011, D-089-08 | "Overdue" was undefined for a fixed cadence with an older miss next to a current window | The check looks at every open occurrence; both cases get a regression test; the reason text fits both |
| RF-089-02 | major | BUG-UX-011 | The draft called every refusal correct and left unstated that the dialog cannot move a later occurrence while an older miss stands | Recorded as a known limit until FEAT-UX-050; the refusal says so |
| RF-089-03 | minor | BUG-UX-009 | The mechanism missed `NcContent` and required the mount element to fill the container | Corrected with the computed frame x=8..1272, y=44..792 at 1280×800; stylesheet off the manifest; the wrapper's background and z-index named in the measuring step |
| RF-089-04 | minor | BUG-UX-011 | `TaskList.vue` and the message shared with `AgreementService` were missing | Added to scope, tests and manifest |
| RF-089-05 | minor | FEAT-UX-047 | My Day does not load floors | One `fetchFloorsWithRooms()` per view, never per row |
| RF-089-06 | minor | Tests, manifest | The `#content` contrast selectors, `TaskCreationServiceTest`, the Quick clean scenario name and several docs were missing | Added |

All findings are resolved at plan level; none claims a delivered fix. Architecture, security and
retention rules stay binding: the round adds no endpoint and no migration, and conflict messages
already pass the safe-message filter. The register is sanitized at role level; no out-of-scope issue
arose.

### Questions and decisions

D-089-00..06 from the operator. The operator accepted D-089-07..09 and the recommended review on
2026-11-04. RF-089-01 sharpens D-089-08. No question is open.

### Gates

Jest 159 suites / 2241 tests, `npm run lint`, `backlog:check`, `docs:encoding`, `agents:check`,
`git diff --check`. Documentation only, so Docker backend, browser, build and package gates were
not required and did not run; they stay binding for the implementation.

### Transition

`new/` to `active/`; 11 owning backlog items `In Progress`; RELEASE-002 assigns the plan its alpha.4
slice. One scoped review commit; no merge, push, tag or deployment.
