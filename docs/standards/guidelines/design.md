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

## 3. Typography & Styling
- **Fonts:** Inherited globally from the Nextcloud system (usually system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto). We do **not** define our own custom fonts to ensure native integration and keep things simple.
- **Shapes:** Slightly rounded corners (border-radius: 8px to 12px) for a friendly, "game-like" look.

## 4. Brand Assets & AI Generation
The primary visual style for the project's branding (Logo, Repo Avatar) is **Modern Isometric 3D**. 

*(Note: If a reference image is provided, it serves as the base structure for these prompts via Image-to-Image generation).*

### Required Asset Sizes:
- **App Logo (for SVG tracing):** 512x512px or 1024x1024px (1:1 Aspect Ratio) -> will be scaled down/vectorized to 64x64px later.
- **Codeberg Repo Avatar:** 256x256px or 512x512px (1:1 Aspect Ratio).
- **README Header / Banner:** 1200x400px (3:1 Aspect Ratio).

### Primary Style: "Modern Isometric 3D" (Clean & Gamified)
> **Prompt:** A stylized, modern isometric 3D app icon of a cozy house that acts as a cute defense base. The house has clean ocean blue walls, a vibrant coral-orange roof, and glowing sunny yellow windows. A futuristic, slightly magical broom stands next to the door like a guard. Clean solid background, UI design, dribbble style, soft studio lighting, high resolution, vibrant colors.

### Alternative / Iterative Prompts:
**Flat Vector Minimalist (App-Focused)**
> **Prompt:** Minimalist flat vector app icon of a house combined with a shield shape. The house is bright ocean blue with a warm coral-pink roof and a bright yellow star emblem on the front. Two crossed brooms behind the house like swords. Clean bold lines, solid colors, modern SaaS logo design, white background, no gradients, highly readable at small sizes.

**16-Bit Pixel Art (Retro Tower Defense)**
> **Prompt:** 16-bit pixel art of a cute fortress house in ocean blue and coral orange, defending against tiny, cute dust monsters. Bright sunny yellow highlights from the windows. The house has a broom mounted on the roof like a playful defense turret. Cozy retro gaming aesthetic, vibrant colors, clear silhouette, isometric perspective, clean pixel perfect lines.

## 5. Feedback Patterns (CS-PLAN-041)

Canonical interaction-feedback rules; every destructive or mutating action
follows one of these three patterns. No native confirm(), no silent saves.

| Situation | Pattern | Implementation |
|---|---|---|
| Destructive action (task/floor delete, member remove) | Styled confirmation dialog | shared src/components/Common/ConfirmDialog.vue (data-testid="confirm-dialog", danger styling, loading state) |
| Async mutation (saves, renames, deletes) | Success/error toast | showSuccess/showError from @nextcloud/dialogs; error toasts on every failure path, never raw logger-only |
| Completion of a task | Undo snackbar (~5 s) | global src/components/Common/UndoSnackbar.vue — *Undo* reverts via the one `POST /room-tasks/{id}/undo` aggregate (CS-PLAN-064), dismiss/timeout hides without effect; entries are queued so a second completion never destroys the first undo (UX-015) |

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
  outline using `var(--color-primary)` or the browser default). Never remove
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
