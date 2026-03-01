# Phase 4: Advanced Game Mechanics - Implementation Plan

**Status**: Planning  
**Timeline**: Week 15-18  
**Goal**: Implement combo system, weekly medals, and advanced gamification features

---

## Priority 1: Combo System (P4-BE-001)

### Database Schema
- **ComboTracker Entity**: Track task completion sequences
  - `id` (PK)
  - `house_id` (FK)
  - `user_id` (string)
  - `task_count` (integer) - consecutive tasks in combo
  - `started_at` (datetime)
  - `last_task_at` (datetime)
  - `combo_bonus_points` (integer)

### Backend Implementation
- `ComboService`: Detect and manage combo sequences
  - `startCombo()`: Initialize combo for user
  - `addTaskToCombo()`: Add task to active combo
  - `endCombo()`: Finalize combo and award bonus
  - `getActiveCombo()`: Get current combo status
  - `calculateComboBonus()`: Calculate bonus points (10 points per task)
- `ComboMapper`: CRUD operations for ComboTracker
- Update `GameLogicService`: Integrate combo detection with task completion

### API Endpoints
- `GET /api/v1/game/combo/{userId}` - Get active combo
- `POST /api/v1/game/combo/start` - Start combo
- `PUT /api/v1/game/combo/add-task` - Add task to combo
- `POST /api/v1/game/combo/end` - End combo and award bonus

### Tests
- Unit tests for combo detection logic
- Integration tests for combo with task completion
- Edge cases: combo timeout, task failure, user switch

---

## Priority 2: Weekly Medals (P4-BE-002)

### Database Schema
- **Medal Entity** (enhancement):
  - Add `medal_icon` (string) - emoji or icon name
  - Add `medal_description` (text)
  - Add `rarity` (enum: common, rare, epic, legendary)

### Medal Types
1. **Team Anchor** (Common)
   - Condition: Complete 10+ tasks in a week
   - Points: 50
   - Icon: 🏆

2. **Streak Keeper** (Rare)
   - Condition: Maintain 7+ day streak
   - Points: 100
   - Icon: 🔥

3. **Combo Master** (Rare)
   - Condition: Achieve 5+ task combo
   - Points: 75
   - Icon: ⚡

4. **Perfect Week** (Epic)
   - Condition: Complete all tasks assigned in a week
   - Points: 200
   - Icon: ✨

5. **Legendary Helper** (Legendary)
   - Condition: 100+ total tasks completed
   - Points: 500
   - Icon: 👑

### Backend Implementation
- `MedalService`: Award medals based on conditions
  - `checkAndAwardMedals()`: Weekly check for all users
  - `awardMedal()`: Award specific medal
  - `getUserMedals()`: Get user's medal collection
  - `getMedalStats()`: Get medal statistics
- Update `GameLogicService`: Integrate medal checking with weekly job
- `MedalMapper`: Enhanced queries for medal tracking

### API Endpoints
- `GET /api/v1/game/medals` - Get house medals
- `GET /api/v1/game/medals/{userId}` - Get user medals
- `GET /api/v1/game/medals/stats` - Get medal statistics

### Tests
- Unit tests for medal award conditions
- Integration tests for medal checking
- Edge cases: duplicate medals, medal timing

---

## Priority 3: Leaderboard Enhancements (P4-FE-001)

### Frontend Components
- **MedalDisplay.vue**: Show earned medals
  - Medal grid with icons
  - Medal details on hover
  - Rarity color coding

- **ComboIndicator.vue**: Display active combo
  - Current combo count
  - Bonus points preview
  - Combo timer

- **WeeklyStats.vue**: Weekly statistics
  - Tasks completed this week
  - Current streak
  - Medals earned this week
  - Comparison with previous week

### Leaderboard Enhancements
- Add medal display to leaderboard rows
- Show combo status for active users
- Weekly stats comparison
- Achievement badges

### Features
- Medal showcase on user profile
- Combo notifications
- Weekly medal announcements
- Achievement unlocked animations

---

## Priority 4: Gamification UI (P4-FE-002)

### Components
- **AchievementNotification.vue**: Toast notifications for achievements
  - Medal earned
  - Combo milestone
  - Streak milestone
  - Level up

- **GameStats.vue**: Overall game statistics
  - Total medals earned
  - Total combos achieved
  - Highest streak
  - Total points

- **WeeklyChallenge.vue**: Weekly challenges
  - Challenge description
  - Progress bar
  - Reward preview
  - Difficulty indicator

### Features
- Real-time achievement notifications
- Animated medal unlocks
- Progress tracking for challenges
- Leaderboard animations
- Celebration effects for milestones

---

## Implementation Order

1. **Week 1**: P4-BE-001 (Combo System)
   - Database schema
   - ComboService and ComboMapper
   - API endpoints
   - Unit tests

2. **Week 2**: P4-BE-002 (Weekly Medals)
   - Database enhancements
   - MedalService implementation
   - Medal checking logic
   - API endpoints
   - Tests

3. **Week 3**: P4-FE-001 (Leaderboard Enhancements)
   - MedalDisplay, ComboIndicator, WeeklyStats components
   - Leaderboard integration
   - Medal showcase

4. **Week 4**: P4-FE-002 (Gamification UI)
   - AchievementNotification, GameStats, WeeklyChallenge components
   - Animations and effects
   - E2E tests
   - Polish and optimization

---

## Success Criteria

- [ ] Combo system detects and awards bonuses correctly
- [ ] Weekly medals awarded based on conditions
- [ ] Medal display on leaderboard and profiles
- [ ] Achievement notifications working
- [ ] All E2E tests passing
- [ ] No performance degradation
- [ ] Mobile responsive design

---

## Technical Considerations

### Performance
- Cache combo status to avoid repeated calculations
- Batch medal checking in background job
- Optimize leaderboard queries with combo/medal data

### Data Integrity
- Use transactions for combo and medal updates
- Prevent duplicate medal awards
- Handle concurrent task completions

### User Experience
- Smooth animations for achievements
- Clear feedback for combo progress
- Celebratory effects for milestones
- Mobile-friendly notifications

---

## Dependencies

- Phase 3 (User Management, Points Tracking) - **REQUIRED**
- GameLogicService enhancements
- Database migration system
- Background job scheduler (for weekly medal check)

---

## Next Steps After Phase 4

1. **Phase 5: Smart Scheduling**
   - AI-based task scheduling
   - Availability optimization
   - Workload balancing

2. **Phase 6: Mobile App**
   - Native mobile application
   - Offline support
   - Push notifications

3. **Phase 7: Social Features**
   - Friend system
   - House invitations
   - Shared achievements

---

## Notes

- Combo timeout: 24 hours without task completion
- Weekly medal check: Every Monday at 00:00
- Medal rarity affects display and prestige
- Combo bonus scales with task difficulty
- Consider seasonal medals for special events
