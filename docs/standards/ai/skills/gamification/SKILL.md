---
name: gamification
description: ChoreStrike gamification domain model — coins, combos, medals, energy, streaks, and fairness rules
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
- Incremented by the task's energy cost on each completion (`GameLogicService::calculateContributionScore`).
- Never decreases. Used for role title progression.

### 2. Coins (renewable currency)
- Earned on task completion, with combo and early-completion bonuses.
- Formula (per task): `energy × baseRate × taskTierMultiplier × personalMultiplier × earlyMultiplier`, doubled on combo (see `GameLogicService::calculateCoins`). Held on `HouseUser.coins`.
- Family-friendly terminology — never "points" or military terms.

### 3. Weekly Medals (recognition)
- Catalogue (`lib/Db/Medal.php`): `team_anchor`, `flow_keeper`, `room_guardian`, `team_player`, `streak_keeper`.
- **Award reality (CS-PLAN-012):** all five medal types are awarded in the live `GameLogicService::checkAndAwardMedals()` flow: `team_anchor` (10+ tasks/week), `streak_keeper` (7+ day streak), `flow_keeper` (4+ distinct active days), `room_guardian` (5+ completions in one room), `team_player` (3+ combo bonuses). Awarded weekly, reset weekly.
- The dead `lib/Service/MedalService.php` is archived (CS-PLAN-010); the live path is `GameLogicService::awardMedalIfNew()` (race-safe via unique-constraint handling).

### 4. Combos
- Consecutive completions by the **same user** (≥2 recent completions) double coins: `coins *= 2` in `GameLogicService::calculateCoins()`. This is a **personal** combo, not the cooperative ×1.5 multi-user concept from the foundational design.
- Combo tracking state is held in `ComboService` / `Combo` entity.
- Combo milestone (≥ `combo_bonus_freeze_threshold`) awards a bonus streak freeze.

### 5. Energy Model
- Each task costs 1–5 energy (~5 min per unit).
- Members define daily energy budgets and presence days.
- Scheduler distributes tasks against capacity; never assigns on unavailable days.

### 6. Config-driven reward tokens (defaults in `RewardConfigService`)
Coins (`coin_base_per_energy` 10), tier multipliers (bronze/silver/gold 1.0/1.5/2.0), early bonus (`early_bonus_multiplier` 1.5, threshold 21d), streak freezes (`streak_freezes_per_week` 1, `streak_freeze_cap` 2), combo bonus freeze (`combo_bonus_freeze_threshold` 5), teamwork bonus (`teamwork_bonus_enabled`, `teamwork_bonus_max_pct` 50), weekly goal (`weekly_goal_target_percent` 80), role titles (`titles_enabled`, `title_threshold_*`). Full table: [`gamification-and-rewards.md`](../../../../concepts/gamification-and-rewards.md) § Implemented Reward Mechanics.

## Backend Services

| Service | Responsibility |
|---------|---------------|
| `GameLogicService` | Core reward calculation on task completion |
| `ComboService` | Combo tracking and multiplier windows |
| `GameLogicService` | Core reward calculation **and** weekly medal evaluation (`awardMedalIfNew`, race-safe via unique-constraint handling) |
| `UserStatsService` | Contribution score, streaks |
| `RewardConfigService` | Configurable reward formulas per house |
| `SmartSchedulerService` | Energy-aware task distribution |

## Persistence

| Entity/Mapper | Stores |
|---------------|--------|
| `UserStats` / `UserStatsMapper` | Per-user contribution, streak data |
| `HouseUser` / `HouseUserMapper` | Coins, streak, streak-freeze, title state |
| `Combo` / `ComboMapper` | Active combo state per user |
| `Medal` / `MedalMapper` | Awarded medals (user, type, week) |
| `CompletedTask` / `CompletedTaskMapper` | Completion history for scoring |
| `AvailabilitySettings` / `AvailabilitySettingsMapper` | Presence days, energy budgets |

## Design Rules

- **No punishment loops.** Missing tasks never subtract coins or break streaks punitively.
- **Absence-safe:** unavailable days never break streaks or count as missed.
- **Cooperative > competitive:** shared progress bars, team streaks, weekly team goals.
- **Configurable formulas:** reward calculations are policy, not hardcoded constants.
- **Family-friendly language:** no military/aggressive terms. Use "Coins", not "points" or "XP".
- **Fairness normalization:** compare contribution relative to available capacity, not raw counts.

## Role Titles (localized packs)

3 built-in packs with 3 progressive tiers each (low/medium/high contribution). All packs must be family-friendly. See [`gamification-and-rewards.md`](../../../../concepts/gamification-and-rewards.md) for current packs.

## When Modifying Gamification

1. Check ADR-004 and the concept docs for locked decisions.
2. Keep formulas in `RewardConfigService` — don't hardcode in `GameLogicService`.
3. Update `HouseUser` calculations atomically (contribution + coins + streak in one transaction).
4. Add regression tests for edge cases: absence days, combo expiry, weekly medal reset boundaries.
5. Verify fairness: no single strategy should dominate all medal types.
