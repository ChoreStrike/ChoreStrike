# Code Review Record — 2026-11-15

## Standard B - CS-PLAN-090 WP-K colour schemes (interim)

**Outcome:** `PASS`. Interim review of WP-K (FEAT-UX-057); the plan stays in `active/`.

### Scope and capture

- `/review-chorestrike` without a scope, started 2026-11-14 in the main checkout, whose `master`
  had nothing to review. The operator chose the uncommitted WP-K change set of the
  `docs/release-a-plan` worktree, with commit. Tier Standard B, no subagents.
- Base HEAD `5c10481fc405ab93a989be1591af0ec12c9e66af` on branch `docs/release-a-plan`, in its
  pinned worktree, 10 ahead and 0 behind `master`, never pushed. Empty index; 62 modified tracked
  files and 13 untracked paths (`ColorSchemeService`, `AppearanceSettings.vue`,
  `useColorScheme`, `schemes.css`, `colorSchemes.js`, their tests, the new browser spec and its
  fixture). Scope fingerprint over the staged and unstaged binary diff and the sorted untracked
  paths with their SHA-256:
  `8ba636907cb84dbe61c78cf79acac56abd1ce70d4096d3286fbf026d561e5947`, unchanged after the gates.
- Owning plan CS-PLAN-090; the other active plan, CS-PLAN-080, owns none of these files. No
  migration and no table change; one new route and one per-user setting.
- Read: the route, `PreferenceController`, `PageController`, `ColorSchemeService`, account
  erasure and the test-data reset; `schemes.css`, `tokens.css` and the literal moves in fourteen
  components, `colorSchemes.js`, `useColorScheme`, the preferences store,
  `AppearanceSettings.vue`, `Settings.vue`, `App.vue` and `confetti.js`; the German catalogue;
  the API, guide, glossary, design, concept, testing and architecture sections; the unit,
  integration, Jest and browser tests, the route matrix, the scenario manifest and the gate
  runner.

### Findings

None in WP-K.

Checked without findings: `PUT /preferences/me/color-scheme` is `@NoAdminRequired`, checks
membership first and sets only the caller's scheme, because the service takes the member the
gate resolved; the route matrix pins the preference surface as self-only. Anything but a listed
key (wrong case, empty, null, a number, a list) is a 400 before any write; *Standard* deletes the
value, and an unknown stored value reads as *Standard*. The page hands only the viewer's own
setting to the app. Account erasure and the test-data reset delete it. `schemes.css` sets exactly
the sixteen primary-derived variables that the theming app of Nextcloud 35 defines (checked
against `apps/theming/lib/Themes` in the canonical container), scoped to the app root and its two
teleported overlays, which are the only teleports in `src/`; the *Standard* swatch takes the
instance colour from `<body>`, outside that scope. Beyond the pairs Jest computes, the muted
element text reaches at least 5.4:1 on every accent; no component paints text with
`--color-primary`, and a scheme's primary against the dark background (about 2.8:1, used as a
border only) stays near Nextcloud's own default (2.93:1). The store drops an overtaken read or
save and restores the previous scheme after a failed save. Every new string goes through `t()`
with German copy.

Note, not routed: the new strings spell "colour", while Nextcloud and most project prose use
"color" (one older catalogue string reads "Sort by color"). No standard names an English
variant; the German working names already go to the second GUI review (D-090-73).

Outside WP-K, from the browser gate (no WP-K code on either path; both passed in the complete
reruns):

- Primary row (`run-flsDoF`), `mobile-webkit`: `mobile-core.spec.ts` "settings stay reachable
  through the phone navigation for a child" timed out waiting for Settings. The trace shows that
  the tap on the *Settings* entry, a router link, opened a second page 0.3 s later; that page
  loaded the app and showed Settings, while the test's page stayed on Today. Nothing in `src/`
  opens a window, and `mobile-chromium` passed the scenario. New backlog item
  [TECH-TEST-014](../backlog.md#p3-tech-test-014--a-tap-in-mobile-webkit-can-open-a-second-page).
- AIO row (`run-O6eNGi`), `chromium`: `task-manager.spec.ts` "User can open add task modal from
  Task Manager" found *Add task* disabled for 14 s. The page's three room-task reads waited
  14.2 s on the server and answered 200 together, while the staged PostgreSQL's checkpoint spent
  14.7 s in fsync for 137 buffers in the same window; no error in the logs, no foreign container.
  Folded into
  [TECH-TRANSPORT-001](../backlog.md#p3-tech-transport-001--attribute-intermittent-browser-api-connection-termination).

### Questions and decisions

One: the scope, because the main checkout had nothing to review. The operator chose the WP-K
change set of the worktree, with commit.

### Gates

On the reviewed tree, which the review did not change:

- `npm test` (178 suites, 2494 tests), `npm run lint`, stylelint, `l10n:check-keys` (1341 keys),
  `backlog:check`, `docs:encoding`, `agents:check`, `git diff --check`, the gate runner's
  self-test and `npm run build`: green.
- `composer cs:check` (456 files) and `composer validate --strict`; PHPUnit unit 1699 /
  integration 715 on the canonical PostgreSQL stack (Nextcloud 35.0.3) and, with
  `--fail-on-skipped`, on MariaDB and SQLite (Nextcloud 35.0.3) and the AIO row (Nextcloud
  35.0.2 with Redis), matrix run `cs090-wpk-r1`.
- Browser gate, 5 projects, 54 required specs: the first runs lost one scenario each, both
  outside WP-K (above): primary row `run-flsDoF`, AIO row `run-O6eNGi`. The complete reruns on
  the same tree passed: primary row `run-SXfzOH`, AIO row `run-x6gdyU`, each 361 tests without
  skipped, failed or flaky results. The staged targets are torn down with their volumes.
- Not run: the package smoke, which builds from a clean committed HEAD and belongs to the
  qualification of WP-G.

### Transition

None: an interim review. WP-G is open, and so are the operator's second GUI review and the
final Full review. FEAT-UX-057 is implemented and stays `In Progress` until the release. One
scoped review commit; no merge, push, tag or deployment.

## Full Review (final) - CS-PLAN-090 Simplified Core Release A candidate

**Outcome:** `PASS-WITH-FIXES`. The final Full review the plan requires before the
qualification; the plan stays in `active/`, because the qualification, the export and the
deployment are open.

### Scope and capture

- `/review-chorestrike-full` on the operator's request of 2026-11-15, after the candidate commit.
  The whole plan: `master..022bfd55` on branch `docs/release-a-plan`, twelve commits, 404 files
  (WP-A to WP-F, WP-H to WP-K, the runtime update RF-090-12 and the candidate of WP-G), plus
  the plan's decisions, manifest, acceptance criteria and durable-doc integration.
- Base HEAD `022bfd559d325a046811c9447f25d67588d7363e`, pinned worktree, 12 ahead and 0 behind
  `master`, never pushed. Clean working tree and empty index at capture; the review changed
  only what is listed below.
- Owning plan CS-PLAN-090; the other active plan, CS-PLAN-080, owns none of these files.
- Four read-only reviewers in parallel (correctness, architecture, security and data flow,
  performance and quality), each told what the interim reviews had already settled; every
  finding re-checked against the code before routing. A static sweep found no `console.*`,
  `v-html`, `TODO`/`FIXME`/`HACK`, `@PublicPage` or focused test in the changed files.

### Findings

All in the plan's §9, fixed in this review unless noted.

| ID | Sev | Track | Area | Status |
|----|-----|-------|------|--------|
| RF-090-41 | major | correctness, architecture | `Rewards.vue` grew from 599 to 610 lines against "No view grows" | Fixed (operator's choice): `Rewards/RewardWalletBanner.vue`, view at 565 |
| RF-090-42 | minor | performance | no test looked at requests after grouping by room or person | Fixed (operator's choice): browser budget groups and opens groups |
| RF-090-43 | minor | performance | Today read `/due-today` for nothing, and a failed refetch showed a refresh banner | Fixed (operator's choice) |
| RF-090-44 | minor | performance | due-class sort worked out dates per comparison; headings four times per row | Fixed (operator's choice): `sortByDueGroup()`, computed headings |
| RF-090-45 | minor | performance | Insights ran ranking and medal reads one after another | Fixed (operator's choice): side by side |
| RF-090-46 | minor | correctness | a second "Other" or "Kitchen" beside a kept built-in category | Fixed (operator's choice): built-in names join the duplicate check |
| RF-090-47 | minor | correctness | three strings still named *My Day* | Fixed: *Today* / *Heute* |
| RF-090-48 | minor | review (main agent) | the pinned private-filter scenario did nothing since WP-B | Fixed: real checks on `#/tasks?visibility=private` |
| RF-090-49 | minor | correctness | deployment guide and changelog said no migration runs with the update that carries step 1034 | Fixed |
| RF-090-50 | minor | performance | skeletons used `--color-background`, which Nextcloud 35 does not define | Fixed: `--color-main-background` |
| RF-090-51 | minor | architecture | stale size inventory, missing rows, component map and store section | Fixed: inventory re-measured in non-blank lines |
| RF-090-52 | nit | architecture, correctness | README step 4, app description, testid standard, `GET /house/health`, ADR-013 pointer, comments, manifest | Fixed |
| RF-090-53 | nit | architecture | orphaned CSS rules and icon imports, a repeated router assertion | Fixed |
| RF-090-54 | nit | performance | uncleared timer and double logging in the arcade settings | Fixed |
| RF-090-55 | nit | architecture | `PauseService::listForViewer()` about 71 lines | Fixed: `viewerEntry()` |
| RF-090-56 | minor | security (note) | the reset route refuses user IDs with a space or an apostrophe, like the older scheduling route | User-rescoped to [BUG-API-002](../backlog.md#p3-bug-api-002--member-routes-refuse-nextcloud-user-ids-with-a-space-or-an-apostrophe) |

Out of scope, to the backlog: placeholders that `translate()` escapes as HTML show entities in
names across the app ([BUG-I18N-002](../backlog.md#p3-bug-i18n-002--names-in-translated-strings-show-html-entities));
pause coverage regroups the whole pause history per occurrence
([CR-SVC-66](../backlog.md#p3-cr-svc-66--pause-coverage-regroups-the-whole-pause-history-per-occurrence)).
Both are older than this plan.

Accepted without change (nits): the card style of Settings exists three times
(`settings.css`, `Settings.vue`, `ArcadeSettings.vue`); RF-090-38 chose local card rules so
that the form sheet keeps its scope rule, and three declarations do not justify a fourth class
yet. Today keeps its own two-option filter beside `SegmentedSwitch`; it looks different, the
GUI reviews accepted it, and swapping it is a design change for Release B to weigh.

Checked without findings (security track): every new route is `@NoAdminRequired`, with
membership in controller and service and `requireHouseOwner()` for categories, thresholds and
resets; a reset target must be a member under the house-row lock; absence deletion follows the
subject-or-owner rule before and under the lock, and the list's `deletable` uses the same
rule; thresholds, category labels, scheme ids and absence dates are validated at the boundary;
`coinMinor` appears only for work the viewer may see (D-090-53); the page hands over only the
viewer's own scheme; account erasure removes both per-user values; step 1034 is data only with
bound parameters; no `v-html`, and logs carry ids only.

### Questions and decisions

Four, answered by the operator on 2026-11-15 with the recommended option each: extract the
wallet banner (RF-090-41); fix all four performance and test findings now (RF-090-42..45); fix
the category names now (RF-090-46); move the user-ID routes to the backlog for both routes
together (RF-090-56).

### Gates

On the final tree:

- `npx jest` (179 suites, 2499 tests), `npm run lint`, stylelint, `l10n:check-keys` (1341 keys),
  `backlog:check`, `docs:encoding`, `agents:check`, `git diff --check`, `npm run build`: green.
- `composer cs:check` (456 files); PHPUnit unit 1703 / integration 716 with
  `--fail-on-skipped` on the canonical PostgreSQL stack (Nextcloud 35.0.3, family seed). The
  same suites ran green on the captured tree before the fixes (unit 1699 / integration 715).
- Focused browser runs on the canonical stack, without retries: `chromium` with
  `workflow-request-budgets`, `private-tasks` and `task-grouping` (14 tests with setup), and
  `chromium-serial-db` with `family-rewards`, `ranking-consent`, `settings` and `arcade-games`
  (39 tests); no failed, skipped or flaky result.
- Not run here: the provider suites, the AIO row, the complete browser gates on both rows and
  the package build and smoke. They are the qualification of WP-G, which follows on the
  committed tree.

### Transition

None: the final Full review passed, and the plan stays in `active/` until the qualification,
the export, the deployment and its verified record. One scoped review commit; no merge, push,
tag or deployment.
