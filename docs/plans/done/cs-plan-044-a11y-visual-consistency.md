# CS-PLAN-044: A11y & Visual Consistency

> **Status:** Done (2026-08-23) — implemented, Standard-B review PASS (6 auto-fixes), moved to `done/`
> **Effort:** L
> **Priority:** P2
> **Depends on:** soft — schedule after CS-PLAN-043 to avoid churning the same
> scheduling components twice
> **Closes:** UX-007, UX-010, UX-012, TECH-LINT-004, FEAT-UX-030 (conventions),
> FEAT-UX-031 (conventions)

## 1. Problem Summary

Accessibility and visual consistency gaps span the whole frontend: complete/
claim buttons (28 px / 24 px) and action icons (~16 px) sit below the 44 px
touch target mobile.css itself demands; icon-only buttons rely on `title`
tooltips; priority labels differ per surface (Low/Medium/High vs
High/Normal/Low vs Urgent/Normal/Can Wait); abstract terms (Admin-Only,
Dynamic/Static, Scope) leak implementation vocabulary; the CS-PLAN-038 chip
toggles (`AssigneeChips.vue`, weekday chips) hide their checkbox with
`display:none`, removing them from keyboard tab order (UX-007). Components
hardcode hex colors (RoomDetail stat values, FloorList action buttons, TaskList
badge fallbacks) bypassing NC vars/`--cs-*` tokens — dark-mode drift — while
mobile.css still carries dead selector blocks (`.mobile-only`, `.desktop-only`;
note UX-010's wider claim is stale: `.room-card`, `.task-item`,
`.leaderboard-entry` are still used by RoomCard/CalendarView/SnakeLeaderboard)
(UX-010). Shared `Modal.vue` lacks ESC handling, focus trap/restore,
`aria-modal` and scroll lock — and `OverdueClaimDialog.vue` bypasses Modal
with its own overlay; CalendarView prev/next use raw `<`/`>` glyphs without
aria-labels (UX-012). The 26 stylelint `use-logical` warnings were already
converted mechanically by commit `c09c5c9` (2026-08-21) — the gate is
warning-free, but the LTR/RTL rendering verification TECH-LINT-004 demands
never happened (TECH-LINT-004).
Error/empty states are ad-hoc per component and offline behavior is undefined
(FEAT-UX-031); a11y conventions exist only piecemeal (FEAT-UX-030).

## 2. Strategy

Codify first, then apply mechanically: extend `design.md` with binding a11y +
state conventions (touch targets, aria-labels, focus states, reduced motion,
one priority vocabulary, error/empty/offline component contract), then sweep
the codebase against them. The stylelint logical-properties work is now a
verification pass, not a conversion: commit `c09c5c9` already applied the
repo-wide auto-fix (26 warnings → 0), so each converted context is verified
file-by-file for LTR/RTL rendering instead (standing warning on TECH-LINT-004:
a blind auto-fix churned 15 files and had to be reverted once — do not repeat
`stylelint:fix` repo-wide). Kids-mode wording follows the unified
vocabulary; the "simplified child surface" question gets a recorded yes/no
product decision during this plan.

## 3. Backend / Frontend Changes

- **Backend** (`lib/`): none.
- **Frontend** (`src/`):
  - `docs/standards/guidelines/design.md`: binding a11y conventions (>=44 px,
    aria-labels, focus states, reduced motion), priority-vocabulary table,
    error/empty/offline component contract (FEAT-UX-030/031).
  - Touch targets + labels: Complete/claim buttons >=44 px, aria-labels on all
    icon-only buttons (TaskList, FloorList, RoomDetail, CalendarView nav).
  - Chip/toggle keyboard access (UX-007): `AssigneeChips.vue` plus the
    scheduling weekday toggles (`AvailabilitySettings.vue` /
    `SelfAvailabilitySettings.vue`, in whatever shape CS-PLAN-043 leaves
    them): visually-hidden checkbox (clip pattern, not `display:none`) or
    `role="checkbox"` + key handling — keyboard-reachable again.
  - Tokens: replace hardcoded hex with NC vars/`--cs-*` (verified in dark
    mode; hex fallbacks inside `var()` stay allowed per the existing
    `theme-consistency.spec.js` convention); delete dead
    `src/styles/mobile.css` selector blocks (`.mobile-only`, `.desktop-only`,
    any others the grep proves unused) after grep-verifying zero usage —
    `.room-card`/`.task-item`/`.leaderboard-entry` are still used and stay.
  - `Modal.vue`: ESC close, focus trap + restore, `aria-modal="true"`, body
    scroll lock; migrate `OverdueClaimDialog.vue` (own overlay, bypasses
    Modal) onto the shared Modal so it inherits this; CalendarView
    prev/next get aria-labels (UX-012).
  - Stylelint: verify the already-applied logical conversions (`c09c5c9`)
    file-by-file against LTR+RTL rendering; fix any regression root-cause
    (scoped `stylelint-disable-next-line` only with a recorded reason) and
    keep the gate at 0 errors / 0 warnings (TECH-LINT-004).
  - State components: `EmptyState.vue` / `ErrorState.vue` (retry action,
    offline detection strategy within NC ecosystem constraints) applied to
    Dashboard, TodayView, TaskList (FEAT-UX-031).
  - i18n: every new user-visible string — aria-labels included (Modal's
    hardcoded `aria-label="Close"` becomes `t()`) — goes through `t()`;
    `l10n/` artifacts updated in the same change.

## 4. Tests

- **Jest**: Modal (ESC/focus trap/restore), chip keyboard toggling, state
  components (variants + retry event).
- **Stylelint gate**: 0 errors, 0 warnings (or per-line disables with reasons).
- **l10n gate**: `npm run l10n:check-keys` clean (new aria-label/state strings).
- **E2E**: smoke + dashboard/today regression specs stay green; documented
  keyboard-only walkthrough (tab order, dialogs) recorded in the plan record.

## 5. File Manifest

```
new:      src/components/Common/EmptyState.vue
new:      src/components/Common/ErrorState.vue
modified: src/components/Common/Modal.vue
modified: src/components/Tasks/AssigneeChips.vue
modified: src/components/Tasks/OverdueClaimDialog.vue        (onto shared Modal)
modified: src/components/Scheduling/* weekday-toggle owners  (keyboard fix; the file(s)
          owning the toggles after CS-PLAN-043, e.g. AvailabilityEditModal.vue)
modified: src/styles/mobile.css                              (dead selector removal)
modified: src/views/Dashboard.vue
modified: src/views/TodayView.vue
modified: src/views/CalendarView.vue
modified: src/views/RoomDetail.vue
modified: src/components/Tasks/TaskList.vue
modified: src/components/Dashboard/FloorList.vue
modified: docs/standards/guidelines/design.md
modified: the ~14 files converted by c09c5c9                  (TECH-LINT-004 LTR/RTL
          verification pass; re-fix only where rendering regressed)
modified: tests/...                                           (as above, incl.
          theme-consistency.spec.js if swept blocks change)
archived: -
```

## 6. Implementation Order

1. design.md conventions + vocabulary decision (incl. child-surface answer).
2. `Modal.vue` a11y (ESC, trap, restore, scroll lock) + OverdueClaimDialog
   migration — benefits every dialog.
3. Chip/weekday-toggle keyboard accessibility fix (UX-007 regression;
   scheduling toggles in their post-CS-PLAN-043 shape).
4. Touch-target + aria-label sweep.
5. Token replacement + mobile.css dead-selector cleanup.
6. Stylelint logical-conversion verification file-by-file (conversions already
   landed via `c09c5c9`; never run repo-wide autofix again).
7. State components + application to the three views.
8. Gate + keyboard walkthrough + archive the closed items.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| Already-applied logical conversions (`c09c5c9`) changed rendering | Medium | File-by-file LTR+RTL verification pass (step 6); fix regressions root-cause (standing TECH-LINT-004 warning) |
| Dark-mode regressions from token swap | Medium | Toggle dark theme per touched view during the sweep |
| Focus trap breaks stacked modals | Low | Trap per top-most instance; restore focus to the opener element |
| Vocabulary change breaks existing E2E assertions | Medium | Update l10n artifacts + specs in the same change (pre-release: no BC) |
| Touch-target growth (28/24 px → ≥44 px) shifts row density/layout | Medium | Per-view visual check after the sweep; E2E smoke + dashboard/today specs stay green |

## 8. Acceptance Criteria

- [x] design.md carries binding a11y + state + vocabulary conventions
- [x] All interactive targets >=44 px; icon-only buttons labelled; chips and
      weekday toggles keyboard-operable
- [x] Modal: ESC/focus-trap/restore/aria-modal/scroll-lock; OverdueClaimDialog
      on the shared Modal; calendar nav labelled
- [x] No hardcoded hex in the swept components (hex fallbacks inside `var()`
      allowed per theme-consistency.spec.js); dead mobile.css selectors
      (`.mobile-only`, `.desktop-only`, any other grep-proven-unused) gone —
      in-use `.room-card`/`.task-item`/`.leaderboard-entry` kept
- [x] `c09c5c9` logical conversions verified for LTR+RTL rendering;
      `npm run stylelint` stays at 0 errors, 0 warnings
- [x] Shared Empty/Error states live in Dashboard, TodayView, TaskList
- [x] New user-visible strings (incl. aria-labels) via `t()`;
      `npm run l10n:check-keys` clean
- [x] Build gate green; UX-007/010/012 + TECH-LINT-004 + FEAT-UX-030/031
      moved to archive
- [x] `ACTIVE_TASKS.md` updated

## 9. Implementation Record (2026-08-23)

Implemented in one turn, ahead of the soft CS-PLAN-043 dependency — the
scheduling components were edited in their current (pre-043) shape, exactly
the fallback the plan allows.

### What was done (per step)

1. **design.md** — new binding sections: §6 Accessibility Conventions
   (≥44px targets, aria-labels on icon-only buttons, visually-hidden clip
   pattern + `:focus-within`, shared-Modal dialog contract, focus/motion
   rules), §7 Unified Vocabulary table (3=High / 2=Normal / 1=Low;
   dynamic=Flexible, static=Fixed schedule; admin-only=Admins only) with the
   recorded product decision **no separate simplified child surface** (one
   vocabulary for all ages), §8 Empty/Error/Offline state contract.
2. **Modal.vue** — ESC (top-most of a module-level instance stack only), Tab
   focus trap with wrap, focus restore to opener, `role="dialog"` +
   `aria-modal="true"` + optional `ariaLabel` prop, body scroll lock with
   stack counter, labelled close button. `OverdueClaimDialog.vue` migrated
   onto it (testids `overdue-claim-dialog` / `confirm-claim-button` kept).
3. **Chip keyboard access (UX-007)** — new global `src/styles/a11y.css`
   (`.cs-visually-hidden`); applied to `AssigneeChips.vue`, TaskList inline
   assignee chips, `TaskEditForm.vue` weekday chips, and both
   `AvailabilitySettings.vue` / `SelfAvailabilitySettings.vue` toggle grids
   (replacing `display:none`), each with a `:focus-within` ring.
4. **Touch targets + labels** — TaskList (complete 28→44, claim 24→44,
   action buttons + bulk-clear 28→44, all aria-labelled), FloorList
   (toggle 32→44 + aria-label, refresh 34→44), RoomDetail icon actions
   32→44 + labels, CalendarView prev/next labelled "Previous"/"Next" + 44px.
5. **Tokens + mobile.css** — hex → tokens in RoomDetail (stat values,
   cleanliness rings), FloorList (icon shells, toggle buttons incl. the
   white-alpha borders invisible in light mode), TaskList (completed-check),
   TaskEditForm (primary button), Dashboard (house-title icon), toggle
   knobs. `mobile.css` dead-selector sweep removed ~90 lines
   (`.mobile-only`, `.desktop-only`, `.mobile-nav*`, `.pull-to-refresh*` +
   spin keyframes, `.bottom-sheet*`, `.hover-effect`, `.touch-feedback`,
   `.no-callout`, `.scroll-container`, `.app-header`, `.app-footer`,
   `.room-grid`, `.sidebar`, `.form-label`, `.form-select`,
   `.form-textarea` — each grep-verified at zero template usage;
   `.room-card`/`.task-item`/`.leaderboard-entry`/`.icon-button`/
   `.action-button`/form-group+input+actions kept).
6. **TECH-LINT-004 verification** — `c09c5c9` audited file-by-file: all
   `inset-inline*`/`margin-inline`/`border-inline-end` conversions correct.
   Two real regressions found: the availability toggle knob needed a
   `[dir='rtl'] { transform: translateX(-20px) }` override in both
   scheduling components (fixed); the dead `.pull-to-refresh` centering
   pair was deleted with the sweep (moot). Stylelint 0/0.
7. **State components** — `EmptyState.vue` + `ErrorState.vue` (retry event;
   offline variant via `navigator.onLine` + window online/offline events,
   strategy documented in design.md §8). Applied: Dashboard (ErrorState on
   total init failure, retry re-runs `initializeDashboard`), TodayView
   (replaces ad-hoc error div; `today-error` testid kept), TaskList
   (EmptyState replaces ad-hoc markup).
8. **i18n** — new keys in en.json + de.json (Normal, Previous, Nothing here
   yet, You appear to be offline, Reconnect and try again, Could not load
   your household); `Admin-Only` key removed (unused after rename).

### Deviations & additions beyond the manifest

- **E2E infra root-fix:** `dashboard.spec.ts` added to
  `SEED_DEPENDENT_SPECS` in `playwright.config.ts`. Its create-flow reads
  room IDs from the seed while holding the form open; when a serial-pool
  reseed (e.g. today-view's `seededData`) replaced rooms mid-test, the
  room-task POST 404'd ("Task saved, but adding it to the room failed").
  Stash-verified pre-existing on the clean tree; serial-pool membership is
  the same mechanism CS-PLAN-015b established for seed-mutating specs.
- **E2E spec update:** `task-checklist-and-features.spec.ts` assertions
  `text=Dynamic`/`text=Static` → `text=Flexible`/`text=Fixed schedule`
  (vocabulary rename; option *values* unchanged, so `selectOption('urgent')`
  etc. keep working).
- **Testid migration:** TaskList empty-state assertions moved from
  `.empty-state` to `[data-testid="empty-state"]`; TodayView retry button
  testid `today-retry` → ErrorState's `error-retry` (no spec/E2E relied on
  the old one).

### Verification (gate)

| Gate | Result |
|---|---|
| `npm test` (Jest) | 806 passed / 0 failed (79 suites), incl. new Modal a11y (ESC/trap/restore/scroll-lock/aria), AssigneeChips keyboard spec, EmptyState/ErrorState specs, Dashboard error-state spec |
| `npm run lint` | clean (0 errors, 0 warnings) |
| `npm run stylelint` | 0 errors / 0 warnings |
| `npm run build` (webpack) | green |
| `npm run l10n:check-keys` | de/en synchronized |
| E2E smoke + dashboard + today-view | 24/24 green (`--retries=0`, family profile) |

Environment note: the Docker instance must be started with
`TEST_DB_PROFILE=family` (entrypoint provisions mom/dad/teen/kid/grandma/
friend); a minimal-profile boot leaves those NC accounts missing and every
profile-user login fails. Stale `.playwright/.auth/*.json` must be cleared
after a `down -v`.

### Keyboard walkthrough (live, Docker instance @ host.docker.internal:8080)

- TaskManager "New Task" → `role="dialog"`, `aria-modal="true"`, focus lands
  inside the dialog; ESC closes, body scroll lock released, focus restored
  to the opener button.
- Priority filter shows the unified High/Normal/Low vocabulary; form shows
  Flexible / Fixed schedule and Admins only.
- Fixed schedule → weekday chips: checkbox reachable in tab order
  (`display: block` + `clip-path: inset(50%)`, focusable, `tabIndex ≥ 0`),
  Space toggles it, chip shows a 2px `:focus-within` ring.
- Assignee chips render as real checkboxes in the accessibility tree.
- Icon-only buttons expose accessible names: "Mark as complete",
  "Claim & complete", "Edit", "Show details", "Remove", "Close",
  "Expand floor", "Previous", "Next".
- CalendarView navigation labelled and keyboard-operable.

### Known remaining (out of scope)

- `AvailabilitySettings.vue` day-item border hexes (#4caf50/#f44336) sit
  outside the swept-component list and stay (log-worthy only if a dark-mode
  pass flags them).
- The pre-existing Jest noise-gate line ("Failed to reorder floors") is
  unchanged by this plan (verified identical on a stashed clean tree).

## Review Outcome (Standard B, 2026-08-23)

**Verdict: PASS** — implementation matches the plan record; checklist
verified against the diff (all 9 acceptance criteria hold). 6 auto-fixes
applied, 2 backlog items logged (**UX-016**, **UX-017**).

Findings fixed inline:

1. **`OverdueClaimDialog.vue` never forwarded the shared Modal's `close`** —
   ESC, the × button and overlay clicks silently did nothing after the
   migration. Fixed: `@close="$emit('cancel')"` + 2 regression specs.
2. **Missing l10n keys** for strings this change introduced/promoted to
   `aria-label`: `Flexible`, `Fixed schedule`, `Expand floor`,
   `Collapse floor`, `Mark as complete`, `Show details` (+ the adjacent
   pre-existing `Fixed schedule (e.g., every Monday 18:00)`); added to
   en.json + de.json. Note: `l10n:check-keys` only verifies de/en parity,
   not src usage — the plan's "l10n clean" gate could not catch these.
3. **`OvercapacityWarning.vue` priority label drift** — still `Medium` for
   priority 2, contradicting the unified vocabulary this plan codifies
   (design.md §7). Fixed to `Normal`.
4. **`TaskList.vue` `.completed-check`** used `--color-primary-text` on a
   `--color-success` background; corrected to `--color-success-text`.
5. **`Modal.vue` `ariaLabel` JSDoc** claimed a slot-heading fallback that
   does not exist; comment corrected. The underlying gap (15 of 16 Modal
   call sites pass no accessible name) → **UX-016**.
6. **Jest noise gate red** — the "Failed to reorder floors" line (recorded
   above as pre-existing) fails `npm run test:noise`; root cause is
   `Settings.spec.js` exercising the real logger in its reorder-failure spec.
   Fixed with the CS-PLAN-034 logger-mock convention; noise gate now clean.

Gate re-verified after fixes: `npm run lint:fix` clean · `npm run stylelint`
0/0 (check-only, per TECH-LINT-004 no repo-wide `stylelint:fix`) ·
`npm test` 808 passed / 79 suites · `npm run test:noise` clean ·
`npm run l10n:check-keys` synced. No PHP touched → no Docker backend run.
