# Phase 11: Task Visibility & Privacy Features

**Status:** Planned  
**Priority:** Medium  
**Estimated Effort:** 2-3 weeks

---

## Overview

Extend task system to support different visibility levels and ownership models, allowing users to create private reminders and admins to manage special reward-bearing tasks.

---

## Features

### 1. Private Tasks (Personal Reminders)
**ID:** P11-PRIVATE-001  
**Priority:** HIGH

**Description:**  
Allow users to create personal tasks that are only visible to them, without rewards or gamification elements. These serve as simple reminders integrated into the ChoreStrike interface.

**Requirements:**
- [ ] Add `visibilityType` field to `Task` entity (`public`, `private`, `admin-only`)
- [ ] Add `ownerId` field to track who created the private task
- [ ] Private tasks appear only to their creator
- [ ] Private tasks do NOT grant points/XP/rewards
- [ ] Private tasks can be marked complete but don't affect house health
- [ ] UI indicator showing task is private (e.g., lock icon)

**Database Changes:**
```sql
ALTER TABLE chorestrike_tasks 
ADD COLUMN visibility_type VARCHAR(20) DEFAULT 'public',
ADD COLUMN owner_user_id VARCHAR(64) NULL;
```

**API Endpoints:**
- `POST /api/v1/tasks` - Add `visibilityType: 'private'` option
- `GET /api/v1/tasks` - Filter private tasks to owner only
- `GET /api/v1/tasks/my-private` - Fetch user's private tasks

**UI Changes:**
- TaskForm: Add visibility selector (Public / Private)
- TaskList: Show lock icon for private tasks
- Dashboard: Option to show/hide private tasks

**Tests:**
- Unit: Private task creation and filtering
- Integration: Authorization (user cannot see other user's private tasks)
- E2E: Create private task, verify visibility

---

### 2. Admin-Only Tasks (Special Rewards)
**ID:** P11-ADMIN-002  
**Priority:** MEDIUM

**Description:**  
Allow admins/owners to create tasks that are only visible to them but DO provide rewards when completed. Useful for administrative chores or special bonus tasks.

**Requirements:**
- [ ] `visibilityType: 'admin-only'` tasks visible only to house owners
- [ ] Admin-only tasks CAN grant rewards (unlike private tasks)
- [ ] Admin-only tasks affect house health
- [ ] Clear UI distinction from private and public tasks
- [ ] Role-based visibility enforcement

**Authorization Rules:**
```
Public tasks: Visible to all house members
Private tasks: Visible only to creator (any role)
Admin-only tasks: Visible only to owners, grants rewards
```

**UI Changes:**
- TaskForm: Visibility options (Public / Private / Admin-Only)
- TaskList: Crown/shield icon for admin-only tasks
- Filters: "Show admin tasks" toggle for owners

**Tests:**
- Unit: Visibility filtering by role
- Integration: Child/guest cannot see admin-only tasks
- E2E: Owner completes admin-only task, gets rewards

---

### 3. Teamwork Task Rewards (Bonus Calculation)
**ID:** P11-TEAMWORK-003  
**Priority:** HIGH

**Description:**  
Implement bonus rewards for teamwork tasks where multiple users participate. All participants receive a bonus on top of the base reward.

**Requirements:**
- [ ] Base reward split among participants
- [ ] Bonus: +0.5 to +1 energy worth of points per participant
- [ ] Round up final points (1-energy task → both get 1 point each)
- [ ] 3-energy task split → both get 2 points each (1.5 + 0.5 bonus, rounded up)
- [ ] Update `GameLogicService::calculateRewards()` for teamwork

**Reward Calculation:**
```php
// Example: 3-energy task, 2 participants
$baseReward = 30; // 3 energy * 10 points
$perParticipant = $baseReward / 2; // 15
$bonusPerParticipant = 5; // 0.5 energy * 10 points
$finalReward = ceil($perParticipant + $bonusPerParticipant); // ceil(20) = 20 points each
```

**Tests:**
- Unit: Teamwork reward calculation with different energy costs
- Integration: Multiple users complete teamwork task, verify rewards
- E2E: 2-user teamwork task completion

---

### 4. Overdue Task Claiming
**ID:** P11-OVERDUE-004  
**Priority:** MEDIUM

**Description:**  
Allow any house member to claim/reassign overdue tasks originally assigned to someone else.

**Requirements:**
- [ ] Overdue tasks (due date < today) become claimable by others
- [ ] UI shows "Overdue - Available to claim" status
- [ ] Original assignee receives notification when task is claimed
- [ ] No penalty/streak loss for original assignee (separate feature)
- [ ] Authorization: Any member can claim public overdue tasks

**UI Changes:**
- TaskList: "Claim Task" button for overdue tasks assigned to others
- Notification: "Dad claimed your overdue task: Dishes"
- Dashboard: Filter "Overdue tasks available"

**Tests:**
- Unit: Overdue status calculation
- Integration: User claims overdue task from another user
- E2E: Multi-user overdue claiming flow

---

## Technical Notes

### Authorization Matrix
```
                 | Public Task | Private Task | Admin-Only Task
-----------------+-------------+--------------+----------------
View             | All members | Owner only   | Owners only
Edit             | Owners      | Owner only   | Owners only
Assign           | Owners      | N/A          | Owners only
Complete         | Assigned    | Owner only   | Assigned owner
Claim (Overdue)  | All members | N/A          | Owners only
```

### Database Schema
```php
// Task entity additions
private string $visibilityType = 'public'; // 'public', 'private', 'admin-only'
private ?string $ownerUserId = null; // For private tasks
```

---

## Dependencies

- **Phase 1-3:** Core task and multi-user functionality
- **Phase 4:** Teamwork mechanics (for P11-TEAMWORK-003)
- **Phase 5:** Smart scheduling (for overdue detection)

---

## Testing Strategy

### Unit Tests
- `tests/unit/Service/TaskVisibilityServiceTest.php`
- `tests/unit/Service/TeamworkRewardCalculationTest.php`

### Integration Tests
- `tests/integration/Controller/PrivateTaskAuthorizationTest.php`
- `tests/integration/Controller/AdminOnlyTaskTest.php`

### E2E Tests
- `tests/e2e/private-tasks.spec.ts`
- `tests/e2e/admin-only-tasks.spec.ts`
- `tests/e2e/teamwork-rewards.spec.ts`
- `tests/e2e/overdue-task-claiming.spec.ts`

---

## Open Questions

1. **Streak Loss:** Should original assignee lose streak when task is claimed overdue? (For later phase?)
2. **Claim Notification Method:** Toast, push notification, or email?
3. **Private Task Limits:** Should there be a limit on private tasks per user?
4. **Admin-Only UI:** Different color scheme or just icon?

---

**Created:** 2026-03-15  
**Last Updated:** 2026-03-15
