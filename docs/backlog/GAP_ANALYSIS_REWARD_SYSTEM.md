# Technische Gap-Analyse: Reward System Redesign

> **Status:** Draft
> **Created:** 2026-03-06
> **Based on:** Fachliches Regelwerk V1 (simplified Coin/Energy system)

---

## Executive Summary

This document compares the current ChoreStrike implementation against the new "Fachliches Regelwerk V1" for the reward/energy/scheduling system. The new ruleset introduces:

- **Coins** as single reward currency (replacing Team Sparks)
- **TaskTier** (Bronze/Silver/Gold) for task multipliers
- **Personal Coin Multiplier** for fairness (e.g., 0.1x for parents)
- **Flex-Window Scheduling** with early completion bonus
- **Simplified Contribution** = sum of Energy (no multipliers)

**Overall Impact:** Medium-High complexity refactoring across backend, frontend, and data model.

---

## 1. Energy System

### Current Implementation

| Component | Current State | Location |
|-----------|---------------|----------|
| Task Energy | `Task.energyCost` (1-5 range) | `lib/Db/Task.php:278` |
| Daily Budget | `SchedulingService::updateEnergyBudget()` (1-20 range) | `lib/Service/SchedulingService.php:76` |
| Consumed Today | `CompletedTaskMapper::getConsumedEnergyForUserOnDate()` | Backend + Frontend |
| Remaining | `scheduling.js::todayRemainingEnergy` (clamped to 0) | `src/store/scheduling.js:113` |

### New Ruleset Requirements

| Requirement | Value |
|-------------|-------|
| Energy Definition | 1 Energy ≈ 10 minutes |
| Max Energy per Task | **9** (configurable) |
| Daily Budget | User-defined, can be **negative** |
| Negative Handling | Show motivation message (no punishment) |

### Gaps

| Gap ID | Description | Impact | Effort |
|--------|-------------|--------|--------|
| **E-1** | Task energy validation is 1-5, needs 1-9 | Backend, Migration | Low |
| **E-2** | No negative energy handling in UI | Frontend | Low |
| **E-3** | Energy limit hardcoded (not configurable) | Backend Config | Low |
| **E-4** | No motivation message for negative budget | Frontend | Low |

### Required Changes

```php
// lib/Db/Task.php:278 - Update validation
if ($this->energyCost < 1 || $this->energyCost > 9) {  // was: 1-5
    $errors[] = 'Energy cost must be between 1 and 9';
}
```

```javascript
// src/store/scheduling.js:113 - Allow negative
todayRemainingEnergy: (state, getters) => {
    const today = new Date().toLocaleDateString('en-US', { weekday: 'long' }).toLowerCase()
    const total = state.energyBudget[today] ?? 10
    return total - state.todayConsumedEnergy  // Remove Math.max(0, ...)
}
```

---

## 2. Reward Currency: Team Sparks → Coins

### Current Implementation

| Component | Current State | Location |
|-----------|---------------|----------|
| Currency Name | "Team Sparks" | UI, Backend, DB |
| Calculation | `GameLogicService::calculateTeamSparks()` | `lib/Service/GameLogicService.php:119` |
| Formula | `energyCost * 5` + priority bonus + combo bonus | Backend |
| User Storage | `HouseUser.teamSparks` | `lib/Db/HouseUser.php:60` |
| Completion Storage | `CompletedTask.sparksEarned` | `lib/Db/CompletedTask.php:58` |

### New Ruleset Requirements

| Requirement | Value |
|-------------|-------|
| Currency Name | **Coins** |
| Base Formula | `Energy × 10` |
| TaskTier Multiplier | Bronze 1.0x, Silver 1.5x, Gold 2.0x |
| Personal Multiplier | Per user (e.g., 0.1 for parents) |
| Early Bonus | Multiplier for early completion |

### Gaps

| Gap ID | Description | Impact | Effort |
|--------|-------------|--------|--------|
| **C-1** | Rename "Team Sparks" → "Coins" everywhere | Backend, Frontend, DB | Medium |
| **C-2** | Formula change (base 5 → 10) | Backend | Low |
| **C-3** | No `TaskTier` concept exists | Backend, Frontend, DB | Medium |
| **C-4** | No `PersonalMultiplier` per user | Backend, Frontend, DB | Medium |
| **C-5** | No early completion bonus | Backend, Frontend | Medium |

### Required Changes

```php
// lib/Service/GameLogicService.php - New calculateCoins() method
public function calculateCoins(
    Task $task,
    float $personalMultiplier = 1.0,
    float $earlyBonus = 1.0
): int {
    $base = $task->getEnergyCost() * 10;
    $tierMultiplier = match($task->getTaskTier()) {
        'gold' => 2.0,
        'silver' => 1.5,
        'bronze' => 1.0,
        default => 1.0
    };
    
    return (int)($base * $tierMultiplier * $personalMultiplier * $earlyBonus);
}
```

---

## 3. Contribution System

### Current Implementation

| Component | Current State | Location |
|-----------|---------------|----------|
| Calculation | `GameLogicService::calculateContributionScore()` | `lib/Service/GameLogicService.php:94` |
| Formula | `energyCost * 10` × priority × room cleanliness | Backend |
| Storage | `HouseUser.contributionScore` | `lib/Db/HouseUser.php:57` |

### New Ruleset Requirements

| Requirement | Value |
|-------------|-------|
| Definition | Contribution = **Sum of completed Energy** |
| Multipliers | **None** (simple sum) |
| Purpose | Measure actual work done, separate from reward |

### Gaps

| Gap ID | Description | Impact | Effort |
|--------|-------------|--------|--------|
| **CO-1** | Current formula has multipliers (priority, cleanliness) | Backend | Low |
| **CO-2** | Need to simplify to pure Energy sum | Backend | Low |

### Required Changes

```php
// lib/Service/GameLogicService.php - Simplified contribution
public function calculateContributionScore(Task $task): int
{
    return $task->getEnergyCost();  // Simple sum of Energy
}
```

---

## 4. Task Tier System (Bronze/Silver/Gold)

### Current Implementation

| Component | Current State | Location |
|-----------|---------------|----------|
| Task Priority | `Task.priority` (1=low, 2=medium, 3=high) | `lib/Db/Task.php:86` |
| Usage | Used in contribution and sparks calculation | Backend |

### New Ruleset Requirements

| Requirement | Value |
|-------------|-------|
| Field Name | `taskTier` (replacing priority) |
| Values | bronze, silver, gold |
| Multipliers | Bronze 1.0x, Silver 1.5x, Gold 2.0x |
| Purpose | Reward multiplier for difficult/unpopular tasks |

### Gaps

| Gap ID | Description | Impact | Effort |
|--------|-------------|--------|--------|
| **T-1** | No `taskTier` field exists | Backend, DB Migration | Medium |
| **T-2** | UI shows "priority" not "tier" | Frontend | Low |
| **T-3** | Migration from priority → tier needed | Migration | Medium |

### Required Changes

```php
// lib/Db/Task.php - Add taskTier field
/** @var string */
protected $taskTier = 'bronze';  // bronze, silver, gold

// Migration: Map priority → tier
// priority 1 (low) → bronze
// priority 2 (medium) → silver  
// priority 3 (high) → gold
```

---

## 5. Personal Coin Multiplier

### Current Implementation

| Component | Current State | Location |
|-----------|---------------|----------|
| User Roles | `HouseUser.role` (owner/member/child) | `lib/Db/HouseUser.php:69` |
| Fairness | No multiplier mechanism | N/A |

### New Ruleset Requirements

| Requirement | Value |
|-------------|-------|
| Field | `coinMultiplier` per user |
| Default | 1.0 |
| Example | Parents = 0.1 (to not dominate leaderboard) |
| Configuration | Admin can set per user |

### Gaps

| Gap ID | Description | Impact | Effort |
|--------|-------------|--------|--------|
| **P-1** | No `coinMultiplier` field exists | Backend, DB Migration | Medium |
| **P-2** | No UI for admin to configure | Frontend | Medium |
| **P-3** | Not used in reward calculation | Backend | Low |

### Required Changes

```php
// lib/Db/HouseUser.php - Add coinMultiplier
/** @var float */
protected $coinMultiplier = 1.0;

// In reward calculation
$coins = $this->calculateCoins($task, $user->getCoinMultiplier(), $earlyBonus);
```

---

## 6. Flex-Window Scheduling

### Current Implementation

| Component | Current State | Location |
|-----------|---------------|----------|
| Scheduling Mode | `Task.schedulingMode` (dynamic/static) | `lib/Db/Task.php:131` |
| Due Date | `RoomTask.nextDue` (single date) | `lib/Db/RoomTask.php:61` |
| Calculation | `RoomTask::markCompleted()` calculates next | `lib/Db/RoomTask.php:223` |

### New Ruleset Requirements

| Requirement | Value |
|-------------|-------|
| Flex-Window | Base interval ± flex days (e.g., 4 weeks ± 1 week) |
| Ideal Due Date | Scheduler picks date within window |
| Early Bonus | Multiplier for completing before ideal date |
| Example | 3 weeks early = 1.5x, on time = 1.0x |

### Gaps

| Gap ID | Description | Impact | Effort |
|--------|-------------|--------|--------|
| **S-1** | No `flexDays` field on Task | Backend, DB Migration | Medium |
| **S-2** | No `idealDueDate` concept | Backend, Frontend | Medium |
| **S-3** | No early bonus calculation | Backend | Medium |
| **S-4** | Scheduler needs major rework | Backend | High |

### Required Changes

```php
// lib/Db/Task.php - Add flexDays
/** @var int|null */
protected $flexDays = null;  // e.g., 7 for ±1 week

// lib/Db/RoomTask.php - Add idealDueDate
/** @var \DateTime|null */
protected $idealDueDate = null;

// New method: calculateEarlyBonus()
public function calculateEarlyBonus(\DateTime $completedAt): float
{
    if ($this->idealDueDate === null) return 1.0;
    
    $daysEarly = (int)$this->idealDueDate->diff($completedAt)->days;
    if ($daysEarly <= 0) return 1.0;
    
    // Example: 1.5x if completed 3+ weeks early
    return $daysEarly >= 21 ? 1.5 : 1.0;
}
```

---

## 7. Team Tasks

### Current Implementation

| Component | Current State | Location |
|-----------|---------------|----------|
| Teamwork Flag | `Task.allowTeamwork` | `lib/Db/Task.php:152` |
| Max Participants | `Task.maxParticipants` | `lib/Db/Task.php:155` |
| Team Multiplier | `Task.teamSparkMultiplier` | `lib/Db/Task.php:158` |
| Completion Tracking | `CompletedTask.isTeamCompletion`, `participantCount` | `lib/Db/CompletedTask.php` |

### New Ruleset Requirements

| Requirement | Value |
|-------------|-------|
| Team Tasks | Keep existing mechanism |
| Completion | **All participants must confirm** |
| Rewards | All receive **full Coins** (no special team bonus) |
| Spontaneous Teams | **Not allowed** (already implemented) |

### Gaps

| Gap ID | Description | Impact | Effort |
|--------|-------------|--------|--------|
| **TM-1** | No multi-user confirmation workflow | Backend, Frontend | Medium |
| **TM-2** | `teamSparkMultiplier` should be removed | Backend, DB | Low |
| **TM-3** | Team completion gives standard Coins | Backend | Low |

### Required Changes

```php
// Team task completion: all must confirm
// New table: task_confirmations (room_task_id, user_id, confirmed_at)

// Reward calculation: no special team multiplier
$coins = $this->calculateCoins($task, $user->getCoinMultiplier(), $earlyBonus);
// Each participant gets same amount
```

---

## 8. UI Components

### Current State

| Component | Shows | Location |
|-----------|-------|----------|
| `GameStats.vue` | "Team Sparks", Combos, Medals | `src/components/Gamification/GameStats.vue` |
| `Leaderboard.vue` | Contribution, Team Sparks | `src/views/Leaderboard.vue` |
| `HouseholdProgress.vue` | Team Sparks, Weekly Goal | `src/components/Gamification/HouseholdProgress.vue` |

### Required Changes

| Change | Description | Effort |
|--------|-------------|--------|
| Rename "Team Sparks" → "Coins" | All UI labels | Low |
| Add TaskTier display | Show Bronze/Silver/Gold badge on tasks | Low |
| Update Leaderboard | Show Coins with personal multiplier applied | Low |
| Negative Energy UI | Show motivation message when budget negative | Low |
| Early Bonus Display | Show bonus multiplier when completing early | Low |

---

## 9. Database Migration Summary

### New Columns Required

| Table | Column | Type | Default |
|-------|--------|------|---------|
| `chorestrike_tasks` | `task_tier` | VARCHAR(10) | 'bronze' |
| `chorestrike_tasks` | `flex_days` | INT | NULL |
| `chorestrike_house_users` | `coin_multiplier` | DECIMAL(3,2) | 1.00 |
| `chorestrike_room_tasks` | `ideal_due_date` | DATETIME | NULL |

### Columns to Remove/Rename

| Table | Column | Action |
|-------|--------|--------|
| `chorestrike_tasks` | `team_spark_multiplier` | Remove (unused) |
| `chorestrike_tasks` | `priority` | Deprecate (replaced by task_tier) |
| `chorestrike_completed_tasks` | `sparks_earned` | Rename to `coins_earned` |
| `chorestrike_completed_tasks` | `team_spark_bonus` | Remove (unused) |
| `chorestrike_house_users` | `team_sparks` | Rename to `coins` |

---

## 10. Implementation Priority

### Phase 1: Core Changes (High Priority)

| Task | Gap IDs | Effort | Dependencies |
|------|---------|--------|--------------|
| 1.1 Rename Team Sparks → Coins | C-1 | Medium | None |
| 1.2 Add TaskTier field | T-1, T-2, T-3 | Medium | None |
| 1.3 Add PersonalMultiplier | P-1, P-2, P-3 | Medium | 1.1 |
| 1.4 Update reward formula | C-2, C-3 | Low | 1.2, 1.3 |
| 1.5 Simplify contribution | CO-1, CO-2 | Low | None |

### Phase 2: Scheduling (Medium Priority)

| Task | Gap IDs | Effort | Dependencies |
|------|---------|--------|--------------|
| 2.1 Add Flex-Window fields | S-1, S-2 | Medium | None |
| 2.2 Implement early bonus | S-3 | Medium | 2.1 |
| 2.3 Rework scheduler | S-4 | High | 2.1, 2.2 |

### Phase 3: Team Tasks (Lower Priority)

| Task | Gap IDs | Effort | Dependencies |
|------|---------|--------|--------------|
| 3.1 Multi-user confirmation | TM-1 | Medium | None |
| 3.2 Remove teamSparkMultiplier | TM-2, TM-3 | Low | 1.4 |

### Phase 4: Energy Polish (Lower Priority)

| Task | Gap IDs | Effort | Dependencies |
|------|---------|--------|--------------|
| 4.1 Update energy limits | E-1, E-3 | Low | None |
| 4.2 Negative energy UI | E-2, E-4 | Low | None |

---

## 11. Risk Assessment

| Risk | Likelihood | Impact | Mitigation |
|------|------------|--------|------------|
| Data migration errors | Medium | High | Write migration tests, backup DB |
| UI confusion during transition | Medium | Medium | Feature flag for new system |
| Scheduler bugs | Medium | High | Comprehensive unit tests |
| Performance impact | Low | Medium | Profile new calculations |

---

## 12. Open Questions - RESOLVED

| Question | Decision |
|----------|----------|
| **Migration Strategy** | No parallel systems needed. Acceptable if system is non-functional during transition. |
| **Legacy Data** | Test databases only - can be regenerated. No production data to migrate. |
| **Priority Field** | Full migration to `taskTier`. Remove `priority` field completely. |
| **Early Bonus Formula** | **Configurable by admin**. Same as TaskTier multipliers (bronze/silver/gold). |
| **Team Confirmation** | Simple approach: First team member sets task, others can modify if needed. No timeout/auto-complete. |

### Configuration Requirements

Based on decisions, the following must be configurable by admin:

| Config Key | Description | Default |
|------------|-------------|---------|
| `tier_bronze_multiplier` | Coin multiplier for Bronze tasks | 1.0 |
| `tier_silver_multiplier` | Coin multiplier for Silver tasks | 1.5 |
| `tier_gold_multiplier` | Coin multiplier for Gold tasks | 2.0 |
| `early_bonus_threshold_days` | Days before ideal due for bonus | 21 |
| `early_bonus_multiplier` | Coin multiplier for early completion | 1.5 |
| `energy_max_per_task` | Maximum energy per task | 9 |

---

## Appendix A: Current Code References

### Backend

- `lib/Service/GameLogicService.php` - Core reward logic
- `lib/Service/SchedulingService.php` - Energy budget management
- `lib/Db/Task.php` - Task entity with energy/priority
- `lib/Db/HouseUser.php` - User with contribution/sparks
- `lib/Db/CompletedTask.php` - Completion tracking
- `lib/Db/RoomTask.php` - Due date calculation

### Frontend

- `src/store/game.js` - Game state, Team Sparks calculation
- `src/store/scheduling.js` - Energy budget, availability
- `src/components/Gamification/GameStats.vue` - Stats display
- `src/views/Leaderboard.vue` - Leaderboard display

---

**Document Version:** 1.0
**Last Updated:** 2026-03-06
