# Phase 7: Polish & Optimization - Implementation Plan

**Status**: In Progress  
**Timeline**: Week 25-28  
**Goal**: Production-ready app with optimized performance, improved UX, and comprehensive testing

---

## Current State Analysis (2026-03-04)

### Bundle Size
- **Current**: 727 KiB (main bundle)
- **Target**: < 244 KiB per chunk
- **Gap**: 483 KiB reduction needed

### Lazy Loading Status
- ✅ TaskManager, RoomDetail, Leaderboard, Settings (lazy-loaded via router)
- ❌ Dashboard (eager-loaded)
- ❌ Large components: SchedulingSuggestions (29KB), ScheduleCalendar (26KB)

### Database
- No indexes beyond primary keys
- Queries need optimization analysis

---

## Priority 1: Frontend Performance (P7-FE)

### P7-FE-001: Code Splitting for Large Components
**Status**: Pending  
**Priority**: High

#### Tasks
- [ ] Lazy-load Scheduling components (SchedulingSuggestions, ScheduleCalendar)
- [ ] Lazy-load Gamification components (GameStats, WeeklyChallenge)
- [ ] Split vendor chunk (axios, vue-router, pinia separate)
- [ ] Configure webpack chunk naming

#### Acceptance Criteria
- [ ] Main bundle < 300 KiB
- [ ] Lazy chunks < 100 KiB each
- [ ] First paint time improved by 30%+

### P7-FE-002: Dashboard Optimization
**Status**: Pending  
**Priority**: High

#### Tasks
- [ ] Lazy-load FloorList, RoomCard, HouseHealthBar
- [ ] Virtual scrolling for long room lists
- [ ] Image optimization for monster sprites
- [ ] CSS critical path extraction

#### Acceptance Criteria
- [ ] Dashboard initial render < 500ms
- [ ] Room list scroll 60fps with 50+ rooms

---

## Priority 2: Backend Performance (P7-BE)

### P7-BE-001: Query Optimization
**Status**: Pending  
**Priority**: Medium

#### Tasks
- [ ] Add database indexes for common queries
  - [ ] `tasks.house_id, tasks.assigned_to`
  - [ ] `completed_tasks.user_id, completed_tasks.completed_at`
  - [ ] `availability_settings.user_id, availability_settings.day`
- [ ] Analyze slow queries with EXPLAIN
- [ ] Add query result caching where appropriate

#### Acceptance Criteria
- [ ] All API endpoints < 100ms response time
- [ ] No N+1 query patterns

---

## Priority 3: UX Improvements (P7-UX)

### P7-UX-001: Loading States & Empty States
**Status**: Pending  
**Priority**: Medium

#### Tasks
- [ ] Add skeleton loaders for all async content
- [ ] Improve empty state messaging
- [ ] Add loading indicators for mutations

### P7-UX-002: Mobile Responsiveness
**Status**: Pending  
**Priority**: Medium

#### Tasks
- [ ] Audit mobile breakpoints
- [ ] Touch-friendly interactions
- [ ] Bottom navigation for mobile

---

## Priority 4: Testing Coverage (P7-TEST)

### P7-TEST-001: Unit Test Coverage
**Status**: Pending  
**Priority**: Medium

#### Tasks
- [ ] Backend: 80%+ coverage for services
- [ ] Frontend: 80%+ coverage for stores
- [ ] Component tests for critical paths

### P7-TEST-002: E2E Test Coverage
**Status**: Pending  
**Priority**: Medium

#### Tasks
- [ ] All user flows covered
- [ ] Mobile viewport tests
- [ ] Accessibility tests

---

## Implementation Order

1. **P7-FE-001**: Code splitting (highest impact on bundle size)
2. **P7-FE-002**: Dashboard optimization
3. **P7-BE-001**: Query optimization
4. **P7-UX-001/002**: UX improvements
5. **P7-TEST-001/002**: Testing coverage

---

## Risks and Mitigations

- **Risk**: Code splitting breaks lazy-loaded routes
  - **Mitigation**: Test all routes after webpack changes
- **Risk**: Indexes slow down writes
  - **Mitigation**: Only index read-heavy tables
- **Risk**: Mobile UX requires significant refactor
  - **Mitigation**: Prioritize critical flows only

---

## Immediate Next Actions

1. Implement code splitting for Scheduling components
2. Configure webpack chunk naming and vendor splitting
3. Verify bundle size reduction
