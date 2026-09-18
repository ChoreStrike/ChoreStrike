# ADR-004: Multi-User Gamification Architecture

**Status**: Accepted — currency model superseded  
**Date**: 2026-02-24  
**Deciders**: Development Team

> **Note (2026-07-24):** The "Team Sparks" currency described below was replaced
> by the **Coins + task-tier multiplier + personal coin multiplier** model shipped
> in `cs-plan-002-gamification-simplification` (completion record:
> [`docs/backlog/archive/2026-03.md`](../backlog/archive/2026-03.md)). The
> body is retained as a historical decision record, not a current formula or
> feature specification. Contribution is now energy-only, medals have evolved,
> and the current streak is availability-day activity rather than planned-rhythm
> adherence. See [current mechanics and proposed reward options](../concepts/gamification-and-rewards.md)
> for the implementation baseline and the reopened family-first decisions.

## Context

ChoreStrike aims to motivate household members through gamification. We need to design a system that:
- Tracks individual contributions fairly
- Encourages collaboration through team bonuses
- Provides recognition through achievements
- Maintains engagement through progression systems

The system must work for various household types (families, roommates, couples) with different dynamics.

## Decision

We will implement a **multi-layered gamification system** with three core components:

### 1. Contribution Scoring System
- **Contribution Score**: Permanent points based on task completion
- Calculated from: `energy_cost * 10 * priority_multiplier * cleanliness_multiplier`
- Tracks lifetime contribution to household

### 2. Team Sparks System
- **Team Sparks**: Renewable currency for team achievements
- Earned through task completion with combo bonuses
- Encourages rapid task completion and collaboration
- Can be "spent" on future features (e.g., house upgrades, special events)

### 3. Weekly Medal System
- **5 Medal Types**: Team Anchor, Flow Keeper, Room Guardian, Team Player, Streak Keeper
- Awarded weekly based on different play styles
- Recognizes diverse contributions (not just quantity)
- Resets weekly to give everyone fresh opportunities

## Consequences

### Positive

- **Fair Recognition**: Multiple metrics prevent single-strategy dominance
- **Diverse Play Styles**: Different medals reward different approaches
- **Team Focus**: Team Sparks encourage collaboration over competition
- **Fresh Starts**: Weekly medal reset prevents permanent hierarchy
- **Engagement Loops**: Multiple progression systems maintain interest
- **Family-Friendly**: Non-aggressive terminology ("Team Sparks" vs "points")

### Negative

- **Complexity**: Three separate systems to balance and maintain
- **Database Load**: More tables and calculations required
- **Potential Imbalance**: Scoring formulas may need tuning based on real usage
- **Comparison Pressure**: Leaderboards might create unwanted competition in some households

### Neutral

- **Configurable**: System could be made optional or configurable per household
- **Extensible**: Easy to add new medal types or scoring factors
- **Data-Driven**: Rich data for future analytics and improvements

## Implementation Details

### Database Entities

```
chorestrike_house_users
- contribution_score (lifetime points)
- team_sparks (renewable currency)
- current_streak (consecutive days)
- role (owner/member/child)

chorestrike_completed_tasks
- sparks_earned (per completion)
- was_combo (boolean)
- combo_with_user_id (for team combos)

chorestrike_medals
- medal_type (5 types)
- week_start (for weekly reset)
- value (achievement metric)
```

### Scoring Formulas

**Contribution Score**:
```
base = energy_cost * 10
if priority == 3: base *= 1.5
if priority == 2: base *= 1.2
if cleanliness < 40: base *= 1.3
if cleanliness < 20: base *= 1.5
```

**Team Sparks**:
```
sparks = energy_cost * 5
if is_combo: sparks *= 2
if priority == 3: sparks += 10
if priority == 2: sparks += 5
```

**Combo Detection**:
- 2+ tasks by same user within 5 minutes = Speed Combo
- 2+ tasks in same room = Room Cleaning Combo

### Medal Criteria

- **Team Anchor**: 10+ tasks completed in a week
- **Flow Keeper**: Active 5+ days in a week
- **Room Guardian**: 3+ rooms improved in a week
- **Team Player**: 5+ collaborative tasks in a week
- **Streak Keeper**: 7+ day activity streak

## Alternatives Considered

### Single Point System
- **Pros**: Simpler to understand and implement
- **Cons**: Creates single-dimension competition, doesn't reward diverse contributions
- **Rejected**: Too simplistic for engaging long-term motivation

### Competitive Leaderboard Only
- **Pros**: Clear rankings, simple gamification
- **Cons**: Can create household tension, discourages helping others, not family-friendly
- **Rejected**: Conflicts with cooperative household goals

### Achievement Badges Only
- **Pros**: Non-competitive, fun to collect
- **Cons**: No progression sense, limited engagement after collecting all badges
- **Rejected**: Insufficient for sustained motivation

### Energy/Stamina System
- **Pros**: Limits task hoarding, encourages daily participation
- **Cons**: Punishes enthusiastic users, complex to balance, feels restrictive
- **Rejected**: Too game-like, might frustrate rather than motivate

## Future Enhancements

### Potential Additions
- **House Level**: Collective progression for the whole household
- **Seasonal Events**: Special challenges with unique rewards
- **Custom Medals**: Households can define their own achievements
- **Team Sparks Shop**: Spend sparks on cosmetic upgrades or special features
- **Streak Bonuses**: Increasing multipliers for long streaks
- **Difficulty Modes**: Adjust scoring for different household sizes

### Monitoring & Tuning
- Track medal distribution to ensure fairness
- Monitor combo frequency to adjust time windows
- Analyze contribution score distribution across households
- Gather user feedback on motivation effectiveness

## References

- GameLogicService: `lib/Service/GameLogicService.php`
- GameController: `lib/Controller/GameController.php`
- Database Schema: `lib/Migration/Version1000Date20260224000000.php`
- Frontend Store: `src/store/game.js`
- Concept Doc: `docs/concepts/gamification-and-rewards.md`
