# Phase: Reward System Redesign

> **Status:** Planned
> **Created:** 2026-03-21
> **Based on:** GAP_ANALYSIS_REWARD_SYSTEM.md, Fachliches Regelwerk V1
> **Priority:** High

---

## Overview

Redesign the reward system to implement a simplified, child-friendly Coin/Energy system with:
- **Coins** as single reward currency (replacing Team Sparks)
- **TaskTier** (Bronze/Silver/Gold) for task multipliers
- **Personal Coin Multiplier** for fairness
- **Flex-Window Scheduling** with early completion bonus
- **Simplified Contribution** = sum of Energy

---

## Configuration Requirements

All multipliers and limits must be configurable by admin:

| Config Key | Description | Default |
|------------|-------------|---------|
| `tier_bronze_multiplier` | Coin multiplier for Bronze tasks | 1.0 |
| `tier_silver_multiplier` | Coin multiplier for Silver tasks | 1.5 |
| `tier_gold_multiplier` | Coin multiplier for Gold tasks | 2.0 |
| `early_bonus_threshold_days` | Days before ideal due for bonus | 21 |
| `early_bonus_multiplier` | Coin multiplier for early completion | 1.5 |
| `energy_max_per_task` | Maximum energy per task | 9 |
| `coin_base_per_energy` | Base coins per energy point | 10 |

---

## Phase 1: Core Changes (High Priority)

### 1.1 Rename Team Sparks → Coins
**Status:** Pending
**Gap IDs:** C-1
**Effort:** Medium
**Dependencies:** None

**Tasks:**
- [ ] Rename `HouseUser.teamSparks` → `HouseUser.coins`
- [ ] Rename `CompletedTask.sparksEarned` → `CompletedTask.coinsEarned`
- [ ] Update all UI labels from "Team Sparks" → "Coins"
- [ ] Update API responses and frontend stores
- [ ] Update database column names via migration

**Files:**
- `lib/Db/HouseUser.php`
- `lib/Db/CompletedTask.php`
- `lib/Service/GameLogicService.php`
- `src/store/game.js`
- `src/components/Gamification/*.vue`
- `src/views/Leaderboard.vue`

---

### 1.2 Add TaskTier Field
**Status:** Pending
**Gap IDs:** T-1, T-2, T-3
**Effort:** Medium
**Dependencies:** None

**Tasks:**
- [ ] Add `taskTier` field to Task entity (bronze/silver/gold)
- [ ] Create migration: map `priority` → `taskTier` (1→bronze, 2→silver, 3→gold)
- [ ] Remove `priority` field after migration
- [ ] Update TaskForm UI to show tier selector with badges
- [ ] Update task display components to show tier badge

**Files:**
- `lib/Db/Task.php`
- `lib/Migration/VersionXXXXDateXXXX.php`
- `src/components/Tasks/TaskForm.vue`
- `src/components/Tasks/TaskCard.vue`

---

### 1.3 Add Personal Coin Multiplier
**Status:** Pending
**Gap IDs:** P-1, P-2, P-3
**Effort:** Medium
**Dependencies:** 1.1

**Tasks:**
- [ ] Add `coinMultiplier` field to HouseUser entity (default 1.0)
- [ ] Create migration with default values
- [ ] Add admin UI to set multiplier per user
- [ ] Apply multiplier in `calculateCoins()` method
- [ ] Update leaderboard to show multiplier-adjusted coins

**Files:**
- `lib/Db/HouseUser.php`
- `lib/Service/GameLogicService.php`
- `src/views/Settings.vue`
- `src/components/UserManagement/UserEditor.vue`

---

### 1.4 Update Reward Formula
**Status:** Pending
**Gap IDs:** C-2, C-3
**Effort:** Low
**Dependencies:** 1.2, 1.3

**Tasks:**
- [ ] Replace `calculateTeamSparks()` with `calculateCoins()`
- [ ] Implement formula: `Energy × 10 × tierMultiplier × personalMultiplier`
- [ ] Load tier multipliers from config
- [ ] Update all callers of old method
- [ ] Add unit tests for new formula

**Formula:**
```
Coins = Energy × baseRate × tierMultiplier × personalMultiplier
      = Energy × 10 × (1.0|1.5|2.0) × userMultiplier
```

**Files:**
- `lib/Service/GameLogicService.php`
- `tests/unit/Service/GameLogicServiceTest.php`

---

### 1.5 Simplify Contribution
**Status:** Pending
**Gap IDs:** CO-1, CO-2
**Effort:** Low
**Dependencies:** None

**Tasks:**
- [ ] Simplify `calculateContributionScore()` to return `task.getEnergyCost()`
- [ ] Remove priority and cleanliness multipliers
- [ ] Update unit tests

**Files:**
- `lib/Service/GameLogicService.php`
- `tests/unit/Service/GameLogicServiceTest.php`

---

### 1.6 Add Config Service
**Status:** Pending
**Gap IDs:** New
**Effort:** Medium
**Dependencies:** None

**Tasks:**
- [ ] Create `RewardConfigService` for admin-configurable multipliers
- [ ] Add config table or use Nextcloud app config
- [ ] Add API endpoints for config management
- [ ] Add admin UI for config settings
- [ ] Cache config values for performance

**Files:**
- `lib/Service/RewardConfigService.php`
- `lib/Controller/RewardConfigController.php`
- `src/store/rewardConfig.js`
- `src/views/AdminSettings.vue`

---

## Phase 2: Scheduling (Medium Priority)

### 2.1 Add Flex-Window Fields
**Status:** Pending
**Gap IDs:** S-1, S-2
**Effort:** Medium
**Dependencies:** None

**Tasks:**
- [ ] Add `flexDays` field to Task entity
- [ ] Add `idealDueDate` field to RoomTask entity
- [ ] Create migration for new columns
- [ ] Update TaskForm UI to allow setting flex window
- [ ] Update scheduler to calculate ideal due date within window

**Files:**
- `lib/Db/Task.php`
- `lib/Db/RoomTask.php`
- `src/components/Tasks/TaskForm.vue`

---

### 2.2 Implement Early Completion Bonus
**Status:** Pending
**Gap IDs:** S-3
**Effort:** Medium
**Dependencies:** 2.1

**Tasks:**
- [ ] Add `calculateEarlyBonus()` method
- [ ] Load early bonus config (threshold days, multiplier)
- [ ] Apply bonus in `calculateCoins()` when completing early
- [ ] Show bonus indicator in UI when completing early
- [ ] Add unit tests

**Formula:**
```
if daysEarly >= threshold:
    earlyMultiplier = config.early_bonus_multiplier
else:
    earlyMultiplier = 1.0
```

**Files:**
- `lib/Service/GameLogicService.php`
- `lib/Service/RoomService.php`
- `src/components/Tasks/TaskCompletionFeedback.vue`

---

### 2.3 Rework Scheduler for Flex-Window
**Status:** Pending
**Gap IDs:** S-4
**Effort:** High
**Dependencies:** 2.1, 2.2

**Tasks:**
- [ ] Update scheduler to pick ideal due date within flex window
- [ ] Consider user availability when scheduling
- [ ] Avoid scheduling conflicts
- [ ] Add integration tests for new scheduler behavior

**Files:**
- `lib/Service/SchedulingService.php`
- `lib/Service/TaskAssignmentService.php`
- `tests/integration/Service/SchedulingServiceTest.php`

---

## Phase 3: Team Tasks (Lower Priority)

### 3.1 Simplify Team Task Rewards
**Status:** Pending
**Gap IDs:** TM-2, TM-3
**Effort:** Low
**Dependencies:** 1.4

**Tasks:**
- [ ] Remove `teamSparkMultiplier` field from Task
- [ ] Remove `teamSparkBonus` field from CompletedTask
- [ ] All team participants receive standard Coins (same formula)
- [ ] Update migration to remove unused columns

**Files:**
- `lib/Db/Task.php`
- `lib/Db/CompletedTask.php`
- `lib/Migration/VersionXXXXDateXXXX.php`

---

### 3.2 Team Task Confirmation Workflow
**Status:** Pending
**Gap IDs:** TM-1
**Effort:** Medium
**Dependencies:** None

**Tasks:**
- [ ] Simple approach: First team member sets task assignment
- [ ] Other participants can modify if needed (no timeout)
- [ ] All assigned participants must mark complete
- [ ] Update UI to show team task status per participant

**Files:**
- `lib/Service/RoomService.php`
- `src/components/Tasks/TeamTaskStatus.vue`
- `src/store/roomTasks.js`

---

## Phase 4: Energy Polish (Lower Priority)

### 4.1 Update Energy Limits
**Status:** Pending
**Gap IDs:** E-1, E-3
**Effort:** Low
**Dependencies:** None

**Tasks:**
- [ ] Update Task validation: energy 1-9 (was 1-5)
- [ ] Make max energy configurable via `RewardConfigService`
- [ ] Update TaskForm UI to show correct max

**Files:**
- `lib/Db/Task.php`
- `lib/Service/RewardConfigService.php`
- `src/components/Tasks/TaskForm.vue`

---

### 4.2 Negative Energy UI
**Status:** Pending
**Gap IDs:** E-2, E-4
**Effort:** Low
**Dependencies:** None

**Tasks:**
- [ ] Allow negative `todayRemainingEnergy` (remove clamp)
- [ ] Show motivation message when energy is negative
- [ ] Design friendly, encouraging UI (no punishment feeling)
- [ ] Add visual indicator for "overworked" state

**Files:**
- `src/store/scheduling.js`
- `src/components/Scheduling/EnergyBudgetIndicator.vue`
- `src/components/Gamification/MotivationMessage.vue`

---

## Database Migration Summary

### New Columns

| Table | Column | Type | Default |
|-------|--------|------|---------|
| `chorestrike_tasks` | `task_tier` | VARCHAR(10) | 'bronze' |
| `chorestrike_tasks` | `flex_days` | INT | NULL |
| `chorestrike_house_users` | `coin_multiplier` | DECIMAL(3,2) | 1.00 |
| `chorestrike_room_tasks` | `ideal_due_date` | DATETIME | NULL |

### Renamed Columns

| Table | Old Name | New Name |
|-------|----------|----------|
| `chorestrike_house_users` | `team_sparks` | `coins` |
| `chorestrike_completed_tasks` | `sparks_earned` | `coins_earned` |

### Removed Columns

| Table | Column | Reason |
|-------|--------|--------|
| `chorestrike_tasks` | `priority` | Replaced by `task_tier` |
| `chorestrike_tasks` | `team_spark_multiplier` | Unused |
| `chorestrike_completed_tasks` | `team_spark_bonus` | Unused |

---

## Testing Requirements

- [ ] Unit tests for new `calculateCoins()` formula
- [ ] Unit tests for `calculateEarlyBonus()`
- [ ] Unit tests for simplified `calculateContributionScore()`
- [ ] Integration tests for flex-window scheduler
- [ ] E2E tests for team task confirmation
- [ ] Migration tests for all DB changes

---

## Risk Mitigation

| Risk | Mitigation |
|------|------------|
| Data migration errors | Test databases can be regenerated - no production risk |
| Scheduler bugs | Comprehensive unit + integration tests |
| Performance impact | Profile new calculations, cache config |

---

## Completion Criteria

- [ ] All Phase 1 tasks completed
- [ ] All unit tests passing
- [ ] Manual testing of new reward flow
- [ ] Admin can configure all multipliers
- [ ] UI shows Coins, TaskTier badges, early bonus indicators
- [ ] Negative energy shows motivation message
