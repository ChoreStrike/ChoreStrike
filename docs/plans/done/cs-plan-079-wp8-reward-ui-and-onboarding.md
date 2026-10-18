# CS-PLAN-079: WP8 - Reward UI and Onboarding

> **Status:** Done (implementation 2026-10-02; final Standard B `PASS-WITH-FIXES` 2026-10-02 — RF-079-01..04 resolved in-round, plan moved to `done/`)
> **Effort:** L; 5-8 engineering days, including T11, documentation and one correction pass
> **Priority:** P1
> **Depends on:** [CS-PLAN-078 / WP7](../done/cs-plan-078-wp7-catalog-and-request-backend.md), delivered 2026-10-01; WP0-WP6 and parent G1 delivered
> **Related backlog items:** FEAT-UX-026 (frontend remainder), FEAT-UX-023 (essential onboarding/help slice), CONCEPT-001 via [CS-PLAN-070](../done/cs-plan-070-family-rhythm-and-reward-clarification.md)
> **Planning baseline:** `master` at `f7ddcbb1860b9c1b4d2021bfd10960e535081247`, 2026-10-02

This child details parent WP8: D7/D8/D10/D11, C3a-d/h, all C4 UI states,
C5f-h and T11. The parent's approved decisions remain authoritative. This round
changes documentation only: no runtime, schema, translations, test implementation,
activation or commit. The design below is proposed for Standard A, not new
product approval or evidence that the UI works.

## 1. Problem Summary

WP7 supplies the complete optional catalog and private request lifecycle, but no
frontend consumer. Coins must not acquire a spend button that bypasses approval,
reservation, physical delivery or the aggregate funding hold.

| Area | Delivered foundation | WP8 gap |
|---|---|---|
| Catalog and requests | Owner-controlled `catalogEnabled`, item CRUD/archive and the complete C4 API; [ADR-017](../../adr/ADR-017-family-catalog-and-redemption.md). | Catalog browsing/editor, own and owner request lists, authorized detail/audit and action forms. |
| Optional areas | `src/store/preferences.js:23-39` and `PreferenceService.php:274-287` default `catalogVisible=true`; WP6 Settings persist the person's choice. The separate WP7 household catalog defaults off. | Consume the loaded current-person preference without hiding existing promises or rewriting defaults. |
| Wallet and recognition | Exact B/R/A minor-unit wallet, correction-related negative balances and retained rhythm awards. | Explain available versus reserved Coins; display funding holds without punishment language or a second wallet authority. |
| Input and commands | `minorUnits.js` has `formatCoinMinor`/`formatEnergyMinor`, but no decimal input parser; `house.js:242-271` retains an uncertain reset command identity. WP7 carries displayed terms and optimistic versions. | Add exact decimal parsing to the existing utility; delivery/non-delivery attestations, stable uncertain retries and conflict-safe drafts. |
| Help and identity | `src/components/Help/{HelpEntry,HelpGlossary}.vue`; central `useStoreReset.js` explicitly enumerates stores. | Contextual account/role/share/catalog help, reward reset registration and guarded wallet/preference refreshes. |
| Browser evidence | WP7 `family-reward-api.spec.ts` proves HTTP lifecycle/accounting; it is not catalog UI evidence. | T11 owner/member/child UI flows on desktop/mobile/keyboard, registered in the required browser gate. |

The implemented API contract is [rewards.md](../../api/rewards.md), not the
parent's older route proposal. In particular, catalog enablement uses
`PUT /house/catalog`, requests use `expectedVersion`, and refund correction needs
the original `settlementEntryId`. Existing membership/private-task rights remain
unchanged; `allowedActions` is a viewer hint, never authorization.

## 2. Strategy

Build one thin Pinia client and small Vue surfaces on the delivered WP7 API.
Keep catalog discovery optional, request management continuously reachable and
money/state server-authoritative. Reuse Nextcloud components, shared feedback
patterns and the delivered help/reset infrastructure; no new service or currency.

| Owner | Deliverable / boundary |
|---|---|
| WP8 / this child | Catalog visibility/navigation, owner gate/editor, own/owner requests and all C4 actions, exact price entry, retry/conflict/privacy reconciliation, essential onboarding/help, EN/DE/POT and T11 evidence. |
| WP0-WP7 / delivered | Schema, authorization, lifecycle/accounting, roles/departure/erasure, quiet digest, preferences, recognition and calm core UI. Consume these seams; no redesign of their approved behavior. |
| WP9 / later | Full cross-DB verification, two consecutive combined browser gates and voluntary family evidence. A technical WP8 pass neither finishes CONCEPT-001 nor authorizes family contact/data collection. |
| FEAT-UX-023 / remaining | Wider guided tour/help expansion beyond the parent's essential WP8 slice. Do not claim whole-item closure from account/catalog copy or contextual links alone. |

G2 requires delivered WP7/T6 plus this WP8/T11 slice, including disabled-catalog
request access and planning with the catalog permanently off. The household
catalog defaults off; preserve WP5's delivered personal visibility defaults
(`catalogVisible=true`, `arcadeVisible=true`) and honor personal opt-outs. These
are visibility choices, not notification/ranking consent or authority to redeem.
No new preference default or migration is inferred from "optional catalog".
The 5-8-day detail estimate expands the parent's
original 3-5-day allowance for the now-concrete paging, uncertain-response,
shared-device, complete actor/state and browser/localization evidence; it is not
a promised deadline or an estimate of agent runtime.

Out of scope: default seeded rewards, discounts/stock/expiry, real payments,
essential-care rewards, chore exemptions/Task Pass, cosmetics, additional games,
local child profiles, account provisioning, a general chore-approval queue,
automatic tours and persisted tour-dismissal preferences. No backend route or
migration is expected; any necessary API change is recorded and reviewed before
expanding this manifest.

## 3. Backend / Frontend Changes

### 3.1 Surfaces and the Two Independent Gates

Proposed routes use one new `Rewards.vue` with explicit catalog/request mode rather
than adding another primary dashboard. Existing `/today` landing is unchanged.

| Surface | Entry / visibility | Behavior |
|---|---|---|
| Catalog | `/rewards`; navigation only when the house gate and the current person's loaded `catalogVisible` are both true. | Active items and request confirmation; hidden/disabled direct entry shows a neutral explanation, not an auto-opt-in or stale items. |
| Own requests | `/reward-requests`; one persistent authenticated navigation entry, independent of BOTH catalog gates and request count. | Own pending/approved/history states, funding-hold explanation, cancellation and current allowed actions; explicit empty/loading/error states. |
| Request detail | `/reward-requests/:requestId`; same request surface. | Fetch authorized detail/audit on opening; unavailable/foreign IDs get a generic unavailable state, not reconstructed cached wishes. |
| Owner request administration | Owner-only household scope within the request surface. | Explicit `scope=house`, never prefetch for ordinary members; administration remains accessible while catalog browsing is off. |
| Owner catalog administration | Existing owner House section in `Settings.vue`. | Prepare/edit/archive items and toggle the household gate even while personal browsing is hidden or the house gate is off. |
| Personal visibility | Existing self-only `NotificationPreferences.vue` in Settings. | Reuse the existing writer; hiding browsing immediately removes its entry/content, not request access or the house gate. |

Use the existing `house` store for the household gate and refreshed
`stateVersion`; never send the flag through unversioned `PUT /house`. A child or
ordinary member gets no owner gate/editor/house-list controls. Owner enablement
does not enable personal browsing, notifications, sound, animations or ranking.
The gate does not write `catalogVisible`: a person's already-visible preference
can expose browsing when the family enables it, while an existing personal
opt-out always remains hidden. Treat unknown/loading/failed current preferences
as non-browsable; add a context-guarded loaded marker rather than trusting the
store's initial `true` value as a completed preference read.
Load the house flag through its guarded `fetchHouse()` after membership resolves;
do not eagerly fetch catalog items for a hidden navigation entry. Requests only
depend on current membership/authorization, not a successful optional-area read.

### 3.2 Transport and Read State

Add API wrappers in `src/services/api.js`, following its existing envelope/error
contract. `src/store/rewards.js` owns catalog pages, own/house request pages,
selected detail, loading/errors and in-flight command identities, not accounting.

| Operation | Exact client payload / read |
|---|---|
| Gate | `{catalogEnabled, expectedVersion: house.stateVersion, commandId}` -> refreshed house DTO. |
| Item create/edit | Literal title/description and integer `priceMinor`; create has `commandId`, update adds item `expectedVersion`; archive uses the same update, never DELETE. |
| New own request | `{itemId, itemVersion, priceMinor, commandId}` from the explicitly reviewed displayed item; no requester/role/balance supplied. |
| Approve/reject/cancel | Request `expectedVersion` and `commandId`; only documented optional `reasonCode` for reject/cancel. |
| Fulfill | Request version/command plus explicitly confirmed `delivered: true`; approval is never fulfillment. |
| False-fulfillment correction | Request version/command, explicit `notDelivered: true`, required closed-set `reasonCode` and the server's original `settlementEntryId`; no client refund amount. |

- Keep active/admin catalog and mine/house request pages separate by context,
scope and state filter. Catalog cursors advance ascending IDs; requests use
  descending IDs. Use the last returned ID, default limit 50, maximum 100;
  no invented `nextCursor`, count, or unbounded load-all call.
- Provide explicit load-more behavior with ID deduplication. Changing a scope,
  filter or identity invalidates its cursor/read generation; a delayed page must
  not repopulate another list. Request audit loads on demand, not once per row.
- Refresh current authorized lists/detail, house version and existing user/game
  wallet reads after mutations. Do not optimistically increment balances,
  reservations, awards or request state. A request's optional `wallet` snapshot
  is caller-own only; do not derive or fetch another person's B/R/A totals.
- Use `game.wallet` from `fetchGameState()` as the existing wallet display
  source, matching `Dashboard.vue:52-54`; no `rewards.wallet` cache or local
  request-price sum. Reconcile an own response through `user.applyOwnWallet()`
  where needed, without inventing contribution or foreign-wallet values.
  Empty request lists still load own-wallet state independently.
- A successful command followed by a failed refresh remains a successful
  command with a visible refresh error, not permission to submit a new effect.
  Guard late read AND mutation responses against the reset/context generation.

Extend `game` read generations and reset all cached wallet/contribution/version
fields before using its refresh in this slice. Add a forced, generation-aware
current-user refresh for role/membership reconciliation; the cached
`initializeCurrentUser()` return is not a fresh authority check. Likewise guard
preference reads/writes and their loaded marker across reset. Keep reset
orchestration outside stores and extend the existing store-DAG/enumeration tests.

### 3.3 Exact Prices and Authored Content

Extend `src/utils/minorUnits.js` with a strict `parseCoinMinor()` alongside its
existing formatters; no parser exists at baseline. Keep drafts as strings in a
text field with decimal input mode. Parse decimal digits to integer hundredths, never
`parseFloat`, floating-point multiplication, `v-model.number` or `toFixed` for
money. Accept whole prices and at most two fractional digits with dot or comma;
reject mixed separators/grouping, exponents, excess precision, zero, negatives
and unsafe integers. Format positive and negative B/R/A values exactly; never
clamp an available balance to zero.

Respect the API's title 1-120 / description at most 2000 character bounds and
control-character rejection. Family-authored text is plain escaped content,
not an l10n key or HTML/Markdown. Do not use `v-html`, fabricate default promises
or auto-adjust prices when a request becomes unaffordable. Catalog edits never
rewrite the snapshot displayed on an existing request.

### 3.4 Request States and Attestations

`RewardRequestList.vue` handles paging/filter/selection;
`RewardRequestDetails.vue` handles frozen terms, authorized audit and action
forms. Splitting list from detail keeps stateful forms out of repeated rows.
Use `allowedActions` plus `fundingHold` for presentation, without a second C4
transition engine or assumptions based on an old role snapshot.

| State / condition | Required presentation and actions |
|---|---|
| `pending` | Waiting for approval, Coins reserved but not spent. A current owner can approve/reject a child's request; a promoted adult can explicitly approve their own pending request. Requester/owner cancellation remains available. |
| `approved` | Approved, not delivered. Children never self-fulfill; current owner or authorized adult self separately confirms actual delivery. Cancellation remains available until delivery. |
| Funding hold on either open state | Keep the actual approval state and fixed terms; disable fulfillment and new requests for the affected wallet, not approval/rejection/cancellation. Explain balance clarification neutrally; no expiry, priority queue or demand to earn more. |
| `fulfilled` | Delivered and debited once. No ordinary cancel/refund button; only an owner's explicitly attested non-delivery correction. |
| `rejected` / `cancelled` | Terminal, reservation released without a booked credit. Archive/re-enable/rejoin does not revive the promise. |
| `corrected` | Terminal false-fulfillment correction, original debit refunded and no new reservation. A later reward requires a new consciously submitted request. |

Normal delivery confirmation identifies the frozen reward/price and requires
explicit confirmation. Correction additionally explains that it is ONLY for a
reward never delivered, requires a selected reason code and the non-delivery
attestation, and retains the original settlement reference. Do not offer a
generic refund for a real delivered reward. Reject/cancel reasons use only the
API's closed codes; no medical/personal free-text notes.

Render departed/erased actor placeholders from the authorized DTO, localized and
without identifiers. Owners processing another person's request see its funding
hold and terms, never that person's wallet amounts. Refresh current actions
after role changes/re-entry; the API still denies stale authority immediately.

### 3.5 Conflicts and Uncertain Outcomes

- Generate one RFC 4122 v4 UUID per logical command. Keep UUID, normalized
  payload and submitted version together until the outcome is known; disable
  duplicate submits for the same command. No generic retry framework is needed.
- An uncertain transport/5xx outcome does NOT prove rollback. Offer an explicit
  retry of the SAME UUID/payload/version, never an automatic fresh-key resend or
  an edited payload under the old key. Keep the unresolved intent in memory only.
  Make its reconciliation/retry reachable from Requests even if browsing is
  subsequently hidden or disabled; this is replay, not a new request. Reload
  never auto-submits an abandoned intent; load persisted state before new actions.
- `409` is not necessarily a stale version: it can be price/description drift,
  gate/archive, closed transition, insufficient funds or funding hold. Refresh
  authorized source data, preserve the draft, explain the conflict and require
  review/confirmation before any newly versioned command.
- For changed displayed item terms, show the refreshed title/description/price
  before requesting again. Never silently reserve the new price or auto-submit
  merely because a refresh succeeded. Owner edit drafts likewise survive 409.
- `400` preserves input with actionable validation. `403`/`404` clear denied
  detail/owner-scope caches and refresh membership/controls; no privilege retry
  or message revealing whether a foreign wish exists.
- On identity/house reset, clear drafts, audit, scopes, errors and unresolved
  commands; invalidate their responses before loading the next person. A server
  command already executing cannot be recalled, but its result must not render
  or be retried as the next identity.

### 3.6 Essential Onboarding and Help

Extend the delivered `src/components/Help/{HelpEntry,HelpGlossary}.vue`, not a
second glossary or an auto-started overlay. `HelpEntry` currently renders a
navigation `<li>` and uses `NcModal`: support a valid context-trigger root outside
navigation, and use the shared named `Modal.vue` for focus/keyboard behavior.
Add manually reachable contextual explanations in
My Day, Scheduling, task details, reward surfaces and relevant Settings sections;
reuse the existing labeled help modal and keep navigation-level help available.

- Setup/invites: each person, including a child, needs their own existing
  Nextcloud account; account creation/sign-in belongs to Nextcloud. Wizard
  invites create members; an owner sets the child role through member Settings.
  A shared device uses separate sign-ins, not local child profiles.
- Agreements: responsibility/joint obligations differ from voluntary help.
  Actual shares split the total work, not multiply it; a total energy-1 chore
  earns 10 Coins once, with 34/33/33% giving 3.4/3.3/3.3 Coins.
- Progress: explain completion windows, planned-day rhythm, neutral free/paused
  days, catch-up without retrospective on-time credit, and contribution versus
  spendable/reserved Coins. Do not teach combos, freezes or Coin-funded games.
- Catalog: owners and the person control different gates; child request/owner
  approval and adult request both reserve first, with delivery confirmed later.
  Existing requests remain reachable after hiding/disablement. Spending does
  not remove legitimate rhythm titles/awards; games only have separate scores.
- Owner pricing: suggest affordable, family-agreed non-essential treats against
  the child's own realistic effort/share, not adult output. Care, affection,
  meals, rest and necessary chore exemptions are not purchasable rewards.
  No automatic catalog templates or household earning-rate controls.

Keep the wizard's existing apply/invite/skip behavior and core task commands
unchanged. Household catalog, notifications, effects and ranking retain their
delivered quiet defaults; personal visibility choices are not rewritten. This
help slice supplies instructions; it is not WP9 comprehension
or two-week family-use evidence.

### 3.7 Accessibility, Localization and Feedback

Follow [design.md](../../standards/guidelines/design.md): shared `Modal.vue` and
`ConfirmDialog.vue` for named dialogs, focus trap/restore and Escape; shared
`EmptyState.vue` / `ErrorState.vue`, explicit async status and retry. Use at least
44px targets, keyboard-operable forms/filter/load-more controls, visible focus,
live status and non-color state labels on narrow/mobile and desktop layouts.

Use literal `t()` calls for state/action/reason/help labels and messages; authored
catalog content remains escaped content. Update EN/DE/POT together, including
placeholders/accessibility text; do not whitelist an unextracted dynamic label
map. Reuse `src/utils/logger.js` with sanitized technical context only, never
wish text, balances, notes, tokens or whole command payloads. Feedback is quiet
success/error/status text, without new sounds, confetti or award modals.

## 4. Tests

These are required future implementation proofs, not tests run in this draft.
`N` means new after activation; `M` means extend an existing test. T6's backend
proofs remain mandatory regressions, not substitutes for T11 UI actions.

### 4.1 Jest and Backend Regressions

| Case | Required proof / owner |
|---|---|
| API/store T11 | N `tests/unit/store/rewards.spec.js`; M `tests/unit/services/api.spec.js`: exact URLs/envelopes/payloads, separate scopes/filters, both cursor directions, deduplication/exhaustion, detail audit, UUID retention/changed intent, 400/403/404/409/uncertain errors and success-with-refresh-failure. |
| Catalog/editor | N `tests/unit/views/Rewards.spec.js`, `tests/unit/components/RewardCatalogEditor.spec.js`: both gates, direct hidden entry, independent owner preparation, positive exact decimal input/bounds, current terms review, archive and 409 draft preservation; escaped authored text. |
| Request list/detail | N `tests/unit/components/RewardRequestList.spec.js`, `tests/unit/components/RewardRequestDetails.spec.js`: every state/allowed action, child denial/adult explicit self-approval, hold orthogonality, delivery and non-delivery attestations, required settlement reference, closed reasons, former actors and no foreign wallet display. |
| Navigation/preferences/house | M `tests/unit/components/App.spec.js`, `tests/unit/router.spec.js`, `tests/unit/views/Settings.spec.js`, `tests/unit/store/{house,preferences}.spec.js`: persistent request entry, fail-closed not-yet-loaded visibility and immediate personal opt-out, owner gate's house version/command identity, unchanged personal defaults and no member/child admin-scope fetch. |
| Identity reconciliation | M `tests/unit/store/{user,game,preferences,dependencies}.spec.js`, `tests/unit/composables/useStoreReset.spec.js`, plus rewards spec: forced current-role refresh, complete wallet reset, delayed page/detail/wallet/preference/mutation rejection, scope/filter generations and no cross-login drafts/retries. |
| Exact money/help | M `tests/unit/utils/minorUnits.spec.js`, `tests/unit/components/{SetupWizard,Help,TodayView,SchedulingView,TaskListDetails}.spec.js`: strict new parser/signed exact formatting, safe boundaries, malformed/grouped/exponent/excess precision; truthful account/role/share/window/pause/catalog help, valid context markup and named dialogs. |
| T6 regression | Retain `tests/unit/Service/{RewardService,RewardRequestService}Test.php`, `tests/integration/Service/{RewardLifecycle,RewardConcurrency}Test.php`, `tests/integration/Controller/RewardApiTest.php`, existing erasure/member-removal/digest suites and their no-skip gate. No new PHP behavior is assumed; an in-scope contract fix needs its own regression. |

### 4.2 Browser Scenarios (T11)

Add `tests/e2e/family-rewards.spec.ts` to `chromium-serial-db` through
`SEED_DEPENDENT_SPECS`, following `family-reward-api.spec.ts`.
Use isolated `family` fixtures, authenticated owner/member/child contexts and
real Nextcloud CSRF sessions. Default seed/catalog stays off; each reward case
explicitly enables its own gates and creates synthetic promises. The feature
actions under test must use the UI; APIs may set up data, stimulate a concurrent
change/failure and assert durable effects, not replace the measured UI action.
Reuse `tests/e2e/fixtures/{database-fixtures,auth-contexts}.ts` (`seededData`,
`createUserContext`) and `completion-api.ts` for setup; close created contexts.
No new seed endpoint, global wallet-credit bypass or auth runner is required.

| Required scenario | Assertions |
|---|---|
| Catalog-off core and independent opt-ins | All-role My Day/planning/completion still work with the house catalog off; personal enablement alone does not open it; either opt-out hides browsing without hiding Requests. |
| Owner administration and exact prices | Hidden/off catalog remains editable by owner; create/edit/archive with whole and decimal prices, validation and stale draft recovery; members/children never gain these controls. |
| Child request, approval and delivery | Child creates pending request with one reservation; owner approval does not spend; owner separately confirms delivery once; child cannot self-fulfill; own and owner UI reload to correct totals/state. |
| Adult flow, cancellation and correction | Adult request is approved, not delivered; explicit self-delivery; requester cancellation releases only R; owner-attested false fulfillment refunds original debit once and stays corrected, including after item repricing. |
| Changed terms and hidden promises | Owner changes price/content or archives/disables while another dialog is open: conflict requires renewed review, no silent reservation; prior request retains terms and all authorized processing outside hidden catalog. |
| Correction-related funding hold | Actual chore undo/attribution creates B<R; all affected open settlements/new requests are blocked, approval/cancellation remain possible, history does not expire and coverage restoration refreshes UI. Spending leaves legitimately earned recognition visible after reload. |
| Role/privacy/identity boundaries | Adult->child requires owner reapproval; promoted adult explicitly self-approves; demoted owner loses admin actions; foreign request detail is unavailable; departure/rejoin restores booked value/history, not active requests/opt-ins; second identity sees no previous wishes. |
| Uncertain UI command outcome | Commit a command then lose its response; explicit same-key UI retry returns the original identity with one reservation/debit/refund, including after catalog hiding. No duplicate submit while unresolved and no fresh-key effect caused by a failed follow-up refresh. |
| Mobile, keyboard and real localization | Narrow owner/child request flows and keyboard-only editor/attestation/help: labels, focus trap/restore, status/errors and reachable requests after opt-out. Assert real EN/DE state/help rendering, not only identity-mocked `t()`. |

Register exact assertion-bearing scenario titles in
`tests/e2e/required-scenarios.json`, `scripts/run-e2e-gate.js` and
`playwright.config.ts` in the same change. Extend existing calm-core, setup and
localization browser specs only where their surface changes. Keep the delivered
WP7 API spec and title-retention proof required. No sleep-based waits, vacuous
conditional assertions, new skips or mock-only browser acceptance.
Extend `tests/unit/scripts/browserGate.spec.js` for the new required-file/pool
entry. Run the runner's `--self-test`; the full canonical gate starts with
Playwright `--list` discovery for all three pools and rejects missing/duplicate
ownership before launching tests. The runner itself has no `--list` CLI option.

### 4.3 Gates

Future implementation gates in the canonical Docker environment:

```powershell
npm test -- --runInBand
npm run test:noise
npm run lint
npm run stylelint
npm run build
npm run l10n:check-keys
npm run backlog:check
npm run docs:encoding
git diff --check
docker compose -f docker/compose.test.yml exec -T nextcloud-test vendor/bin/phpunit --configuration phpunit.unit.xml --fail-on-skipped
docker compose -f docker/compose.test.yml exec -T nextcloud-test vendor/bin/phpunit --configuration phpunit.integration.xml --fail-on-skipped
docker compose -f docker/compose.test.yml exec -T nextcloud-test composer cs:check
node scripts/run-e2e-gate.js --self-test
npm run test:e2e:gate
```

Use the existing `family` environment and reset-owning fixtures; WP8 introduces
no schema rebuild/upgrade path. Diagnose Docker failures per project rules and
load `docker-dev` before environment work; never replace the Docker gates with
host-only results. WP9 owns the complete engine matrix and final gate pair.
Drafting alone runs document/backlog/link/encoding/whitespace and manifest checks,
including this untracked plan; it does not claim these runtime gates ran.

## 5. File Manifest

`N` = proposed new implementation file; `M` = existing file to extend. Section
4's test paths belong to this manifest. No deletion/archive or new dependency is
expected. The existing source/test paths below were checked during drafting;
Standard A rechecks them against its then-current baseline.

| Kind | Paths / purpose |
|---|---|
| N reward client | `src/store/rewards.js`; `src/views/Rewards.vue`; `src/components/Rewards/{RewardCatalogEditor,RewardRequestList,RewardRequestDetails}.vue`. |
| M transport/navigation | `src/services/api.js`; `src/{router.js,App.vue}`; `src/store/house.js` (narrow catalog writer and refreshed version). |
| M preference/reset/reconciliation | `src/components/Settings/NotificationPreferences.vue`; `src/composables/useStoreReset.js`; `src/store/{user,game,preferences}.js` (forced role refresh, wallet refresh/reset guards and loaded preference state). |
| M owner/help surfaces | `src/views/{Settings,TodayView,SchedulingView}.vue`; `src/components/Setup/SetupWizard.vue`; `src/components/Tasks/TaskListDetails.vue`; `src/components/Help/{HelpEntry,HelpGlossary}.vue`. |
| M exact money | `src/utils/minorUnits.js` (new strict parser alongside existing exact formatters); no parallel reward-only money helper. |
| N Jest/browser | `tests/unit/store/rewards.spec.js`; `tests/unit/views/Rewards.spec.js`; `tests/unit/components/{RewardCatalogEditor,RewardRequestList,RewardRequestDetails}.spec.js`; `tests/e2e/family-rewards.spec.ts`. |
| M existing Jest | `tests/unit/services/api.spec.js`; `tests/unit/router.spec.js`; `tests/unit/views/Settings.spec.js`; `tests/unit/store/{house,user,game,preferences,dependencies}.spec.js`; `tests/unit/composables/useStoreReset.spec.js`; `tests/unit/utils/minorUnits.spec.js`; `tests/unit/components/{App,SetupWizard,Help,TodayView,SchedulingView,TaskListDetails}.spec.js`; `tests/unit/scripts/browserGate.spec.js`. |
| M browser/gates | `tests/e2e/{calm-core-ui,family-core-ux,setup-wizard-journey}.spec.ts`; `tests/e2e/required-scenarios.json`; `scripts/run-e2e-gate.js`; `playwright.config.ts`. Keep the existing WP7 API/title-retention tests required. |
| M l10n | `l10n/{en,de}.json`; `l10n/templates/chorestrike.pot`; no source/UI text change without these artifacts. |
| M durable docs | `docs/concepts/{ui-use-cases,calm-core-ux,gamification-and-rewards,house-setup-and-templates}.md`; `docs/engineering/{architecture,testing}.md`; `docs/testing/release-test-plan.md`; `docs/api/rewards.md` only for client/presentation clarifications, not invented contracts. No new ADR expected. |
| M tracking/indexes | This child, parent CS-PLAN-070, `docs/plans/README.md`, `docs/README.md`, `docs/backlog/{backlog,ACTIVE_TASKS}.md`; monthly archive/review record only at actual reviewed completion. |

Parent refinements: `catalogVisible`, Help-directory components, exact-money
formatters and reset orchestration already exist (M, not N); price parsing is
new work in the existing helper, not an existing parser to reuse. Keep the WP5
visibility defaults and WP7 household-off gate distinct. Owner administration belongs in the
existing Settings House section, not a new owner view. The request route/detail,
shared reset, API wrappers and browser gate registration supplement the parent's
short WP8 manifest; no reward schema/service/controller/routes are recreated.

## 6. Implementation Order

1. **Standard A:** verify the WP7/G1 evidence and current DTOs, baseline/manifest,
   actor/state map, estimate and essential-help boundary. Resolve any exposed
   questions together before activation. No runtime start from drafting alone.
2. **Transport/store/price seam:** implement exact wrappers, pages/detail,
   context generations and command/retry handling with focused Jest tests;
   connect the existing house and own-wallet read sources.
3. **Catalog and persistent requests:** wire routes/nav/two gates, owner editor,
   own/house list/detail, every action/attestation, hold/privacy and conflict-safe
   forms. Verify actor/state and uncertain-outcome regressions before polishing.
4. **Essential help and accessibility:** extend existing help/setup/context
   surfaces, keep wizard/core behavior, verify mobile/keyboard and shared-device
   reset; update literal labels plus full EN/DE/POT artifacts.
5. **T11 browser integration:** add isolated UI scenarios and exact gate titles,
   keep T6/backend/API proofs green and run the full implementation gates. No
   deferral of a required UI scenario to WP9.
6. **Durable documentation and review:** integrate built surfaces/contracts into
   the named permanent docs; resolve this child's findings and submit final
   Standard B or Full review. Only that passing review moves this child to done,
   records G2 evidence and archives FEAT-UX-026 if its whole scope is satisfied.
   FEAT-UX-023 retains any broader open help/tour scope; CONCEPT-001 and parent
   remain active for WP9/family evidence.

Main owns synthesis/shared-file writes. Read-only lookup/review subagents may
assist; no visible Agent Manager worktrees or alternate implementations are
requested by this plan.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|---|---|---|
| Hiding optional browsing strands approved/underfunded promises | High | Independent always-reachable request route/nav and owner administration; test both gates off and archived items. |
| Fresh-key retry duplicates a real reservation/delivery | High | Logical command retains UUID/payload/version; unresolved submit guard and lost-response UI proof; no optimistic accounting. |
| Stale roles or delayed responses expose foreign wishes/actions | High | Explicit owner scope, server hints/current checks, 403/404 clearing, central reset and read/mutation generation guards; second-identity and demotion proofs. |
| Decimal conversion or stale wallet UI lies about affordability | High | Shared exact parser/formatter, one own-wallet source, signed safe-range tests, explicit terms review and refresh; server remains decisive. |
| Delivery/correction UI becomes an ordinary refund shortcut | Medium | Distinct mandatory attestations, closed reasons/original settlement reference, terminal corrected state and no refunds for delivered rewards. |
| Help silently expands to a full tour or new account model | Medium | Parent essential-slice boundary, existing help reuse, no new preferences/profiles/provisioning; leave wider FEAT-UX-023 scope open. |
| Mock-only tests or a missing runner entry masquerade as T11 | Medium | UI-backed owner/member/child scenarios, real EN/DE and mobile/keyboard, exact discovery manifests and complete retry-free browser gate. |

## 8. Acceptance Criteria

- [x] All-age My Day/core planning still work with the catalog permanently off;
  optional areas stay outside the primary landing. Household catalog, consent
  and feedback/delivery defaults are preserved; personal visibility choices
  are honored without inventing another default or auto-opt-in.
- [x] House/personal catalog gates are independent; owner administration works
  while off/hidden and no owner can enable another person's preferences.
- [x] Own requests/detail and authorized owner processing stay reachable under
  both hidden gates, archiving, negative balances and funding holds.
- [x] All C4 actor/state forms work, including promoted-adult explicit approval,
  child reapproval, cancellation, explicit delivery and owner-only non-delivery
  correction with the original settlement reference and terminal outcome.
- [x] Exact price validation/display, frozen terms, no foreign wallet/request
  data and retained recognition after spending are proven in T11.
- [x] Changed terms/stale versions retain drafts and require renewed review;
  uncertain retries keep the same identity without duplicate money effects.
- [x] Pagination/filter/detail and delayed mutation/read responses cannot leak
  across scope, role, identity or house reset; no sensitive browser persistence.
- [x] Essential Nextcloud account/role/share/rhythm/pause/catalog/pricing help is
  reachable, truthful and tested without changing wizard/core semantics.
- [x] Desktop/mobile/keyboard, feedback/focus and real EN/DE presentation pass;
  every new system string has complete synchronized EN/DE/POT artifacts.
- [x] All section 4 gates/tests pass with no missing/newly unapproved skipped
  scenarios; T11 is registered in the canonical browser gate, not deferred.
- [x] Durable docs are integrated, implementation/review questions resolved,
  all section 9 findings fixed or explicitly user-rescoped, and final Standard B
  or Full review is PASS/PASS-WITH-FIXES before this child moves to done.
- [x] Parent/backlog/index/ACTIVE_TASKS reflect actual G2 completion; archive
  only fully completed owners. WP9/family evidence and wider help scope remain
  explicitly open, without fabricated family results or premature parent closure.

## 9. Review Findings (Plan-Owned)

None at drafting/Standard A time. Final Standard B (2026-10-02) found and
resolved all findings in-round — none remain open, nothing routed to the
backlog:

- RF-079-01 · minor · `src/store/rewards.js` `refreshAfterCommand` — a
  post-command refresh reset touched request pages without their active
  state filter, silently flipping a filtered list to All while the select
  still showed the filter. **Fixed:** the refresh passes the context's
  `state`; regression pins the filtered re-read.
- RF-079-02 · minor · `src/views/Rewards.vue` `ensureCatalog` — the
  one-shot `catalogRequested` guard was never released on a failed fetch,
  so the ErrorState retry never re-fetched the catalog and the view
  degraded to the empty state. **Fixed:** the guard releases on failure
  and `loadAll()` restarts the catalog read on retry; regression covers
  the retry path.
- RF-079-03 · minor · `src/store/house.js` `updateCatalogEnabled` — a 409
  left the house `stateVersion` stale (no authorized re-read), so further
  gate toggles kept failing until a Settings remount. **Fixed:** the
  catch re-reads the house after a 409 (§3.5 contract); regression pins
  the fresh version.
- RF-079-04 · nit · `docs/backlog/backlog.md` FEAT-UX-023 status — the
  line lost its closing parenthesis and gained hard wraps. **Fixed**
  in-round.

## Draft Validation

Documentation-only validation passed on 2026-10-02:

- WP7 API/controller/DTO and current frontend navigation, Settings, help,
  wallet/preference/reset and money-helper seams checked against the planning
  baseline. Named existing client/Jest paths are present; proposed reward
  source/Jest/browser paths are absent. No runtime implementation is implied.
- `npm run backlog:check`, `npm run docs:encoding`, `git diff --check` and the
  new plan's standalone `git diff --no-index --check` passed.
- The unchanged document checkers also passed with Git's tracked-plus-untracked
  inventory supplied in-process (`git ls-files --cached --others
  --exclude-standard`): 32 link-scoped files and 864 encoding-scoped paths,
  including this draft; no staging or persisted checker change.
- `npm run l10n:check-keys` passed for the unchanged 1137-key baseline. This
  does not prove the future WP8 strings or UI have translation/test coverage.
- The index and runtime paths are unchanged; the pre-existing three `.gitkeep`
  deletions are preserved. Backlog ownership stays with active CS-PLAN-070
  until the child passes Standard A; draft links do not activate it.

No formal review, runtime suite/browser gate or family trial ran in this round.
Standard A is mandatory next; the child remains in `new/`, unactivated and
uncommitted, while the parent and existing backlog owners remain open.

## Review Outcome

**Standard A — 2026-10-02 — `PASS-WITH-FIXES` (one safe fix, zero open findings).**

- **Scope:** this plan only (documentation round; runtime tree untouched).
- **Baseline:** `master` at `f7ddcbb1860b9c1b4d2021bfd10960e535081247` (equals
  the planning baseline recorded in the header); index empty at capture
  (staged-diff sha1 `e69de29bb2d1d6434b8b29ae775ad8c2e48c5391`); unstaged diff
  sha1 `56926889dd679b611d610f5cb5fe33c492f9d7a2` (the draft-support tracking
  package plus the unrelated pre-existing `.gitkeep` deletions, preserved
  unstaged); untracked plan SHA256
  `C5F9B645B77C9571590098EE32BB7D8FED49834E65448D07BA08E01A75B9A0BB`.
- **Eligibility:** sole regular non-symlink `*.md` in `docs/plans/new/`
  (`LinkType`/`Target` empty); destination `active/` did not exist.
- **Verification:** baseline citations checked exact against the runtime —
  `src/store/preferences.js:23-39` and `PreferenceService.php:274-287` default
  `catalogVisible=true`; `src/utils/minorUnits.js` exports exact formatters and
  no parser; `house.js:242-271` retains the uncertain reset command identity;
  `Dashboard.vue:52-54` reads `gameStore.wallet`; `user.js` has
  `applyOwnWallet()` and the cached `initializeCurrentUser()`; `game.js`
  `fetchGameState()`; `HelpEntry.vue` renders a navigation `<li>` with
  `NcModal`; shared `Modal.vue`/`ConfirmDialog.vue`/`EmptyState.vue`/
  `ErrorState.vue` exist under `src/components/Common/`. API contract claims
  match `docs/api/rewards.md` exactly (`PUT /house/catalog` as the only gate
  writer, `expectedVersion`, closed reject/cancel codes, `settlementEntryId`,
  `allowedActions`, orthogonal `fundingHold`, title 1-120 / description ≤2000).
  Manifest: every M path present, every N path absent;
  `tests/e2e/fixtures/{database-fixtures,auth-contexts,completion-api}.ts`,
  `SEED_DEPENDENT_SPECS`, `chromium-serial-db` and the runner `--self-test`
  verified; the runner has no `--list` CLI option as stated. Parent CS-PLAN-070
  authorizes exactly this WP8 slice (WP8 row, T11 mapping and the recorded
  5-8-day refinement of the original 3-5-day allowance); no overlap with any
  other active plan. Plan-only checks: nine template sections complete,
  per-step order with ownership, coherent single-slice scope, named risks,
  complete acceptance criteria, concrete test plan.
- **Fix applied in-review (safe, documentation-only):** §3.1 said proposed
  routes "reuse `Rewards.vue`" although `src/views/Rewards.vue` does not exist
  (manifest `N`); reworded to "use one new `Rewards.vue`".
- **Findings:** none remain open; nothing to route (plan-owned, fold-in, or
  backlog).
- **Decisions:** none required — the parent authorizes exactly this slice and
  the WP7/C4 product behavior was user-approved; no behavior, architecture or
  security question arose.
- **Gates (documentation-only round):** `npm run backlog:check`,
  `npm run docs:encoding` and `git diff --check` rerun green after the fix and
  the activation edits; runtime gates (Jest, lint, build, Docker PHPUnit,
  browser gate) belong to implementation steps 2-6 and were intentionally not
  run (no runtime file changed).
- **Transition:** plan moved `new/` → `active/`; FEAT-UX-026 WP8 Standard A
  sub-item checked; parent CS-PLAN-070 header/T11/WP8 rows, `docs/README.md`,
  `docs/plans/README.md`, `docs/backlog/backlog.md` and `ACTIVE_TASKS.md`
  updated; activation record in
  `docs/backlog/archive/CODE_REVIEW_2026-10-02.md`.

## Implementation Evidence

**Implementation round 1 — 2026-10-02 — steps 2-6 delivered, all gates green.**

- **Scope implemented (manifest M + N):** `parseCoinMinor` strict hundredths
  parser (`src/utils/minorUnits.js`, integer-only, no float arithmetic); reward
  API wrappers (`src/services/api.js`); new `src/store/rewards.js` (separate
  paged contexts per scope/state with both cursor directions, UUID-per-command
  identity retained across uncertain outcomes, definitive-4xx discard, 409
  conflict flag + authorized re-read, touched-context-only refresh,
  success-with-refresh-failure = visible `refreshError`, identity-reset
  generation guards); house `catalogEnabled` getter + narrow versioned
  `updateCatalogEnabled` writer (`PUT /house/catalog` only); preferences
  `loaded` fail-closed marker; game read generation + full wallet-field reset;
  `user.refreshCurrentUser()` forced identity re-read; central reset
  enumeration extended (15 stores); routes `/rewards`, `/reward-requests`,
  `/reward-requests/:requestId`; navigation (gated catalog entry + persistent
  My Requests entry, bootstrap house/preference loads after membership
  resolves); `Rewards.vue` (both-gate resolution before any catalog read via
  watcher, wallet banner, frozen-terms confirmation, 409 conflict review,
  funding-hold disablement, route re-entry wallet refresh);
  `RewardCatalogEditor.vue` (gate toggle, exact price entry, edit/archive,
  409 draft preservation) hosted in the owner-only Settings section;
  `RewardRequestList.vue` (own/owner scopes, state filter, text state labels,
  former-member placeholders, funding-hold badges, explicit same-key retry
  banner) and `RewardRequestDetails.vue` (frozen terms, audit, allowedActions
  forms, delivery attestation, reasoned reject/cancel, attested owner
  correction referencing the server settlement entry, 403/404 generic
  unavailable state); `HelpEntry` context variant on the shared named Modal
  with five essential topics placed on My Day, Scheduling, task details, the
  reward surfaces, Settings and the wizard invite step; glossary reward terms;
  EN/DE/POT regenerated (1269 keys, 134 new German translations).
- **Jest:** 1216/1216 across 108 suites (new: rewards store 23, Rewards view 7,
  catalog editor 9, request list 8, request details 12, minorUnits parser 12,
  api wrappers 7; extended: house, preferences, game, user, useStoreReset,
  App nav gating, router, Settings, Help, TodayView/SchedulingView/
  TaskListDetails/SetupWizard help presence, browserGate pin).
- **Lint/stylelint/l10n/backlog/encoding/diff:** all green.
- **Build:** `npm run build` green.
- **Backend (Docker):** PHPUnit 1116 unit + 529 integration, zero failures
  (no PHP changed).
- **Browser evidence (T11):** `family-rewards.spec.ts` — 9 required scenarios
  + mobile/keyboard/real-EN-DE proof, 12/12 green in `chromium-serial-db`
  (gate-registered in `required-scenarios.json`, `run-e2e-gate.js`,
  `playwright.config.ts`, runner self-test 14/14). Neighbor regression specs
  re-run green: `calm-core-ui` 4/4, `family-reward-api` 3/3,
  `family-core-ux` 7/7.
- **Fixes found by the browser gate (root-caused, not worked around):**
  catalog read moved behind a `browsable` watcher (bootstrap gate reads land
  after mount; a one-shot mounted fetch silently skipped browsing); App
  bootstrap loads house + preferences once membership resolves (the nav gate
  requires an authoritative loaded preference); `Rewards.vue` refreshes the
  wallet source on route re-entry (same-route navigation reuses the component,
  so a hold cleared elsewhere kept buttons stalely disabled); owner
  administration already works while the gate is off (verified by scenario 2).
- **Spec/test-harness repairs:** e2e waits poll the authoritative API reads
  (never UI toasts, which can still show a previous action's message);
  `fetchOwnRequestId` ambiguity resolved by state-polling; browserGate
  pipeline test pins its excluded journey by filename (manifest-order
  independent) and silences the gate's expected violation logging.
- **Findings/state changes:** none open; nothing routed to the backlog
  (everything was plan-owned and fixed in-round). No rollbacks.
- **Remaining:** final Standard B/Full code review for the `active/` →
  `done/` transition; durable-doc integration of the delivered WP8 surfaces
  is part of that review's completion criteria.

## Review Outcome (Final Standard B)

**Final Standard B - 2026-10-02 - `PASS-WITH-FIXES` (four fixes: three minor logic, one typo-class; zero open findings).**

- **Scope:** the whole WP8 implementation package (46 modified + 11 untracked
  reviewed files) plus this plan's completion criteria (file manifest,
  acceptance criteria, required test evidence, durable-doc integration).
- **Baseline:** `master` at `812721a910bbd9aed3c9c282260d751645d22d2b`; index
  empty at capture (staged-diff sha1 `e69de29bb2d1d6434b8b29ae775ad8c2e48c5391`);
  unstaged-diff sha1 `671e682493f453c1d8ddb2316d17cb98e77925b6` (434254 bytes);
  untracked manifest with per-file SHA256 captured before review; unrelated
  pre-existing `.gitkeep` deletions preserved unstaged.
- **Verification:** store/view/component contracts checked against
  `docs/api/rewards.md` (exact command payloads incl. `expectedVersion` and
  `commandId`, closed six-code reason set, caller-own `wallet` snapshot
  semantics, settlement-referenced correction); two-gate fail-closed
  navigation and persistent request surfaces; UUID identity retention with
  same-key replay and definitive-4xx discard; per-context read generations
  and the 15-store reset enumeration; strict `parseCoinMinor` grammar
  (no float arithmetic); literal-`t()` label maps (l10n-extractable); no
  raw `console.*`, no `v-html`; exact gate registration (9/9 scenario
  titles across `required-scenarios.json`, `run-e2e-gate.js`,
  `playwright.config.ts`).
- **Questions/decisions:** one batched structured request, both
  recommendations accepted - (1) fix RF-079-01..03 in-round with
  regression tests; (2) re-run the FULL canonical three-pool browser gate
  on the final tree (see gates).
- **Gates (rerun green on the reviewed final tree):** Jest 1219/1219
  (108 suites, +3 review regressions), ESLint/stylelint zero problems,
  `npm run build` webpack compiled successfully, `npm run test:noise`
  green, `npm run l10n:check-keys` 1269 keys EN/DE/POT,
  `npm run backlog:check` 32 files, `npm run docs:encoding` 865 files,
  `git diff --check` clean, `node scripts/run-e2e-gate.js --self-test`
  passed, Docker PHPUnit unit 1116 + integration 529 (`--fail-on-skipped`,
  zero skips; no PHP changed), `composer cs:check` 0/395 fixable, and the
  full `npm run test:e2e:gate` **PASSED** - chromium ->
  chromium-serial-db -> chromium-scheduling-owner sequentially with zero
  skipped/failed/flaky required scenarios.
- **Durable-doc integration:** UC-17 + gap-index/whitespace updates
  (`ui-use-cases.md`), `useRewardsStore` contract block + directory
  entries (`architecture.md`), optional-areas two-gate contract +
  fifteen-store reset (`calm-core-ux.md`), WP8 implemented note
  (`gamification-and-rewards.md`), serial-pool spec note (`testing.md`),
  release checklist 8a + coverage pointer (`release-test-plan.md`),
  wizard help note (`house-setup-and-templates.md`). `docs/api/rewards.md`
  needed no change - the client mirrors the documented contract.
- **Findings:** RF-079-01..04 recorded in section 9, all resolved
  in-round; nothing out-of-scope, no `// HACK:` additions, no backlog
  routing.
- **Transition:** plan moved `active/` -> `done/`; FEAT-UX-026 archived
  (whole scope satisfied by WP7+WP8); parent CS-PLAN-070 WP8 row marked
  DELIVERED (parent stays active for WP9/family evidence); FEAT-UX-023
  keeps its wider tour remainder; CONCEPT-001 stays In Progress;
  ACTIVE_TASKS, docs READMEs and `CODE_REVIEW_2026-10-02.md` updated.
