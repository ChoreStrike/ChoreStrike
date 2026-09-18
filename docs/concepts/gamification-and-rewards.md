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
- Use **Coins** as the reward currency (shipped). Tasks carry a tier
  (Bronze/Silver/Gold) multiplier and each member has a personal coin multiplier
  for balancing. Most global defaults are read through `RewardConfigService`;
  member balancing uses the persisted member multiplier. The former
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

> Current implementation, checked 2026-09-16. Most configuration is instance-wide
> Nextcloud app config, not a household settings surface. The tier constants are
> an exception to the intended config-driven policy.

| Mechanic | Behaviour | Config key (default) |
|----------|-----------|----------------------|
| **Coins** (renewable currency) | `energy_cost × coin_base_per_energy × tier_multiplier × personal_multiplier × early_multiplier`; **×combo_multiplier on personal combo** (`GameLogicService::calculateCoins`). Tier values come from `Task::getTaskTierMultiplier`, not the stored `tier_*` keys. | `coin_base_per_energy` (10), fixed tiers (1.0/1.5/2.0), `early_bonus_multiplier` (1.5), `early_bonus_threshold_days` (21), `combo_multiplier` (2.0) |
| **Contribution score** | Credits the task's `energy_cost`; no passive penalty. Undo restores the recorded before-state, not just a delta; see the cross-event limitation below. Leaderboard and `home_helpers` title signal. | None |
| **Personal combo** | Rapid consecutive completions by the same user double coins (configurable multiplier). *Not* the cooperative ×1.5 multi-user concept from the foundational design — see Decision Point B (CS-PLAN-010). | `combo_multiplier` (2.0) |
| **Streak freezes** | Protect a streak on a missed available day; consumed by completion and `StreakFreezeJob`; restocked weekly by `StreakRestockJob`. Job bodies live in `StreakFreezeService` / `StreakRestockService` (thin job delegates; triggerable with an injected date via `occ chorestrike:trigger-jobs`). | `streak_freezes_per_week` (1), `streak_freeze_cap` (2) |
| **Combo bonus freeze** | A combo reaching the threshold awards one bonus freeze (respecting the cap). | `combo_bonus_freeze_threshold` (5) |
| **Teamwork bonus** | Multi-assignee task completion grants the completer an additional bonus; it is not credited to every assignee. Configuration is instance-wide. | `teamwork_bonus_enabled` (true), `teamwork_bonus_max_pct` (50) |
| **Weekly household goal** | Distinct completed room-task IDs divided by the union of currently due and completed room-task IDs that week; not completed due occurrences or an on-time rate. First achievement sends a notification. | `weekly_goal_target_percent` (80) |
| **Role titles** | Progression overlay (3 packs × 3 tiers); thresholds come from the server config/catalog. | `titles_enabled` (true), `title_threshold_{pack}_{tier}` (catalog defaults) |

**Known mismatches requiring follow-up:** the early-bonus calculation sees the
already-advanced next due date, not the completed occurrence's original due date.
Undo restores old shared wallet/contribution values and can overwrite independent
later changes. A daily task counts only once in the weekly goal, so completing it
once can show 100% although further occurrences remain. These are not evidence of
reliable on-time rewards; the [clarification plan](../plans/new/cs-plan-070-family-rhythm-and-reward-clarification.md)
tracks their contracts and required regression cases.

### Weekly Medals

> **Award reality (CS-PLAN-012, updated by CS-PLAN-067 WP8):** the medal enum
> (`lib/Db/Medal.php`) catalogues `team_anchor`, `flow_keeper`, `room_guardian`,
> `team_player`, `streak_keeper`. **All five are awarded** in the live
> `MedalService::checkAndAwardMedals()` flow via `awardMedalIfNew()`
> (idempotent, race-safe, notifies once per user+type+week — the cluster was
> extracted out of `GameLogicService` in WP8):
> - `team_anchor` — 10+ task completions in the week
> - `streak_keeper` — 7+ day streak
> - `flow_keeper` — 4+ distinct active days in the week
> - `room_guardian` — 5+ completions in a single room (concentration)
> - `team_player` — 3+ combo bonuses in the week (`comboCount` aggregation)
>
> Integration coverage: `GameControllerIntegrationTest` pins the award from
> real persisted completions plus the repeat-read idempotence.

## Role Title Systems (Localized)

> **Status: Shipped (CS-PLAN-006).** Three title packs are live as a
> progression-overlay on top of the existing stats. Titles react to existing
> progression signals only — no new core loop, currency, or completion path.

### Arcade Coin Sink (shipped, CS-PLAN-068 §3.6 documentation)

Coins are also spent on Snake boosts in the household arcade. The retained
2048/Memory component prototypes do not expand the server's `snake` score
whitelist. Prices live **server-side only** (`MinigameService::BOOST_COSTS`
via `GET /minigames/boosts`); the shop disables purchases without the
authoritative catalog instead of guessing, the debit is an atomic
`POST /minigames/boosts/{id}/purchase` with the exact catalog price, and
score saves are capped (`MinigameService::MAX_SCORE` and
`DAILY_SCORE_SAVE_LIMIT`, rather than a household-defined reward budget, per member and
house-local day). The awarded coins for a game are server-computed
(`floor(score / 10)`), never client-supplied. See the Minigames section in
[`../api/README.md`](../api/README.md).

Arcade earnings enter the same coin wallet as chores. Boosts are currently the
only purchase flow; a family reward catalog, redemption requests and fulfillment
do not exist. The approved target separates arcade from family Coins; the current
shared economy must change before real-world redemption. Playable 2048/Memory
prototypes do not have supported score saves.

Role names must feel friendly and motivating.
Use localized title packs so each household can choose a style.
Each pack includes 3 progressive titles (low/medium/high contribution).

MVP scope: provide 3 built-in family-friendly packs.

Implementation (`lib/Titles/TitleCatalog.php` + `lib/Service/TitleService.php`):

| Pack | Signal | Tier thresholds (defaults) |
|------|--------|----------------------------|
| Home Helpers | `contributionScore` | 50 / 250 / 1000 |
| Team Energy | `longestStreak` | 7 / 30 / 90 |
| Cozy Home | `coins` | 500 / 2500 / 10000 |

- Server-authoritative unlocks: the client only displays what the server returns.
- Each pack's signal is an **actively-maintained** stat: `contributionScore`
  and `coins` are incremented on every task completion, and `longestStreak`
  is tracked from the effective (post-freeze) streak in
  `GameLogicService::handleTaskCompletion`.
- Thresholds use `RewardConfigService` keys (`title_threshold_{pack}_{tier}`).
  There is no live reward-config HTTP API; API consumers must not rely on
  the retired configuration routes.
- The user picks one active pack; within that pack the highest currently
  qualifying tier is shown, recomputed from live stats. Switching packs is free.
- **Recognition limitation:** Cozy Home reads the current spendable coin balance,
  not lifetime earnings or permanently retained unlocks. A member at 500 Coins
  qualifies for Cozy Buddy; spending 10 Coins drops the balance to 490 and removes
  that title on the next title computation. The `unlocked_titles` cache prevents
  repeated celebrations; it is not the authority for title visibility. Separating
  recognition from spending is an open reward-contract decision, not implemented.
- Unlock moments reuse the celebration layer (`chorestrike:title-unlocked`
  event → confetti + milestone SFX) and fire a best-effort Nextcloud
  notification. The whole subsystem can be disabled via the
  `titles_enabled` config flag.

### Pack A: Home Helpers (Neutral, Family-Friendly)

- EN: Helper, Organizer, Care Captain
- DE: Helfer, Organisator, Fürsorge-Captain

### Pack B: Team Energy (Cooperative, Positive)

- EN: Spark Starter, Flow Keeper, Team Anchor
- DE: Funkenstarter, Flow-Hüter, Team-Anker

### Pack C: Cozy Home (Warm, Inviting)

- EN: Cozy Buddy, Room Ranger, Home Guardian
- DE: Wohlfühl-Buddy, Raum-Ranger, Zuhause-Hüter

### Post-MVP Extension

- Additional built-in packs can be added after MVP.
- Custom title packs are planned for post-MVP once stable pack management APIs exist.
- All future packs must follow family-friendly, non-aggressive naming policy.

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

**Decision (2026-09-17; target, not implemented):** the user selected recognition
and shared milestones by default, plus an optional family reward catalog. The
comparison below retains the alternatives; cosmetics were not selected. Keep the
loop small rather than adding currencies, multiplier tiers or protection tokens.

| Option | Example | Strength | Trade-off |
|---|---|---|---|
| A. Recognition and shared milestones | "Six planned sessions completed"; a family chooses a board-game evening after a realistic shared goal. No coin spending is required. | Useful without turning each necessary chore into a transaction; smallest conceptual change. | Some children/adults want a more tangible choice. |
| B. Optional family reward catalog | Save chore-earned Coins to choose the next movie, a shared baking activity or an agreed small privilege. | Gives individual agency and a clear reason to save. | Needs an affordable family-agreed catalog, request/approval/cancellation rules and reliable accounting. |
| C. Optional cosmetic collection | Unlock avatar accessories or decorate a shared virtual home. | No parent has to deliver a real-world promise. | New artwork/UI and collection rules; can distract from planning. Not implemented. |

### Approved Reward Direction (2026-09-17; Not Yet Implemented)

- A is the default; B is a household opt-in after the accounting and rhythm
  contracts are safe. Planning works without a reward catalog.
- Family Coins reward actual task effort, including catch-up work. Remove tier,
  personal, speed, early and on-time multipliers from this target model. Streaks
  and recognition acknowledge punctuality separately; duplicate booking earns
  nothing. C3 below specifies a fixed rate of 10 and whole-percent effort shares.
- Optional minigames use their own highscores, never earning or spending family
  Coins. The first slice supports Snake only. The existing coin boost shop is not
  part of the target household economy; its UI/game disposition must be scoped
  before implementation. Other game prototypes are not a promise of support.
- Task energy is a total effort budget, split visibly between actual participants
  rather than credited in full to each name. The old assignment-count teamwork
  bonus is not part of this earning model; C3 specifies conserved exact allocations.
- Earned titles/awards remain after ordinary spending or a later streak break.
  A genuine incorrect completion may still be corrected by undo, under the
  separately defined reversal rules. A wallet balance is not an earned title.
- The approved redemption lifecycle and role boundaries are described below;
  implementation details and acceptance tests remain open. Do not sell streak
  repairs or task exemptions.

### Approved Accounting Contract C3 (2026-09-17)

Target behavior only; the current formulas, integer wallets, catalogs and snapshot
undo remain unchanged until implementation.

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
  preserve independent later spending and earnings, unlike the current snapshot
  implementation. Store/calculations must use exact units, not floating-point
  wallet arithmetic; schema/transactions and concrete tests remain to be specified.

### Approved Rhythm Core (2026-09-17; Not Yet Implemented)

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
  traceable decision. The target removes freezes/jokers; existing code is unchanged.
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

- C2 amendments/pause rules and C3 correction policy must be integrated with C1
  chronological evaluation in the exact technical contract; late replanning
  cannot erase a miss.
- C3 selects 3/6/12 planned-day rhythm thresholds, not 7/30/365 calendar days.
  C5 fixes the quiet presentation policy; final friendly localized strings belong
  to the implementation manifest, not another earning formula.
- Prefer shared progress relative to agreed work over raw child/adult rankings.
  Occurrence counting, the 80% target, C1 cross-week attribution and C2 denominator
  amendments are approved; C3 correction policy still needs exact persistence and
  concurrency mapping alongside the approved C5 UI contract.

### Approved Family Catalog Rules (2026-09-17; Not Yet Implemented)

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
  request. C4 below specifies actors/states; map duplicate/retry behavior to the
  exact technical contract before implementing the API.
- Undo corrects only the mistaken credit, preserving independent later events.
  If rewards were already spent, the balance may become negative and later work
  repays it; block further redemptions until covered. Delivered rewards are not
  reclaimed. Missing a chore does not trigger this correction mechanism.
- C3 specifies the funding hold after correction; C4 specifies the catalog lifecycle
  below. C5 specifies quiet UI/delivery. Concrete onboarding/pricing guidance,
  localized copy, persistence and race handling remain part of the technical manifest.

### Approved Catalog Lifecycle C4 (2026-09-17)

Target behavior only; no family catalog or redemption API is implemented yet.
The states below are domain descriptions, not finalized schema/API identifiers.
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

## Open Decisions

The [clarification plan](../plans/new/cs-plan-070-family-rhythm-and-reward-clarification.md)
records the approved core/C1-C5 product choices; exact technical mapping and
cross-contract integration remain open, not implicitly approved implementation.
The [C5 UI contract](ui-use-cases.md#approved-calm-ux-and-evidence-c5-2026-09-17)
specifies quiet feedback, prospective consent-based weekly plan-fulfillment
rankings and the future family trial. The selected order is reliable core first, then the
optional family catalog. Task Pass, cosmetics, external-task sync, local child
profiles and general chore-approval workflows are explicitly outside this slice;
existing backlog owners retain those future options.
