# Phase 2: Multi-User Gamification & Advanced Features - Backlog

**Phase Timeline**: Week 7-12  
**Goal**: Multi-user support, smart scheduling, gamification polish, and Nextcloud integration

## Overview

Phase 2 expands ChoreStrike from a single-user task manager to a collaborative household chore management system with intelligent scheduling, comprehensive gamification, and Nextcloud ecosystem integration.

## Backend Tasks

### [P2-001] Smart Scheduler Service

**Status**: Planned  
**Estimated Effort**: L  
**Assignee**: Unassigned  
**Priority**: High

#### Description
Implement intelligent task scheduling algorithm that considers member availability, energy budgets, fairness, and task priorities.

#### Acceptance Criteria
- [ ] Scheduler assigns tasks based on member availability (days of week)
- [ ] Scheduler respects daily energy budget per member
- [ ] Fairness algorithm prevents overloading single members
- [ ] Priority-based scheduling (higher priority first)
- [ ] Deadline-aware scheduling (approaching deadlines get priority)
- [ ] Conflict resolution when multiple members are eligible
- [ ] Schedule preview API endpoint
- [ ] Unit tests for scheduler algorithms

#### Technical Notes
- Algorithm should consider: `availability[day]`, `dailyEnergyBudget`, `currentStreak`, `currentLoad`
- Weighted scoring system for task assignment
- Regenerate schedules weekly or on-demand

#### Dependencies
- Depends on: HouseUser entity with availability fields
- Depends on: RoomTask scheduler integration

---

### [P2-002] Member Availability System

**Status**: Planned  
**Estimated Effort**: M  
**Assignee**: Unassigned  
**Priority**: High

#### Description
Extend HouseUser entity and API to support availability configuration per weekday.

#### Acceptance Criteria
- [ ] Database migration adds `availability` JSON field to house_users
- [ ] Availability format: `{"monday": true, "tuesday": false, ...}`
- [ ] API endpoints: GET /users/{id}/availability, PUT /users/{id}/availability
- [ ] Default availability: all days available
- [ ] Validation: at least one day must be available
- [ ] Integration with scheduler

#### Dependencies
- Depends on: UserController

---

### [P2-003] Energy Budget Management

**Status**: Planned  
**Estimated Effort**: M  
**Assignee**: Unassigned  
**Priority**: High

#### Description
Daily energy budget configuration per member for balanced workload distribution.

#### Acceptance Criteria
- [ ] Database migration adds `daily_energy_budget` JSON field
- [ ] Budget format: `{"monday": 10, "tuesday": 5, ...}`
- [ ] API endpoints for budget management
- [ ] Default budget: 10 energy units per day
- [ ] Budget validation (min 0, max 20 per day)
- [ ] Integration with scheduler and GameLogicService
- [ ] Warning when scheduler cannot fit all tasks within budgets

#### Dependencies
- Depends on: HouseUser entity
- Depends on: Smart Scheduler

---

### [P2-004] Team Sparks System Enhancement

**Status**: Planned  
**Estimated Effort**: M  
**Assignee**: Unassigned  
**Priority**: Medium

#### Description
Expand the basic Team Sparks system with streaks, bonuses, and spending mechanics.

#### Acceptance Criteria
- [ ] Daily login streak tracking
- [ ] Streak bonus calculation (2x sparks on day 7+)
- [ ] Team combo multiplier for synchronized completions
- [ ] Sparks spending API (rewards, unlocks)
- [ ] Sparks history/audit log
- [ ] Weekly sparks leaderboard
- [ ] Notification when streak is about to break

#### Dependencies
- Depends on: GameLogicService
- Depends on: CompletedTask tracking

---

### [P2-005] Medal System Completion

**Status**: Planned  
**Estimated Effort**: S  
**Assignee**: Unassigned  
**Priority**: Medium

#### Description
Complete medal awarding system with all medal types and weekly awards.

#### Acceptance Criteria
- [ ] Implement all 5 medal types with criteria:
  - [ ] Team Anchor: Most tasks completed (count)
  - [ ] Flow Keeper: Most consecutive days with completions
  - [ ] Room Guardian: Most room-specific tasks
  - [ ] Team Player: Most tasks completed on "voluntary" tasks
  - [ ] Streak Keeper: Longest task completion streak (same task)
- [ ] Weekly medal calculation job (cron or on-demand)
- [ ] Medal award notification system
- [ ] Medal display persistence (once awarded, stays in profile)
- [ ] Medal statistics API

#### Dependencies
- Depends on: Medal entity and mapper
- Depends on: CompletedTask tracking

---

### [P2-006] Monster Evolution System

**Status**: Planned  
**Estimated Effort**: M  
**Assignee**: Unassigned  
**Priority**: Medium

#### Description
Enhance monster collection with evolution, types, and visual states.

#### Acceptance Criteria
- [ ] Monster types: Grime Golem, Dust Bunny, Mold Monster, etc.
- [ ] Monster evolution stages (Baby → Adult → Boss)
- [ ] Room cleanliness affects monster spawn chance
- [ ] Overdue tasks increase monster spawn rate
- [ ] Monster defeat mechanics (completing overdue tasks)
- [ ] Monster collection API
- [ ] Visual monster sprites for each type/stage

#### Dependencies
- Depends on: Room monster_count field
- Depends on: Task completion tracking

---

### [P2-007] Nextcloud Tasks Integration

**Status**: Planned  
**Estimated Effort**: L  
**Assignee**: Unassigned  
**Priority**: High

#### Description
Bidirectional sync between ChoreStrike tasks and Nextcloud Tasks app.

#### Acceptance Criteria
- [ ] Create Nextcloud task when ChoreStrike task is created (optional)
- [ ] Sync task completion both directions
- [ ] Sync due dates
- [ ] Sync reminders/notifications
- [ ] Link ChoreStrike task with Nextcloud task ID
- [ ] Conflict resolution (latest timestamp wins)
- [ ] Enable/disable sync per task
- [ ] Integration tests

#### Dependencies
- Depends on: TaskController
- Depends on: Nextcloud Tasks API

---

### [P2-008] Notification System

**Status**: Planned  
**Estimated Effort**: M  
**Assignee**: Unassigned  
**Priority**: Medium

#### Description
In-app and push notification system for task reminders and gamification events.

#### Acceptance Criteria
- [ ] Notification entity and mapper
- [ ] Notification types: task_due, task_overdue, medal_awarded, streak_warning, etc.
- [ ] API endpoints: list, mark_read, dismiss
- [ ] Real-time notifications (WebSocket or polling)
- [ ] Nextcloud notification integration
- [ ] Email notification option
- [ ] Notification preferences per user

#### Dependencies
- Depends on: HouseUser
- Depends on: Nextcloud Notification API

---

### [P2-009] Task Comment System

**Status**: Planned  
**Estimated Effort**: S  
**Assignee**: Unassigned  
**Priority**: Low

#### Description
Allow users to add comments to tasks for communication and handoff notes.

#### Acceptance Criteria
- [ ] Comment entity and mapper
- [ ] API endpoints: create, list, delete
- [ ] Comments linked to task and user
- [ ] Comment notifications
- [ ] Comment display in task detail view

#### Dependencies
- Depends on: Task/RoomTask entities

---

## Frontend Tasks

### [P2-101] Multi-User Dashboard

**Status**: Planned  
**Estimated Effort**: L  
**Assignee**: Unassigned  
**Priority**: High

#### Description
Enhanced dashboard showing all household members and their contributions.

#### Acceptance Criteria
- [ ] Member list with avatars and spark counts
- [ ] Today's assigned tasks per member
- [ ] Weekly contribution chart
- [ ] Team activity feed
- [ ] Quick assign/unassign actions
- [ ] Responsive member cards

#### Dependencies
- Depends on: UserController API
- Depends on: GameController API

---

### [P2-102] Leaderboard View

**Status**: Planned  
**Estimated Effort**: M  
**Assignee**: Unassigned  
**Priority**: Medium

#### Description
Gamification leaderboard showing rankings by sparks, medals, and contributions.

#### Acceptance Criteria
- [ ] Weekly leaderboard tab
- [ ] All-time leaderboard tab
- [ ] Medal display per user
- [ ] Rank progression visualization
- [ ] Personal rank highlight
- [ ] Medal detail modal

#### Dependencies
- Depends on: GameController leaderboard API
- Depends on: MedalBadge component

---

### [P2-103] Availability Settings UI

**Status**: Planned  
**Estimated Effort**: M  
**Assignee**: Unassigned  
**Priority**: High

#### Description
User interface for configuring availability and energy budgets.

#### Acceptance Criteria
- [ ] Day picker (Mon-Sun) with toggle per day
- [ ] Energy budget slider/input per day
- [ ] Visual weekly calendar view
- [ ] Total weekly energy summary
- [ ] Save/reset actions
- [ ] Validation feedback

#### Dependencies
- Depends on: Availability API endpoints
- Depends on: Energy Budget API endpoints

---

### [P2-104] Schedule Preview Component

**Status**: Planned  
**Estimated Effort**: M  
**Assignee**: Unassigned  
**Priority**: Medium

#### Description
Visual preview of scheduled tasks for the upcoming week.

#### Acceptance Criteria
- [ ] Weekly calendar view with tasks
- [ ] Day columns with assigned member
- [ ] Task cards with energy cost
- [ ] Conflict/warning indicators
- [ ] Regenerate schedule button
- [ ] Accept/modify schedule actions
- [ ] Print/export schedule

#### Dependencies
- Depends on: Smart Scheduler API

---

### [P2-105] Monster Collection Gallery

**Status**: Planned  
**Estimated Effort**: M  
**Assignee**: Unassigned  
**Priority**: Low

#### Description
Visual gallery of collected monsters with evolution and stats.

#### Acceptance Criteria
- [ ] Grid view of caught monsters
- [ ] Monster detail modal with stats
- [ ] Evolution stage display
- [ ] Monster type filtering
- [ ] Achievement progress for monster types
- [ ] Share monster feature

#### Dependencies
- Depends on: Monster system API
- Depends on: MonsterSprite component

---

### [P2-106] Notification Center

**Status**: Planned  
**Estimated Effort**: M  
**Assignee**: Unassigned  
**Priority**: Medium

#### Description
In-app notification center with history and preferences.

#### Acceptance Criteria
- [ ] Notification bell icon with unread count
- [ ] Dropdown/panel with recent notifications
- [ ] Mark all as read action
- [ ] Notification preferences modal
- [ ] Notification type toggles
- [ ] Email notification settings

#### Dependencies
- Depends on: Notification API

---

### [P2-107] Team Sparks Shop

**Status**: Planned  
**Estimated Effort**: M  
**Assignee**: Unassigned  
**Priority**: Low

#### Description
Shop interface for spending earned sparks on rewards and unlocks.

#### Acceptance Criteria
- [ ] Shop categories: Themes, Titles, Boosts
- [ ] Spark balance display
- [ ] Purchase confirmation
- [ ] Inventory view
- [ ] Preview before purchase
- [ ] Admin configurable items

#### Dependencies
- Depends on: Sparks spending API

---

## Infrastructure Tasks

### [P2-201] Background Jobs Setup

**Status**: Planned  
**Estimated Effort**: M  
**Assignee**: Unassigned  
**Priority**: High

#### Description
Configure Nextcloud background jobs for scheduled tasks.

#### Acceptance Criteria
- [ ] Weekly medal calculation job
- [ ] Daily schedule generation job
- [ ] Monster spawn check job
- [ ] Streak break check job
- [ ] Notification batch job
- [ ] Job execution logging

#### Dependencies
- Depends on: Nextcloud Cron system

---

### [P2-202] Performance Optimization

**Status**: Planned  
**Estimated Effort**: M  
**Assignee**: Unassigned  
**Priority**: Medium

#### Description
Optimize database queries and frontend performance.

#### Acceptance Criteria
- [ ] Database index review and optimization
- [ ] API response caching
- [ ] Frontend lazy loading for large lists
- [ ] Image optimization for monster sprites
- [ ] Bundle size optimization
- [ ] Performance benchmarks

---

### [P2-203] Security Audit

**Status**: Planned  
**Estimated Effort**: S  
**Assignee**: Unassigned  
**Priority**: High

#### Description
Security review of multi-user features and permissions.

#### Acceptance Criteria
- [ ] Review all API endpoints for authorization
- [ ] Validate input sanitization
- [ ] Check SQL injection prevention
- [ ] XSS prevention in frontend
- [ ] CSRF token validation
- [ ] Rate limiting on sensitive endpoints
- [ ] Security documentation

---

## Testing Tasks

### [P2-301] Frontend Component Tests

**Status**: In Progress  
**Estimated Effort**: M  
**Assignee**: Unassigned  
**Priority**: High

#### Description
Complete Vue component test coverage.

#### Acceptance Criteria
- [ ] MedalBadge tests (completed)
- [ ] MedalList tests
- [ ] MedalDetails tests
- [ ] QuickStats tests
- [ ] HouseHealthBar tests (completed)
- [ ] RoomCard tests
- [ ] FloorList tests
- [ ] MonsterSprite tests
- [ ] Modal tests (completed)
- [ ] ComboNotification tests

#### Dependencies
- Depends on: Jest/Vitest setup

---

### [P2-302] E2E Test Expansion

**Status**: Planned  
**Estimated Effort**: M  
**Assignee**: Unassigned  
**Priority**: Medium

#### Description
Expand Playwright E2E tests for multi-user scenarios.

#### Acceptance Criteria
- [ ] Multi-user task assignment flow
- [ ] Leaderboard visibility tests
- [ ] Availability settings flow
- [ ] Schedule generation flow
- [ ] Medal awarding flow
- [ ] Cross-browser testing

---

### [P2-303] Load Testing

**Status**: Planned  
**Estimated Effort**: S  
**Assignee**: Unassigned  
**Priority**: Low

#### Description
Load testing for concurrent multi-user access.

#### Acceptance Criteria
- [ ] Simulate 10+ concurrent users
- [ ] API response time benchmarks
- [ ] Database connection pool testing
- [ ] Scheduler performance with many tasks

---

## Documentation Tasks

### [P2-401] API Documentation Update

**Status**: Planned  
**Estimated Effort**: S  
**Assignee**: Unassigned  
**Priority**: Medium

#### Description
Update API.md with new Phase 2 endpoints.

#### Acceptance Criteria
- [ ] Document availability endpoints
- [ ] Document energy budget endpoints
- [ ] Document scheduler endpoints
- [ ] Document notification endpoints
- [ ] Update examples with multi-user scenarios

---

### [P2-402] User Guide

**Status**: Planned  
**Estimated Effort**: M  
**Assignee**: Unassigned  
**Priority**: Low

#### Description
Create end-user documentation for ChoreStrike features.

#### Acceptance Criteria
- [ ] Getting started guide
- [ ] Task management guide
- [ ] Multi-user setup guide
- [ ] Gamification explanation
- [ ] FAQ section

---

## Definition of Done

A task is considered done when:
- [ ] Code is written and follows coding standards (AGENTS.md)
- [ ] All acceptance criteria are met
- [ ] Unit tests are written and passing (backend)
- [ ] Component tests are written (frontend)
- [ ] E2E tests cover critical flows
- [ ] Code is reviewed (if applicable)
- [ ] Documentation is updated
- [ ] Changes are committed with meaningful message
- [ ] No new linting errors or warnings
- [ ] Security review completed (if applicable)

---

**Last Updated**: 2026-02-24

**Next Review**: 2026-03-03
