# CS-PLAN-070: Family Rhythm and Reward Clarification

> **Status:** Active; Standard A `PASS-WITH-FIXES` (2026-09-18); WP0 delivered via CS-PLAN-071 (2026-09-19); WP1 delivered via CS-PLAN-072 (final Standard B `PASS-WITH-FIXES` 2026-09-23, plan in `done/`; ADR-013 recorded; occurrence timeline + Q-072-01 Option A agreement-writer slice live); WP2 delivered via [CS-PLAN-073](../done/cs-plan-073-wp2-agreements-capacity-pauses.md) (final Standard B `PASS-WITH-FIXES` 2026-09-25, plan in `done/`; agreements/capacity/pauses slice live, RF-01..28 resolved, CR-SVC-64 closed; Q-073-01/02/03 remain resolved); WP3 delivered via [CS-PLAN-074](../done/cs-plan-074-wp3-work-accounting-and-replay.md) (final Standard B `PASS-WITH-FIXES` 2026-09-27, plan in `done/`; ADR-014 recorded; actual shares, exact wallet ledger, targeted correction, room journal/replay, Q-074-01A recognition closure and no-Coin arcade live, RF-074-WP3-01..10 resolved, CR-SVC-63 closed)
> **Effort:** XL implementation: 48-76 engineering days, approximately 58-95 with contingency, plus 14 calendar days of family observation; former M estimate covered clarification only
> **Priority:** P1
> **Depends on:** None remaining — Q-070-01/02 resolved 2026-09-18 and Standard A passed 2026-09-18 (see Review Outcome)
> **Related backlog items:** CONCEPT-001; existing follow-up owners CR-053-11, CR-SVC-63, CR-SVC-64, FEAT-UX-018, FEAT-UX-019, FEAT-UX-023, FEAT-UX-024, FEAT-UX-025, FEAT-UX-026
> **Review baseline:** `master` at `1946166618d591eba54bd42d1d338030389e994e`, 2026-09-16
> **Technical inventory:** Same runtime revision rechecked 2026-09-18; pre-existing documentation changes preserved
> **Delivered children:** WP0-WP7. Latest: [CS-PLAN-078](../done/cs-plan-078-wp7-catalog-and-request-backend.md), final Standard B `PASS-WITH-FIXES` 2026-10-01: the owner-gated family reward catalog and the complete C4 request lifecycle (reserve/approve/settle/refund with exactly-once money effects, WP7 under-lock receipt guard, role-rebase/departure/erasure/digest integration, ADR-017). WP5 [CS-PLAN-076](../done/cs-plan-076-wp5-preferences-and-digest-delivery.md) delivered 2026-09-30 (ADR-016). Parent remains active for WP8-WP9 and family evidence.
> **Next child:** [CS-PLAN-079 / WP8 - Reward UI and Onboarding](../active/cs-plan-079-wp8-reward-ui-and-onboarding.md), drafted 2026-10-02, activated 2026-10-02 (Standard A `PASS-WITH-FIXES`); implementation not started.

## 1. Problem Summary

The requested product is a free, family-friendly chore planner with motivation:
keep agreed days or a self-chosen rhythm, without an obligation to be active
every day or loss-framed reminders. The current app has a substantial working
foundation, but "everything except rewards is implemented" is too strong.

Planning exists; reliable fixed-day edge cases, obligation-based streaks, calm
notification controls and a coherent earning/spending contract still need work.
Green tests of the existing behavior do not demonstrate the desired product fit.

### Current-State Matrix

| Area | Verified foundation | Gap, contradiction or undecided boundary |
|---|---|---|
| Setup and membership | Wizard, templates, invites, owner/member/child roles | Children need Nextcloud accounts; wizard invites members, not local child profiles. |
| Task execution | Atomic creation, checklists, My Day, completion and undo ledger | Undo can restore shared totals over independent later events; not a general isolated reversal. |
| Recurrence | Static weekdays, completion-relative intervals, flex windows | Early/late completion can repeat/skip fixed occurrences; availability does not constrain all recurrence advances. |
| Household planning | Owner preview/adjust/apply, seven-day horizon, capacity accounting | Date overrides and shared placements do not consistently enforce their documented constraints. |
| Child workload | Child landing page, daily filter and owner-set planning limit | Overdue/unassigned work can exceed the implied personal commitment; no dated pause workflow. |
| Streaks | Available-day activity, freezes, weekly restock | No-obligation days can break a streak; freeze/job/completion interaction remains open under CR-053-11. |
| Shared progress | Weekly goal, household health, capacity-normalized Insights | Goal counts distinct task IDs, not due occurrences; rankings/medals still favor raw activity. |
| Rewards | Coins, contribution, tiers, combos, titles, medals, Snake boosts | Early bonus sees the advanced occurrence; arcade earns the same coins; spending can remove Cozy Home titles; no family redemption workflow. |
| Calm UX | Completion feedback and Nextcloud notifications | Individual reminders, no digest/settings/quiet hours; rankings and metrics are not opt-in. |
| External clients | App runs in Nextcloud | Tasks/CalDAV synchronization removed; not a shipped mobile-client completion path. |

Canonical context: [vision](../../concepts/product-vision.md),
[task model](../../concepts/task-model-and-sync.md),
[balancing](../../concepts/energy-and-balancing.md),
[current rewards and options](../../concepts/gamification-and-rewards.md).

## 2. Strategy

Preserve the existing planner and execution surfaces. First define the agreement
with the family, then make recurrence/history/metrics honor it, then decide how
little reward machinery is needed. Do not rebuild the app or add a shop first.

The user approved 24 core product choices, six C1 timeline rules, eleven C2
agreement rules, nine C3 accounting rules, eight C4 reward-lifecycle rules and
thirteen C5 delivery/UX rules on 2026-09-17, recorded separately below.
Unasked details and existing code do not count as consent. The 2026-09-18 technical
draft below specifies the implementation scope; its two newly exposed retention
choices were resolved on 2026-09-18 (section 9) before the Standard A review
passed. The planning sessions themselves did not authorize code changes, plan
activation or a commit; the separate review round did.

### Decision Register

The decision logs are authoritative for approved choices. Earlier logs retain
their round's dependency context; the remaining-contract table reflects the
current technical integration work after the C1-C5 answers.

| Package | Approved direction | Implementation / review focus |
|---|---|---|
| D1 / P1 | Fixed cadence or completion-relative interval; per-task window; no retrospective history rewriting. | WP1-2; validate saved rule/occurrence/time-basis design and T1/T2. |
| D2 / P1 | Isolated correction preserving other events; negative balance allowed for genuine corrections after spending. | WP0/3; validate additive accounting, room replay and T4; Q-070-01/02 retention gates. |
| D3 / P1 | One personal planned-day streak; all agreed work in its windows; no-obligation days neutral. | WP4; chronological confirmation/recomputation and T5. |
| D4 / P1 | Recognized dated pauses, no freezes, one visible catch-up item; controlled retroactive illness. | WP2/4/6; pause lifecycle and freeze retirement, T2/T9. |
| D5 / P1 | On-time agreed occurrences; 80% weekly milestone, without pretending the remainder is done. | WP4; planned-week denominator/neutrality/provisional results, T5. |
| D6 / P1 | Responsible person plus helpers; explicit joint work; split total effort rather than multiply it. | WP2-3/6; exact share/capacity conservation and attribution UI, T3/T9. |
| D7 / P1 | Recognition plus opt-in family catalog; effort-only Coins; retained awards; separate arcade. | WP3-4/6-8; fixed rate/retained recognition and no-coin Snake, T5/T6/T10/T11. |
| D8 / P1 | Child request/owner approval; adults direct; reserve then settle at fulfillment; locked request price. | WP7-8; C4 actor/state matrix, underfunding/race/privacy tests T6/T11. |
| D9 / P1 | Off by default; optional bounded summary; no free-day/pause reminders or threats. | WP5; bounded at-most-once dispatch and queue/consent contract, T7. |
| D10 / P2 | Planning-first, quiet defaults, voluntary rankings; existing Nextcloud role/account model. | WP4-6; all-age My Day, self-only preferences and consent-filtered ranking, T8/T9. |
| D11 / P2 | Reliable core first, optional family catalog next; Snake-only arcade in this slice. | Gates G1-G3, WP9 family protocol and T12; no runtime/family evidence yet. |

### Decision Log (2026-09-17)

Approved through explicit user answers; these are target behavior, not current
implementation and not authorization to start runtime work.
The dependency column records the initial core-decision round; the subsequent
C1-C5 logs resolve their respective questions without changing these 24 answers.

| Decision | Approved rule | Remaining dependencies |
|---|---|---|
| D1a: recurrence modes | Each task can use a fixed calendar cadence or an interval measured from actual completion. Completing a fixed Saturday chore on Friday does not move future Saturdays; a seven-day interval moves to Friday. | Monthly/alternate-week boundaries, expired occurrences and overlapping windows require example-level specification. |
| D1b: on-time window | Default: the planned day through day-end. A task may have an explicitly agreed earlier/later window, such as Friday-Sunday for a Saturday chore. Completion within the window fulfills that occurrence once. | Exact early/late attribution, overlaps and house-local boundary examples; amendment principle approved in D1c. |
| D3 core: personal streak | One step per planned personal task day, only when all agreed tasks for that day are completed within their respective windows. Tuesday/Saturday provides two opportunities per week; no-obligation days are neutral. | Confirmation of joint participation, plan changes and when a multi-day window finalizes the day's result. |
| D4a: unexcused miss | When an agreed deadline/window is missed without a recognized exception, the current run ends; the next successful planned day begins a new run. Personal best, earned awards and Coins are retained. No threatening reminders or additional penalty. | Detailed recovery/history behavior; pause authority and retrospective illness principle approved in D4d. |
| D4b: pauses, no freezes | Dated recognized illness/absence pauses for a member or household are neutral: no step, no break, no fake completion. Streak freezes/jokers are removed from the target model. | Pending pause requests, urgent work reassignment and notification integration. Existing freeze code is not removed in this clarification round. |
| D4c: catch-up list | Show one pending catch-up item per recurring chore, rather than one card per missed occurrence. Missed occurrences remain in history; one completion does not retroactively make all of them on time. | Which outstanding occurrence is fulfilled when windows overlap; reconciliation with the next fixed/relative due date and child capacity. |
| D6a: responsibility | Normal chores have one responsible person and optional helpers. Voluntary help creates no extra obligation. Work that truly requires multiple people is explicitly a joint task. | Joint participant confirmation/credit and the transition from today's multi-assignee representation. |
| D6b: substitution | Personal streak credit requires actual participation. An agreed handover before the deadline releases the former responsible person neutrally, without awarding success. Unplanned completion by a parent/other person alone does not automatically rescue that person's streak. | Consent/recording of handover, child permissions and actual-work attribution. |
| D5 core: weekly goal | Measure agreed due occurrences completed inside their windows. A daily chore supplies seven weekly opportunities, not one distinct task. Late catch-up stays visible but is not relabeled on time. | Denominator amendments, pauses, cross-week windows and zero-obligation weeks; 80% target approved in D5b. |
| D7a: reward model | Recognition, personal streaks and shared goals are the default. A household may opt into its own catalog of non-essential family rewards purchasable with chore-earned Coins. Planning works without that catalog. | Detailed fulfillment/cancellation actors and pricing guidance; core lifecycle/admin roles approved in D8. Cosmetics were not selected. |
| D7b: earning | Award Coins by actual task effort, including catch-up work, without task-tier, personal, speed, early or on-time multipliers. Streaks/recognition reward punctuality separately. Do not reward duplicate booking. | Base rate, share precision and repeated-credit prevention; total-effort allocation approved in D6c. |
| D7c: recognition | Properly earned titles/awards survive spending and later streak breaks. A genuinely incorrect completion may be corrected through undo; ordinary spending is not lost progress. | Exact earned-signal/retained-unlock implementation and correction rules under D2. |
| D7d: arcade | Keep an optional, separate arcade with its own highscores. Games neither mint nor spend family Coins. The existing coin boost shop no longer belongs to the household economy. | Coin-boost UI/game disposition; supported first-slice game is Snake (D11). Do not invent another family currency. |
| D8a: reward authority | Child-role members request redemption; a house owner approves. Adult members may redeem directly. Owners administer the catalog/prices. Ordinary chore completion remains immediate, not blocked on a parent approval queue. | Concrete fulfillment/cancellation permissions, membership changes and API authorization tests. Owner is the existing household role, not a new guardian relationship. |
| D8b: reservation | Reserve Coins at request, settle the debit once on confirmed fulfillment, and release the reservation on rejection/cancellation. Existing requests keep their agreed price if catalog prices change. | Retry/concurrency semantics and a reservation that becomes underfunded after a legitimate correction. |
| D2 core: correction after spending | Reverse only the incorrect completion's credit/effects; preserve unrelated correct events. A correction may produce a negative coin balance, repaid by later earnings. Block further redemptions until covered; do not reclaim already delivered rewards. This is an accounting correction, not a penalty for missed chores. | Exact reversal architecture, reserved versus available balance, historical streak/title recomputation and concurrency. |
| D9 core: reminders | Off by default. Opt-in summary at the chosen time, at most one task-reminder summary per person/day, only for relevant planned work. No reminders on free/paused days, no streak threats; respect quiet hours. | Delivery window/timezone, delayed jobs and settings for non-reminder events such as reward fulfillment. |
| D10a: default surface | Tasks and next appointments first for all ages, with small positive personal streak/shared-goal feedback. Arcade and reward catalog are optional areas. Sound off by default, animations can be disabled, no military language. | Concrete layouts, retained accessibility behavior and first-run setup exceptions. |
| D10b: ranking | Off by default. If a household uses rankings, only members who explicitly opt in appear; children may decline or leave. Household activity history remains a separate collaboration/transparency surface. | Consent storage/lifecycle and reset/departure behavior; existing private-task visibility/redaction remains distinct. |
| D4d: pause authority | Adult members manage their own pauses; child members request and an owner confirms. Only an owner sets house-wide pauses or explicitly recognizes retrospective illness. Such exceptions must be traceable, not silent streak repair. | Pending requests, date bounds and recomputation of genuinely excused history. |
| D1c: amendments | Ordinary date/window changes may be agreed before the existing deadline expires. After that, a miss remains in history even if catch-up work is replanned. Recognized retrospective illness is a separate explicit exception. | Recording agreement/consent, concurrent plan edits and denominator amendments. |
| D6c: total effort | Configured task energy means total work, split visibly between participants in planning and credit. More assigned names do not create more Coins; helpers receive a share rather than another full reward. | Default/custom shares, precision/remainders and planned versus actual participant reconciliation. This deliberately replaces current full-effort-per-assignee capacity. |
| D5b: target | Celebrate at 80% of agreed weekly opportunities completed on time. Still display all open/missed work; the milestone neither excuses the other 20% nor changes personal streak rules. | Week/window finalization and eligible-denominator amendments. |
| D11: first delivery scope | Implement the reliable core, then the optional family reward catalog in this program. Initially retain only fully supported Snake in the optional arcade. Defer CalDAV sync, managed local child profiles, Task Pass, cosmetics and general chore-approval workflows outside this slice. | Scope removal/hiding of unsupported prototypes and the old coin boost surface; exact implementation manifest and gates before activation. |

None of the runtime findings in section 9 is resolved merely by choosing the
desired behavior. Existing accounts/authorization and task-privacy protections
remain the baseline; core choices do not authorize unstated permission changes.

### C1 Timeline Decision Log (2026-09-17)

All six recommendations were explicitly selected through the question tool.
These are additional approved target rules, not implemented behavior or a
Standard A review. Durable rules live in the
[task model](../../concepts/task-model-and-sync.md#approved-timeline-contract-c1-2026-09-17)
and [rhythm concept](../../concepts/gamification-and-rewards.md#approved-timeline-evaluation-c1-2026-09-17).

| Decision | Approved rule |
|---|---|
| C1a: month end | If a fixed monthly day does not exist, use that month's last day. Preserve the originally selected day as the anchor: January 31, February 28/29, March 31. The same rule applies to the 29th and 30th. |
| C1b: alternate weeks | Anchor every-second-Saturday recurrence to a selected first Saturday and continue at 14-day intervals. Early/late completion, missed occurrences and year boundaries do not shift the fixed sequence; do not derive it from odd/even ISO week numbers. |
| C1c: overlapping windows | Among unfulfilled occurrences whose windows are open, select the earliest end deadline, then the earliest planned date for a tie. Show the attributed planned date before completion. One completion fulfills only that occurrence; other open occurrences remain open. |
| C1d: catch-up versus current work | An open occurrence takes precedence over expired ones, using C1c if several qualify. Current work clears the old catch-up card as covered, not as a second fulfilled occurrence; historical misses remain. With no open window, catch-up work is attributed late to the most recent missed occurrence; older misses remain historical and fixed future dates remain unchanged. No double credit. |
| C1e: week attribution | Weeks run Monday-Sunday. An occurrence belongs to its planned-date week, not its completion/deadline week. A Saturday occurrence completed within its window on Monday can improve the previous week's result. Mark that week provisional while its associated windows remain open. |
| C1f: chronological streak | Display completed work immediately, but confirm streak steps in planned-day order, no earlier than each planned day. All agreed work on time means success; an unexcused expired obligation means a miss; otherwise the day is pending. Hold later successful steps behind an earlier pending day. If Tuesday later fails, an already successful Thursday starts the new run rather than extending the old one. Neutral-day and participation rules remain as approved in D3/D4/D6. |

C1's six product choices are resolved. The C2 log below now specifies the selected
pause/handover and date-amendment rules. Correction/undo belongs to C3 and
local-time/DST rules to C5; remaining state transitions and the technical
implementation/test manifest are not inferred from these answers.

### C2 Agreement Decision Log (2026-09-17)

All eleven recommendations were explicitly selected through three question batches.
These are target rules, not runtime changes or a review verdict. Durable contracts
live in the [task model](../../concepts/task-model-and-sync.md#approved-agreement-contract-c2-2026-09-17),
[balancing](../../concepts/energy-and-balancing.md#approved-whole-day-capacity-c2-2026-09-17)
and [rhythm concept](../../concepts/gamification-and-rewards.md#approved-goal-amendments-c2-2026-09-17).

| Decision | Approved rule |
|---|---|
| C2a: actual participation | An actor already authorized to complete the task records themselves and actual coworkers at completion. Completion and credits apply immediately; participants can see attribution and mistakes receive traceable correction. Assignment alone is not participation. No per-person confirmation queue or new access rights; exact shares remain C3. |
| C2b: agreed handover | Before the existing deadline, an owner records the handover agreed with both the former and receiving responsible person. No extra confirmation clicks; the change is traceable to authorized viewers. Release the former person neutrally and transfer the obligation to the recipient. Same process for adults/children; private-task rights remain intact. |
| C2c: date/window amendment | An owner records an agreed change with affected responsible people, preserving old/new state, before the existing deadline and only for an unfulfilled occurrence. It takes effect immediately, without a confirmation queue. This is not a way to move an already fulfilled or expired occurrence retrospectively; preserve private-task rights. |
| C2d: pause scope | An acknowledged dated pause excuses an unfulfilled personal obligation when its planned date lies in the pause. For a Saturday task with a Friday-Monday window, a Saturday pause applies; a Monday-only pause does not automatically apply. Correctly completed work remains. Retrospective illness or another permitted amendment must be explicit where needed. |
| C2e: pending child pause | An undecided request is visibly pending and changes neither obligation, deadline nor weekly goal. No automatic extension or rejection. After the deadline, an excuse requires the separately approved explicit owner recognition of retrospective illness. |
| C2f: no automatic redistribution | A pause never automatically hands over work or increases others' shares. With the sole responsible person paused, neutrally suspend the occurrence and keep necessary work visible to authorized household/owner viewers for resolution. For joint work, an owner must confirm an agreed feasible remaining plan before the deadline; until then the occurrence is neutrally suspended. Newly agreed work must fit capacity. |
| C2g: denominator amendments | Remove neutrally suspended occurrences from the weekly denominator, showing the exceptions rather than pretending completion: ten opportunities minus two suspended ones leaves eight. A valid handover still counts once. Timely rescheduling moves the same occurrence to its new planned-date week, never a copy. Historical misses remain except for explicit retrospective illness recognition. Zero opportunities means neutral, not 100% success. |
| C2h: whole-day child limits | New planning, takeovers and offered extra work account jointly for completed work and outstanding daily commitments, including regular, catch-up, early and voluntary help. Energy uses the child's own share; each participated occurrence counts once toward task count, never twice as planned plus completed. At capacity, no extra commitments; owners must replan within limits, not bypass them via a per-item override. |
| C2i: recording real overload | Real work remains immediately recordable with normal effort credit even when it exceeded the plan's limit. Show overload neutrally, offer no further extra work and require owner clarification of remaining commitments. This grants no extra unplanned-day streak step and does not repair historical misses. Limits constrain planning, not the recording of reality. |
| C2j: shorten/revoke pause | Shortening or revoking an acknowledged pause takes effect no earlier than the next local day, under the existing pause roles. Obligations already neutrally excused for today or earlier remain neutral; no retrospective misses. Actual voluntary work remains recordable today without creating a retrospective duty; future commitments must fit capacity. |
| C2k: restart completion-relative work | After a pause neutrally suspends an interval occurrence without completion, keep necessary work visible for clarification rather than automatically overdue at return. An owner records an agreed capacity-fitting restart date. The suspended occurrence remains historically neutral; only the next actual completion starts the completion-relative interval again. Fixed calendar recurrences retain their original anchor. |

These eleven choices resolve the presented C2 questions, including the two
pause lifecycle follow-ups. C3 below now defines shares and correction policy;
C5 still owns the local-day/UI contract, and the technical manifest the
authorization, concurrency and aggregate-capacity enforcement. None of these
choices grants an owner access to an otherwise inaccessible private task.

### C3 Accounting Decision Log (2026-09-17)

All nine recommendations were explicitly selected in two question batches.
Approved target behavior, not implementation or an architectural review pass;
durable rules live in [rewards](../../concepts/gamification-and-rewards.md#approved-accounting-contract-c3-2026-09-17),
[effort allocation](../../concepts/energy-and-balancing.md#approved-effort-allocation-c3-2026-09-17)
and [correction](../../concepts/task-model-and-sync.md#approved-correction-contract-c3-2026-09-17).

| Decision | Approved rule |
|---|---|
| C3a: coin rate | Use a fixed 10 Coins per energy unit in the first slice: energy 1-5 yields 10-50 total Coins, allocated once among actual participants. No personal/household earning multiplier; owners adapt catalog prices rather than the earning formula. |
| C3b: share proposal | Propose equal shares among planned participants unless otherwise agreed; solo responsibility starts at 100%. The authorized completer confirms/corrects actual participants and shares, explicitly reviewing deviations from the plan. No extra owner approval. |
| C3c: precision | Positive whole-percent shares total exactly 100%; at most 100 actual participants. Equal thirds become visible 34/33/33%, with remainder assigned in a stable member order independent of input order or role. Energy 1 yields 0.34/0.33/0.33 energy and 3.4/3.3/3.3 Coins. Support energy and Coins to two decimal places; preserve the exact total, no hidden rounding gains. |
| C3d: recognition catalog | First slice: retained personal rhythm awards at 3/6/12 consecutive successful planned task days and the 80% household weekly milestone. No extra Coins, separate effort/wallet title series or old activity/combo medals. The earned rhythm tier can serve as a personal title; friendly localized wording belongs to C5. |
| C3e: targeted undo | Target a specific completion, including older completions of the same task, by its completer or an authorized owner under existing membership/visibility rights. Remove only its effects; preserve valid later completions and spending. Re-derive dependent streaks/goals and still-open future dates from corrected history, never restore a whole old snapshot. Repeated undo has one effect only. |
| C3f: attribution correction | The completer or authorized owner may correct actual participants/shares with an audit trail while retaining the real completion time, fulfilled occurrence and room effect. Adjust personal Coins, effort and streaks accordingly; previously spent erroneous credit may produce a negative balance. This route cannot edit completion time, total effort or planned date. |
| C3g: erroneous recognition | Revoke an award/title only when corrected valid history never legitimately reached the threshold; another valid earlier/later run preserves it. Correct false personal records and false weekly milestones too. Ordinary spending or later real streak breaks never revoke earned recognition. Undo/redo loops must not repeat rewards or celebrations. |
| C3h: underfunded reservations | Keep requests, reservations and fixed prices. If booked balance is 40 and reserved total 60, available balance is -20. Until the aggregate reservation total is covered, block new requests/redemptions and settlement of all still-unfulfilled rewards. Earnings or authorized cancellation can restore coverage. Never reclaim delivered rewards; actor permissions remain C4. |
| C3i: room correction | Recompute room state in chronological order from remaining valid events, using historical effects and clamps. Preserve later valid cleaning and any later explicit absolute setting. Neither restore an old room snapshot nor blindly subtract an earlier numeric delta. |

#### C3 Technical Consequences (Design Direction, Not a Final Manifest)

- Use exact integer storage/calculation units for hundredths of energy and Coins;
  no binary floating-point balances. For energy E and integer percentage P,
  energy hundredths = E * P and Coin hundredths = 10 * E * P. Choose the stable
  remainder order from immutable member identifiers, never display names or roles.
- Distinguish booked balance B, active reservations R and available balance A = B-R.
  Reservation changes R only; fulfillment debits B and releases the same reserved
  amount once; cancellation releases R without minting Coins. Validate request
  cost against A and settlement against aggregate coverage under the same lock.
- Completion records need stable occurrence/completion identity, frozen effort/rate,
  participant allocations and event time. Targeted undo references that identity;
  attribution correction records a new version and applies only allocation
  differences to affected wallets/contributions, including removed participants.
  Retain independent debits and later credits; reserve state is not an old snapshot.
- Separate additive accounting reversals from derived projections: rebuild affected
  chronological streak/record/award and occurrence projections from valid facts.
  Preserve later completed occurrences and their actual timestamps, C2 amendments
  and unrelated checklist progress; no room-task-wide checklist snapshot restore.
- Room replay needs a creation baseline and ordered, historically parameterized
  room mutations, including completion and manual changes; record requested effects,
  not only already-clamped differences. Equal timestamps require a stable persisted
  order. Derive room timestamps from retained events, not correction execution time.
  A later explicit absolute event, if present, remains authoritative. The current
  manual endpoint applies a delta (`RoomService.php:216-220`), not an absolute set;
  this contract does not add an absolute-setting UI. Inventory reset/bulk writers
  and their history boundaries before claiming complete replay coverage.
- All affected participant wallets, reservations and projections must commit or
  roll back atomically, with under-lock authorization/version checks, stable lock
  order and retry/idempotency handling. Adapt the existing transaction/effect-record
  infrastructure; no general event-sourcing rewrite is authorized by this note.
  CR-SVC-63 remains the existing owner of manual-adjust locking; a lock alone does
  not supply replay history. Exact schema, endpoints, reset boundaries, lock graph,
  file/test mapping and estimates remain required before Standard A.

### C4 Reward Lifecycle Decision Log (2026-09-17)

All eight recommendations were explicitly selected in two question batches.
These are target rules, not shipped features or a review verdict. The durable
[catalog lifecycle contract](../../concepts/gamification-and-rewards.md#approved-catalog-lifecycle-c4-2026-09-17)
owns the actor/state matrix. C3's aggregate-underfunding hold remains unchanged.

| Decision | Approved rule |
|---|---|
| C4a: fulfillment actors | For child requests, a current owner confirms actual fulfillment after owner approval. Adults, including owners, may confirm their own fulfillment; an owner may also confirm for them. Adult requests need no separate approval, but reservation and fulfillment remain distinct. Settlement still requires C3 aggregate coverage. |
| C4b: cancellation/rejection | Requesters, including children, may cancel their own unfulfilled requests without further consent, even after approval or while underfunded. Owners may reject pending child requests and traceably cancel any unfulfilled request. Release the reservation once, without minting Coins. No automatic expiry through time/underfunding; fulfilled rewards are not ordinary cancellations. |
| C4c: catalog retirement | Archiving an item or disabling the catalog prevents new requests, not processing existing ones. Existing requests keep the agreed content and price and remain manageable by authorized actors outside the hidden catalog view. Edits affect new requests only. Explicitly cancel impossible promises; do not silently delete them. Re-enabling never revives cancelled requests. |
| C4d: prices | Owners set positive Coin prices with at most two decimal places, such as 25 or 12.50. No zero/negative prices, discounts or changing price formulas in the first slice. Validate current price and sufficient available funds at request creation; preserve that price thereafter. |
| C4e: ordinary departure | On ordinary departure/removal, atomically cancel all still-unfulfilled requests and release reservations. Preserve fulfilled history and positive/negative booked balance for the same account in this household, without further household access. Rejoining with that same account restores the balance, not old requests. No cross-house transfer or debt erasure. This is not an account-deletion/privacy-erasure policy. |
| C4f: role changes | Check current role on every action. Moving to child requires fresh owner approval for all still-unfulfilled requests. Moving to adult permits explicit self-approval of existing own pending requests, not automatic redemption. Loss of owner rights applies immediately. Preserve prices/reservations and completed history. |
| C4g: visibility | Members see their own requests; current owners see household requests for administration. Other members may see the active catalog, not foreign wish/reservation details. No automatic publication to shared activity; notifications obey the same rights. Ranking consent is separate and private-task access is unchanged. |
| C4h: false fulfillment | Only a current owner may correct an erroneous fulfillment of a reward that was never delivered, recording a reason. Refund exactly the original debit once, create no new reservation, and leave the request terminal and visibly corrected. A later desired reward needs a new request under then-current conditions. No ordinary refund/reclaim of genuinely delivered rewards; retries never duplicate the refund. |

Implementation must keep funding hold orthogonal to request state: approval does
not remove an underfunding hold, whereas cancellation or correcting a false debit
can restore coverage through C3 arithmetic. The current member-removal transaction
deletes the wallet-bearing `house_users` row (`HouseService.php:172-185`); preserve
financial identity/history separately from active authorization before implementing
C4e. Existing owner-removal/last-owner guards remain unchanged. Ordinary departure
does not authorize indefinite retention after an account-deletion/privacy request.

### C5 Delivery and UX Decision Log (2026-09-17)

All thirteen recommendations were explicitly selected through three question
batches. These are target rules, not shipped behavior or a formal review pass.
Durable contracts live in the [delivery model](../../concepts/task-model-and-sync.md#approved-delivery-contract-c5-2026-09-17)
and [UI/evidence model](../../concepts/ui-use-cases.md#approved-calm-ux-and-evidence-c5-2026-09-17).

| Decision | Approved rule |
|---|---|
| C5a: time basis | ChoreStrike delivery times, quiet hours and daily budget use the visibly identified household timezone, independent of device/travel timezone. No separate personal delivery timezone in the first slice. |
| C5b: DST | For a nonexistent chosen time, try at the first valid later instant (02:30 -> 03:00 where applicable). For a repeated time, use its first occurrence only. Quiet hours and the daily budget still apply. |
| C5c: delivery window | Permit delivery for at most 60 elapsed minutes from the resolved scheduled time, only on the same local day and outside personal quiet hours. A later eligible instant within that window is allowed; afterward skip, never next-day catch-up. Recheck relevance/rights before sending. Suggest personally editable quiet hours 20:00-08:00 when enabling. |
| C5d: task relevance | Include only unfulfilled personal work agreed for today that can meaningfully be done now: today's planned occurrences and explicitly agreed catch-up for today. Exclude unsolicited backlog, optional offers and future tasks. No task reminders on personally free/recognized-paused days, threats or streak-repair prompts. |
| C5e: categories | Authorized in-app state remains available. Nextcloud notifications default off; individuals separately opt into task and organizational categories. Enabled content shares at most one daily summary at the selected time with the same quiet/late rules. Organizational updates may appear on free/paused days; task reminders may not. Awards stay quietly in-app, not separate external alerts. Nextcloud security messages are outside this app policy. |
| C5f: landing | After setup, all ages land on My Day with today's agreements and next appointments. Separate non-stacked catch-up and clear overload clarification; a small line shows confirmed streak and shared weekly progress with pending/neutral states. Planning, household and open requests remain reachable. Arcade/catalog/rankings are outside the primary area. Free days show a neutral empty state, not an engagement prompt. |
| C5g: feedback | Default to a short visible completion confirmation with undo access, no sound/confetti or interrupting award modal. Playful effects require personal opt-in; OS reduced-motion always overrides animation preferences. Errors, focus and status remain understandable without sound/motion. |
| C5h: personal preferences | Every person, including a child, controls reminders, organizational digest, sound/motion and optional-area visibility per household. Owners cannot enable them for others. Store server-side across devices without cross-account leakage on shared devices. Opt-out immediately stops unsent notices; departure invalidates opt-ins and rejoin starts quiet/off. Catalog still requires owner household enablement; own existing requests remain reachable under C4. |
| C5i: optional rankings | Owner may enable the area, but only personally consenting members appear. Compare current-week percentage of on-time personal agreements from household-public tasks only, never Coins/speed; ties stay tied and zero opportunities are neutral/unranked. No retroactive inclusion. Withdrawal removes the member from visible ranking; withdrawal, household disablement or rejoin requires fresh consent. Children may decline/leave themselves. |
| C5j: family evidence | After implementation, voluntary guided comprehension test with at least one adult and one child, guardian consent for minors, then two weeks of voluntary everyday use. Both find their next chore, explain progress/neutrality and turn optional features off without operating help. Exercise pause/catch-up/handover/reward cases with test data without provoking real misses. Technical tests cover desktop/mobile/keyboard. Record anonymized pressure/organizing feedback; no names/child data in public evidence. Missing testers leaves the gate open; no contact/data collection is authorized now. |
| C5k: timezone changes | Historical day/week attribution and already concretely agreed occurrences retain their saved time basis/deadlines. New timezone applies to newly agreed occurrences; existing dates change only by allowed explicit replanning. Label differing old time basis visibly. Future digest/quiet timing uses the new house zone without another send from switching: at least 24 elapsed hours since the last digest during transition. |
| C5l: ranking start | Consent starts evaluation at the next Monday 00:00 in household time, not the current partial week. Show pending participation without rank until then. Subsequent agreed visible occurrences follow C1/C2 week rules; open windows make results provisional. Re-consent also starts next full week, never retroactively. |
| C5m: organizational queue | New authorized organizational changes after a regular digest time may enter the next planned digest, taking only the latest relevant state per object, never repeating unchanged pending requests. If that digest's 60-minute window is missed, discard its queued notices from future delivery, retaining in-app state. Only new changes can qualify again; task relevance is freshly computed for the delivery day. |

### Technical Contract Coverage

The presented C1-C5 product questions are answered. The technical draft below
now maps them to persistence, APIs, transactions, files, estimates and tests.
These are engineering proposals for Standard A, not additional approved product
decisions. Q-070-01/02 in section 9 remain unanswered user-visible retention
choices; the dismissed question batch is not consent to either recommendation.

| ID | Technical coverage / remaining gate |
|---|---|
| C1: timeline | Sections 3.2-3.4; WP1; tests T1/T5. Stable identity/anchor, saved boundaries, deterministic selection, catch-up and chronological confirmation specified. |
| C2: agreement | Sections 3.2-3.5; WP2; tests T2/T3. Explicit agreement revisions, neutral pause lifecycle, restart and completed-plus-outstanding capacity specified. |
| C3: accounting | Sections 3.2/3.3/3.5; WP3-4; tests T3-T6/T10. Exact units, isolated reversal, full room/reset writer inventory and CR-SVC-63 race coverage specified; structural-deletion retention selected by Q-070-01 (tombstone, 2026-09-18). |
| C4: reward actors | Sections 3.2/3.4/3.5; WP7-8; tests T6/T11. Request state/actors, fixed terms, persistent financial identity and race outcomes specified; account erasure selected by Q-070-02 (anonymize, 2026-09-18). |
| C5: delivery and evidence | Section 3.6; WP4-6/WP9; tests T7-T9/T12. DST/slot/cutoff/uncertain-send boundary, consent and UI/evidence gates specified. No delivery or family trial has run. |

### Family Examples (Approved Core and Open Details)

- A child has Tuesday and Saturday chores. Both completed within their agreed
  windows means two fulfilled sessions. Wednesday without chores changes nothing,
  even if Wednesday availability is enabled.
- Completing the Saturday occurrence early on Friday fulfills Saturday once;
  it must not create a second Saturday obligation. Finishing a late Tuesday chore
  on Friday must not silently skip the next Saturday occurrence.
- A fortnightly chore stays on its fixed calendar cadence, or deliberately moves
  to 14 days after completion in interval mode. The UI explains which was chosen.
- An illness pause leaves recognition intact, creates no fake completion/reward,
  and makes urgent leftover work visible to the household with a recovery choice.
- One daily chore has seven weekly obligations, not 100% progress after its first
  completion. No-work weeks show a neutral state, not a manufactured achievement.
- An adult's larger energy budget does not make a child's successful plan look
  inferior. Helping is recognized without making another person's streak hostage
  to an assignment or who happened to tap Complete.
- A family can use the entire planning flow without enabling Coins, an arcade or
  rankings. An optional reward might be choosing a movie or a shared activity,
  not buying normal care, rest, affection or exemption from necessary duties.

## 3. Backend / Frontend Changes

**Technical draft (2026-09-18), not implementation approval.** Sections 3-6
specify the proposed implementation of the approved product rules. Dated decision
logs above retain their historical "manifest pending" context; they are not new
unanswered product questions. A separate Standard A review must accept the design
before activation. This round changes documentation only, not runtime, schema,
translations or tests, and does not authorize a commit.

### 3.1 Boundaries and Sources of Truth

- Keep controllers thin, business transactions in services and persistence in
  Nextcloud mappers. Extend `TransactionExecutor`, the completion ledger and the
  existing planner; do not introduce an external queue or generic event-sourcing
  framework. All new endpoints remain authenticated, CSRF-protected and subject
  to service-side membership plus `TaskAccessPolicy` checks.
- `RoomTask` remains the recurring chore, not the identity of an individual
  obligation. An occurrence is the durable agreement unit; a completion is the
  recorded work unit. An amendment keeps the occurrence ID, while undo targets a
  completion ID. Helpers/actual workers and obligated people are different sets.
- Use one backend occurrence evaluator for My Day, Calendar, planning, capacity,
  rhythm, weekly goals, ranking and reminder relevance. UI stores receive results
  and versions, not a second recurrence/streak implementation.
- Store monetary and allocated-energy values as signed integer hundredths
  (`coinMinor`, `energyMinor`), percentages as integers. At total energy E and
  share P, allocated energy is E*P and credit is 10*E*P minor units. API amounts
  use integer minor units; format decimals only for display/input. No floats,
  overloaded old `coins` field, duplicate balances or compatibility adapters.
  Validate all transported amounts and intermediate sums within signed JavaScript
  safe-integer range as well as the DB/PHP range; overflow rejects atomically.
- Distinguish active membership from a persistent house/account financial ID.
  Wallet/history ownership never grants access. Normal departure preserves the
  financial ID; consent and preferences belong to the active membership and do
  not survive departure. Account deletion is a separate privacy boundary.
- Change the existing migration chain for fresh installs, together with entities,
  fixtures and all callers. Do not migrate hypothetical productive balances,
  maintain two recurrence engines, or ship a partly converted economy. Package
  boundaries below are development/review boundaries, not independent releases.

### 3.2 Proposed Persistence Contract

Table names below omit the `chorestrike_` prefix. IDs are bigint primary keys;
foreign identifiers are indexed and house-scoped in mapper queries. UTC instants
are stored at one consistent precision; local dates are ISO `YYYY-MM-DD`, times
are `HH:mm`, zones are validated IANA identifiers. Append-only facts carry an
actor, UTC event time and stable database ID for equal-time ordering. JSON is
limited to bounded rule/audit snapshots; queried balances, status, participants
and dates are typed columns. Constraints are enforced in services as well as
portable unique indexes, without relying on DB-specific partial indexes.

| Table / change | Required fields and invariant | Index / lifecycle |
|---|---|---|
| `houses` (extend) | `state_version`, `timezone_version`, `catalog_enabled=false`, `ranking_enabled=false`; increment relevant version on every plan/accounting/preference mutation. | Existing PK is the aggregate write lock; timezone changes do not rewrite saved occurrence facts. |
| `house_users` (reshape) | `wallet_id`, current role plus `role_revision`, membership generation, exact contribution minor units and rebuildable rhythm projection fields. Remove legacy Coin/multiplier/freeze/title-pack fields with their consumer cutover. | Active membership remains the sole permission source; role revision advances only on role changes, not unrelated household edits. |
| `schedule_revs` (new) | `room_task_id`, `revision`, effective boundary and explicit `agreed_through` frontier, recurrence lifetime (`once`/`recurring`/`bounded`), inclusive `active_from`/`active_until` local dates, cadence mode/unit/interval, original anchor date/day, weekday set, early/late window days, zone, total-energy default, voluntary/public-at-agreement classification and default responsibility/participant-share snapshot. | Unique `(room_task_id, revision)`; immutable complete generation inputs, monotonic agreement frontier, no month-end anchor drift or historical dependency on mutable task fields. |
| `occurrences` (new) | `house_id`, `room_task_id`, `schedule_rev_id`, stable `ordinal`, `kind`, `planned_date`, `week_start`, `timezone`, UTC planned-day start/window start/exclusive window end, agreed total energy, public-at-agreement flag, `version`, suspension/coverage provenance, optional interval predecessor/completion ID and current valid completion ID. | Unique `(schedule_rev_id, ordinal)`; indexes `(house_id, planned_date)` and `(room_task_id, window_end)`; amendment retains ID; expiry is derived, not deletion. |
| `occ_members` (new) | `occurrence_id`, persistent financial/member ID, `role` (responsible/joint/helper), `obligated`, agreed positive `share_pct`, agreed work date/zone, active/released/excused disposition. | Unique `(occurrence_id, member_id)`; member/work-date lookup. A confirmed executable plan totals 100%; a suspended plan keeps its former 100% snapshot but creates no live capacity obligation. Do not require remaining unpaused shares alone to total 100 or silently redistribute them; agreed resumption writes a new feasible 100% revision. |
| `agreements` (new) | `occurrence_id`, revision, kind (date/window/handover/share/catch-up/restart/pause), actor, affected people, old/new agreement snapshot, referenced pause, recorded consent, UTC time. | Unique `(occurrence_id, revision)`; traceable facts, not a second current-state authority. No sensitive medical free text. |
| `pauses` (new) | `house_id`, stable pause group ID, nullable subject for house scope, requester/decider, inclusive start/end local dates, saved zone, reason code, requested/acknowledged/rejected/cancelled state, revision and next-day effective shortening boundary; explicit retrospective-illness marker. | Unique `(pause_group_id, revision)`; append revisions, resolve current state by highest revision. House/subject/date index; preserve already excused dates. Pending alone has no projection effect. |
| `completed_tasks` (reshape existing ledger) | `occurrence_id`, actor, server completion instant and local work-date basis, frozen total energy/rate, current allocation revision, `undone_at`, command reference. Keep immutable work evidence rather than a shared `before_state` restore contract. | Occurrence/history index; lock occurrence to set one current valid completion. A new completion after undo has a new ID; retained correction facts prevent repeated credit. |
| `chk_progress` (extend existing checklist progress) | Add `occurrence_id` and revision; retain item/member attribution. | Unique occurrence/item key replaces room-task/item key. Complete closes only that occurrence's progress; undo never restores a room-task-wide snapshot over later progress. |
| `work_shares` (new) | `completion_id`, allocation revision, persistent member ID, `share_pct`, exact `energy_minor` and `coin_minor`. | Unique `(completion_id, revision, member_id)`; old revisions immutable, exactly one selected revision per valid completion. |
| `wallets` (new) | `house_id`, Nextcloud account binding, immutable ID, signed `booked_minor`, `reserved_minor>=0`, version. | Unique `(house_id, account binding)`; leave does not delete it. `house_users` references it but remains the authorization source. Stable ID also orders equal-share remainders. |
| `wallet_entries` (new) | wallet, source kind/ID/revision, signed delta, reason code, actor/time. Sources: chore credit, attribution delta, undo, reward settlement, false-fulfillment refund. | Unique `(wallet_id, source_kind, source_id, source_revision)`; booked balance equals the entry sum. No reservation represented as an earning/debit. |
| `room_events` (new) | room, kind, effective UTC instant, stable order, source completion ID where relevant, requested effect and frozen parameters; baseline/absolute event value where relevant. | `(room_id, effective_at, id)`; invalidate only the target completion effect. Keep unclamped requested effect, not merely the observed delta. |
| `medals` (reshape existing awards) | persistent member/house subject, rhythm threshold or weekly milestone key, first earned time, current validity, celebration-already-issued marker. | Unique subject/type/period key (non-null period sentinel for lifetime awards). Corrected validity may change; celebration identity does not. Remove old activity/combo catalog. |
| `reward_items` (new, WP7) | house, title/description, positive `price_minor`, archived flag, version, created/updated actor/time. | House/active index; text is escaped user content, not a localization key. Catalog disable/archive prevents only new requests. |
| `reward_requests` (new, WP7) | house, wallet/requester, item ID, immutable content/price snapshot, pending/approved/fulfilled/rejected/cancelled/corrected state, revision, approved requester-role revision, approving actor, settlement/refund references. | `(wallet_id, state)` and `(house_id, state)`; pending/approved reserve exactly their fixed price. Funding hold is derived, not another request state. |
| `request_events` (new, WP7) | request, revision, actor, action, old/new state, original financial entry reference and bounded correction/cancellation reason. | Unique `(request_id, revision)`; private to requester/current owners. No broadcast to household activity. |
| `member_prefs` (new, WP4 consent slice; WP5 delivery extension per Q-075-01 A) | active membership ID, version, ranking consent generation, effective next Monday and saved consent zone in WP4; WP5 adds task/org opt-ins, digest time, quiet range and sound/animation/optional-area choices in place. | Unique membership ID; default off. Delete/invalidate on leave; ranking disable invalidates all consent generations. |
| `digest_changes` (new, WP5) | recipient membership, object type/ID/revision, category, change time, target slot, latest-state pointer and consumed/expired disposition. | Unique recipient/object/target-slot; no sensitive rendered payload. Coalesce state, retain the original slot cutoff. |
| `digest_slots` (new, WP5) | house/account, local day, zone/version, resolved scheduled/window-end UTC instants, preference revision, pending/dispatching/sent/skipped/uncertain status, dispatch token/time. | Unique `(house_id, account, local_day)` independent of opt-in generation; durable last-dispatch time enforces timezone-transition spacing. |
| `commands` (new) | house, actor, command UUID, action, normalized payload hash, result references/status. | Unique `(house_id, actor, command UUID)`; commit with domain facts. Same key/payload returns original IDs, changed payload is a conflict. Never store tokens or raw request bodies. |

Use a non-null stable internal subject for history, not display names or a deleted
`house_users.id`. A raw account UID must not accidentally reattach deleted-account
history to a newly created account of the same name. Exact privacy handling is an
activation check, distinct from the approved ordinary-departure rule.

### 3.3 Occurrences, Amendments and Projections

1. **Generate deterministically.** Fixed rules generate from the original anchor,
   including missing dates while cron was stopped. Monthly clamp preserves the
   anchor; alternate weeks use elapsed calendar days. Interval rules have one next
   pending occurrence, not a fictitious chain based on missed completion dates.
   Enumerating a rule does not itself change an agreement or create Coins.
   Completion-relative day/week intervals advance by local calendar dates from
   the actual completion in its saved basis, not by assumed 24-hour days.
2. **Separate preview from agreement.** Preview/GET may derive virtual dates but
   must not persist. Task creation, confirmed planning/amendment and the timeline
   job materialize facts through one idempotent generator. Once concretely agreed,
   the occurrence keeps its saved zone/deadlines. A timezone/rule revision affects
   only dates not already agreed; cache presence alone must never decide this.
   Persist the rule effective boundary and explicit agreement frontier so reads,
   jobs and restarts choose the same revision without skipping historical misses.
   Before a definition change, reconcile elapsed agreements through the edit
   instant under the old immutable revision, then close its effective boundary
   and append the new revision in the same house-first transaction. Preserve
   already agreed dates even when new active bounds shorten the rule. Historical
   generation uses the old lifetime, bounds, effort and participant defaults;
   neither a late job nor a missing cache row may consult today's task definition
   to reconstruct that history. A failed edit commits neither boundary changes
   nor new rules. Use this same path for energy, lifetime and active-range edits,
   not just frequency/timezone changes.
   Extend the existing seven-day agreement horizon through any earlier-opening
   window that is actionable now; farther calendar entries are explicitly
   projections until agreed. A delayed job reconciles each rule revision's
   elapsed horizon before extending the current one. A timeline job, not a GET,
   confirms time-driven rhythm/award changes; reads can derive fresh pending/miss
   state without minting awards or silently editing agreements.
   Public occurrence identity is the canonical `<scheduleRevisionId>:<ordinal>`
   key, independent of a materialized row's bigint surrogate. Virtual agreements
   expose that key, version 0 and a server-computed selection token. A command
   resolves it using saved rules/frontiers, rechecks the token and materializes
   the same key under the house lock/unique constraint before mutation. Creating
   that row alone does not change agreement version 0. Thus a stopped cron never
   forces a write on GET, a fabricated bigint ID, or a second user action merely
   to record actual work. Completion must not silently turn a farther unagreed
   calendar projection into a personal obligation. Authorized actionable voluntary
   work remains recordable without creating a planned-day streak opportunity.
3. **Use half-open instants.** Default on-time window is local midnight through
   next local midnight, `[start, end)`, not 86,400 assumed seconds. Store resolved
   boundaries once. At exactly `end`, ordinary amendments are too late. Capacity
   uses actual-work day plus outstanding agreed work-day shares, deduplicated by
   participant/occurrence; fulfilled work no longer counts as an outstanding plan.
4. **Complete one shown target.** Select open windows by deadline/planned date/ID;
   otherwise select the latest missed, not-yet-covered occurrence. The client
   sends that occurrence ID and version. A racing completion or changed selection
   returns 409, never silently credits the next occurrence. Current work can mark
   older catch-up as covered but leaves their misses/numerator unchanged.
   Replace the old room-task/user/day duplicate guard with occurrence validity:
   different legitimate occurrences on one day are not duplicates; repeating the
   same shown occurrence or command is. One recorded piece of work is never
   allocated to two occurrences by the server.
5. **Keep catch-up separate from rewriting.** Agreed catch-up changes the work
   appointment of the old obligation, not its missed planned date/window. It
   counts toward that day's capacity/reminder relevance but not as a new on-time
   opportunity or unplanned-day streak step. Show at most one catch-up card per
   room task; preserve all historical occurrences behind it.
6. **Apply agreements atomically.** Owner handover/date/share amendments retain
   before/after evidence and require an unfulfilled occurrence before its old
   deadline, recorded agreement and capacity for every affected participant.
   Generic due-date/assignment writes must not bypass this route. Voluntary
   recorded work can exceed capacity; offered/planned work cannot.
7. **Pause without fake work.** Evaluate planned-date inclusion in the saved
   occurrence basis. Preserve completed facts; pending requests do nothing.
   Suspend unresolved sole/joint obligations neutrally, never redistribute shares.
   An owner-confirmed feasible joint plan can resume before the old deadline.
   Shortening cannot remove today's/past excuses. Interval restart creates a new
   agreed work occurrence linked to the neutral predecessor; only its real
   completion creates the next completion-relative interval.
8. **Replay only dependent results.** Undo voids one completion, reverses its
   latest allocations and re-evaluates affected occurrences, personal planned
   days, weeks, awards and room events. Attribution correction changes allocations
   and personal projections only. Preserve all later valid completions, spending,
   explicit agreements and unrelated checklist work. Recalculate only open,
   automatically derived interval successors from valid completion history;
   explicit agreed amendments take precedence and require explicit replanning.
9. **Confirm chronologically.** Personal day results are success/miss/pending/
   neutral; early work waits for its saved planned-day start and earlier pending
   days. A miss ends the run; a later successful day starts the next run. Count
   each agreed occurrence once in its planned-date week, excluding neutral
   suspensions; compare `5 * onTime >= 4 * eligible` only when eligible > 0.
   Provisional weeks remain labeled until their open windows close. Ranking uses
   the same personal facts filtered to public tasks and effective consent; compare
   ratios exactly, leave equal ratios tied and zero denominators unranked.

### 3.4 API Contract

Paths below are relative to `/apps/chorestrike/api/v1`. Retain the shared
`{success,data,error}` envelope and `ControllerErrorFormatter`: malformed input
400, visible-resource role denial 403, invisible/foreign IDs 404, stale version or
business-state conflict 409. Every mutating command carries a UUID `commandId`;
updates carry `expectedVersion` (planner uses `previewVersion`). Current rights
are checked even on an idempotent replay. Unknown fields/enum values, invalid
dates/zones and invalid whole-percent allocations fail before side effects.
For occurrence routes/payloads, `occurrenceId`/`{id}` is the public key defined in
3.3, not its internal DB surrogate; room-task, completion and reward IDs retain
their normal numeric meaning. Validate and house-scope both parts of an occurrence
key, including for virtual targets. The selection token fingerprints the relevant
rule/agreement/participant versions and shown attribution/status, not clock seconds.

| Route / controller | Request and response | Authority / conflict contract |
|---|---|---|
| `GET /room-tasks/filtered-daily`, `/room-tasks/overdue`, calendar/list reads (existing RoomTaskController) | Enrich with occurrence ID/version, planned/work date, saved zone, window, responsibility/shares, neutral/pending/late status, completion candidate, exact personal effort and next appointments. | Preserve visibility; no extra owner access to private tasks, no GET writes or client-only child limit. |
| `POST /room-tasks`, `/room-tasks/from-template`; `PUT /tasks/{id}` (existing creation/definition routes) | Explicit cadence/anchor/windows and normal/joint participants with shares. Return created rule/occurrence agreement plus current execution row. | Existing task-management roles; future rule changes are prospective, never an alternate path to editing agreed history. |
| `POST /scheduling/suggestions`, `/scheduling/apply` (existing SchedulingController) | Preview returns occurrence IDs, agreement changes, per-person/day minor-unit capacity and content fingerprint; apply sends identical input/fingerprint plus command ID. | Owner and visibility checks. Fingerprint includes rule/occurrence/participant/pause/preference/house versions and actual-work capacity; stale/midnight drift is 409 with no writes. |
| `POST /occurrences/{id}/amend`, `/catch-up`, `/restart` (new OccurrenceController) | Kind-specific payload: new date/window/zone or responsible/joint people/shares, affected people's recorded agreement, expected version and selection token. Return agreement revision and impacted rows/capacity/progress. | Owner with task visibility and capacity under lock. Only ordinary `/amend` requires the old unexpired deadline. `/catch-up` requires an unfulfilled uncovered miss and keeps its original date/window; `/restart` requires a neutral suspended interval predecessor, even if its former window has expired. Neither exception relabels old history. |
| `POST /room-tasks/{id}/complete` (existing RoomTaskController) | `{commandId, occurrenceId, expectedVersion, selectionToken, participants:[{userId,sharePct}], allocationReviewed}`; time, effort and rate are server-owned. Return `completionId`, allocation revision, affected rows, caller-safe wallet/progress/room result and confirmation identity. | Authorized completer must record self plus eligible visible coworkers; review deviations explicitly. Resolve/materialize virtual keys atomically. No owner-approval queue, no duplicate participants, total 100%, max 100. |
| `GET /completions/{id}`; `POST /completions/{id}/undo`; `PUT /completions/{id}/attribution` (new CompletionController) | Read versioned attribution/history; undo targets exact ID; attribution changes participants/shares and records reason, not time/energy/occurrence. | Completer or currently authorized owner for mutation; participants may inspect only authorized attribution, not foreign wallets/private task metadata. Replace room-task "latest undo" route and all callers, no compatibility alias. |
| `GET /house/activity` (existing HouseController / ActivityService) | Rows expose stable completion ID, occurrence key, original work time, frozen total effort, viewer-visible allocation/revision, undone/corrected state and `canCorrect` action hint. Replace `wasCombo` and live-definition energy; do not expose other people's wallet totals. | Keep task redaction and current membership/visibility rules. This read provides older completion IDs for targeted correction, not just a snackbar for the latest work. `canCorrect` is a hint, never server authorization. |
| `GET/POST /pauses`; `POST /pauses/{id}/approve`, `/reject`, `/recognize-illness`; `PUT /pauses/{id}` (new PauseController) | Scope/subject, local dates/zone, reason code and expected revision; response includes pending/recognized state and affected neutral agreements. | Adult self-service, child request, owner house-wide/approval/explicit retrospective recognition. Expired request cannot use ordinary approval to hide a miss. |
| `GET/PUT /preferences/me` (new PreferenceController) | Versioned personal C5 settings and effective ranking start; omit owner-managed capacity limits. Existing house settings add catalog/ranking flags. | Only the current person, including children; owner house feature enablement grants no personal consent. Leave/rejoin resets these settings. |
| `GET /game/state`, `/house/stats`, `/users/{id}/titles`; new `GET /ranking` (existing Game/House/UserController plus new RankingController) | Replace old multiplier/freeze/coin-ranking fields with confirmed/current/best rhythm, retained tier, eligible/onTime/neutral counts, provisional weeks and consent-filtered scores. Own wallet is in game state as `bookedMinor`, `reservedMinor`, `availableMinor`, `fundingHold`; stop exposing balances in the general member list. | No foreign private wallet or non-consenting ranking data. Published rank position uses equal scores, not a hidden Coin/activity tiebreaker. |
| `GET/POST /rewards`; `PUT /rewards/{id}` (new RewardController) | Catalog item/version; price in positive minor units; archive flag. Return active items or owner catalog view. | Members read active catalog when enabled; owner creates/edits/archives. Concurrent price change conflicts with stale displayed price. |
| `GET/POST /reward-requests`; `GET /reward-requests/{id}` (new RewardRequestController) | Create with item ID/version, displayed price and command ID; server freezes item content/price. List own requests, or explicit owner administration scope. | Active member and enabled catalog for creation; `availableMinor >= priceMinor`. Item version drift is 409, never silently reserve a different price. |
| `POST /reward-requests/{id}/approve`, `/reject`, `/cancel`, `/fulfill`, `/correct` (RewardRequestController) | Expected request version; correction requires bounded reason and original settlement reference. Return request revision and caller-visible wallet state. | C4 actor matrix; approval has no debit, fulfillment requires aggregate coverage, correction is owner-only and terminal. Existing requests remain reachable with catalog hidden. |
| Existing `PUT /users/{id}/role`, `DELETE /users/{id}`, `POST /users/invite` (UserController) | Role changes invalidate approvals; removal atomically cancels active requests; re-invitation reattaches same-account wallet but creates quiet preferences. There is no existing self-leave or invitation-acceptance route to preserve. | Existing owner/last-owner guards, rechecked under lock; no access via retained financial records. Do not invent a new self-leave workflow. |
| Existing checklist progress/toggle routes (ChecklistController) | Supply explicit occurrence ID and progress revision; return only that occurrence's items/progress. | Same task visibility plus live occurrence check. Toggling old/stale progress cannot change the next occurrence. |
| `POST /minigames/{gameId}/scores`, score/best/leaderboard reads (existing MinigameController) | Snake score/highscore only; remove family-Coin fields and boost purchase/catalog plus `/minigames/coins` endpoints. | Keep score validation/rate limits and task-domain authorization unchanged. No family wallet mutation from arcade code. |

The request state machine is closed; any unlisted transition returns 409 without
financial writes. Current roles and visibility are checked before reporting a
business conflict. All table amounts below are the immutable request price P.

| Command / source state | Target state | Actor and financial effect |
|---|---|---|
| Create, current child | `pending` | Requester; require A>=P, R+=P, B unchanged. |
| Create, current adult/owner | `approved` | Requester; require A>=P, R+=P, B unchanged; fulfillment is still a separate command. |
| Approve, `pending` | `approved` | Current owner for child; current adult requester may explicitly self-approve their own pending request. No B/R change. |
| Reject, child's `pending` | `rejected` | Current owner; R-=P, no credit to B. |
| Cancel, `pending`/`approved` | `cancelled` | Requester or current owner; R-=P, including while underfunded. |
| Fulfill, `approved` | `fulfilled` | Current owner for a child; adult requester or current owner for an adult. Require valid current-role approval and B>=R; B-=P and R-=P together. |
| Correct false fulfillment, `fulfilled` | `corrected` | Current owner attests non-delivery and records reason; B+=original debit exactly once, R unchanged. Terminal, never auto-reserved again. |
| Requester changes to child, any unfulfilled | `pending` | Role-change transaction invalidates approval, retains P/R. Promotion to adult leaves pending requests pending until explicit self-approval. |
| Membership ends, any unfulfilled | `cancelled` | Authorized membership-removal transaction; release all corresponding R before access disappears. |

Catalog archive/disable, time passage and underfunding do not add transitions.
An owner's later demotion removes their present authority, not the historical
fact that an approval was valid when recorded; the requester's child-role
transition is the explicit C4 reapproval trigger.

House-scoped return aggregates must be filtered per viewer: a multi-person
correction returns invalidation IDs/version for other people, never their private
wallet balances or reward requests. Stores refresh authorized slices after any
mutation; 409 preserves the draft and presents reload/re-preview, not blind retry
with new IDs. Server events use one committed confirmation identity so retry,
re-mount and undo/redo do not repeat celebrations.

### 3.5 Transactions and Concurrency

**Proposed lock extension:** serialize house mutations on the existing house row
first, then lock existing room tasks by ID, task definitions by ID, rooms by ID,
active membership rows by ID, wallets by ID, occurrence/completion rows by ID,
then request/preference/digest rows by ID. Every writer follows this order or a
subsequence; none may acquire the house lock after a child lock. This deliberately
supersedes the completion-only entry order of ADR-011/012; it cannot be rolled out
only in new services. WP0 audits all existing writers/jobs/reset paths. Household
scale justifies the simpler serialization; reads and different houses remain
independent. Never hold a DB transaction while sending a Nextcloud notification.

| Operation | Atomic writes and rechecks | Required competing outcome |
|---|---|---|
| Complete / undo / correct attribution | Live membership, visibility, occurrence/version, actual allocation; completion/share facts, additive wallet entries/caches, room journal/replay, occurrence/checklist state, rhythm/goal/award projections, command receipt and organizational notice references. | Two completes of the shown occurrence: one credit. Undo versus newer same-task completion: later work survives. Correct versus undo: version conflict or one ordered pair, never partial participant balances. |
| Plan / handover / pause / restart | All relevant commitments plus completed work, availability/limits, old deadline, saved zone, current agreement versions; revision evidence and affected projections in one commit. | Two capacity-consuming plans cannot both fit the same remaining slot. Completion versus pause either preserves already completed work or records voluntary work against neutral history, never fake success. |
| Manual room adjustment | House/room lock, append requested delta with historical parameters, recompute and update room cache/timestamp. | Adjust versus completion/undo is serialized; correction preserves later valid manual effects, including clamp behavior (CR-SVC-63). |
| Request reward | Lock wallet and item/current role, require matching displayed terms and sufficient A=B-R, insert request/event, increase R and record receipt. | Two requests competing for the same Coins cannot both overreserve. Catalog edit/archive/disable versus request has one serial outcome. |
| Approve / fulfill / cancel / refund | Recheck current role/approval version and request state. Fulfill requires B>=R for all reservations, writes one debit and releases this R. Cancel releases R only. False fulfillment refunds the original debit once without a reservation. | Fulfill/cancel/refund retries cannot double debit/release/refund. Underfunding blocks all unfulfilled settlements regardless of approval order. |
| Role change / departure / rejoin | Lock membership/wallet and all unfulfilled requests; invalidate approvals on demotion to child, cancel/release on departure, invalidate preferences/queue/consent, then remove access. | Settlement cannot pass with a deleted membership or stale owner role. Rejoin never restores a cancelled request or old consent. |
| Timezone / preferences / ranking disable | Version house/preference changes and future digest schedule; retain agreed occurrence facts, invalidate unsent disabled categories and ranking consent as applicable. | Cached preview/send/ranking read cannot use old settings; a zone change or off/on toggle creates no new daily notification allowance. |

Use `TransactionExecutor` rollback semantics; retry only recognized transient DB
lock/deadlock failures with a small bounded retry policy and the same command key.
Authorization, insufficient funds and stale preview are not retryable successes.
A command receipt commits only with all facts. A lost HTTP response is recovered
by replaying the same key after fresh authorization, not by executing another
credit. A reused key with a different normalized payload returns 409.
Replays return the original result identity with freshly read, authorized current
projections (and a replay marker), not old wallet/room totals that could overwrite
newer client state. A new command key against an already terminal target never
repeats its financial effect.

Real two-connection tests must establish interleavings with barriers, not sleeps.
At least one injected failure after the first wallet update and one after room/
request mutation must prove complete rollback. Include absent-wallet concurrent
creation: the house lock plus unique account binding prevents duplicate identity.
SQLite requires equivalent write serialization despite lacking row-level
`FOR UPDATE`; supported-database verification is part of WP0/WP9, not a claim that
a MariaDB-only pass proves PostgreSQL/SQLite behavior.

**Room/reset coverage:** `RoomService::updateRoomCleanliness` is a delta, not an
absolute set. Record that delta and every completion effect after a creation
baseline; replay in `(effective_at,id)` order with the original clamps, deriving
`last_cleaned` from retained events. The production `POST /house/reset` currently
sets room cleanliness to 100, monster count to 0 and `last_cleaned` to null, plus
house health to 100; it does not clear work or finances. Preserve that contract by
recording one absolute reset event per affected room and updating house/rooms in
the same house-first transaction. Undo before a later reset must leave that reset
authoritative; undo after it replays subsequent events. No new absolute-setting
UI is introduced. Remove or restrict the unused absolute mapper setter so future
callers cannot bypass the journal. Only the existing test-mode reset/seed may
clear all app tables, including new journals, receipts and projections, in
dependency order. Production structural deletion follows the selected Q-070-01
tombstone contract and account erasure the selected Q-070-02 anonymization
policy (section 9, resolved 2026-09-18); neither is silently treated as
test reset or as ordinary departure.

### 3.6 Digest, Consent and UI Delivery

- Reconfigure `DailyNotificationJob` from its current 24-hour interval to a
  five-minute due-slot pass; `TimelineJob` uses the same injected-clock cadence
  for planned-day/window boundaries. Nextcloud cron may still be late: the app
  never extends the 60-minute window to compensate. Document the Nextcloud cron
  prerequisite, not an external worker/service or a delivery-time guarantee.
- Resolve each slot using house time: spring gap first valid later instant, autumn
  overlap first instant only. Deadline is the earlier of scheduled+60 elapsed
  minutes and local day-end. Quiet intervals may cross midnight; recheck current
  quiet/preference settings and current rights at dispatch, not only enqueue.
- Queue authorized organizational changes transactionally with their source
  action, keyed by recipient/object and assigned to the next scheduled cutoff.
  Changes strictly after that cutoff belong to the next slot, even when today's
  worker is late. Coalesce to latest relevant state for that slot. A missed slot
  permanently expires its queue rows. Task relevance is computed at dispatch,
  never copied from yesterday's queue. Do not backfill events predating opt-in.
- Claim one slot atomically across both categories and workers. Persist the
  dispatch identity before calling Nextcloud; recheck membership, consent and
  relevance before this final claim. Opt-out/departure committed before the claim
  wins; a call already dispatching is in flight, not recallable app state.
- Do not promise exactly-once delivery across a DB commit and `IManager::notify`.
  The conservative target is **at most one dispatch attempt** per daily budget:
  retry pre-claim failures within the window, but never resend an uncertain
  post-claim timeout/crash. Mark it uncertain for bounded technical diagnostics,
  without payload logging; in-app state remains authoritative. A stable object ID
  aids tracing, but is not assumed to be a Nextcloud deduplication guarantee.
  Change the existing notification adapter's catch-and-log-only return contract
  so `DigestService` can distinguish a returned accepted call from an uncertain
  exception, without rolling back already committed domain work. The notifier
  must honor current visibility when resolving object references; no hidden task
  names or foreign request details may be reconstructed from audit snapshots.
- Count a dispatch attempt for timezone-transition spacing, including an uncertain
  result: at least 24 elapsed hours before another zone-switched dispatch. Keep
  daily claim history independent of preference deletion/recreation. Ranking
  withdrawal/house disablement clears visible participation immediately; renewed
  consent has a new generation and next-full-week start, never retroactive data.
- My Day becomes the post-setup landing for all ages. Core UI presents planned
  work, next appointments, one catch-up card per chore, explicit overload and
  pending/neutral progress. Keep requests reachable outside catalog navigation.
  Default feedback is accessible confirmation plus targeted undo, with no sound,
  confetti or award modal. Server preferences govern optional effects; reduced
  motion always wins. Reset per-account/house store state before loading another
  identity. All new system text uses `t()` and synchronized `l10n/` artifacts.

## 4. Tests

### Evidence From the Concept Review (2026-09-16)

- `npm test -- --runInBand`: 100 suites / 1056 tests passed. Duplicate manual-mock
  warnings from a local worktree remain; routed separately to TECH-JEST-001.
- `npm run lint`: passed.
- Docker PHPUnit unit: 941 tests / 2760 assertions; integration: 361 tests / 1577
  assertions, both with `--fail-on-skipped`, passed.
- Read-only entity reproduction: a Tue/Sat task due 2026-09-19, completed
  2026-09-17, stays due 2026-09-19; completed 2026-09-18, advances to 2026-09-22.
  This confirms completion uses its date rather than consuming the due occurrence.
- Documentation gates and new-file link/encoding checks are recorded in the
  [review record](../../backlog/archive/CODE_REVIEW_2026-09-16.md#family-first-concept-review).
- These are baseline results, not tests of the subsequently approved target.
  No browser usability trial or new product acceptance tests ran in that round.

**Clarification validation (2026-09-17):** documentation-only changes.
`npm run backlog:check`, `npm run docs:encoding` and `git diff --check` passed.
Both unchanged document gates also passed with the untracked draft included in
their inventory (23 link-scoped files, 720 encoding-scoped paths); its standalone
whitespace check passed. The decision log contains all 24 answers. Runtime diff
and index remain unchanged; runtime suites were not rerun for these doc edits.
The baseline suites above do not prove the approved target or fix its bugs.

**C1 follow-up validation (2026-09-17):** six additional explicit answers recorded
and integrated into task/rhythm concepts, with eight future acceptance scenarios.
Backlog, encoding and tracked/new-plan whitespace gates passed again, including
the untracked plan in the document-gate inventory. Runtime diff and index remain
empty; no runtime suites or family trial were run, and no activation or commit
was performed.

**C2 follow-up validation (2026-09-17):** eleven additional explicit answers
integrated into task/rhythm/balancing concepts, with fourteen future acceptance
scenarios. Backlog, encoding and tracked/new-plan whitespace gates passed,
including the untracked draft in the gate inventory. Runtime diff and index
remain empty. No runtime suites or family trial ran; no activation or commit.

**C3 follow-up validation (2026-09-17):** nine explicit answers integrated into
permanent concepts, with fifteen future scenarios and design consequences.
Backlog, encoding and tracked/new-plan whitespace gates passed, including the
untracked draft. A standalone arithmetic check covered 500 equal-share examples
(energy 1-5, participant counts 1-100) plus reservation/room examples; it tested
the specification formulas, not application code. Runtime diff and index remain
empty; no runtime suites, family trial, activation or commit in this round.

**C4 follow-up validation (2026-09-17):** eight explicit answers integrated into
the permanent reward concept, with an actor/state matrix and fourteen future
scenarios. Backlog, encoding and tracked/new-plan whitespace gates passed,
including the untracked draft. Runtime diff and index remain empty. No runtime
suites, family trial, activation or commit in this documentation round.

**C5 follow-up validation (2026-09-17):** thirteen explicit answers integrated into
task/delivery, UI/evidence, reward and vision concepts; ADR-009 distinguishes its
current timezone limitation from the approved target. Sixteen future scenarios
specified. Backlog, encoding and tracked/new-plan whitespace gates passed,
including the untracked draft. Runtime diff and index remain empty; no runtime
suites, family trial, activation or commit in this documentation round.

### Required Cases for the Approved Implementation

#### C1 Acceptance Scenarios (Specified, Not Yet Executed)

| Scenario | Required result |
|---|---|
| Fixed monthly 31st, non-leap/leap February | January 31 -> February 28/29 -> March 31. A February clamp never changes the original anchor; also cover selected days 29 and 30. |
| Every second Saturday across a year boundary | An anchor at December 26, 2026 yields January 9 and January 23, 2027, including when December work is early, late or missed. |
| Two open unfulfilled windows | Complete the earliest-deadline occurrence only, with planned date as tie-breaker; the UI shows that attribution and the other occurrence remains open. |
| Expired Tuesday plus open Saturday | One completion fulfills Saturday, removes the stale catch-up card and awards only one work credit. Tuesday remains missed in history and the weekly numerator. |
| Catch-up with no open window | Attribute one late completion to the most recent missed occurrence; older misses stay missed, no on-time credit is added and future fixed occurrences are retained. |
| Saturday window through Monday | A Monday completion within the window counts once in Saturday's Monday-Sunday week. That week's displayed result remains provisional while associated windows are open. |
| Tuesday pending, Thursday successful | Thursday work is shown completed immediately but its streak step waits. A later Tuesday success permits ordered confirmation; a Tuesday miss resets the run and Thursday starts it at one. |
| Future planned day completed early | Show completion now, but do not award that planned day's streak step before the planned date or before earlier pending days are resolved. |

The implementation manifest must assign these scenarios to concrete unit,
integration and UI tests; this table is specification, not passing test evidence.

#### C2 Acceptance Scenarios (Specified, Not Yet Executed)

| Scenario | Required result |
|---|---|
| Joint completion by an authorized actor | Record actual coworkers and apply completion/credit immediately, without extra approval. Assigned-but-absent members do not acquire personal credit; attribution is visible to participants. |
| Owner records an agreed pre-deadline handover | Former responsibility becomes neutral, the recipient inherits it, and household occurrence count stays one. Unplanned substitution still does not rescue the former person's streak. |
| Timely date/window amendment | Preserve old/new state and the same occurrence identity. Crossing a week boundary moves its denominator membership, not a duplicate; fulfilled/expired occurrences cannot use this route. |
| Saturday obligation, Friday-Monday window | An acknowledged Saturday pause excuses the still-unfulfilled personal obligation; Monday-only does not. Already correctly completed work is retained. |
| Child pause pending across a deadline | Pending status alone does not change the obligation or goal. No silent extension/rejection; later excusal requires explicit owner recognition of retrospective illness. |
| Sole responsible person pauses | Neutrally suspend the occurrence, remove it from the denominator and expose necessary work only to authorized viewers for clarification, not as an automatic new duty for someone else. |
| One participant in joint work pauses | No automatic share increase; occurrence is neutral until an owner confirms a feasible agreed remaining plan before the deadline. Capacity checks cover every remaining participant. |
| Ten opportunities, two neutral suspensions | Denominator becomes eight; both exceptions remain traceable and neither creates completed work, success credit or Coins. Zero opportunities displays neutral rather than 100%. |
| Child has regular, catch-up, early and helper work | Count completed work plus outstanding commitments together, own energy shares and one task per participated occurrence. A planned-to-completed transition must not double-count the same occurrence. |
| Child reaches either daily limit | Reject extra commitments/offers and per-item owner bypasses; replan within the constraints. Existing overload remains visible, never silently rewritten. |
| Actual work exceeds the plan | Record real completion and ordinary effort credit immediately, display overload and stop extra offers. Do not add an unplanned-day streak step, repair a miss or silently remove remaining obligations. |
| Private task or unauthorized mutation | New owner agreement workflows and household clarification views do not bypass existing membership/private-task rights or reveal inaccessible tasks. |
| Pause shortened or revoked today | Change takes effect no earlier than tomorrow in the applicable local day. Today's/past excused obligations stay neutral; voluntary work is recordable without turning them into missed duties. Existing pause roles and future capacity constraints still apply. |
| Completion-relative task returns from neutral pause | No automatic overdue obligation or fictional completion. Keep work visible for clarification; an owner records an agreed feasible restart. Prior suspension stays neutral and the next actual completion anchors the interval. Fixed calendar anchors do not shift. |

These scenarios also require concrete test-file assignments in the implementation
manifest. They do not claim executed tests or resolve C3/C5 cross-contract
dependencies.

#### C3 Acceptance Scenarios (Specified, Not Yet Executed)

| Scenario | Required result |
|---|---|
| Energy 1-5, solo or shared | Award exactly 10-50 total Coins, with no old multipliers or duplicate participant credit. |
| Equal thirds at energy 1 | Show 34/33/33%, allocate 0.34/0.33/0.33 energy and 3.4/3.3/3.3 Coins; totals remain 1 energy and 10 Coins. Reordering input or changing a role never moves the remainder. |
| Invalid/changed allocation | Reject duplicate participants, zero/non-integer shares, totals other than 100% and more than 100 actual participants. Review actual deviations from the proposal without an owner approval queue. |
| Wallet credit then independent spending/earnings then undo | Reverse only the target credit; retain later valid events, allow legitimate negative booked balance and repay it with later earnings. |
| Older same-task completion undone | Preserve valid later completed occurrences and their times; recalculate only dependent projections/open future dates. Independent checklist progress survives. |
| Retry or concurrent undo | One correction effect only, no duplicate credit/debit, no partial multi-participant state. |
| Attribution corrected after deadline | Preserve actual on-time completion, occurrence, total effort and room effect; apply allocation differences and personal rhythm corrections only, including negative balances when required. |
| Rhythm threshold reached then spending/real streak break | Retain legitimate 3/6/12 awards and title; no wallet-balance qualification or obsolete activity/combo medal. |
| Threshold existed only through a false completion | Correct award, record and weekly result; preserve the award if another valid historical run qualifies. No duplicate celebration from undo/redo. |
| B=40, R=60 | Show A=-20; retain requests/prices, deny new requests and all unfulfilled settlements. Earning 20 yields B=60, A=0 and restores coverage for existing reservations, not a new positive-cost request. |
| Fulfill a covered reservation | Debit booked balance and release reservation once in one transaction; cancellation only releases reservation. Never reclaim already delivered rewards. |
| Room 90, false +20, later valid +20 | Original state is 100; undo the first event and replay to 100, not 90 or 80 by subtraction. Keep the later event's timestamp. |
| Room mutation after false completion | Preserve later manual delta and any supported absolute/reset event under its defined history boundary; never invent an absolute manual endpoint. |
| Manual adjust races completion/undo | CR-SVC-63 regression plus replay consistency: no lost updates and one serialized room-event order. |
| Authorization and rollback | Deny cross-house/invisible targets and unauthorized participant-only corrections; injected failure rolls back wallets, reservations, projections and effect revisions together. |

These are future cases, not executed tests. Assign concrete tests and complete
reset/history boundary semantics in the technical manifest before activation.

#### C4 Acceptance Scenarios (Specified, Not Yet Executed)

| Scenario | Required result |
|---|---|
| Child request, approval and fulfillment | Reserve once at request; owner approval does not debit. Only a current owner may confirm actual fulfillment after approval and aggregate coverage, debiting/releasing once. |
| Adult request and actual fulfillment | No separate owner approval; reservation and fulfillment remain distinct. Requester or current owner may confirm, not another ordinary member. |
| Cancel approved or underfunded request | Requester (including child) or owner may cancel; release once, no booked-balance credit. Time/underfunding alone never expire the request. |
| Archive item or disable catalog | Block new requests; keep existing agreed content/price and authorized processing accessible. Re-enable does not revive cancelled requests. |
| Price validation/change | Accept positive prices with at most two decimal places; reject zero, negative or excess precision. A changed catalog price affects new requests, not old ones. |
| Ordinary departure with requests | Atomically cancel unfulfilled requests, release reservations and remove access while retaining booked balance and fulfilled history for the same account/house. Existing membership safety guards still apply. |
| Rejoin after departure | Restore positive or negative balance for the same account/house only, with no revived request or transfer between households. |
| Adult becomes child | Every unfulfilled request requires fresh owner approval; prior adult status is no fulfillment bypass. Price and reserved amount remain unchanged. |
| Child becomes adult or owner is demoted | Permit explicit self-approval of own pending requests after promotion, not automatic fulfillment; deny owner-only actions immediately after owner demotion. |
| Read another member's request | Ordinary members cannot read foreign wishes, request history or reserved amounts; owners can administer household requests. Shared activity and notifications must not leak these details. |
| Erroneous fulfillment corrected | Current owner records reason, refunds exactly the original debit once, leaves request corrected/terminal and creates no new reservation. Valid delivered rewards do not use this path. |
| C3 funding hold intersects actions | Underfunding prevents settlement even after approval or catalog retirement. Authorized cancellation/refund can restore coverage; no action silently changes the request price or priority policy. |
| Concurrent fulfill/cancel/departure | Serialize into one valid outcome: no double debit/release, no settlement after membership has ended, no partially cancelled departure. |
| Concurrent role/price changes or refund retries | Recheck live authority/state and snapshot terms atomically; stale commands cannot bypass child approval or produce repeated refunds. |

These are future test obligations, not executed tests. Exact routes, schema,
privacy-erasure integration and transaction boundaries belong in the technical
manifest, not an inferred new permission or an automatic plan activation.

#### C5 Acceptance Scenarios (Specified, Not Yet Executed)

| Scenario | Required result |
|---|---|
| Device/travel timezone differs | Agreed household time governs chosen delivery/quiet time and daily budget; show the zone instead of silently following the device. |
| Spring DST gap / autumn repeated time | Resolve a nonexistent 02:30 to first valid later instant; use only the first repeated 02:30, never another digest from the second occurrence. |
| Late worker, midnight or quiet hours | Send only within 60 elapsed minutes, the same local day and outside quiet hours; try a later permissible instant within the window or skip permanently. |
| Relevance changes before send | Completed/paused/free-day or inaccessible tasks vanish from the summary; no unsolicited overdue, future or optional work is injected. |
| Tasks plus organization enabled | One shared daily summary budget, not one per category. Organizational content may appear on free/paused days without task reminders. Awards create no separate external notice. |
| Organizational changes after cutoff | Put new changes in the next planned slot, coalesce by latest relevant object state, do not repeat an unchanged request. Missing that slot expires its queued notices, not in-app history. |
| Parallel workers, retries and opt-in toggles | At most one digest per daily budget; no duplicate on retry or off/on toggle. Recheck live preference/authority before dispatch; exact persistence/failure boundaries need the technical manifest. |
| Fresh adult/child entry after setup | My Day first, small confirmed/provisional/neutral progress, separate catch-up, next appointments and reachable planning/requests; no primary arcade/catalog/ranking panel. |
| Completion with default or reduced-motion settings | Short accessible confirmation and undo; no default sound/confetti/modal. Reduced-motion overrides opted-in animation; errors/focus remain clear. |
| Shared device and second device | Server preferences follow the correct person/house, not a previous login. Children can opt out; owners cannot opt others in. |
| Departure/rejoin or unsent notice opt-out | Unsent delivery stops on opt-out, no restored opt-in after rejoin. Existing financial balance follows C4 separately; existing requests remain reachable where authorized. |
| Ranking consent midweek | Pending without rank until next Monday 00:00; no pre-consent/partial-week history imported. Open windows make progress provisional. |
| Ranking score, tie and zero work | Score only timely eligible personal agreements from household-public tasks; no private-task leak, Coin/speed tie-breaker or rank for zero opportunities. |
| Ranking withdrawal/house disablement | Remove participant from visible ranking; fresh consent and next-full-week start required to return. Children can withdraw without owner approval. |
| Owner changes house timezone | Historical assignments and concrete agreed deadlines retain their saved basis; newly agreed dates use the new zone, old basis is visible, no switch-generated digest within 24 hours of the last send. |
| Family protocol | Comprehension test plus two voluntary weeks, adult and consenting child/guardian, synthetic exception cases and anonymized pressure/organizing feedback; missing evidence cannot be called a pass. |

These are specifications, not executed application tests or a completed family
trial. The technical manifest must map each to concrete automated/manual evidence.

#### Cross-Layer Coverage

| Layer | Required evidence |
|---|---|
| PHPUnit unit | Static Tue/Sat early/on-time/late/adjacent-day and alternating weeks; monthly boundaries; dynamic interval; flex versus overdue; obligation-free/partial/pending planned days; pause versus unexcused miss; total-effort share conservation; effort-only payout without old multipliers; retained recognition versus genuine correction. |
| PHPUnit integration (Docker) | Preview/apply legal-date and every-participant constraints with agreed effort shares; no child overload; isolated undo interleaved with room/wallet actions; negative balance and reservation coverage; exact occurrence/80% goal; pause approval/history amendments; duplicate redemption/approval/fulfillment and failure rollback. |
| Jest | Fixed versus dynamic explanations, non-obligation empty state, bounded child overdue recovery, quiet/muted mode and ranking choice, consistent store reconciliation and the selected optional family reward states. |
| Playwright | Owner/member/child Tue/Sat journey; no tasks on an available day; early/late occurrence; unavailable joint participant; pause and recovery; role-denied actions; notification opt-out; optional reward flow on desktop/mobile and keyboard. |
| Family trial | Child and adult can find the next agreed chore, explain their progress, recover a missed session and opt out of playful features without coaching. Track on-time planned work and perceived nagging/parent effort, not daily active users. No child-identifiable telemetry or external analytics service. |

For retained titles, add a regression that earning a tier then spending Coins
does not revoke recognition, while undo/reset follows its separately approved
correction policy. Pin the service response and the displayed title after reload;
this is a required future case, not an existing passing acceptance test.

Existing suites (`RoomTaskTest`, `SchedulePlanServiceTest`,
`StreakFreezeIdempotencyTest`, `CompletionUndoStateEqualityTest`,
`HouseServiceTest`, `GameLogicServiceTest`, family workflow specs) are reuse
points, not proof these new cases already exist. Every chosen behavior change
needs a regression test; none may be newly skipped to complete this plan.
Retire freeze-specific tests only alongside the approved feature removal and
replacement pause/rhythm coverage; do not add new freeze mechanics to satisfy
obsolete target behavior. Add opt-out ranking and no-coin Snake regression cases.

### Concrete Test Ownership (Technical Draft 2026-09-18)

Scenario IDs below mean the ordered rows of the C1-C5 acceptance tables above:
`C1.1` is monthly clamping, `C1.8` early future work, etc. All 67 rows
(8/14/15/14/16) remain required, not claimed passing. `M` means extend an existing
file; `N` means a proposed new file. Paths with braces enumerate exact files,
not a wildcard search. Each package supplies its tests when implemented, not at
the end of the program.

| Test group / package | Cases assigned | Concrete test files |
|---|---|---|
| T0 / WP0 | Fresh schema, unique command/identity constraints, house-first order including SQLite write acquisition, rollback, visibility/role matrix and historical-delete/account-erasure decisions once resolved. | N `tests/integration/Db/FamilyRhythmSchemaTest.php`, `tests/integration/Service/HouseMutationConcurrencyTest.php`, `tests/integration/Service/AccountErasureTest.php`; M `tests/integration/RoleGuardApiTest.php`, `tests/integration/Controller/InviteOnlyMembershipTest.php`. |
| T1 / WP1 | C1.1-5, C1.8: calendar/interval anchors, overlap/catch-up selection, same target replay, elapsed days after stopped cron, saved zone revisions, one-time/bounded recurrence and occurrence-scoped checklist. Include stopped cron spanning energy/lifetime/bounded-end edits: old misses and effort survive, already agreed future dates stay fixed, only new agreements use the new revision; failed edits roll back both boundary and revision. | M `tests/unit/Db/Entity/RoomTaskTest.php`, `tests/unit/Service/CalendarServiceTest.php`; N `tests/unit/Service/OccurrenceServiceTest.php`, `tests/integration/Service/OccurrenceTimelineTest.php`; M `tests/integration/Db/ChecklistToggleConcurrencyTest.php`. |
| T2 / WP2 | C2.2-7, C2.12-14: handover, amendment cutoff, personal/joint pauses, pending child requests, old-week denominator, shortening, restart, private visibility. | N `tests/unit/Service/{AgreementService,PauseService}Test.php`, `tests/integration/Service/PauseAgreementTest.php`; M `tests/integration/Service/SchedulePlanAggregateTest.php`, `tests/integration/RoleGuardApiTest.php`. |
| T3 / WP2-3 | C2.1, C2.8-11 and C3.1-3, C3.7: planned/actual shares, conservation, regular/catch-up/early/helper dedupe, real overload versus forbidden extra commitments, attribution deviations. | M `tests/unit/Service/{SchedulePlanService,GameLogicService,GameLogicServiceTeamwork}Test.php`; N `tests/unit/Service/EffortAllocationServiceTest.php`; M `tests/integration/Service/{SchedulePlanConcurrency,TeamworkReward}Test.php`. |
| T4 / WP3 | C3.4-7, C3.12-15: older undo, intervening spend/earn/manual adjust, equal-time room events, reset before/after correction, preserved later checklist, all-wallet rollback, authorization. | M `tests/integration/Service/{CompletionUndoStateEquality,CompletionConcurrency,ConcurrentCompletion}Test.php`; N `tests/unit/Service/RoomReplayServiceTest.php`, `tests/integration/Service/CompletionCorrectionTest.php`, `tests/integration/Service/RoomReplayConcurrencyTest.php`; M `tests/unit/Service/{CompletionService,RoomService,HouseService}Test.php`. |
| T5 / WP4 | C1.6-8, C2.7, C3.8-9: chronological pending days, retained 3/6/12 awards, legitimate alternate qualifying run, provisional 80% weeks, no repeated celebration, zero-work neutrality. | N `tests/unit/Service/{RhythmService,WeeklyGoalService}Test.php`, `tests/integration/Service/RhythmProjectionTest.php`; M `tests/unit/Service/{MedalService,TitleService,HouseService}Test.php`. |
| T6 / WP7 | C3.10-11 and all C4.1-14: price snapshots, child/adult actor/state matrix, B/R/A funding holds, departure/rejoin, role changes, privacy-filtered reads, fulfillment/cancel/refund/price races. | N `tests/unit/Service/{WalletService,RewardService,RewardRequestService}Test.php`, `tests/integration/Service/{RewardLifecycle,RewardConcurrency}Test.php`; M `tests/integration/Service/HouseMemberRemovalIntegrationTest.php`, `tests/integration/Controller/UserControllerIntegrationTest.php`. |
| T7 / WP5 | C5.1-7, C5.11, C5.15: gap/fold, quiet/midnight/window end, both categories/one budget, queue cutoffs/coalescing/expiry, relevance/opt-out/leave, ambiguous dispatch failure, concurrent workers and zone change. | N `tests/unit/Service/{DigestService,DigestScheduleService}Test.php`, `tests/integration/Service/{DigestDelivery,DigestConcurrency}Test.php`; M `tests/unit/Service/CalendarServiceTest.php`, `tests/integration/Service/TimezoneDayBucketingTest.php`. |
| T8 / WP4-6 | C5.10, C5.12-15: own settings across identities/devices, child consent, next-Monday activation, ratio ties, zero denominators, no private data, withdrawal/disable/rejoin generations. WP4 owns ranking consent/backend + minimal client pins (Q-075-01 A); WP5 owns non-ranking preferences; WP6 owns full presentation. | N `tests/unit/Service/RankingServiceTest.php`, `tests/integration/Service/RankingConsentTest.php`, `tests/unit/store/preferences.spec.js`; M `tests/unit/views/Leaderboard.spec.js`, `tests/unit/store/user.spec.js`. |
| T9 / WP6 | C5.8-10 and all UI-visible C1/C2/C3 states: My Day landing, confirmations/undo, capacity and neutral states, exact minor-unit formatting, reduced-motion override, preserved draft after 409. | M `tests/unit/components/{TodayView,SchedulePlanner,RecurrencePresetSelector,TaskList,TaskChecklist,UndoSnackbar}.spec.js`, `tests/unit/store/{roomTasks,scheduling,game}.spec.js`, `tests/unit/views/{Dashboard,Settings}.spec.js`; N `tests/unit/components/{ParticipationDialog,PauseSettings}.spec.js`. |
| T10 / WP3+6 | D7d/D11: Snake score never changes family wallet or awards chore contribution; no boost routes/buttons, unsupported game cards, freeze promises or wallet-qualified titles remain. | M `tests/unit/Service/MinigameServiceTest.php`, `tests/integration/Service/MinigameConcurrencyTest.php`, `tests/unit/components/{ArcadeSection,SnakeGame}.spec.js`, `tests/unit/store/minigame.spec.js`, `tests/e2e/snake-game.spec.ts`. |
| T11 / WP8 | All C4 UI states plus C5.8/10/11: opt-in catalog, price drift, fixed pending terms, underfunded hold, owner/current-role controls, requests reachable while catalog hidden, two-decimal input and no foreign request data. Detailed in [CS-PLAN-079](../active/cs-plan-079-wp8-reward-ui-and-onboarding.md), activated 2026-10-02 (Standard A `PASS-WITH-FIXES`). | N `tests/unit/store/rewards.spec.js`, `tests/unit/views/Rewards.spec.js`, `tests/unit/components/{RewardRequestList,RewardRequestDetails,RewardCatalogEditor}.spec.js`, `tests/e2e/family-rewards.spec.ts`; exact existing-client regression mapping in the child. |
| T12 / WP9 | C5.16 and integrated owner/member/child use, desktop/mobile/keyboard, paused/free days, catch-up/handover, stale concurrent work and safe correction. | M `tests/e2e/core-household-journey.spec.ts`, `tests/e2e/family-core-ux.spec.ts`, `tests/e2e/workflow-state-reconciliation.spec.ts`; N `tests/e2e/family-rhythm.spec.ts`, `tests/e2e/quiet-digest.spec.ts`; anonymized manual evidence in `docs/testing/release-test-plan.md`. |

Shared test groups are delivered incrementally, not circular exit dependencies:
T3 planning/capacity cases exit with WP2 and actual allocation/accounting with WP3;
T8 non-ranking preference persistence exits with WP5; ranking consent persistence
and ranking backend exit with WP4 (Q-075-01 A), and
Jest presentation with WP6; T10 no-Coin backend proofs exit with WP3 and UI/E2E
removal proofs with WP6. T0 schema/erasure coverage grows as later packages add
tables, with the complete cross-domain gate in WP9. No future-package test is
newly skipped to simulate an earlier package pass. T1 must specifically cover a
write-free virtual GET followed by atomic materialization/completion, including
stale tokens and competing first materializations.

Additional existing adapter/history tests change with their consumers: T4 adds
`tests/unit/Service/ActivityServiceTest.php` and
`tests/integration/Controller/HouseActivityIntegrationTest.php`; T7 adds
`tests/unit/Service/NotificationServiceTest.php`,
`tests/unit/Notification/ChoreStrikeNotifierTest.php`,
`tests/unit/BackgroundJob/DailyNotificationJobTest.php`,
`tests/unit/Command/TriggerJobsTest.php` and
`tests/integration/Command/TriggerJobsIntegrationTest.php`. T1 adds new
`tests/unit/BackgroundJob/TimelineJobTest.php`. T9 includes existing
`tests/unit/components/{ActivityFeed,MemberFairnessTable}.spec.js` and new
`tests/unit/components/NotificationPreferences.spec.js`. Test suspended 50/50
joint work explicitly: no forced 100% reassignment, no live planned capacity
while suspended, and a new capacity-checked 100% agreement before resumption.

Add full-share property cases (energy 1-5, equal groups 1-100, reordered inputs,
custom valid partitions) and reject duplicate/zero/fractional/over-100 allocations.
Include amount overflow and JSON safe-integer limits, not just happy-path decimals.
Time examples use an injected clock and named zones (`Europe/Berlin` DST plus UTC
and a timezone change across date/week boundaries), never the host clock.
Concurrency assertions inspect both aggregate caches and ledger sums from fresh
connections. A sequential double-call is an idempotency test, not a race test.

**Required implementation gates:** `npm test -- --runInBand`, `npm run lint`,
`npm run build`, `npm run l10n:check-keys`, `npm run backlog:check`,
`npm run docs:encoding`, `git diff --check`; Docker PHPUnit unit and integration
with `--fail-on-skipped`, plus `composer cs:check`. Use the canonical
`docker/compose.test.yml` environment and commands from the
[Docker skill](../../standards/ai/skills/docker-dev/SKILL.md).
`npm run test:e2e:gate` is the browser gate, not an ad-hoc passing subset. Add the
new mandatory scenarios to `scripts/run-e2e-gate.js` and the appropriate existing
serial pool in `playwright.config.ts`; preserve zero retries/skips. WP9 requires
two consecutive clean browser-gate passes, schema creation on SQLite/MariaDB/
PostgreSQL in isolated disposable test environments, and the C5 family evidence.
The canonical compose environment remains unchanged for ordinary backend tests;
an isolated DB-matrix override is listed in the manifest. Never reset an unrelated
shared environment to obtain a clean run.

**Retirement is not a skip:** archive obsolete freeze/restock/boost/2048/Memory
tests together with their removed runtime surface only after replacement tests
T2/T5/T7/T10 are wired into the required gates. Update gate discovery in the same
package. Existing unrelated TECH-JEST-001 warnings keep their backlog owner;
no warning suppression or worktree deletion is part of this program.

`tests/e2e/required-scenarios.json` pins exact required scenario titles and must
change alongside the runner/config, not be forgotten while adding new specs.
The following are explicit replacement owners, not permission to drop coverage:

| Existing required browser surface | Replacement and owning package |
|---|---|
| `tests/e2e/title-selection.spec.ts` | WP4/WP6 rewrite for retained 3/6/12 recognition after correction/spending; no three-pack picker or removed selection route. |
| `tests/e2e/time-travel-streak-notification.spec.ts` | WP4/WP5 archive obsolete freeze/at-risk assumptions only when their pending/neutral rhythm and quiet-digest scenarios are pinned in new `family-rhythm.spec.ts` / `quiet-digest.spec.ts`. |
| `tests/e2e/release-notifications.spec.ts` | WP5/WP6 replace external weekly-award assertions with opted-in shared digest, missed-slot and no-default-delivery proofs. |
| `tests/e2e/leaderboard.spec.ts`, `multi-user-interactions.spec.ts`, `error-handling.spec.ts`, `insights-flow.spec.ts` | WP4/WP6 replace unconditional all-member/raw-contribution ranking assumptions with opt-in next-week percentage, neutral ties/private exclusion and reachable authorized navigation. |
| `tests/e2e/teamwork-rewards.spec.ts`, `task-completion-full-flow.spec.ts` | WP3/WP6 replace multiplier hints with exact total-work splits and targeted correction; keep atomic completion/reconciliation assertions. |
| `tests/e2e/release-boost-shop.spec.ts`, `snake-game.spec.ts` | WP3/WP6 remove boost-purchase assumptions only with pinned Snake-score/no-family-wallet-write tests. |

WP1-8 author their new browser scenarios and update required discovery as behavior
changes; WP9 runs the final combined evidence, not the first implementation of
all replacement scenarios. The full runtime graph must build between packages.

**Technical-planning validation (2026-09-18):** only documentation gates apply
because runtime is unchanged. `npm run backlog:check`, `npm run docs:encoding`,
`git diff --check` and the untracked plan's standalone whitespace check passed.
The unchanged document gates also passed with Git's tracked-plus-untracked
inventory supplied in-process (23 link-scoped files, 720 encoding-scoped paths),
without staging the draft or editing the checkers. Runtime paths and index remain
unchanged. A read-only internal consistency pass informed corrections to virtual
occurrence addressing, amendment/restart preconditions, suspended joint shares,
complete historical rule inputs across definition edits, file paths, activity
DTOs, gate discovery and package dependencies; it is not
Standard A. No runtime suites, family trial, activation or commit ran. None of
the future T0-T12 tests is claimed passing from this documentation evidence.

## 5. File Manifest

Documentation round:

```text
new:      docs/plans/new/cs-plan-070-family-rhythm-and-reward-clarification.md
modified: README.md
modified: docs/README.md
modified: docs/plans/README.md
modified: docs/concepts/product-vision.md
modified: docs/concepts/task-model-and-sync.md
modified: docs/concepts/energy-and-balancing.md
modified: docs/concepts/gamification-and-rewards.md
modified: docs/concepts/house-setup-and-templates.md
modified: docs/concepts/ui-use-cases.md
modified: docs/concepts/design.md
modified: docs/adr/ADR-004-multi-user-gamification.md
modified: docs/adr/ADR-009-per-house-timezone.md
modified: docs/adr/ADR-011-transactional-completion-and-undo.md
modified: docs/standards/ai/skills/gamification/SKILL.md
modified: docs/backlog/backlog.md
modified: docs/backlog/ACTIVE_TASKS.md
modified: docs/backlog/archive/CODE_REVIEW_2026-09-16.md
```

The list above records the preceding clarification rounds, not files changed
anew in every session. This technical-planning round edits this draft plus
`docs/backlog/ACTIVE_TASKS.md`, `docs/backlog/backlog.md` and `docs/plans/README.md`.
Unrelated pre-existing deletions of `docs/plans/{new,active,onhold}/.gitkeep` remain
excluded and must not be restored.

### Implementation Manifest by Package

`M` = modify existing; `N` = create after activation; `R` = retire from the active
tree only with replacement coverage, preserving old source under the gitignored
`archive/YYYY-MM/cs-plan-070-retired/` at implementation time. Brace lists enumerate
exact paths. T0-T12 in section 4 supply the test files for each package; they are
part of its manifest, not optional additions. No files listed below are created
in this documentation round.

Shared-file entries are not permission to remove fields before their consumers
are converted: WP0 changes write-entry locking/identity, WP1 changes occurrence
identity, WP3 replaces all monetary writers together, and WP4 removes obsolete
recognition. Apply each schema edit with its consuming code/fixtures in that
package, resetting disposable development data as required. An intermediate
package must still build and pass its relevant gates; no compatibility shim or
temporary dual-authority balance is a package deliverable.

| Package | Existing files (M) | Proposed new files (N) |
|---|---|---|
| WP0: write/identity foundation | `lib/Db/{TransactionExecutor,House,HouseMapper,HouseUser,HouseUserMapper}.php`; `lib/Service/{HouseService,FloorService,RoomService,RoomTaskService,TaskService,TaskCreationService,HouseSetupService,CompletionService,SchedulePlanService,SchedulingService,ChecklistService,MinigameService,TestDataService,GameLogicService,MedalService,TitleService,StreakFreezeService,StreakRestockService}.php`; `lib/Db/RoomTaskChecklistProgressMapper.php`; `lib/AppInfo/Application.php` | `lib/Db/{CommandReceipt,CommandReceiptMapper,Wallet,WalletMapper,WalletEntry,WalletEntryMapper}.php`; `lib/Service/CommandService.php`; selected by Q-070-02 (erasure): `lib/Listener/UserDeletedListener.php`, `lib/Service/AccountErasureService.php`. |
| WP1: occurrence timeline | `lib/Db/{Task,RoomTask,RoomTaskMapper,TaskAssignee,TaskAssigneeMapper,CompletedTask,CompletedTaskMapper,RoomTaskChecklistProgress,RoomTaskChecklistProgressMapper}.php`; `lib/Service/{CalendarService,TaskCreationService,HouseSetupService,RoomTaskService,RoomTaskReadService,TaskService,ChecklistService,TaskAccessPolicy}.php`; `lib/Controller/{TaskController,RoomTaskController,ChecklistController}.php`; `appinfo/info.xml` | `lib/Db/{ScheduleRevision,ScheduleRevisionMapper,Occurrence,OccurrenceMapper,OccurrenceMember,OccurrenceMemberMapper}.php`; `lib/Service/{RecurrenceCalculator,OccurrenceService}.php`; `lib/BackgroundJob/TimelineJob.php`. |
| WP2: agreements/capacity/pauses | `lib/Service/{SchedulePlanService,SchedulingService,RoomTaskService,RoomTaskReadService}.php`; `lib/Controller/SchedulingController.php`; `lib/Db/TaskAssigneeMapper.php` | `lib/Db/{Agreement,AgreementMapper,Pause,PauseMapper}.php`; `lib/Service/{AgreementService,PauseService}.php`; `lib/Controller/{OccurrenceController,PauseController}.php`. |
| WP3: work/accounting/replay | `lib/Service/{CompletionService,CompletionEffectService,CompletionPostCommitService,GameLogicService,RoomService,HouseService,MinigameService,ActivityService}.php`; `lib/Db/{CompletedTask,CompletedTaskMapper,Room,RoomMapper,HouseUser,HouseUserMapper}.php`; `lib/Controller/{RoomTaskController,MinigameController,HouseController}.php` | `lib/Db/{WorkShare,WorkShareMapper,RoomEvent,RoomEventMapper}.php`; `lib/Service/{EffortAllocationService,WalletService,RoomReplayService}.php`; `lib/Controller/CompletionController.php`. |
| WP4: rhythm/goals/ranking | `lib/Service/{GameLogicService,MedalService,TitleService,RewardConfigService,HouseService,CompletionPostCommitService}.php`; `lib/Db/{HouseUser,HouseUserMapper,Medal,MedalMapper,HouseMapper}.php`; `lib/Titles/TitleCatalog.php`; `lib/Controller/{GameController,UserController}.php`; `appinfo/info.xml`; `lib/Command/TriggerJobs.php` | `lib/Service/{RhythmService,WeeklyGoalService,RankingService}.php`; `lib/Controller/RankingController.php`. |
| WP5: preferences/delivery | `lib/Service/{NotificationService,CompletionPostCommitService,HouseService,CalendarService}.php`; `lib/Notification/ChoreStrikeNotifier.php`; `lib/BackgroundJob/DailyNotificationJob.php`; `lib/Command/TriggerJobs.php`; `lib/AppInfo/Application.php`; `appinfo/info.xml` | `lib/Db/{MemberPreference,MemberPreferenceMapper,DigestChange,DigestChangeMapper,DigestSlot,DigestSlotMapper}.php`; `lib/Service/{PreferenceService,DigestScheduleService,DigestService}.php`; `lib/Controller/PreferenceController.php`. |
| WP6: calm core UI | `src/{router.js,main.js,App.vue}`; `src/views/{TodayView,Dashboard,CalendarView,Settings,Leaderboard,InsightsView,SchedulingView}.vue`; `src/store/{user,house,roomTasks,tasks,checklist,scheduling,game,titles,minigame}.js`; `src/services/api.js`; `src/components/Tasks/{TaskEditForm,RecurrencePresetSelector,RoomTaskEditModal,TaskList,TaskListItem,TaskListDetails,AssigneeChips,TaskChecklist,FlexWindowDisplay,OverdueClaimDialog}.vue`; `src/components/Scheduling/{SchedulePlanner,ScheduleCalendar,SelfAvailabilitySettings,AvailabilitySettings,EnergyBudgetSettings}.vue`; `src/components/Dashboard/{HouseholdGoalBar,QuickStats,ArcadeSection}.vue`; `src/components/Profile/TitleBadge.vue`; `src/components/Minigames/{SnakeGame,SnakeLeaderboard}.vue`; `src/components/Common/UndoSnackbar.vue`; `src/composables/{useCelebration,useToasts}.js`; `src/utils/{sound,confetti}.js` | `src/store/preferences.js`; `src/components/Tasks/ParticipationDialog.vue`; `src/components/Scheduling/PauseSettings.vue`; `src/components/Settings/NotificationPreferences.vue`; `src/utils/minorUnits.js`. **Drift (annotated 2026-09-30, CS-PLAN-077 drafting; updated at its Standard A activation):** `src/store/preferences.js` was delivered by WP5, `ParticipationDialog.vue` and `src/utils/minorUnits.js` by WP3; WP6 adds `src/store/pauses.js` (thin client for the delivered WP2 pause API) which this row does not list; `PauseSettings.vue` ships as `src/components/Settings/PauseSettings.vue` per Q-077-03 Option A (self-only Settings section plus owner House controls), not under `Scheduling/` as this row lists. |
| WP7: catalog/request backend | `lib/Service/HouseService.php`; `lib/Db/{House,HouseMapper,HouseUserMapper}.php`; `lib/Controller/{HouseController,UserController}.php`; wallet/command/preferences services introduced above | `lib/Db/{RewardItem,RewardItemMapper,RewardRequest,RewardRequestMapper,RewardRequestEvent,RewardRequestEventMapper}.php`; `lib/Service/{RewardService,RewardRequestService}.php`; `lib/Controller/{RewardController,RewardRequestController}.php`. |
| WP8: reward UI/onboarding | `src/{router.js,App.vue}`; `src/views/Settings.vue`; `src/store/{house,user,game}.js`; `src/components/Setup/SetupWizard.vue`; `src/components/Tasks/TaskListDetails.vue` | `src/store/rewards.js`; `src/views/Rewards.vue`; `src/components/Rewards/{RewardCatalogEditor,RewardRequestList}.vue`. **Refinement (2026-10-02, CS-PLAN-079 draft):** existing preferences, `src/components/Help/`, minor-unit formatters and reset seams are M; the decimal input parser is new work in the existing utility. Add API wrappers, reset/refresh guards, contextual help and `RewardRequestDetails.vue`. Owner administration stays in Settings House; request routes are independent of browsing. Preserve delivered personal visibility defaults (`catalogVisible=true`) separately from the household-off gate; no new schema/default is inferred. No WP8 runtime implementation in this draft. |
| WP9: fixtures/gates/docs | `lib/Service/TestDataService.php`; `lib/Command/TriggerJobs.php`; `tests/integration/IntegrationTestCase.php`; `tests/fixtures/{FamilyTestData,TestDataFixtures}.php`; `tests/e2e/fixtures/{auth-contexts,database-fixtures,occ-runner,test-house}.ts`; `tests/e2e/fixtures/house-clock.js`; `tests/e2e/required-scenarios.json`; `playwright.config.ts`; `scripts/run-e2e-gate.js`; `docs/testing/release-test-plan.md`; permanent docs listed below | `docker/compose.test-matrix.yml` (isolated SQLite/PostgreSQL verification override, no replacement of the canonical MariaDB compose); test files T0-T12. |

**Shared files:** every route-owning package updates `appinfo/routes.php`,
`tests/unit/RouteMatrixTest.php` and `docs/api/README.md` in the same unit.
Every UI/domain-message package updates `l10n/en.json`, `l10n/de.json` and
`l10n/templates/chorestrike.pot`. Contract changes update the named stores and
their section 4 tests together. `TaskAccessPolicy` must understand explicit
occurrence assignees without granting helpers or house owners blanket access to
private definitions; test the existing visibility matrix on reads and mutations.
WP3/WP6 also modify `src/components/Insights/{ActivityFeed,MemberFairnessTable}.vue`:
adapt frozen multi-person work/history and remove foreign Coin/freeze statistics;
retain the collaboration activity surface, not an implicit replacement ranking.
Fixtures/gate files listed under WP9 are shared manifests: modify their relevant
rows in the package that changes behavior, then verify the complete set in WP9.

### Schema File Allocation

Modify the existing chain where fields change; new migrations below create new
feature tables only, with no old-data transforms or upgrade fallbacks. All must
produce the same final schema on a fresh install.

- M `lib/Migration/Version1000Date20260224000000.php`: house versions/feature flags
  and recurrence read-cache ownership; structural-deletion fields follow the
  selected Q-070-01 tombstone contract.
- M `lib/Migration/Version1004Date20260228131728.php`: replace membership wallet
  columns with financial identity reference, reshape medals and completion fields.
- M `lib/Migration/Version1005Date20260228160000.php`: occurrence-scoped checklist
  keys. M `lib/Migration/Version1029Date20260906000000.php`: completion references,
  revisions and undo marker, removing the before-state snapshot authority.
- M `lib/Migration/Version1027Date20260725000000.php`: remove obsolete tier,
  personal multiplier and freeze fields; retain only still-used scheduling fields.
  M `lib/Migration/Version1025Date20260719000000.php`: retire obsolete title-pack
  storage when WP4's retained rhythm catalog replaces its consumers.
- N `lib/Migration/Version1030Date20260918000000.php`: `commands`, `wallets`,
  `wallet_entries`, `work_shares`, `room_events` (WP0/WP3).
- N `lib/Migration/Version1031Date20260918000000.php`: `schedule_revs`,
  `occurrences`, `occ_members`, `agreements`, `pauses` (WP1/WP2).
- N `lib/Migration/Version1032Date20260918000000.php`: `member_prefs`
  ranking-consent slice in WP4 (Q-075-01 A); WP5 extends `member_prefs` in
  place and adds `digest_changes`, `digest_slots`.
- N `lib/Migration/Version1033Date20260918000000.php`: `reward_items`,
  `reward_requests`, `request_events` (WP7).

The schema currently has no foreign-key cascade safety net; services and tests
must enforce house-scoped referential cleanup and the approved retention policy.
Do not introduce backfills merely to make an old disposable development DB pass.

### Removal and Durable Documentation

- R in WP4: `lib/Service/{StreakFreezeService,StreakRestockService}.php`,
  `lib/BackgroundJob/{StreakFreezeJob,StreakRestockJob}.php`,
  `lib/Migration/{Version1022Date20260717000000,Version1023Date20260718000000}.php`
  once their freeze/old weekly-notification columns have no consumers. Archive
  `tests/unit/Service/{StreakFreezeService,StreakRestockService}Test.php` and
  `tests/integration/Service/StreakFreezeIdempotencyTest.php` with replacements.
- R in WP5: `lib/Service/{StreakAtRiskService,TaskDueReminderService}.php` and
  `tests/unit/Service/{StreakAtRiskService,TaskDueReminderService}Test.php` after
  the digest replaces all callers. Rewrite, do not blindly remove, notification
  browser scenarios that still assert relevant behavior. **Stale entry
  (annotated 2026-09-29, CS-PLAN-076 drafting):** `StreakAtRiskService` and its
  test were already retired in WP3 (Q-074-01 Option A); the WP5 removal covers
  only `TaskDueReminderService` and its test.
- R in WP6: `src/components/Minigames/{BoostShop,Game2048,MemoryGame}.vue`,
  `tests/unit/components/{BoostShop,Game2048,MemoryGame}.spec.js` and
  `tests/e2e/release-boost-shop.spec.ts`; replace required boost-gate entries with
  Snake-no-family-economy assertions, never merely shrink the release gate.
  **Stale entry (annotated 2026-09-30, CS-PLAN-077 drafting):** WP3 already
  retired those components/specs and the release-boost-shop spec with pinned
  Snake/no-Coin replacements (confirmed absent 2026-09-30); the WP6 remainder
  is the `SnakeLeaderboard.vue` "Boosts used" label, the dead
  `release-boost-shop` regex in `playwright.config.ts` and stale spec/config
  comments.
- R in WP4 (Q-075-02 A; formerly WP6): `src/components/Profile/TitlePicker.vue`,
  `tests/unit/components/TitlePicker.spec.js` and the old title-pack selection
  route; keep `TitleBadge` and title reads for retained rhythm recognition.
  M `src/constants/titles.js` to reflect the one retained 3/6/12 series. Remove
  obsolete config overrides (including test-seeded 1% weekly targets) from
  `RewardConfigService`/`TestDataService`; first-slice 10/80/3-6-12 policy is fixed,
  not an owner earning-formula setting. Test realistic 80% scenarios instead.
- M per owning package: `docs/engineering/{architecture,testing}.md`,
  `docs/api/README.md`, `docs/concepts/{task-model-and-sync,energy-and-balancing,gamification-and-rewards,ui-use-cases,product-vision,design}.md`,
  `docs/standards/ai/skills/gamification/SKILL.md`,
  `docs/adr/{ADR-004-multi-user-gamification,ADR-009-per-house-timezone,ADR-011-transactional-completion-and-undo,ADR-012-actionable-household-scheduling}.md`.
  Preserve historical ADR context, clearly mark superseded rules and link the new
  accepted contract instead of implying that old snapshots were correct.
- N after design acceptance: `docs/adr/ADR-013-occurrence-agreements-and-corrections.md`,
  `docs/adr/ADR-014-work-accounting-ledger-and-replay.md` (delivered by WP3
  2026-09-27 under this refined name — the reserved
  `ADR-014-family-wallet-and-redemption.md` placeholder covered WP3 accounting
  plus WP7 redemption; the delivered ADR records WP3 accounting as accepted and
  WP7 redemption as explicitly pending its own ADR),
  `docs/adr/ADR-015-consent-and-digest-delivery.md`; update `docs/adr/README.md`
  and `docs/README.md`. ADR numbering is reserved by this draft, recheck collisions
  before creation. Permanent docs integrate implemented behavior per package;
  the plan is not the only lasting description.

## 6. Implementation Order

### Work Packages and Estimates

One engineering day means approximately six focused hours by one contributor
familiar with this repository. Estimates include implementation, package-local
unit/integration/UI tests, documentation and one correction pass; they are ranges,
not promised calendar deadlines or measurements of agent runtime. Dedicated final
cross-package verification is WP9. No implementation has started.

| Package / owner | Concrete deliverable and exit condition | Dependencies | Effort |
|---|---|---|---|
| WP0 / main implementation agent; CONCEPT-001 | Freeze the shared schema/API/lock contracts; implement house-first command receipt/identity foundation plus the selected Q-070-01 tombstone deletion and Q-070-02 account-erasure plumbing; update every writer entry point and seed/reset inventory. T0 proves fresh install, auth and rollback, not just new empty tables. | **DELIVERED** — implemented 2026-09-18, final Standard B `PASS-WITH-FIXES` 2026-09-19; see [CS-PLAN-071](../done/cs-plan-071-wp0-write-identity-foundation.md) (in `done/`). WP1 may start. | 3-5 days |
| WP1 / backend agent coordinated by main; CONCEPT-001 | Persist rule revisions/occurrence identity; anchored recurrence, saved time basis, one completion target, generation/frontier and occurrence-scoped checklist; wire existing daily/calendar reads. T1 passes fixed/interval/overlap/cron-gap cases. Child plan: [CS-PLAN-072](../done/cs-plan-072-wp1-occurrence-timeline.md), including completion/client integration and the Q-072-01 Option A agreement-writer slice (`agreements` persistence plus C2 deadline/authorization/capacity checks). | **DELIVERED** — implemented 2026-09-20 (RF-06..17 resolving round + RF-18 collaborator split 2026-09-22), final Standard B `PASS-WITH-FIXES` 2026-09-23; see [CS-PLAN-072](../done/cs-plan-072-wp1-occurrence-timeline.md) (in `done/`). WP2 may start. | 7-10 days (revised 2026-09-20, Q-072-01 Option A). |
| WP2 / backend agent; CONCEPT-001 + FEAT-UX-025 + CR-SVC-64 | Handover/pause/restart state transitions; remaining shared-capacity actual/outstanding work with exact planned shares. T2/T3 plus apply races and midnight-stale 409 pass; no silent child-limit truncation or owner bypass. The minimal common agreement writer, `agreements` persistence and necessary C2 checks moved into WP1 via Q-072-01 Option A (2026-09-20). Child plan: [CS-PLAN-073](../done/cs-plan-073-wp2-agreements-capacity-pauses.md), activated 2026-09-23 (Standard A `PASS-WITH-FIXES`; Q-073-01/02/03 resolved, incl. the frozen cross-zone capacity/pause comparison); actual multi-person allocation/accounting stays WP3. | **DELIVERED** — final Standard B `PASS-WITH-FIXES` 2026-09-25 (RF-01..28 resolved in the R0-R8 fix round; CR-SVC-64 closed); child plan in `done/`. WP3 may start. | Original 5-7-day allowance; fix/closure round completed 2026-09-25. |
| WP3 / backend agent; CONCEPT-001 + CR-SVC-63 | Effort-only multi-person ledger, negative wallets, targeted undo/attribution, full room mutation journal including reset; remove arcade mint/spend in the same economy cutover. T3/T4/T10 conserve sums and preserve later independent work across real races. Child plan: [CS-PLAN-074](../done/cs-plan-074-wp3-work-accounting-and-replay.md), activated 2026-09-25 (Standard A `PASS`); Q-074-01 Option A moves the minimum rhythm/weekly/retained-recognition correction slice, its coupled legacy-effect retirement and T5 into WP3; Q-074-02 Option A lets the completer or authorized owner correct a false completer attribution with the audit actor/time immutable. | **WP3 DELIVERED** (final Standard B `PASS-WITH-FIXES` 2026-09-27, child in `done/`; RF-074-WP3-01..10 resolved; CR-SVC-63 closed; ADR-014 recorded). | 10-16 days (7-11-day parent allowance plus the 3-5-day selected correction-closure slice). |
| WP4 / backend agent; CONCEPT-001 + CR-053-11 | Planned-day rhythm, exact weekly goal, retained/correctable recognition and consent-filtered ranking; retire freezes/restock/old medals and title formulas. T5/T8 pass; GET progress has no award side effects. The minimum correction-closure slice, its coupled legacy-effect retirement and T5 moved into WP3 via Q-074-01 Option A (2026-09-25); this row keeps the remaining full build-out. Child plan: [CS-PLAN-075](../done/cs-plan-075-wp4-rhythm-goals-and-ranking.md), activated 2026-09-27 (Standard A `PASS-WITH-FIXES`; Q-075-01/02 Option A). Manifest drift recorded there: `RhythmService`/`WeeklyGoalService` exist since WP3 (M, not N), and the WP4 removal entry for `Version1022`/`Version1023` is stale — 1022 is already a no-op placeholder and 1023 is the live weekly-goal receipts table; neither is removed. | **WP4 DELIVERED** (final Standard B `PASS-WITH-FIXES` 2026-09-29, child in `done/`; RF-075-01..05 resolved in the in-review fix round; ADR-015 recorded; CR-053-11 freeze/tiebreaker remainders resolved without closure). | 5-9 days (4-7 plus 1-2 consent) |
| WP5 / backend agent; FEAT-UX-024 + CONCEPT-001 | Self-only preferences, DST-aware quiet schedule, transactional/coalesced organization queue and one bounded at-most-once combined digest; pressure notifications retired. Child: [CS-PLAN-076](../done/cs-plan-076-wp5-preferences-and-digest-delivery.md), final Standard B `PASS-WITH-FIXES` 2026-09-30, RF-076-01..11 resolved; T7 and non-ranking T8 evidence green, ADR-016 integrated. | **WP5 DELIVERED**; parent cross-DB/family evidence and WP6 presentation remain with their existing packages. | 5-8 days |
| WP6 / frontend agent coordinated by main; CONCEPT-001 + FEAT-UX-023/024/025 | All-age My Day, agreements/participation/pause UI, calm feedback, opt-ins, accessible ranking, Snake-only area and no coin boosts/prototypes. T9/T10 and core browser flows pass with EN/DE artifacts and shared-device reset. Child plan: [CS-PLAN-077](../done/cs-plan-077-wp6-calm-core-ui.md), activated 2026-09-30 (Standard A `PASS-WITH-FIXES`; Q-077-01..05 all Option A). | **WP6 DELIVERED** (final Standard B `PASS-WITH-FIXES` 2026-10-01, child in `done/`; RF-077-01..09 resolved; FEAT-UX-024/025 closed; FEAT-UX-023 keeps its WP8 tour/catalog remainder; durable docs: `docs/concepts/calm-core-ux.md`). | 5-8 days |
| WP7 / backend agent; FEAT-UX-026 | Optional catalog and complete C4 request lifecycle, aggregate reservation hold, departure/rejoin/role changes and private reads; no family value before core gate G1. T6 proves state/role/race matrix and wallet sums. Child plan: [CS-PLAN-078](../done/cs-plan-078-wp7-catalog-and-request-backend.md), activated 2026-10-01 (Standard A `PASS`, zero findings); includes role revision, current-authority receipt guard, erasure of active/departed subjects and reward digest relevance. | G1 (WP0-6 delivered); **DELIVERED 2026-10-01** — final Standard B `PASS-WITH-FIXES` (schema Version1033 + role revision, RewardService/RewardRequestService with the WP7 receipt guard, full C4 lifecycle + erasure/digest integration, 94-route pin, docs/ADR-017/l10n; unit 1116, Jest 1118, integration 529, full E2E gate PASSED). | 6-9 days |
| WP8 / frontend agent; FEAT-UX-026 + essential FEAT-UX-023 slice | Catalog administration, child request/owner approval, adult flow, cancellation/false-fulfillment correction and persistent request access; onboarding explains Nextcloud accounts, shares and optional features. T11 passes desktop/mobile/keyboard flow. Child: [CS-PLAN-079](../active/cs-plan-079-wp8-reward-ui-and-onboarding.md), drafted 2026-10-02 with exact-money input, paging, uncertain-response/conflict handling, shared-device reset and essential-help scope. | WP7 delivered; **WP8 ACTIVE** (Standard A `PASS-WITH-FIXES` 2026-10-02); implementation not started. | 5-8 days in the detail plan (original parent allowance: 3-5). |
| WP9 / main + independent reviewer + voluntary family testers; CONCEPT-001 | Integrate fixtures and gate manifest, cross-DB schema/race checks, two clean browser gates, durable docs and anonymized C5 evidence; resolve all owning findings before final review. Technical green alone does not finish family evidence. | WP0-8 and available consenting testers. | 4-6 engineering days plus 14 calendar days of voluntary use |

**Total:** 48-76 engineering days (288-456 focused hours), plus the two-week
family observation interval. Allow roughly 20-25% contingency for cross-contract
corrections: a planning envelope of about **58-95 engineering days**. The program
is **XL**, not the former M-sized decision exercise. The estimate must be revised
if the two open retention decisions expand privacy/deletion behavior; large
additional scope is not silently absorbed. Parallelism can shorten elapsed time,
not remove integration or family-test gates.

The total above retains the original program estimate; child detail estimates
refine its package allowances, including WP8's 5-8 days rather than the original
3-5. It is not a recalculated aggregate of the later child estimates.

### Dependency and Gate Sequence

1. **G0: design/activation — satisfied 2026-09-18.** Q-070-01/02 were resolved
   through the batched Standard A review questions and the review of sections
   3-6, actor matrix, DB portability and required tests passed with
   `PASS-WITH-FIXES`; the plan moved to `active/` (see Review Outcome).
2. **Core spine (updated Q-075-01 A, 2026-09-27):** WP0 -> WP1 -> WP2 -> WP3 ->
   WP4 -> WP5 -> WP6. WP4 owns real ranking consent persistence in Version1032;
   WP5 extends it in place for delivery preferences. The prior WP5-first
   dependency is superseded; a mock contract is not an independent package exit.
   WP6 may prepare presentation against frozen DTO
   fixtures, but integrates the backend before its exit. Main owns synthesis and
   shared-file integration;
   no visible Agent Manager sessions are requested or created by this plan.
3. **G1: reliable core.** WP0-6, T0-T5/T7-T10 and corresponding browser paths
   pass; no old arcade wallet writer, freeze path, snapshot restore or assignment
   bypass remains. Required docs/localization/build gates are green. Only then
   may real-world reservation/fulfillment behavior be enabled in WP7.
4. **G2: complete optional catalog.** WP7-8 and T6/T11 pass with current-role,
   financial-race and disabled-catalog request-access proofs. Catalog remains
   off by default; basic planning is tested with it permanently off.
5. **G3: final evidence.** WP9 + T12, two-week voluntary use and final Standard B
   or Full review. Missing family evidence leaves the plan incomplete; explicit
   user rescoping is required to remove that gate. Only the final passing review
   performs `active/ -> done/` and archives fully completed backlog owners.

### Backlog Ownership and Existing Findings

| Owner / finding | Package disposition |
|---|---|
| CONCEPT-001; RF-070-01/02/03/04/06/07/08/09/11/15 | Core occurrence/agreement/accounting/rhythm/UI implementation in WP0-6; declaration of intended behavior is not closure. |
| CR-SVC-63; RF-070-08 room portion | **CR-SVC-63 CLOSED 2026-09-27** (WP3 final Standard B): the manual adjust runs through journaled `room.manual_delta` commands with ordered replay evidence and real race coverage; RF-070-08 resolved with the WP3 delivery. No parallel backlog item was created. |
| CR-SVC-64 | WP2 supplies the existing midnight-crossing stale-preview 409 regression when replacing the preview contract; retain its owner until that case passes. |
| CR-053-11; RF-070-05 | WP4 removes freeze/restock consumers and replaces tests, rather than repairing obsolete mechanics. Keep any independent Snake leaderboard ordering remainder open until its own acceptance passes. |
| FEAT-UX-024/025; RF-070-10 | WP2/WP5/WP6 implement dated pauses and quiet opt-in delivery with UI; archive only after all corresponding requirements pass. |
| FEAT-UX-026; RF-070-13 | WP7-8 implement the selected catalog; WP3/WP6 separate and reduce arcade. Task Pass/extra games are not catalog prerequisites. |
| FEAT-UX-023; RF-070-12 | WP6/WP8 deliver essential account/role/rhythm/catalog help. Wider tour/glossary scope remains with FEAT-UX-023 unless explicitly included; do not claim whole-item closure from a small hint. |
| FEAT-UX-018/019 | General chore approval and Task Pass remain outside the approved first slice. No runtime work, new dependency or closure implied. |
| RF-070-14 | Already resolved documentation finding; do not reopen or use it as evidence that runtime targets pass. |

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|---|---|---|
| Replacing one complicated game with another | High | Recognition first; one optional currency; no new combo/freeze/collection layer by default. |
| Daily pressure survives in medals, notifications or rankings | High | Review the whole experience against the same obligation model, not just the streak counter. |
| Reward shop gives unreliable accounting real-world meaning | High | D2 and interleaved-event tests before redemption; separate arcade and chore value. |
| "Fixed day" and "flexible" mean different things in different views | High | One occurrence/window contract with explicit exceptions and shared test timelines. |
| Parents become supervisors of every click | Medium | Approved immediate completion; owner approval for child rewards/pauses, no general chore-approval queue in this slice. |
| Child account/onboarding burden prevents family adoption | Medium | Document real Nextcloud account requirements; test actual child/adult use and shared-device sign-in. |
| Concept plan becomes another backlog or silently changes old decisions | Medium | One CONCEPT-001 owner, existing add-on owners retained, approved core/C1-C5 answers separated from exact technical planning; no code or activation in this round. |
| House-wide serialization causes long lock holds | Medium | Bound planner input to its existing 100 rows, generate history in deterministic batches, index date/member/source queries and measure T0/T4 contention. No network calls inside transactions; do not weaken correctness to hide a slow replay. |
| A house-reset or structural deletion destroys replay evidence | High | Journal the known production reset as an absolute room event; implement the selected Q-070-01 tombstone retention before changing cascade deletion; test undo on both sides of a reset. |
| Retained financial identity outlives lawful account retention | High | Q-070-02 selected erasure/anonymization (2026-09-18); ordinary departure is not an erasure policy. Test deletion/recreated UID across every reference in T0/T6. |
| Notification timeout is mistaken for a safe retry | High | Durable at-most-once dispatch claim; uncertain post-claim result consumes the slot, no speculative exactly-once claim or duplicate next-day retry. |
| Required human evidence is unavailable | Medium | Keep C5 family gate open, no invented results or contact/telemetry collection. Observation adds calendar time, not an excuse to mark WP9 complete. |

## 8. Acceptance Criteria

- [x] User direction, current behavior and proposed options are distinguished.
- [x] Verifiable documentation drift is corrected without runtime changes.
- [x] Core choices for D1-D11 approved and recorded in 24 explicit answers (2026-09-17).
- [x] Six C1 timeline choices approved and documented with acceptance scenarios (2026-09-17); implementation and cross-contract dependencies remain open.
- [x] Eleven C2 agreement/capacity/pause lifecycle choices approved with acceptance scenarios (2026-09-17); C3/C5 cross-contract dependencies remain explicit.
- [x] Nine C3 accounting/recognition/correction choices approved with design consequences and future acceptance scenarios (2026-09-17); technical mapping drafted below, runtime remains open.
- [x] Eight C4 actor/catalog/membership/privacy/correction choices approved with a durable state matrix and future scenarios (2026-09-17); technical mapping drafted below, runtime remains open.
- [x] Thirteen C5 delivery/UX/consent/family-protocol choices approved with future scenarios (2026-09-17); no family trial or runtime verification performed.
- [x] All presented core and C1-C5 product questions answered explicitly; no silent approval of unasked details.
- [x] Technical draft specifies C1-C5 persistence, APIs, locks/races, reset replay, delivery failure boundaries and exact package/test manifests (2026-09-18); not a review approval.
- [x] Q-070-01/02 resolved explicitly and integrated (2026-09-18: tombstone retention; account erasure); Standard A accepted the resulting cross-contract design (`PASS-WITH-FIXES`, 2026-09-18).
- [ ] Fixed dates, interval recurrence, completion windows and recovery use one definition.
- [x] Every implementation package WP0-WP9 has an explicit owner, dependencies, file manifest, effort range, exit gate and required test assignment; conditional retention branches are marked.
- [ ] Approved changes satisfy the section 4 scenarios, including child/off-day cases.
- [ ] Required tests, lint, documentation and applicable build/E2E gates pass without new/unapproved skips.
- [ ] Every section 9 finding is resolved or explicitly user-rescoped; existing backlog owners reflect real completion state.
- [ ] Durable rules are in concepts/ADRs/engineering/API docs, not only this plan.
- [ ] Family usability evidence is recorded without sensitive data.
- [ ] Final Standard B or Full review passes; tracking and completion archive updated.

## 9. Review Findings (Plan-Owned)

Locations below refer to the baseline revision, not line positions after doc edits.
These are follow-up obligations, not claimed fixes; existing add-on implementation
ownership remains with the cited backlog item.

- [ ] **RF-070-01 major:** `HouseUser.php:194-212`, `StreakFreezeService.php:84-113`: available-day activity is not obligation/rhythm continuity (D3/D4).
- [ ] **RF-070-02 major:** `RoomTask.php:342-393`: fixed-weekday completion skips/repeats occurrences at early/late/adjacent-day boundaries (D1). **WP1 portion DELIVERED (CS-PLAN-072 WP1, ADR-013)** — the durable occurrence timeline replaces `RoomTask` recurrence math: fixed-cadence occurrences are enumerated on calendar dates with query-independent ordinals, early/late work never moves a fixed anchor (interval rules anchor the successor on the real completion work date), and a completion fulfills exactly the one shown occurrence under a version/token receipt. The mutable `next_due` advance the finding cites is now a derived projection. Broader rhythm/streak continuity stays with RF-070-01 and later packages.
- [ ] **RF-070-03 major:** `SchedulePlanService.php:549-582`: legal-date overrides and all-participant capacity constraints are not consistently enforced (D1/D6).
- [ ] **RF-070-04 major:** `RoomTask.php:132-136,447-469,756-777`: flex/overdue/availability semantics differ; recovery of expired legal windows is undefined (D1/D4). **WP1 portion DELIVERED (CS-PLAN-072 WP1, ADR-013)** — occurrences carry saved half-open `[window_start, window_end)` with planned-day start, per-occurrence early/late grace and stored timezone; selection is the earliest open window, else the latest uncovered miss (`late` catch-up), else voluntary; completing current work covers (never fulfills) the older miss, so expired-window recovery is now defined. Availability/capacity balancing stays with RF-070-03 and WP4 rhythm.
- [ ] **RF-070-05 major:** `GameLogicService.php:144-156`: D4 selects removal of freezes/restock in favor of dated pauses. Retire the old paths and replace coverage, coordinated with CR-053-11; the selection itself does not fix the running implementation.
- [ ] **RF-070-06 major:** `HouseService.php:466-482`: distinct-task weekly goal does not measure fulfilled due occurrences (D5).
- [x] **RF-070-07 major:** `CompletionService.php:230-259`, `GameLogicService.php:115,261`, `Task.php:296-301`: early reward uses advanced due date; stored tier config is not the payout authority. D7 resolves the target by removing these bonus/tier paths, not repairing their obsolete formulas. **RESOLVED (CS-PLAN-074 WP3, final Standard B `PASS-WITH-FIXES` 2026-09-27)** — the bonus/tier/multiplier paths are deleted, not repaired: `GameLogicService` reward orchestration retired (349→54 lines), `RewardConfigService` economy knobs pruned, and the frozen `rate_minor` on each completion is the sole payout authority (ADR-014).
- [x] **RF-070-08 major:** `CompletionEffectService.php:121-131`, `CompletionService.php:419-453`: snapshot undo can overwrite unrelated later room/wallet/contribution changes (D2). **RESOLVED (CS-PLAN-074 WP3, 2026-09-27)** — `CompletionEffectService` and the snapshot-restore model are deleted; undo/attribution target an exact completion and re-derive every dependent projection from valid history (exact wallet union deltas, room journal replay with requested effects, recognition recompute), so unrelated later room/wallet/contribution changes are never overwritten; the manual-adjust journal remainder closed with CR-SVC-63.
- [ ] **RF-070-09 major:** `MedalService.php:124-128`, `GameLogicService.php:124-140`, `MinigameService.php:149-164`: implement the approved occurrence/effort fairness model in place of raw-activity/solo-combo recognition, completer-only shared rewards and mixed arcade/chores value (D5-D8). **WP3 portions DELIVERED (CS-PLAN-074, 2026-09-27)** — actual multi-person shares with frozen effort/rate replace completer-only shared rewards; the wallet ledger is the sole monetary authority with contribution derived from selected work shares; the arcade is scores-only (no chore value); retained recognition re-derives from valid history (rhythm 3/6/12 + weekly milestone, corrected medals reconciled). Remaining D5-D8 rhythm/ranking consent aspects stay with WP4/WP5.
- [x] **RF-070-10 major:** resolved by CS-PLAN-076 WP5 (final Standard B `PASS-WITH-FIXES` 2026-09-30): legacy task/at-risk and award/title/takeover bells are retired; self-only opt-ins, shared membership/day claim, current relevance, recognized-pause suppression, quiet hours and bounded at-most-once outcomes are implemented with full regression/concurrency/browser evidence. FEAT-UX-024/025 stay open only for their remaining presentation work.
- [ ] **RF-070-11 major:** `Dashboard.vue:36,45-58`, `src/store/user.js:53-55`, `RoomTaskReadService.php:418-451`: calm/ranking choice and whole-child-workload boundaries missing; military greeting remains live (D6/D10).
- [ ] **RF-070-12 major:** `AuthorizationService.php:59-61`, `SetupWizard.vue:683`, `CompletionService.php:396-405`: implement clear account/role onboarding and the selected responsibility/reward/pause boundaries. Local child profiles and general chore approval are explicitly outside D11, not missing first-slice features (FEAT-UX-018/023).
- [ ] **RF-070-13 major:** selected family catalog remains unimplemented (FEAT-UX-026); remove/hide unsupported game surfaces for the approved Snake-only slice. Task Pass is explicitly deferred outside this slice (D11; FEAT-UX-019), not a blocker requiring its implementation. **WP3 arcade portion DELIVERED (CS-PLAN-074, 2026-09-27)** — the unsupported 2048/Memory prototypes and the BoostShop surface are retired with pinned supported-Snake/no-Coin replacements; Coin mint/read/boost routes are gone. The selected catalog itself stays with WP7-8 (FEAT-UX-026). **WP7 backend portion DELIVERED (CS-PLAN-078 final Standard B `PASS-WITH-FIXES`, 2026-10-01)** — the owner-gated catalog and the full C4 request lifecycle exist behind the API with ADR-017; no UI yet (WP8/FEAT-UX-026 remains open).
- [x] **RF-070-14 minor:** objective doc drift corrected: external-client completion, 1-9 energy, shared bonus/config scope, weekly goal meaning, undo delta claim, frontend-only role guard, digest and three fully supported games; foundational/ADR/skill summaries now defer to current mechanics.
- [ ] **RF-070-15 major:** `TitleService.php:57-74,198-210`, `TitleCatalog.php:145-149`: Cozy Home qualification uses current spendable Coins, so purchases can remove titles. C3 replaces the wallet/effort series with retained 3/6/12 rhythm recognition; implement that replacement and genuine-correction semantics, not a repair of the obsolete wallet-title formula. **WP3 recognition portion DELIVERED (CS-PLAN-074, Q-074-01A, 2026-09-27)** — the wallet-qualified cozy-home pack is retired; `TitleCatalog` keeps the retained rhythm 3/6/12 series evaluated from valid-history streaks, and corrections reconcile awards (a corrected record shrinks only to the corrected valid-history maximum). The parent's final review owns the whole-item verdict.
- [ ] **RF-070-16 major:** structural deletion/history retention; Q-070-01 selected tombstoned minimum-history retention (2026-09-18). **WP0 portion DELIVERED (CS-PLAN-071 final Standard B `PASS-WITH-FIXES` 2026-09-19)** — tombstone columns + re-keyed uniqueness, services/read filters/undo-across-tombstones, `deleteRoomTaskDependents` → evidence-retaining `deleteRoomTaskPlanningState`, authoritative under-lock tombstone gates on every structural mutation surface (RF-071-03); the WP3 `room_events` replay half stays open for WP3/T4. **WP3 portion DELIVERED (CS-PLAN-074, 2026-09-27)** — `room_events` journals creation baselines, completion effects, manual deltas and absolute resets with `(effective_at, id)` ordered replay; tombstoned structures are never reactivated by correction, and erasure anonymizes the new share/revision/entry actor references. The retention contract is complete for WP3's tables; the item stays open for any later-package surfaces.
- [ ] **RF-070-17 major:** true account erasure versus ordinary departure; Q-070-02 selected erasure/anonymization (2026-09-18). **WP0 portion DELIVERED (CS-PLAN-071 final Standard B `PASS-WITH-FIXES` 2026-09-19)** — `AccountErasureService` + `UserDeletedListener` wired in `Application.php`, wallet/ledger purge, receipt/completion actor anonymization, "Former member" placeholder, T0 erasure matrix incl. no-rebinding; the WP7 reservation/wish/digest surfaces extend it when they exist. **WP3 portion DELIVERED (CS-PLAN-074, 2026-09-27)** — erasure now also purges/anonymizes the WP3 evidence: work-share wallet references, completion-revision actors, wallet-entry actor/wallet keys and room-event command provenance; departed-member wallets survive ordinary departure without access, and same-UID non-rebinding is pinned for the new tables. **WP7 portion DELIVERED (CS-PLAN-078 final Standard B `PASS-WITH-FIXES`, 2026-10-01)** — erasure now also releases/cancels the person's reward holds, purges their reward wishes/requests/events, removes queued `reward_request` references from ALL recipients and anonymizes their actor references on other retained records (wallet-id provenance finds already-departed actors); pinned in the erasure suites.

### Activation Decisions Exposed by the Technical Inventory

Both questions were first presented together on 2026-09-18; that question batch
returned **dismissed, with no selected answers**, and dismissal was neither
consent nor rejection. During the Standard A review on 2026-09-18, the batched
review questions were answered explicitly: **Q-070-01 retain minimum history
(tombstone)** and **Q-070-02 erase/anonymize on account deletion**. The
conditional branches below are resolved accordingly; implementation ownership
stays with WP0/WP3/WP7 and tests T0/T4/T6.

| ID / decision owner | Evidence and concrete options | Status / required integration |
|---|---|---|
| Q-070-01 / product owner: deleting a task, room or floor with valid history | Current cascades remove `completed_tasks`; retained balances alone would lose their correction evidence. Selected (2026-09-18): remove from active planning while retaining minimum historical object/occurrence references, existing visibility and valid earned effects. Alternative rejected: block deletion when history exists and permit deactivation only. Do not silently reverse correct work or erase ledger entries. | **RESOLVED 2026-09-18: retain minimum history (tombstone).** Add deleted-at/tombstone state and adapt Floor/Room/RoomTask services, history reads and undo authorization; structural deletion removes objects from active planning only. T0/T4 must cover tombstoned deletion and deletion bypass attempts. |
| Q-070-02 / product owner: true Nextcloud account deletion/privacy erasure | There is no existing deletion listener. C4e approves ordinary leave/rejoin retention only. Selected (2026-09-18): remove account binding, personal finances/wishes/preferences/awards, release active reservations, and keep only minimum non-reidentifiable shared work/room facts needed for the remaining household. A later same-named account must not recover prior data. Alternative rejected: keep privacy policy undecided with activation blocked. | **RESOLVED 2026-09-18: erase/anonymize on account deletion.** Specify nullable/tombstoned actor/share references and purge identifiers from agreement snapshots, command receipts, digest records and audit reasons as well as primary tables; wire the verified Nextcloud deletion event in `Application.php`. T0/T6 must prove no old UID rebinding or personal read leakage. Merely deleting `house_users` is insufficient. |

Standard A must also validate the engineering choices in sections 3-6, especially
the all-writer lock transition, occurrence agreement frontier, schema portability,
bounded replay cost and at-most-once dispatch tradeoff. These are proposed technical
contracts, not additional user answers or passing runtime findings. The 2026-09-18
Standard A review validated these choices against the runtime inventory with no
new findings; see Review Outcome below.

## Review Outcome

**Implementation readiness: BLOCKED pending Q-070-01/02 and Standard A.** The
original four read-only domain tracks informed the clarification draft; evidence
and limitations are in the
[review record](../../backlog/archive/CODE_REVIEW_2026-09-16.md#family-first-concept-review).
The 2026-09-17 product sessions approved 24 core and 47 C1-C5 answers
(6/11/9/8/13). On 2026-09-18, three read-only code-inventory tracks supported the
technical specification in sections 3-6: schema/APIs, transactions/races, exact
file/test manifest, WP0-WP9 and 48-76 engineering-day estimate. This is not a
second formal review verdict. Two new retention questions received no answer;
their conditional branches remain explicit. Runtime findings remain implementation
obligations, not prerequisites to fix before starting implementation once G0
passes. No activation, final code-review verdict, automatic commit or plan
completion is implied. The original review record remains a historical snapshot.

### Standard A Review Outcome (2026-09-18)

**Tier/Outcome: Standard A (plan) — `PASS-WITH-FIXES`.**

- **Scope:** this plan file (1243 lines pre-review, untracked), SHA256
  `A84AC15790AA034E9099D124C38DDE1F15272FD6BAED349ADE640A41FC12D2B8`;
  base `master` @ `1946166618d591eba54bd42d1d338030389e994e`; empty index;
  unstaged-diff fingerprint of the 20 unrelated/plan-owned dirty files
  `352486442C8A4B3151DF85EF97D74A0BEB8214D4F18A400265527384FACB01CA`
  (preserved; the three `.gitkeep` deletions stay excluded per section 5).
- **Verified:** template sections 1-9 and header complete; no overlap with an
  active plan (`active/` empty); every referenced backlog ID open; migration
  chain correct (6 `M` files exist, `Version1030-1033` free), ADR-013/014/015
  reservations free; all `M` services/jobs/routes/fixtures/test files and gate
  scripts exist; baseline code claims spot-checked (`RoomService.php:216` delta,
  `:193` evidence-deleting cascade; `HouseService.php:172-185`;
  `HouseUser.php:194-212`); house-first lock supersession, fresh-install
  migration policy, closed C4 actor matrix and the 67-scenario T0-T12 mapping
  assessed as adequate.
- **Findings:** none new (0 block / 0 major / 0 minor / 0 nit).
- **Decisions (batched question tool, answered 2026-09-18):** Q-070-01 retain
  minimum history (tombstone); Q-070-02 erase/anonymize on account deletion;
  activation commit includes the full section-5 documentation package,
  excluding the unrelated `.gitkeep` deletions.
- **Fixes applied:** decision integration across header, §2, technical coverage
  table, §3.5, §5 (WP0/schema), §6 (WP0/G0), §7 risks, §8 acceptance criteria
  and §9; RF-070-16/17 reclassified `block` → `major` (their contracts are now
  selected; only implementation remains).
- **Gates:** `npm run backlog:check`, `npm run docs:encoding`,
  `git diff --check` green (documentation-only round; runtime suites unchanged
  and not required). Review record:
  [CODE_REVIEW_2026-09-18.md](../../backlog/archive/CODE_REVIEW_2026-09-18.md).
- **Transition:** `new/ → active/` with this review's scoped commit;
  CONCEPT-001 set `In Progress`.
