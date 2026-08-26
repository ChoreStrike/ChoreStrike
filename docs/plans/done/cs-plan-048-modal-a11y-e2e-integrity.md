# CS-PLAN-048: Modal A11y Names, Energy Display Guard & E2E Integrity Sweep

> **Status:** Implemented (2026-08-25) — gate green; ready for manual `active/ → done/` move
> **Effort:** M
> **Priority:** P2
> **Depends on:** none
> **Closes backlog items:** UX-016 (P3/S) · BUG-E2E-003 (P3/S) · BUG-E2E-001 (P3/M) · BUG-UI-001 (P4/S)
> **Activation decisions:** D1 (UX-016 approach), D2 (serial-gate green confirmation), D3 (invalid-input display semantics)

## 1. Problem Summary

Four open, small-to-medium quality items form the next sensible batch after CS-PLAN-047
(the ACTIVE_TASKS follow-up note recommends UX-016 first, then BUG-E2E-001/003):

1. **UX-016 — Modal call sites without accessible name.** `Modal.vue` has an
   `ariaLabel` prop since CS-PLAN-044, but only `OverdueClaimDialog` passes one.
   The other **15 of 16** `<Modal>` call sites render `role="dialog"` without a
   name (WCAG 4.1.2). Grep-verified 2026-08-25: `TaskManager` ×2,
   `Settings` ×3, `RoomDetail` ×3, `ArcadeSection` ×3, `TitlePicker`,
   `AvailabilityEditModal`, `TaskChecklist`, plus the shared `ConfirmDialog`
   wrapper (which multiplies to all its consumers).
2. **BUG-E2E-003 — `title-selection.spec.ts:39` flake.** "Selecting a pack marks
   it active" fails on first attempt even standalone (stash-verified
   pre-existing): `active-pack-tag` not visible within 10 s of the selection PUT.
   Likely a race against the house-membership bootstrap or concurrent reseed.
3. **BUG-E2E-001 — `3-floor-house.spec.ts` pre-existing failures.** In the
   serial pool, 2 of 10 tests fail identically on the unmodified tree
   (re-counted 2026-08-25; the backlog's "13" predates the CS-PLAN-042 spec
   rewrite): `Navigation: Can navigate to all 3 floors from dashboard` (`:54`)
   and `Task Management: Add cleaning task to Gästebad` (`:98`, times out
   waiting for a `Save`/`Create` button). The spec is registered in
   `SEED_DEPENDENT_SPECS` (`chromium-serial-db`) since CS-PLAN-018 and was
   never removed — but every full serial-project run is red on it, and the
   plan-level local gates have not covered it.
4. **BUG-UI-001 — garbled energy totals during invalid input.**
   `EnergyBudgetSettings.totalWeeklyEnergy` (computed at `:102`) and
   `BudgetAnalysis` total/average (`:45`) reduce with plain `sum + energy`.
   While a day input is cleared (`''`) or mid-typing invalid (`NaN`), the
   display degrades to string concatenation (`"1015"`) or `NaN`. Display-only
   (save is guarded by up-front validation), verified pre-existing.

## 2. Strategy

One frontend-quality sweep in three work packages, E2E work last because it
needs the Docker environment:

- **WP-A (UX-016):** every Modal call site gets an explicit translated
  `aria-label` (D1a). `ConfirmDialog.vue` forwards its required `title` prop as
  the Modal's accessible name — one line covers all ConfirmDialog consumers.
  design.md §6 records the requirement durably; l10n artifacts synced.
- **WP-B (BUG-UI-001):** normalize non-finite day values to `0` before the
  display reduces (D3), pinned by Jest cases. Two call sites only → inline
  coercion, no shared helper yet (rule of three).
- **WP-C (BUG-E2E-003 + BUG-E2E-001):** diagnose-first, then fix the spec or the
  underlying UI flow; `3-floor-house.spec.ts` is already registered in the
  blocking serial project, so the goal is a green full `chromium-serial-db`
  run (D2). No soft-skip territory.

No backend changes.

## 3. Backend / Frontend Changes

### Frontend (`src/`)

**WP-A — accessible names (15 sites + wrapper):**

| File | Call site | Label source |
|---|---|---|
| `src/components/Common/ConfirmDialog.vue` | `<Modal>` at `:7` | forward `title` prop |
| `src/views/TaskManager.vue` | add-task `:135`, edit-task `:142` | reuse trigger key "New Task"; new "Edit Task" key |
| `src/views/Settings.vue` | add-floor `:206`, edit-floor `:234`, reset `:261` | reuse modal headings ("Add New Floor" / "Edit Floor" / "Reset House?") |
| `src/views/RoomDetail.vue` | add-task `:123`, edit-task `:140`, rename-room `:156` | reuse "Add Task" trigger + "Rename Room" heading; edit reuses "Edit Task" |
| `src/components/Dashboard/ArcadeSection.vue` | snake `:122`, 2048 `:127`, memory `:132` | reuse game-card headings ("Snake" / "2048" / "Memory") |
| `src/components/Profile/TitlePicker.vue` | `:7` | reuse "Choose your title" heading |
| `src/components/Scheduling/AvailabilityEditModal.vue` | `:7` | reuse existing "Edit Availability" key; replace the stray `:title` binding (Modal has no `title` prop — it lands on the overlay, not the dialog) |
| `src/components/Tasks/TaskChecklist.vue` | item-edit `:103` | reuse "Edit Sub-Task" heading (label-in-name) |

(`OverdueClaimDialog.vue` already passes `aria-label`; unchanged.)

**WP-B — display coercion:**

- `EnergyBudgetSettings.vue:102` — coerce each day value via
  `Number.isFinite(Number(energy)) ? Number(energy) : 0` inside the computed.
- `BudgetAnalysis.vue:45` — same coercion for total/average;
  `peakDay`/`lowEnergyDays` use the coerced values too (numeric-0 days can
  never become peak since `maxEnergy` starts at `0` — behavior preserved).

### Docs & l10n

- `docs/standards/guidelines/design.md` §6 *Dialogs*: accessible-name
  requirement for every dialog surface (new rule + rationale).
- Reuse-first: 12 of the 15 sites reuse an existing heading/trigger key
  (verified present 2026-08-25), which also satisfies WCAG 2.5.3
  label-in-name; at most ~2 new keys ("Edit Task"). All touched keys synced
  across `l10n/en.json`, `l10n/de.json`, `l10n/templates/chorestrike.pot`.

### Cross-layer contracts

None affected — purely presentational props and test specs.

## 4. Tests

- **Jest:**
  - `tests/unit/components/ConfirmDialog.spec.js`: modal container exposes
    `aria-label` equal to the `title` prop.
  - Accessible-name assertions extended in existing specs that already open the
    modal (`ArcadeSection.spec.js`, `AvailabilityEditModal.spec.js`,
    `TitlePicker.spec.js`, `TaskChecklist.spec.js`, view specs where present).
  - `EnergyBudgetSettings.spec.js` / `BudgetAnalysis.spec.js`: empty (`''`)
    and mid-edit invalid day input → total renders numeric (no `"1015"`, no
    `NaN`); average/peak/low counts stay sane.
- **E2E (Playwright, Docker family profile):**
  - `title-selection.spec.ts`: passes 3× standalone with `--retries=0` after
    the race fix.
  - `chromium-serial-db` full project: `3-floor-house.spec.ts` 10/10 and the
    whole serial project green (the spec already sits in the blocking gate;
    per D2).
  - Regression: `structure-*` / scheduling-owner suites still green.
- **Gate:** `npm test` · `npm run lint` + stylelint 0 errors · l10n key check
  clean · noise gate clean · webpack build green · Docker PHPUnit unit +
  integration unchanged-green.

## 5. File Manifest

```
modified: src/components/Common/ConfirmDialog.vue
modified: src/views/TaskManager.vue
modified: src/views/Settings.vue
modified: src/views/RoomDetail.vue
modified: src/components/Dashboard/ArcadeSection.vue
modified: src/components/Profile/TitlePicker.vue
modified: src/components/Scheduling/AvailabilityEditModal.vue
modified: src/components/Tasks/TaskChecklist.vue
modified: src/components/Scheduling/EnergyBudgetSettings.vue
modified: src/components/Scheduling/BudgetAnalysis.vue
modified: tests/unit/components/ConfirmDialog.spec.js
modified: tests/unit/components/EnergyBudgetSettings.spec.js
modified: tests/unit/components/BudgetAnalysis.spec.js
modified: tests/e2e/title-selection.spec.ts
modified: tests/e2e/3-floor-house.spec.ts
modified: docs/standards/guidelines/design.md
modified: l10n/en.json
modified: l10n/de.json
modified: l10n/templates/chorestrike.pot
```

(Further existing Jest specs listed in §4 join the manifest only if they need
assertion extensions.)

## 6. Implementation Order

1. Baseline: run the affected Jest suites; run `title-selection.spec.ts` and
   the `chromium-serial-db` project standalone to capture current failure
   signatures (traces) before touching anything.
2. WP-A step 1: `ConfirmDialog` aria-label passthrough + spec assertion.
3. WP-A step 2: remaining 14 direct call sites with translated labels; sync
   l10n en/de/pot.
4. WP-A step 3: design.md §6 accessible-name rule.
5. WP-B: coercion in both scheduling components + Jest pins.
6. Gate A+B: Jest, lint/stylelint, l10n check, webpack build.
7. WP-C step 1 (BUG-E2E-003): diagnose the failing request from the baseline
   trace; fix race or replace fixed waits with deterministic waits; verify 3×
   standalone `--retries=0`.
8. WP-C step 2 (BUG-E2E-001): fix both failing tests (spec-level or underlying
   UI flow); verify the full `chromium-serial-db` project green — the spec is
   already registered in the blocking gate (D2).
9. Full gate + Standard-B review; move closed backlog items to
   `archive/2026-08.md`.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| ~15-file mechanical diff invites merge conflicts with parallel work | Low | Single focused sweep; no unrelated edits; land WP-A/B before starting E2E work |
| New l10n keys drift between en/de/pot | Medium | `npm run lint:fix` + l10n key-check gate in step 6 and final gate |
| E2E root causes turn out to be app-side bugs (not spec flakiness), growing scope | Medium | Diagnose-first step 1 with traces; cap app-side fixes at S effort, else split into a follow-up backlog item and close only the diagnosed part |
| Full serial project stays red while the two tests are unfixed (the spec already sits in the blocking gate — no re-inclusion needed, so no CI-slowdown trade-off exists) | Certain (current state) | Baseline capture step 1, fix step 8; full-project green is the exit criterion |
| Coercion changes displayed values users relied on | Very low | Invalid intermediate states previously showed garbage (`"1015"`/`NaN`); numeric-0 fallback is strictly more truthful; pinned by Jest cases |

## 8. Acceptance Criteria

- [x] All 16 `<Modal>` call sites render a `role="dialog"` element with a
      non-empty accessible name (grep-verified count; screen-reader spot check).
- [x] `ConfirmDialog` consumers inherit the accessible name from `title`
      automatically; covered by a Jest assertion.
- [x] design.md §6 names the accessible-name requirement explicitly.
- [x] With an empty/invalid day input, weekly total and BudgetAnalysis cards
      show numeric values (no concatenation, no `NaN`); Jest pins cover it.
- [x] `title-selection.spec.ts` passes 3× standalone with `--retries=0`.
- [x] `3-floor-house.spec.ts` passes 10/10 and the full `chromium-serial-db`
      project is green (the spec already sits in the blocking gate; D2 signs
      off the restored green state).
- [x] Build gate green: `npm test`, lint + stylelint 0 errors, l10n clean,
      noise clean, webpack build green, Docker PHPUnit unit/integration
      unchanged-green, regression E2E suites green.
- [x] UX-016, BUG-E2E-003, BUG-E2E-001, BUG-UI-001 moved to
      `docs/backlog/archive/2026-08.md`; `ACTIVE_TASKS.md` updated.
- [x] Durable knowledge integrated (design.md §6 — no further permanent-doc
      targets; plans are temporary).

## 9. Implementation & Review Outcome (2026-08-25)

Implemented as planned; deviations recorded:

- **l10n reality vs §3:** more than ~2 reused keys were missing from the
  artifacts. Synced six keys in total: `New Task`, `Snake`, `Add New Floor`,
  `Reset House?`, `Edit Sub-Task` (used via `t()` but never extracted) plus
  `2048` (the artifacts only contained the mangled `"0": "2048"` entry,
  replaced during review). All synced across en/de/pot.
- **BUG-E2E-003 root cause:** *not a race.* A successful selection emits
  `selected` and Settings closes the picker immediately, so the Active tag
  inside the closing modal was unobservable by construction (failure snapshot
  showed the picker gone). Spec rewritten to observe the durable outcome:
  catalog-render wait → already-active branch asserts the tag → select branch
  observes the PUT response, expects the picker closed, reopens and asserts
  the persisted Active tag. The disabled-button skip is now honest: it probes
  `/users/current` (authenticated fetch with requesttoken) and skips only when
  no house user id exists; transport errors fail loudly.
- **BUG-E2E-001:** not reproducible on the current tree — 10/10 standalone
  (`--retries=0`) AND the full blocking `chromium-serial-db` project green
  (86 passed / 4 pre-existing data-state conditional skips / 0 failed). D2
  green-state sign-off met without any config change.
- **Gate:** Docker PHPUnit unit 834 / integration 200 [24 pre-existing skips]
  · Jest 842 · lint + stylelint 0 errors · noise clean · l10n clean · webpack
  build green · title-selection 3× standalone green (`--retries=0`, both
  branches exercised) · full serial project green.
- **Standard-B review (2026-08-25): PASS** — independent reviewer pass over
  all changed files; 3 findings (2 minor, 1 nit), all auto-fixed inline
  (`2048` l10n key sync incl. mangled-`"0"` removal, honest disabled-button
  skip, redundant inline stubs dropped from the new ArcadeSection test);
  gates re-verified after the fixes. No backlog items added.
- **Out-of-manifest gate repair:** `ScheduleCalendar.spec.js` de-shape
  week-range regex assumed 3-letter month abbreviations and failed on any
  week touching "Sept." (date-dependent, verified pre-existing via stash);
  pattern relaxed to 3–4 letters so the gate stays green across all weeks.

### Review Outcome (Standard B — 2026-08-26)

Re-reviewed the full uncommitted tree against the shared checklist
(architecture, authorization, security, KISS, workarounds, logging, i18n,
tests). Implementation confirmed clean: all 16 `<Modal>` sites carry a
translated `aria-label` (grep-verified), `Modal.vue` applies it on the
`role="dialog"` element, `ConfirmDialog` forwards its required `title`,
the energy coercion covers both DOM (`''`) and slider (`NaN`) paths with
preserved peak/low semantics, and the rewritten `title-selection.spec.ts`
probes the real `/users/current` endpoint (route + response shape
verified) with fail-loud transport errors. **2 minor doc findings, both
auto-fixed inline:**

- `backlog.md` UX-016: two dangling unchecked `- [ ]` lines left under the
  Resolved status (the other three closed items removed their bodies) —
  removed.
- `archive/2026-08.md` CS-PLAN-047 entry: Gate-line indentation churned
  2 → 3 spaces against the entry's 2-space continuation — restored.

**Gate re-verified after fixes:** `npm run lint:fix` clean · Jest 842/842 ·
noise gate clean · `l10n:check-keys` synchronized. (No PHP changed → no
Docker run; no styles touched → no stylelint; E2E + webpack + Docker gates
per the 2026-08-25 record above — unaffected by doc-only fixes.) No backlog
items added.
