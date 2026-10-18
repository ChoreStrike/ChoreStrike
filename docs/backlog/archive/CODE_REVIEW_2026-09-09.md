# Code Review 2026-09-09 — CS-PLAN-064 WP5 Backend Slice (Interim Standard B)

> **Tier:** Standard B (code), interim · **Outcome:** `PASS-WITH-FIXES`
> **Owning plan:** [`docs/plans/done/cs-plan-064-wp5-completion-ledger-full-undo.md`](https://github.com/ChoreStrike/ChoreStrike/blob/c03e940d071251ed62cedbe9ef4a7a2640d0f294/docs/plans/done/cs-plan-064-wp5-completion-ledger-full-undo.md) (interim — stayed `active/` at review time; completed later on 2026-09-09, link repaired)
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

---

# Code Review 2026-09-09 (2nd) — CS-PLAN-064 WP5 Step-6 Round (Interim Standard B)

> **Tier:** Standard B (code), interim · **Outcome:** `PASS`
> **Owning plan:** [`docs/plans/done/cs-plan-064-wp5-completion-ledger-full-undo.md`](https://github.com/ChoreStrike/ChoreStrike/blob/c03e940d071251ed62cedbe9ef4a7a2640d0f294/docs/plans/done/cs-plan-064-wp5-completion-ledger-full-undo.md) (interim — stayed `active/` at review time; completed later on 2026-09-09, link repaired)
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

---

# Code Review 2026-09-09 (3rd) — CS-PLAN-064 WP5 Steps 7–10 Round (Final Standard B)

> **Tier:** Standard B (code), final · **Outcome:** `PASS-WITH-FIXES`
> **Owning plan:** [`docs/plans/done/cs-plan-064-wp5-completion-ledger-full-undo.md`](https://github.com/ChoreStrike/ChoreStrike/blob/c03e940d071251ed62cedbe9ef4a7a2640d0f294/docs/plans/done/cs-plan-064-wp5-completion-ledger-full-undo.md) (moved `active/ → done/` by this review)
> **Reviewer:** Kilo (review skill, no subagents)

## Scope & Base

- The uncommitted steps 7–10 round of CS-PLAN-064 plus whole-plan
  completion verification: frontend reconciliation-first completion and
  one-request undo (RF-01), queued `UndoSnackbar` (UX-015), E2E re-pins +
  new full-flow/today-view journeys (RF-03), ADR-011 + durable-doc
  integration and backlog dispositions (RF-04), and the undo-payload
  `completerUserId` + post-undo `weeklyGoal` amendment — 33 modified
  tracked files (+1111/−390) and 1 untracked new file (ADR-011).
- Base: `master` @ `23936860f2c969e2c327248c253ae1425ac8b115`; index clean
  at capture; pre-review unstaged binary-diff git-hash
  `ae64724f5707a2df7966622477a713786132d371`; untracked ADR-011 SHA256
  `64AC9F7DC8A8B3347F75AD6333500DB67B4ADE32B0C943EF0787037A2B671128`.
  No unrelated dirty files in the tree.

## Verified Against the Plan

- RF-01 (resolved): `completeRoomTask` reconciles roomTasks+dailyTasks
  (cached assignees preserved), rooms (`reconcileRoom`), game (weekly
  goal), user (`applyRewardTotals`), and the coin wallet
  (`reconcileCoins`) from the one payload BEFORE any event — ordering
  pinned by the `stateAtEmit` Jest spy; `undoRoomTaskCompletion` is one
  `POST /room-tasks/{id}/undo`; `uncompleteRoomTask`/
  `reactivateRoomTask` deleted (prune pinned); completer-identity gating
  on the undo totals (`completerUserId`) pinned for the owner-undoes-
  child's-completion case; `apiContract.spec.js` green.
- UX-015 (resolved): `UndoSnackbar` is a queued list — per-entry
  countdown/dismissal, cap 3 with oldest eviction, `role="status"` +
  `aria-live`, localized labels, 44 px class pinned; copy unchanged.
- RF-03 (resolved): lifecycle spec drives the one aggregate (exact
  `nextDue`/`lastCompleted`/`isActive` inverse + idempotent second-undo
  no-op); interception spec matches the undo route; new full-flow
  `complete → undo → reload` state-equality journey (runtime-resolved
  Kitchen id, coin wallet and API ground-truth assertions); new
  today-view queue journey (two entries, independent undos, post-reload
  restoration). Incidental pre-existing serial-pool reds cured in-round:
  templates re-pin to the CS-PLAN-063 aggregate shape,
  `createRoomViaSettings` create-if-missing, room-management
  `seededData` isolation.
- RF-04 (resolved): ADR-011 written (effect-record JSON contract incl.
  RF-12 keys, lock order + deadlock argument, gates, D11 semantics, D7
  boundary, D3/D8 policy, D12 deviation, frozen formulas); ADR-007
  annotated; ADR index; development.md locking section; api/README
  complete/undo contracts + route-count fix; task-model-and-sync
  completion/undo model; ADR-010/ui-use-cases/design.md/security.md/
  release-test-plan integrated; dispositions: CR-WF-02 + UX-015 archived,
  CR-053-20/CR-SVC-15/CR-SVC-40 WP5 slices annotated, CR-WF-04 +
  BUG-E2E-005 fold-ins, CS-PLAN-058 §9 WP5 row updated.
- Completion gates: every §8 acceptance criterion re-checked against the
  tree; RF-01..RF-12 all resolved; manifest + 2026-09-09 amendment
  reconciled with the working tree; durable knowledge lives in
  permanent docs (plans are temporary).

## Findings

| ID | Sev | Where | Issue | Status |
|----|-----|-------|-------|--------|
| — | minor | plan §11 RF-04 text | Resolution cited a "CR-SVC-64" backlog item that was never created (the title-celebration dead-event finding was folded into CR-WF-04 — correct routing; stale plan text only) | Fixed in review (plan text corrected in place) |
| — | nit | `NotAuthorizedState.vue:73` | Sole stylelint warning (`margin-left` vs `margin-inline`) — pre-existing in an unmodified file | No action — already tracked as TECH-LINT-007 |

## Questions & Decisions

None required — no security/auth, behavior-changing, architectural, or
migration question arose; all twelve decision-register entries (D1–D12)
stand ratified and implemented.

## Gates (final state, rerun by the review)

| Gate | Result |
|------|--------|
| Jest (`npm test`) | Green — 820/820 (81 suites) |
| PHPUnit unit (Docker) | Green — 847 tests, 2523 assertions |
| PHPUnit integration (Docker) | Green — 290 tests, 1041 assertions, 10 skips (pre-existing documented inventory) |
| `composer cs:check` | Green (0 of 280 files) |
| ESLint (`npm run lint`) | Green |
| stylelint | 0 errors (1 pre-existing TECH-LINT-007 warning, unmodified file) |
| Playwright (full pool, run twice) | 385 passed / 0 failed / 26 skips — all 26 verified as the pre-existing data-conditional guard inventory; every new CS-PLAN-064 journey passed in both runs |
| `l10n:check-keys` · `docs:encoding` · `backlog:check` · `test:noise` | Green |
| webpack production build | Green |
| `git diff --check` | Clean |

## Commit

One scoped conventional review commit on `master` (final reviewed
package): steps 7–10 implementation + ADR-011/durable docs + plan
`active/ → done/` transition + link repairs + tracking/archive updates.
No unrelated changes present or included.
