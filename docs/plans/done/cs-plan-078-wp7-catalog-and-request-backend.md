# CS-PLAN-078: WP7 - Catalog and Request Backend

> **Status:** Active (implementation not started); Standard A `PASS` 2026-10-01
> **Effort:** L; 6-9 engineering days, including tests, documentation and one correction pass
> **Priority:** P1
> **Depends on:** Parent G1 / WP0-WP6, all delivered via CS-PLAN-071 through CS-PLAN-077; confirm the recorded core-gate evidence before implementation
> **Related backlog items:** FEAT-UX-026 (backend slice), CONCEPT-001 via [CS-PLAN-070](../done/cs-plan-070-family-rhythm-and-reward-clarification.md)
> **Planning baseline:** `4b5b089ea180ab6514eb94725eff78032f172138`, 2026-10-01

This child implements parent WP7, D2/D7/D8/D11, C3h and C4a-h, with C5e/h/m
delivery/privacy integration. The parent's approved decisions remain authoritative.
This drafting round changes documentation only: no runtime, schema, localization,
test implementation, activation or commit. Engineering details below are proposals
for Standard A, not new product approvals or passing test evidence.

## 1. Problem Summary

The reliable core is delivered, but chore-earned Coins still have no optional
family redemption path. Giving them real-world meaning requires complete private
request handling and exact accounting, not just catalog CRUD or a debit button.

| Area | Verified baseline | WP7 gap |
|---|---|---|
| Catalog | `House.catalogEnabled` and `houses.catalog_enabled=false` exist; the flag is absent from `House::jsonSerialize()` and the house update path. | Expose the gate and add an owner-only, versioned writer; persist catalog items. |
| Wallet | `WalletService::postEntries()` owns booked value; `reserved_minor` exists with no reservation writer. Own-wallet reads already expose B/R/A and `fundingHold`. | Maintain reservations; add settlement/refund ledger sources without another balance authority. |
| Requests | No reward entities, mappers, services, controllers, routes or tests exist. `RewardConfigService` is not a catalog service. | Closed C4 state/actor matrix, immutable terms, audit events and caller-safe DTOs. |
| Roles | `HouseService::updateUserRole()` is the role writer; `HouseUser` and Version1004 have no `role_revision`. | Add revision provenance and invalidate/rebase approvals atomically on real role changes. |
| Departure | Removal retains wallets; invitation reattaches the same-account wallet. Removal has no request handling yet. | Cancel all unfulfilled promises and release holds before access ends; preserve booked value/history. |
| Erasure | `AccountErasureService` covers active memberships and an orphan-wallet sweep. | Extend both paths to wishes, request events, actor references and third-party digest references. |
| Digest | WP5 queues organizational references transactionally; `DigestService::orgSourceIsRelevant()` accepts only takeover/agreement/pause. | Add reward-request relevance and current requester/owner rights without a new external notification subject. |
| Optional UI | WP5/WP6 persist `catalogVisible`; WP6 presents its placeholder toggle. | Reuse this preference; the catalog/request surface and its consumer remain WP8. |

Current delivered accounting is [ADR-014](../../adr/ADR-014-work-accounting-ledger-and-replay.md),
not the historical integer/multiplier economy. The selected product contract is
[C4](../../concepts/gamification-and-rewards.md#approved-catalog-lifecycle-c4-2026-09-17);
notification integration follows [ADR-016](../../adr/ADR-016-opt-in-digest-notifications.md)
and the [pipeline](../../engineering/notifications.md).

## 2. Strategy

Implement one backend slice on the existing house-first transaction, command
receipt, persistent wallet identity and organizational queue seams. Reserve at
request creation, settle only at separately confirmed delivery, and refund only
an owner-attested false fulfillment. Reads never mutate or reconstruct balances.

| Owner | Deliverable / boundary |
|---|---|
| WP7 / this plan | Fresh schema, catalog gate/CRUD, C4 lifecycle, exact reservations/debits/refunds, role/departure/erasure integration, private reads, digest source integration, T6 plus package-local HTTP/browser contract evidence and durable docs. |
| WP0-WP6 / delivered | Locks, receipts, wallet binding/ledger, completion correction and retained recognition, self-only preferences, quiet digest and calm core UI. Extend their named seams; do not reopen approved choices. |
| WP8 / later | Catalog navigation/editor, requester/owner UI, decimal price input, hidden-catalog request access, shared-device reward-store reset, onboarding/pricing/help and T11 desktop/mobile/keyboard presentation. |
| WP9 / later | Complete DB-matrix evidence, two consecutive combined browser gates and voluntary family evidence. No contact, telemetry or human testing is authorized by this draft. |

G1 evidence is the delivered WP0-WP6 reviews, most recently the
[WP6 final review](../../backlog/archive/CODE_REVIEW_2026-10-01.md#gates-all-rerun-by-this-review-on-the-reviewed-tree)
and its full core/browser/build/backend gates. That evidence is not a new run in
this round; activation checks its applicability to the then-current tree. WP7
does not declare G2 complete without WP8, close FEAT-UX-026, or finish CONCEPT-001.
Catalog defaults off; existing planning, chore recording and recognition must
work with it permanently disabled. No default rewards, stock/expiry system,
discounts, essential-care rewards, task exemptions, arcade currency or external
payment/identity service are introduced.

## 3. Backend / Frontend Changes

### 3.1 Persistence and Boundaries

Add the parent's reserved `Version1033Date20260918000000.php` for the three new
feature tables. Amend Version1004's original membership definition for
`role_revision` (bigint, default 0) and `HouseUser`'s typed property. No upgrade
transforms, backfills, legacy columns or compatibility aliases; verify a fresh
install of the complete chain. Version1000's catalog flag already exists.

Table names omit the `chorestrike_` prefix. IDs are bigint, dates are server UTC
instants, version counters start at 0, and every query is house-scoped through
Nextcloud query builders. Do not rely on foreign-key cascades or partial indexes.

| Table | Required fields / invariants | Portable indexes / lifecycle |
|---|---|---|
| `reward_items` | House, plain-text title/description, positive `price_minor`, archived flag, version, created/updated times and nullable actor membership/wallet references. Proposal: title 1-120 characters, description 0-2000; reject control characters. | `(house_id, archived, id)`; archive instead of deleting promised terms. Owners can prepare/administer items while the catalog is off. |
| `reward_requests` | House, requester wallet, item ID, frozen title/description/price, state, version, approved requester-role revision and approval provenance, nullable settlement/refund entry references, timestamps. | `(house_id, wallet_id, state, id)` and `(house_id, state, id)`; pending/approved reserve exactly their immutable positive price. No duplicate raw UID/display-name snapshot or funding-hold state. |
| `request_events` | Request ID, revision, action, old/new state, nullable actor membership/wallet, old/new requester-role revision where relevant, bounded reason code, financial entry reference, time and optional source command UUID. | Unique `(request_id, revision)`; append-only until authorized privacy erasure. Entity/mapper names are `RewardRequestEvent` / `RewardRequestEventMapper`, not `RequestEvent`. |

Use reason codes (maximum 64 characters, closed allowed set), not arbitrary
personal free text. False-fulfillment correction requires a nonempty reason
code and explicit `notDelivered=true`; normal fulfillment requires explicit
`delivered=true`. These record an authorized person's attestation, not a claim
that software can verify physical delivery. System state/action codes are stable;
their user-visible explanations use `t()`. Family-authored catalog text is content,
not a translation key, and must be escaped when presented by WP8.

Wallet B and R remain the only balance caches; R must equal the sum of prices
of that wallet's pending/approved requests. Maintain caches in the same write
transaction; use sums for test/audit reconciliation, not read-time repair.
Preserve immutable request terms when the live item changes or is archived.
Actor-wallet provenance allows erasure even after the actor's membership ended;
history placeholders must not recreate access or expose deleted identifiers.

### 3.2 Money and Closed State Machine

All monetary API values are integer hundredths: `priceMinor=1250` means 12.50
Coins. Reject floats, numeric strings, zero/negative prices, unknown fields and
unsafe integers; decimal parsing/display belongs to WP8. Validate B, R, A=B-R,
every delta/sum and transported version/ID against their signed safe-integer and
DB/PHP limits, including subtraction overflow after legitimate negative credit.

Let P be the frozen request price. Funding hold is `B < R`, orthogonal to state.
Coverage is per wallet across ALL active reservations, not across other members'
wallets and not a priority rule for the cheapest/oldest request.

| Action / source | Target / actor | Atomic financial effect |
|---|---|---|
| Create / active item and catalog | Child self -> `pending`; adult/owner self -> `approved` | Require A>=P; R+=P, B unchanged. Adult creation records current-role approval, not delivery. |
| Approve / `pending` | Owner for current child; current adult requester for self -> `approved` | No B/R change; record approval/role provenance. An owner does not approve a different adult's pending request. |
| Reject / current child's `pending` | Current owner -> `rejected` | R-=P only. |
| Cancel / `pending` or `approved` | Requester, including child, or current owner -> `cancelled` | R-=P only, including while underfunded; owner cancellation is traceable. |
| Fulfill / `approved` | Current owner for child; adult requester or current owner for adult -> `fulfilled` | Require current valid approval and B>=R; B-=P and R-=P together. |
| Correct / `fulfilled` | Current owner attests non-delivery -> `corrected` | Refund the original settlement debit exactly once; R unchanged, no new reservation. |
| Requester becomes child / unfulfilled | Role aggregate -> `pending` | Keep P/R; invalidate all prior approvals. |
| Requester departs / unfulfilled | Removal aggregate -> `cancelled` | Release every corresponding reservation before membership deletion. |

Every unlisted transition conflicts without writes. Time, funding hold, archive
and catalog disablement never expire/reopen requests. Approval and cancellation
remain possible under funding hold; ALL unfulfilled settlements and new requests
are blocked until coverage is restored. Example in minor units: B=4000, R=6000,
A=-2000; earning 2000 restores coverage for existing requests, not funds for a
new positive-price request.

Extend `WalletService` with under-lock reserve/release operations and ledger
sources `reward_settlement` / `reward_refund`, keyed by request ID and effect
revision. Reservation/rejection/cancellation creates no booked-value entry.
Settlement/refund entry IDs are stored on the request/audit; correction verifies
the supplied settlement reference against the server's original entry, wallet
and amount, never the current item price or a client refund value. Reward actions
do not alter work shares, contribution, rooms, streaks, medals or title unlocks.
Completion undo/attribution remains additive and can make B/A negative after
spending; never reclaim a genuinely delivered reward.

### 3.3 API and Viewer-Safe DTOs

Paths are relative to `/apps/chorestrike/api/v1`; use `{success,data,error}`,
`ControllerErrorFormatter`, authenticated `@NoAdminRequired` endpoints and normal
CSRF protection. Use pure `assertHouseMember()`, never a bootstrap auto-join.
Controllers validate/delegate; services enforce current membership/authority.

| Route / owner | Command / read contract |
|---|---|
| `GET /house` (existing) | Add `catalogEnabled`; retain `stateVersion` and existing response fields. |
| `PUT /house/catalog` (HouseController/HouseService) | Owner-only `{catalogEnabled, expectedVersion, commandId}`; version is house `stateVersion`. Serialize with request creation; do not reset personal visibility preferences or existing requests. |
| `GET /rewards` (RewardController) | `scope=active` default: active items only when enabled, otherwise explicit disabled state plus empty items. `scope=admin`: owner-only, includes archived items even while off. |
| `POST /rewards`; `PUT /rewards/{id}` | Owner create/edit/archive; whitelisted title/description/priceMinor/archived, commandId and expectedVersion on update. Non-owner members may not manage items. |
| `GET /reward-requests`; `GET /reward-requests/{id}` (RewardRequestController) | Default `scope=mine`; explicit `scope=house` owner administration. Keep reads available when either household catalog or personal catalog navigation is hidden. Detail includes authorized audit history. |
| `POST /reward-requests` | Self-only `{itemId, itemVersion, priceMinor, commandId}`; validate the displayed terms against the live unarchived item under lock and freeze them. Drift is 409, never a different silently reserved price. |
| `POST /reward-requests/{id}/approve`, `/reject`, `/cancel`, `/fulfill`, `/correct` | commandId and expectedVersion; cancel/reject use bounded reason codes where supplied; fulfill requires delivered confirmation, correct requires non-delivery confirmation, reasonCode and settlementEntryId. |

`PUT /house/catalog` is a deliberate narrow refinement of the parent's shared
house-settings proposal: existing `PUT /house` has no command/version contract.
Keep name/timezone/ranking callers unchanged rather than refactoring unrelated
settings or adding a second unversioned catalog writer. This route still belongs
to the existing house controller/service; no new configuration service is needed.

Bound lists (proposal: default 50, maximum 100, stable ID cursor) and allow only
documented scope/state filters. Validate IDs, UUIDs, booleans, enums, text bounds
and versions before side effects. Errors: 400 malformed input; 404 nonexistent,
foreign or invisible IDs; 403 visible-resource role denial; 409 stale version,
closed transition, displayed-price conflict, insufficient funds or funding hold.
Visibility/authority precedes reporting a business conflict.

Catalog DTO: item ID, title/description, priceMinor, archived and version.
Request DTO: request/item ID, authorized requester reference/placeholder, frozen
terms, state/version, times, audit and current-role `allowedActions` plus
`fundingHold` for this request's administrability. Action hints never authorize.
Own requests/mutations may include the caller's own B/R/A wallet. Owners acting
for another person receive NO target wallet totals, raw wallet binding, unrelated
members' wishes or ledger dump; they receive only the request's processing state.
General member lists, ranking and household activity gain no reward details.
WP8 consumes these DTOs/versions; no catalog frontend or second wallet store ships
in WP7. All new system/error text updates EN/DE/POT artifacts in the same unit.

### 3.4 Transactions, Receipts and Membership

`RewardService` owns catalog reads/writes; `RewardRequestService` owns lifecycle
and its under-lock membership hooks. Both depend on lower-level mappers/services,
not back on `HouseService`; avoid a HouseService/request-service DI cycle.
WalletService remains a lower-level collaborator, not an independent coordinator.

Use `CommandService::runCommand()` for every new public mutation. Add one small
optional under-lock authorization callback to that seam, invoked after the house
lock and BEFORE receipt lookup on both execution and replay; all WP7 commands
must supply it. It checks fresh actor identity, visibility and current action
authority, not business version/funding/state preconditions already satisfied by
a committed command. This avoids authorization living only in the callback that
is skipped on replay; no generic policy engine or new receipt namespace.

Receipts store original IDs/effect revisions only. Same actor/key/action/payload
replays those identities with freshly authorized current DTOs; a changed payload
or action is 409. A replay creates no audit, reservation, debit, refund or queued
notice, including after catalog retirement. A new key on a terminal request
cannot repeat its effect. Recognized transient DB failures retain the same key;
authorization/business failures are not retryable successes.

Follow `TransactionExecutor`'s order: house FIRST, then active memberships by ID,
wallets by ID, then reward/request and preference/digest rows by ID. Document the
reward-item rows in the final child-row lock group; no writer may lock an item
before its house. HouseMapper supplies the lock; no HouseWriteLockService exists
or is introduced. Request, event, wallet/cache/ledger, house version, receipt and
organizational references commit/roll back together; no network calls in a write.

- Role changes: reread caller/target and owner guards under the existing house
  lock. Advance `roleRevision` only when role changes. Becoming child moves every
  unfulfilled request to pending and clears approval; a no-op child write must
  not invalidate a fresh owner approval. Other role transitions preserve valid
  approvals with updated provenance; already pending requests remain pending
  until explicit self/owner approval under C4. Append request audit/version changes.
- Departure: reread target/caller and existing owner-removal guards under lock;
  cancel all pending/approved requests, release R, then remove access/preferences/
  digest state in the SAME source aggregate. Expose no new self-leave workflow.
  Keep positive/negative B and terminal history; owners may correct a retained
  false fulfillment against the departed wallet without granting it membership.
- Rejoin: use existing invitation + `ensureBinding()`. Same account/house recovers
  its booked balance and authorized own terminal history, never active requests
  or old notification/ranking opt-ins. No cross-house transfer or debt reset.
- Erasure: extend BOTH active-membership and orphan-wallet paths. Release/cancel
  holds, purge that requester's requests/events/wishes and ledger/wallet, remove
  queued references to those requests from ALL recipients, and anonymize the
  erased actor's references on other retained catalog/request/event records.
  Use actor-wallet provenance for already-departed actors; sanitize receipt
  references to purged wishes and retain no personal reason text. Repeated erasure
  is harmless; a recreated UID has fresh IDs and recovers no former data.

Production house reset retains all request/financial state just as it retains
the ledger; structural chore deletion does not delete catalog promises. Test-only
reset and integration cleanup add request-events -> requests -> items before
wallet/membership/house cleanup. Tests own isolated fixtures; do not reset an
unrelated environment or invent a production house-deletion route.

### 3.5 Organizational Digest Integration

Queue `object_type=reward_request`, request ID/revision and event time inside
the source transaction via `DigestChangeMapper::queueOrCoalesce()`. Recipients
are the active requester and current owners minus the actor, only when their
own organizational opt-in is already enabled. New child requests reach owners;
owner decisions reach the requester; requester cancellations reach owners.
State/role changes can coalesce to the latest relevant revision. No historical
backfill on opt-in, new owner role, rejoin or catalog re-enable; no repeated
unchanged-pending or funding-threat notifications.

Extend `DigestService`'s closed resolver: the request must still exist in the
same house at the queued revision and the recipient must NOW be its active
requester or a current owner. Demoted owners and departed/erased requesters lose
eligibility. Archive/disable/personal navigation hiding does not remove authorized
processing updates. Remove superseded references without resurrecting missed
slots. Preserve WP5's cutoff/coalescing/expiry, opt-out, quiet-hours, daily budget
and uncertain-send rules. Count-based `digest` remains the only external subject;
never include wish title/description, reasons, price or wallet totals in it.

## 4. Tests

Required evidence below is to be implemented, not already passing. `C4.1-14`
refer to the parent's ordered acceptance cases; T6 is wholly owned here.

| Cases / gate | Concrete files and required proof |
|---|---|
| T6 money; C3.10-11 | M `tests/unit/Service/WalletServiceTest.php`; N `tests/unit/Service/RewardRequestServiceTest.php`: exact reservation/release/settlement/refund, B/R/A overflow and hold arithmetic; actual completion undo/attribution after fulfillment preserves spend and valid later earnings. |
| C4.1-5 / catalog and lifecycle | N `tests/unit/Service/RewardServiceTest.php`, `tests/integration/Service/RewardLifecycleTest.php`: all actors/transitions, immutable terms, malformed/unknown payloads, stale item/request/house versions, no expiry or reopen, hidden/archived catalog processing and explicit delivery. |
| C4.6-9 / membership | M `tests/unit/Service/HouseServiceTest.php`, `tests/integration/Service/HouseMemberRemovalIntegrationTest.php`, `tests/integration/Controller/UserControllerIntegrationTest.php`: balances/history on leave/rejoin, child demotion/reapproval, adult promotion, unchanged-role no-op, demoted owner's immediate authority loss and preserved owner guards. |
| C4.10 / privacy and HTTP | N `tests/unit/Controller/RewardControllerTest.php`, `tests/unit/Controller/RewardRequestControllerTest.php`, `tests/integration/Controller/RewardApiTest.php`; M `tests/unit/RouteMatrixTest.php`: self/owner/foreign-house matrix, 404 vs 403, CSRF/auth annotations, DTO whitelists, no foreign balances/activity/ranking leak and localized safe errors. |
| C4.11-14 / atomicity and concurrency | N `tests/integration/Service/RewardConcurrencyTest.php`: real two-connection request/request, price/archive/disable/request, fulfill/cancel, fulfill/departure, fulfill/requester-role or acting-owner-demotion, fulfillment/completion-correction and refund/refund interleavings. Fresh connections reconcile ledger sums, R sums, events, versions and receipts. |
| Replay / rollback | M `tests/integration/Service/CommandServiceTest.php`; N lifecycle/concurrency suites above: authorization guard runs on replay, changed action/payload conflicts, lost-response replay returns original identity/current totals, fresh-key terminal conflict. Inject failures after reserve/debit/refund, request/event and digest write: NOTHING partially commits, including receipt. |
| Q-070-02 / new privacy surfaces | M `tests/unit/Service/AccountErasureServiceTest.php`, `tests/integration/Service/AccountErasureTest.php`: active/departed requester and departed actor, pending/approved/fulfilled/corrected history, other recipients' queue rows, repeated erasure and same-UID recreation; remaining households' unrelated data survives. |
| T0 fresh schema / reset | M `tests/integration/Db/FamilyRhythmSchemaTest.php`, `tests/integration/IntegrationTestCase.php`: three tables/indexes/defaults, role revision and nullable actor fields, ledger source uniqueness, dependency-ordered cleanup and fresh chain. Default `family` catalog remains off; reward fixtures enable it explicitly. |
| C5e/h/m / digest | M `tests/unit/Service/DigestServiceTest.php`, `tests/integration/Service/DigestDeliveryTest.php`: transactional source queue, coalescing/cutoff/expiry, no default notification, current-role filtering, hide/disable processing updates, opt-out/erasure and one shared summary with task updates. |
| House gate / retained recognition | M `tests/unit/Db/Entity/HouseTest.php`, `tests/unit/Db/Entity/HouseUserTest.php`, `tests/unit/Controller/HouseControllerTest.php`, `tests/integration/Controller/HouseControllerIntegrationTest.php`; lifecycle suite: owner-only versioned flag, safe serialization, title/medal/streak/contribution unchanged by real spending and false-fulfillment refund. |
| Package-local browser contract | N `tests/e2e/family-reward-api.spec.ts`: authenticated owner/member/child HTTP lifecycle with real CSRF tokens, funding hold after actual chore correction, private denial, replay, disabled catalog with retained request access and catalog-off core use. M `tests/e2e/title-selection.spec.ts`: legitimately earned title remains displayed after real redemption and reload. |

Add exact browser titles to `tests/e2e/required-scenarios.json`, runner discovery
in `scripts/run-e2e-gate.js`, and the existing serial DB pool in
`playwright.config.ts`. These are API-backed WP7 proofs, not invented reward UI
coverage. T11's `family-rewards.spec.ts` and mobile/keyboard catalog journeys stay
WP8; do not add skipped placeholders for them. Use existing shared auth/test-house
fixtures for the necessary setup now; do not defer the first contract test to WP9.

Race tests use deterministic process/connection barriers, never sleeps or a
sequential double call. The current MariaDB harness is the package-local engine;
do not add skips to make a gate green or claim it proves SQLite/PostgreSQL.
Portable mapper/migration design is reviewed here; WP9 owns the isolated matrix
environment and full engine evidence. Inject server clocks for event/slot cases.

Implementation gates (canonical Docker environment, see
[docker-dev](../../standards/ai/skills/docker-dev/SKILL.md)):

```powershell
npm test -- --runInBand
npm run lint
npm run build
npm run l10n:check-keys
npm run backlog:check
npm run docs:encoding
git diff --check
docker compose -f docker/compose.test.yml exec -T nextcloud-test vendor/bin/phpunit --configuration phpunit.unit.xml --fail-on-skipped
docker compose -f docker/compose.test.yml exec -T nextcloud-test vendor/bin/phpunit --configuration phpunit.integration.xml --fail-on-skipped
docker compose -f docker/compose.test.yml exec -T nextcloud-test composer cs:check
npm run test:e2e:gate
```

Use the `family` profile for the existing browser environment. Rebuild disposable
owned schema when Version1004 changes; an old test DB is not a reason for a
backfill. Documentation-only drafting runs document/link/encoding/whitespace
checks, not these future runtime acceptance gates.

## 5. File Manifest

`N` = proposed new file after activation; `M` = existing file to modify.
Brace lists enumerate exact files. Section 4's test files are part of this
manifest. No runtime retirement is required. Preserve unrelated `.gitkeep`
deletions; do not stage or restore them.

| Kind | Paths / purpose |
|---|---|
| N schema | `lib/Migration/Version1033Date20260918000000.php` |
| M schema | `lib/Migration/Version1004Date20260228131728.php` (role revision; not another wallet schema) |
| N persistence | `lib/Db/{RewardItem,RewardItemMapper,RewardRequest,RewardRequestMapper,RewardRequestEvent,RewardRequestEventMapper}.php` |
| N domain / HTTP | `lib/Service/{RewardService,RewardRequestService}.php`; `lib/Controller/{RewardController,RewardRequestController}.php` |
| M existing seams | `lib/Db/{House,HouseUser,WalletMapper,TransactionExecutor}.php`; `lib/Service/{WalletService,CommandService,HouseService,AccountErasureService,DigestService,TestDataService}.php`; `lib/Controller/HouseController.php`; `appinfo/routes.php` |
| M cleanup / browser gate | `tests/integration/IntegrationTestCase.php`; `tests/e2e/required-scenarios.json`; `scripts/run-e2e-gate.js`; `playwright.config.ts`; section 4 tests. Reuse existing auth/test-house fixtures rather than introducing another login/seed runner. |
| M l10n | `l10n/{en,de}.json`; `l10n/templates/chorestrike.pot` |
| N durable docs | `docs/api/rewards.md`; `docs/adr/ADR-017-family-catalog-and-redemption.md` (number currently free; recheck before creating) |
| M durable docs / indexes | `docs/api/README.md`; `docs/adr/{README,ADR-014-work-accounting-ledger-and-replay}.md`; `docs/concepts/{gamification-and-rewards,ui-use-cases}.md`; `docs/engineering/{architecture,notifications,testing}.md`; `docs/standards/ai/skills/gamification/SKILL.md`; `docs/README.md` |
| M tracking | This child, parent CS-PLAN-070, `docs/plans/README.md`, `docs/backlog/{backlog,ACTIVE_TASKS}.md`; monthly archive/review record only at actual reviewed completion. |

Parent manifest refinements: WalletService/WalletServiceTest are M, not N;
catalogEnabled/catalogVisible already exist; roleRevision does not; request-event
class names differ from the table name; the digest resolver and orphan-wallet
erasure sweep need explicit extensions. Existing UserController routes remain
unchanged; service-side membership hooks implement their new financial effects.
No `src/` changes are planned because the catalog-gate refinement leaves current
settings transport intact and the new reward UI belongs to WP8.

Durable integration must describe shipped B/R/A/hold arithmetic, the actor/state
matrix, immutable terms, receipt/lock boundaries, requester/current-owner privacy,
ordinary departure versus erasure and reward queue relevance. ADR-017 extends
ADR-014's ledger; its existing work-accounting decision is not rewritten. Plan
history is not the sole lasting description, and no future UI is labeled shipped.

## 6. Implementation Order

| Step | Work / exit | Effort |
|---|---|---|
| 1 | Standard A: verify G1 evidence and baseline, review schema/DTO/lock/authorization-hook proposals, resolve any newly exposed questions together; only a passing review activates this child. | 0.5 day |
| 2 | Fresh chain, entities/mappers, role revision, reset/cleanup and schema tests; default catalog remains off. | 1 day |
| 3 | Command authorization hook, owner catalog flag/CRUD, snapshot creation and exact reserve/release/hold arithmetic; unit/HTTP/lifecycle tests. | 1.5-2 days |
| 4 | Approve/reject/cancel/fulfill/correct with audit + immutable ledger; real correction/recognition regressions, receipts and rollback. | 1-1.5 days |
| 5 | Atomic role/removal/rejoin and both erasure paths; transactional reward digest producer/resolver; lifecycle/privacy/digest evidence. | 1-1.5 days |
| 6 | Real interleaving matrix, required browser contract tests/gate discovery, complete implementation gates, durable docs and self-review. | 0.5-1 day |
| 7 | Final Standard B / Full, resolve owned findings, move this child to done only if every WP7 obligation passes; update parent/backlog truth and hand off frozen DTOs to WP8. | 0.5-1.5 days |

One engineering day is approximately six focused hours, not agent runtime or a
calendar promise. If erasure/locking evidence reveals extra scope, revise the
estimate explicitly rather than dropping required tests. Parent G2/G3 remain
open; WP8 activation and implementation are separate work.

### Implementation Status (2026-10-01)

Steps 1-6 of §6 are complete; step 7 (final Standard B / Full review and the
`done/` transition) is the next lifecycle gate and has NOT run yet — no
acceptance checkbox above is checked by implementation alone.

- **Step 2 (schema):** `Version1033Date20260918000000` adds the three tables
  (portable indexes, frozen defaults, nullable actor provenance, unique
  `(request_id, revision)` audit identity); `Version1004`'s original
  membership definition carries `role_revision` (bigint, default 0); reset
  order and integration cleanup extended; schema test in
  `FamilyRhythmSchemaTest`. Fresh chain verified on a rebuilt Docker DB.
- **Step 3 (commands/money):** `CommandService::runCommand()` gained the
  optional under-lock authorization guard (execution AND replay, before the
  receipt lookup); `WalletService` gained `reserveReward`/`releaseReward`/
  `settleReward`/`refundReward` and `postEntries` returns entry ids;
  `RewardService` (catalog CRUD + gate command) and `RewardRequestService`
  (lifecycle) landed with the routes
  (`PUT /house/catalog`, `GET|POST /rewards`, `PUT /rewards/{id}`,
  `GET|POST /reward-requests`, `GET /reward-requests/{id}`,
  `POST /reward-requests/{id}/approve|reject|cancel|fulfill|correct` —
  94-route pin updated).
- **Step 4 (audit/ledger):** one immutable event per revision, ledger keys
  `reward_settlement`/`reward_refund` (request id + effect revision),
  stored settlement/refund references with server-side verification, exact
  rollback proven by injected mid-aggregate failure.
- **Step 5 (membership/erasure/digest):** role rebase via
  `HouseService::updateUserRole` (no-op writes never advance
  `role_revision`), departure cancels + releases before access ends, both
  erasure paths purge wishes/requests/events + cross-recipient queue
  references and anonymize retained actor refs; `DigestService` resolves
  `reward_request` (same house, current revision, requester-or-owner
  eligibility); producer queues identity-only rows for opted-in
  requester/owners minus the actor.
- **Step 6 (gates):** unit 1112 OK (host + container), Jest 1118 OK,
  integration 527 OK (fresh family-profile Docker chain), `composer
  cs:check` clean, `npm run lint` clean, `npm run l10n:check-keys` green
  (28 new keys, EN/DE/POT exact coverage), docs encoding/backlog checkers
  green, and the full E2E gate PASSED (`npm run test:e2e:gate` — all three
  pools, zero skipped/failed/flaky required scenarios) with the new
  `family-reward-api.spec.ts` browser contract (serial-DB pool).
- **Implementation-round findings:** none outstanding — routing per §9
  (fix-now items were fixed in-round: `find()` additions for the NC31
  `QBMapper` gap on the new/wallet mappers, entity property defaults
  aligned with the Nextcloud setter-tracking convention).

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|---|---|---|
| Overreservation, double debit/release/refund or partial departure | High | One house-first aggregate, unique effect/event/receipt identities, real competing connections and injected rollback. |
| Funding hold is implemented per request instead of aggregate | High | B>=R settlement check; test multiple reservations, correction, earning, cancellation and refund combinations. |
| Role downgrade or owner demotion bypasses approvals, including on replay | High | Role revision + under-lock live guard on receipt lookup, state/version audit and role/settlement interleavings. |
| Erasure misses departed actors, wishes or another recipient's queue | High | Stable actor-wallet provenance, BOTH erasure paths, cross-recipient purge and recreated-UID tests. |
| Catalog hiding strands agreed promises or mutates personal consent | Medium | Distinct household gate and personal navigation flag; request APIs remain independently reachable; no consent reset on catalog toggles. |
| Digest adds pressure or private content | Medium | Reference-only queue, current self/owner resolver, count-only existing summary, no default sends or repeated funding/pending prompts. |
| Backend completion is mistaken for a usable shop or full feature closure | Medium | Explicit WP8/T11 ownership; FEAT-UX-026 and parent stay open, no simulated UI/family evidence. |
| Read/write growth or a service dependency cycle | Medium | Indexed bounded reads, lock only inside the established aggregate, lower-level collaborators, no generic event/policy framework. |

## 8. Acceptance Criteria

- [x] Standard A passes; G1 applicability and all exposed decisions are recorded before runtime work. (2026-10-01, zero findings)
- [x] Fresh schema has three reward tables, role revision, correct defaults/indexes and complete reset/cleanup; no upgrade/shim path. (Version1033 + Version1004 amendment; `FamilyRhythmSchemaTest`; rebuilt disposable chain)
- [x] Household catalog is owner-controlled and default off; personal catalog visibility remains self-only; planning works permanently off. (`PUT /house/catalog` stateVersion contract; `catalogVisible` untouched; gate tests incl. disabled-state reads)
- [x] Catalog prices/amounts are exact bounded minor units; concurrent edits never change agreed request terms silently. (strict `priceMinor` validation; frozen snapshots; displayed-terms drift is 409; price-change race coherent)
- [x] Every C4 actor/state case is implemented; underfunding is orthogonal, and no time/disable/archive transition expires or reopens promises. (lifecycle suite C4.1-9, hold-cancellation, role rebase, departure/rejoin)
- [x] B equals the ledger sum; R equals active request prices; settlement/refund/release occurs once and rollback leaves no partial effects. (reconciliation assertions; injected mid-aggregate failure rollback; idempotent replay; exactly-once refund retries)
- [x] Real undo/attribution after spending preserves independent value/history; spending/refund does not change legitimately earned recognition. (separate `reward_settlement`/`reward_refund` ledger sources; the WP3 completion/attribution suites stay green unchanged)
- [x] Current membership/roles gate commands AND replay; role changes, departure/rejoin and owner invariants are atomic and tested. (WP7 under-lock guard on execution+replay; role revision no-op rule; last-owner/self-demotion invariants green)
- [x] Active/departed erasure purges personal wishes and all affected queue/actor references; recreated UID cannot recover them. (both erasure paths; cross-recipient digest purge; anonymization by wallet provenance; same-UID non-rebinding pinned by the WP0 suites)
- [x] Self/owner request DTOs and errors are privacy-safe; no foreign wallet, shared activity/ranking or notification detail leak. (whitelist DTOs, own-wallet-only block, placeholder serialization, 404-vs-403 mapping, HTTP contract tests)
- [x] Reward changes join the opted-in WP5 digest transactionally with current-rights checks and unchanged bounded at-most-once delivery. (identity-only producer rows, current-revision/requester-or-owner resolver, digest delivery tests)
- [x] Section 4 tests and complete build/lint/backend/browser/document/l10n gates pass with no missing/newly skipped required cases. (unit 1116, Jest 1118, integration 529, php-cs/eslint/l10n/encoding/backlog checkers, full three-pool E2E gate PASSED)
- [x] All plan-owned findings and implementation/review questions are resolved; durable API/ADR/concept/engineering docs are integrated. (§9 all resolved; `docs/api/rewards.md`, ADR-017, concept/architecture/notifications/testing/SKILL/development updates)
- [x] Final Standard B or Full is PASS/PASS-WITH-FIXES; tracking reflects WP7 only, while FEAT-UX-026/WP8 and CONCEPT-001/WP9 remain open. (final Standard B 2026-10-01 `PASS-WITH-FIXES`; parent/ACTIVE_TASKS updated; WP8 and WP9 stay open)

## 9. Review Findings (Plan-Owned)

Resolved during the final Standard B review round (2026-10-01; the full fix
list lives in §Review Outcome):

- [x] major · `Version1033` index led with `house_id` for wallet-led queries
  (mine list / per-wallet hooks) — fixed to `(wallet_id, state, id)`.
- [x] major · list-path N+1 serialization (per-request requester + wallet
  lookups) — batched prefetch in `listForViewer`.
- [x] major · `is_int` paging boundary broke real HTTP GET (`limit`/`cursor`
  strings) — canonical digit strings accepted, non-canonical rejected.
- [x] major · departure: stale pre-lock owner-invariant check and a null
  audit/digest actor — under-lock recheck + acting owner forwarded.
- [x] block · two race-test baseline bugs (post-race `$bookedBefore` capture
  made the fulfilled branch unpassable and the rollback assertion vacuous).
- [x] minor · no-op catalog toggle bumped `stateVersion`; `settleReward`
  lacked the reservation undershoot guard; departure/role-rebase digest
  recipient rules (per-request queue, requester inclusion, hoisted
  `ownersOf`); dead mapper methods/docblocks; C4 matrix denial/replay
  cases added.
- [x] minor · `runLifecycleCommand()` (~97 lines) aggregated payload
  normalization, the aggregate closure, event/financial wiring and digest
  recipient selection — extracted into `executeLifecycleEffectUnderLock()`
  and `digestRecipientsFor()`.
- [x] minor · `requireCommandId`/`requireExpectedVersion` validators were
  duplicated verbatim between `RewardService` and `RewardRequestService` —
  deduplicated as `CommandService::requireCommandUuid()` /
  `requireExpectedVersion()` statics.
- [x] minor · `settleReward` re-locks the wallet row after `postEntries` —
  RESOLVED BY ANALYSIS, not a defect: the reservation release must apply to
  the row state AFTER `postEntries`' booked update, so the second
  locked read is structurally required (folding it into the ledger writer
  would couple reservation semantics into the generic ledger API).
- [x] nit · audit-history serialization resolved event actors per row —
  memoized per serialization call.

Out-of-scope finding routed to the backlog: `DigestChangeMapper::
expireQueuedBefore` uses `setMaxResults()` on an UPDATE (silently ignored by
Doctrine DBAL — unbounded expiry), pre-existing WP5 defect (see backlog).

## Draft Validation

Documentation-only checks passed on 2026-10-01: the unchanged backlog/link and
encoding checkers with Git's tracked-plus-untracked inventory supplied in-process,
`git diff --check`, and the new draft's standalone whitespace check. Existing
seams and M test paths were checked against the planning baseline; the index and
runtime paths remain unchanged, and unrelated `.gitkeep` deletions are preserved.
No formal review, runtime suite or family trial ran. The next required lifecycle
gate is Standard A; this draft remains in `new/` and is not activated or committed.

## Review Outcome

**Standard A (plan activation) — 2026-10-01 · `PASS`**

- **Scope:** this plan file only (documentation round; no runtime change). Owning
  plan: this child under parent
  [CS-PLAN-070](../done/cs-plan-070-family-rhythm-and-reward-clarification.md) WP7.
- **Baseline:** `master` at `4b5b089ea180ab6514eb94725eff78032f172138` (equals the
  planning baseline); index empty; unstaged diff sha1
  `2e01799d35b56001812661085a6cbf756ab66d34` (drafting metadata package + unrelated
  `.gitkeep` deletions, preserved unstaged); untracked plan SHA256
  `18EC3E9E222E780DA4DB3617B8655405AE84C4FDEF50FFDCB03A21AF66E9BEDC`.
- **Verification:** every section-1 baseline citation checked exact against the
  runtime (`House::jsonSerialize()` omits `catalogEnabled`; `WalletService::postEntries()`
  + B/R/A/`fundingHold` read; `reserved_minor` with no reservation writer; no
  `roleRevision` on `HouseUser`/Version1004; `orgSourceIsRelevant()` closed over
  takeover/agreement/pause; orphan-wallet erasure sweep; `ensureBinding()`;
  `updateUserRole()`; `runCommand()`; frozen house-first lock order with reward rows
  placeable in the final child-row group; `catalogVisible` persistence; Version1000
  catalog flag; no reward routes/entities/services/tests; `PUT /house` without a
  command/version contract). Manifest: all 26 M paths present, all 21 N paths absent;
  `Version1033Date20260918000000.php` exactly as reserved by the parent (line 1041);
  ADR-017 number free. §3.2 matches the approved C4 matrix row for row; C4.1-14/T6
  enumerated in the parent; effort consistent with the WP7 row.
- **Findings:** zero (nothing fixed, nothing parked; §9 stays empty).
- **Decisions:** none required — C4 product behavior was user-approved 2026-09-17
  and the parent authorizes exactly this WP7 backend slice; in-plan "Proposal:"
  items are parameter choices inside that approval.
- **Gates (documentation-only round):** `backlog:check`, `docs:encoding`,
  `git diff --check` and the plan's standalone whitespace check green after the
  activation edits; runtime gates belong to implementation steps 2-7.
- **Transition:** `new/ → active/` with this outcome; FEAT-UX-026 set In Progress
  (backend slice); tracking docs, parent WP7 row and review record updated;
  unrelated `.gitkeep` deletions preserved unstaged.

**Final Standard B (whole-plan) — 2026-10-01 · `PASS-WITH-FIXES`**

- **Scope:** the complete WP7 change set at `master` `18eb7ad32e01c4015dc36c93c94c7cedab44e424`
  (index empty at capture; capture-time unstaged-diff sha1
  `200ab75fb3b2cb90557c2e14f364300b600370ec`; final reviewed tree after fixes
  unstaged-diff sha1 `1daff20561b798611b7e827182e7bde285d0f4a7`; 54 modified +
  22 untracked paths at capture; per-file untracked SHA256 inventory appended
  to the review record in `docs/backlog/archive/CODE_REVIEW_2026-10-01.md`), reviewed in four read-only tracks
  (correctness, architecture/ownership, security/auth/data-flow,
  performance/code-quality) over the §5 file manifest plus every owning-plan
  requirement: acceptance criteria, §4 test evidence, durable-doc integration.
- **Findings:** 1 block + 8 major + 19 minor/nit consolidated across tracks;
  every finding either fixed in-round or resolved by analysis (§9 records the
  full routing; no open entries remain). Highlights: wallet-led request index
  corrected, list-path N+1 batched, real-HTTP paging boundary fixed,
  departure invariant re-checked under the house lock with the acting owner
  forwarded, two race-test baseline bugs repaired, `settleReward` undershoot
  guard added, `runLifecycleCommand` extracted, validators deduplicated onto
  `CommandService`, C4 matrix denial/replay cases added.
- **Safe fixes applied in-round:** all of the above (no security/auth
  findings were auto-fixed; the auth-adjacent items were fixed deliberately
  by the main agent with focused re-tests).
- **Decisions:** no user questions were required — every behavior change
  conforms to the approved C4/C5e text (departure digest per request,
  role-rebase including the requester, no-op toggle not advancing the
  version) and the fixes stay inside the plan's scope. Out-of-scope
  pre-existing defect routed to the backlog (`TECH-DIGEST-001`:
  `DigestChangeMapper::expireQueuedBefore` UPDATE-LIMIT silently ignored).
- **Gates (rerun on the reviewed tree after every fix cluster):** unit 1116
  OK (host + container), Jest 1118 OK, integration 529 OK on a rebuilt
  family-profile fresh chain, `composer cs:check` 0-of-395, ESLint clean,
  `l10n:check-keys` 1137 keys EN/DE/POT exact, docs encoding 842 files,
  backlog checker green, `git diff --check` clean, and the full three-pool
  E2E gate PASSED (`run-l4c22e`: chromium + serial-db + scheduling-owner,
  zero skipped/failed/flaky required scenarios; an intermediate gate run
  flaked in `3-floor-house.spec.ts` under host load — the spec passes
  standalone and in the rerun).
- **Transition:** `active/ → done/` with this outcome: every §8 criterion
  verified and checked, all §9 findings resolved, durable docs integrated
  (`docs/api/rewards.md`, ADR-017, concepts/architecture/notifications/
  testing/SKILL/development/backlog). FEAT-UX-026 keeps its WP8 UI
  remainder; CONCEPT-001 stays In Progress for WP8-WP9.
