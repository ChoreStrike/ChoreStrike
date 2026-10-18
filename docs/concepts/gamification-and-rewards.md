# Gamification and Rewards

## Goal

Keep the game layer fun and motivating while preserving a supportive family tone.

**Product direction (2026-09-16):** reward keeping an agreed chore rhythm,
not daily activity. The implemented mechanics below are a baseline, not approval
of their suitability for that goal. Dated decision sections distinguish the
2026-09-17 approved target from current behavior; unaddressed details remain open.

## Core Loop

1. Task becomes due.
2. Visual room state reflects pending work.
3. User completes task in ChoreStrike (external task-client sync is not implemented).
4. System awards positive rewards and updates cooperative progress.

## Reward Language Policy

- Avoid military reward terms.
- Use friendly terms that fit family collaboration.
- Use **Coins** as the reward currency (shipped). Wallet value is exact
  **minor units** since WP3 (2026-09-27, [ADR-014](../adr/ADR-014-work-accounting-ledger-and-replay.md)):
  100 coin minor = 1 Coin, 1000 coin minor per frozen energy unit (10 Coins at
  the frozen rate). Rewards are exact derivations of the frozen work evidence —
  no integer mints, no tier/personal/early/combo multipliers (retired with the
  legacy economy); the Coin column is gone from the UI. The former
  reward-config HTTP routes were pruned, not shipped as an admin API.

## Parent Contribution Balancing

Parents or primary caretakers often complete more chores. Raw output is not a
fair comparison between ages, abilities or available time. Reducing a parent's
reward merely to manufacture equal rankings is not an agreed requirement.

### Suggested mechanism

- Separate "contribution score" from "reward currency".
- Contribution score reflects actual completed energy.
- For MVP, do not apply role-based multipliers to coins.
- If balancing is needed, compare contribution score and coins in separate views.

## Cooperative Features and Limits

- Household progress/health bars, a weekly goal and completion celebrations exist.
- Team streaks are an earlier idea, not an implemented household-rhythm counter.
- Shared assignment currently does not distribute contribution or coins to all
  participants. The completer receives the contribution, base coins and teamwork
  bonus; the planner reserves full effort for every assignee.
- Rankings and many medal thresholds use raw activity rather than fulfillment of
  each person's agreed plan. Capacity-normalized Insights is a separate view.

## Implemented Reward Mechanics

> Current implementation, WP3 (2026-09-27, ADR-014). Most configuration is
> instance-wide Nextcloud app config, not a household settings surface. The
> legacy economy columns/multipliers (tier coins, personal multiplier,
> early bonus, combo, streak freezes, teamwork bonus, boosts) were retired
> with the WP3 cutover — the rows below are the surviving mechanics.

| Mechanic | Behaviour | Config key (default) |
|----------|-----------|----------------------|
| **Coins (minor-unit wallet)** | Exact derivations of the frozen work evidence: `coinMinor = 10 × totalEnergy × sharePct` at the frozen `rate_minor` (1000 coin minor per energy). Booked through the immutable `wallet_entries` ledger (`(wallet, source-kind, source-id, source-revision)` idempotency identity); cached balance maintained transactionally, safe-integer envelope enforced. Corrections move value between wallets with exact union deltas — a completion pays out exactly the frozen total. | frozen `rate_minor` at completion |
| **Contribution** | `energyMinor = totalEnergy × sharePct` accumulated per wallet (`contributionMinor`); fairness signal. Corrections re-derive it from the valid history — never a cached increment/decrement. Not a ranking surface (see the consent-filtered ranking below). | None |
| **Work splits** | The completer records the actual participants (1–100 unique eligible members, whole percents summing to 100); the completer must take part. A later attribution corrects the split by completer/owner authority with the explicit review confirmation; departed participants' retained wallets are always part of the reversal. | None |
| **Weekly household goal** | Agreed due occurrences completed validly inside their window, counted by planned house-local week. Daily repeats count separately, late catch-up does not move credit into the completion week, recognized pauses are neutral, and undo removes the valid result. The first qualifying closed-week pass claims one durable household bell receipt; an open week never sends it. The house progress and the caller's personal slice (WP4) carry explicit `eligible`/`onTime`/`neutral` counts and the `provisional` flag. | None — **fixed 80% target** (WP4 retired the `weekly_goal_target_percent` override) |
| **Role titles** | Single retained series (WP4): Family Rhythm on the valid-history maximum, fixed 3/6/12 rungs, no pack selection. | `titles_enabled` (true) |
| **Streaks (planned-day rhythm)** | `currentStreak`/`longestStreak` recompute from the valid planned-day history after completion/undo/attribution and when a deadline expires: a day is fulfilled/pending-neutral/expired-miss per its actual participation and window; a correction shrinks the longest record only to the corrected valid-history maximum (C3g). Evaluations also expose the pending-hold indicator. | None |
| **Weekly ranking (WP4, ADR-015)** | Opt-in, consent-filtered comparison: one visible entry per effectively-consented member with the on-time ratio of that member's public agreed occurrences — exact-rational ties, neutral zero-denominators, provisional week, pending consents unranked. Consent activates the NEXT Monday (never retroactive), withdrawal is immediate, consent belongs to the active membership, and the owner-only `rankingEnabled` flag defaults off. The filter lives server-side; no Coins, speed, contribution or private-task data is exposed. | None — flag lives on the house row |

**WP3 closure notes:** the early-bonus/midnight, snapshot-reversal and
daily-count weekly-goal mismatches listed before 2026-09-27 are closed by the
ADR-014 model (frozen rates/facts, valid-history re-derivation, due∪completed
evaluation). Corrections, authority and audit are specified in
[task-model-and-sync.md](task-model-and-sync.md) and the
[clarification plan](../plans/done/cs-plan-070-family-rhythm-and-reward-clarification.md).

### Weekly Medals

> **Award reality (WP3, ADR-014):** the old activity/combo medal catalog
> (`team_anchor`, `flow_keeper`, `room_guardian`, `team_player`) retired with
> the wallet cutover — no wallet-qualified or raw-activity medals remain, and
> combo aggregation is gone with the combo mechanic. `MedalService` now
> carries `rhythm_3`, `rhythm_6`, and `rhythm_12` from valid personal
> planned-day history, plus `weekly_milestone` for members who had joined by
> the qualifying planned week's end. Neither mints Coins. Corrections hide
> unsupported awards, and `awardMedalIfNew()` remains race-safe and
> idempotent per user/type/week without a second celebration.

## Role Title System (Single Series, since WP4)

> **Status: Retained single series (CS-PLAN-070 WP4, Q-075-02 A).** The
> multi-pack selection surface (picker, `PUT /users/{id}/titles/select`,
> `selected_title_pack`) retired 2026-09-27: titles are derived identity,
> not a preference. The section below is the historical pack design.

One retained series is live as a progression overlay on top of the existing
stats. Titles react to existing progression signals only — no new core loop,
currency, or completion path:

| Series | Signal | Fixed tier thresholds |
|--------|--------|-----------------------|
| Family Rhythm | `longestStreak` (valid-history maximum) | 3 / 6 / 12 |

- Server-authoritative: the client only displays what the server returns.
  `currentTitle` is always the highest qualifying tier of the single series
  (an ordinary later streak break never strips an earned title — D7c).
- `unlocked_titles` is the first-earned celebration marker (C3g no-repeat),
  reconciled against the corrected valid history; recomputable from stats.
- Thresholds are fixed policy — the `title_threshold_{pack}_{tier}` config
  keys retired with WP4 (fixed 10/80/3-6-12 first slice).
- Unlock moments reuse the celebration layer (`chorestrike:title-unlocked`
  event → confetti + milestone SFX) and fire a best-effort Nextcloud
  notification. The whole subsystem can be disabled via the
  `titles_enabled` config flag.

<details><summary>Historical: the retired multi-pack catalog</summary>

The effort-driven Home Helpers (`contributionMinor` 50/250/1000) and
streak-driven Team Energy (`longestStreak` 7/30/90) packs, and the earlier
coins-driven Cozy Home pack, retired with the WP3/WP4 cutovers (C3d: no
separate effort/wallet title series). Their l10n label keys were removed
with the catalog.

</details>

### Arcade (play-only since WP3, CS-PLAN-068 → ADR-014)

The minigames are play-only: the boost shop, the boost purchase flow and the
arcade coin earnings were retired with the WP3 economy cutover (no shared
wallet with chores, no redemption surface). Score saves stay capped: per-game
ceilings and a play-time plausibility rule in `ArcadeCatalog` (CS-PLAN-086)
plus `DAILY_SCORE_SAVE_LIMIT` per member and house-local day; leaderboards
remain house-scoped and per game. The 2048/Memory
prototypes were retired in the same cutover; CS-PLAN-086 grows the arcade
into a household-themed game collection on a shared game shell: Spot Sprint
(`spots`, wipe stains in 60-second rounds, always open) and Crumb Snake (the
`snake` id).
See the Minigames section in
[`../api/README.md`](../api/README.md).

Games unlock as **permanent milestones of finished chores**
([ADR-019](../adr/ADR-019-arcade-unlocks-from-completion-history.md),
amending D7d): a member's count is the number of valid completions in whose
selected allocation revision they hold a share, so helpers count and undone
or revised-away work does not. The lock state is derived on every read and
never stored; an undo can re-lock a game. There is no daily coupling, and
the arcade still never mints, spends or shows Coins, wallet values,
contribution or ranking. Owners can switch the gate off for the household
(`arcade_gate_enabled`), which opens every game for everyone.

The chore-ranking comparison between members is NOT the arcade leaderboard —
it is the consent-filtered weekly ranking described above
([ADR-015](../adr/ADR-015-ranking-consent-and-privacy.md)); no unconditional
contribution leaderboard exists.

## Streaks and Rewards

Streaks are important motivation mechanics.

### Current Personal Streak Rule

- Completion updates a member's activity streak; gaps are evaluated against
  weekday availability, not assigned chores, due windows or on-time completion.
- Unavailable gap days are excluded. An available day without a due chore is
  still treated as missed activity and can consume a freeze or reset a streak.
- Freeze processing and the later completion path have inconsistent protection
  bookkeeping (existing follow-up CR-053-11). This is not a fully reliable
  occurrence-based safety net.
- Medals for four active days or a seven-day streak also favor frequent activity;
  they do not yet reflect a twice-weekly cleaning agreement.

### Earlier Streak Milestone Ideas (Not Implemented)

- Milestones can grant playful one-time tools.
- Example: after an X-day streak, the member receives one "Task Pass".

### Task Pass (Earlier, Unapproved Idea)

- User can move one assigned task to another available member.
- Receiver gets a reward boost for that task completion (default: 2x coins).
- System should cap abuse with configurable limits (for example weekly usage cap).

## Gamification Flexibility

Gamification should stay intentionally open for future ideas.

- Keep core loop stable (tasks, completion, positive reward).
- Treat reward formulas and event rules as configurable policy, not hardcoded constants.
- Allow feature flags for experimental mechanics.
- Document each new mechanic with fairness and tone checks.

## Constraints

- No punishment-only loops.
- No hard lose state.
- Keep UI text neutral and inclusive.

## Reward Options for a Family-First App

**Decision (2026-09-17; implemented by WP3-WP8):** the user selected recognition
and shared milestones by default, plus an optional family reward catalog. The
comparison below retains the alternatives; cosmetics were not selected. Keep the
loop small rather than adding currencies, multiplier tiers or protection tokens.

| Option | Example | Strength | Trade-off |
|---|---|---|---|
| A. Recognition and shared milestones | "Six planned sessions completed"; a family chooses a board-game evening after a realistic shared goal. No coin spending is required. | Useful without turning each necessary chore into a transaction; smallest conceptual change. | Some children/adults want a more tangible choice. |
| B. Optional family reward catalog | Save chore-earned Coins to choose the next movie, a shared baking activity or an agreed small privilege. | Gives individual agency and a clear reason to save. | Needs an affordable family-agreed catalog, request/approval/cancellation rules and reliable accounting. |
| C. Optional cosmetic collection | Unlock avatar accessories or decorate a shared virtual home. | No parent has to deliver a real-world promise. | New artwork/UI and collection rules; can distract from planning. Not implemented. |

### Approved Reward Direction (2026-09-17; implemented by WP3-WP8)

- A is the default; B is a household opt-in after the accounting and rhythm
  contracts are safe. Planning works without a reward catalog.
- Family Coins reward actual task effort, including catch-up work. Remove tier,
  personal, speed, early and on-time multipliers from this target model. Streaks
  and recognition acknowledge punctuality separately; duplicate booking earns
  nothing. C3 below specifies a fixed rate of 10 and whole-percent effort shares.
- Optional minigames use their own highscores, never earning or spending family
  Coins. The first slice supports Snake only. The former coin boost shop is not
  part of the household economy and was retired with the WP3 economy cutover.
  Other game prototypes are not a promise of support.
- Task energy is a total effort budget, split visibly between actual participants
  rather than credited in full to each name. The old assignment-count teamwork
  bonus is not part of this earning model; C3 specifies conserved exact allocations.
- Earned titles/awards remain after ordinary spending or a later streak break.
  A genuine incorrect completion may still be corrected by undo, under the
  separately defined reversal rules. A wallet balance is not an earned title.
- The approved redemption lifecycle and role boundaries are described below and
  implemented by WP7-WP8 ([ADR-017](../adr/ADR-017-family-catalog-and-redemption.md)).
  Do not sell streak repairs or task exemptions.

### Approved Accounting Contract C3 (2026-09-17)

Implemented by WP3 (CS-PLAN-074,
[ADR-014](../adr/ADR-014-work-accounting-ledger-and-replay.md)); reservations and
the funding hold by WP7 ([ADR-017](../adr/ADR-017-family-catalog-and-redemption.md)).

- Fixed rate: 10 Coins per energy unit, hence 10-50 total Coins for energy 1-5.
  No personal/household earning multiplier. Families configure catalog prices,
  not the earning formula. Actual allocation follows the
  [effort-share contract](energy-and-balancing.md#approved-effort-allocation-c3-2026-09-17).
- Replace the old activity/combo medals and effort/wallet title series in the
  first slice with retained personal rhythm awards at 3/6/12 consecutive
  successful planned task days and the 80% household weekly milestone. No extra
  Coins. The earned rhythm tier can be a personal title; friendly localized
  names/presentation belong to C5. No daily-activity threshold is implied.
- Ordinary spending and later genuine streak breaks preserve earned recognition.
  After a genuine correction, revoke a title/award only if no valid historical
  run ever reached its threshold. Correct false personal records and false weekly
  milestones as well. Retain recognition supported by another valid earlier/later
  run and suppress duplicate rewards/celebrations from undo/redo loops.
- Booked balance B is separate from active reservations R; available balance is
  A = B - R and may be negative after correction. Reserving changes R, not B.
  Fulfillment debits B and releases the corresponding R once; rejection/cancellation
  releases R without crediting B. Price remains fixed for each request.
- If aggregate reservations are underfunded, retain all requests/reservations but
  block new requests/redemptions and settlement of all still-unfulfilled rewards.
  Example: B=40, R=60 -> A=-20. Earning 20 restores coverage (B=60, A=0), allowing
  existing covered reservations to settle, but not a new positive-cost request.
  Authorized cancellation may also restore coverage. Do not automatically cancel
  requests or reclaim delivered rewards. Fulfillment/cancellation actors remain C4.
- [Targeted undo and attribution correction](task-model-and-sync.md#approved-correction-contract-c3-2026-09-17)
  preserve independent later spending and earnings; the former snapshot restore is
  retired. Storage and calculations use exact minor units, never floating-point
  wallet arithmetic (ADR-014).

### Approved Rhythm Core (2026-09-17; implemented by WP1-WP4)

- The user chose one personal streak of planned task days. A day earns one step
  only when all agreed tasks for that day are completed within their respective
  windows. Individual tasks do not get separate visible streak counters.
- Chores on Tuesday and Saturday produce two possible steps a week. A day with
  no agreed tasks is neutral, even when availability is enabled. Extra chores do
  not create additional planned-day steps or repair a missed obligation.
- An unexcused missed window ends the current run; the next successful planned
  day starts a new run. Personal best, earned awards and Coins remain intact.
  No threatening reminders or additional penalty accompany the reset.
- Recognized dated illness/absence pauses are neutral, with no fake completion.
  Adults manage their own pauses; children request owner approval. Owners manage
  household pauses and may explicitly recognize retrospective illness with a
  traceable decision. Freezes/jokers are removed (WP4, ADR-015).
- Personal credit requires actual participation. A pre-deadline agreed handover
  releases the previous responsible person neutrally; another person's unplanned
  completion alone does not automatically fulfill that person's streak obligation.
- The shared weekly goal counts agreed due occurrences completed within their
  windows. A daily task supplies seven weekly opportunities; late catch-up is
  shown separately rather than retrospectively counted as on time.
- The household milestone is 80%, not an all-or-nothing goal. Open/missed work
  remains visible; reaching 80% neither excuses the remainder nor changes the
  personal all-agreed-work streak rule.

### Approved Timeline Evaluation C1 (2026-09-17)

Target rules, not current behavior. The
[task timeline contract](task-model-and-sync.md#approved-timeline-contract-c1-2026-09-17)
defines calendar anchors and which occurrence a completion fulfills.

- Weeks run Monday-Sunday. Attribute each occurrence to its planned-date week,
  not the week of its completion or deadline. A Saturday task with a window
  through Monday can still improve the previous week when completed on Monday.
  Mark a week's result provisional while its associated windows remain open.
- Confirm personal streak steps in planned-day order, no earlier than the
  corresponding planned date. Show early completion as completed work immediately,
  not as an already earned future-day streak step.
- All agreed work completed on time makes the planned day successful; any
  unexcused expired obligation makes it missed; otherwise it is pending.
  Existing approved neutral-day and actual-participation rules still apply.
- A later successful day waits behind an earlier pending day for streak credit,
  while its tasks remain visibly completed. If Tuesday is pending and Thursday
  succeeds, resolve Tuesday first: success permits ordered confirmation; a miss
  ends the old run and Thursday starts the new run at one.
- Clearing a stale catch-up card through current work does not grant another
  success, on-time occurrence or reward for the historical miss.

### Approved Goal Amendments C2 (2026-09-17)

Target rules only. Apply the
[agreement/pause contract](task-model-and-sync.md#approved-agreement-contract-c2-2026-09-17)
and retain C1 chronological evaluation.

- Neutrally suspended occurrences leave the weekly denominator but remain visible
  as exceptions, not successes: ten opportunities with two suspended become
  eight. An undecided child pause request alone changes neither denominator nor
  personal obligation. Correctly completed work remains intact.
- A valid handover changes responsibility, not the number of opportunities: the
  occurrence still counts once. The former responsible person gains neutral
  release, not success; actual participation remains required for personal credit.
- A timely agreed amendment moves the same unfulfilled occurrence to the week of
  its new planned date without creating another occurrence. Preserve old/new state
  and visible exceptions; historical misses stay missed unless an owner explicitly
  recognizes retrospective illness under the approved exception policy.
- Zero opportunities is a neutral week, not a manufactured 100% success. Excusal
  creates neither completion nor Coins. The 80% milestone does not change personal
  all-agreed-work rules.
- Recorded real overload earns normal effort credit, not additional unplanned-day
  streak steps or repair of missed obligations. Planning limits remain distinct
  from the accurate recording of work already done.

### Remaining Rhythm Details

- C2 amendments/pause rules and C3 correction policy are integrated with C1
  chronological evaluation (WP1-WP4, ADR-013/ADR-015); late replanning cannot
  erase a miss.
- C3 selects 3/6/12 planned-day rhythm thresholds, not 7/30/365 calendar days.
  C5 fixes the quiet presentation policy; the localized title strings are
  implemented, not another earning formula.
- Prefer shared progress relative to agreed work over raw child/adult rankings.
  Occurrence counting, the 80% target, C1 cross-week attribution, C2 denominator
  amendments and the C3 correction re-derivation are implemented (WP3-WP4).

### Approved Family Catalog Rules (2026-09-17; backend implemented since WP7)

- Families choose attainable, non-essential treats; affection, meals, rest and
  ordinary participation in family life are not conditional rewards.
- Use a simple, explainable chore earning rule. Calibrate prices against a
  realistic child's own plan, not an adult's output or arcade income; no price
  promises are locked here.
- Chore completion remains immediate. Child-role members request rewards with
  separate owner approval; adult members may redeem directly. Owners administer
  the catalog and prices. Existing owner/member/child roles are not a new
  guardian-consent model.
- Reserve Coins at request, settle once on confirmed fulfillment, and release
  on rejection/cancellation. Catalog price changes do not reprice an existing
  request. C4 below specifies actors/states; duplicate/retry behavior follows the
  shared receipt/version contract (ADR-017).
- Undo corrects only the mistaken credit, preserving independent later events.
  If rewards were already spent, the balance may become negative and later work
  repays it; block further redemptions until covered. Delivered rewards are not
  reclaimed. Missing a chore does not trigger this correction mechanism.
- C3 specifies the funding hold after correction; C4 specifies the catalog lifecycle
  below. C5 specifies quiet UI/delivery. Onboarding/pricing help, localized copy,
  persistence and race handling shipped with WP7-WP8.

### Approved Catalog Lifecycle C4 (2026-09-17)

Implemented by WP7 (backend, ADR-017) and WP8 (UI). The states below are domain
descriptions; the API identifiers live in the [rewards API](../api/rewards.md).
An underfunding hold is a separate condition, not a replacement for approval state.
Here, an open/unfulfilled request means pending approval or approved, not already
rejected, cancelled or corrected. Those terminal records cannot be reopened or
release/refund funds again; fulfilled records only allow the specific false-
fulfillment correction below.

| Action | Authorized actor / condition | State and accounting effect |
|---|---|---|
| Request | Current member for self, active catalog/item, positive price, sufficient available Coins | Child request awaits owner approval; adult request is approved without a separate approval step. Both reserve the fixed price once; neither is fulfilled yet. |
| Approve | Current owner for a child's pending request; a newly adult requester may explicitly self-approve an existing own pending request | Approved, reservation unchanged, no debit. Approval does not clear an underfunding hold. |
| Fulfill | For a child: current owner after owner approval. For an adult: requester or current owner. Actual delivery and C3 aggregate coverage required | Fulfilled; debit booked balance and release the reservation once, atomically. |
| Reject | Current owner, pending child request | Rejected; release reservation once, no booked-balance credit. |
| Cancel | Requester, including a child, or current owner; any unfulfilled request, including approved/underfunded | Cancelled; release once, no booked-balance credit. No further consent required. |
| Correct false fulfillment | Current owner, documented reason, reward was never delivered | Refund exactly the original debit once; corrected terminal record, no new reservation. A later desired reward requires a new request. |

- No automatic expiry through elapsed time or underfunding. Genuine delivered
  rewards are not ordinary cancellations/refunds and are never reclaimed.
- Owners set positive prices with at most two decimal places (e.g. 25 or 12.50).
  Zero/negative prices, discounts and dynamic price formulas are outside the first
  slice. Snapshot the agreed content and price at request, not only the catalog ID.
- Archiving an item or disabling the catalog prevents new requests. Existing
  requests retain agreed content/price and remain accessible to authorized actors
  for processing outside the hidden catalog view. Changes affect new requests only;
  impossible promises need explicit cancellation. Re-enabling revives no cancelled
  requests.
- Ordinary departure/removal atomically cancels every unfulfilled request and
  releases reservations. Preserve fulfilled history and positive/negative booked
  balance for that account in that household, but remove household access. On
  rejoin with the same account, restore the balance, not cancelled requests.
  No cross-house transfer and no escaping correction debt by leaving.
- This financial identity must not itself grant active membership. Existing
  owner-removal/last-owner guards remain intact. The departure rule is not an
  account-deletion/privacy-erasure contract or indefinite-retention approval;
  those boundaries require explicit technical integration before implementation.
- Check current roles on every action. A change to child requires fresh owner
  approval for all still-unfulfilled requests. A change to adult permits explicit
  self-approval of own pending requests, not automatic redemption. Owner demotion
  removes owner privileges immediately; preserve prices, reservations and completed
  history.
- Requesters see their own requests; current owners can administer household
  requests. Other members see the active catalog, not foreign wishes, request
  history or reserved amounts. Do not automatically publish these to shared
  activity; notifications follow the same rights. Ranking consent and private-task
  permissions are separate, not implicit disclosure permissions.
- Correction of false fulfillment is owner-only and traceable. Never refund the
  current catalog price instead of the original debit, restore a reservation or
  double-refund on retry. Funding recovery then follows C3 arithmetic.

The implementation manifest must serialize fulfillment/cancellation/departure,
role changes, refunds and wallet corrections under live authorization and state
checks. Request terms remain immutable while commands are versioned/idempotent.
The current `HouseService::removeHouseMember` deletes the wallet-bearing membership
row; do not mistake that for the approved retained-financial-identity model.
Exact storage, API, erasure integration and lock order remain unimplemented and
require review; no new external identity service is implied.

> **Implemented (CS-PLAN-070 WP7, 2026-10-01 — [ADR-017](../adr/ADR-017-family-catalog-and-redemption.md)).**
> The C4 lifecycle is live behind the owner-controlled household gate:
> tables `chorestrike_reward_items` / `chorestrike_reward_requests` /
> `chorestrike_request_events` (+ `house_users.role_revision`, migration
> Version1033), services `RewardService` (catalog) and `RewardRequestService`
> (lifecycle), API contract [rewards.md](../api/rewards.md). Reserved Coins
> live in `wallets.reserved_minor`; settlement/refund are
> `reward_settlement`/`reward_refund` ledger entries keyed by request id +
> effect revision. Identity is wallet-based (no raw UID/display-name
> snapshots); the funding hold is derived (`booked < reserved`), never
> stored. Departure/erasure integration follows the rules above (erasure
> purges requests/events/wishes and queued digest references, anonymizes
> actor references elsewhere). The reward UI (WP8) follows below
> (delivered 2026-10-02).

> **UI implemented (CS-PLAN-070 WP8 / [CS-PLAN-079](../plans/done/cs-plan-079-wp8-reward-ui-and-onboarding.md),
> 2026-10-02).** The reward surfaces consume the WP7 API through one thin
> Pinia client (`src/store/rewards.js` — pages, detail and command
> transport, never accounting): `/rewards` catalog browsing behind BOTH
> independent gates (household flag + personal `catalogVisible`,
> fail-closed while the preference read is pending); the persistent
> `/reward-requests` list/detail (own and owner-household scopes, state
> filter, funding-hold badges, former-member placeholders); every C4
> action as an explicit attestation form (delivery confirmation, reasoned
> reject/cancel, owner-only false-fulfillment correction referencing the
> server's original settlement entry); and the owner editor (gate toggle
> plus item create/edit/archive with the strict `parseCoinMinor`
> hundredths parser) in the Settings House section. Uncertain
> transport/5xx outcomes retain the command UUID for an explicit
> same-key replay; a 409 re-reads the authorized sources and keeps the
> caller's draft. Essential contextual help (accounts/roles, agreements
> and shares, progress/rhythm, catalog gates, owner pricing) rides the
> shared help modal. Browser evidence: `tests/e2e/family-rewards.spec.ts`
> (nine required scenarios, T11).

## Open Decisions

The [clarification plan](../plans/done/cs-plan-070-family-rhythm-and-reward-clarification.md)
records the approved core/C1-C5 product choices; WP0-WP8 implement them
(ADR-013..017). The family trial evidence remains open in
[CS-PLAN-080](../plans/active/cs-plan-080-wp9-cross-db-and-family-evidence.md).
The [C5 UI contract](ui-use-cases.md#approved-calm-ux-and-evidence-c5-2026-09-17)
specifies quiet feedback, prospective consent-based weekly plan-fulfillment
rankings and the future family trial. The selected order is reliable core first, then the
optional family catalog. Task Pass, cosmetics, external-task sync, local child
profiles and general chore-approval workflows are explicitly outside this slice;
existing backlog owners retain those future options.

WP5 keeps medal/title/weekly-goal recognition quietly in-app; no award bell is
sent. The weekly receipt remains the once-only celebration marker, not a
delivery claim. Only self-opted-in task/organizational summaries use the
[bounded digest](notifications.md), never streak threats or activity pressure.
