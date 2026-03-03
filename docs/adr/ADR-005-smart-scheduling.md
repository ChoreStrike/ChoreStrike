# ADR-005: Smart Scheduling Architecture

**Status**: Accepted  
**Date**: 2026-03-03  
**Deciders**: Development Team

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
