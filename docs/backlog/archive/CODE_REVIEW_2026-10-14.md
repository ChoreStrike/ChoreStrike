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
