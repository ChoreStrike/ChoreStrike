# Code Review: CS-PLAN-071 WP0 — Final Standard B (Write/Identity Foundation)

**Review Date**: 2026-09-19
**Reviewer**: Main agent (Kilo, skills: review / plan-management / git-workflow)
**Tier**: Standard B (code, final) — whole owning plan: file manifest, acceptance criteria, required test evidence, durable-doc integration, implementation history
**Scope**: the complete uncommitted WP0 package of `docs/plans/active/cs-plan-071-wp0-write-identity-foundation.md` — 81 modified tracked files (+2598/−876 pre-fix) + 17 untracked files (11 `lib/`, 6 `tests/`)
**Base Commit**: `master` @ `b6ec6b68e6e6cd58c718aa05f588abe1d18ae18b`

---

## Scope And Capture

- Branch `master`, HEAD `b6ec6b68`, index empty (no staged changes) at capture.
- Pre-review unstaged-diff fingerprint (SHA256 of `git hash-object` over
  `git diff`): `2fa60541027d422869891a0eac84e774bb11f102`.
- Sorted untracked paths with per-file SHA256 at capture:

```text
B832993AEAC416827315F669A7E34DCDB7B989D608CE8DAB176C030BAC5A12BA  lib/Db/CommandReceipt.php
ECE56FAC3221EBE71A91DBBDE1158F9CC24561F9F1DA1A3C29AD989110AB24F4  lib/Db/CommandReceiptMapper.php
7197AAF40D1326226B13B23A4732A2682DCCFDA9BD06BC02F74A1C93A9F86A3B  lib/Db/Wallet.php
8100FC747BD7BEEFCA36646A051A374A6C6AD7028BB235248EFE03EB2F44C94E  lib/Db/WalletEntry.php
BE414918ADF2E80D13D44DDEAA9B16560A58D88253BBA37741C9970489E48969  lib/Db/WalletEntryMapper.php
7339E0CFA6086BAA3B1DD4573FC76EA16BED369B2CD7B8E5D6E9E3252E1754D1  lib/Db/WalletMapper.php
D1AEBD9AAA1A40466815CE168A3AEEC06482F582FE631DFAA83063257BAEC5B4  lib/Listener/UserDeletedListener.php
908F227748BA52A4077A6A7C31C1AA17EF8D74ED459274C49AD077F3C67E053C  lib/Migration/Version1030Date20260918000000.php
A4DB1F57089BAE1BA31863F40CEFA6E40A76EB11CF0358E7F6EDFC04A57AE067  lib/Service/AccountErasureService.php
97EC732DAEE98414D95DA654D14B8B1D11D1735906B4FAEEADCDD4E669891959  lib/Service/CommandService.php
8ABE9A63A9BAF25C6E5C238115CBF94B326F7394216CB420BBC8679DF9432188  lib/Service/WalletService.php
57532B789C57823F2017C56158190FC235DC4178C8129BB2932C42FE674AE0AF  tests/integration/Db/FamilyRhythmSchemaTest.php
D872CE45F940F646A75338D64772F066E34DB77C1C4D20875DDDF697996FE191  tests/integration/Service/AccountErasureTest.php
A15C08EF88F54F4BC84B1E8173C3EACD009CF7CF6E40D50699979FEB311AB108  tests/integration/Service/CommandServiceTest.php
80F4EC4D3B1B0AE6FF139ED7D36E745417959425817A164C267AB02BA25677BF  tests/integration/Service/HouseMutationConcurrencyTest.php
7292BE995D1E8AE78A1DE087F1945C1DD194796B9187CB1AE43CB2C321405A13  tests/unit/Service/AccountErasureServiceTest.php
7681D38FCA9F93801E8BF09B5A73F84EA2FF84586CAE20399CC5912F92BF6397  tests/unit/Service/WalletServiceTest.php
```

  (`lib/Service/CommandService.php` changed post-capture by the RF-071-01
  docblock fix; all other untracked files are committed byte-identical to
  these hashes.)
- Dirty-tree triage: the three deleted `docs/plans/{new,active,onhold}/.gitkeep`
  are unrelated pre-existing changes excluded from both 2026-09-18 review
  commits — preserved unstaged and excluded again. Every other dirty path
  matches the plan's §5 manifest (plus the manifest-anticipated test-seam
  updates).
- Owning plan: explicitly given — CS-PLAN-071 (active, final Standard B
  requested by its §6 step 10). No ambiguity; the parent CS-PLAN-070 stays
  active (WP1+ open).

## Review Verification

Final Standard B depth (whole owning plan, not just the diff):

- **Manifest completeness:** every §5 `new:` file exists; every `modified:`
  file is dirty except the five audit-confirmed no-change services
  (`SchedulePlanService`, `TaskCreationService` — already house-first;
  `GameLogicService`, `MedalService`, `TitleService` — caller-inherited via
  the `CompletionService` aggregate), each recorded with its entry lock in
  the development.md WP0 writer/lock audit table. `appinfo/routes.php`,
  `docs/api/README.md`, `RouteMatrixTest` untouched (no new routes, D6).
- **Architecture:** thin controllers unchanged; all logic in services;
  persistence in mappers/entities; `createNamedParameter`/query-builder
  everywhere (the only string-built statement is the constant-integer
  `PRAGMA busy_timeout`); `TransactionExecutor` remains the sole transaction
  owner (the mapper-owned checklist toggle transaction correctly moved to
  `ChecklistService::toggleLocked` participation); file/method size limits
  respected; `WalletService::ensureBinding` deduplicates the binding block.
- **Authorization/security:** no route surface change; erasure is
  event-only (never an app API); house-membership/owner gates intact and
  re-evaluated on fresh locked entities; `hash_equals` for payload-hash
  comparison; reason-scoped 409 mapping (only unique-constraint violations;
  transient failures stay retryable); no tokens/raw bodies in receipts; no
  secrets/PII in logs (erasure logs identifiers only — houseId/walletId/
  houseUserId, no UIDs beyond the platform event); wallet identity grants no
  access and leaks through no endpoint (`HouseUser::jsonSerialize` excludes
  `walletId`; no controller serializes `Wallet`).
- **Lock order:** the frozen house-first order or a documented subsequence
  across the full audit table; no writer acquires the house lock after a
  child lock; bulk writes ascending-id; no notifications inside
  transactions (post-commit boundary preserved in `CompletionService`).
- **Schema:** Version1000/1004 in-chain edits + Version1030 match the
  parent §3.2 contracts exactly (portable unique indexes, nullable actor
  refs, `deleted_token` re-keyed `uniq_rt`, bigint minor units); fresh-install
  policy respected (no upgrade paths/shims).
- **Erasure (Q-070-02):** full WP0-stage purge matrix with per-house failure
  containment and summary logging (single-shot event), departed-only wallet
  sweep, UID-keyed minigame-score sweep, receipt/completion actor
  anonymization, no-rebinding via internal bigint IDs, "Former member"
  placeholder with l10n artifacts in the same change;
  `chk_progress.checked_by_user_id` documented as a pre-existing dangling-id
  pattern adopted by WP3 (internal id, not rebinding-capable).
- **Tombstones (Q-070-01):** evidence retention (`completed_tasks` never
  touched by structural cascades), live-row-only bulk tombstones, active-read
  filters across floor/room/room-task reads, history/activity reads resolve
  through tombstoned rows, undo authorized across tombstones, no production
  hard-delete outside the test-mode reset.
- **D1/D2 boundary:** wallets dormant (zero balances, no API, no
  `wallet_entries` runtime writer); legacy coin writers untouched; freeze/
  title-pack columns retained for WP4.
- **Tests:** T0 file set equals the plan §4 assignment; regression net
  intact — `WriteBatchRegressionTest` rewritten to the NEW Q-70-01 contract
  with sibling-house pins (strengthened, not weakened); query budgets
  re-pinned (8→11 / 27→28 / 32→33) with statement-order pins unchanged;
  zero new skips (`--fail-on-skipped` green); implementation-status notes
  1-9 all verified against the code.
- **Durable docs:** development.md (audit tables, single order, SQLite
  strategy, Version1030), architecture.md (WP0 foundation section, idiom 5
  rewrite, scheduling subsequence note), ADR-011/012 supersession notes with
  preserved historical context, concepts/task-model-and-sync.md
  tombstone/erasure section — the plan is not the only lasting description.
- **Tracking:** parent plan WP0 row + RF-070-16/17 annotations, backlog
  CONCEPT-001 narrative, CR-SVC-63 narrowed-not-closed annotation,
  ACTIVE_TASKS — all coherent with the delivered state.

## Findings And Resolutions

| ID | Severity | Location | Issue | Resolution |
|----|----------|----------|-------|------------|
| RF-071-01 | nit | `lib/Service/CommandService.php:84` | two `@param` tags merged onto one docblock line | fixed in review (auto-fix category: wrong PHPDoc); `cs:check` green |
| RF-071-02 | nit | `docs/concepts/task-model-and-sync.md:329` | accidental one-space list-indent drift re-nested an unchanged bullet | fixed in review (auto-fix category: behavior-neutral format) |
| RF-071-03 | minor | `RoomService::{createRoom,updateRoomName,deleteRoom}`, `FloorService::{updateFloorName,deleteFloor}`, `RoomTaskService::deleteRoomTask`, `SchedulingService::updateMemberAvailabilityByNextcloudUserId` | tombstone/state checks ran only BEFORE the house lock (TOCTOU): races could re-stamp a tombstoned row's original `deleted_at`, rename tombstoned evidence, create a room under a just-tombstoned floor, or merge member preferences from a stale pre-read. Not auth-relevant (owner gates intact) | validation-logic change → asked; user selected "Fix now + unit pins". Authoritative under-lock re-checks added (404 semantics matching the existing room-task gates; preference merge on the FRESH locked entity; `deleteFloor` room-id collection moved under the lock); 9 deterministic unit pins added (unit 951 → 960); integration 388 green; post-fix E2E gate re-run per the second decision |

0 block / 0 major findings. No `// HACK:` workarounds introduced. No
security finding (nothing auto-fixed in the security surface). No
potentially exploitable unremediated detail — no `/private/` placeholder
needed.

## Decisions (batched structured question, 2026-09-19)

1. **RF-071-03 disposition** — "Fix now + unit pins (Recommended)" selected
   over "Record as plan-owned finding (blocks done/)" and "Rescope to
   backlog". Applied in this round; §9 entry resolved.
2. **E2E evidence after the fix** — "Re-run E2E gate after fix (Recommended)"
   selected over "Accept pre-fix E2E run + backend reruns". The pre-fix
   3-pool gate run (`run-bR1SyL`) had already finished PASSED and is
   retained as supplementary evidence; the authoritative post-fix re-run is
   `run-EVPJCv` — PASSED (46 required specs, 3 sequential pools, zero
   skipped/failed/flaky).

## Tests and Validation

Gates run by this review (all green):

- `git diff --check` — clean.
- `npm run backlog:check` — 24 scoped files link-checked.
- `npm run docs:encoding` — 722 tracked files.
- `npm run l10n:check-keys` — 942 keys, POT/EN/DE exact coverage.
- `npm run lint` — clean.
- `npm run test:noise` — clean.
- `npm test -- --runInBand` — 100 suites / 1056 tests.
- `npm run build` — webpack production build compiled successfully.
- `composer cs:check` (host PHP 8.3, `vendor/bin/php-cs-fixer --dry-run`) —
  0 of 312 fixable, pre- and post-fix.
- Docker clean volume (`down -v` + `up -d --build`, `TEST_DB_PROFILE=family`,
  full fresh-install migration chain): PHPUnit unit **951** (pre-fix) and
  **960** (post-fix), integration **388** (pre- and post-fix), both with
  `--fail-on-skipped` — zero skips.
- `npm run test:e2e:gate` — pre-fix run `run-bR1SyL` PASSED; post-fix
  authoritative re-run `run-EVPJCv` PASSED (46 required specs, 3 pools,
  workers=1, retries=0, forbid-only; evidence under gitignored
  `build/e2e-gate/`).

## Documentation and Backlog Changes

- Plan updated in place: status header → Done, §8 final checkboxes checked
  with evidence, §9 RF-071-01..03 recorded resolved, final Standard B Review
  Outcome appended, depends-on link repaired for the folder move; then moved
  `active/ → done/`.
- Parent CS-PLAN-070: WP0 row → DELIVERED (link retargeted to `done/`),
  RF-070-16/17 WP0 portions → delivered (WP3/WP7 remainders stay open).
- `docs/backlog/backlog.md`: CONCEPT-001 narrative updated (WP0 delivered,
  plan link → `done/`); item stays `In Progress` (WP1+ open). CR-SVC-63
  stays open (journal/replay remainder is WP3).
- `docs/plans/README.md`: active set → CS-PLAN-070 only; CS-PLAN-071 listed
  as latest completed.
- `docs/backlog/ACTIVE_TASKS.md`: In Progress cleared; delivery one-liner
  added; rolling window trimmed to the ~10-entry cap (CS-PLAN-065/066/067
  rolled into `archive/2026-09.md`, housekeeping 2026-09-19).
- This review record created.
- Safe fixes: RF-071-01/02 inline; RF-071-03 with user authorization
  (6 service methods + 4 test classes: 9 new pins, 4 mock-count re-pins,
  3 locked-entity validation stubs — allowed seam categories only, no
  behavior assertion weakened).

## Outcome

- **`PASS-WITH-FIXES`** — all three findings resolved in-round; every
  acceptance criterion verified with evidence; all required gates green on
  the final tree; no open question; no unresolved plan-owned finding.
- Plan transition: `active/ → done/` performed (final review; whole plan
  complete, durable docs integrated).
- Scoped commit: one conventional commit containing the reviewed WP0
  implementation package, the moved plan with its Review Outcome, parent/
  backlog/README/ACTIVE_TASKS updates, the monthly-archive roll, and this
  record; the three unrelated pre-existing `.gitkeep` deletions excluded
  (unstaged).
- Next step: the parent program continues with WP1 (occurrence timeline) —
  not part of this plan.

---

**Review Completed**: 2026-09-19
**Next Review**: WP1 owning-plan reviews under CS-PLAN-070
