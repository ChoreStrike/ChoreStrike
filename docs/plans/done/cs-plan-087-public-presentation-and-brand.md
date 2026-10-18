# CS-PLAN-087: Public Presentation — Brand Icons, Reproducible Screenshots and User Guide

> **Status:** Done — delivered 2026-10-18; final Standard B `PASS-WITH-FIXES` 2026-10-18 (interim Standard B `PASS-WITH-FIXES`, Standard A `PASS-WITH-FIXES` 2026-10-17); drafted 2026-10-17
> **Effort:** M
> **Priority:** P3
> **Depends on:** none (`master` `37bcd5c`). WP-A changes the app package, so the next deployment candidate needs its own requalification. The operator's external repository-review notes are still to be supplied; they are triaged on arrival (D-087-06). WP-C's arcade text is feature-level only so it does not depend on CS-PLAN-086 (D-087-07).
> **Related backlog items:** FEAT-UX-037, CR-NAV-01, CR-PWA-01 (folded in 2026-10-18, RF-087-07), TECH-DOCS-005, TECH-DOCS-007, TECH-DOCS-008, TECH-DOCS-009
> **Branch:** `feat/public-presentation` (pinned worktree `~/worktrees/chorestrike-public-presentation`)

## 1. Problem Summary

The GitHub landing page and organization are the project's first impression,
and the directly visible material lags behind the app:

- **README drift:** *Getting Started* still says a task completes "with one
  tap"; since `0.8.0-alpha.1` *Done* needs a 600 ms press-and-hold.
- **Hand-made screenshots:** the four README images (`docs/screenshots/`) were
  captured ad hoc; no tooling is checked in, so every UI change silently
  outdates them. They already show two defects: the pre-brand app icon in the
  header and five navigation entries without an icon (CR-NAV-01).
- **App icon:** `img/app.svg` is the old colored house, broom and shield.
  Nextcloud renders the header icon at 20 px with
  `filter: var(--background-image-invert-if-bright)` (server `stable34`,
  `core/src/components/AppMenu.vue`) and so expects a white monochrome glyph;
  a colored icon is inverted on bright headers. The notifications popover
  applies `--background-invert-if-dark` (notifications app
  `NotificationItem.vue`) and expects a dark glyph (`app-dark.svg` convention).
- **Hard-coded asset paths:** the notifier sets its icon to
  `getAbsoluteURL('apps/chorestrike/img/app.svg')` and the PWA manifest points
  to `/apps/chorestrike/img/app.svg`. Physical assets live under
  `/custom_apps` in the AIO and canonical installs
  ([asset contract](../../engineering/deployment.md#2-build-the-frontend)), so
  both URLs presumably 404 there (to be confirmed in WP-A).
- **No user documentation:** the README's five *Getting Started* steps are the
  only end-user text; the documentation index lists developer documents only.
- **Organization page:** `ChoreStrike/.github` with `profile/README.md` does not
  exist, so GitHub shows its default overview. The operator replaced the
  organization avatar with the brand mark on 2026-10-17.

## 2. Strategy

Introduce a two-tier brand system — the full mark for large surfaces and a
mascot symbol for small ones — and deliver the icons through Nextcloud's
conventions and URL helpers. Then make the screenshots reproducible by script,
recapture them with the new icons, refresh the README and add a short user
guide built from the same scenes. Finally give the organization a profile.
Drift guards (version sync test, release checklist step) keep the visible
material current afterwards.

### Decisions (D-087-01..05 confirmed by the operator at Standard A, 2026-10-17)

- **D-087-01 — two icons** (operator proposal 2026-10-17): the full "Night Ops"
  mark stays the avatar/README/social asset (≥ 96 px). The new **mascot symbol**
  is the broom head with face, headband and fluttering tails; the handle is cut
  by the top frame edge at the mark's 6° tilt so the shape still reads as a
  broom. Variants: a color mascot (`docs/brand/`, PWA icons) and a monochrome
  glyph (`img/app.svg` white, `img/app-dark.svg` dark) with eyes and smile as
  negative space; cheeks, bristle strokes and sparkles are dropped below 64 px.
- **D-087-02 — visual approval gate:** the operator approves a preview sheet
  (512/192/64/20 px; white glyph on bright and dark headers, dark glyph on light
  and dark popovers) before any icon enters `img/`.
- **D-087-03 — isolated capture:** screenshots are captured on an isolated
  staged row (`scripts/test-runtime-stage.js`, own project and port, `family`
  seed), never on the shared canonical stack or a real household; the output is
  committed under `docs/screenshots/` and is not part of any gate. The capture
  accepts only a loopback URL (WP-B).
- **D-087-04 — English documentation:** the user guide and profile are English
  (project rules §1); screenshots use the English UI.
- **D-087-05 — outward-facing step:** creating the public `ChoreStrike/.github`
  repository and its content need an explicit operator go at WP-D time;
  profile pinning and the social-preview upload are web-UI steps for the
  operator.
- **D-087-06 — external review notes (operator, 2026-10-17):** the plan is
  activated without the notes. On arrival they are checked independently;
  findings inside this plan's scope go to §9, the rest to `backlog.md`
  (project rules §4).
- **D-087-07 — arcade in the guide (operator, 2026-10-17):** the user guide
  describes the arcade at feature level only (optional, opt-in, scores only, no
  effect on planning): no game list, unlock rules or arcade screenshot, so
  CS-PLAN-086 cannot outdate it.
- **D-087-02 approved (operator, 2026-10-17):** the mascot preview sheet
  (color mascot 512/192/64/20 px, maskable variant, white glyph on bright and
  dark headers, dark glyph on light and dark popovers) approved as shown, PWA
  `any` icons with a transparent background.
- **D-087-08 — My Day phone layout (operator, 2026-10-18):** the defect the
  capture surfaced (RF-087-08) is fixed in this plan, not routed to the
  backlog, so the guide's phone screenshots show the real layout.
- **D-087-05 go and integration (operator, 2026-10-18):** `master`
  fast-forwarded to the interim review commit `ca7f98e` and pushed (it also
  carried the unpushed `c888992`, `8747f4d` and `37bcd5c`); then the public
  `ChoreStrike/.github` repository was created.
- **D-087-09 — dialog stacking (operator, 2026-10-18):** the navigation toggle
  drawn over the setup wizard (RF-087-09) is fixed in this plan for the wizard
  and the shared `Modal.vue`.

## 3. Backend / Frontend Changes

### WP-A — Brand icons in the app (FEAT-UX-037, CR-NAV-01)

- **Brand source:** `docs/brand/chorestrike-mascot.svg` (hand-built vector
  derived from the mark's broom paths, no fonts or external references) and
  its 512 px export; preview sheet for D-087-02.
- **Nextcloud icons:** `img/app.svg` (white `#fff`, single fill, transparent)
  and new `img/app-dark.svg` (dark/default fill, same geometry), following the
  convention of Nextcloud's own apps (server `stable34` `apps/files/img`, looked
  up by `AppManager::getAppIcon()`). `info.xml` keeps `app.svg`.
- **PWA:** color mascot PNGs in `img/` (192 and 512 `any`, 512 `maskable` on
  the navy background inside the 80 % safe zone); manifest `icons` use paths
  relative to the manifest URL, which `PageController` already resolves through
  `imagePath()`. The PNGs are exported with the headless-render procedure of
  [design.md §4](../../standards/guidelines/design.md#4-brand-assets) (no new
  tooling); a Jest guard pins their pixel sizes.
- **Backend:** `ChoreStrikeNotifier::prepare()` sets the icon via
  `getAbsoluteURL(imagePath(APP_ID, 'app-dark.svg'))` (the `IURLGenerator` test
  stub already declares `imagePath`).
- **Frontend:** `src/App.vue` renders every `NcAppNavigationItem` icon through
  the `#icon` slot with the app's `Icon` component instead of legacy
  `icon-*` classes (all eleven entries, labels/ARIA unchanged, glyphs follow
  `currentColor`). The plan's first step fixes the entry → glyph mapping with
  distinct glyphs (today *Scheduling* and *Calendar* both map to a calendar):
  existing registry names cover My Day (`Clock`), Dashboard (`Home`), Tasks
  (`CheckCircle`), Leaderboard (`Trophy`), Scheduling/Calendar (`Calendar` plus
  a second calendar-style glyph) and Arcade (`Gamepad2`); Insights, Rewards,
  My Requests and Settings may need new Fluent glyphs in `src/utils/icons.js`
  (`@fluentui/svg-icons` is already bundled and attributed in
  `docs/THIRD_PARTY.md`). `getIcon()` returns `undefined` for an unknown name,
  so the guard asserts `hasIcon()`, not just a non-empty attribute.
- **Contracts:** no API or payload change; the package file set grows by the
  new `img/` files (`docker/package-build.json` already ships `img/`). No new
  user-visible string (icons are decorative; labels are unchanged), so no
  `l10n/` change.

### WP-B — Reproducible screenshots (TECH-DOCS-007)

- `npm run docs:screenshots` is a thin wrapper: stage an isolated row
  (`scripts/test-runtime-stage.js`; `docker/compose.test-matrix.yml` already
  defaults to the `family` seed and bind-mounts the checkout), run the capture
  with Playwright, remove only its own stage resources.
- **Reuse, no second login path:** the capture is a Playwright project of its
  own (`playwright.docs.config.ts`, `testDir` `tests/docs-screenshots/`) that
  reuses the `setup` project and `tests/e2e/fixtures/auth-contexts` for the
  seeded roles. It lives outside `tests/e2e/` so the canonical gate's
  discovery (`scripts/run-e2e-gate.js`) and its required-spec manifest never
  see it.
- **Privacy guard:** the capture refuses any base URL that is not loopback
  (`127.0.0.1`, `localhost`, `::1`) and first checks the seeded demo household,
  so a real instance can never be photographed.
- **Build prerequisite:** the staged row bind-mounts the checkout, so the
  script fails early unless `js/` (production build) and `vendor/` exist and
  `js/` is not older than `src/`; the README documents `npm run build` first.
- Scene list (route, role, ready selector, viewport, output name) at 1280×800
  (plus 390 px phone scenes for the guide), English locale, light theme,
  reduced motion, one file; README and user guide reference only its outputs.
- **Drift guards:** a Jest check that the README status badge and screenshot
  caption name the `appinfo/info.xml` version, and a release checklist item in
  `deployment.md` (*Versions & Metadata*: screenshots recaptured, README and
  user guide reviewed for changed behavior).

### WP-C — README and user guide (TECH-DOCS-005, TECH-DOCS-008)

- Recapture the four README views with the new icons; fix the hold-to-confirm
  wording; split *Documentation* into "Using ChoreStrike" and "Developing
  ChoreStrike".
- `docs/user-guide.md`: what ChoreStrike is; roles (owner, member, child);
  first start (wizard, invitations); daily use (My Day, hold to complete, undo,
  checklists); planning the week (preview, adjust, apply); optional features
  (Coins and rewards, opt-in ranking, daily digest; the arcade at feature level
  only per D-087-07); privacy (data stays in your Nextcloud); troubleshooting
  (error codes in toasts, where to report). Linked from the README and
  `docs/README.md`.

### WP-D — GitHub organization profile (TECH-DOCS-009)

- After the D-087-05 go: public `ChoreStrike/.github` with `profile/README.md`
  (one-line pitch, one screenshot, status, links to repository, user guide,
  security policy and contact), verified in a logged-out browser. The agent
  prepares the content locally and creates the repository only after the go;
  pinning the repository on the profile and the optional 1280×640
  social-preview image from the mark are web-UI steps for the operator.

## 4. Tests

- **PHPUnit unit:** the notifier icon is the absolute URL of
  `imagePath('chorestrike', 'app-dark.svg')` (fails against the hard-coded path).
- **Jest:**
  - navigation guard: every `NcAppNavigationItem` in `App.vue` renders an icon
    through the `#icon` slot with a name for which `hasIcon()` is true; no
    legacy `icon=` class prop remains; the entry → glyph mapping is unique per
    entry;
  - `pwa-bootstrap.spec.js`: manifest icon paths are relative, the files exist
    and declare matching sizes/purposes; PNG pixel sizes read from the file
    header equal the declared sizes;
  - icon guard: `app.svg` is white-filled (`#fff`) and `app-dark.svg` dark or
    default-filled, both square, single-fill, without gradients, raster images
    or external references;
  - docs drift guard: README badge and caption version equal `info.xml`; every
    image the README and user guide reference exists;
  - capture guards: the scene list has unique outputs and seeded roles only; a
    non-loopback base URL is refused (negative control).
- **E2E (both runtime rows):** the header app icon, `app-dark.svg`, the manifest
  and its icons load with HTTP 200 and the right MIME under `/custom_apps`;
  each navigation entry shows a visible icon. Prefer assertions inside the
  existing `minimal-smoke-tests.spec.ts` scenarios; a new `test()` there (or a
  new spec) must update `minTests`/`scenarios` in the `scripts/run-e2e-gate.js`
  manifest and the inventory in `docs/engineering/testing.md` in the same
  change. Full browser gate before the plan closes (package change).
- **Package:** `test:package:build` file set contains the new `img/` files.
- **Manual (recorded):** D-087-02 approval; header, app settings, notification
  popover and PWA install icon in light and dark on NC 34 AIO and NC 35.

## 5. File Manifest

```
new:      docs/brand/chorestrike-mascot.svg, docs/brand/chorestrike-mascot-512.png
new:      img/app-dark.svg, img/mascot-192.png, img/mascot-512.png, img/mascot-maskable-512.png
new:      scripts/capture-screenshots.js (wrapper), playwright.docs.config.ts, tests/docs-screenshots/ (scene list + capture), tests/unit/scripts/captureScreenshots.spec.js
new:      tests/unit/docsDrift.spec.js, tests/unit/navigationIcons.spec.js
new:      docs/user-guide.md, docs/screenshots/<guide scenes>.png
modified: img/app.svg, img/manifest.webmanifest, src/App.vue, src/utils/icons.js (missing glyphs only)
modified: lib/Notification/ChoreStrikeNotifier.php, tests/unit/Notification/ChoreStrikeNotifierTest.php
modified: tests/unit/pwa-bootstrap.spec.js, tests/e2e/minimal-smoke-tests.spec.ts, package.json
modified (only if a test() is added): scripts/run-e2e-gate.js, docs/engineering/testing.md
modified: docs/screenshots/{my-day,dashboard,weekly-planner,reward-catalog}.png
modified: README.md, docs/README.md, CHANGELOG.md
modified: docs/standards/guidelines/design.md (§4), docs/engineering/development.md, docs/engineering/deployment.md
modified: docs/backlog/backlog.md, docs/backlog/ACTIVE_TASKS.md
external: ChoreStrike/.github — profile/README.md (WP-D, separate public repository)
```

As implemented (2026-10-18), beyond the list above: `img/manifest.webmanifest`
became `img/manifest.json` and `lib/Controller/PageController.php` lost its dead
manifest injection (RF-087-07); `docs/brand/chorestrike-mascot-maskable.svg`
is the maskable source (the framed mascot cannot keep the head inside the safe
zone and the handle bleeding at the same time); `src/views/TodayView.vue` and
`tests/e2e/mobile-core.spec.ts` carry the phone fix (RF-087-08, D-087-08); the
icon guard lives in `tests/unit/pwa-bootstrap.spec.js`; the `NcAppNavigationItem`
Jest mock renders the `icon` slot (`tests/unit/__mocks__/nextcloud-vue.js`,
asserted in `tests/unit/components/App.spec.js`); the reset-owning project list
in `tests/e2e/fixtures/database-fixtures.ts` names the serial `docs` project;
`.eslintrc.js` and the `lint` script cover `tests/docs-screenshots/`;
`docs/engineering/architecture.md` lists the new `img/` and test files.
RF-087-09 (D-087-09) adds `src/components/Common/Modal.vue`,
`src/views/Dashboard.vue`, `tests/unit/setup.js` (global `teleport` stub),
`tests/unit/components/Modal.spec.js`, `tests/unit/views/Dashboard.spec.js`,
`tests/e2e/fixtures/dialog-layer.ts`, `tests/e2e/task-manager.spec.ts` and
`tests/e2e/setup-wizard-journey.spec.ts`. No `test()` was added to a gate
spec, so `scripts/run-e2e-gate.js` and the `testing.md` inventory are
unchanged.

## 6. Implementation Order

1. WP-A1: mascot source and preview sheet → operator approval (D-087-02).
2. WP-A2: Nextcloud icons, PWA icons and manifest, notifier icon, navigation
   icons (mapping first), tests; focused browser check on both rows. Integrate
   `master` and the `feat/arcade-minigames` state of `src/App.vue` first
   (`npm run branch:status`).
3. WP-B: capture wrapper, separate Playwright project and scene list, drift
   guards; first run on a staged row.
4. WP-C: recapture, README refresh, user guide.
5. Interim Standard B (WP-A..C) with the full browser gate on both rows.
6. WP-D: organization profile after the D-087-05 go.
7. Final Standard B; durable docs (design.md §4, development.md capture
   section, deployment checklist) and backlog archive.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| The mascot reads as something else at 20 px (cupcake, bell) | Medium | Handle bleeds out of the frame at the mark's tilt; preview at 20 px on bright and dark headers; operator approval gate; fallback: a longer handle stub |
| A colored glyph is inverted by Nextcloud's filters | High if ignored | Monochrome `app.svg`/`app-dark.svg` only, pinned by the icon guard |
| Capture collides with another session's canonical stack | Medium | Isolated staged row with its own project and port; teardown limited to owned resources |
| Screenshots expose non-seed data | Low | Staged row with the `family` seed only; scene list restricted to seeded accounts; loopback-only base URL and seeded-household check, pinned by a negative-control test |
| The capture spec disturbs the canonical gate's discovery/inventory | Medium | Separate Playwright config and directory outside `tests/e2e/`; gate manifest updated in the same change if any gate test is added |
| `src/App.vue` conflicts with CS-PLAN-086 (arcade navigation entry) | Medium | Integrate `master` and the arcade branch's `App.vue` before WP-A2; whichever branch lands second integrates the other; plan WP-C stays feature-level for the arcade (D-087-07) |
| Stale `js/` build makes the capture show old UI | Medium | The wrapper refuses a missing or older-than-`src/` production build |
| Flaky or inconsistent captures | Medium | Ready selectors instead of sleeps, reduced motion, fixed viewport and locale; date-dependent content accepted |
| Package change after the qualified `0.8.0-alpha.1` | Certain | Requalification of the next candidate (release-freeze rule); no deployment in this plan |
| Public organization content leaks private details | Low | Same privacy scan as tracked docs; operator go and review before creation |
| Documentation drifts again | Medium | Version-sync guard, image-reference guard and the release checklist step |

## 8. Acceptance Criteria

- [x] D-087-01..07 confirmed (Standard A, 2026-10-17)
- [x] The operator's external review notes triaged on arrival (D-087-06): scope findings in §9, the rest in `backlog.md` (2026-10-18: no new finding, see §9)
- [x] Mascot approved by the operator; source and exports under `docs/brand/`
- [x] `img/app.svg` and `img/app-dark.svg` monochrome mascot glyphs; legible at 20 px in the header on NC 34 AIO and NC 35, light and dark (operator check, 2026-10-18)
- [x] Notifier and manifest icons resolve with HTTP 200 under `/custom_apps`; PWA install shows the color mascot (HTTP: smoke on both rows; install: operator check, 2026-10-18)
- [x] Every navigation entry shows an icon
- [x] `npm run docs:screenshots` reproduces all README and user-guide images from a staged row; documented in `development.md`
- [x] README current (hold-to-confirm, documentation split) with recaptured screenshots
- [x] `docs/user-guide.md` published and linked from README and `docs/README.md`
- [x] Organization profile live and checked logged out (after the D-087-05 go)
- [x] Drift guards and the release checklist item in place
- [x] Build gate green (`npm test` + lint + backend tests)
- [x] Required tests ran without new or unapproved skips
- [x] All implementation decisions and review questions resolved and recorded
- [x] All plan-owned review findings (§9) resolved or user-rescoped to `backlog.md`
- [x] Owning backlog items updated or archived according to their actual completion state
- [x] Durable concepts/decisions integrated into the permanent docs (`docs/standards/guidelines/design.md`, `docs/engineering/`) — plans are temporary
- [x] Final Standard B or Full review is `PASS` or `PASS-WITH-FIXES`
- [x] `docs/backlog/ACTIVE_TASKS.md` updated

## 9. Review Findings (plan-owned)

Findings **within this plan's scope** from implementation rounds or reviews
(scope-first routing per `project-rules.md` §4). Resolve before `done/`;
rescoping to `backlog.md` needs explicit user approval. Out-of-scope findings
go directly to `backlog.md` (or fold into an existing item) instead.

Standard A, 2026-10-17 (all fixed in the plan text in the same round):

- [x] **RF-087-01 major — WP-B privacy:** the capture "uses an explicitly given URL", contradicting D-087-03 and the risk row "never a production URL". Fixed: loopback-only base URL plus a seeded-household check, pinned by a negative-control test.
- [x] **RF-087-02 major — WP-B tooling:** a stand-alone script would duplicate the login path, and a capture spec under `tests/e2e/` (`testDir`) would enter the canonical gate's discovery. Fixed: separate Playwright config and `tests/docs-screenshots/` reusing the `setup` project and `auth-contexts`.
- [x] **RF-087-03 medium — Tests:** E2E icon assertions pin the gate manifest (`scripts/run-e2e-gate.js` `minTests`/`scenarios`, inventory in `testing.md`) as soon as a `test()` is added. Fixed: prefer assertions in existing scenarios; manifest and docs updated in the same change otherwise; both files in the File Manifest.
- [x] **RF-087-04 medium — WP-A navigation:** *Scheduling* and *Calendar* both map to a calendar, the registry lacks glyphs for Insights/Rewards/My Requests/Settings, and `getIcon()` returns `undefined` for unknown names. Fixed: the mapping is the first step of WP-A2, missing glyphs join `src/utils/icons.js`, the guard asserts `hasIcon()` and a unique mapping.
- [x] **RF-087-05 medium — overlap:** `src/App.vue` (arcade entry) and the user guide's arcade text overlap CS-PLAN-086. Fixed: integration step and risk row; guide stays feature-level (D-087-07).
- [x] **RF-087-06 minor — WP-A/WP-B/WP-D details:** stale `js/` build would be photographed (wrapper refuses it); PNG export procedure unspecified (design.md §4 procedure plus a PNG-size guard); pinning and social preview are web-UI-only (operator steps).

D-087-06 triage, 2026-10-18: the operator named the sanitized deployment
result for `0d27d38` (private exchange) as the only relevant external input.
It holds no finding in this plan's scope, and every point is already covered:
the three deployment-guide notes are in `deployment.md` (pending App Store
updates, `--showonly` listing disabled apps, maintenance mode kept), the
notifier deprecation is fixed (`37bcd5c`), its additional hint to check
`dismissNotification()`/`INotifyHandler::notify()` does not apply (ChoreStrike
implements neither), and the real-device checklist and 14-day trial stay with
CS-PLAN-080 (RF-080-15, RF-080-05). Nothing new routed.

Implementation, 2026-10-17/18:

- [x] **RF-087-07 major — WP-A PWA manifest never linked:** `PageController`
  called `OCP\Util::addLink()`, which exists neither in NC 34 nor 35; the
  best-effort catch swallowed the error, so pages only carried Nextcloud's
  theming manifest (`/apps/theming/manifest/chorestrike`, icons generated from
  `app.svg`, the Nextcloud logo where Imagick is missing). Core's page layout
  links `image_path(app, 'manifest.json')` and prefers the app's own file.
  Fixed: `img/manifest.webmanifest` → `img/manifest.json` with manifest-relative
  icon, `start_url` and `scope` (`../../../index.php/apps/chorestrike/`, works
  without pretty URLs and in a subdirectory), the dead injection removed. The
  404s that CR-PWA-01 reported came from the notifier's hard-coded icon; that
  item is folded in. `imagePath()` results sit in the distributed cache under a
  prefix that includes the installed app versions, so the switch takes effect
  with the version bump of the next deployment (a same-version dev row needs a
  cache flush). Pinned by `pwa-bootstrap.spec.js` and the smoke assertion that
  the page carries exactly one manifest link, under `/custom_apps`.
- [x] **RF-087-08 medium — My Day task cards collapse on phones:** at ≤ 600 px
  `.task-info` (flex basis 0) shrank to 13 px (iPhone 14) instead of wrapping,
  breaking "Assigned to: Teen" into single letters. Fixed per D-087-08: the
  info takes its own full-width line above *Done* and *Checklist*. Regression:
  width assertion in `mobile-core.spec.ts` (red on the old build, green on both
  phone projects).
- [x] **RF-087-09 medium — app dialogs below Nextcloud's navigation toggle:**
  the setup wizard and every `Modal.vue` dialog rendered their fixed overlay
  inside the app content's stacking context, so the navigation toggle stayed
  on top (it covered the wizard title in the capture). Fixed per D-087-09:
  `Modal.vue` teleports its overlay to `<body>`, and `Dashboard.vue` (the
  wizard's only consumer) renders `SetupWizard` inside a `<Teleport>` — the
  interim review moved that out of the component to avoid re-indenting its
  320-line template. Jest stubs `teleport` globally and pins the target in
  `Modal.spec.js` and `Dashboard.spec.js`. Regression:
  `fixtures/dialog-layer.ts` asserts the dialog is the topmost element under
  the toggle in `task-manager.spec.ts` (add-task modal) and
  `setup-wizard-journey.spec.ts` (red on the old build, green after). The
  pre-086 `SnakeLeaderboard.vue` overlay has the same pattern but is deleted by
  CS-PLAN-086, whose replacements use no fixed overlay; it stays untouched here.

Interim Standard B, 2026-10-18:

- [x] **RF-087-10 major — the teleport dropped the dialogs' caller attributes:**
  with `<Teleport>` as its root, `Modal.vue` could no longer inherit the
  `data-testid`/class its callers set, so 13 required scenarios
  (`family-rewards`, `core-household-journey`, `availability-settings-flow`,
  `scheduling-apply-flow`) failed identically on both gate rows; the Jest
  teleport stub, a real element, had masked it. Fixed: `inheritAttrs: false`
  and `v-bind="$attrs"` on the overlay restore the previous placement; a
  `Modal.spec.js` case pins it (red without the binding).
- [x] **RF-087-11 minor — KISS:** teleporting inside `SetupWizard.vue`
  re-indented its 320-line template; the `<Teleport>` moved to its only
  consumer, `Dashboard.vue`.
- [x] **RF-087-12 minor — stale README and wrapper detail:** the README spoke
  of "email invitations" (ChoreStrike sends none; an invitation needs the
  email of an existing Nextcloud account), of the wizard on first open (it
  opens on the Dashboard), of "overdue" (the UI says catch-up) and of
  owner-only task creation (adult members create tasks too); the capture
  wrapper ignored a failed teardown (now names the manual cleanup, tested).

Final Standard B, 2026-10-18:

- [x] **RF-087-13 minor — undocumented brand duplication:** the broom geometry
  lives in four hand-built sources and, simplified, in the two glyphs (SVG has
  no include without external references); `design.md` §4 now says to change
  them together and re-export.

## Review Outcome

### Standard A (Plan) — 2026-10-17 — `PASS-WITH-FIXES`

- **Scope:** this plan, plus the drafting package in `docs/backlog/backlog.md`
  (FEAT-UX-037, CR-NAV-01, TECH-DOCS-005, TECH-DOCS-007..009) and
  `docs/backlog/ACTIVE_TASKS.md`; branch `feat/public-presentation` (pinned
  worktree) at base `37bcd5c`.
- **Capture before review:** nothing staged (the plan was only marked
  intent-to-add); unstaged binary diff `743dc3ef…ad84`, no untracked files;
  combined scope fingerprint `255d9ff2…0689`; plan content hash
  `e164d349…e98b`.
- **Verified against the code and Nextcloud `stable34`:** header icon 20 px with
  `--background-image-invert-if-bright`, notification icon with
  `--background-invert-if-dark`, `app.svg` white / `app-dark.svg` dark and
  `AppManager::getAppIcon()` lookup; the hard-coded
  `/apps/chorestrike/img/` URLs in the notifier and manifest;
  `IURLGenerator::imagePath` in the test stub; `img/` in the package
  allowlist; eleven `NcAppNavigationItem` entries; the `family` default of
  `compose.test-matrix.yml` and the seeded Playwright roles; the gate manifest
  in `scripts/run-e2e-gate.js`.
- **Findings:** six (two major, three medium, one minor), all fixed in the plan
  text (§9, RF-087-01..06). No security/auth logic and no code is touched by
  this review.
- **Decisions (operator, 2026-10-17):** D-087-01..05 confirmed; D-087-06
  activate without the external review notes and triage them on arrival;
  D-087-07 arcade at feature level in the user guide. FEAT-UX-037 priority
  raised from P4 to P3 to match this plan.
- **Gates:** `check-backlog`, docs encoding and agent-adapter checks green.
  Jest, lint and backend suites were not run: no code changed in this review
  and the new worktree has no installed dependencies yet (`npm ci` is the
  first step of WP-A).
- **Transition:** `new/ → active/`; owning backlog items set to `In Progress`.
  No separate `CODE_REVIEW_*.md` record: the plan carries the evidence (a
  second Standard A section at the end of the shared 2026-10-17 record would
  only collide with the arcade branch's).

### Standard B (interim, WP-A..C) — 2026-10-18 — `PASS-WITH-FIXES`

- **Scope:** the WP-A..C implementation on `feat/public-presentation`, base
  `30bf1f2` (`master` `37bcd5c`, unchanged): 39 tracked and 20 new paths, listed
  in §5 and its *As implemented* note. Captured before review: unstaged diff
  `a607bf40…`, untracked content `fe694378…`; reviewed state including the
  review fixes `2a29a443…` (`git diff HEAD --binary` with intent-to-add, 59
  paths).
- **Checklist:** no business logic or endpoint changed (`PageController` lost
  dead code, the notifier resolves through `IURLGenerator`); no authorization
  change — the capture's member removal and catalog writes run only on its own
  staged row through the ordinary owner API; privacy: loopback-only capture
  target, seeded-household proof, seed data only; no new user-visible string
  (icons are decorative), no `console.*`; every fix has a regression test with
  a negative control; docs updated.
- **Findings:** RF-087-10 (major), RF-087-11 and RF-087-12 (minor), all fixed
  in this round; RF-087-07..09 fixed earlier in it with the operator decisions
  D-087-08/09 (§2).
- **Gates:** Jest 130/1885, lint (now including `tests/docs-screenshots/`),
  stylelint, `test:noise`, `agents:check`, `docs:encoding`, `backlog:check`
  green; PHPUnit unit 1386 and integration 613 with `--fail-on-skipped` on the
  NC 35/PostgreSQL 18 row and the NC 34 AIO row (Redis), `cs:check` 0/415; full
  browser gate **PASSED** on NC 35 (`run-7Ohp1w`) and the NC 34 AIO row
  (`run-DU01qw`): 51 required specs, zero skipped/failed/flaky.
  `npm run docs:screenshots` ran end to end (12 scenes, own row removed).
  Earlier attempts are not counted as passes: the first pair found RF-087-10
  (13 identical failures on both rows); the second had only load and transport
  failures while foreign containers held the host at 5 × 120 % CPU (`fetch
  failed` while seeding, a late dialog, `socket hang up` on a plain API read),
  green in isolation and folded into TECH-TRANSPORT-001.
- **Not yet run:** `test:package:build` refuses a dirty tree, so it runs on
  this review commit (`img/` is allowlisted wholesale). The manual checks of §4
  (header, app settings, notification popover and PWA install in light and
  dark on both rows) remain operator steps.
- **Transition:** interim — the plan stays in `active/`. Open: WP-D (D-087-05
  go; needs this branch on `master` and pushed), the external review notes
  (D-087-06), the manual checks, package evidence, the final Standard B.
  The plan carries the evidence, as at Standard A.

### WP-D evidence — 2026-10-18

- Push: `origin/master` `06caf9e..ca7f98e`, all commits with the GitHub noreply
  identity; CI `checks` green on `ca7f98e`; the four README screenshots render
  on the repository page (1280×800 each, logged out).
- `ChoreStrike/.github` (public) created with `profile/README.md` (commit
  `707b1a8`, noreply identity): logo, pitch, the recaptured My Day screenshot,
  status, links to repository, user guide, security policy and contact.
  Checked in a fresh browser without a GitHub session: profile rendered, both
  images loaded (512×512, 1280×800), every link answers 200.
- Operator web-UI steps: `ChoreStrike/ChoreStrike` pinned on the organization
  page (2026-10-18); social-preview image (1280×640) from the mark, prepared
  as `docs/brand/chorestrike-social-preview.svg` and its PNG export for the
  operator's upload (repository *Settings → Social preview*).
- Manual check (§4) done by the operator on 2026-10-18: header, app settings,
  notification popover and PWA install icon in light and dark on the NC 34 AIO
  and NC 35 — "passt".

### Standard B (final) — 2026-10-18 — `PASS-WITH-FIXES`

- **Scope:** the whole plan — implementation `30bf1f2..ca7f98e` (interim
  review commit, fast-forwarded into `master` and pushed) plus the closing
  changes on `feat/public-presentation`: WP-D evidence, the operator's manual
  check and pin, the D-087-06 triage, the social preview
  (`docs/brand/chorestrike-social-preview.svg` and its PNG), the `design.md`
  rows, the backlog archive and this transition. Captured before the review:
  unstaged diff `2e9ce4f7…`, untracked content `ccb921c1…`, `master` =
  `origin/master` = `ca7f98e`.
- **Plan check:** every §5 entry exists and changed (twelve screenshots, eight
  of them in the README/guide set of four plus the four guide scenes); all §8
  criteria met; RF-087-01..13 resolved; decisions D-087-01..09 recorded.
  Durable outcomes live in `design.md` §4 (two tiers, Nextcloud icon and
  manifest conventions, export and approval rule, the duplication note),
  `development.md` (screenshot capture), `deployment.md` (release checklist),
  `architecture.md` (`img/`, `tests/docs-screenshots/`), the README and
  `docs/user-guide.md`.
- **Findings:** RF-087-13 (minor, docs), fixed.
- **Gates:** no code changed since `ca7f98e`, so its evidence stands: full
  browser gate on NC 35 (`run-7Ohp1w`) and the NC 34 AIO row (`run-DU01qw`),
  PHPUnit 1386/613 and `cs:check` on both rows, package build 529 files with
  all new `img/` files, CI green after the push. Rerun for the closing docs
  change: Jest, lint, `docs:encoding`, `backlog:check`, `agents:check` green.
- **Transition:** `active/ → done/`; FEAT-UX-037, CR-NAV-01, TECH-DOCS-005,
  TECH-DOCS-007, TECH-DOCS-008 and TECH-DOCS-009 archived
  ([record](../../backlog/archive/2026-10.md#delivered-public-presentation-cs-plan-087-10-18)).
  Remaining operator step outside the plan's criteria: upload the social
  preview in the repository settings. The plan carries the evidence, as at
  Standard A and the interim review.
