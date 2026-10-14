# Code Review: CS-PLAN-084 Round 2 (RF-084-01 Overdue Boundary) — Interim Standard B

- **Review Date**: 2026-10-14
- **Reviewer**: Claude Code
- **Mode**: Standard B (interim; plan stays `active/`)
- **Scope**: working tree at `eb75142` (index empty): day-based `RoomTask::isOverdue($tz)`, `RoomTaskReadService::statusFor` (list/detail status), takeover recording keyed on the `late` classification, shared client `isOverdue`/`dueClass`/`dueText` and TaskManager overdue filter, tests and docs
- **Owning plan**: [CS-PLAN-084](../../plans/active/cs-plan-084-family-trial-ux-baseline.md)

## Captured Fingerprint

- Unstaged diff (20 tracked paths) SHA256 prefix `8ec569f4ce9161b5`; no untracked files.

## Findings

| ID | Severity | Area | Issue | Status |
|----|----------|------|-------|--------|
| B2-01 | minor | `src/components/Dashboard/RoomCard.vue` | Room card overdue count still compared the due instant with now (work due today counted as overdue) | fixed (shared `isOverdue`/`dueClass`; Jest regression) |
| B2-02 | minor | `src/views/TaskManager.vue` due-today filter | Own day rule excluded open-flex-window rows the badge shows as "today" | fixed (filter = badge rule) |
| RF-084-02 | minor | `lib/Service/RoomTaskService.php` `getRoomTaskStats` | Stats card counts use the entity day rule, not the occurrence authority (flex/paused rows) | routed to plan §9 (WP-C) |

## Verified Non-Findings

- `next_due` is the planned day's start instant in the house zone (`OccurrenceReconcileService::updateNextDueProjectionUnderLock`), so the day bucket is correct east and west of UTC (tested for UTC, Berlin, New York, Auckland).
- The takeover rule now matches the claimable set (`getClaimableOverdueTasks` → `late`); agreed amendments and catch-up handovers stay non-takeovers.

## Gates

Jest 119 / 1709; PHPUnit unit 1385, integration 610 (skips forbidden); ESLint, `composer cs:check`, `l10n:check-keys`, docs encoding, backlog check green. Browser 75/75 (official pool separation) for the round; post-fix rerun 35/37 with a recorded concurrent-pool invocation race, the two affected specs 9/9 isolated.

## Outcome

**PASS-WITH-FIXES (interim).** Two findings fixed in-round, one minor plan-owned finding routed to WP-C. Plan stays active.

---

## Round 3

# Code Review: CS-PLAN-084 Round 3 (WP-B Colors and Contrast) — Interim Standard B

- **Review Date**: 2026-10-14
- **Reviewer**: Claude Code
- **Mode**: Standard B (interim; plan stays `active/`)
- **Scope**: working tree at `8cac20a` (index empty): NC32+ semantic color pairs across 40+ components, body-keyed dark tokens, axe color-contrast E2E spec, CSS guard tests, `@axe-core/playwright` devDependency, ESLint e2e override, design guideline, TECH-TRANSPORT-001 recurrence fold-in
- **Owning plan**: [CS-PLAN-084](../../plans/active/cs-plan-084-family-trial-ux-baseline.md)

## Findings

| ID | Severity | Area | Issue | Status |
|----|----------|------|-------|--------|
| B3-01 | minor | MemberList, AssigneeChips, FlexWindowDisplay | `--color-primary-light` tint with `--color-primary-element` text (≈4.7:1 dark) instead of the light-text pair | fixed (`--color-primary-element-light-text`) |

## Verified Non-Findings

- Scripted scan: no CSS rule with text in its own background token family; no `--color-primary-element` surface with white text; all `--color-element-*` backgrounds are text-free bars/markers.
- The mechanical sweep touched only `color`, `border*`, `outline*` and `accent-color` declarations; backgrounds were classified by hand (badge / fill / danger button).

## Gates

Jest 119 / 1713 (CSS guards 9/9); ESLint, Stylelint green; color-contrast spec 0 violations in light and dark over nine views; focused browser runs green except two recorded TECH-TRANSPORT-001 closures that pass isolated.

## Outcome

**PASS-WITH-FIXES (interim).** One minor finding fixed in-round; no open findings from this round.

## Round 4

# Code Review: CS-PLAN-084 Round 4 (WP-E Hold-to-Confirm) — Interim Standard B

- **Review Date**: 2026-10-14
- **Reviewer**: Claude Code
- **Mode**: Standard B (interim; plan stays `active/`)
- **Scope**: working tree at `c9740a7` (index empty): 28 modified tracked paths (unstaged diff SHA256 prefix `48d3ea3d1aa4a3bf` at capture) plus untracked `src/components/Common/HoldButton.vue` (`dc7bc62ffeaecb0a`), `tests/e2e/fixtures/hold-to-confirm.ts` (`d7e634df6e1691ea`), `tests/unit/components/HoldButton.spec.js` (`304e2d2b400b95ff`), `tests/unit/helpers/hold.js` (`74dc8137bdcbe22e`) — HoldButton, shared completion in-flight guard, Done/Quick clean migration, E2E hold helper and spec migration, docs, l10n
- **Owning plan**: [CS-PLAN-084](../../plans/active/cs-plan-084-family-trial-ux-baseline.md)

## Findings

| ID | Severity | Area | Issue | Status |
|----|----------|------|-------|--------|
| E4-01 | minor | `HoldButton.vue` hint | The short-tap hint sat below the button; `.room-card` clips with `overflow: hidden` and Quick clean is the card's last row, so the Dashboard hint was invisible | fixed (hint above the button) |
| E4-02 | minor | `completion-api.ts` `completeWithOptionalDialog` | `isVisible({ timeout })` does not wait: a dialog opening after an asynchronous identity read was skipped and the helper hung on the response (pre-existing race, helper reworked this round); the response matcher also accepted an earlier still-pending completion (`today-view` two-completion test reproduced it after the first fix) | fixed (race the dialog against the next `/complete` request registered before the hold; return its response; family-core-ux mobile dialog assertion made unconditional) |
| RF-084-03 | minor | `RoomCard.vue` quick clean | A failed quick clean only logs; no error toast (pre-existing, against design.md §5) | routed → plan §9, WP-F error-toast helper |

## Verified Non-Findings

- `.hidden-visually` (the `aria-describedby` target) is defined by Nextcloud core (`core/css/server.css`), so the description stays off-screen.
- A concurrent `completeRoomTask` call with different `options` joins the pending request: the first command is authoritative and a second one would be a 409 against the fulfilled occurrence anyway.
- `HoldButton.vue` is 399 lines, of which ≈170 are its own scoped styles; template + script ≈ 230 lines. Splitting styles or the gesture into a composable would add an abstraction with a single consumer.
- No authorization, API, schema or logging change; the store guard only joins client calls; server idempotency (command UUID) is unchanged.

## Gates

Jest 120 / 1738; ESLint, Stylelint, `l10n:check-keys` (1281), `backlog:check`, `docs:encoding`, `git diff --check` green; bundle rebuilt after the fixes. Browser on the final bundle, no retries: chromium-serial-db 33/33 (calm-core-ui, family-core-ux, family-rhythm, today-view) and 17/17 (task-completion-full-flow, core-household-journey, dashboard, workflow-state-reconciliation); chromium 42/42 (core-functionality, task-completion, dashboard-navigation, room-detail, task-manager, overdue-task-claiming, color-contrast light+dark); each run incl. 3 setup.

## Outcome

**PASS-WITH-FIXES (interim).** Two minor findings fixed in-round; RF-084-03 routed to WP-F.

## Round 5

# Code Review: CS-PLAN-084 Round 5 (WP-F Feedback and Diagnosable Errors) — Interim Standard B

- **Review Date**: 2026-10-14
- **Reviewer**: Claude Code
- **Mode**: Standard B (interim; plan stays `active/`)
- **Scope**: working tree at `319022a` (index empty): 24 modified tracked paths (unstaged diff SHA256 prefix `65bc77886396eb42` at capture) plus untracked `src/utils/errorToast.js` (`14ad645ec6b08eb8`), `src/utils/haptics.js` (`fc045b449e9f4827`), `tests/unit/utils/errorToast.spec.js` (`9929ecd52e4aeb1a`), `tests/unit/utils/haptics.spec.js` (`72e3bae54a8920ef`) — haptics, snackbar check mark, dead streak branch removal, error-reference toasts, Quick clean failure toast, docs, l10n, TECH-TRANSPORT-001 fold-in
- **Owning plan**: [CS-PLAN-084](../../plans/active/cs-plan-084-family-trial-ux-baseline.md)

## Findings

| ID | Severity | Area | Issue | Status |
|----|----------|------|-------|--------|
| E5-01 | minor | `errorToast.js` | The `escape: false` rationale claimed toasts render plain text; `@nextcloud/dialogs` 7.2.0 decodes its text once through `innerHTML` → `innerText`, so it expects escaped input. The code is correct (message escaped by the caller's `t()`, reference limited to `[A-Z0-9_ ]`), but the unstated contract invited a raw-text caller | fixed (comment + JSDoc + design.md row state "message is always `t()` output") |

## Verified Non-Findings

- Every `showErrorWithReference` call site passes `t()` output; `errorReference` never echoes free text (codes `^[A-Z][A-Z0-9_]{1,63}$`, integer status, `NETWORK`).
- The Insights 409 branches keep their specific "changed since it was displayed" message without a reference; the condition is already explicit.
- The haptic pulse rides the completion event only (no pulse for medals/titles, none for a short tap — E2E-recorded); `navigator.vibrate` absence/refusal/throw are no-ops.
- The check mark is `aria-hidden`; the snackbar's live text stays "Task completed"; reduced motion disables the pop.
- Removing the streak branch drops no reachable behavior: the completion event never carried `currentStreak`.

## Gates

Jest 122 / 1756 (after the fix), `test:noise` clean; ESLint, Stylelint, `l10n:check-keys` (1283), `backlog:check`, `docs:encoding`, `git diff --check` green. Browser (pre-fix bundle; the fix is comment/doc only), no retries: chromium 42/42; chromium-serial-db 46/47 with one seed-fixture preflight failure before any assertion (TECH-TRANSPORT-001, folded in), calm-core-ui 9/9 isolated.

## Outcome

**PASS-WITH-FIXES (interim).** One minor finding fixed in-round; RF-084-03 resolved; no open findings from this round.

## Round 6

# Code Review: CS-PLAN-084 Round 6 (WP-C Clickable Dashboard + RF-084-02) — Interim Standard B

- **Review Date**: 2026-10-14
- **Reviewer**: Claude Code
- **Mode**: Standard B (interim; plan stays `active/`)
- **Scope**: working tree at `c41b103` (index empty): 19 modified tracked paths (unstaged diff SHA256 prefix `43f1383d57671c18` at capture), no untracked files — QuickStats tiles, stretched-link RoomCard, Dashboard house read and `showRooms`, TaskManager `?status=` preset, `RoomTaskService::getRoomTaskStats` (RF-084-02), Jest/E2E/PHPUnit tests, ui-use-cases router contract, API stats semantics, TECH-TRANSPORT-001 fold-in
- **Owning plan**: [CS-PLAN-084](../../plans/active/cs-plan-084-family-trial-ux-baseline.md)

## Findings

| ID | Severity | Area | Issue | Status |
|----|----------|------|-------|--------|
| C6-01 | minor | `Dashboard.vue` | `showRooms()` was inserted between the "My Private Tasks" docblock and `handleTogglePrivateFilter`, so that docblock described the wrong method | fixed (method moved above the docblock) |
| C6-11 | minor | `family-core-ux.spec.ts` | The overdue-list assertion compared the row count with a count sampled once (`toHaveCount(await …count())`) | fixed (`[data-testid="task-item"]:not(.overdue)` has count 0) |

## Verified Non-Findings

- `getRoomTaskStats` keeps the visibility filter before counting; the new path only reads (one batched selection, `statusFor`), no authorization or write change; `houseIdForRoom` adds two cheap lookups on a per-room stats read.
- The stretched link nests no interactive control inside a link; Quick clean stays a sibling button above it (`z-index`); the card lost its `div @click` pseudo-button.
- In-page room tiles are buttons instead of the plan's RouterLinks (recorded as an implementation decision in the plan): a hash route would need a global window-scrolling `scrollBehavior` that does not fit Nextcloud's inner scroll container.
- `?status=` only accepts the three listed values; `completed` stays for WP-D's on-demand read.
- The keyboard E2E focuses the room link programmatically and proves Enter activation; Tab reachability of row controls is covered by the existing family-core-ux traversal pattern.

## Gates

Jest 122 / 1760 (Dashboard spec rerun after the fix), `test:noise` clean; ESLint, Stylelint, `l10n:check-keys`, `composer cs:check` (0/413), `docs:encoding`, `backlog:check` green; bundle rebuilt. PHPUnit unit 1385, integration 611. Browser, no retries: chromium 40/40; chromium-serial-db 28/29 with one seed `fetch failed` before any assertion (TECH-TRANSPORT-001), room-management 10/10 isolated; family-core-ux 12/12 after the fix.

## Outcome

**PASS-WITH-FIXES (interim).** Two minor findings fixed in-round; RF-084-02 resolved; no open findings from this round.
