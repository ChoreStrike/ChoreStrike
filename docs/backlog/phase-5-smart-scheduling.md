# Phase 5: Smart Scheduling - Implementation Plan

**Status**: Done  
**Timeline**: Week 19-22  
**Goal**: Implement intelligent task scheduling based on user availability and preferences

## Current Implementation Status (Review Update)

### Intensive review (2026-03-02)

#### Fixed in this review pass
- [x] Daily tasks API payload now matches frontend contract (`name`, `roomName`, `energyCost`, `priority`, `completed`, `isOverdue`, `allowTeamwork`, `order`)
- [x] Ownership validation enforced for daily task mutations (`complete`, `uncomplete`, `skip`, `reschedule`)
- [x] Invalid reschedule date now returns `400 VALIDATION_ERROR` instead of generic `500`
- [x] Smart-schedule member availability payload identity mismatch fixed (`userId` now Nextcloud user ID, additional `houseUserId` for internal references)
- [x] Regression tests updated for task mutation validation and daily payload mapping
- [x] `TaskSchedulerServiceTest` constructor setup synchronized with extracted scheduling collaborators

#### Open findings (prioritized)
1. **Resolved (High)**: Deprecated legacy `SmartSchedulerService` internals and replaced them with a compatibility facade over `TaskSchedulerService` + `RoomTaskService`, removing invalid `RoomTask` method usage and restoring `/api/v1/smart-schedule/*` runtime stability.
2. **Resolved (Medium)**: Local unit-test bootstrap now includes missing mocks (`OCSController`, `JSONResponse`, `ILogger`), enabling deterministic controller/service test execution outside Docker.
3. **Resolved (Medium)**: `TaskController::daily()` now uses `RoomTaskService::getDailyTasksWithDetails()` with per-request task/room caching, removing per-task detail lookups in controller flow.
4. **Resolved (Medium)**: Option-3 API semantics implemented for task mutation flows (`404 RESOURCE_NOT_FOUND` for hidden/foreign resources, `403 FORBIDDEN` for explicit forbidden actions).
5. **Resolved (Medium, 2026-03-03)**: `SmartSchedulerController::getSchedulingSuggestions()` now uses generated smart-schedule assignments instead of an empty placeholder list, restoring meaningful suggestion output for `/api/v1/smart-schedule/suggestions`.

#### Open questions
1. ✅ **RESOLVED**: `houseUserId` in availability payload - DECISION: Keep both IDs
   - `userId` (Nextcloud user ID): Primary public identifier for API consumers
   - `houseUserId` (internal DB ID): Maintained for internal service references and backward compatibility
   - Rationale: Allows gradual migration and provides flexibility for future architecture changes without breaking existing consumers

### Completed in this session
- [x] P5-PLANNING: Phase plan created and aligned with roadmap
- [x] P5-BE-001: AvailabilitySettings data model
  - [x] `AvailabilitySettings` entity
  - [x] `AvailabilitySettingsMapper`
  - [x] Migration `Version1013Date20260301210000`
- [x] P5-BE-002: Initial `TaskSchedulerService`
  - [x] Priority scoring
  - [x] Availability-aware distribution
  - [x] Energy-budget/day-limit checks
  - [x] Root-cause fix: house -> floors -> rooms traversal
- [x] P5-TEST-UNIT: `TaskSchedulerServiceTest`
  - [x] 11 tests passing, 51 assertions
- [x] P5-FE-002: `DailyTaskList.vue` first implementation

### Open issues moved to backlog
- [x] All Phase 5 tasks completed

### Implementation Summary
Phase 5 Smart Scheduling successfully delivered:
- Availability settings data model with migrations
- TaskSchedulerService with priority scoring and distribution algorithm
- Daily task API endpoints (GET daily, PUT complete/uncomplete/skip/reschedule)
- Frontend components (DailyTaskList.vue, AvailabilitySettings.vue)
- Comprehensive test coverage (unit, integration, E2E)
- API hardening with 404/403 semantics for authorization
- Legacy SmartSchedulerService deprecated in favor of TaskSchedulerService compatibility layer

### Prioritized tasks for tomorrow
1. **P6-PLANNING (High)**: Define and approve implementation scope for Phase 6 (i18n)
2. **P6-BE-FE-BOOTSTRAP (High)**: Create initial translation extraction/update workflow (`l10n/templates/chorestrike.pot`, frontend string audit)
3. **P5-DOC-CLOSEOUT (Medium)**: Keep API + ADR docs in sync with final Phase 5 endpoint behavior and review fixes

---

## Overview

Smart Scheduling automatically distributes tasks across the week based on:
- User availability (days and times)
- Task urgency and priority
- Workload balancing
- Energy levels
- Historical completion patterns

---

## Priority 1: Architecture & Data Model (P5-BE-001)

### Database Schema

**AvailabilitySettings Entity**
```php
- id (PK)
- house_id (FK)
- user_id (string)
- weekday_availability (JSON) - {"monday": true, "tuesday": false, ...}
- time_ranges (JSON) - {"start": "09:00", "end": "18:00"}
- energy_budget (JSON) - {"monday": 10, "tuesday": 8, ...}
- planning_mode (enum: weekend_warrior, daily_cleaner, custom, auto_balance)
- max_tasks_per_day (integer)
- updated_at (datetime)
```

**ScheduledTask Entity**
```php
- id (PK)
- house_id (FK)
- room_task_id (FK)
- scheduled_date (date)
- scheduled_time (time, nullable)
- assigned_to_user_id (string, nullable)
- priority_score (integer) - calculated priority
- energy_cost (integer)
- status (enum: pending, completed, skipped, rescheduled)
- created_at (datetime)
```

**TaskSchedule Entity** (for tracking generated schedules)
```php
- id (PK)
- house_id (FK)
- week_start (date)
- schedule_data (JSON) - full week schedule
- is_active (boolean)
- generated_at (datetime)
- applied_at (datetime, nullable)
```

---

## Priority 2: TaskScheduler Service (P5-BE-002)

### Core Algorithm

```php
class TaskSchedulerService {
    public function generateWeeklySchedule(int $houseId, DateTime $weekStart): TaskSchedule;
    public function optimizeSchedule(TaskSchedule $schedule): TaskSchedule;
    public function applySchedule(TaskSchedule $schedule): void;
    public function suggestTaskReassignment(RoomTask $task): array;
}
```

### Scheduling Algorithm Steps

1. **Collect Data**
   - Get all active room tasks for house
   - Get user availability settings
   - Get user stats (completion rates, preferences)
   - Get existing scheduled tasks

2. **Calculate Priority Scores**
   ```
   priority_score = (
     task_priority * 10 +
     days_until_due * 5 +
     overdue_days * 20 +
     room_cleanliness_weight * 3
   )
   ```

3. **Distribute Tasks**
   - Filter tasks by available users on each day
   - Balance workload across users
   - Respect energy budgets
   - Prioritize urgent tasks
   - Group related tasks (same room/floor)

4. **Optimization**
   - Minimize daily task count variance
   - Maximize user preference alignment
   - Avoid back-to-back difficult tasks
   - Consider task dependencies

---

## Priority 3: Planning Modes (P5-BE-003)

### Weekend Warrior Mode
- Concentrate tasks on weekends
- Weekdays: minimal tasks (5% of weekly load)
- Saturday/Sunday: 45% each

### Daily Cleaner Mode
- Even distribution across all available days
- Same number of tasks per day
- Respect user availability

### Custom Distribution
- User-defined percentages per weekday
- Validation: must sum to 100%

### Auto-Balance Mode
- AI-driven optimization
- Historical completion patterns
- User performance analytics
- Dynamic adjustment

---

## Priority 4: API Endpoints (P5-BE-004)

```
GET    /api/v1/schedule/weekly          - Get current week schedule
POST   /api/v1/schedule/generate        - Generate new schedule
PUT    /api/v1/schedule/apply            - Apply generated schedule
DELETE /api/v1/schedule/{id}             - Delete schedule

GET    /api/v1/availability              - Get user availability
PUT    /api/v1/availability              - Update availability
GET    /api/v1/availability/suggestions  - Get optimization suggestions

GET    /api/v1/tasks/daily               - Get today's scheduled tasks
PUT    /api/v1/tasks/{id}/reschedule     - Reschedule specific task
```

---

## Priority 5: Frontend Components (P5-FE-001/002)

### AvailabilitySettings.vue
- Weekday availability toggles
- Time range picker
- Energy budget sliders per day
- Planning mode selector
- Preview of resulting distribution

### DailyTaskList.vue
- Today's scheduled tasks
- Drag-and-drop reordering
- Quick complete/skip actions
- Reschedule button
- Energy cost display

### WeeklyScheduleView.vue
- Calendar view of scheduled tasks
- Color-coded by priority
- User assignment indicators
- Bulk actions (reschedule, reassign)

### ScheduleOptimizer.vue
- Auto-optimization controls
- Suggestion preview
- Apply/Discard buttons
- Optimization metrics display

---

## Priority 6: Testing (P5-TEST)

### Unit Tests
- TaskSchedulerService algorithm tests
- Priority calculation tests
- Distribution balance tests
- Edge cases (no availability, all tasks overdue)

### Integration Tests
- Schedule generation API tests
- Availability update tests
- Task rescheduling tests

### E2E Tests
- Availability settings flow
- Schedule generation flow
- Daily task list interaction
- Optimization preview flow

---

## Implementation Order

1. **Week 1**: Database migrations + AvailabilitySettings entity
2. **Week 2**: TaskSchedulerService core algorithm
3. **Week 3**: Planning modes + API endpoints
4. **Week 4**: Frontend components + Testing

---

## Success Criteria

- [ ] Schedule generated within 2 seconds for 50 tasks
- [ ] Workload variance < 20% across available days
- [ ] 100% of high-priority tasks scheduled on time
- [ ] User preferences matched > 80%
- [ ] All tests passing
- [ ] Mobile responsive
- [ ] Documentation complete

---

## Dependencies

- Phase 3 (User Management) - **REQUIRED** ✅
- Phase 4 (Points/Stats) - **REQUIRED** ✅
- UserStats for workload balancing
- HouseUser for availability tracking

---

## Technical Considerations

### Performance
- Cache generated schedules
- Incremental updates (only changed tasks)
- Background schedule regeneration

### Data Integrity
- Transaction-safe schedule updates
- Conflict detection (double-booked users)
- Rollback capability

### UX
- Real-time preview before applying
- Undo/redo for schedule changes
- Clear visualization of workload distribution

---

## Next Steps

1. Create database migration
2. Implement AvailabilitySettings entity
3. Create TaskSchedulerService
4. Implement planning modes
5. Create API endpoints
6. Build frontend components
7. Write comprehensive tests
8. Performance optimization
9. Documentation

---

**Estimated Code**: ~1500 lines
**Estimated Tests**: ~25 unit tests, ~10 E2E tests
**Risk**: Medium (algorithm complexity)
**Dependencies**: Phase 3 & 4 complete ✅
