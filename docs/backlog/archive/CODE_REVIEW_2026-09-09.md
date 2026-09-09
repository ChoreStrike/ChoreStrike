# Code Review 2026-09-09 — CS-PLAN-064 WP5 Backend Slice (Interim Standard B)

> **Tier:** Standard B (code), interim · **Outcome:** `PASS-WITH-FIXES`
> **Owning plan:** [`docs/plans/active/cs-plan-064-wp5-completion-ledger-full-undo.md`](../../plans/active/cs-plan-064-wp5-completion-ledger-full-undo.md) (stays `active/`)
> **Reviewer:** Kilo (review skill, no subagents)

## Scope & Base

- Uncommitted working-tree backend slice of CS-PLAN-064 (implementation
  order steps 1–5 + unit-test round): 30 modified tracked files
  (+805/−2280), 3 untracked new files (`Version1029Date20260906000000.php`,
  `CompletionService.php`, `CompletionServiceTest.php`).
- Base: `master` @ `ad3e1fbcfe64192f1258a67c41e32584a6da6d87`; index clean
  at capture; pre-fix unstaged-diff SHA256
  `1222C2CA70ABD2E8167A982FAED009969EAEE3F46D3C62142BD457B8411C65D1`;
  untracked content hashes `36859095…D76D7` / `C2D24156…13B20` /
  `4399C0FF…F8AC`.
- Unrelated dirty file preserved and excluded from the commit:
  `docs/plans/done/cs-plan-063-wp4-atomic-task-creation-assignment.md`
  (pre-existing mojibake repair).
- Steps 6–10 (real-DB integration suites, frontend reconciliation/undo
  queue, E2E re-pins, ADR-011/durable docs, backlog dispositions) verified
  **not started** — the review is interim and makes no completion claim.

## Verified Against the Plan

- Effect record (D1): ledger row carries `occurrence_due`, full
  `before_state`, `deltas`; dead columns dropped; serialization contract
  pinned by `testBeforeStateSerializationContract_IsPinned`.
- Lock order `room_task → room → house_user` (ADR-011 wording, D12
  definition-no-lock) implemented in both aggregates and pinned by the
  undo mock-order test; `RoomMapper::lockForUpdate` mirrors the
  `RoomTaskMapper` platform split (SQLite self-assignment UPDATE).
- Occurrence gates before any mutation: inactive → 409 (D4), day-duplicate
  → 403 over non-undone rows (D11), visibility → 404 anti-enumeration
  (ADR-010); membership re-resolved under the lock (CR-SVC-10 pattern).
- D6 first caller of `resetProgress` inside the transaction; undo restores
  the snapshotted rows via the new `restoreProgress` helper.
- D7 post-commit log-and-degrade boundary: committed completion always
  answers success; `rewards.degraded` + stable-key warning; pinned by test.
- D9 teamwork fold: one locked house-user write; second unlocked RMW
  deleted; frozen formulas untouched (`calculateTeamworkBonus` unchanged).
- D3 undo: newest-revertible lookup, completer-or-owner gate, under-lock
  re-check no-op, restore-never-recalculate, `titlesAdded` LIFO removal,
  zero-write idempotent success.
- D5 routes: `room_task#undo` added; `task#uncomplete` +
  `room_task#reactivate` pruned with controller/service/mapper/store seams
  on the backend; `RouteMatrixTest` pin updated (70 → 69) + no-reintroduce
  guard added.
- D11 filters on all three ledger reads + mapper-level query pins.

## Findings

IDs RF-01…RF-11 are canonical in the plan's `## 11. Review Findings`;
open items are plan-owned and block the `done/` move.

| ID | Sev | Where | Issue | Status |
|----|-----|-------|-------|--------|
| RF-06 | block | `Version1029Date20260906000000.php` | Dead-column drops missed dependent indexes `idx_team_complete`/`idx_claimed_complete` → MariaDB 1072; fresh install dead; all Docker gates unrunnable | Fixed in review (user-authorized migration fix) |
| RF-09 | major | integration tests | 44 errors: 6 raw ledger seeders missing NOT NULL `before_state`/`deltas` (1364); 6 stale controller construction sites; `TaskLifecycleVisibilityApiTest` on pruned endpoints | Fixed in review (fixtures seeded; constructors rewired; spec re-pinned to undo incl. once-task `is_active` restore) |
| RF-07 | major | `l10n/` | Two new `t()` strings missing from en/de/pot (rule 6) | Fixed in review |
| RF-01 | major | frontend | §3.5 round pending; store calls pruned routes; Jest `apiContract` red | Open (plan-owned) |
| RF-02 | major | integration suites | Step 6 real-DB exit-gate suites missing | Open (plan-owned) |
| RF-03 | major | E2E | Step 8 re-pins pending | Open (plan-owned) |
| RF-04 | major | durable docs | Step 10 pending (ADR-011 already referenced by code docblocks) | Open (plan-owned) |
| RF-10 | minor | `GameLogicService` | Legacy self-insert path not NOT-NULL-schema-safe | Fixed in review (empty-JSON defaults) |
| RF-05 | minor | `CompletionService::undo` | Deleted-completer membership → 500; completion-worded 403 message in undo context | Open (auth-adjacent, not auto-fixed) |
| RF-08 | minor | `CompletionService` | Unused `AuthorizationService` injection | Fixed in review |
| RF-11 | nit | docblocks | Stale `RoomService::completeRoomTask` references | Fixed in review |

## Questions & Decisions

Batched structured request, both answered with the recommended option:

1. **RF-06 handling** → fix now in `Version1029` (guarded `dropIndex`
   before column drops), rebuild Docker, rerun backend gates.
2. **Commit stance (interim, red Jest by pending frontend round)** →
   interim reviewed-package commit: backend slice + review evidence in one
   scoped commit; RF-01 recorded as plan-owned finding; plan stays
   `active/`.

## Gates (final state)

| Gate | Result |
|------|--------|
| Docker fresh install + migration chain | Green (after RF-06 fix) |
| PHPUnit unit (Docker) | Green — 844 tests, 2505 assertions |
| PHPUnit integration (Docker) | Green — 278 tests, 883 assertions, 10 skips (pre-existing documented inventory, unchanged) |
| `composer cs:check` | Green (scoped fix of 4 reviewed files: PHPDoc alignment, CRLF→LF) |
| ESLint (`npm run lint`) | Green |
| Jest (`npm test`) | 814/815 — `apiContract.spec.js` red (RF-01, plan-owned) |
| `l10n:check-keys` | Green |
| Playwright | Not run — E2E re-pins are step 8 (RF-03) |

## Commit

One scoped conventional commit on `master` (interim reviewed package):
backend implementation slice + review fixes + plan/tracking evidence.
Excluded: the unrelated `cs-plan-063` mojibake repair (left dirty).
