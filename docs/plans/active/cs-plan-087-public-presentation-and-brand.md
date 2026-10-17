# CS-PLAN-087: Public Presentation — Brand Icons, Reproducible Screenshots and User Guide

> **Status:** Active (WP-A not started); drafted 2026-10-17; Standard A `PASS-WITH-FIXES` 2026-10-17
> **Effort:** M
> **Priority:** P3
> **Depends on:** none (`master` `37bcd5c`). WP-A changes the app package, so the next deployment candidate needs its own requalification. The operator's external repository-review notes are still to be supplied; they are triaged on arrival (D-087-06). WP-C's arcade text is feature-level only so it does not depend on CS-PLAN-086 (D-087-07).
> **Related backlog items:** FEAT-UX-037, CR-NAV-01, TECH-DOCS-005, TECH-DOCS-007, TECH-DOCS-008, TECH-DOCS-009
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
- [ ] The operator's external review notes triaged on arrival (D-087-06): scope findings in §9, the rest in `backlog.md`
- [ ] Mascot approved by the operator; source and exports under `docs/brand/`
- [ ] `img/app.svg` and `img/app-dark.svg` monochrome mascot glyphs; legible at 20 px in the header on NC 34 AIO and NC 35, light and dark
- [ ] Notifier and manifest icons resolve with HTTP 200 under `/custom_apps`; PWA install shows the color mascot
- [ ] Every navigation entry shows an icon
- [ ] `npm run docs:screenshots` reproduces all README and user-guide images from a staged row; documented in `development.md`
- [ ] README current (hold-to-confirm, documentation split) with recaptured screenshots
- [ ] `docs/user-guide.md` published and linked from README and `docs/README.md`
- [ ] Organization profile live and checked logged out (after the D-087-05 go)
- [ ] Drift guards and the release checklist item in place
- [ ] Build gate green (`npm test` + lint + backend tests)
- [ ] Required tests ran without new or unapproved skips
- [ ] All implementation decisions and review questions resolved and recorded
- [ ] All plan-owned review findings (§9) resolved or user-rescoped to `backlog.md`
- [ ] Owning backlog items updated or archived according to their actual completion state
- [ ] Durable concepts/decisions integrated into the permanent docs (`docs/standards/guidelines/design.md`, `docs/engineering/`) — plans are temporary
- [ ] Final Standard B or Full review is `PASS` or `PASS-WITH-FIXES`
- [ ] `docs/backlog/ACTIVE_TASKS.md` updated

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

Open item: the operator's external review notes (D-087-06) are triaged on arrival.

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
