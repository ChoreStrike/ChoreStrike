# Code Review Record — 2026-11-14

## Standard B - CS-PLAN-090 WP-J arcade owner controls (interim)

**Outcome:** `PASS-WITH-FIXES`. Interim review of WP-J (FEAT-UX-056); the plan stays in
`active/`.

### Scope and capture

- `/review-chorestrike` with the scope "the uncommitted WP-J change set of this worktree,
  including PHPUnit on the AIO row and the browser gate on both rows", tier Standard B, no
  subagents.
- Base HEAD `13097788907092c146fe9895aa843755da8d3990` on branch `docs/release-a-plan`, in its
  pinned worktree, 9 ahead and 0 behind `master`, never pushed. Empty index; 40 modified tracked
  files and 12 untracked paths (the settings controller, the start-point store, three Vue
  components, the `IUserConfig` and `ValueType` test doubles and their test files). Scope
  fingerprint over the staged and unstaged binary diff and the sorted untracked paths with their
  SHA-256, before the review's fix:
  `2735ff2ce78dd917792ca31c6424289105dcf2464e0df68cd541984fdd651bbf`.
- Owning plan CS-PLAN-090; the other active plan, CS-PLAN-080, owns none of these files. No
  migration and no table change; three new routes, two new settings (an app-config array and a
  lazy per-user key).
- Read: the ADR-019 amendment; `ArcadeAccessService`, `ArcadeStartPointStore`,
  `ArcadeSettingsController`, the routes, `RewardConfigService`, the grouped count of
  `WorkShareMapper`, `CompletedTaskMapper::maxId()`, account erasure and the test-data reset;
  the minigame store, the threshold form, the reset list, the category wrapper, the card copy,
  the glossary, the shared settings stylesheet and the German catalogue; the API, guide and
  concept sections; the unit, integration, Jest and browser tests, the route matrix and the
  scenario manifest.

### Findings

| ID | Severity | Area | Issue | Resolution |
|---|---|---|---|---|
| RF-090-38 | minor | `ArcadeSettings.vue` | The category root carried `cs-settings-form`, so the shared rules of `settings.css` reached the gate switch, the threshold form and the reset list, which bring their own styles; rules of equal weight then win by load order | Fixed: the root drops the class and styles its cards itself, as `Settings.vue` does; the Jest case pins it |
| RF-090-39 | minor | `tests/e2e/settings.spec.ts` (from `b545c97d`, 2026-10-10) | "House drafts wait for the winning read on a direct Settings load" never waited for its overtaken first house read; on the AIO row (`run-qrPuOs`) that read was still in `route.fetch()` when the test ended, and the closing page failed the test after every check had passed. All three reads answered 200 in the access log. "Cold structure hydration survives room reconciliation" had the same gap for its second read | Fixed: both wait for their route handlers (`page.unrouteAll({ behavior: 'wait' })`) before the page closes; a temporary hanging first fetch failed the old cleanup and passed the new one |

RF-090-39 lies outside WP-J but is a test defect with a known cause, safely fixable, so it was
fixed here rather than routed to TECH-TRANSPORT-001: the requests reached the server.

Checked without findings: the three routes are `@NoAdminRequired` and check membership and the
owner role in the controller and the service; the reset also checks the target's membership on
both layers. The threshold payload must name every game with a whole number from 0 to 500; a
list, a float, a string or an unknown game is refused with a 400. The reset takes its start
point under the house-row lock, so it never interleaves with a score save, and reads the start
point past the config cache. The grouped count builds one `IN` group per distinct lower bound
with named parameters only, and the progress list stays one count for all members, sorted by
name. Highscores, wallets and the work ledger are untouched. Account erasure and the test-data
reset delete the new settings. Every new string goes through `t()` with German copy, and a
threshold of 1 reads in the singular. The store drops overtaken catalog and progress reads.

### Questions and decisions

None. RF-090-38 and RF-090-39 fell under the fix-now rule.

### Gates

On the final tree:

- `npm test` (174 suites, 2419 tests), `npm run lint`, stylelint, `l10n:check-keys` (1328 keys),
  `backlog:check`, `docs:encoding`, `agents:check`, `git diff --check` and `npm run build`:
  green.
- `composer cs:check`; PHPUnit unit 1682 / integration 709 on the canonical PostgreSQL stack
  and, with `--fail-on-skipped`, on MariaDB and SQLite (Nextcloud 35.0.3) and the AIO row
  (Nextcloud 35.0.2 with Redis), matrix run `cs090-wpj-r1`; the grouped count changed its query,
  so every provider ran it. RF-090-38 and RF-090-39 changed only the client and a browser test,
  so these stand.
- Browser gate, 5 projects, 52 required specs: the primary row passed (`run-yXLnZu`). The AIO
  row lost one scenario (`run-qrPuOs`), the test defect fixed as RF-090-39; the complete rerun
  on the final tree passed (`run-BRaDiN`). Each pass: 357 tests without skipped, failed or
  flaky results. RF-090-39 changed only `settings.spec.ts`, which then passed in full on the
  primary row (20 tests). The staged targets, the AIO row's Redis companion included, are torn
  down with their volumes.
- The canonical integration run of the implementation had failed twice before this review:
  `MinigameControllerIntegrationTest` read a threshold an earlier browser run had left on the
  shared stack (its `setUp` now clears the thresholds), and `PauseAgreementConcurrencyTest` got
  no answer from its helper process once. Neither failed again in the canonical rerun, three
  isolated runs or the three matrix targets; the cause of the second is not established.
- Not run: the package smoke, which builds from a clean committed HEAD and belongs to the
  qualification of WP-G.

### Transition

None: an interim review. WP-K and WP-G are open, and so are the operator's second GUI review
and the final Full review. FEAT-UX-056 is implemented and stays `In Progress` until the
release. One scoped review commit; no merge, push, tag or deployment.
