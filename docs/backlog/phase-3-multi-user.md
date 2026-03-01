# Phase 3: Multi-User Support - Implementation Plan

**Status**: Planning  
**Timeline**: Week 11-14  
**Goal**: Enable multi-user household management with task assignment, points tracking, and leaderboards

---

## Priority 1: User Management Foundation (P3-BE-001)

### Database Schema
- **HouseUser Entity**: Link users to houses with roles
  - `id` (PK)
  - `house_id` (FK)
  - `user_id` (string, Nextcloud user)
  - `role` (enum: owner, member, child)
  - `joined_at` (datetime)
  - `is_active` (boolean)

- **UserStats Entity**: Track per-user metrics
  - `id` (PK)
  - `house_id` (FK)
  - `user_id` (string)
  - `total_points` (integer)
  - `current_streak` (integer)
  - `longest_streak` (integer)
  - `tasks_completed` (integer)
  - `last_completion_date` (datetime)

### Backend Implementation
- `HouseUserMapper`: CRUD operations for HouseUser
- `UserStatsMapper`: Track and update user statistics
- `HouseUserService`: Business logic for user management
- `HouseController::addUser()`: Invite Nextcloud users to house
- `HouseController::removeUser()`: Remove users from house
- `HouseController::updateUserRole()`: Change user roles

### API Endpoints
- `POST /api/v1/houses/{houseId}/users` - Add user to house
- `DELETE /api/v1/houses/{houseId}/users/{userId}` - Remove user
- `PUT /api/v1/houses/{houseId}/users/{userId}` - Update user role
- `GET /api/v1/houses/{houseId}/users` - List house users
- `GET /api/v1/houses/{houseId}/stats` - Get user statistics

### Tests
- Unit tests for HouseUserService
- Integration tests for user management endpoints
- Authorization tests (only owner can manage users)

---

## Priority 2: Task Assignment (P3-BE-002)

### Database Changes
- Add `assigned_to_user_id` to `chorestrike_room_tasks`
- Add `assigned_by_user_id` to track who assigned the task
- Add `assigned_at` timestamp

### Backend Implementation
- `RoomTaskService::assignTask()`: Assign task to user
- `RoomTaskService::unassignTask()`: Remove assignment
- `RoomTaskController::assignTask()`: API endpoint
- Update `markCompleted()` to track which user completed it

### API Endpoints
- `PUT /api/v1/room-tasks/{taskId}/assign` - Assign to user
- `PUT /api/v1/room-tasks/{taskId}/unassign` - Remove assignment

### Tests
- Unit tests for task assignment logic
- Integration tests for assignment endpoints
- Authorization tests (only owner/member can assign)

---

## Priority 3: Points Tracking (P3-BE-003)

### Database Schema
- **CompletedTask Enhancement**:
  - Add `completed_by_user_id` (who actually completed it)
  - Add `points_earned` (calculated based on difficulty + priority)
  - Add `bonus_points` (for streaks, combos, etc.)

### Backend Implementation
- `GameLogicService::calculatePoints()`: Points calculation
  - Base: difficulty * priority * 10
  - Streak bonus: +5 per day streak
  - Combo bonus: +10 per combo
- `UserStatsService::updateStats()`: Update user statistics after completion
- `UserStatsService::calculateStreaks()`: Calculate current/longest streaks

### Tests
- Unit tests for points calculation
- Unit tests for streak calculation
- Integration tests for stats updates

---

## Priority 4: Frontend - User Management UI (P3-FE-001)

### Components
- `UserManagement.vue`: Main user management interface
  - List of house users
  - Add user form (search Nextcloud users)
  - Role selector
  - Remove user button
  - Confirmation dialogs

- `UserCard.vue`: Display user info
  - Avatar
  - Username
  - Role badge
  - Join date
  - Current stats preview

### Views
- Update `SettingsView.vue` to include user management tab

### Features
- Search Nextcloud users by username/email
- Invite multiple users at once
- Change user roles
- Remove users with confirmation
- Show user join dates and activity status

---

## Priority 5: Frontend - Leaderboard (P3-FE-002)

### Components
- `Leaderboard.vue`: Main leaderboard view
  - Ranking table with user positions
  - Points column
  - Streak column
  - Tasks completed column
  - Time period filter (week, month, all-time)

- `UserStats.vue`: Individual user statistics
  - Total points
  - Current streak
  - Longest streak
  - Tasks completed
  - Recent activity

### Views
- New `LeaderboardView.vue`
- Add leaderboard link to main navigation

### Features
- Sort by points, streaks, tasks completed
- Filter by time period
- Highlight current user
- Show rank changes (up/down arrows)
- Responsive design for mobile

---

## Implementation Order

1. **Week 1**: P3-BE-001 (User Management Foundation)
   - Database migrations
   - Mappers and services
   - API endpoints
   - Unit tests

2. **Week 2**: P3-BE-002 (Task Assignment)
   - Database changes
   - Assignment logic
   - API endpoints
   - Tests

3. **Week 3**: P3-BE-003 (Points Tracking)
   - Points calculation
   - Stats updates
   - Streak calculation
   - Tests

4. **Week 4**: P3-FE-001 + P3-FE-002 (Frontend)
   - User management UI
   - Leaderboard view
   - Integration with backend
   - E2E tests

---

## Success Criteria

- [ ] All 5 database entities created and migrated
- [ ] All API endpoints implemented and tested
- [ ] User management UI functional
- [ ] Leaderboard displays correct data
- [ ] Points calculation accurate
- [ ] Streak tracking working
- [ ] Authorization checks in place
- [ ] E2E tests passing
- [ ] Documentation updated

---

## Risks & Mitigations

| Risk | Mitigation |
|------|-----------|
| Complex authorization logic | Start with simple owner-only permissions, expand later |
| Performance with many users | Add database indexes on user_id, house_id |
| Data consistency in points | Use transactions for points updates |
| UI complexity | Start with MVP, add features incrementally |

---

## Next Steps

1. Create Phase 3 backlog file with detailed task breakdown
2. Create database migration for HouseUser and UserStats
3. Implement HouseUserService and HouseUserMapper
4. Create API endpoints for user management
5. Write comprehensive unit tests
6. Create frontend components
7. Integration testing
8. E2E testing
