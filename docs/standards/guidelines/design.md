# ChoreStrike Design & Branding Guidelines

## 1. Core Philosophy
"Clean & Alert" (Modern & Vibrant)
This theme combines the clean feel of a productivity app with the energy of a game. It avoids the classic, boring "cleaning bucket blue" and instead relies on fresh, lively contrasts.

## 2. Color Palette
- **Primary (Ocean Blue):** `#0984E3` (Alternative for more turquoise: `#00CEC9`)
  - *Usage:* Base UI, headers, calm areas, standard tasks.
- **Action (Coral Pop):** `#FF7675` (Alternative: `#FAB1A0`)
  - *Usage:* Buttons, alerts, enemies ("The Dust"), gamification elements.
- **Highlight (Sunny Yellow):** `#FDCB6E`
  - *Usage:* Medals, rewards, shields, glowing windows.
- **Background/Neutral:** Light cream or pure white for Light Mode; deep navy/dark gray for Dark Mode.

### UI colors in the app (UX-017, CS-PLAN-084)

The brand palette above is for branding assets and decorative accents. App UI
colors come from the Nextcloud theme so light, dark and high-contrast themes
work automatically (NC34/35 semantics, verified on both runtime rows):

| Purpose | Use | Never |
|---|---|---|
| Status text (ok / due / overdue / info) | `--color-success-text`, `--color-warning-text`, `--color-error-text`, `--color-info-text` | `color: var(--color-success)` (a pale tint since NC32) |
| Status badge / chip / soft danger button | background `--color-<state>` + text `--color-<state>-text` (+ border `--color-element-<state>`) | white text on `--color-<state>` |
| Borders, progress fills, dots, status icons | `--color-element-<state>` | the pale `--color-<state>` tint |
| Accent text, links, focus/selection borders | `--color-primary-element` | `--color-primary` (theme-independent, 2.4:1 in dark) |
| Filled primary button | `--color-primary-element` + `--color-primary-element-text` | — |

Dark overrides of the `--cs-*` tokens key on the Nextcloud theme attributes on
`<body>` (`data-theme-dark`, or `data-theme-default` + OS dark), never on `:root`
or the OS preference alone. `tests/unit/css/theme-consistency.spec.js` guards the
rules statically; `tests/e2e/color-contrast.spec.ts` checks WCAG AA text contrast
of every main view in the light and dark themes.

## 3. Typography & Styling
- **Fonts:** Inherited globally from the Nextcloud system (usually system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto). We do **not** define our own custom fonts to ensure native integration and keep things simple.
- **Shapes:** Slightly rounded corners (border-radius: 8px to 12px) for a friendly, "game-like" look.
- **View inset:** Nextcloud's navigation toggle sits in the top-left corner of the app content, next to the heading row only. Every routed view pads its inline sides with `--cs-view-gutter` (2rem, 1rem up to 768 px), so the content below the heading keeps the gutter, and its `<header>` adds `--cs-view-heading-inset` so the heading clears the toggle (`--cs-view-inline-start`, the clickable area plus twice the navigation padding). The tokens live in `src/styles/tokens.css` on `.app-content`, where Nextcloud resolves those host metrics, not at `:root`; logical properties keep right-to-left layouts correct. Guarded by `tests/unit/css/view-inline-start.spec.js` and measured, including varied host metrics and RTL, by `tests/e2e/layout-clearance.spec.ts`.
- **No sideways page scroll on phones:** at phone width no routed view scrolls horizontally. A wide table scrolls inside its own `overflow-x: auto` box (focusable, with an accessible name), and grid tracks use `minmax(0, 1fr)` so their content cannot widen the view (UX-018). Measured per view by `tests/e2e/layout-clearance.spec.ts`.

## 4. Brand Assets

The brand mark (adopted 2026-10-17, TECH-DOCS-005) is the **"Night Ops" CS
monogram**: a slanted geometric "CS" in cream with an ocean-blue extrusion on a
deep-navy gradient, and between the letters a cheerful broom mascot (smiling face
on coral bristles, yellow headband with fluttering tails, sparkles). It nods to
the tactical-game sound of the name while staying playful: the "strike" is a
sweep, never an attack. It supersedes the earlier isometric-3D prompt set.

ChoreStrike uses **two tiers** (CS-PLAN-087, D-087-01): the full mark on large
surfaces (96 px and up: avatar, README, social preview) and the **mascot
symbol** on small ones (app icon, notifications, PWA). The mascot is the
broom head with face, headband and fluttering tails; the handle leaves through
the top edge of the frame at the mark's 6° tilt, so the shape still reads as a
broom at 20 px. The operator approved it on 2026-10-17 (D-087-02).

| Asset | File | Notes |
|---|---|---|
| Mark source | [`docs/brand/chorestrike-avatar.svg`](../../brand/chorestrike-avatar.svg) | Hand-built vector, no fonts or external references; edit here, then re-export |
| Repository / organization avatar (GitHub) | [`docs/brand/chorestrike-avatar-512.png`](../../brand/chorestrike-avatar-512.png) | 512×512, full-bleed square (GitHub rounds the corners); uploaded by the operator |
| Mascot source | [`docs/brand/chorestrike-mascot.svg`](../../brand/chorestrike-mascot.svg) | Color mascot on a transparent 512×512 frame; export [`chorestrike-mascot-512.png`](../../brand/chorestrike-mascot-512.png) |
| Maskable mascot source | [`docs/brand/chorestrike-mascot-maskable.svg`](../../brand/chorestrike-mascot-maskable.svg) | Mascot on the brand navy; the head inside the 80 % safe zone (radius 205 px), the handle bleeding out of the top |
| Nextcloud app icon | `img/app.svg`, `img/app-dark.svg` | Monochrome mascot glyph, 64×64: white (`#fff`) and its dark twin with the same geometry |
| PWA icons | `img/mascot-192.png`, `img/mascot-512.png`, `img/mascot-maskable-512.png` | Exports of the two mascot sources, listed in `img/manifest.json` |
| GitHub social preview | [`docs/brand/chorestrike-social-preview.svg`](../../brand/chorestrike-social-preview.svg) → [`chorestrike-social-preview-1280x640.png`](../../brand/chorestrike-social-preview-1280x640.png) | 1280×640: the mark plus name and tagline. The text uses the system sans-serif at export (no embedded font), so the PNG is the artifact; the operator uploads it in the repository settings |
| README banner (optional) | — | 1200×400 (3:1), reusing the mark and mascot |

**Rules**

- **Location:** sources and brand-only exports live under `docs/brand/`.
  `img/` ships in the app package (`docker/package-build.json` runtime
  allowlist), so it holds only the derived files the app uses: the two glyphs,
  the PWA icons and the manifest.
- **Palette:** the §2 brand colors plus deep navy (`#10233D`, gradient
  `#18365C` → `#0C1B30`), cream `#FFF8EC` and wood tones `#D9965B`/`#EDB97F`
  for the broom handle.
- **Originality:** original artwork only; no third-party game logos, typefaces,
  silhouettes or color schemes. No weapons, crosshairs or violent imagery.
- **Legibility:** the mark's letters must stay readable at 96 px; below that the
  mascot takes over. The glyph keeps eyes and smile as negative space and drops
  cheeks, bristle strokes and sparkles; it must read as a broom at 20 px on
  bright and dark headers.
- **Nextcloud icons:** Nextcloud 34/35 draw `app.svg` in the header as a CSS
  mask (only its alpha counts) and invert it on bright surfaces elsewhere; the
  notifications popover shows `app-dark.svg` and inverts it in the dark theme
  (the notifier links it through `IURLGenerator::imagePath()`, never a fixed
  `apps/` path, because apps live under `custom_apps`). Both glyphs are flat:
  one fill, no gradient, stroke, opacity, raster image or external reference —
  pinned by `tests/unit/pwa-bootstrap.spec.js`.
- **Manifest:** Nextcloud's page layout links the app's own `img/manifest.json`
  (`image_path(app, 'manifest.json')`); without that file it falls back to the
  theming manifest. Its icon, `start_url` and `scope` paths are relative to the
  manifest, so they work under `apps` or `custom_apps`, in a subdirectory and
  without pretty URLs.
- **Export:** render each SVG at its native size in headless Chromium through
  an `<img>` element with a transparent page background (Playwright's
  `omitBackground`), never by scaling a larger PNG: the mascot at 512
  (`docs/brand/chorestrike-mascot-512.png` and its `img/` copy) and 192, the
  maskable source at 512, the mark at 512. Check every export at 512, 192, 64
  and 20 px on light and dark backgrounds; a changed glyph or mascot needs the
  operator's approval of such a preview sheet before it enters `img/`.
- **One broom, five copies:** SVG offers no include without the external
  references these sources forbid, so the broom geometry is repeated in the
  mark, mascot, maskable and social-preview sources and, simplified, in the two
  glyphs. Change them together and re-export every derived file.

## 5. Feedback Patterns (CS-PLAN-041)

Canonical interaction-feedback rules; every destructive or mutating action
follows one of these three patterns. No native confirm(), no silent saves.

| Situation | Pattern | Implementation |
|---|---|---|
| Destructive action (task/floor delete, member remove) | Styled confirmation dialog | shared src/components/Common/ConfirmDialog.vue (data-testid="confirm-dialog", danger styling, loading state) |
| Async mutation (saves, renames, deletes) | Success/error toast | showSuccess/showError from @nextcloud/dialogs; error toasts on every failure path, never raw logger-only. New and changed failure toasts use `showErrorWithReference(t(…), error)` (src/utils/errorToast.js; the message is always `t()` output, never raw or server text), which appends the stable API code / HTTP status / `NETWORK` — required for the completion journey (CS-PLAN-084 WP-F) |
| Completion of a task | Undo snackbar (~5 s) | global src/components/Common/UndoSnackbar.vue — *Undo* reverts the exact completion via `POST /completions/{id}/undo` (WP3 targeted correction), dismiss/timeout hides without effect; entries are queued so a second completion never destroys the first undo (UX-015) |
| Frequent consequential tap (Done, Quick clean) | Press-and-hold (600 ms) | shared src/components/Common/HoldButton.vue (`@confirm`, `busy` for the in-flight state, `data-hold-state`, short-tap hint) — never for Undo, dialog confirms or destructive actions ([calm-core-ux](../../concepts/calm-core-ux.md#completion-needs-a-deliberate-press-hold-to-confirm)) |

Rules:

- Controls must never lie: a filter that cannot change the output is removed,
  not decorated (see the Leaderboard period filter removal).
- Prices and other authoritative data come from the server catalog; when the
  catalog is unavailable the action renders a disabled state with an explicit
  error instead of stale values.
- Every new user-visible string goes through `t()` with `l10n/` artifacts
  updated in the same change.

## 6. Accessibility Conventions (CS-PLAN-044)

Binding rules for every interactive component. These close UX-007 / UX-012 /
FEAT-UX-030 and are enforced in review.

### Touch targets

- Every interactive control (buttons, checkboxes, chips, toggle sliders,
  selects) provides a **≥44 × 44 px** hit area (`min-width`/`min-height` or
  equivalent padding), matching mobile.css's own `--touch-target-min`.
  Icon glyphs inside may stay small (16–20 px); the *target* grows.
- Icon-only buttons carry an `aria-label` via `t()` — a `title` tooltip is
  not an accessible name. Visible-text buttons need no aria-label.

### Keyboard operability

- Custom-looking toggles built from native inputs (assignee chips, weekday
  chips, availability sliders) must keep the native `<input>` **focusable**:
  hide it with the visually-hidden clip pattern
  (`position:absolute; width:1px; height:1px; clip-path: inset(50%); …`),
  never `display:none`. Show a visible focus indicator via
  `.chip:focus-within { outline: … }` so keyboard users can see and operate
  the control (Space toggles, Tab moves on).

### Dialogs

- All dialogs use the shared `Modal.vue`, which owns ESC-to-close, focus
  trap + restore (per top-most instance when stacked), `role="dialog"` +
  `aria-modal="true"`, and body scroll lock. Do not hand-roll overlays.
- Every dialog surface must have a non-empty **accessible name**
  (WCAG 4.1.2): pass the dialog's translated heading/trigger label as
  `Modal`'s `aria-label` prop (reuse an existing heading or trigger key —
  this also satisfies WCAG 2.5.3 label-in-name). `ConfirmDialog`
  forwards its required `title` automatically; a `title` attribute on
  `<Modal>` is not an accessible name (the component has no such prop)
  and must not be used.

### Focus & motion

- Interactive elements show a visible focus state (`:focus`/`:focus-within`
  outline using `var(--color-primary-element)` or the browser default). Never remove
  outlines without replacing them.
- Respect reduced motion: transitions/animations ride on the global
  `prefers-reduced-motion` handling of `src/styles/tokens.css`
  (`--cs-transition-*` collapse to `0ms`) — don't introduce animations that
  bypass it.

## 7. Unified Vocabulary (CS-PLAN-044)

One user-facing vocabulary across all surfaces (closes the UX-007 drift).
Values are API contracts and do not change; only labels unify.

| Concept | Value | Label (`t()` key) | Replaces |
|---|---|---|---|
| Priority | 3 | High | Urgent (TaskManager filter) |
| Priority | 2 | Normal | Medium (TaskList badge), Normal |
| Priority | 1 | Low | Can Wait (TaskManager filter) |
| Scheduling mode | dynamic | Flexible | Dynamic |
| Scheduling mode | static | Fixed schedule | Static |
| Visibility | public | Public | — |
| Visibility | private | Private | — |
| Visibility | admin-only | Admins only | Admin-Only |

**Product decision (recorded 2026-08-22):** there will be **no separate
simplified child surface** for now — the app speaks one vocabulary for all
ages, and kid-facing wording follows this table. Revisit only if a real
child-cohort test demands it (would be a new backlog item).

## 8. Empty / Error / Offline States (CS-PLAN-044)

Shared components close FEAT-UX-031; ad-hoc per-view markup is not allowed
for these three cases:

- **Empty:** `src/components/Common/EmptyState.vue` — icon + message +
  optional hint/action slot. Used when a list/view has zero items after a
  successful load (not while loading, not on error).
- **Error:** `src/components/Common/ErrorState.vue` — message + retry
  button emitting `retry`. Shows an offline notice instead of the generic
  error text when the browser reports no connection.
- **Offline detection strategy:** `navigator.onLine` plus the window
  `online`/`offline` events. This is a browser capability available inside
  the Nextcloud page shell — no external service needed. It drives the
  ErrorState offline variant only; actual retry success is decided by the
  request itself.

Applied at minimum to Dashboard, TodayView, and TaskList; new views reuse
the components instead of inventing state markup.
