# Code Review 2026-09-09 â€” CS-PLAN-064 WP5 Backend Slice (Interim Standard B)

> **Tier:** Standard B (code), interim Â· **Outcome:** `PASS-WITH-FIXES`
> **Owning plan:** [`docs/plans/active/cs-plan-064-wp5-completion-ledger-full-undo.md`](../../plans/active/cs-plan-064-wp5-completion-ledger-full-undo.md) (stays `active/`)
> **Reviewer:** Kilo (review skill, no subagents)

## Scope & Base

- Uncommitted working-tree backend slice of CS-PLAN-064 (implementation
  order steps 1â€“5 + unit-test round): 30 modified tracked files
  (+805/âˆ’2280), 3 untracked new files (`Version1029Date20260906000000.php`,
  `CompletionService.php`, `CompletionServiceTest.php`).
- Base: `master` @ `ad3e1fbcfe64192f1258a67c41e32584a6da6d87`; index clean
  at capture; pre-fix unstaged-diff SHA256
  `1222C2CA70ABD2E8167A982FAED009969EAEE3F46D3C62142BD457B8411C65D1`;
  untracked content hashes `36859095â€¦D76D7` / `C2D24156â€¦13B20` /
  `4399C0FFâ€¦F8AC`.
- Unrelated dirty file preserved and excluded from the commit:
  `docs/plans/done/cs-plan-063-wp4-atomic-task-creation-assignment.md`
  (pre-existing mojibake repair).
- Steps 6â€“10 (real-DB integration suites, frontend reconciliation/undo
  queue, E2E re-pins, ADR-011/durable docs, backlog dispositions) verified
  **not started** â€” the review is interim and makes no completion claim.

## Verified Against the Plan

- Effect record (D1): ledger row carries `occurrence_due`, full
  `before_state`, `deltas`; dead columns dropped; serialization contract
  pinned by `testBeforeStateSerializationContract_IsPinned`.
- Lock order `room_task â†’ room â†’ house_user` (ADR-011 wording, D12
  definition-no-lock) implemented in both aggregates and pinned by the
  undo mock-order test; `RoomMapper::lockForUpdate` mirrors the
  `RoomTaskMapper` platform split (SQLite self-assignment UPDATE).
- Occurrence gates before any mutation: inactive â†’ 409 (D4), day-duplicate
  â†’ 403 over non-undone rows (D11), visibility â†’ 404 anti-enumeration
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
  on the backend; `RouteMatrixTest` pin updated (70 â†’ 69) + no-reintroduce
  guard added.
- D11 filters on all three ledger reads + mapper-level query pins.

## Findings

IDs RF-01â€¦RF-11 are canonical in the plan's `## 11. Review Findings`;
open items are plan-owned and block the `done/` move.

| ID | Sev | Where | Issue | Status |
|----|-----|-------|-------|--------|
| RF-06 | block | `Version1029Date20260906000000.php` | Dead-column drops missed dependent indexes `idx_team_complete`/`idx_claimed_complete` â†’ MariaDB 1072; fresh install dead; all Docker gates unrunnable | Fixed in review (user-authorized migration fix) |
| RF-09 | major | integration tests | 44 errors: 6 raw ledger seeders missing NOT NULL `before_state`/`deltas` (1364); 6 stale controller construction sites; `TaskLifecycleVisibilityApiTest` on pruned endpoints | Fixed in review (fixtures seeded; constructors rewired; spec re-pinned to undo incl. once-task `is_active` restore) |
| RF-07 | major | `l10n/` | Two new `t()` strings missing from en/de/pot (rule 6) | Fixed in review |
| RF-01 | major | frontend | Â§3.5 round pending; store calls pruned routes; Jest `apiContract` red | Open (plan-owned) |
| RF-02 | major | integration suites | Step 6 real-DB exit-gate suites missing | Open (plan-owned) |
| RF-03 | major | E2E | Step 8 re-pins pending | Open (plan-owned) |
| RF-04 | major | durable docs | Step 10 pending (ADR-011 already referenced by code docblocks) | Open (plan-owned) |
| RF-10 | minor | `GameLogicService` | Legacy self-insert path not NOT-NULL-schema-safe | Fixed in review (empty-JSON defaults) |
| RF-05 | minor | `CompletionService::undo` | Deleted-completer membership â†’ 500; completion-worded 403 message in undo context | Open (auth-adjacent, not auto-fixed) |
| RF-08 | minor | `CompletionService` | Unused `AuthorizationService` injection | Fixed in review |
| RF-11 | nit | docblocks | Stale `RoomService::completeRoomTask` references | Fixed in review |

## Questions & Decisions

Batched structured request, both answered with the recommended option:

1. **RF-06 handling** â†’ fix now in `Version1029` (guarded `dropIndex`
   before column drops), rebuild Docker, rerun backend gates.
2. **Commit stance (interim, red Jest by pending frontend round)** â†’
   interim reviewed-package commit: backend slice + review evidence in one
   scoped commit; RF-01 recorded as plan-owned finding; plan stays
   `active/`.

## Gates (final state)

| Gate | Result |
|------|--------|
| Docker fresh install + migration chain | Green (after RF-06 fix) |
| PHPUnit unit (Docker) | Green â€” 844 tests, 2505 assertions |
| PHPUnit integration (Docker) | Green â€” 278 tests, 883 assertions, 10 skips (pre-existing documented inventory, unchanged) |
| `composer cs:check` | Green (scoped fix of 4 reviewed files: PHPDoc alignment, CRLFâ†’LF) |
| ESLint (`npm run lint`) | Green |
| Jest (`npm test`) | 814/815 â€” `apiContract.spec.js` red (RF-01, plan-owned) |
| `l10n:check-keys` | Green |
| Playwright | Not run â€” E2E re-pins are step 8 (RF-03) |

## Commit

One scoped conventional commit on `master` (interim reviewed package):
backend implementation slice + review fixes + plan/tracking evidence.
Excluded: the unrelated `cs-plan-063` mojibake repair (left dirty).

---

# Code Review 2026-09-09 (2nd) — CS-PLAN-064 WP5 Step-6 Round (Interim Standard B)

> **Tier:** Standard B (code), interim · **Outcome:** `PASS`
> **Owning plan:** [`docs/plans/active/cs-plan-064-wp5-completion-ledger-full-undo.md`](../../plans/active/cs-plan-064-wp5-completion-ledger-full-undo.md) (stays `active/`)
> **Reviewer:** Kilo (review skill, no subagents)

## Scope & Base

- The uncommitted step-6 round of CS-PLAN-064: RF-02 real-DB suites, the
  RF-05 undo deleted-completer edge, the RF-12 `lastCleaned`/
  `lastActivity` snapshot/restore — 8 modified tracked files
  (+538/-151 incl. docs) and 2 untracked new suites
  (`CompletionConcurrencyTest.php`, `CompletionUndoStateEqualityTest.php`).
- Base: `master` @ `61c328027937aed9d121cbd9106535bb61138ee7`; index clean
  at capture; pre-review unstaged-diff SHA256
  `008E502B81DEC6E10319D627EA0E02FC62574E218116B5BE12713EE7EED752D5`;
  untracked content hashes `67B1A3BB…5BEA0` / `79D28A97…C785E4`.
- Preserved and excluded from the review commit (committed separately on
  explicit user request): `docs/plans/done/cs-plan-063-…md` mojibake
  repair (pure UTF-8 fix, 133/133 lines, no content change).
- Steps 7–10 (frontend reconciliation/undo queue, E2E re-pins, durable
  docs/dispositions) verified not started — interim, no completion claim.

## Verified Against the Plan

- RF-02 (resolved): exit-gate exact-persisted-state equality over
  once/recurring-dynamic/static/flex permutations + overdue takeover
  (claim fields, owner actor) + D11 same-day re-complete roundtrip (two
  ledger rows, one revertible at a time, final state = initial snapshot)
  + title LIFO (only the own occurrence's unlocks removed); deterministic
  two-connection races (same-room room-lock freshness, degraded-retry
  day-duplicate, concurrent-undo no-op loser) + a true two-process
  same-room race; inactive-409 zero-write; unit-level roundtrip case.
- RF-05 (resolved): actor gate null-safe (`DoesNotExistException` ?
  clean 403 "The completer is no longer a member of this house");
  `lockCompleterOrForbidden` context-worded for undo; l10n en/de/pot;
  two unit pins (deleted completer, under-lock vanish).
- RF-12 (resolved): `lastCleaned`/`lastActivity` in `before_state` +
  restore (streak-absence correctness); serialization contract re-pinned.
- `IntegrationTestCase` raw house-user seeds now match production shape
  (`unlocked_titles` JSON `[]`, not NULL).

## Findings

None new. Plan-owned open items unchanged: RF-01 (frontend, Jest
`apiContract` red by design), RF-03 (E2E re-pins), RF-04 (durable docs &
dispositions) — all step 7–10 scope, all blocking `done/`.

## Gates (final state)

| Gate | Result |
|------|--------|
| PHPUnit unit (Docker) | Green — 847 tests, 2523 assertions |
| PHPUnit integration (Docker) | Green — 290 tests, 1041 assertions, 10 skips (pre-existing documented inventory; MySQL-only race tests ran) |
| `composer cs:check` | Green (0 of 280 files) |
| ESLint (`npm run lint`) | Green |
| Jest (`npm test`) | 814/815 — `apiContract.spec.js` red (RF-01, plan-owned; no new skips) |
| `l10n:check-keys` | Green |
| `backlog:check` | Green |
| Playwright | Not run — E2E re-pins are step 8 (RF-03) |

## Commit

One scoped conventional review commit on `master`: step-6 implementation +
tests + l10n + plan/tracking/review evidence. Separately (explicit user
request, outside the review authorization): the `cs-plan-063` mojibake
repair as its own docs commit.
