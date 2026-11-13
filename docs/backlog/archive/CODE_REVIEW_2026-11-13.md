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

## Standard B - CS-PLAN-090 WP-H and WP-I release hardening and task categories (interim)

**Outcome:** `PASS-WITH-FIXES`. One interim review of WP-H (TECH-TEST-013, TECH-TEST-012, the
in-range slice of TECH-DEPS-001) and WP-I (FEAT-UX-053), as the operator chose; the plan stays in
`active/`.

### Scope and capture

- `/review-chorestrike` with the scope "the uncommitted WP-H and WP-I change set of this
  worktree", tier Standard B, no subagents.
- Base HEAD `87d6254932a243da1797113a021a57430344a7f9` (the Standard A commit of the extension) on
  branch `docs/release-a-plan`, in its pinned worktree, 7 ahead and 0 behind `master`, never
  pushed. Empty index; 56 modified tracked files and 9 untracked paths (the category service,
  controller, settings component, client helper and their five test files). Scope fingerprint
  over the unstaged binary diff and the sorted untracked paths with their SHA-256, before the
  review's fixes: `34130e1fd7b60f1c8f5e3dcc2a023971bcb1f8318d4d936f240b7741e70c9611`.
- Owning plan CS-PLAN-090; the other active plan, CS-PLAN-080, owns none of these files. No
  migration, no table change; one new route.
- Read: the Rewards view's wallet gate and its Jest and browser cases; the dev autoload of
  `composer.json`, the renamed integration namespace and the removed symlink HACK; the JUnit
  check of `run-tests.js` and its use in both workflows; the Dependabot group, the dependency
  diff and the lint exemptions; the category service, controller, route, house payload, task
  validation, creation default, template mapping and test-data reset; the client helper, house
  store, task form, filter bar and query rules, the Settings category, the glossary, the German
  catalogue; the API, concept and guide sections; the unit, integration, Jest and browser tests
  and the scenario manifest.

### Findings

| ID | Severity | Area | Issue | Resolution |
|---|---|---|---|---|
| RF-090-34 | minor | `TaskEditForm.vue` | Until the house read landed, the form read a stored custom key as *Other*, and a save rewrote the task's category | Fixed: the list decides only once the house carries it, the stored key stays selectable until then; Jest case |
| RF-090-35 | minor | `TaskCategoryService.php` | An oversized list was refused only after a walk with quadratic key generation | Fixed: more than 20 items are refused before the walk; unit case |
| RF-090-36 | nit | `TaskCategorySettings.vue` | The row buttons said "Move up" or "Delete category" without naming the row | Fixed: each names its category, with German copy and a Jest assertion |
| RF-090-37 | major | `store/scheduling.js` (WP-D) | Today's older preferences read could land after the planning settings' read and reset the energy or days form, losing an unsaved edit | Fixed: read sequence and read in flight as in `fetchHouse`; a save or reset invalidates older reads; three Jest cases, red against the old store |

RF-090-37 lies outside WP-H and WP-I but inside the plan (WP-D); the AIO browser gate of this
review found it, and it was safely fixable, so it was fixed here.

Checked without findings: the category writer is `@NoAdminRequired`, checks membership and
ownership in the controller and the service, and refuses a non-integer revision or a non-list
body at the boundary. The revision is compared and written under the house-row lock against a
value read past the app-config cache (RF-090-27). New keys come from the server (RF-090-30).
*Other* stays last and keeps its name. A deleted category leaves the task rows unchanged and
can no longer be written. Built-in names stay translated unless renamed. The route matrix pins
99 routes. The filter accepts only well-formed keys from the link. Every new string goes
through `t()` with German copy. TECH-TEST-012 holds on a case-sensitive copy of the tree in the
container.

### Questions and decisions

The operator chose the recommended option, "fix both now", for RF-090-34 and RF-090-35
(2026-11-13). RF-090-36 and RF-090-37 were fixed under the fix-now rule. No question is open.

### Gates

On the final tree:

- `npm test` (171 suites, 2395 tests), `npm run lint`, stylelint, `l10n:check-keys` (1308 keys),
  `backlog:check`, `docs:encoding`, `agents:check`, `git diff --check` and `npm run build`:
  green.
- `composer cs:check` (0 of 446 files); PHPUnit unit 1637 / integration 700 on the canonical
  PostgreSQL stack and,
  with `--fail-on-skipped`, on the AIO row (`cs082-cs090rv-aio`, Nextcloud 35.0.2 with Redis,
  matrix run `cs090-rv-r1`). RF-090-37 changed only the client, so these stand.
- Browser gate, 5 projects, 52 required specs: the first pass before RF-090-37 passed on the
  primary row (`run-yV6aya`) and failed two scenarios on the AIO row (`run-cyYpmo`): the
  application race fixed as RF-090-37, and one `socket hang up` of a test's own API GET that
  never reached the server's access log, folded into TECH-TRANSPORT-001. After the fix the AIO
  row passed (`run-8xxQBQ`). On the primary row, `run-mvRorq` lost `arcade-games.spec.ts` "Sock
  Match saves a round of found pairs to its own board" the same way: the test's own GET after the
  score POST ended in `socket hang up` and is absent from the access log, whose 413 requests of
  that window all answered 2xx or 3xx. Folded in as well; the complete rerun `run-5vA2t2` passed.
  Each pass: 356 tests without skipped, failed or flaky results. The AIO target is torn down
  with its volumes.
- Not run: the package smoke, which builds from a clean committed HEAD and belongs to the
  qualification of WP-G; the MySQL and SQLite suites, because the category list lives in typed
  app config and the only new query path is the existing house-row lock.

### Transition

None: an interim review. WP-J, WP-K and WP-G are open, and so are the operator's second GUI
review and the final Full review. TECH-TEST-012 and TECH-TEST-013 are done and archived;
TECH-DEPS-001 keeps its major rows; FEAT-UX-053 is implemented and stays `In Progress` until
the release. One scoped review commit; no merge, push, tag or deployment.
