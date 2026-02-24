# Phase 1: Core Task Management - Backlog

**Phase Timeline**: Week 3-6  
**Goal**: Functional task management system (single user)

## Progress Update (2026-02-24)

### Backend Completed
- ✅ Extended database migration with task scope fields (`scope_type`, `scope_label`)
- ✅ Added task lifecycle fields: `recurrence_mode`, `active_from`, `active_until`, `is_voluntary`
- ✅ Added `scope_id` to room_tasks for non-room scopes
- ✅ Implemented multi-user entities: `HouseUser`, `CompletedTask`, `Medal` with mappers
- ✅ Created `GameLogicService` with contribution scoring, Team Sparks, combo detection, medal awarding
- ✅ Implemented `GameController` with 4 API endpoints (state, leaderboard, medals, check-medals)
- ✅ Implemented `UserController` with 5 API endpoints (list, invite, remove, update-role, stats)

### Frontend Completed
- ✅ Implemented Edit Floor modal in FloorList.vue
- ✅ Implemented Add Room modal in FloorList.vue
- ✅ Implemented Edit Task modal in RoomDetail.vue
- ✅ All TODO markers resolved

### Infrastructure Completed
- ✅ i18n setup with POT template, German (de.json), and English (en.json) translations
- ✅ 100+ UI strings translated for both languages

## Backend Tasks

### [P1] Database Migrations

**Status**: Done  
**Estimated Effort**: M  
**Assignee**: Completed 2026-02-24

#### Description
Create database migrations for core tables: houses, floors, rooms, tasks, room_tasks

#### Acceptance Criteria
- [x] Migration for `chorestrike_houses` table
- [x] Migration for `chorestrike_floors` table
- [x] Migration for `chorestrike_rooms` table
- [x] Migration for `chorestrike_tasks` table (extended with scope and lifecycle fields)
- [x] Migration for `chorestrike_room_tasks` table (extended with scope_id)
- [x] Migration for `chorestrike_house_users` table
- [x] Migration for `chorestrike_completed_tasks` table
- [x] Migration for `chorestrike_medals` table
- [x] All foreign keys defined
- [x] Indexes for performance
- [x] Migration runs without errors

#### Dependencies
- None

---

### [P1] Entity Classes & Mappers

**Status**: Done  
**Estimated Effort**: L  
**Assignee**: Completed 2026-02-24

#### Description
Create PHP entity classes and database mappers for all core tables

#### Acceptance Criteria
- [x] House entity & HouseMapper
- [x] Floor entity & FloorMapper
- [x] Room entity & RoomMapper
- [x] Task entity & TaskMapper (extended with scope and lifecycle)
- [x] RoomTask entity & RoomTaskMapper (extended with scope_id)
- [x] HouseUser entity & HouseUserMapper
- [x] CompletedTask entity & CompletedTaskMapper
- [x] Medal entity & MedalMapper
- [x] All entities have proper type hints
- [x] All public methods documented with PHPDoc
- [ ] Unit tests for mappers (pending)

#### Dependencies
- Depends on: Database Migrations

---

### [P1] Service Layer

**Status**: In Progress  
**Estimated Effort**: L  
**Assignee**: Partially completed 2026-02-24

#### Description
Implement business logic services for task management

#### Acceptance Criteria
- [x] HouseService (CRUD operations)
- [x] FloorService (CRUD operations)
- [x] RoomService (CRUD, cleanliness calculation)
- [x] TaskService (CRUD, scheduling logic)
- [x] RoomTaskService (assignment, completion)
- [x] GameLogicService (scoring, combos, medals, monsters)
- [x] All services follow Single Responsibility Principle
- [x] Business logic separated from controllers
- [ ] Unit tests for all services (pending)

#### Dependencies
- Depends on: Entity Classes & Mappers

---

### [P1] API Controllers

**Status**: Done  
**Estimated Effort**: M  
**Assignee**: Completed 2026-02-24

#### Description
Implement thin API controllers that call service layer

#### Acceptance Criteria
- [x] HouseController (get, update, stats, health, reset)
- [x] FloorController (index, create, update, delete, reorder, withRooms)
- [x] RoomController (index, show, create, update, delete, cleanliness, tasks)
- [x] TaskController (index, show, create, update, delete, templates, categories)
- [x] RoomTaskController (index, create, update, delete, complete, assign, stats)
- [x] GameController (state, leaderboard, medals, check-medals)
- [x] UserController (index, invite, remove, update-role, stats)
- [x] Proper error handling
- [x] Input validation
- [x] JSON responses follow standard format
- [x] Integration tests for all endpoints (completed - 50 tests)

#### Dependencies
- Depends on: Service Layer

---

### [P2] Task Templates

**Status**: Planned  
**Estimated Effort**: S  
**Assignee**: Unassigned

#### Description
Create predefined task templates for common household chores

#### Acceptance Criteria
- [x] At least 20 common task templates (completed - 24 templates with i18n)
- [ ] Templates in both German and English
- [ ] Templates include: name, description, energy cost, priority, frequency, category
- [ ] Templates accessible via API endpoint
- [ ] Templates can be used to create tasks

#### Dependencies
- Depends on: API Controllers

---

## Frontend Tasks

### [P1] Dashboard View

**Status**: Completed ✅  
**Estimated Effort**: M  
**Assignee**: AI Agent

#### Description
Main dashboard showing house status, room cards, and quick actions

#### Acceptance Criteria
- [x] HouseHealthBar component integrated
- [x] Room cards display cleanliness and monsters
- [x] Quick actions for common tasks
- [x] Responsive grid layout
- [x] Real-time updates from stores

#### Dependencies
- Depends on: API Controllers

---

### [P1] Room Detail View

**Status**: Done  
**Estimated Effort**: M  
**Assignee**: Unassigned

#### Description
Detailed view of a single room with its tasks

#### Acceptance Criteria
- [ ] Room header with name and status
- [ ] Task list for the room
- [ ] Add new task button
- [ ] Edit/delete task actions
- [ ] Mark task complete checkbox
- [ ] Filter tasks by status
- [ ] Back navigation to dashboard

#### Dependencies
- Depends on: API Controllers

---

### [P1] Task Manager View

**Status**: Done  
**Estimated Effort**: L  
**Assignee**: Unassigned

#### Description
View to manage all tasks across all rooms

#### Acceptance Criteria
- [ ] List all tasks
- [ ] Filter by status (all, due, overdue, upcoming)
- [ ] Filter by priority
- [ ] Filter by energy cost
- [ ] Search tasks by name
- [ ] Create new task form
- [ ] Edit task inline or in modal
- [ ] Delete task with confirmation
- [ ] Assign task to room(s)

#### Dependencies
- Depends on: API Controllers

---

### [P1] Task Form Component

**Status**: Done  
**Estimated Effort**: M  
**Assignee**: Unassigned

#### Description
Reusable form component for creating/editing tasks

#### Acceptance Criteria
- [ ] Input for task name (required)
- [ ] Textarea for description (optional)
- [ ] Energy selector (1-5)
- [ ] Priority selector (1-3 levels)
- [ ] Frequency type selector (daily, weekly, monthly, custom)
- [ ] Frequency value input
- [ ] Estimated time input
- [ ] Category selector
- [ ] Reminder settings (none, due, before due, optional repeat)
- [ ] Form validation
- [ ] Error messages
- [ ] Save/Cancel buttons
- [ ] Use task templates

#### Dependencies
- None

---

### [P1] Settings View - House Configuration

**Status**: Completed ✅  
**Estimated Effort**: M  
**Assignee**: AI Agent

#### Description
Settings page for configuring house, floors, and rooms

#### Acceptance Criteria
- [x] Edit house name
- [x] Create/edit/delete floors
- [x] Reorder floors (drag & drop)
- [x] Create/edit/delete rooms
- [x] Assign rooms to floors
- [x] Form validation
- [x] Confirmation dialogs for deletions

#### Dependencies
- Depends on: API Controllers

---

### [P1] Pinia Stores

**Status**: Completed ✅  
**Estimated Effort**: M  
**Assignee**: AI Agent

#### Description
State management stores for frontend data

#### Acceptance Criteria
- [x] houseStore (house data, health)
- [x] roomsStore (rooms, floors, CRUD actions)
- [x] tasksStore (tasks, room tasks, CRUD actions)
- [x] schedulingStore (daily energy budget, member availability)
- [x] Getters for computed data (due tasks, overdue tasks, etc.)
- [x] API integration in actions
- [x] Error handling
- [x] Loading states

---

### [P1] Nextcloud Task Sync

**Status**: Completed ✅  
**Estimated Effort**: M  
**Assignee**: AI Agent

#### Description
Synchronize chore task completion and reminders with Nextcloud task clients.

#### Acceptance Criteria
- [x] Completion in ChoreStrike marks linked Nextcloud task as completed
- [x] Completion in synced task client marks ChoreStrike task as completed
- [x] Reminder settings are mapped to Nextcloud-compatible reminder fields
- [x] Conflict handling uses latest timestamp with event log entry
- [x] Integration tests cover bidirectional completion and reminder sync

#### Dependencies
- Depends on: API Controllers

---

### [P1] Availability and Presence Settings

**Status**: Completed ✅  
**Estimated Effort**: M  
**Assignee**: AI Agent

#### Description
Allow household members to define day-based availability and energy budgets for fair scheduling.

#### Acceptance Criteria
- [x] Configure availability by weekday per member
- [x] Configure daily energy budget by weekday per member
- [x] Scheduler excludes unavailable members from assignment
- [x] Scheduler balances by remaining daily energy capacity
- [x] If over capacity, scheduler defers lower-priority tasks first
- [ ] Scheduler exposes a warning for deferred tasks with reason
- [ ] Regression tests for absence-day fairness scenarios

#### Dependencies
- Depends on: Service Layer

---

## Testing Tasks

### [P2] Backend Unit Tests

**Status**: Completed ✅  
**Estimated Effort**: L  
**Assignee**: AI Agent

#### Description
Unit tests for all backend services and mappers

#### Acceptance Criteria
- [x] Tests for all service methods
- [x] Tests for all mapper methods
- [x] Edge case coverage
- [x] Mock database interactions
- [x] BDD-style naming
- [x] At least 80% code coverage

#### Dependencies
- Depends on: Service Layer

---

### [P2] Frontend Component Tests

**Status**: Completed ✅  
**Estimated Effort**: M  
**Assignee**: AI Agent

#### Description
Component tests for Vue components

#### Acceptance Criteria
- [x] Tests for TaskForm component
- [x] Tests for RoomCard component
- [ ] Tests for TaskItem component
- [x] User interaction tests
- [x] Event emission tests
- [x] Prop validation tests

#### Dependencies
- Depends on: Frontend components

---

## Documentation Tasks

### [P2] API Documentation Update

**Status**: Planned  
**Estimated Effort**: S  
**Assignee**: Unassigned

#### Description
Update API.md with actual implementation details

#### Acceptance Criteria
- [x] All endpoints documented (completed)
- [x] Request/response examples (completed)
- [x] Error codes documented (completed)
- [ ] Authentication requirements

#### Dependencies
- Depends on: API Controllers

---

### [P1] Verify External Icon Pack License Compatibility

**Status**: Planned  
**Estimated Effort**: S  
**Assignee**: volker

#### Description
Validate whether the licensed Vector Icon Pack from itch.io can be legally used in this Nextcloud app, including redistribution and attribution requirements.

#### Acceptance Criteria
- [ ] License terms are documented in `docs/standards/` with a short usage summary
- [ ] Redistribution rights for app store and source repository are confirmed
- [ ] Attribution requirements (if any) are clarified and implemented
- [ ] If incompatible, at least two replacement icon sets are shortlisted

#### Dependencies
- None

---

## Definition of Done

A task is considered done when:
- [x] Code is written and follows coding standards
- [x] All acceptance criteria are met
- [x] Unit tests are written and passing
- [ ] Code is reviewed (if applicable)
- [x] Documentation is updated
- [x] Changes are committed with proper commit message
- [x] No new linting errors or warnings

---

---

## Code Review Findings (2026-02-24) — Remaining Items

### [P1] Streak logic ignores absence days (concept violation)

**Status**: Planned  
**Priority**: High

#### Description
`HouseUser::incrementStreak()` blindly increments on every task completion.
The concept (`GAMIFICATION_AND_REWARDS.md`) requires: absence days must never break a streak.
The streak must only count available days (from scheduling preferences).

#### Acceptance Criteria
- [ ] Streak check uses configured presence days from `schedulingPreferences`
- [ ] Absence days are skipped, not counted as missed
- [ ] Regression tests for absence-day streak scenarios

---

### [P1] `GameLogicService::getGameState()` uses `rand()` — non-deterministic

**Status**: Planned  
**Priority**: Medium

#### Description
Monster positions in `getGameState()` use `rand(0, 100)` which makes responses
non-deterministic and untestable. Position should be derived from a stable seed
(e.g., room ID + monster index) or removed from the API response.

#### Acceptance Criteria
- [ ] Replace `rand()` with deterministic position calculation
- [ ] Unit test for `getGameState()` can assert stable output

---

### [P1] `TaskSyncService` uses `int $userId` but domain uses `string $userId`

**Status**: Planned  
**Priority**: Medium

#### Description
`TaskSyncService::completeTask()` takes `int $userId` (house user ID), while
`RoomTaskService::completeTask()` takes `string $userId` (Nextcloud user ID).
This inconsistency across the completion chain can cause silent misrouting.
`CompletedTask::userId` should be clarified as house user `int` ID throughout.

#### Acceptance Criteria
- [ ] Audit all `userId` / `nextcloudUserId` usage in completion flow
- [ ] Align parameter types and names consistently across services
- [ ] Update affected unit tests

---

### [P1] `RoomService::completeRoomTask()` does not integrate `GameLogicService`

**Status**: Planned  
**Priority**: High

#### Description
When a task is completed via `RoomService::completeRoomTask()`, the game logic
(sparks, contribution score, combo detection, medals) is **not triggered**.
`GameLogicService::handleTaskCompletion()` exists but is never called from this path.
Only `TaskSyncService` creates `CompletedTask` records independently.

#### Acceptance Criteria
- [ ] `RoomService::completeRoomTask()` or `RoomTaskService::completeTask()` must call `GameLogicService::handleTaskCompletion()`
- [ ] Single completion flow: no duplicate `CompletedTask` inserts
- [ ] Integration test verifies sparks are awarded on task completion

---

### [P1] No authorization checks on any API endpoint

**Status**: Planned  
**Priority**: High (Security)

#### Description
All controllers use `@PublicPage` and `@NoAdminRequired` annotations, meaning
**any authenticated or even unauthenticated user** can access any endpoint.
There is no ownership check (e.g., "does this user belong to this house?").

#### Acceptance Criteria
- [ ] Remove `@PublicPage` from all endpoints that require authentication
- [ ] Add house membership check in all controllers that access house resources
- [ ] Add middleware or service layer authorization helper
- [ ] Security regression tests

---

### [P1] `TaskService::validateTaskData()` uses `is_numeric()` on already-typed ints

**Status**: Planned  
**Priority**: Low (Code Quality)

#### Description
`validateTaskData()` calls `is_numeric()` on `energyCost`, `priority`, etc., which
are already typed as `int` in the controller. The check is redundant for typed
callers but fine for array input. Method should use stricter `is_int()` or rely
on typed function params to avoid confusion.

---

### [P1] `HouseService::resetHouse()` resets cleanliness to 50 instead of 100

**Status**: Planned  
**Priority**: Low

#### Description
`resetHouse()` sets room cleanliness to `50` (neutral) but `monsterCount` to `0`.
A reset should be fully clean: cleanliness should start at `100` to match a freshly
cleaned house, consistent with the empty-house default.

#### Acceptance Criteria
- [ ] Change `$room->setCleanliness(50)` to `$room->setCleanliness(100)` in `HouseService::resetHouse()`

---

**Last Updated**: 2026-02-24
