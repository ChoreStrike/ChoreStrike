# CS-PLAN-074: WP3 - Work Accounting and Replay

> **Status:** Done (Standard A PASS 2026-09-25; Q-074-01/02 resolved - Option A each; final Standard B NEEDS-FOLLOW-UP 2026-09-27 with RF-074-WP3-01..08 resolved in the dedicated fix round; **final Standard B re-review PASS-WITH-FIXES 2026-09-27** - RF-074-WP3-09 capacity-seam integration pin delivered per user decision, RF-074-WP3-10 doc drift auto-fixed; plan moved `active/ → done/`, CR-SVC-63 closed)
> **Effort:** L/XL; 10-16 engineering days (parent WP3 allowance 7-11 plus the selected Q-074-01 Option A correction-closure slice 3-5)
> **Priority:** P1
> **Depends on:** [WP0 / CS-PLAN-071](../done/cs-plan-071-wp0-write-identity-foundation.md), [WP1 / CS-PLAN-072](../done/cs-plan-072-wp1-occurrence-timeline.md) and [WP2 / CS-PLAN-073](../done/cs-plan-073-wp2-agreements-capacity-pauses.md), delivered; activation decisions resolved 2026-09-25
> **Related backlog items:** CONCEPT-001 via [CS-PLAN-070](../done/cs-plan-070-family-rhythm-and-reward-clarification.md); CR-SVC-63 journal/replay remainder (closed with this plan's final review)
> **Planning baseline:** `f365369f51e4e447cdadb34425306ad3982036e5`, 2026-09-25, after the WP2 final review

This is the implementation sub-plan for parent WP3. The parent C3 rules remain
authoritative. The section 10 activation decisions were resolved through the
Standard A question batch on 2026-09-25 (Option A each); the engineering
proposals below are activated plan scope, not additional product choices
beyond the parent's approvals.

## 1. Problem Summary

WP0 supplies persistent wallet identity, command receipts, house-first writes,
tombstones and account erasure. WP1 supplies stable occurrence keys, saved
time bases and occurrence-scoped checklists. WP2 supplies exact **planned**
shares, agreements, pauses and whole-day capacity. None of these makes a
planned coworker an actual worker or delivers isolated financial correction.
`WalletService::ensureBinding` and `WalletServiceTest` already exist from WP0;
extend them with the ledger behavior while retaining their binding/rejoin tests.

The remaining work is one coordinated cutover, not another reward formula:

| Area | Baseline / required change |
|---|---|
| Completion identity | Client `src/store/roomTasks.js:452-457` sends the occurrence receipt, but no actual participants. Add frozen total work, actual allocation and a separately versioned completion. |
| Capacity | `CompletedTaskMapper::findEnergyUsageBetweenByHouse` joins saved occurrence effort for WP2's completer-only attribution. Replace that attribution with selected actual shares; preserve saved-zone and neutral-pause rules. |
| Economy | `GameLogicService.php:114-140` calculates bonuses and writes `HouseUser` Coins/contribution. Use the existing `wallets`/`wallet_entries` foundation as the sole monetary authority. |
| Correction | `CompletionEffectService.php:119-154` restores room, claim and member snapshots; `src/store/roomTasks.js:574-594` targets latest-by-room-task undo. Replace both with exact completion targeting and dependent replay. |
| Rooms | `RoomMapper.php:158-168` creates cleanliness 50; `:186-202` resets it to 100 with monsters 0 and no `last_cleaned`. Capture these distinct baselines and every requested completion/manual effect. |
| Arcade / clients | `src/store/minigame.js:93-101,209-268` reads Coins, purchases boosts and handles game earnings. `ArcadeSection.vue:148-149,197-204` also mounts/loads unsupported prototypes. Remove live monetary consumers in the cutover. |
| Recognition dependency | `GameLogicService.php:142-212`, `CompletionEffectService.php:132-154` and `StreakRestockService.php:61-68` couple completion, freeze restock and titles. Removing snapshots cannot silently leave false personal results; resolve Q-074-01. |

Durable requirements: [C3 accounting](../../concepts/gamification-and-rewards.md#approved-accounting-contract-c3-2026-09-17),
[effort allocation](../../concepts/energy-and-balancing.md#approved-effort-allocation-c3-2026-09-17),
[correction](../../concepts/task-model-and-sync.md#approved-correction-contract-c3-2026-09-17)
and [ADR-013](../../adr/ADR-013-occurrence-agreements-and-corrections.md).
The older parent inventory is historical; use this post-WP2 baseline when
implementing, and recheck it if another package changes the shared files.

## 2. Strategy

Preserve the existing command coordinator and occurrence facade. Separate
immutable work/allocation evidence, additive wallet entries and rebuildable
projections. Extend the fresh-install migration chain with its consumers in
one logical change; do not migrate disposable balances, overload old fields,
maintain duplicate authorities or build a generic event-sourcing framework.

| Ownership | Deliverable / boundary |
|---|---|
| WP3 / this plan | Actual shares, frozen effort/rate, wallet cutover, targeted undo/attribution, room journal, actual-share capacity, history/API/client integration, no-Coin arcade backend and required live-client changes. T3 actual-work portion, T4, T10 backend and touched browser contracts. |
| WP2 / delivered | Planned shares, consent, pause lifecycle and capacity admission. Reuse them; do not reopen Q-073-01/02/03 or infer actual participation from them. |
| WP4 / dependency decision | Planned-day rhythm, weekly goals, retained recognition and ranking remain parent-owned here until Q-074-01 explicitly assigns the minimum correction-closure slice. Never implement a disposable legacy projection engine merely to bridge packages. |
| WP5 / later | Preferences, ranking consent and bounded digest delivery. No external notification/queue service or new approval workflow in WP3. |
| WP6 / later | Full My Day/calm-UX redesign and optional-area polish. Move only necessary participation, amount formatting, correction and removed-route consumers forward; do not leave broken controls until WP6. |
| WP7-9 / later | Real catalog/reservation/fulfillment workflows, combined cross-DB gates and voluntary family evidence. WP3 tests financial interleavings with test-only ledger fixtures, not a prematurely enabled shop. |

CONCEPT-001 owns the work. CR-SVC-63 owns the existing manual-room remainder;
do not create a parallel backlog item. Parent RF-070-07/08 and the accounting,
arcade, room-retention and erasure portions of RF-070-09/13/16/17 are addressed
here, not automatically closed in their entirety.

## 3. Backend / Frontend Changes

### 3.1 Persistence and Exact Units

| Storage | Proposed change / invariant |
|---|---|
| `completed_tasks` | Keep immutable occurrence key, server work instant/date/zone and audit completer. Freeze total energy and rate 10. Add completion `version`, selected `allocation_revision` and command reference; retain `undone_at`. Remove the before-state restore authority and obsolete reward outputs with their consumers. |
| `work_shares` | New immutable `(completion_id, allocation_revision, wallet_id)` allocations: positive integer `share_pct`, exact `energy_minor` and `coin_minor`. One selected revision per valid completion; old revisions are evidence, never counted again. Nullable erased subjects retain only non-identifying shared work. |
| `completion_revs` | Proposed small append-only audit table: completion, unique revision, action (`complete`, `attribution`, `undo`), selected allocation revision, nullable actor, recorded instant, bounded reason code and command UUID. Audit revision matches completion version (initial 0). Keep correction evidence separate from planned `agreements` and receipt result caches. |
| `wallets` / `wallet_entries` | Reuse WP0. `booked_minor` equals the sum of entries; `reserved_minor >= 0`; `availableMinor = bookedMinor - reservedMinor`. Entries have a unique wallet/source-kind/source-ID/source-revision identity. Negative booked/available balances are valid correction results. |
| `house_users` | Replace whole-unit contribution with exact `contribution_minor`, derived from valid selected work shares. Remove legacy `coins` and earning multipliers only together with all writers/readers. Rejoining derives contribution from retained work; membership never becomes financial authority. |
| `room_events` | New room/kind/effective-time/stable-ID journal, source completion where relevant, requested effect, frozen parameters, baseline/reset values and timestamp behavior. Index `(room_id, effective_at, id)`; completion-source uniqueness prevents duplicate effects. |

`completion_revs` refines the parent's proposed manifest: `CommandService`
stores a payload hash and a bounded ID/version result, not a durable explanation
of attribution changes (`CommandService.php:35-38,148-165`). Do not put an
unbounded audit array into `completed_tasks.deltas` or raw request bodies into
receipts. Review the additional table during Standard A.

All calculations/storage use integer hundredths. For total energy `E` (1-5)
and percentage `P`, `energyMinor = E * P`, `coinMinor = 10 * E * P`.
Totals are exactly `100 * E` and `1000 * E`. Check inputs, intermediate sums,
balance updates and JSON outputs against both PHP/DB bounds and signed
JavaScript safe integers; overflow rejects the entire transaction.

Extend existing Version1030 for the new work/audit/room tables; amend the
existing completion/membership migrations and fixtures. There is no new
Version1034 upgrade path and no backfill. Update Version1015's contribution
index with the renamed column and Version1016's obsolete boost metadata with
its score entity/consumers; a changed early migration must leave the entire
later chain valid. Do not remove WP4-owned fields while
their consumers remain; Q-074-01 must settle the complete removal sequence.

### 3.2 Actual Work and Capacity

- Propose the effective concrete agreement, not today's task-assignee defaults.
  Unassigned solo work starts at 100%; equal proposals use immutable wallet
  order for remainders. Energy 1 shared by three people is visibly 34/33/33%,
  allocating 34/33/33 energy minor and 340/330/330 Coin minor.
- An authorized completion records the actor and actual coworkers, not every
  assigned name. Require 1-100 unique eligible participants, positive whole
  percentages totaling 100, and explicit `allocationReviewed` confirmation of
  any deviation. No per-person confirmation or owner-approval queue.
- Revalidate every new participant's live house membership and task visibility
  under lock. Actual participation creates no new private-task access. Use one
  pure share-validation/remainder implementation for planned and actual shares,
  moving the existing WP2 arithmetic rather than copying it.
- Replace the completed-work mapper query used by `DailyCapacityService` with
  valid selected work shares. Sum each participant's frozen energy on the actual
  work instant; count each participated occurrence once. A fulfilled occurrence
  no longer consumes outstanding planned capacity, including for an absent
  assignee; their personal obligation outcome remains a separate rhythm fact.
- Preserve Q-073-02: actual instants fall within the candidate day's half-open
  UTC interval; saved appointments intersect that interval; historical labels
  and zones are not rewritten. Preserve helper-only release, joint suspension,
  catch-up appointments, restart links and the exact Berlin/New York test vector.
- Real over-limit or paused work and truthful attribution correction remain
  recordable. Recompute overload and invalidate affected previews; never turn
  real work into an extra planned obligation, remove other commitments or
  repair an expired miss to make capacity fit.

### 3.3 Atomic Commands and Wallets

`CompletionService` coordinates complete/undo/attribution through
`CommandService`. `EffortAllocationService`, `WalletService` and
`RoomReplayService` own their narrow domain calculations/writes;
`CompletionEffectService` must no longer restore shared snapshots. Lower
services may use mappers/pure evaluators, not call the completion coordinator
back. Extend the existing occurrence collaborators rather than another engine.

Keep the WP0 lock order: house, sorted room tasks, task definitions, rooms,
active memberships, wallets, occurrence/completion rows, then their dependent
rows. Determine the union of old/new participant wallets after the house lock
and acquire each category in stable ID order. Do not acquire a room lock after
wallets or acquire the house twice from a lower helper.

One transaction rechecks current actor/visibility/version, writes completion
and allocation/audit facts, additive entries/caches, room history, occurrence
and capacity projections, affected progress and the receipt. Bump the house
state version so scheduling previews become stale. No notification/network
work occurs while locked; post-commit refresh failures cannot report committed
work as an uncommitted failure.

Initial credit is the selected allocation. Attribution writes only
`newAllocation - oldAllocation` for the union of participants; removal means
a new allocation of zero. Undo reverses the **latest** selected allocation
once, not the original one and not all historical revisions. Unrelated debits,
credits and reservations remain unchanged. `fundingHold` derives from
`bookedMinor < reservedMinor`; there is no reservation lifecycle in this slice.

Authorize before receipt replay and again under the write lock. Canonicalize
participant order and include action, target, expected version, shares and
review/reason inputs in command identity. Same UUID/input returns the original
completion/revision identity plus freshly authorized current state; changed
input is 409. Use one confirmation identity and suppress repeat effects on
replay. Keep bounded transient-DB retry with the same UUID, not blind retries
of a changed target or authorization failure.

### 3.4 Targeted Correction and Timeline Replay

- Address a specific numeric completion ID, including older completions of the
  same recurring task. Completer or current authorized owner may mutate;
  participation alone is not correction authority. Structural tombstones retain
  existing history access, not permission to resume deleted tasks.
- Initial completion version is 0 and allocation revision is 1. Attribution
  increments both; undo increments completion version and marks the selected
  allocation invalid through `undone_at`. Audit revisions remain immutable.
  A new command on an already-undone target has no additional effect; a stale
  live target conflicts. Specify/check terminal handling before version handling
  only after authorization, with a stable no-op result.
- Attribution preserves the real work time, saved zone, occurrence, frozen
  total effort/rate and room event. It cannot edit planned date, completion
  time or total energy. Q-074-02 settles removal of the audit completer from
  actual participation; never manufacture work merely to satisfy an actor rule.
- Undo marks the completion invalid before re-deriving occurrence projections.
  Preserve later valid completions, agreed amendments, saved time bases,
  historical misses, neutral pause provenance and unrelated checklist progress.
  Recompute catch-up coverage from remaining valid work rather than clearing
  another completion's coverage indiscriminately.
- Replace the open-successor deletion in `OccurrenceCompletionService.php:298-313`
  with an explicit-agreement/progress provenance check as well. It already
  protects completed successors, but does not check amendments before deleting
  an open row. Only still-open automatically derived future
  dates may be recalculated. A completed successor or explicit catch-up/restart/
  amendment survives. Do not delete successor checklist/member evidence to
  obtain a convenient recurrence result or reactivate a tombstoned structure.
  Likewise, do not restore old room-task claim markers over a later valid claim;
  derive current claim state from its surviving agreement/work provenance.
- Refresh affected actual-work capacity, room projection and dependent personal
  progress. Q-074-01 is an activation gate precisely because correct monetary
  deltas alone do not satisfy C3's streak/record/award correction requirement.

### 3.5 Room Journal and History Boundaries

Record a creation baseline before any room mutation, including setup and seed
paths. Replay valid events by `(effective_at, id)` using the saved requested
effects and clamps, not today's task energy/config and not observed clamped
deltas. Capture timestamp behavior explicitly: correction execution time must
never become `last_cleaned`.

| Writer | Required journal behavior |
|---|---|
| Completion | One total-room effect, independent of the number of actual participants; source is the stable completion ID. Undo invalidates this effect only. Attribution never changes it. |
| `RoomService::updateRoomCleanliness` | Record the requested delta, not an absolute value or only the realized difference. House/room locking and event insertion/cache update are one transaction. |
| Production `POST /house/reset` | One absolute reset event per room: cleanliness 100, monsters 0, `last_cleaned = null`; house health 100 remains the documented reset behavior. It never purges work, wallets or history. |
| Creation/setup/test seed | Establish explicit baseline facts; test history must contain coherent completion/allocation/entry/event facts, not orphaned balances. |
| Derived health/monster/job writes | Baseline inventory found no cron cleanliness writer: `TimelineJob` writes occurrence projections and freeze/restock jobs write memberships. Preserve existing health/monster derivation; do not invent periodic decay. Any newly found actual room-state writer must join the journal. |
| Test-only full reset | Clear dependent audit/share/event rows in safe order under existing test-mode authorization; this is not a production history boundary. |

Examples: baseline 90, false requested +20, later valid +20 replays to 100
after undo, with the later valid timestamp. A later reset remains authoritative
over earlier corrections. Freeze explicit reset values while recomputing only
its successors. Unlike the parent's older inventory, this baseline has no
separate unused absolute cleanliness setter in `RoomMapper`; audit generic
entity/mapper updates and the explicit reset path instead, so none can bypass
the journal.

Bound replay reads with indexed cursor batches and measure query budgets. An
atomic correction must finish or roll back entirely; never truncate history,
silently accept partial replay or add a mutable snapshot as a second authority.

### 3.6 API, History and Privacy

All paths are relative to `/apps/chorestrike/api/v1`. Keep authenticated,
CSRF-protected `@NoAdminRequired` routes and the shared envelope/error formatter:
400 malformed, 403 visible-resource role denial, 404 invisible/foreign target,
409 stale/business conflict. Use closed payloads and service-side checks.

| Endpoint / DTO | Contract |
|---|---|
| `POST /room-tasks/{id}/complete` | Existing command/occurrence/version/token plus `participants:[{userId,sharePct}]` and `allocationReviewed`. Time, rate and total work are server-owned. Return completion ID/version/allocation revision and caller-safe current projections. |
| `GET /completions/{id}` | Versioned original work, selected allocation, authorized correction history and `canCorrect`; no foreign wallet totals or raw audit snapshots. GET is write-free. |
| `POST /completions/{id}/undo` | `commandId`, `expectedVersion`, bounded `reasonCode`; target the exact completion. Remove the old room-task latest-undo route and callers, without a compatibility alias. |
| `PUT /completions/{id}/attribution` | `commandId`, `expectedVersion`, participants, explicit review and bounded `reasonCode`; never accept time/energy/occurrence edits. |
| `GET /house/activity` | Stable completion ID and occurrence key, frozen total effort, viewer-safe allocation/revision, original time and undone/corrected state. Retain privacy redaction and history navigation; remove `wasCombo` and live-definition effort. |
| `PUT /rooms/{id}/cleanliness`; `POST /house/reset` | Preserve delta versus absolute-reset semantics and existing roles. Add `commandId` and `expectedVersion` against the displayed house state version; record the journal and receipt together. Expose that version in the corresponding reads. Same-command replay must not repeat a delta or reset over later work. |
| Own game/user state | `wallet:{bookedMinor,reservedMinor,availableMinor,fundingHold}`, own `contributionMinor` and a state version. Foreign member/fairness DTOs contain no Coin totals; do not substitute wallet ranks. |
| Snake scores/bests/leaderboard | Scores only; retain validation, house scope, save limits and supported-game whitelist. Remove Coin mint/read/boost purchase/catalog routes and their response fields. |

Multi-person actions return the caller's wallet and only viewer-safe affected
resource/version references; another participant's per-completion share is not
permission to reveal their total wallet. Refresh authorized store slices after
commit rather than applying a foreign completer's total to the owner actor.
Keep history reads bounded but make older correctable IDs discoverable through
time-range/paged activity navigation, with stable time/ID ordering and the same
viewer filtering on every page; a latest-only snackbar is not the history API.

Ordinary departure preserves wallet and historical shares but grants no access;
later correction can adjust that retained wallet without recreating membership.
Newly added participants must be currently eligible; historical departed shares
remain auditable/removable. Account erasure purges personal finance and binding,
anonymizes share/audit/room actor references and bounded metadata, and retains
only indispensable non-identifying shared facts. Do not recreate erased wallets,
reattach same-named accounts or expose old IDs through correction DTOs. Extend
WP0 erasure and tombstone tests for every new table.

### 3.7 Minimal Client and Economy Cutover

- Reuse task lists and history. Bring `ParticipationDialog.vue` and
  `minorUnits.js` forward from the parent's WP6 manifest for the functioning
  minimum: show target date, actual people, exact shares and total before work
  is recorded; reuse the form for attribution correction.
- Snackbar queues store completion IDs/versions, not just room-task IDs.
  Activity history exposes authorized older-completion correction; owner actions
  reconcile the owner's own state and invalidate other authorized slices.
- Preserve the draft and command UUID on transport failure. On 409, retain the
  draft and require refresh/review; do not silently retarget or resubmit a new
  UUID. Reset account/house state when identity changes.
- Cut over all amount consumers together. Localize display with exact minor-unit
  formatting, including negative/sub-Coin amounts and safe-integer boundaries.
  Never round to whole Coins, reuse `coins` for cents or reconstruct financial
  authority from formatted decimals.
- Remove live boost calls/buttons when the backend routes disappear. Preserve
  Snake play/save/highscores without coin awards, spending or chore contribution.
  Remove the 2048/Memory cards, imports and score loads at the same time: both
  mounted prototypes consume the disappearing economy store. Bring their and
  BoostShop's source/test retirement forward from WP6 with pinned supported-Snake
  replacements and updated gate discovery. Full optional-area redesign stays WP6.
- Remove stale multiplier/teamwork-bonus promises on touched completion/user
  surfaces. Recognition fields and their required tests follow the selected
  Q-074-01 closure, not invented zero/empty success placeholders.
- New domain/UI text uses `t()` with EN/DE/POT in the same change. Verify dialog
  focus, validation, undo and history actions on desktop/mobile and keyboard.
  No general chore-approval UI or reward catalog is added.

## 4. Tests

These are required future tests, not results of this documentation round.
Parent scenario numbers refer to its ordered C2/C3 tables. Existing suites
must be rewritten for the new contract, not dropped merely because snapshots
or multiplier expectations no longer match.

| Group | Required evidence | Concrete files (brace lists enumerate files) |
|---|---|---|
| T3 actual allocation / C2.1,8-11; C3.1-3,7 | Energy 1-5, equal groups 1-100, input/role order, custom partitions, invalid/duplicate/zero/fractional shares, overflow, frozen effort, absent assignees and explicit deviation review. | N `tests/unit/Service/EffortAllocationServiceTest.php`; M `tests/unit/Service/{GameLogicService,GameLogicServiceTeamwork,DailyCapacityService}Test.php`; M `tests/integration/Service/TeamworkRewardTest.php`. |
| Capacity seam | Actual-share completed/outstanding dedupe, early/catch-up/helper work, overload accepted, extra plans rejected, Q-073-02 zone vector, correction invalidates stale previews without rewriting pauses. | N `tests/integration/Db/WorkShareMapperEnergyTest.php` (final-review mapping 2026-09-27, RF-074-WP3-09: real-DB selected-revision/valid-window/undo pins through `findSelectedSharesBetweenByHouse` + `evaluateDays`, replacing the retired completer-only `CompletedTaskMapperEnergyTest`); M `tests/integration/Service/{OccurrenceTimeline,SchedulePlanAggregate,SchedulePlanConcurrency}Test.php`. |
| T4 wallets / C3.4-7,15 | Credit -> independent debit/earn -> older undo; corrected then undone allocations; negative balance/repayment; reservation hold arithmetic; ledger sums equal caches; injected failures after first wallet and room update roll everything back. | M `tests/unit/Service/WalletServiceTest.php`; N `tests/integration/Service/CompletionCorrectionTest.php`; M `tests/integration/Service/CompletionUndoStateEqualityTest.php`; M `tests/unit/Service/CompletionServiceTest.php`. |
| T4 timeline / C3.5-7 | Older same-task undo retains later completions and explicit amendments/restarts; only open automatic interval successors change; unrelated checklist and coverage survive; attribution preserves time/occurrence/room. | M `tests/integration/Service/{OccurrenceTimeline,CompletionUndoStateEquality}Test.php`; N `tests/integration/Service/CompletionCorrectionTest.php`. |
| T4 room / C3.12-14; CR-SVC-63 | Clamp example, manual delta, equal-time ordering, reset before/after undo, creation/setup coverage, retained `last_cleaned`, house/monster cache consistency and no unjournaled writer; command replay of manual delta/reset preserves later work. | N `tests/unit/Service/RoomReplayServiceTest.php`; N `tests/integration/Service/RoomReplayConcurrencyTest.php`; M `tests/unit/Service/{RoomService,HouseService}Test.php`. |
| Real races | Complete/complete, correct/undo, correct/correct, older undo/newer completion, manual/reset vs correction, member removal/role loss/erasure vs work. Fresh connections inspect evidence, entry sums, projections and receipts. | M `tests/integration/Service/{CompletionConcurrency,ConcurrentCompletion,HouseMutationConcurrency}Test.php`; N `tests/integration/Service/{CompletionCorrection,RoomReplayConcurrency}Test.php`. |
| API / T0 retention | Malformed/foreign/private targets, owner without private access, participant-only denial, rights on receipt replay, tombstones, departed wallet, erasure and same-UID non-rebinding; fresh indexes/test reset. | N `tests/unit/Controller/CompletionControllerTest.php`; M `tests/unit/Controller/RoomTaskControllerTest.php`; M `tests/integration/{RoleGuardApiTest,Db/FamilyRhythmSchemaTest}.php`; M `tests/integration/Service/{AccountErasure,HouseMemberRemovalIntegration}Test.php`. Final-review mapping (2026-09-27, RF-074-WP3-09): `RoleGuardApiTest` is unchanged because WP3 adds no member-role semantics — the new completion routes' authorization is pinned in `CompletionControllerTest` (400 closed-set/403 no-authority/404 anti-enumeration/409 stale), the integration authority matrix in `CompletionCorrectionTest` (participant-only denial, replay after demotion/visibility revocation) and the insights-flow E2E wire flows. |
| History / amount contract | Frozen multi-person history and correction authority; no foreign wallet totals or raw audit metadata; current actor-safe totals after owner corrections. | M `tests/unit/Service/ActivityServiceTest.php`; M `tests/integration/Controller/HouseActivityIntegrationTest.php`; M `tests/unit/store/{roomTasks,game,user,apiContract}.spec.js`; M `tests/unit/components/{ActivityFeed,MemberFairnessTable,UndoSnackbar}.spec.js`; N `tests/unit/utils/minorUnits.spec.js`. |
| T10 / D7d | Snake saves and former monetary routes never write wallet entries/contribution; concurrency retains score limits; no active boost consumer. | M `tests/unit/Service/MinigameServiceTest.php`; M `tests/integration/Service/MinigameConcurrencyTest.php`; M `tests/unit/store/minigame.spec.js`; M `tests/unit/components/{ArcadeSection,SnakeGame}.spec.js`; M `tests/e2e/snake-game.spec.ts`. |
| Minimal UI / browser | Actual 34/33/33 split, explicit changed proposal, earlier completion undo from history, owner attribution, negative decimals after reload, lost response replay, preserved 409 draft and no-Coin Snake. | N `tests/unit/components/ParticipationDialog.spec.js`; M `tests/unit/components/{TaskList,TaskListDetails}.spec.js`; M `tests/e2e/{teamwork-rewards,task-completion-full-flow,workflow-state-reconciliation,family-rhythm}.spec.ts`. Fix-round mapping (2026-09-27): "owner attribution" + "preserved 409 draft" are browser-pinned in `tests/e2e/insights-flow.spec.ts` (*owner corrects a recorded work split from the activity history*) with the 409 draft retention pinned in `tests/unit/views/InsightsView.spec.js`; "earlier completion undo from history" remains the snackbar/history undo pinned in `today-view`/`task-completion-full-flow` + the targeted-undo contract in `release-task-lifecycle`; `workflow-state-reconciliation.spec.ts` covers navigation read-reconciliation only — the correction read-reconciliation cases map to `insights-flow.spec.ts` (feed → read model → corrected read model) and `CompletionConcurrencyTest` (stale-version conflicts). |

Also update the existing serialization/route/consumer pins in
`tests/unit/Db/Entity/{CompletedTask,HouseUser,MinigameScore}Test.php`,
`tests/unit/Db/Mapper/{HouseUserMapper,RoomMapper,MinigameScoreMapper}Test.php`,
`tests/unit/Controller/{GameController,HouseController,RoomController,MinigameController}Test.php`,
`tests/integration/Controller/{RoomTaskControllerIntegration,RoomControllerIntegration,HouseControllerIntegration,MinigameControllerIntegration}Test.php`,
`tests/integration/Db/WorkflowQueryBudgetTest.php`,
`tests/unit/store/{house,rooms}.spec.js`, `tests/unit/components/SnakeLeaderboard.spec.js`
and `tests/unit/views/{InsightsView,Leaderboard,Dashboard,Settings}.spec.js`.
Keep history/allocation reads bounded and test room replay beyond one batch.
The changed completion-event shape also updates
`src/composables/useCelebration.js` and
`tests/unit/composables/useCelebration.spec.js`; remove obsolete combo handlers,
and handle Q-074-01's recognition identity without replayed celebrations.
Sound/motion preferences and the full calm-feedback redesign remain WP5/WP6.

Q-074-01 option A additionally owns the parent's complete relevant T5 set:
N `tests/unit/Service/{RhythmService,WeeklyGoalService}Test.php`,
N `tests/integration/Service/RhythmProjectionTest.php`,
M `tests/unit/Service/{MedalService,TitleService,HouseService}Test.php`, and
the required recognition/job/browser replacement scenarios. Pin C1.6-8,
C2.7 and C3.8-9: ordered pending days, neutral days, 80%/zero/provisional weeks,
retained 3/6/12 awards, alternate valid runs, corrected records and stable
celebration identity. This conditional scope is not permission to skip it if
selected, or to remove existing required freeze/title tests before replacements.

Use injected clocks (UTC, Europe/Berlin DST and saved-zone changes). Real race
tests need two connections and barriers; sequential double-calls prove only
idempotency. Keep test-only independent spending/reservation fixtures separate
from assertions that actual WP7 endpoints exist. Exercise fresh-install/reset
and test-mode denial, not just entities against an old development database.

**Implementation gates:** `npm test -- --runInBand`, `npm run test:noise`,
`npm run lint`, `npm run build`, `npm run l10n:check-keys`,
`npm run backlog:check`, `npm run docs:encoding`, `git diff --check`;
Docker PHPUnit unit and integration with `--fail-on-skipped`, plus
`composer cs:check`, using the [canonical Docker workflow](../../standards/ai/skills/docker-dev/SKILL.md).
Run `npm run test:e2e:gate`, not a substituted passing subset. Keep
`tests/e2e/required-scenarios.json`, `scripts/run-e2e-gate.js` and
`playwright.config.ts` aligned when scenarios/routes retire or change.
No new/unapproved skips, retries or test-noise suppression. WP9's repeated
combined browser/DB/family gate is additional, not a substitute for WP3 tests.

**E2E coverage notes (round 2, closes the Step 5 §D minor finding):**
- **ActivityFeed `totalEnergy`** — the feed renders the frozen work evidence
  (`totalEnergy` + live `energyCost`) with NO `coinsEarned`: pinned on the
  API contract in `HouseActivityIntegrationTest` (integration, real rows)
  and on the rendered feed in `insights-flow.spec.ts` (activity item
  present, no coins column in the fairness table).
- **CompletionController attribute payload** — the closed receipt set
  (`commandId`, `expectedVersion`, `participants`, `allocationReviewed`,
  `reasonCode`), the closed-set rejection of time/energy/occurrence edits
  and the 400/403/404/409 mappings are pinned in
  `tests/unit/Controller/CompletionControllerTest.php`; the wire behavior
  (targeted `POST /completions/{id}/undo` with receipt + idempotent
  replay) is covered by `release-task-lifecycle.spec.ts` and the undo
  flows in `today-view`, `family-core-ux`, `task-completion-full-flow`
  and `core-household-journey`.
- **ParticipationDialog proposal** — the server proposal block (agreed
  shares pre-fill) is unit-pinned (`RoomTaskReadServiceTest`,
  `ParticipationDialog.spec.js`); a dedicated browser scenario for the
  dialog remains owned by the minimal-client acceptance criterion
  (§8 "Minimal participation ... work after reload").

## 5. File Manifest

`M` means existing and modified; `N` means proposed new after activation;
`R` means archive with replacement coverage, never silently delete a required
surface. Section 4's test paths are part of this manifest. Recheck collisions
at activation and keep edits limited to actual contract consumers.

| Package | Files |
|---|---|
| Schema | M `lib/Migration/{Version1004Date20260228131728,Version1015Date20260304000000,Version1016Date20260314000000,Version1027Date20260725000000,Version1029Date20260906000000,Version1030Date20260918000000}.php`; conditional WP4 field retirement follows Q-074-01. |
| Work / audit / journal | N `lib/Db/{WorkShare,WorkShareMapper,CompletionRevision,CompletionRevisionMapper,RoomEvent,RoomEventMapper}.php`; N `lib/Service/{EffortAllocationService,RoomReplayService}.php`; M `lib/Service/WalletService.php` (preserve `ensureBinding`, add ledger behavior); M `lib/Db/{CompletedTask,CompletedTaskMapper,Wallet,WalletMapper,WalletEntry,WalletEntryMapper,HouseUser,HouseUserMapper,Room,RoomMapper}.php`. |
| Completion / capacity | M `lib/Service/{CompletionService,CompletionEffectService,CompletionPostCommitService,GameLogicService,RewardConfigService,DailyCapacityService,OccurrenceService,OccurrenceCompletionService,OccurrenceReconcileService,SchedulePlanService,RoomTaskService,RoomTaskReadService,TaskAccessPolicy}.php`; M `lib/Db/{Task,OccurrenceMapper,OccurrenceMemberMapper}.php` for obsolete earning helpers and replay/attribution. |
| Room / lifecycle | M `lib/Service/{RoomService,HouseService,HouseSetupService,AccountErasureService,TestDataService}.php`; audit all room cache/job writers during step 1. |
| API / reads / arcade | N `lib/Controller/CompletionController.php`; M `lib/Controller/{RoomTaskController,RoomController,MinigameController,HouseController,GameController,UserController}.php`; M `lib/Service/{MinigameService,ActivityService}.php`; M `lib/Db/{MinigameScore,MinigameScoreMapper}.php`; M `appinfo/routes.php`, `tests/unit/RouteMatrixTest.php`; M `lib/AppInfo/Application.php` only for necessary bindings. |
| Client cutover | M `src/store/{roomTasks,rooms,game,user,house,minigame}.js`; M `src/components/Tasks/{TaskList,TaskListItem,TaskListDetails}.vue`; M `src/components/Common/UndoSnackbar.vue`; M `src/components/Insights/{ActivityFeed,MemberFairnessTable}.vue`; M `src/components/Dashboard/{QuickStats,ArcadeSection}.vue`; M `src/components/Minigames/{SnakeGame,SnakeLeaderboard}.vue`; M `src/views/{InsightsView,Leaderboard,Settings}.vue`; N `src/components/Tasks/ParticipationDialog.vue`, `src/utils/minorUnits.js`. Existing `src/services/api.js` envelope/error transport is reused unchanged unless an actual contract need arises. |
| Arcade retirement | R `src/components/Minigames/{BoostShop,Game2048,MemoryGame}.vue`, `tests/unit/components/{BoostShop,Game2048,MemoryGame}.spec.js`, `tests/e2e/release-boost-shop.spec.ts` only with pinned supported-Snake/no-Coin replacements and discovery changes. This is the necessary live-consumer pull-forward from WP6, not its full optional-area redesign. |
| Fixtures / gates / l10n | M `tests/integration/IntegrationTestCase.php`, `tests/fixtures/{FamilyTestData,TestDataFixtures}.php`; M `tests/e2e/fixtures/{database-fixtures,test-house}.ts`, `tests/e2e/required-scenarios.json`, `scripts/run-e2e-gate.js`, `playwright.config.ts`; M `l10n/{en,de}.json`, `l10n/templates/chorestrike.pot`. |
| Durable documentation | M `docs/api/README.md`, `docs/engineering/{architecture,testing}.md`, `docs/concepts/{task-model-and-sync,energy-and-balancing,gamification-and-rewards,ui-use-cases}.md`, `docs/testing/release-test-plan.md`; M `docs/adr/{ADR-004-multi-user-gamification,ADR-011-transactional-completion-and-undo,ADR-012-actionable-household-scheduling,ADR-013-occurrence-agreements-and-corrections}.md`, `docs/standards/ai/skills/gamification/SKILL.md`; N the parent-reserved WP3 accounting ADR, delivered as `docs/adr/ADR-014-work-accounting-ledger-and-replay.md` (fix-round name refinement over the reserved `ADR-014-family-wallet-and-redemption.md` placeholder; WP3 accounting accepted, WP7 redemption explicitly marked pending in the ADR itself); M `docs/adr/README.md`, `docs/README.md`. |

Conditional Q-074-01 option A also brings forward N
`lib/Service/{RhythmService,WeeklyGoalService}.php` and the necessary M
`lib/Service/{MedalService,TitleService}.php`, `lib/Db/{Medal,MedalMapper,HouseMapper}.php`,
`lib/Titles/TitleCatalog.php`, `lib/BackgroundJob/TimelineJob.php`,
`appinfo/info.xml`, `lib/Command/TriggerJobs.php`, the parent's recognition
migration/client/job removals and section 4 T5 replacements. Standard A must
expand and ratify that exact cutover before activating, including any dependent
at-risk/title notification callers; no placeholder service or dangling DI edge.

Preserve retired source under `archive/YYYY-MM/cs-plan-074-retired/` at
implementation time; it is gitignored, not public test evidence. Keep production
seed/reset, integration cleanup and browser fixtures coherent whenever a table
changes; these are not deferred to WP9.

The **drafting round** changes only this new plan, the parent WP3 pointer,
plans/docs indexes, CONCEPT-001/CR-SVC-63 links, `ACTIVE_TASKS.md`, and the
broken parent link in the moved WP2 plan. Preserve the pre-existing
`docs/plans/{new,active,onhold}/.gitkeep` deletions without staging/restoring them.

## 6. Implementation Order

One implementation owner coordinates shared files. Internal read-only lookups
may assist; synthesis and writes remain with the main agent. This plan neither
starts Agent Manager sessions nor creates a branch.

| Step | Work and exit evidence | Effort |
|---|---|---|
| 0. Activate the contract | Standard A resolves Q-074-01/02, freezes audit schema/actor rules and the complete correction-projection cutover, updates parent ownership/estimates and required test assignments, then activates. Drafting is not this review. | Review prerequisite |
| 1. Lock the baseline | Recheck all room/monetary writers, read/DTO consumers, retention and test gates at current HEAD; map each case to an assertion. Pin known wrong outcomes before replacement. | 0.5-1 day |
| 2. Work and wallet cutover | Fresh schema, immutable allocation/audit, exact math, all-wallet transaction, no-Coin arcade and current-amount consumers together. T3 and ledger/property/overflow/rollback proofs. | 2-3 days |
| 3. Room journal | Creation/setup/reset/manual/completion writers, chronological replay and derived caches; CR-SVC-63 race and clamp/reset evidence. | 1-2 days |
| 4. Targeted correction | Specific-completion controller/commands, attribution deltas, historical timeline/checklist preservation, actual-capacity seam, departure/erasure and real races. No snapshot fallback. | 2-3 days |
| 5. Client and closure | Finish participation/history/undo/reconciliation, exact formatting and gate replacements; durable docs, full gates and final review package. | 1.5-2 days |
| Correction-closure slice (Q-074-01 A selected 2026-09-25) | Implement the approved minimum rhythm/weekly/retained-recognition correction evaluators, retire the obsolete coupled effects and supply T5 before step 5 can exit. | 3-5 days (committed scope) |

Steps 2-4 are development checkpoints, not independently releasable economies.
Keep schema and consumers coherent; do not merge a half-converted balance
contract. Budget approximately six focused hours per engineering day, including
tests/docs/one correction pass; these are estimates, not promised calendar time.
Re-estimate after the activation decisions rather than silently hiding added work.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|---|---|---|
| Cents mistaken for whole Coins | High | Rename storage/DTO fields, remove old writers, test every consumer and boundary formatting; no dual schema or compatibility alias. |
| Correct money but false streaks/awards | High | Q-074-01 blocks activation; required replacement evidence before any full-correction claim. Never restore obsolete snapshots or invent a disposable legacy engine. |
| Older undo deletes later valid history | High | Test completed/explicitly amended successors, neutral restart, catch-up coverage, checklist and tombstone preservation. |
| Hidden room writer corrupts replay | High | All-writer inventory plus creation/reset/equal-time tests and query budgets; no subtracting clamped deltas. |
| Multi-person mutation partially commits | High | Stable house-first locks, union of affected wallets, unique sources, receipts and failure injection after early writes. |
| Capacity silently returns to completer-only totals | Medium | Mapper-level selected-share tests plus planner/daily integration using saved-zone facts. |
| Departure/erasure leaks or recreates identity | High | Preserve ordinary wallet history separately; anonymize/purge all new references and prove same-UID non-rebinding. |
| Required tests disappear with old features | High | Pin replacements in the canonical gate before retirement; conditional T5 scope explicitly resolved, no skipped workaround. |

## 8. Acceptance Criteria

- [x] Q-074-01/02 resolved and recorded; exact package scope, estimates and manifest accepted through Standard A.
- [x] All valid work uses frozen effort, positive conserved actual shares and exactly 10 Coins per energy; planned names alone earn nothing.
- [x] Wallet entries are the sole monetary authority; every cache equals its entry sum, negative corrections are supported and independent reservations/debits survive.
- [x] All monetary writers/readers use safe integer minor units; no arcade mint/spend, multiplier, legacy Coin balance or disconnected live consumer remains.
- [x] Older completion undo and attribution are authorized, versioned, auditable and idempotent; all affected participants commit or roll back together.
- [x] Correction preserves later completions, explicit agreements, neutral pause facts, saved zones, independent checklist work and unrelated spending.
- [x] Full room journal/replay covers creation, completion, manual delta and reset, correct clamps/timestamps and derived caches; CR-SVC-63 regression passes.
- [x] Capacity and overload use selected actual shares with WP2's saved-zone/dedupe rules; actual work is never refused merely for exceeding a plan.
- [x] Correction-dependent progress is correct under the selected Q-074-01 closure; no snapshot restore or falsely successful projection is left behind.
- [x] Minimal participation, older-history correction, targeted snackbar, exact decimals and no-Coin Snake work after reload on desktop/mobile and keyboard.
- [x] Current authorization/privacy, tombstones, departure, account erasure and same-UID non-rebinding are proven for new evidence and response shapes.
- [x] Every section 4 required test/gate passes without new/unapproved skips; real races and rollback are demonstrated, not replaced by sequential calls.
- [x] All plan-owned findings resolved or explicitly user-rescoped; no required dependent test is deferred while marking this plan done.
- [x] Durable behavior integrated into concepts, ADRs, API, engineering/testing and gamification skill; the plan is not the only description.
- [x] CR-SVC-63 archived only after its complete remaining contract passes; CONCEPT-001 and partially fulfilled parent findings stay open.
- [x] Final Standard B or Full review passes, performs the eligible lifecycle move, and updates parent/index/backlog/`ACTIVE_TASKS.md` accurately.

## 9. Review Findings (Plan-Owned)

The Standard A activation review (2026-09-25) found no defects; see the
[Review Outcome](#review-outcome) below. Route newly discovered in-scope
implementation/review findings here; existing parent findings and CR-SVC-63
keep the scope ownership described in section 2. The section 10 decisions are
resolved activation decisions, not findings.

## 10. Activation Decisions

Resolved through the Standard A question batch on 2026-09-25; both decisions
selected the recommended Option A. The original alternatives and review
evidence remain in the
[activation record](../../backlog/archive/CODE_REVIEW_2026-09-25.md#code-review-cs-plan-074-standard-a-plan-activation).

| ID | Decision and options | State |
|---|---|---|
| Q-074-01 | **Correction closure across WP3/WP4.** A (recommended): move the minimum planned-day/weekly/retained-recognition correction slice, its coupled legacy-effect retirement and T5 into WP3; leave consent/ranking and remaining UI with WP5/WP4/WP6. Update parent ownership and estimate explicitly. B: preserve package ownership but agree one coordinated WP3 -> WP5 -> WP4 implementation/finalization boundary; WP3 cannot be independently declared delivered while the required correction-dependent projections are unfinished. Neither option permits snapshot fallback, a dummy legacy engine or skipped required tests. | RESOLVED 2026-09-25: Option A ” minimum slice committed to WP3 (+3-5 days). |
| Q-074-02 | **Correcting a falsely attributed completer.** A (recommended): the completer or authorized owner may remove the original completer from actual shares while keeping the original audit actor/time immutable; only the corrected actual people receive effort/Coins/personal credit. Initial completion still requires self-participation. B: require an authorized owner for this particular correction while keeping ordinary share correction with the completer. Record the selected permission rule and tests, without adding task visibility or a general approval queue. | RESOLVED 2026-09-25: Option A ” completer or authorized owner; audit actor/time immutable. |

**Q-074-01 resolution (Option A).** WP3 owns the minimum
planned-day/weekly/retained-recognition correction evaluators, the coupled
legacy-effect retirement (freeze/restock/title snapshot effects) and the
parent's T5 set; the correction-closure slice is committed scope (header
effort and section 6 updated; parent WP3/WP4 rows updated). Consent/ranking
and the remaining rhythm/UI build-out stay with WP5/WP4/WP6. No snapshot
fallback, no disposable legacy engine, no skipped required tests.

**Q-074-02 resolution (Option A).** The completer or the authorized owner may
remove the original completer from the actual shares while the original audit
actor/time stay immutable; only the corrected actual people receive
effort/Coins/personal credit. Initial completion still requires
self-participation; no new task visibility and no general approval queue.

Q-074-01 is not solved by guarded numeric reversal: a later completion may
leave `currentStreak != recordedAfter`, so a guard preserves a false run;
adding back a consumed freeze after a later absolute restock changes that
restock's result; removing `titlesAdded` can erase a tier supported by a later
valid run. C3 needs valid-history evaluation, not comparisons with an old cache.

All other rules are parent-derived engineering proposals: one wallet authority,
fixed rate 10, exact percentages, append-only evidence, preserved WP2 time/pause
semantics, house-first commands and existing privacy boundaries. Any further
material question found during Standard A joins the same batch before transition.

## Draft Validation

Draft checks (2026-09-25): `npm run backlog:check`, `npm run docs:encoding`
and `git diff --check` passed. The unchanged document checkers also passed
with Git's tracked-plus-untracked inventory supplied in-process (27 link-scoped
files; 791 encoding-scoped paths), and the new plan passed its standalone
`git diff --no-index --check` whitespace check. No staging or checker edits
were needed. Runtime/file/test inventory and a drafting consistency pass
informed the manifest; they are not a Standard A verdict.

The baseline backlog check exposed the moved WP2 plan's broken parent link;
that one cross-link was repaired in this documentation round. Runtime tests
have not run for this documentation-only request; prior WP2 green gates are
baseline evidence, not WP3 acceptance. Runtime and index remain unchanged.
The next lifecycle step was the Standard A plan review, which ran and passed
later the same day; this file moved to `active/` with its scoped review commit.

## Review Outcome

**Standard A plan review ” PASS** (2026-09-25, main agent; skills: review,
plan-management, git-workflow). Activation record:
[CODE_REVIEW_2026-09-25.md](../../backlog/archive/CODE_REVIEW_2026-09-25.md#code-review-cs-plan-074-standard-a-plan-activation).

- **Scope/base:** this plan (489 lines at capture, untracked) plus its
  documentation-round package (6 dirty tracked doc files: parent plan,
  `docs/README.md`, `docs/plans/README.md`, `backlog.md`, `ACTIVE_TASKS.md`,
  and the WP2 done-plan parent-link repair) against `master` @
  `f365369f51e4e447cdadb34425306ad3982036e5`; index empty at capture.
  Fingerprint: unstaged binary diff SHA256
  `905051b69a10986cbcf895d007dd03a24f2cb06b55a379df620f8be12c422fb6`,
  untracked manifest = this file (46,383 bytes, content SHA256
  `d98010cd1c8221cf582b1bbe3fad1d775091fb7f76585bac752412c1b751c28f`).
  The three pre-existing `.gitkeep` deletions stayed excluded.
- **Verification:** all twelve section-1 baseline citations exact at HEAD
  (`roomTasks.js:452-457`/`:574-594`, `CompletedTaskMapper::findEnergyUsageBetweenByHouse`,
  `GameLogicService.php:114-140`/`:142-212`, `CompletionEffectService.php:119-154`,
  `RoomMapper.php:158-168`/`:186-202`, `minigame.js:93-101`/`:209-268`,
  `ArcadeSection.vue:148-149`/`:197-204`, `StreakRestockService.php:61-68`,
  `CommandService.php:35-38`/`:148-165`,
  `OccurrenceCompletionService.php:298-313`); every `M`/`R` manifest path
  exists (180 checked, including the conditional Q-074-01-A set now committed)
  and every `N` path is absent (24); `Version1031` is the migration chain head
  and the "no new Version1034" statement is coherent with the parent's
  1032/1033 reservations ” the in-place Version1030 extension follows the
  fresh-install rule; the ADR-014 reservation matches the plan's N path
  exactly; all three C3 concept anchors and ADR-013 resolve; CONCEPT-001 is
  In Progress via the parent, CR-SVC-63's open journal/replay remainder is
  owned here with no parallel backlog item; the parent WP3 row, 7-11-day
  allowance and 3-5-day conditional slice are consistent across header,
  section 6 and the parent; no overlap with the only active plan (the parent).
- **Type-A checks:** stable per-step ownership with individually validatable
  exit evidence; coherent scope (no mixed concerns); risks and dependencies
  named; no pre-baked workaround stacking (snapshot fallback and dummy legacy
  engines explicitly banned); acceptance criteria complete; test plan
  adequate with the full gate list and real-race requirements.
- **Findings:** none. No fixes were required.
- **Decisions:** Q-074-01 Option A and Q-074-02 Option A (both recommended),
  answered through the batched structured question; recorded in section 10
  and applied to the header status/effort, the section 6 slice row and the
  parent WP3/WP4 rows.
- **Gates:** `npm run backlog:check` (26 files link-checked), `npm run
  docs:encoding` (790 files), `git diff --check` staged+unstaged clean, plan
  standalone whitespace clean (`--no-index --check` reports no errors).
  Runtime gates not applicable ” documentation-only review, runtime tree
  untouched.
- **Transition:** plan moved `new/` → `active/`; CR-SVC-63 set In Progress
  with this plan owning; parent, READMEs, backlog and `ACTIVE_TASKS.md`
  links updated; activation record appended to `CODE_REVIEW_2026-09-25.md`;
  the drafting round lands with the scoped review commit.


---

## Implementation Status (2026-09-25, implementation round 1)

**Scope committed in this round (working tree, uncommitted):** the Steps 2-5
production cutover — schema (Version1030 in-place extension with
`work_shares`/`completion_revs`/`room_events`; amended 1004/1015/1016/1022/
1027/1029; freeze and boost columns retired), the new DB layer
(WorkShare/CompletionRevision/RoomEvent + mappers, CompletedTask/HouseUser/
MinigameScore reshaped), EffortAllocationService (moved WP2 arithmetic),
WalletService ledger, RoomReplayService, RhythmService + WeeklyGoalService
(Q-074-01A minimum slice), the CompletionService complete/undo/attribution
aggregates via CommandService (no snapshot restore, targeted correction,
exact union deltas), GameLogicService/MedalService/TitleCatalog recognition
reshape (rhythm 3/6/12 + weekly milestone; coins/combo/freeze packs retired;
StreakFreeze/StreakRestock/StreakAtRisk + jobs archived under
`archive/2026-09/cs-plan-074-retired/`), no-Coin arcade
(MinigameService/Controller scores-only), CompletionController + targeted
undo/attribution routes (latest-by-room-task undo route removed), room journal
writers (create/setup/seed baselines, manual delta with command UUID, reset),
erasure sweep extension, capacity seam cutover (selected actual shares),
client cutover (roomTasks/user/game/minigame/rooms stores, UndoSnackbar
completion-targeted, ActivityFeed/MemberFairnessTable/Leaderboard reshaped,
minorUnits util, ParticipationDialog component, BoostShop/Game2048/MemoryGame
retired), l10n regeneration with German copy for all new keys, and
frontend gates green: `npm run lint`, `npm test` (1009 pass), stylelint,
`npm run build`, `l10n` gate.

**New backend tests:** EffortAllocationServiceTest (12, conservation/bounds),
RhythmServiceTest (C1f basics), CompletionServiceTest rewritten to the WP3
aggregate contract (11: frozen evidence, one-time reversal, terminal-before-
version ordering, exact union deltas, viewer-safe read model) — all green in
Docker.

## Implementation Status (2026-09-26, implementation round 2)

**Review-findings resolution round (working tree, uncommitted):** all five
plan-owned findings closed (see the resolved checklist above) — the ~20
legacy suites reshaped to the WP3 contract (new `WeeklyGoalServiceTest`,
`CompletionControllerTest`, `ParticipationDialog.spec.js`; teamwork/freeze/
boost sources archived), the proposal block added to the room-task read DTO
(shared `EffortAllocationService` derivation, query-budgeted three batched
reads) with TodayView dialog wiring, `lastFreezeDate` removed, and the
E2E manifest/gate re-cut to the wallet-minor/targeted-undo contract.

**Production defects found and fixed by the re-cut suites:** stale member
stats in the recognition phase (members now re-loaded after the wallet/
contribution writes; complete/undo/attribute), `RhythmService` never
persisting re-derived streaks, `revertClaimIfUnsuperseded` comparing the
house-user id against the NC id (claim/`original_assignee` never reverted),
wallet bindings not ensured for participant-less completions (null wallet
reached the share writer), the completion response's own-wallet block read
from the pre-credit locked entity, the targeted undo re-checking
`undone_at` before the room-task lock, the no-op undo reporting
`undone: true` and firing post-commit effects, `MinigameService` losing its
`HouseUserMapper` collaborator (fatal leaderboard enrichment), the
`GameController` own-wallet read calling a non-existent mapper method, and
`HouseUserMapper` still writing/ordering by the retired `contribution_score`
column.

**Environment note:** the Docker test env was rebuilt fresh
(`down -v`, pre-release fold policy) against the rewritten migration chain;
the E2E gate requires the env started with `TEST_DB_PROFILE=family` (the
entrypoint owns the NC account passwords the browser auth setup reuses).

**Gates (round 2, final):** Docker unit `935 tests / 2882 assertions` and
integration `430 tests` zero-fail/zero-skip (fresh-install chain), Jest
`1015` (+noise clean), lint, build, stylelint, `l10n` (987 keys),
`cs:check` 0-of-350, backlog/encoding/diff-check green, and the **E2E gate
PASSED** — all three pools (chromium, chromium-serial-db,
chromium-scheduling-owner) sequentially with zero skipped/failed/flaky
required scenarios. The browser suites exposed and fixed two further
round-2 defects before the passing run: the `CompletedTask` frozen-fact
entity defaults silently dropping a 1-energy/1000-rate/0-version/1-revision
INSERT (the OCP setter-skip trap — schema defaults added as the safety net)
and the room-detail/task-manager/today completion paths bypassing the
participation dialog on agreed-share work (the dialog now opens whenever
the proposal is multi-person or names someone other than the actor, with
the actor auto-joining a foreign split at an equal renormalized share; a
solo agreement naming the actor completes directly; the proposal block is
serialized on every read path — flat list, filtered-daily, due-today and
overdue).

### Review Findings (open, block `done/`)

- [x] **block** — Legacy backend unit suites not yet re-cut to the WP3
  contract (~254 errors / 14 failures across ~20 suites in Docker):
  GameLogicService(+)Teamwork (coins/combo/streak orchestration tests vs. the
  read-only serializer), HouseServiceTest (resetHouse signature/journal,
  weekly-goal delegation, stats shape), RoomServiceTest (cleanliness journal
  + expectedVersion), MedalServiceTest (old activity/combo catalog vs.
  retained rhythm awards), TitleServiceTest (cozy-home coins pack vs.
  family_rhythm), MinigameServiceTest (coins/boosts vs. scores-only),
  AccountErasureServiceTest (new constructor args), DailyCapacityServiceTest
  (work-share seam), SchedulePlanServiceTest (share-implementation move),
  HouseSetupServiceTest (room baseline journal), TestData/TriggerJobs/
  DailyNotificationJob/Notifier tests (freeze/at-risk passes retired),
  entity/mapper tests (CompletedTask/HouseUser/MinigameScore shapes),
  Migration smoke (Version1030 new tables), controller suites
  (RoomTaskController closed-set participants, GameController wallet,
  CompletionController new), route pin (undo route moved). These suites pin
  the RETIRED behaviors and must be reshaped (not patched) in the next round;
  archived-for-reference sources live in `archive/2026-09/cs-plan-074-retired/`.
  **Resolved (round 2, 2026-09-26):** all ~20 suites reshaped; the full Docker
  unit suite is green (`935 tests, 2882 assertions`, 0 skips) — the weekly-goal
  evaluation moved to the new `WeeklyGoalServiceTest`, the participation
  dialog got `ParticipationDialog.spec.js`, and retired suites are archived.
- [x] **major** — E2E gate (`npm run test:e2e:gate`) and Docker
  integration suites not yet updated/run against the new contract (fixtures
  in `tests/fixtures/`, TestData seed reset for the new tables, Snake no-Coin
  E2E, participation-dialog flows).
  **Resolved (round 2, 2026-09-26):** Docker integration suite green
  (`430 tests, 2143 assertions`) on a fresh-install chain (`down -v` rebuild —
  pre-release fold policy); the shared fixture gained the wallet-binding
  membership invariant, work-evidence/undo/proposal helpers; the boost-shop,
  teamwork-bonus, streak-at-risk notification and minigame coin-race E2E
  specs are archived; snake-game now pins the scores-only arcade; the
  household journey, lifecycle, full-flow, today-view, family-core-ux,
  insights and overdue-claiming specs re-cut to the wallet-minor/targeted
  undo contract.
- [x] **major** — Occurrence-proposal exposure for ParticipationDialog: the
  dialog currently renders the assignee-default equal split client-side; the
  server's effective concrete agreement (occurrence member shares) is not yet
  exposed on the room-task read DTO, so an agreed-share proposal can differ
  from the dialog preview. Requires a proposal block on the room-task read
  path (query-budgeted) before the dialog can show the agreement truthfully.
  **Resolved (round 2, 2026-074 §3.6):** `RoomTaskReadService::withOccurrenceBlocks`
  attaches a query-budgeted `proposal` block (`occurrenceId`, `basis`
  agreement|assignees|solo, `participants`) derived through the SAME shared
  `EffortAllocationService::agreementShares`/`equalShares` implementation the
  completion writer uses (preview == credited truth by construction); the
  ParticipationDialog pre-fills from the proposal and TodayView mounts it for
  multi-person completions (unit: RoomTaskReadServiceTest proposal cases +
  ParticipationDialog.spec.js).
- [x] **minor** — HouseUser::jsonSerialize keeps a null `lastFreezeDate`
  placeholder for older client branches — remove once the client consumers
  are confirmed cut over.
  **Resolved (round 2):** no production consumer reads `lastFreezeDate`
  anymore — the placeholder is removed from `jsonSerialize` and its absence
  pinned in `HouseUserTest` and the users-payload integration test.
- [x] **minor** — ActivityFeed `totalEnergy` and CompletionController
  attribute payload need explicit E2E coverage notes in the gate plan
  (Step 5 §D).
  **Resolved (round 2):** gate-plan coverage notes added — see
  `## 4. Tests` ? E2E coverage notes (ActivityFeed renders `totalEnergy`
  work evidence with no `coinsEarned`; the attribution payload is covered
  by `CompletionControllerTest` unit + the targeted-undo E2E flows; a
  dedicated participation-dialog E2E flow remains plan-tracked via the
  minimal-client acceptance criterion).

### Review Findings (final Standard B 2026-09-27, open, block `done/`)

Routed plan-owned per Finding Routing step 2 (all five lie inside this
plan's scope/domain; none reach `backlog.md`). The user selected one
dedicated fix round for all findings (recommended option); the plan stays
in `active/`.

- [x] **block** — **RF-074-WP3-01** — Durable-doc integration missing
  (acceptance criterion 14, manifest "Durable documentation" row, the
  knowledge-integration gate): `docs/adr/ADR-014-family-wallet-and-redemption.md`
  (N path) absent; no changes to `docs/api/README.md`,
  `docs/engineering/{architecture,testing}.md`,
  `docs/concepts/{task-model-and-sync,energy-and-balancing,gamification-and-rewards,ui-use-cases}.md`,
  `docs/testing/release-test-plan.md`, `docs/adr/{ADR-004,ADR-011,ADR-012,ADR-013}*.md`,
  `docs/standards/ai/skills/gamification/SKILL.md`, `docs/adr/README.md`,
  `docs/README.md`. The Size Guideline Exception Inventory is stale both
  ways: `CompletionService.php` listed at 730 (now ~1418 non-blank),
  `GameLogicService.php` at 349 (now 54), `DailyCapacityService.php` at
  517 (now 529); the WP3-grown files need re-measurement. The plan must
  not remain the only description of the wallet/correction/journal
  contract.
  **Resolved (fix round, 2026-09-27):** ADR-014 delivered
  (`docs/adr/ADR-014-work-accounting-ledger-and-replay.md`, ADR README
  indexed); ADR-004/011/012/013 status sections annotated;
  `docs/api/README.md` WP3 completion/correction/history blocks;
  `architecture.md` WP3 aggregate idiom + schema tables + Size Guideline
  Exception Inventory re-measured 2026-09-27 (all rows refreshed, retired
  files removed, `CompletionService` 1478 **named**);
  `testing.md` WP3 unit/integration surface; concepts
  `task-model-and-sync.md` §Completion and undo model (WP3 rewrite),
  `gamification-and-rewards.md` (minor-unit economy, medals/titles/arcade),
  `energy-and-balancing.md` (minor-unit rules), `design.md` WP3 note;
  `gamification` SKILL.md rewritten; `release-test-plan.md` rows land.
- [x] **block** — **RF-074-WP3-02** — Attribution unreachable from the
  client (§3.7 "reuse the form for attribution correction", §3.6
  "Activity history exposes authorized older-completion correction",
  acceptance criteria 5/10): zero `src/` caller of
  `PUT /completions/{id}/attribution` (no `attribut*` reference at all),
  `canCorrect` unconsumed, `ParticipationDialog` mounted only for initial
  completion (RoomDetail/TodayView/TaskManager). Only targeted undo is
  exposed; the backend attribution endpoint has no UI path and the §4
  minimal-UI "owner attribution" browser evidence cannot exist yet.
  **Resolved (fix round, 2026-09-27):** `roomTasks.fetchCompletion` +
  `attributeCompletion` store actions (receipt-protected, reconcile from
  the one payload); `ActivityService` exposes the viewer-scoped
  `canCorrect` on the feed DTO; `ActivityFeed` renders the authorized
  "Correct split" action; `InsightsView` hosts the correction session
  (reused `ParticipationDialog` pre-filled with the recorded split,
  correter need not participate per Q-074-02, a 409 keeps the draft and
  refreshes the expected version); `ParticipationDialog` gained the
  attribution-correction mode (`initialParticipants`/`heading`/`hint`/
  `confirmLabel`). Browser evidence: `insights-flow.spec.ts` *owner
  corrects a recorded work split from the activity history (WP3 §3.6)*
  (full E2E gate green 2026-09-27).
- [x] **major** — **RF-074-WP3-03** — Required §4 N test files missing /
  coverage not mapped: `tests/integration/Service/CompletionCorrectionTest.php`,
  `tests/unit/Service/RoomReplayServiceTest.php`,
  `tests/integration/Service/RoomReplayConcurrencyTest.php`,
  `tests/integration/Service/RhythmProjectionTest.php` and
  `tests/unit/utils/minorUnits.spec.js` do not exist. Attribution is
  pinned only in unit `CompletionServiceTest` (2 tests) + the route pin —
  zero integration coverage; rhythm/weekly recognition has zero
  integration coverage (unit-only); `minorUnits` formatting (negative
  amounts, safe-integer boundaries) has zero direct tests;
  `RoomReplayService` is only mocked in unit tests (indirect integration
  via the completion/concurrency/undo-equality suites). §4-listed M
  paths `tests/integration/Service/HouseMemberRemovalIntegrationTest.php`,
  `tests/e2e/workflow-state-reconciliation.spec.ts` and the fixture rows
  are unmodified. Fix round: deliver the required evidence (either the
  named files or an explicit §4 matrix update mapping each required case
  to its delivered file) — attribution correction and rhythm projection
  need real integration coverage.
  **Resolved (fix round, 2026-09-27):** all five N files delivered
  (`CompletionCorrectionTest` 6 tests, `RoomReplayServiceTest` 11,
  `RoomReplayConcurrencyTest` 5, `RhythmProjectionTest` 5,
  `minorUnits.spec.js` 13) + `HouseMemberRemovalIntegrationTest`
  departed-attribution regression; `workflow-state-reconciliation.spec.ts`
  cases map to the §4 matrix update below; unit pins added
  (`ActivityServiceTest` canCorrect authority, `ParticipationDialog.spec.js`
  correction mode, `ActivityFeed.spec.js` correct action,
  `InsightsView.spec.js` correction flow, `roomTasks.spec.js`
  attribute/fetch actions); `required-scenarios.json` + `run-e2e-gate.js`
  updated (insights-flow `minTests` 3→4). Bonus defect found and fixed by
  the new `minorUnits.spec.js`: `formatCoinMinor` used the per-energy
  scale (1000) instead of 100 coin minor per Coin — corrected with the
  `COIN_MINOR_PER_UNIT = 100` constant.
- [x] **major** — **RF-074-WP3-04** — Attribution skips departed
  participants' wallet reversal (correctness defect): `attributeAggregate`
  maps previous shares to users through CURRENT membership rows
  (`userIdOfWallet` over `findByHouseId`, `CompletionService.php:832`),
  but departure deletes the `house_users` row (`HouseService.php:218`).
  A departed participant's share silently drops out of `$previous`, so
  the union-delta loop (`:891`) never reverses their credit while the new
  100% allocation credits the current members — the completion pays out
  more than the frozen `1000*E` total, violating §3.6 ("later correction
  can adjust that retained wallet without recreating membership;
  historical departed shares remain auditable/removable"). `undoAggregate`
  already does it correctly (deltas keyed by the share rows' `wallet_id`
  directly). Fix: build the union from the share rows by wallet id (lock
  the departed wallet too), resolve display names separately; regression
  test: complete with two participants → one departs → owner attributes →
  departed wallet reversed.
  **Resolved (fix round, 2026-09-27):** the union is built from the share
  rows by wallet id (`previousByWallet`), bindings + ONE sorted lock pass
  cover departed wallets, deltas key wallet ids directly, recognition
  recomputes over still-present previous owners; visibility checks skip
  only previously-credited members (wallet-id match). Regression proofs:
  `CompletionCorrectionTest::testAttribute_DepartedParticipantWalletIsReversed`
  + `HouseMemberRemovalIntegrationTest::testRemoveHouseMember_LaterAttribution_ReversesTheDepartedWallet`
  (frozen-total conservation asserted in both).
- [x] **major** — **RF-074-WP3-05** — `GET /completions/{id}` 500s when
  an audit actor departed: `showCompletion` calls
  `houseUserMapper->find($revision->getActorHouseUserId())`
  (`CompletionService.php:1330`) without the `DoesNotExistException`
  guard the undo/attribute authorization paths already have (`:519`,
  `:737`) — a departed (not erased) corrector leaves a dangling
  `completion_revs.actor_house_user_id` and the read model crashes
  instead of rendering the 'Removed member' fallback. Fix: same
  try/catch → null actor → localized placeholder; regression test.
  **Resolved (fix round, 2026-09-27):** try/catch around the actor lookup
  → null actor → localized placeholder (same guard class as
  undo/attribute). Regression proof:
  `CompletionCorrectionTest::testShowCompletion_DepartedAuditActor_RendersRemovedMemberPlaceholder`
  (200 + placeholder + owner `canCorrect` true).
- [x] **nit** — **RF-074-WP3-06** — `CompletionService.php:838-841`:
  dead empty `if` branch (comment-only body); also the class docblock
  lock order (memberships → wallets → room) contradicts the implemented
  and inline-documented order (room BEFORE memberships/wallets,
  `:328-331`). Fix in the same round.
  **Resolved (fix round, 2026-09-27):** dead branch removed; the
  deviation-review gate it hinted at is implemented for real — an
  unreviewed deviation from the recorded allocation → 409
  (`sameAllocation` over the currently-present participants; same-
  allocation submissions stay valid without review). Class docblock lock
  order corrected to room_task → room → memberships → wallets →
  completion rows.
- [x] **nit** — **RF-074-WP3-07** — `RhythmService.php:104-113`:
  redundant `if`/`elseif` — both branches assign the recomputed longest
  streak; simplify to a single `!==` comparison.
  **Resolved (fix round, 2026-09-27):** collapsed to one `!==` branch
  with the C3g comment.
- [x] **nit** — **RF-074-WP3-08** — `CompletionController::attribute`
  duplicates the closed-payload validation inline instead of extending
  the shared `validateCorrectionPayload` helper (DRY); optional tidy in
  the fix round.
  **Resolved (fix round, 2026-09-27):**
  `validateCorrectionPayload(array $allowedFields, bool $withParticipants = false)`
  now validates the closed set + commandId/expectedVersion/reasonCode +
  participants/allocationReviewed; `attribute` consumes it (identical
  error messages, `CompletionControllerTest` green).

### Review Findings (final Standard B re-review 2026-09-27)

Routed plan-owned per Finding Routing step 2; resolved in the same review
round per the batched user decision (integration pin, recommended).

- [x] **major** — **RF-074-WP3-09** — §4 capacity-seam mapper evidence
  deleted without replacement: the §4-named
  `tests/integration/Db/CompletedTaskMapperEnergyTest.php` was retired
  with the completer-only query, leaving
  `WorkShareMapper::findSelectedSharesBetweenByHouse` (selected-revision,
  `undone_at` and half-open-window SQL filters) without a real-DB pin
  while §7 explicitly requires mapper-level selected-share tests; the
  unit suite mocks the mapper. The §4-listed `RoleGuardApiTest` is
  unmodified (its member-role-guard scope is untouched by WP3).
  **Resolved (final review round, 2026-09-27, user-selected fix round):**
  N `tests/integration/Db/WorkShareMapperEnergyTest.php` (2 tests / 18
  assertions, green) pins the selected-revision/valid-window/undo
  behavior through real completions + `evaluateDays`
  (`completedEnergyMinor`, fulfilled-never-planned, reopened-after-undo);
  the §4 capacity-seam row and the API/T0 row carry explicit mapping
  notes (RoleGuardApiTest unchanged by design; the completion routes'
  authorization is pinned in `CompletionControllerTest`,
  `CompletionCorrectionTest` and the insights-flow E2E).
- [x] **nit** — **RF-074-WP3-10** — Doc drift fixed in-review (safe
  auto-fixes): ADR-014 linked the non-existent
  `docs/plans/done/cs-plan-071-wp0-schema.md` (repaired to
  `cs-plan-071-wp0-write-identity-foundation.md`) and carried no
  WP7-redemption-pending note (added); the delivered ADR name
  (`ADR-014-work-accounting-ledger-and-replay.md`) deviated from the
  parent's reserved placeholder name — the parent reservation line and
  the §5 manifest row now record the delivered name and the WP7-pending
  scope; the Size Guideline Exception Inventory was stale after the
  follow-up fix round (8 rows re-measured: `CompletionService`
  1478→1435, `HouseService` 477→489, `TestDataService` 577→649,
  `RoomTask` 357→362, `CompletedTaskMapper` 525→567, `Dashboard.vue`
  327→351, `InsightsView.vue` 342→531, `roomTasks.js` 1038→1064) and
  missed the two newly crossing WP3 files (`MedalService` 331,
  `RoomService` 328 — both added as accepted cohesive-domain entries).

## Review Outcome — Final Standard B (2026-09-27): NEEDS-FOLLOW-UP

**Final Standard B code review — NEEDS-FOLLOW-UP** (2026-09-27, main
agent; skills: review, plan-management, git-workflow, docker-dev).
Review record:
[CODE_REVIEW_2026-09-27.md](../../backlog/archive/CODE_REVIEW_2026-09-27.md).

- **Scope/base:** the complete uncommitted WP3 implementation increment
  (rounds 1-2) — 178 changed tracked paths (modified + deleted) plus 18
  untracked paths — against `master` @
  `28755b6584d008db150ef76022e867930a7b3811`; index empty at capture.
  Fingerprint: unstaged binary diff SHA1
  `0edea0cf34d8a4f94173c4bde0c007f92cba635d` (`git diff --binary |
  git hash-object --stdin`), staged diff hash `e69de29…` (empty); the
  sorted untracked manifest with per-file SHA256 content hashes is
  recorded in the review record. The three pre-existing `.gitkeep`
  deletions stayed excluded.
- **Verification:** full §5 manifest re-checked (every delivered N source
  path exists; R paths retired with pinned replacements;
  `archive/2026-09/cs-plan-074-retired/` holds 29 retired sources); the
  round-2 resolved findings (RF checklist above) spot-verified in source
  (proposal block via the shared `EffortAllocationService` derivation,
  `lastFreezeDate` removal, entity-default safety nets); shared checklist
  applied across the changed set — thin `CompletionController` with
  closed payloads/`@NoAdminRequired`/membership gates and 404
  anti-enumeration, house-first lock order in all three aggregates,
  CommandService receipts with terminal-before-version handling and
  authorization before replay, exact union-delta attribution, unique
  ledger entry identities, sole-wallet-authority cutover
  (`findEnergyUsageBetweenByHouse` completer-only attribution deleted),
  selected-share capacity seam, room journal/replay with clamps and
  timestamp rules, Q-074-01A valid-history recognition re-derivation,
  parameterized SQL, no `@PublicPage`/`v-html`/raw `console.*`/secret
  logging, i18n via `t()` with 987-key exact EN/DE/POT coverage.
- **Findings:** 5 plan-owned (2 block, 3 major) + 3 nits recorded in the
  checklist above — RF-074-WP3-01 durable-doc integration missing,
  RF-074-WP3-02 attribution unreachable from the client, RF-074-WP3-03
  required §4 test files missing/coverage unmapped, RF-074-WP3-04
  attribution skips departed participants' wallets, RF-074-WP3-05
  `showCompletion` 500 on a departed audit actor. All block the `done/`
  move; no new backlog items (nothing out-of-scope, no `// HACK:`).
- **Decisions:** batched structured question — (1) E2E evidence: accept
  the mtime-verified passing gate run `run-qUhkY2` (2026-09-27 01:50:48,
  all three pools exit 0, zero violations; no reviewed file changed
  after the run) — the recommended option, same approach as the WP2
  final review; (2) one dedicated fix round resolves all findings before
  the final re-review (recommended). Both selected by the user.
- **Gates (rerun by this review on the reviewed source):** Docker unit
  `935 tests / 2882 assertions` and integration `430 tests / 2143
  assertions` `--fail-on-skipped` zero-skip, `composer cs:check`
  0-of-350, Jest `1015` / 95 suites, `test:noise` clean, `lint`,
  `stylelint`, `l10n:check-keys` (987 keys exact), `backlog:check`
  (27 files), `docs:encoding` (791 files), `git diff --check`, `build` —
  all green; E2E gate evidence accepted per decision (1).
- **Transition:** none — the plan stays in `active/`; the implementation
  package stays uncommitted in the working tree per the NEEDS-FOLLOW-UP
  rule; this evidence-only commit contains the plan updates, the review
  record and `ACTIVE_TASKS.md`. CR-SVC-63 stays open (In Progress, this
  plan owning) — its complete remaining contract is not fully proven
  while RF-074-WP3-03's room-replay evidence is open. Next: the fix
  round (RF-074-WP3-01..08), then the final re-review.

## Fix Round Evidence (2026-09-27)

All eight RF-074-WP3 findings resolved in one dedicated fix round (this
working-tree increment, uncommitted per the NEEDS-FOLLOW-UP rule — no
commit authorized by the implementation request):

- **Backend:** `attributeAggregate` wallet-keyed reversal union
  (departed wallets reverse; frozen total conserved) + the real
  deviation-review gate replacing the dead branch; class docblock lock
  order corrected; `showCompletion` departed-actor guard;
  `RhythmService` longest-streak branch collapsed;
  `CompletionController::attribute` on the shared
  `validateCorrectionPayload` helper; `wallet_entries.delta_minor`
  schema default 0 (entity setter-skip INSERT trap — same safety-net
  pattern as the round-2 completed_tasks defaults; Version1030 amended,
  fresh-install chain rebuilt per the pre-release fold policy).
- **Client:** attribution correction reachable end to end — store
  actions, viewer-scoped `canCorrect`, feed action, InsightsView
  correction session reusing `ParticipationDialog` (409 keeps the
  draft); new l10n keys with German copy (995-key exact coverage).
- **Defect found by the new tests:** `formatCoinMinor` formatted at the
  per-energy scale (1000 minor per Coin instead of 100) — corrected via
  the `COIN_MINOR_PER_UNIT = 100` constant (`src/utils/minorUnits.js`).
- **Gates (fix round, 2026-09-27, all green):** Docker unit `947 tests /
  2912 assertions` and integration `448 tests / 2248 assertions`
  `--fail-on-skipped` zero-skip (fresh-install chain after the
  Version1030 amendment + family-profile env recreate); `php-cs-fixer`
  dry-run 0-of-354; Jest `1040` / 96 suites; `test:noise` clean; `lint`
  + `stylelint`; `l10n:check-keys` (995 keys exact EN/DE/POT);
  `check-backlog` (27 files); `check-docs-encoding` (792 files);
  webpack production build; full E2E gate `npm run test:e2e:gate` —
  all three pools green (44 required specs, zero skipped/failed/flaky,
  insights-flow now 4 scenarios incl. the owner-attribution browser
  evidence).
- **Next:** the final re-review (Standard B) over this fix-round
  increment; a PASS owns the `active/ → done/` move.

## Read-Only Review Follow-up Fix Round (2026-09-27)

The uncommitted `/review` of this active-plan implementation reported 23
in-plan findings (6 critical, 17 warning). The user selected **Fix all
findings** after receiving the complete findings-only review. All 23 are
resolved in this uncommitted fix round; no reviewed code was staged or
committed by the read-only review or its implementation follow-up.

- **Privacy and authorization:** hidden-task activity omits allocations and
  frozen effort; correction/replay and score writes reauthorize under the
  house lock; current and departed actor/wallet links anonymize on erasure.
  The feed now batches shares and membership resolution.
- **Receipts, claims, and room replay:** exact claim-source completion IDs
  protect later claims; correction receipts retain bounded identities and
  retry authorization; manual and reset commands use house-scoped checked
  receipts; Settings sends its UUID/version. Room replay batches validity,
  starts after the latest absolute reset and retains NULL-source events.
- **Recognition:** actual selected shares, neutral pauses, chronological
  pending days and deadline refresh govern personal rhythm; planned-week
  due occurrences govern goals. Closed weeks are scanned in bounded pages
  by the production timeline job; a unique `(house_id, week_start)` receipt
  allows one household bell claim per week, including after job downtime or
  retrospective correction. Corrected medals are reconciled, and members
  joining after the saved week end cannot inherit an earlier milestone.
- **Client:** a 409 preserves the correction draft and requires an explicit
  review of the fresh allocation; uncertain replies retry the original
  attribution UUID; history offers targeted undo; the own-wallet balance
  displays exact negative/fractional Coins, with all four new medal types
  and other changed labels in EN/DE/POT.
- **Additional gate fixes:** the fresh-install receipt table name was
  shortened to satisfy Nextcloud's 30-character prefixed limit; the query
  builder uses grouped planned-week keys; the opt-in test-only family seed
  supplies legitimate closed-week evidence for the required bell browser
  scenario. The existing claim-before-dispatch path remains an at-most-once
  attempt, not guaranteed delivery after a Nextcloud manager failure;
  failures are logged and the broader outbox question is folded into
  FEAT-UX-024 rather than silently weakening this plan's receipt contract.
- **Verification (fresh `family` Docker install):** backend unit `981 tests /
  3077 assertions` and integration `465 tests / 2363 assertions`, both
  `--fail-on-skipped`; `composer cs:check` 0-of-356; Jest `1054` / 96 suites;
  `test:noise`, lint, stylelint, `l10n:check-keys` (1003 exact keys),
  backlog/encoding/diff checks and webpack production build all green.
  `npm run test:e2e:gate` passed all three projects with 44 required specs
  and zero skipped, failed or flaky required scenarios; Insights includes
  the three newly pinned correction/wallet cases, and the closed-week bell
  is exercised through the same timeline pass as cron. Raw local browser
  traces remain under ignored `build/e2e-gate/` and are not published.
- **Lifecycle:** no commit or transition. The plan stays in `active/` with
  CR-SVC-63 owned here; the separate final Standard B re-review must verify
  the whole plan before any `active/ → done/` move.

## Review Outcome — Final Standard B re-review (2026-09-27): PASS-WITH-FIXES

**Final Standard B re-review — PASS-WITH-FIXES** (2026-09-27, main agent;
skills: review, plan-management, git-workflow, docker-dev). Review record:
[CODE_REVIEW_2026-09-27.md](../../backlog/archive/CODE_REVIEW_2026-09-27.md#final-standard-b-re-review--pass-with-fixes).

- **Scope/base:** the complete WP3 implementation increment (implementation
  rounds 1-2 + the RF-074-WP3-01..08 fix round + the 23-finding read-only
  follow-up fix round) — 217 changed tracked paths (186 M / 31 D) plus 26
  untracked paths — against `master` @
  `631f0221a288a7d728e27f72baa6a839adf37441`; index empty at capture.
  Fingerprint: unstaged binary diff SHA1
  `4ed47d4270f1d664479d666c6ce9c5ea1333fef4` (`git diff --binary |
  git hash-object --stdin`); the sorted untracked manifest with per-file
  SHA256 content hashes is recorded in the review record. The three
  pre-existing `.gitkeep` deletions stayed excluded.
- **Verification:** the whole owning plan re-verified — §5 manifest (every
  delivered N source path exists; R paths retired with pinned supported-Snake/
  no-Coin replacements; 29 retired sources preserved under the gitignored
  `archive/2026-09/cs-plan-074-retired/`); §4 test matrix (all required N
  files present; explicit mapping notes for the two deviations); all sixteen
  acceptance criteria verified against source and gate evidence; every
  RF-074-WP3-01..08 resolution re-verified in source (wallet-keyed
  attribution union `previousByWallet`, `showCompletion` departed-actor
  guard, client attribution path store → feed → InsightsView →
  ParticipationDialog, shared `validateCorrectionPayload`, rhythm branch,
  docblock lock order, `COIN_MINOR_PER_UNIT = 100`, `delta_minor` schema
  default); the follow-up round's key changes spot-verified (viewer-scoped
  `canCorrect`/redaction with batched share reads in `ActivityService`,
  house-first re-authorization in `MinigameService`, unique
  `(house_id, week_start)` weekly-goal receipt, erasure anonymization of the
  new share/revision/wallet actor links, manual delta routed through
  `room.manual_delta` commands); durable-doc integration verified (ADR-014
  delivered and indexed; ADR-004/011/012/013 annotated; API/architecture/
  testing/concepts/gamification-skill/release-test-plan updated).
- **Findings:** two — RF-074-WP3-09 (major: §4 capacity-seam mapper
  evidence deleted without replacement; resolved with N
  `tests/integration/Db/WorkShareMapperEnergyTest.php`, 2 tests / 18
  assertions, plus §4 mapping notes) and RF-074-WP3-10 (nit: ADR-014
  broken link + missing WP7-pending note, ADR name/manifest alignment, 8
  stale + 2 missing size-inventory rows; all auto-fixed). No finding
  remains open; nothing reaches `backlog.md`.
- **Decisions (batched structured question):** (1) E2E evidence — user
  selected the recommended option: accept the mtime-verified passing gate
  run `run-UK5bLd` (2026-09-27 15:16:20 local; all three pools
  `processExit: 0`, zero violations, 95+124+39 tests; only review/tracking
  docs changed after the run). (2) RF-074-WP3-09 — user selected the
  recommended option: deliver the integration pin in this review round.
- **Gates (rerun by this review on the reviewed source):** Docker unit
  `981 tests / 3077 assertions` and integration `467 tests / 2402
  assertions` `--fail-on-skipped` zero-skip (the +2 integration tests are
  the new seam pin), `composer cs:check` 0-of-357, Jest `1054` / 96
  suites, `test:noise` clean, `lint`, `stylelint`, `l10n:check-keys` (1003
  keys exact EN/DE/POT), `backlog:check` (27 files), `docs:encoding` (792
  files), `git diff --check`, webpack `build` — all green; E2E gate
  evidence accepted per decision (1).
- **Transition:** plan moved `active/ → done/`; CR-SVC-63 closed to
  `docs/backlog/archive/2026-09.md` (its complete journal/replay remainder
  passes: manual adjust routed through journaled `room.manual_delta`
  commands with ordered replay evidence and real race coverage);
  CONCEPT-001 and the partially fulfilled parent findings stay open (WP3
  portions annotated in the parent); parent/header/child/WP-table rows,
  READMEs, `ACTIVE_TASKS.md` and plan links updated; the complete
  implementation package (rounds 1-2 + both fix rounds) lands with this
  scoped final-review commit.
