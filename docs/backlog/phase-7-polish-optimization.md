# Phase 7: Polish & Optimization - Implementation Plan

**Status**: In Progress  
**Timeline**: Week 25-28  
**Goal**: Production-ready app with optimized performance, improved UX, and comprehensive testing

---

## Current State Analysis (2026-03-04)

### Bundle Size
- **Initial**: 727 KiB (single bundle)
- **After P7-FE-001**: 630 KiB (split into multiple chunks)
- **Improvement**: -13% reduction, lazy-loaded routes

### Lazy Loading Status
- ✅ Dashboard, TaskManager, RoomDetail, Leaderboard, Settings (all lazy-loaded with named chunks)
- ✅ Vendor chunks separated (vue, nextcloud, general vendor)

### Database
- ✅ Indexes added for all common query patterns
- Migration: Version1015Date20260304000000

---

## Priority 1: Frontend Performance (P7-FE)

### P7-FE-001: Code Splitting for Large Components ✅
**Status**: Complete  
**Priority**: High

#### Tasks
- [x] Lazy-load all route components with named chunks
- [x] Split vendor chunk (axios, vue-router, pinia separate)
- [x] Configure webpack chunk naming
- [x] Fix webpack 5 + axios compatibility

#### Results
- Bundle size reduced: 727 KiB → 630 KiB (-13%)
- All routes lazy-loaded
- Vendor chunks properly separated

### P7-FE-002: Dashboard Optimization ✅
**Status**: Complete  
**Priority**: High

#### Tasks
- [x] Dashboard now lazy-loaded like other routes

---

## Priority 2: Backend Performance (P7-BE)

### P7-BE-001: Query Optimization ✅
**Status**: Complete  
**Priority**: Medium

#### Tasks
- [x] Add database indexes for common queries
  - [x] `house_users`: house_id, house_id+nextcloud_user_id, contribution_score
  - [x] `room_tasks`: room_id, assigned_to_user_id, next_due, scheduled_for, scope_id
  - [x] `completed_tasks`: user_id, user_id+completed_at, room_task_id
  - [x] `user_stats`: house_id, house_id+total_points, house_id+user_id
  - [x] `availability`: house_id, house_id+user_id
  - [x] `task_scopes`: house_id, house_id+type
  - [x] `task_claims`: room_task_id, claimed_by_user
  - [x] `task_participants`: completed_task_id, user_id

#### Acceptance Criteria
- [x] All mapper queries now have supporting indexes

---

## Priority 3: UX Improvements (P7-UX)

### P7-UX-001: Loading States & Empty States ✅
**Status**: Complete  
**Priority**: Medium

#### Tasks
- [x] Add skeleton loaders for Dashboard
  - [x] Header skeleton
  - [x] Health bar skeleton
  - [x] Stats skeleton
  - [x] Floors skeleton
- [x] Add skeleton loaders for TaskList
  - [x] Task item skeletons with animated gradient
  - [x] Mimics actual task layout

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
