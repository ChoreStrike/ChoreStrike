# CS-PLAN-071: WP0 — Write/Identity Foundation

> **Status:** Active (implementation not started) — child of CS-PLAN-070, executes its work package WP0; Standard A `PASS-WITH-FIXES` (2026-09-18)
> **Effort:** M (3-5 engineering days, bounded by CS-PLAN-070 §6 WP0)
> **Priority:** P1
> **Depends on:** [CS-PLAN-070](cs-plan-070-family-rhythm-and-reward-clarification.md) (active; G0 satisfied 2026-09-18 — Q-070-01/02 resolved, Standard A `PASS-WITH-FIXES`)
> **Related backlog items:** CONCEPT-001 (owning item via CS-PLAN-070 WP0); contributes to CS-PLAN-070 plan-owned findings RF-070-16/RF-070-17 (they stay open in the parent §9 until the WP3/WP7 portions land); CR-SVC-63 stays open (this plan adds lock-order compliance only, not replay history)

## 1. Problem Summary

CS-PLAN-070 §6 fixes the core spine `WP0 -> WP1 -> WP2 -> WP3 -> WP5 -> WP4 ->
WP6`. WP0 is the write/identity foundation every later package builds on; none
of it exists in the current runtime (baseline `master` @
`1946166618d591eba54bd42d1d338030389e994e`, rechecked 2026-09-18):

- **Two competing lock entry orders.** `lib/Db/TransactionExecutor.php:24-56`
  documents house-first ordering for setup/creation but a deliberate
  completion-only entry order `room_task -> room -> house_user` (ADR-011/012),
  and assignment/bulk-assign enter at `room_task` without a house lock.
  CS-PLAN-070 §3.5 supersedes this with one house-first serialization for all
  house mutations; it "cannot be rolled out only in new services".
- **No command receipt / idempotency foundation.** No mutating endpoint carries
  a `commandId`; a lost HTTP response cannot be recovered without re-executing
  side effects (CS-PLAN-070 §3.2 `commands`, §3.5 receipt semantics).
- **Financial identity dies with membership.** `house_users` is the
  wallet-bearing row (`coins`, sparks, freeze and title-pack columns,
  `lib/Db/HouseUser.php:26-140`); `HouseService::removeHouseMember`
  (`lib/Service/HouseService.php:172-186`) deletes it inside the removal
  transaction. `completed_tasks.user_id`/`combo_with_user_id` reference
  `house_users.id` (`lib/Migration/Version1004Date20260228131728.php:56-62`),
  so history dangles after departure and there is no persistent
  house/account financial ID (CS-PLAN-070 §3.1, C4 preamble).
- **Structural deletion destroys evidence.**
  `RoomService::deleteRoomTaskDependents` (`lib/Service/RoomService.php:193-197`,
  shared by `deleteRoom`, `FloorService::deleteFloor:183`,
  `RoomTaskService:400`) hard-deletes checklist progress, assignees **and
  `completed_tasks`**. Q-070-01 selected tombstoned minimum-history retention
  (2026-09-18); RF-070-16 tracks it.
- **No account-erasure path.** `lib/AppInfo/Application.php` registers only the
  notifier; there is no Nextcloud user-deletion hook. Q-070-02 selected
  erase/anonymize on account deletion (2026-09-18); RF-070-17 tracks it.
- **Missing shared schema.** `houses` has no `state_version`,
  `timezone_version`, `catalog_enabled`, `ranking_enabled`
  (`Version1000Date20260224000000.php:30-43`); there are no `commands`,
  `wallets`, `wallet_entries` tables.
- **Seed/reset inventory is unaware of the new foundation.**
  `TestDataService::reset` (`lib/Service/TestDataService.php:147`),
  `POST /api/v1/house/reset` (`HouseService::resetHouse:381`) and test-mode
  `test#reset` must cover receipts/wallets in dependency order
  (CS-PLAN-070 §3.5 room/reset coverage).

Without WP0, WP1-WP3 would build occurrence, agreement and accounting work on
divergent lock orders and ephemeral identities, and every later concurrency,
correction and erasure test would lack its foundation.

## 2. Strategy

Freeze the shared contracts first, then implement the smallest foundation that
proves them, exactly scoped to CS-PLAN-070 §6 WP0 ("Freeze the shared
schema/API/lock contracts; implement house-first command receipt/identity
foundation plus the selected Q-070-01 tombstone deletion and Q-070-02
account-erasure plumbing; update every writer entry point and seed/reset
inventory. T0 proves fresh install, auth and rollback, not just new empty
tables."):

1. **Audit (read-only):** inventory every writer/job/reset path with its
   current entry lock, every read path over `floors`/`rooms`/`room_tasks`, and
   every table column holding a personal identifier (erasure surface). Record
   the dated inventory in `docs/engineering/development.md`.
2. **Schema:** extend `houses` (versions/flags) and add tombstone columns in
   the existing chain (fresh-install policy — no upgrade paths); new
   `Version1030` migration creates `commands`, `wallets`, `wallet_entries`.
3. **Identity:** wallet rows as the persistent house/account financial ID,
   created with membership; departure preserves the wallet; re-invitation
   reattaches it; erasure removes it.
4. **Command receipts:** `CommandService` + `CommandReceipt` with replay,
   payload-hash conflict and house-locked atomic commit semantics — foundation
   only; endpoint adoption starts in WP2/WP3 per parent §3.4.
5. **House-first locking:** convert every audited writer entry point to the
   parent §3.5 order (or a subsequence), including a platform-aware SQLite
   write-acquisition strategy; rewrite the `TransactionExecutor` contract doc.
6. **Tombstone deletion (Q-070-01):** structural deletes mark
   `floors`/`rooms`/`room_tasks` deleted, keep `completed_tasks` evidence,
   filter active reads, keep undo authorized.
7. **Account erasure (Q-070-02):** `AccountErasureService` +
   `UserDeletedListener` wired in `Application.php`; anonymized actor
   references; no UID rebinding.
8. **Seed/reset inventory:** update all three reset/seed paths for the new
   tables in dependency order.
9. **T0 tests + full gates**, then durable docs (engineering docs, ADR-011/012
   supersession notes) and the Standard B review.

**Package boundary (no dual authority):** WP0 delivers identity, receipts,
locking, tombstones and erasure. It does **not** change monetary behavior:
`house_users.coins` and the existing reward writers stay authoritative until
WP3's single economy cutover; freeze/title-pack columns stay until WP4;
occurrence identity stays WP1. WP0 wallets are created, bound and preserved,
but are not exposed through any API and `wallet_entries` has no runtime writer
before WP3 (decision D1/D2, §10).

## 3. Backend / Frontend Changes

All changes are backend (`lib/`), migrations, tests and docs. No frontend
changes are expected: activity rows keep their DTO shape, with a localized
server-rendered placeholder for anonymized actors (§3.7).

### 3.1 Schema (fresh-install chain edits + one new migration)

Per CS-PLAN-070 §3.2/§5 (table prefix `chorestrike_`, bigint PKs, portable
unique indexes, constraints enforced in services as well):

- **M `Version1000Date20260224000000.php`:**
  - `houses`: add `state_version` (bigint, default 0), `timezone_version`
    (bigint, default 0), `catalog_enabled` (boolean, default false),
    `ranking_enabled` (boolean, default false).
  - Tombstone columns (Q-070-01): `floors.deleted_at`, `rooms.deleted_at`,
    `room_tasks.deleted_at` (datetime, nullable) plus `room_tasks.deleted_token`
    (bigint, notnull, default 0). The active unique index `uniq_rt`
    (`room_id`,`task_id`) becomes (`room_id`,`task_id`,`deleted_token`);
    tombstoning sets `deleted_token = id`, so exactly one active row per
    room/task remains unique while repeated tombstones never collide
    (decision D4). The recurrence read-cache ownership portion of the parent's
    Version1000 line is WP1 scope and is not touched here.
- **M `Version1004Date20260228131728.php`:**
  - `house_users`: add `wallet_id` (bigint, nullable until every membership
    path creates wallets; unique per house scoping is enforced through the
    wallets table, not here).
  - `completed_tasks`: `user_id` and `combo_with_user_id` become nullable
    (erasure anonymization, decision D3). No other completion-field reshape —
    that is WP1/WP3 via Version1029.
  - Legacy `coins`/sparks/freeze/title-pack columns stay untouched (D2).
- **N `Version1030Date20260918000000.php`:** creates `commands`, `wallets`,
  `wallet_entries` per parent §3.2:
  - `commands`: `house_id`, `actor_house_user_id` (nullable — erased receipts
    keep their row, lose the actor), `command_uuid` (string 36), `action`
    (string 64), `payload_hash` (string 64), `result_refs` (bounded JSON),
    `status`, `created_at` (UTC). Unique (`house_id`,`actor_house_user_id`,
    `command_uuid`); live uniqueness holds for non-null actors, service-side
    enforcement under the house lock covers the rest. Never stores tokens or
    raw request bodies.
  - `wallets`: `house_id`, `nextcloud_user_id` (string 64, the account
    binding — removed on erasure, preserved on departure), immutable `id`,
    `booked_minor` (bigint, default 0), `reserved_minor` (bigint, default 0,
    check >= 0 service-side), `version` (bigint, default 0), timestamps.
    Unique (`house_id`,`nextcloud_user_id`) while bound; erasure deletes the
    row, so a recreated same-UID account gets a fresh wallet id (no rebinding).
  - `wallet_entries`: `wallet_id`, `source_kind` (string 32), `source_id`
    (bigint), `source_revision` (bigint), signed `delta_minor` (bigint),
    `reason_code`, `actor_house_user_id` (nullable), `created_at` (UTC).
    Unique (`wallet_id`,`source_kind`,`source_id`,`source_revision`).
  - `work_shares` and `room_events` are **not** created here; WP3 adds them to
    this migration in place with its consuming code (decision D10).
- Entities/mappers: M `House`/`HouseMapper` (new fields, `findForUpdate`
  unchanged), M `HouseUser`/`HouseUserMapper` (`walletId`), N
  `Wallet`/`WalletMapper` (incl. `findForUpdate`, `findByHouseAndUser`),
  N `WalletEntry`/`WalletEntryMapper`, N
  `CommandReceipt`/`CommandReceiptMapper`. Signed minor-unit values are
  validated within PHP/DB and JavaScript safe-integer range at every boundary
  (parent §3.1).

### 3.2 Financial/member identity

- Wallet creation points: `HouseSetupService::applySetup` (owner + invited
  members), `HouseService::inviteMember` (the invite write persists
  membership; there is no separate acceptance step), `TestDataService`
  seeds. Creation runs inside the house-first transaction;
  the unique account binding plus the house lock prevent duplicate identity
  under concurrent creation (parent §3.5).
- `HouseService::removeHouseMember` (`HouseService.php:172-186`): keep the
  wallet row (financial identity/history separate from active authorization,
  parent C4 preamble); still delete `task_assignees` + `house_users` in one
  house-first transaction. Full C4e semantics (cancelling unfulfilled
  requests) is WP7 — there are no requests yet.
- Re-invitation of the same account reattaches the existing wallet via
  (`house_id`,`nextcloud_user_id`); balances stay untouched (dormant until
  WP3). Wallet/history ownership never grants access: authorization remains
  solely the active `house_users` membership (parent §3.1).
- `houses.state_version` bump helper (entity method + mapper write inside the
  house lock) is provided and demonstrated by T0; plan/accounting/preference
  mutations adopt it in WP2-WP5. `timezone_version` is wired now into the
  existing house-timezone mutation path as its first consumer (D7).

### 3.3 Command receipt foundation

`lib/Service/CommandService.php` (N) implements the parent §3.2/§3.5 contract:

- `runCommand(houseId, actorHouseUserId, commandUuid, action, payload, fn)`:
  normalize the payload (stable key order, whitelisted fields), hash it, take
  the house lock first, then either insert the receipt and execute `fn` in one
  transaction, or resolve a duplicate key: identical hash → replay (return the
  original result identity with a replay marker and freshly read authorized
  projections); different hash → `ConflictException` (409). Receipts commit
  only with all domain facts; a reused key against an already terminal target
  never repeats financial effects.
- No production endpoint adopts `commandId` in WP0 (first consumers: WP2
  scheduling apply, WP3 completion — parent §3.4); T0 exercises the service
  directly (decision D6). `appinfo/routes.php` and `RouteMatrixTest` are
  unchanged; the route count in `docs/api/README.md` does not move.

### 3.4 House-first locking and SQLite write acquisition

- Target order (parent §3.5, frozen): house → room tasks (by ID) → task
  definitions (by ID) → rooms (by ID) → active membership rows (by ID) →
  wallets (by ID) → occurrence/completion rows (by ID) → request/preference/
  digest rows (by ID). Every writer follows this order or a subsequence; none
  may acquire the house lock after a child lock. Reads and different houses
  stay independent; no DB transaction is held while sending a Nextcloud
  notification (audit verifies `CompletionPostCommitService` compliance).
- Conversion targets from the audit (expected set, confirmed in step 1):
  `CompletionService` (complete/undo — supersedes the ADR-011/012 entry
  order), assignment/bulk-assign paths (`RoomTaskService`, `TaskService`),
  `ChecklistService`, `SchedulePlanService`/`SchedulingService` (already
  house-first per ADR-012 — verify subsequence), `HouseSetupService`,
  `TaskCreationService` (verify), `HouseService` (invite/remove/role/reset),
  `FloorService`/`RoomService`/`RoomTaskService` structural mutations
  (incl. `updateRoomCleanliness`, currently unlocked — CR-SVC-63 stays open,
  this plan only adds the house→room lock order), `MinigameService`,
  `GameLogicService`, `MedalService`, `TitleService`,
  `StreakFreezeService`/`StreakRestockService` and their jobs (converted now,
  retired in WP4), `TestDataService`, `TriggerJobs` paths.
- `TransactionExecutor` (M): rewrite the class contract doc to the single
  house-first order (removing the "completion never takes the house lock"
  deviation) and add platform-aware write acquisition: on SQLite, begin
  transactions in immediate write mode (`BEGIN IMMEDIATE` plus a bounded busy
  timeout) because row-level `FOR UPDATE` is unavailable; MariaDB/PostgreSQL
  keep `SELECT ... FOR UPDATE` via the existing mapper primitives (decision
  D5). Recognized transient lock/deadlock failures get a small bounded retry
  policy with the same command key (parent §3.5).

### 3.5 Tombstone deletion (Q-070-01, RF-070-16 WP0 portion)

- `FloorService::deleteFloor`, `RoomService::deleteRoom`,
  `RoomTaskService::deleteRoomTask`: replace the evidence-deleting cascade
  with tombstoning — set `deleted_at` (UTC) and (room tasks)
  `deleted_token = id`, `is_active = 0`, inside the house-first transaction.
  `task_assignees` and `room_task_checklist_progress` rows for tombstoned
  room tasks are deleted (active-planning state); **`completed_tasks` rows are
  retained** (minimum history, existing visibility, valid earned effects).
  `deleteRoomTaskDependents` is reduced/renamed accordingly; no caller may
  hard-delete structural rows outside the test-mode reset (D4).
- Active reads: existing `is_active` filters (`RoomTaskMapper.php:102-578`)
  already exclude tombstoned room tasks; floor/room structure reads gain
  `deleted_at IS NULL` filters where the audit finds unfiltered paths.
  History/activity reads keep resolving names through tombstoned rows.
- Undo authorization: `CompletionService::undo` must accept completions whose
  room task is tombstoned (row still loads; completer/owner rights unchanged)
  — full targeted-undo semantics remain WP3.
- `POST /house/reset` contract is preserved (cleanliness 100, monsters 0,
  `last_cleaned` null, health 100; no work/finance clearing), now house-first;
  the absolute room-reset **event** journaling arrives with WP3's
  `room_events` (parent §3.5) — until then the reset writes room rows
  directly as today.

### 3.6 Account erasure (Q-070-02, RF-070-17 WP0 portion)

- N `lib/Listener/UserDeletedListener.php` on the verified Nextcloud
  `OCP\User\Events\UserDeletedEvent`, registered in
  `Application.php::register`; N `lib/Service/AccountErasureService.php`:
  house-first per affected house, then for every membership of the UID:
  delete `house_users`, `task_assignees`, `medals`, `minigame_scores` rows,
  delete the wallet with its `wallet_entries` (personal finances removed,
  active reservations cannot exist before WP7), null the actor on
  `commands` receipts, and null `completed_tasks.user_id` /
  `combo_with_user_id` (retained anonymous shared work facts: time, energy,
  coins-earned totals, room effects). Scheduling preferences die with the
  `house_users` row. Ordinary departure is untouched by this path (D3).
- Anonymized actors render through a localized placeholder (`t('Former
  member')`) in `ActivityService` DTOs; `l10n/{en,de}.json` and
  `l10n/templates/chorestrike.pot` are updated in the same change if the
  string is added.
- No rebinding: history references internal bigint IDs (never raw UIDs), so a
  recreated same-name account (new `house_users.id`, new `wallets.id`) cannot
  recover prior data. Erasure of an already-departed account (wallet without
  membership) is covered.
- Later-package surfaces (agreement snapshots, digest records, reward wishes,
  audit reasons) extend this service in WP2/WP5/WP7; T0 coverage grows with
  them and the complete cross-domain gate is WP9 (parent §4).

### 3.7 Seed/reset inventory

- `TestDataService::reset` and test-mode `test#reset`: clear all app tables
  including `commands`, `wallets`, `wallet_entries` in dependency order;
  seeds create wallet rows for every seeded member.
- `HouseService::resetHouse`: house-first; wallets/receipts are not cleared by
  a production house reset (they are financial identity, not room state).

## 4. Tests

T0 per CS-PLAN-070 §4 ("Concrete Test Ownership"), delivered with this
package — no future-package test is newly skipped:

- **N `tests/integration/Db/FamilyRhythmSchemaTest.php`:** fresh-install chain
  produces the final schema; unique constraints (`commands`
  house/actor/UUID, `wallets` house/binding, `wallet_entries` source tuple,
  `room_tasks` room/task/deleted_token); house flag/version defaults;
  tombstoned deletion retains `completed_tasks`, disappears from active
  reads, and deletion-bypass attempts (direct hard-delete outside test-mode
  reset) are covered.
- **N `tests/integration/Service/HouseMutationConcurrencyTest.php`:** real
  two-connection barrier interleavings (no sleeps): complete vs. complete on
  one shown target; complete vs. member removal; complete vs. tombstone
  deletion; manual cleanliness adjust vs. completion serialized (lock order
  only — the CR-SVC-63 replay regression stays WP3); absent-wallet concurrent
  membership creation yields exactly one identity; injected failure after the
  first wallet write proves complete rollback; house-first write acquisition
  on an isolated disposable SQLite environment (D5).
- **N `tests/integration/Service/AccountErasureTest.php`:** full purge matrix
  across every WP0-stage reference; retained anonymous work facts; recreated
  same-UID account gets fresh identities with no history rebinding and no
  personal read leakage through activity/game/house/user endpoints; departure
  preserves the wallet and re-invitation reattaches it; erasure after
  departure removes the preserved wallet.
- **M `tests/integration/RoleGuardApiTest.php`:** role/visibility matrix
  extended — structural tombstone deletion stays owner-only; wallet identity
  grants no access; erasure is not exposed as an app API.
- **M `tests/integration/Controller/InviteOnlyMembershipTest.php`:**
  membership lifecycle with wallet creation/preservation/reattachment.
- **Regression net:** the lock-order conversion keeps the existing suites
  green — Docker PHPUnit unit (941) and integration (361) with
  `--fail-on-skipped`, `npm test -- --runInBand` (1056), including
  `CompletionConcurrencyTest`, `ConcurrentCompletionTest`,
  `CompletionUndoStateEqualityTest`, `SchedulePlanConcurrencyTest`,
  `SetupApplyConcurrencyTest`, `TaskCreationConcurrencyTest`,
  `ChecklistToggleConcurrencyTest`, `StreakFreezeIdempotencyTest`,
  `HouseMemberRemovalIntegrationTest`. Call-order/mocker assertions may be
  updated to the new lock order; behavior assertions may not be weakened and
  nothing may be newly skipped.
- **Gates:** `npm test -- --runInBand`, `npm run lint`, `npm run build`,
  `npm run l10n:check-keys`, `npm run backlog:check`,
  `npm run docs:encoding`, `git diff --check`, `composer cs:check`, Docker
  unit + integration via `docker/compose.test.yml`, and
  `npm run test:e2e:gate` on the unchanged required scenarios (WP0 adds no
  browser scenarios; deletion/membership behavior keeps its API shape).
  The full SQLite/PostgreSQL matrix (`docker/compose.test-matrix.yml`) is WP9;
  WP0 proves the SQLite acquisition mechanism only.

## 5. File Manifest

```text
new:      docs/plans/new/cs-plan-071-wp0-write-identity-foundation.md (this plan)
new:      lib/Migration/Version1030Date20260918000000.php
new:      lib/Db/CommandReceipt.php
new:      lib/Db/CommandReceiptMapper.php
new:      lib/Db/Wallet.php
new:      lib/Db/WalletMapper.php
new:      lib/Db/WalletEntry.php
new:      lib/Db/WalletEntryMapper.php
new:      lib/Service/CommandService.php
new:      lib/Service/AccountErasureService.php
new:      lib/Listener/UserDeletedListener.php
new:      tests/integration/Db/FamilyRhythmSchemaTest.php
new:      tests/integration/Service/HouseMutationConcurrencyTest.php
new:      tests/integration/Service/AccountErasureTest.php
modified: lib/Migration/Version1000Date20260224000000.php (houses versions/flags; tombstone columns + room_tasks unique index)
modified: lib/Migration/Version1004Date20260228131728.php (house_users.wallet_id; nullable completed_tasks actor refs)
modified: lib/Db/TransactionExecutor.php (house-first contract doc; SQLite immediate-write acquisition; bounded retry)
modified: lib/Db/House.php
modified: lib/Db/HouseMapper.php
modified: lib/Db/HouseUser.php
modified: lib/Db/HouseUserMapper.php
modified: lib/Db/RoomTaskChecklistProgressMapper.php
modified: lib/Db/RoomTaskMapper.php (tombstone-aware unique/active queries — audit-confirmed)
modified: lib/Service/HouseService.php (member removal keeps wallet; reset house-first; timezone_version bump)
modified: lib/Service/FloorService.php
modified: lib/Service/RoomService.php
modified: lib/Service/RoomTaskService.php
modified: lib/Service/TaskService.php
modified: lib/Service/TaskCreationService.php
modified: lib/Service/HouseSetupService.php (wallet creation)
modified: lib/Service/CompletionService.php (house-first entry; undo across tombstones)
modified: lib/Service/SchedulePlanService.php
modified: lib/Service/SchedulingService.php
modified: lib/Service/ChecklistService.php
modified: lib/Service/MinigameService.php
modified: lib/Service/GameLogicService.php
modified: lib/Service/MedalService.php
modified: lib/Service/TitleService.php
modified: lib/Service/StreakFreezeService.php
modified: lib/Service/StreakRestockService.php
modified: lib/Service/TestDataService.php (seed/reset inventory incl. new tables)
modified: lib/Service/ActivityService.php (anonymized-actor DTO placeholder — sub-plan addition, D9)
modified: lib/Service/RoomTaskReadService.php (tombstone filtering where the audit finds unfiltered paths — D9)
modified: lib/AppInfo/Application.php (UserDeletedListener registration)
modified: tests/integration/RoleGuardApiTest.php
modified: tests/integration/Controller/InviteOnlyMembershipTest.php
modified: existing unit/integration suites whose lock-order assertions change (no behavior weakening, no new skips)
modified: docs/engineering/development.md (dated writer/lock audit inventory; house-first order; SQLite strategy)
modified: docs/engineering/architecture.md (identity/wallet/command-receipt foundation)
modified: docs/engineering/testing.md (T0 inventory growth)
modified: docs/adr/ADR-011-transactional-completion-and-undo.md (entry-order supersession note, historical context preserved)
modified: docs/adr/ADR-012-actionable-household-scheduling.md (same)
modified: docs/concepts/task-model-and-sync.md (implemented tombstone-deletion and erasure behavior)
modified: l10n/en.json, l10n/de.json, l10n/templates/chorestrike.pot (only if the anonymized-actor placeholder lands)
modified: docs/backlog/ACTIVE_TASKS.md, docs/backlog/backlog.md (CONCEPT-001 WP0-slice annotation; CR-SVC-63 stays open)
modified: docs/plans/active/cs-plan-070-family-rhythm-and-reward-clarification.md (WP0 progress note + RF-070-16/17 WP0-portion status, at implementation time)
```

No files are retired/archived in WP0 (freeze/restock/boost retirement is
WP4/WP6). `appinfo/routes.php`, `docs/api/README.md` and
`tests/unit/RouteMatrixTest.php` are unchanged (no new routes).

## 6. Implementation Order

1. Read-only audit: writer/job/reset entry-lock inventory, structural read
   paths, personal-identifier reference inventory; record in
   `docs/engineering/development.md` and confirm/adjust §3/§5 (D9).
2. Schema + entities/mappers (Version1000/1004 edits, Version1030, House,
   HouseUser, Wallet, WalletEntry, CommandReceipt);
   `FamilyRhythmSchemaTest` green on a fresh install.
3. Identity plumbing: wallet creation at every membership path,
   `wallet_id` binding, departure preservation, re-invitation reattachment.
4. `CommandService` + receipt replay/conflict semantics; T0 receipt cases.
5. House-first lock conversion across all audited writers +
   `TransactionExecutor` contract/SQLite acquisition; convert the existing
   concurrency suites' expectations; `HouseMutationConcurrencyTest` green on
   MariaDB and the isolated SQLite run.
6. Tombstone deletion: services, read filters, undo authorization,
   deletion-bypass coverage.
7. Erasure: `AccountErasureService`, `UserDeletedListener`, `Application.php`
   wiring, anonymized activity reads + l10n artifacts; `AccountErasureTest`.
8. Seed/reset inventory updates (TestDataService, resetHouse, test#reset).
9. Full gate run (§4); durable docs (engineering, ADR-011/012 supersession
   notes, concept integration); update `ACTIVE_TASKS.md`, backlog annotations
   and the parent plan's WP0/RF status.
10. Request the Standard B review; on pass, the parent program continues with
    WP1 (occurrence timeline) — not part of this plan.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Lock-order conversion regresses existing concurrency behavior (deadlocks, serialized-suite timeouts) | High | One documented order + subsequence rule; convert writers in one package, not piecemeal; the existing concurrency suites are the regression net; barrier-based T0 proofs on MariaDB |
| SQLite immediate-write changes transaction/busy semantics | Medium | Bounded busy timeout; isolated disposable SQLite proof in T0; full matrix deferred to WP9 (parent §4); no canonical compose change |
| Tombstoning breaks a read path the audit missed (deleted rooms/tasks still visible somewhere) | Medium | Step-1 read-path audit before code; `is_active=0` reuses existing filters; schema test asserts active-read disappearance across the main read endpoints |
| `deleted_token` uniqueness trick conflicts with WP1's occurrence schema | Low | Contract frozen here and ratified by this plan's Standard A; WP1 builds on `schedule_revs`/`occurrences`, not `uniq_rt` |
| Dormant wallets drift into a dual-authority balance | Medium | D1/D2 boundary: no API exposure, no runtime `wallet_entries` writer, tests assert legacy coin behavior is unchanged in WP0 |
| Erasure misses a personal reference | Medium | Audited reference inventory; erasure test sweeps every WP0-stage table; coverage grows per package with the WP9 cross-domain gate (parent §4) |
| `CommandService` flagged as dead code without endpoint consumers | Low | Parent §3.4/§3.5 mandate foundation-first (D6); T0 exercises replay/conflict/rollback; WP2 is the announced first consumer |
| House-wide serialization lengthens lock holds | Medium | Household scale (parent §7); no network calls inside transactions; measure contention in T0; planner input bounds stay WP2 |
| E2E gate regressions from deletion/membership behavior changes | Low | API shapes unchanged; full `npm run test:e2e:gate` on unchanged required scenarios is part of the WP0 gate |

## 8. Acceptance Criteria

- [ ] Fresh install through the modified chain produces the frozen schema:
      house versions/flags, tombstone columns, `commands`/`wallets`/
      `wallet_entries` with the parent §3.2 unique constraints.
- [ ] Every audited writer/job/reset path enters transactions in the
      house-first order (or a documented subsequence); the
      `TransactionExecutor` contract doc and `docs/engineering/development.md`
      record the single order; ADR-011/012 carry supersession notes with
      preserved historical context.
- [ ] Command receipts: duplicate key + identical payload replays with the
      original result identity and a replay marker; duplicate key + changed
      payload is a 409; receipts commit atomically with domain facts; no
      tokens/raw bodies stored.
- [ ] Wallets exist per house/account, survive ordinary departure, reattach on
      re-invitation, grant no access, and are not exposed through any API;
      legacy coin behavior is provably unchanged (D1/D2).
- [ ] Structural deletion tombstones floors/rooms/room tasks, retains
      `completed_tasks`, removes objects from all active-planning reads, keeps
      undo authorized, and no production path hard-deletes structural rows
      outside the test-mode reset.
- [ ] Account erasure purges every WP0-stage personal reference, retains
      anonymous shared work facts, prevents same-UID rebinding and personal
      read leakage, and fires from the registered Nextcloud deletion event.
- [ ] T0 (schema, concurrency incl. the isolated SQLite acquisition proof,
      erasure, role guard, membership) passes; existing unit/integration/
      Jest/E2E suites pass with no new or unapproved skips.
- [ ] Seed/reset inventory covers the new tables in dependency order;
      `POST /house/reset` contract preserved.
- [ ] Full gate green: `npm test -- --runInBand`, `npm run lint`,
      `npm run build`, `npm run l10n:check-keys`, `npm run backlog:check`,
      `npm run docs:encoding`, `git diff --check`, `composer cs:check`, Docker
      PHPUnit unit + integration `--fail-on-skipped`,
      `npm run test:e2e:gate`.
- [ ] Durable outcomes integrated (engineering docs, ADR notes, concept docs,
      l10n artifacts) — the plan is not the only lasting description.
- [ ] Parent plan updated: WP0 marked delivered pending review; RF-070-16/17
      annotated with their WP0 portions (they close only with WP3/WP7);
      CR-SVC-63 and CONCEPT-001 reflect real state; `ACTIVE_TASKS.md` updated.
- [ ] All implementation decisions and review questions resolved and recorded.
- [ ] All plan-owned review findings (§9) resolved or user-rescoped.
- [ ] Final Standard B review is `PASS` or `PASS-WITH-FIXES`.

## 9. Review Findings (plan-owned)

- [ ] (none yet)

## 10. Plan Review Decision Register

Proposals for ratification by this plan's Standard A review; the parent
CS-PLAN-070 decision logs (D1-D11, C1-C5, Q-070-01/02) remain authoritative
for product behavior.

| ID | Proposed decision | Rationale |
|----|-------------------|-----------|
| D1 | WP0 wallets are identity-only: created, bound, preserved and reattached, but with dormant zero balances, no API exposure and no runtime `wallet_entries` writer; the monetary cutover is WP3's single economy replacement | Parent §5: "no compatibility shim or temporary dual-authority balance is a package deliverable"; WP3 "replaces all monetary writers together" |
| D2 | Legacy `house_users` columns (`coins`, sparks, freeze, title-pack) are not removed in WP0; removal lands with the consumer cutovers (WP3 monetary, WP4 recognition) | Parent §5 shared-file rule: no field removal before consumers are converted |
| D3 | Erasure anonymizes today's actor references by nulling `completed_tasks.user_id`/`combo_with_user_id` (made nullable) and `commands.actor_house_user_id`; the persistent non-null internal subject (`wallets.id`) is introduced now and becomes the ledger actor reference in WP3's completion reshape; activity reads use a localized "Former member" placeholder | Q-070-02 requires purging identifiers from primary tables now; the full non-null-subject ledger is WP3 scope (parent §3.2) |
| D4 | Tombstone mechanics: nullable `deleted_at` on floors/rooms/room_tasks plus `room_tasks.deleted_token` (0 active, `= id` on delete) folding into the `uniq_rt` unique index; tombstoning also sets `is_active = 0`; `task_assignees` and checklist progress are deleted, `completed_tasks` retained | Portable unique indexes without DB-specific partial indexes (parent §3.2); `is_active=0` reuses the existing active-read filters (`RoomTaskMapper.php:102-578`); ledger entries are never erased (Q-070-01) |
| D5 | SQLite write serialization: `TransactionExecutor` begins SQLite transactions in immediate write mode with a bounded busy timeout; `FOR UPDATE` platforms keep row locks; proven on an isolated disposable SQLite environment, full matrix in WP9 | Parent §3.5: "SQLite requires equivalent write serialization despite lacking row-level FOR UPDATE"; canonical compose stays MariaDB |
| D6 | `CommandService` ships without a production endpoint consumer in WP0; T0 integration tests are its proof; first consumers are WP2 (scheduling apply) and WP3 (completion) | Parent §3.4 assigns `commandId` adoption to the route-owning packages; WP0 "freezes the shared API contract" |
| D7 | `houses.state_version` ships as a mechanism demonstrated by T0 (adoption by plan/accounting/preference mutations in WP2-WP5); `timezone_version` is wired into the existing house-timezone mutation now; `catalog_enabled`/`ranking_enabled` ship as frozen default-false flags for WP7/WP4 | Parent §3.2 houses row: "increment relevant version on every plan/accounting/preference mutation" — those mutations do not exist before WP2 |
| D8 | WP0 updates ADR-011/012 with supersession notes and the engineering docs; ADR-013/014/015 are created by their behavior-owning packages (WP1 / WP3 / WP5-WP7), not WP0 | Parent §5: "Permanent docs integrate implemented behavior per package"; WP0 implements no occurrence/wallet-economy/delivery behavior yet |
| D9 | The step-1 audit may add read-path files to the §5 manifest (expected: `RoomTaskMapper`, `RoomTaskReadService`, `ActivityService`, possibly `CalendarService`/structure reads); additions are recorded in this plan before implementation | Parent manifest is a proposal; Q-070-01/02 obligate tombstone filtering and anonymized reads wherever the audit finds them |
| D10 | `Version1030` is created by WP0 with `commands`/`wallets`/`wallet_entries` only; WP3 extends the same migration file in place with `work_shares`/`room_events` | Parent §5: "Apply each schema edit with its consuming code/fixtures in that package"; avoids dead schema at WP0 exit while keeping the parent's single-migration allocation |
| D11 | The recurrence read-cache ownership portion of the parent's Version1000 line stays with WP1 (occurrence contract); WP0's Version1000 slice is house versions/flags + tombstone columns | Parent §5 WP1 row owns occurrence identity and daily/calendar read rewiring |

## Review Outcome

### Standard A Review Outcome (2026-09-18)

**Tier/Outcome: Standard A (plan) — `PASS-WITH-FIXES`.**

- **Scope:** this plan file (506 lines pre-review, untracked), SHA256
  `8D1BEF36E3EB2E8C66F694D2B041968D7F6B7688B67E90FD0C230C355455CF79`;
  base `master` @ `4d82eeade93fa8e7b91219323f96275bf8017e19` (one docs-only
  commit after the plan's recheck revision `19461666`; runtime identical);
  empty index; only other working-tree change: the three unrelated
  pre-existing `docs/plans/{new,active,onhold}/.gitkeep` deletions
  (preserved unstaged).
- **Verified:** template §1-§9 + §10 decision register complete; scope
  matches the parent CS-PLAN-070 §6 WP0 deliverable, §3.2 schema contracts,
  §3.5 lock order and the T0 test-file assignment exactly; RF-070-16/17
  WP0-portion ownership consistent with parent §9. Baseline claims confirmed
  at HEAD: dual lock entry orders (`TransactionExecutor.php:24-56`),
  evidence-deleting cascade (`RoomService.php:193-197`, shared by
  `FloorService:183`/`RoomTaskService:400`), member removal deleting the
  wallet-bearing row (`HouseService.php:172-186`), notifier-only
  `Application.php`, no wallet/command code in `lib/`, migrations ending at
  `Version1029` (`Version1030` free), `uniq_rt (room_id,task_id)` at
  `Version1000:103`, `is_active` filters spanning `RoomTaskMapper.php:102-578`,
  `TestDataService::reset:147`/`resetHouse:381` anchors, `minigame_scores`,
  every manifest `M` file, all nine regression-net test classes and all gate
  scripts present; CONCEPT-001 `In Progress`, CR-SVC-63 open as claimed.
  `OCP\User\Events\UserDeletedEvent` rests on the parent Standard A
  verification (local vendor ships no OCP stubs; OCP resolves in the
  Nextcloud Docker runtime).
- **Findings:** 1 minor, fixed in this review: §3.2/§3.4 named a
  non-existent `HouseService::addMember` (invitation acceptance); the real
  membership writer is `HouseService::inviteMember` (`HouseService.php:109`,
  called by `UserController::invite`) and no acceptance step exists (parent
  §3.4 route table). Otherwise 0 block / 0 major / 0 nit.
- **Decisions:** §10 D1-D11 ratified unchanged — each traces to the parent's
  authoritative decision logs (D1-D11/C1-C5, Q-070-01/02) and introduces no
  new product behavior, security surface or migration policy; no user
  decision was open, so no review question was required.
- **Gates:** `npm run backlog:check`, `npm run docs:encoding`,
  `git diff --check` green (documentation-only round; runtime suites
  unchanged and not required). Review record:
  [CODE_REVIEW_2026-09-18.md](../../backlog/archive/CODE_REVIEW_2026-09-18.md#cs-plan-071-standard-a-plan-activation).
- **Transition:** `new/ → active/` with this review's scoped commit;
  CONCEPT-001 stays `In Progress` (WP0 sub-plan annotation added).
