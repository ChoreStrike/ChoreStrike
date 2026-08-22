# CS-PLAN-044: A11y & Visual Consistency

> **Status:** Plan (not started)
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
mobile.css still targets legacy class names no longer used (`.room-card`,
`.task-item`, `.leaderboard-entry`, `.mobile-only`) (UX-010). Shared
`Modal.vue` lacks ESC handling, focus trap/restore, `aria-modal` and scroll
lock; CalendarView prev/next use raw `<`/`>` glyphs without aria-labels
(UX-012). 26 stylelint `use-logical` warnings remain unaudited (TECH-LINT-004).
Error/empty states are ad-hoc per component and offline behavior is undefined
(FEAT-UX-031); a11y conventions exist only piecemeal (FEAT-UX-030).

## 2. Strategy

Codify first, then apply mechanically: extend `design.md` with binding a11y +
state conventions (touch targets, aria-labels, focus states, reduced motion,
one priority vocabulary, error/empty/offline component contract), then sweep
the codebase against them. The stylelint logical-properties sweep happens
file-by-file with verified LTR/RTL context — explicitly NOT via repo-wide
`stylelint:fix` (standing warning on TECH-LINT-004: a blind auto-fix churned
15 files and had to be reverted). Kids-mode wording follows the unified
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
  - Chip toggles (`AssigneeChips.vue`, weekday chips): visually-hidden
    checkbox (clip pattern, not `display:none`) or `role="checkbox"` + key
    handling — keyboard-reachable again.
  - Tokens: replace hardcoded hex with NC vars/`--cs-*` (verified in dark
    mode); delete stale `mobile.css` selector blocks after grep-verifying
    zero usage.
  - `Modal.vue`: ESC close, focus trap + restore, `aria-modal="true"`, body
    scroll lock; CalendarView prev/next get aria-labels (UX-012).
  - Stylelint: audit each of the 26 `use-logical` hits; convert where the
    positioning/transform context is verified for LTR+RTL, else scoped
    `stylelint-disable-next-line` with a reason (TECH-LINT-004).
  - State components: `EmptyState.vue` / `ErrorState.vue` (retry action,
    offline detection strategy within NC ecosystem constraints) applied to
    Dashboard, TodayView, TaskList (FEAT-UX-031).

## 4. Tests

- **Jest**: Modal (ESC/focus trap/restore), chip keyboard toggling, state
  components (variants + retry event).
- **Stylelint gate**: 0 errors, 0 warnings (or per-line disables with reasons).
- **E2E**: smoke + dashboard/today regression specs stay green; documented
  keyboard-only walkthrough (tab order, dialogs) recorded in the plan record.

## 5. File Manifest

```
new:      src/components/Common/EmptyState.vue
new:      src/components/Common/ErrorState.vue
modified: src/components/Common/Modal.vue
modified: src/components/Tasks/AssigneeChips.vue
modified: src/assets/mobile.css                              (stale selector removal)
modified: src/views/Dashboard.vue
modified: src/views/TodayView.vue
modified: src/views/CalendarView.vue
modified: src/views/RoomDetail.vue
modified: src/components/Tasks/TaskList.vue
modified: src/components/Dashboard/FloorList.vue
modified: docs/standards/guidelines/design.md
modified: ~15 files with physical CSS properties             (TECH-LINT-004 sweep)
modified: tests/...                                           (as above)
archived: -
```

## 6. Implementation Order

1. design.md conventions + vocabulary decision (incl. child-surface answer).
2. `Modal.vue` a11y (ESC, trap, restore, scroll lock) — benefits every dialog.
3. Chip keyboard accessibility fix (UX-007 regression).
4. Touch-target + aria-label sweep.
5. Token replacement + mobile.css cleanup.
6. Stylelint logical sweep file-by-file (never repo-wide autofix).
7. State components + application to the three views.
8. Gate + keyboard walkthrough + archive the closed items.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| Logical-property rewrite changes rendering | Medium | Convert only verified contexts; per-file visual check (standing TECH-LINT-004 warning) |
| Dark-mode regressions from token swap | Medium | Toggle dark theme per touched view during the sweep |
| Focus trap breaks stacked modals | Low | Trap per top-most instance; restore focus to the opener element |
| Vocabulary change breaks existing E2E assertions | Medium | Update l10n artifacts + specs in the same change (pre-release: no BC) |

## 8. Acceptance Criteria

- [ ] design.md carries binding a11y + state + vocabulary conventions
- [ ] All interactive targets >=44 px; icon-only buttons labelled; chips
      keyboard-operable
- [ ] Modal: ESC/focus-trap/restore/aria-modal/scroll-lock; calendar nav
      labelled
- [ ] No hardcoded hex in the swept components; stale mobile.css selectors
      gone
- [ ] `npm run stylelint`: 0 errors, 0 warnings
- [ ] Shared Empty/Error states live in Dashboard, TodayView, TaskList
- [ ] Build gate green; UX-007/010/012 + TECH-LINT-004 + FEAT-UX-030/031
      moved to archive
- [ ] `ACTIVE_TASKS.md` updated
