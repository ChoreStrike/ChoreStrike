# ADR-005: Smart Scheduling Architecture

**Status**: Superseded (2026-08-09)  
**Date**: 2026-03-03  
**Deciders**: Development Team

> **Superseded by removal (CS-PLAN-025 / CR-SVC-08):** the `TaskSchedulerService`
> architecture described below — together with `SmartSchedulerService`,
> `SmartSchedulerController`, the five `Scheduling/*` collaborators
> (`AvailabilityCalculator`, `ScheduleOptimizer`, `TaskAssignmentAlgorithm`,
> `TaskPriorityScorer`, `TaskReassignmentSuggester`), and the orphan
> `ComboService` / `HouseUserService` / `UserStatsService` — was unreachable
> after the smart-schedule routes were removed for an authorization gap
> (CS-016, 2026-07-28) and has been deleted. The **live** scheduling surface is
> `SchedulingController` + `SchedulingService` (preferences, energy, availability,
> suggestions). This ADR is retained for historical context only; do not revive
> the deleted classes without re-establishing `AuthorizationService` gating.
>
> **Follow-up truth (CS-PLAN-055, 2026-08-29):** the `AvailabilitySettings`
> entity/mapper and its `chorestrike_availability` table (Version1013) were the
> last orphans of this era — deleted with their dead migration steps; member
> availability/energy live solely in `house_users.scheduling_preferences`
> (JSON). The task-level mutation routes listed under *API Endpoints*
> (`tasks/daily`, `tasks/{id}/complete|skip|reschedule`) were pruned too
> (only `uncomplete` survives, as the room-task undo path).

## Context

ChoreStrike needed an intelligent task scheduling system to automatically distribute tasks across household members based on availability, workload balancing, and task priority. The system had to replace a legacy `SmartSchedulerService` that had design flaws (invalid method calls on non-existent entities) while maintaining backward compatibility for existing API consumers.

Key requirements:
- Schedule tasks based on user availability and energy budgets
- Balance workload fairly across household members
- Prioritize urgent/overdue tasks
- Support multiple planning modes (weekend warrior, daily cleaner, etc.)
- Provide daily task lists for users
- Maintain backward compatibility with `/api/v1/smart-schedule/*` endpoints

## Decision

We implemented a **new TaskSchedulerService architecture** with extracted collaborators and a **compatibility facade** for legacy endpoints.

### Architecture Components

1. **TaskSchedulerService** (`lib/Service/TaskSchedulerService.php`)
   - Main orchestrator for schedule generation
   - Uses extracted helper classes via dependency injection
   - Public API: `generateWeeklySchedule()`, `optimizeSchedule()`, `suggestReassignment()`

2. **Extracted Collaborators** (Single Responsibility Principle)
   - `TaskPriorityScorer` - Priority calculation algorithms
   - `AvailabilityCalculator` - User availability and capacity checks
   - `TaskAssignmentAlgorithm` - Core distribution logic
   - `ScheduleOptimizer` - Schedule refinement and balancing
   - `TaskReassignmentSuggester` - Reassignment recommendations

3. **Compatibility Layer**
   - `SmartSchedulerService` - Facade delegating to new `TaskSchedulerService`
   - Maintains legacy method signatures for existing consumers
   - Removes invalid `RoomTask` method dependencies

4. **Data Model**
   - `AvailabilitySettings` entity (migration Version1013Date20260301210000)
   - Stores weekday availability, energy budgets, planning mode
   - Links Nextcloud user IDs to house-specific preferences

5. **API Endpoints**
   - `GET /api/v1/tasks/daily` - Daily task list for authenticated user
   - `PUT /api/v1/tasks/{id}/complete|uncomplete|skip|reschedule` - Task mutations
   - `GET /api/v1/smart-schedule/availability` - Member availability (legacy compatible)
   - `PUT /api/v1/smart-schedule/availability|energy-budget` - Update preferences

## Consequences

### Positive

- **Modularity**: Each collaborator has a single, well-defined responsibility
- **Testability**: 50+ unit tests covering all collaborators with deterministic mocks
- **Backward Compatibility**: Existing frontend code continues to work without changes
- **Performance**: Bulk data loading with per-request caching in `RoomTaskService`
- **API Consistency**: 404 for hidden/missing resources, 403 for forbidden actions (Option-3 semantics)
- **Maintainability**: Reduced file sizes (TaskSchedulerService: 372 → ~130 lines)

### Negative

- **Complexity**: More classes to understand and maintain
- **Migration Effort**: Required careful coordination between new service and legacy facade
- **Dual ID Handling**: Both `userId` (Nextcloud) and `houseUserId` (internal) in payloads

## Technical Details

### Priority Scoring Algorithm
```php
priority_score = (
    task_priority * 10 +
    days_until_due * 5 +
    overdue_days * 20 +
    room_cleanliness_weight * 3
)
```

### Authorization Semantics (Option-3)
- `404 RESOURCE_NOT_FOUND` - Task exists but user has no access (hidden/foreign)
- `403 FORBIDDEN` - Task is accessible but action is not permitted

### Payload Identity
```php
// Member availability response
[
    'userId' => 'nextcloud-uid',      // Public API identifier
    'houseUserId' => 123,             // Internal DB reference (kept for compatibility)
    'userName' => 'Display Name',
    'energyBudget' => [...],
    'weekdayAvailability' => [...],
]
```

## References

- `docs/backlog/phase-5-smart-scheduling.md` - Implementation plan
- `lib/Service/TaskSchedulerService.php` - Main service
- `lib/Service/Scheduling/*` - Extracted collaborators
- `tests/unit/Service/TaskSchedulerServiceTest.php` - Test suite (11 tests, 51 assertions)
