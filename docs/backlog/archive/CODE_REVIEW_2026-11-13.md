# Code Review Record — 2026-11-13

## Standard A - CS-PLAN-090 scope extension (WP-H to WP-K)

**Outcome:** `PASS-WITH-FIXES`. Plan review limited to the extension; no implementation judged.

### Scope and capture

- Requested tier: Standard A, no subagents, limited to what operator decision D-090-60 added to
  the active `docs/plans/active/cs-plan-090-simplified-core-release-a.md`: D-090-60 to D-090-67,
  WP-H to WP-K, and their tests, manifest, order, risks and acceptance criteria. The plan stays
  in `active/`.
- Base HEAD `97c917be` on branch `docs/release-a-plan`, in its pinned worktree, 6 ahead and 0
  behind `master`. Empty index. The plan file had SHA-256 prefix `c64bbbf3235a148e` at capture.
  The uncommitted WP-H implementation beside it (binary diff hash `1e25be53`) is not part of
  this review. It joins the first interim Standard B of the extension.
- Context inspected: the backlog items FEAT-UX-053, FEAT-UX-056, FEAT-UX-057, TECH-TEST-012,
  TECH-TEST-013 and TECH-DEPS-001; ADR-019; the task entity, its migration, the two task writers,
  the template catalog and the form's six values; the arcade catalog, access service, score lock
  and the grouped count of `WorkShareMapper`; `RewardConfigService` and the gate route; the
  preference stack; `tokens.css`, the files with colour literals, the teleporting dialogs and the
  contrast specs; the task list's filters; `IAppConfig` and `IUserConfig` of the Nextcloud 35
  runtime. No production access and no exchange read.

### Findings

| ID | Severity | Area | Issue | Resolution |
|---|---|---|---|---|
| RF-090-25 | minor | WP-K | A scheme on the app container would miss the dialogs that `Modal.vue` and `SetupWizardEntry.vue` teleport to `body` | Attribute on `body`, overrides scoped to the app content and its teleported roots; browser check with an open dialog |
| RF-090-26 | minor | WP-J | The grouped count serves several wallets in one query; a start point per person would have split the owner's progress read into one query per member | A lower bound per wallet inside the same query |
| RF-090-27 | minor | WP-I | The revision check would compare against `IAppConfig`'s request cache, so two concurrent saves could both pass | Compare and write under the house-row lock against a value read past the cache |
| RF-090-28 | minor | WP-K | Initial state needs `@nextcloud/initial-state`, which is not a dependency | Named as a new dependency from Nextcloud's own libraries |
| RF-090-29 | minor | WP-K | A scheme could lower the contrast of Nextcloud's high-contrast themes | Schemes yield to *Standard* there, with a browser check |
| RF-090-30 | nit | WP-I | The key source of a new category was open; `tasks.category` holds 50 characters | The server generates a short key |
| RF-090-31 | minor | WP-J | The plan claimed that the reset's start point is serialized with completions; a completion locks wallets first and the house row later | The wording now says that a reset never interleaves with a score save and that an overlapping chore may land on either side, never twice |
| RF-090-32 | minor | Tests | New routes and browser scenarios named no registration | `RouteMatrixTest` at 103 routes; each new scenario joins `required-scenarios.json` with a scenario record |
| RF-090-33 | nit | Order | WP-H had no review step | It joins the first interim Standard B of the extension |

All findings are resolved at plan level, and none claims delivered code. The rest checked out
against the code and the runtime. The six keys stay the starting list, so retained tasks keep
their meaning. Nothing in the extension changes a table. Every new route is `@NoAdminRequired`,
with the membership and owner checks in the service. The per-user values go with the account
and with account erasure. No out-of-scope issue arose.

### Questions and decisions

The operator answered six questions on 2026-11-13, each with the recommended option where one
was given. Scope: the three small items and all three larger features (D-090-60). Schemes per
person across devices (D-090-61), accent only (D-090-62). Arcade reset per person for all games
(D-090-63). This limited Standard A before code. D-090-64 to D-090-67 are plan positions that
bind with this review. No question is open.

### Gates

Jest 169 suites / 2374 tests, `npm run lint`, stylelint, `npm run build`, `backlog:check`,
`docs:encoding`, `agents:check`, `git diff --check`. The review is documentation only, so Docker
backend, browser and package gates were not required for it. They stay binding for WP-H to WP-K.

### Transition

None; the plan stays in `active/`. FEAT-UX-053, FEAT-UX-056 and FEAT-UX-057 are `In Progress`
under CS-PLAN-090 WP-I to WP-K, and TECH-TEST-012 and TECH-TEST-013 under WP-H. TECH-DEPS-001
records the in-range slice. One scoped review commit of the plan, this record and the tracking
files. No merge, push, tag or deployment.
