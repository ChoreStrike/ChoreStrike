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
