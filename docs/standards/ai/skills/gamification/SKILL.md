---
name: gamification
description: ChoreStrike gamification domain model — sparks, combos, medals, energy, streaks, and fairness rules
---

# Skill: Gamification Domain

**Load when:** Implementing or modifying reward mechanics, scoring, combos, medals, streaks, energy balancing, or role titles.

**Canonical references:**
- [`gamification-and-rewards.md`](../../../../concepts/gamification-and-rewards.md)
- [`energy-and-balancing.md`](../../../../concepts/energy-and-balancing.md)
- [ADR-004: Multi-User Gamification](../../../../adr/ADR-004-multi-user-gamification.md)
- [ADR-005: Smart Scheduling](../../../../adr/ADR-005-smart-scheduling.md)

---

## Core Systems

### 1. Contribution Score (permanent)
- Lifetime points reflecting actual work done.
- Formula: `energy_cost * 10 * priority_multiplier * cleanliness_multiplier`
- Never decreases. Used for role title progression.

### 2. Team Sparks (renewable currency)
- Earned on task completion with combo bonuses.
- Encourages rapid, collaborative completion.
- Family-friendly terminology — never "points" or military terms.

### 3. Weekly Medals (recognition)
- 5 types: Team Anchor, Flow Keeper, Room Guardian, Team Player, Streak Keeper.
- Awarded weekly, reset weekly. Recognizes diverse play styles.

### 4. Combos
- Consecutive task completions within a time window multiply sparks.
- Managed by `ComboService`.

### 5. Energy Model
- Each task costs 1–5 energy (~5 min per unit).
- Members define daily energy budgets and presence days.
- Scheduler distributes tasks against capacity; never assigns on unavailable days.

## Backend Services

| Service | Responsibility |
|---------|---------------|
| `GameLogicService` | Core reward calculation on task completion |
| `ComboService` | Combo tracking and multiplier windows |
| `MedalService` | Weekly medal evaluation and awarding |
| `UserStatsService` | Contribution score, spark balances, streaks |
| `RewardConfigService` | Configurable reward formulas per house |
| `SmartSchedulerService` | Energy-aware task distribution |

## Persistence

| Entity/Mapper | Stores |
|---------------|--------|
| `UserStats` / `UserStatsMapper` | Per-user contribution, sparks, streak data |
| `Combo` / `ComboMapper` | Active combo state per user |
| `Medal` / `MedalMapper` | Awarded medals (user, type, week) |
| `CompletedTask` / `CompletedTaskMapper` | Completion history for scoring |
| `AvailabilitySettings` / `AvailabilitySettingsMapper` | Presence days, energy budgets |

## Design Rules

- **No punishment loops.** Missing tasks never subtract sparks or break streaks punitively.
- **Absence-safe:** unavailable days never break streaks or count as missed.
- **Cooperative > competitive:** shared progress bars, team streaks, weekly team goals.
- **Configurable formulas:** reward calculations are policy, not hardcoded constants.
- **Family-friendly language:** no military/aggressive terms. Use "Team Sparks", not "points" or "XP".
- **Fairness normalization:** compare contribution relative to available capacity, not raw counts.

## Role Titles (localized packs)

3 built-in packs with 3 progressive tiers each (low/medium/high contribution). All packs must be family-friendly. See [`gamification-and-rewards.md`](../../../../concepts/gamification-and-rewards.md) for current packs.

## When Modifying Gamification

1. Check ADR-004 and the concept docs for locked decisions.
2. Keep formulas in `RewardConfigService` — don't hardcode in `GameLogicService`.
3. Update `UserStats` calculations atomically (contribution + sparks + streak in one transaction).
4. Add regression tests for edge cases: absence days, combo expiry, weekly medal reset boundaries.
5. Verify fairness: no single strategy should dominate all medal types.
