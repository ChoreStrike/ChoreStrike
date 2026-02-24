# Phase 1: Core Task Management - Backlog

**Phase Timeline**: Week 3-6  
**Goal**: Functional task management system (single user)

## Backend Tasks

### [P1] Database Migrations

**Status**: Planned  
**Estimated Effort**: M  
**Assignee**: Unassigned

#### Description
Create database migrations for core tables: houses, floors, rooms, tasks, room_tasks

#### Acceptance Criteria
- [ ] Migration for `chorewars_houses` table
- [ ] Migration for `chorewars_floors` table
- [ ] Migration for `chorewars_rooms` table
- [ ] Migration for `chorewars_tasks` table
- [ ] Migration for `chorewars_room_tasks` table
- [ ] All foreign keys defined
- [ ] Indexes for performance
- [ ] Migration runs without errors

#### Dependencies
- None

---

### [P1] Entity Classes & Mappers

**Status**: Planned  
**Estimated Effort**: L  
**Assignee**: Unassigned

#### Description
Create PHP entity classes and database mappers for all core tables

#### Acceptance Criteria
- [ ] House entity & HouseMapper
- [ ] Floor entity & FloorMapper
- [ ] Room entity & RoomMapper
- [ ] Task entity & TaskMapper
- [ ] RoomTask entity & RoomTaskMapper
- [ ] All entities have proper type hints
- [ ] All public methods documented with PHPDoc
- [ ] Unit tests for mappers

#### Dependencies
- Depends on: Database Migrations

---

### [P1] Service Layer

**Status**: Planned  
**Estimated Effort**: L  
**Assignee**: Unassigned

#### Description
Implement business logic services for task management

#### Acceptance Criteria
- [ ] HouseService (CRUD operations)
- [ ] FloorService (CRUD operations)
- [ ] RoomService (CRUD, cleanliness calculation)
- [ ] TaskService (CRUD, scheduling logic)
- [ ] RoomTaskService (assignment, completion)
- [ ] All services follow Single Responsibility Principle
- [ ] Business logic separated from controllers
- [ ] Unit tests for all services

#### Dependencies
- Depends on: Entity Classes & Mappers

---

### [P1] API Controllers

**Status**: Planned  
**Estimated Effort**: M  
**Assignee**: Unassigned

#### Description
Implement thin API controllers that call service layer

#### Acceptance Criteria
- [ ] HouseController (get, update)
- [ ] FloorController (index, create, update, delete)
- [ ] RoomController (index, show, create, update, delete)
- [ ] TaskController (index, show, create, update, delete, templates)
- [ ] RoomTaskController (index, create, update, delete, complete)
- [ ] Proper error handling
- [ ] Input validation
- [ ] JSON responses follow standard format
- [ ] Integration tests for all endpoints

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
- [ ] At least 20 common task templates
- [ ] Templates in both German and English
- [ ] Templates include: name, description, energy cost, priority, frequency, category
- [ ] Templates accessible via API endpoint
- [ ] Templates can be used to create tasks

#### Dependencies
- Depends on: API Controllers

---

## Frontend Tasks

### [P1] Dashboard View

**Status**: Planned  
**Estimated Effort**: M  
**Assignee**: Unassigned

#### Description
Main dashboard showing house overview, floors, rooms, and quick stats

#### Acceptance Criteria
- [ ] House health display (placeholder for now)
- [ ] Floor list with room cards
- [ ] Room cards show cleanliness status
- [ ] Quick stats: total tasks, due tasks, overdue tasks
- [ ] Responsive layout
- [ ] Loading states
- [ ] Empty states

#### Dependencies
- Depends on: API Controllers

---

### [P1] Room Detail View

**Status**: Planned  
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

**Status**: Done  
**Estimated Effort**: M  
**Assignee**: Unassigned

#### Description
Settings page for configuring house, floors, and rooms

#### Acceptance Criteria
- [ ] Edit house name
- [ ] Create/edit/delete floors
- [ ] Reorder floors (drag & drop)
- [ ] Create/edit/delete rooms
- [ ] Assign rooms to floors
- [ ] Form validation
- [ ] Confirmation dialogs for deletions

#### Dependencies
- Depends on: API Controllers

---

### [P1] Pinia Stores

**Status**: Planned  
**Estimated Effort**: M  
**Assignee**: Unassigned

#### Description
State management stores for frontend data

#### Acceptance Criteria
- [ ] houseStore (house data, health)
- [ ] roomsStore (rooms, floors, CRUD actions)
- [ ] tasksStore (tasks, room tasks, CRUD actions)
- [ ] schedulingStore (daily energy budget, member availability)
- [ ] Getters for computed data (due tasks, overdue tasks, etc.)
- [ ] API integration in actions
- [ ] Error handling
- [ ] Loading states

---

### [P1] Nextcloud Task Sync

**Status**: Planned  
**Estimated Effort**: M  
**Assignee**: Unassigned

#### Description
Synchronize chore task completion and reminders with Nextcloud task clients.

#### Acceptance Criteria
- [ ] Completion in ChoreWars marks linked Nextcloud task as completed
- [ ] Completion in synced task client marks ChoreWars task as completed
- [ ] Reminder settings are mapped to Nextcloud-compatible reminder fields
- [ ] Conflict handling uses latest timestamp with event log entry
- [ ] Integration tests cover bidirectional completion and reminder sync

#### Dependencies
- Depends on: API Controllers

---

### [P1] Availability and Presence Settings

**Status**: Planned  
**Estimated Effort**: M  
**Assignee**: Unassigned

#### Description
Allow household members to define day-based availability and energy budgets for fair scheduling.

#### Acceptance Criteria
- [ ] Configure availability by weekday per member
- [ ] Configure daily energy budget by weekday per member
- [ ] Scheduler excludes unavailable members from assignment
- [ ] Scheduler balances by remaining daily energy capacity
- [ ] If over capacity, scheduler defers lower-priority tasks first
- [ ] Scheduler exposes a warning for deferred tasks with reason
- [ ] Regression tests for absence-day fairness scenarios

#### Dependencies
- Depends on: Service Layer

---

## Testing Tasks

### [P2] Backend Unit Tests

**Status**: Planned  
**Estimated Effort**: L  
**Assignee**: Unassigned

#### Description
Unit tests for all backend services and mappers

#### Acceptance Criteria
- [ ] Tests for all service methods
- [ ] Tests for all mapper methods
- [ ] Edge case coverage
- [ ] Mock database interactions
- [ ] BDD-style naming
- [ ] At least 80% code coverage

#### Dependencies
- Depends on: Service Layer

---

### [P2] Frontend Component Tests

**Status**: Planned  
**Estimated Effort**: M  
**Assignee**: Unassigned

#### Description
Component tests for Vue components

#### Acceptance Criteria
- [ ] Tests for TaskForm component
- [ ] Tests for RoomCard component
- [ ] Tests for TaskItem component
- [ ] User interaction tests
- [ ] Event emission tests
- [ ] Prop validation tests

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
- [ ] All endpoints documented
- [ ] Request/response examples
- [ ] Error codes documented
- [ ] Authentication requirements

#### Dependencies
- Depends on: API Controllers

---

## Definition of Done

A task is considered done when:
- [ ] Code is written and follows coding standards
- [ ] All acceptance criteria are met
- [ ] Unit tests are written and passing
- [ ] Code is reviewed (if applicable)
- [ ] Documentation is updated
- [ ] Changes are committed with proper commit message
- [ ] No new linting errors or warnings

---

**Last Updated**: 2026-02-24
