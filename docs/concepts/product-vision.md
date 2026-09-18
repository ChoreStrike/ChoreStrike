# Product Vision

## Mission

ChoreStrike helps families and shared homes get necessary, often tedious chores
done with less organizing and reminding. Planning is the product; playful
recognition supports it rather than becoming another obligation.

The intended experience is a cleaning companion with explicit household
planning: agree who does what and when, see a manageable list, complete the
work, and recognize reliable participation. Sweepy is a product reference,
not a feature-parity commitment.

## Product Direction (2026-09-16)

- Family-first: useful to children and adults, including mixed abilities,
  alternating presence days, and households with little spare time.
- Keep chosen days or a self-defined recurrence rhythm. Daily chores are
  supported, but daily activity is never a requirement for every member.
- Reward reliability, not app visits, speed of tapping, or doing unnecessary
  extra chores to protect a counter.
- Be calm: no guilt-based streak threats or repeated engagement prompts.
  An empty planned day should feel complete, not like lost progress.
- Free, open-source Nextcloud app (MIT), without a paid planning tier.
  This is not a promise of free Nextcloud hosting or a standalone mobile app.

These are the requested product goals. The exact streak, reminder and reward
rules are not all implemented or decided; see the current-state distinction
below and the [clarification plan](../plans/new/cs-plan-070-family-rhythm-and-reward-clarification.md).

## Design Principles

1. Motivation over punishment.
2. Cooperative progress over individual pressure.
3. Fairness over raw output.
4. Practical utility first, playful layer second.
5. Mobile-friendly, low-friction use inside Nextcloud; external task sync is not shipped.
6. **Simplicity for all ages**: The interface must be intuitive and accessible for children, seniors, and everyone in between. No complex workflows, minimal steps to complete tasks, clear visual feedback.

## Core Product Outcomes

- Households agree recurring chores, responsibilities and realistic due windows.
- Every member gets a manageable share based on capacity and availability.
- Planning clearly distinguishes a fixed weekday from an interval after completion.
- Progress recognizes keeping that agreement, with neutral recovery after illness,
  absence, overload or a missed task.
- Useful work and optional rewards remain separate: the app still helps a family
  that does not want coins, rankings or minigames.

## Non-Goals

- No punitive mechanics or hard failure states.
- No dependency on external SaaS services.
- No mandatory competitive gameplay.
- No daily-login obligation, purchased streak protection, or paid advantages.
- No requirement to use a reward shop to benefit from planning.

## Terminology Baseline

- Use "energy" as the effort unit.
- Avoid military resource terms for rewards (for example, avoid "ammo").
- Use "Coins" as the reward currency name.

## Current Implementation Versus Target

| Area | Current behavior | Remaining product gap |
|---|---|---|
| Planning | Recurrence, fixed weekdays, flex windows, availability, energy budgets, owner preview/apply, Calendar and My Day exist. | Clarify strict dates versus preferences, exceptions and recovery; do not rebuild the planner. |
| Family use | Nextcloud-account membership, owner/member/child roles, child My Day and planning limits exist. | Account provisioning/shared devices, optional work versus obligations, and child-safe overload recovery need explicit guidance. |
| Streaks | Available-day activity streaks with freezes. | Not yet a streak of on-time planned obligations; being available does not mean a chore is due. |
| Rewards | Coins, separate contribution, personal combos, medals, titles, weekly goal and Snake rewards/boosts exist. | Approved effort-only, shared-allocation and optional catalog model is not implemented; technical integration remains. |
| Calm UX | Completion feedback and server notifications exist. | No comprehensive quiet mode, notification preferences or ranking opt-out; current UI still contains pressure-oriented language. |
| Completion | ChoreStrike UI and authenticated app API. | No Tasks/CalDAV client synchronization; the former stub was removed. |

## Existing Baseline, Not a New Reward Decision

- Coins is the existing currency name. Contribution and spendable coins are
  separate; the existing personal multiplier is not an automatic parent/child
  role multiplier.
- On 2026-09-17 the user chose planned-day streaks, neutral dated pauses instead
  of freezes, effort-based Coins without multipliers, retained recognition and an
  optional family reward catalog. The arcade is separate from the family economy.
  These are approved target rules, not implemented behavior; see the concept's
  dated decision sections and the clarification plan for remaining details.
- The approved default experience is planning-first: tasks/next appointments,
  small positive progress feedback, optional arcade/catalog, sound initially off
  and controllable animation. Rankings are initially off and include only
  voluntary participants, including a child's right to decline/leave. Shared
  activity history and private-task visibility remain separate contracts.
- C5 now specifies My Day entry for all ages, quiet feedback without default
  sound/confetti, personal cross-device opt-ins, one optional shared daily digest,
  prospective voluntary plan-fulfillment rankings and a future comprehension test
  plus two-week family trial. See the [UI/evidence contract](ui-use-cases.md#approved-calm-ux-and-evidence-c5-2026-09-17)
  and [delivery contract](task-model-and-sync.md#approved-delivery-contract-c5-2026-09-17).
  These decisions do not claim runtime completion or already collected evidence.
- [Gamification and rewards](gamification-and-rewards.md) separates current
  mechanics from proposed options. [Task model](task-model-and-sync.md) and
  [energy and balancing](energy-and-balancing.md) describe the existing foundation.
