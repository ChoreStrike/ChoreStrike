# ChoreStrike Development Roadmap

## Project Phases

### Phase 0: Foundation ✅ (Complete)
**Status**: Complete  
**Timeline**: Week 1-2

- [x] Project setup and structure
- [x] MIT License
- [x] Documentation (README, CONTRIBUTING, CONCEPT, etc.)
- [x] Agent guidelines
- [x] Basic Nextcloud app structure
- [x] Frontend scaffolding (Vue.js 3)
- [x] Database schema design
- [x] Initial migrations
- [x] Development environment guide

**Deliverable**: Project ready for development

---

### Phase 1: Core Task Management (MVP) ✅ (Complete)
**Status**: Complete  
**Timeline**: Week 3-6

#### Backend
- [x] Database migrations for core tables
  - [x] Houses
  - [x] Floors
  - [x] Rooms
  - [x] Tasks
  - [x] Room-Task assignments
- [x] API Controllers
  - [x] HouseController
  - [x] FloorController
  - [x] RoomController
  - [x] TaskController
  - [x] RoomTaskController
- [x] Service layer
  - [x] TaskService (CRUD, scheduling)
  - [x] RoomService (CRUD, cleanliness calculation)
- [x] Task templates (predefined common tasks)

#### Frontend
- [x] Dashboard view
  - [x] House overview
  - [x] Floor/Room list
  - [x] Quick stats
- [x] Room detail view
  - [x] Task list for room
  - [x] Add/edit/delete tasks
  - [x] Mark tasks complete
- [x] Task manager view
  - [x] All tasks overview
  - [x] Filter by status, priority, difficulty
  - [x] Create custom tasks
- [x] Settings view
  - [x] House configuration
  - [x] Floor/Room management

#### Features
- [x] Create/edit/delete rooms
- [x] Organize rooms into floors
- [x] Create tasks with:
  - [x] Name, description
  - [x] Difficulty (1-5 stars)
  - [x] Priority (1-3 levels)
  - [x] Frequency (daily, weekly, monthly, custom)
  - [x] Estimated time
- [x] Assign tasks to rooms
- [x] Mark tasks as complete
- [x] Basic task scheduling (calculate next due date)
- [x] Task history

**Deliverable**: Functional task management system (single user)

---

### Phase 2: Basic Gamification ✅ (Complete)
**Status**: Complete  
**Timeline**: Week 7-10

#### Game Mechanics
- [x] Monster spawn system
  - [x] Calculate monster count based on overdue tasks
  - [x] Different monster types per category
- [x] Room cleanliness calculation
  - [x] Based on task completion status
  - [x] Visual indicators (colors)
- [x] House Health system
  - [x] Aggregate cleanliness across all rooms
  - [x] Health bar visualization
- [x] Points system
  - [x] Calculate points based on difficulty + priority
  - [x] Award points on task completion

#### Visual Design
- [x] Monster sprites (pixel art)
  - [x] Dust bunny
  - [x] Slime blob
  - [x] Pixel spider
  - [x] Trash gremlin
- [x] Monster animations
  - [x] Idle animation
  - [x] Spawn animation
  - [x] Defeat animation
- [x] Task completion animation
  - [x] Particle effects
  - [x] Sound effects (optional)
- [x] Room status visualization
  - [x] Clean/Due/Overdue/Critical states
  - [x] Monster overlay on room cards

#### Frontend Components
- [x] MonsterCanvas component
- [x] ParticleEffect component
- [x] HouseHealthBar component
- [x] CompletionAnimation component

**Deliverable**: Gamified task completion with visual feedback

---

### Phase 3: Multi-User Support ✅ (Complete)
**Status**: Complete  
**Timeline**: Week 11-14 (Completed in 1 session)

#### Backend
- [x] User management
  - [x] HouseUser entity (enhanced with roles, scheduling preferences)
  - [x] User roles (owner, member, child)
  - [x] HouseUserService with full CRUD operations
- [x] Task assignment
  - [x] Assign tasks to specific users
  - [x] Database schema for assignment tracking
  - [x] RoomTaskService extensions
- [x] Points tracking per user
  - [x] UserStats entity with points, streaks, task counts
  - [x] UserStatsService with leaderboard queries
  - [x] GameLogicService enhancements
- [x] Streak tracking
  - [x] Current streak calculation
  - [x] Longest streak tracking
  - [x] Streak reset logic
- [x] Completed tasks history per user
  - [x] CompletedTask enhancements with user tracking

#### Frontend
- [x] User management UI
  - [x] UserManagement.vue component
  - [x] Member list display
  - [x] Add user form
  - [x] Role management (select dropdown)
  - [x] Remove user with confirmation dialog
- [x] Leaderboard view
  - [x] Leaderboard.vue component
  - [x] Sort by points (default)
  - [x] Filter by streaks
  - [x] Filter by tasks completed
  - [x] User stats display
  - [x] Current user highlighting
- [x] Task assignment UI (prepared for Phase 4)
- [x] User profile/stats (via leaderboard)

#### Features
- [x] Invite Nextcloud users to house
- [x] Assign tasks to users
- [x] Leaderboard with multiple views
- [x] Individual user stats
- [x] Streak tracking with reset logic
- [x] Task approval workflow (prepared for Phase 4)

#### Testing
- [x] 20+ Unit Tests for UserStatsService, HouseUserService, GameLogicService
- [x] 15+ E2E Tests for User Management and Leaderboard
- [x] Integration test cases

**Deliverable**: Multi-user household management with leaderboards and stats tracking

---

### Phase 4: Advanced Game Mechanics ✅ (Complete)
**Status**: Complete  
**Timeline**: Week 15-18 (Completed ahead of schedule)

#### Features
- [x] Combo system
  - [x] Detect tasks completed within time window
  - [x] Award bonus points (10 points per task)
  - [x] Combo timeout (24 hours)
  - [x] Visual combo notification (AchievementNotification.vue)
- [x] Weekly medals (5 types)
  - [x] Team Anchor (10+ tasks/week) - 50 points
  - [x] Streak Keeper (7+ day streak) - 100 points
  - [x] Combo Master (5+ task combo) - 75 points
  - [x] Perfect Week (all tasks completed) - 200 points
  - [x] Legendary Helper (100+ total tasks) - 500 points
- [x] Medal display
  - [x] Medal badges with rarity colors
  - [x] Medal history (via MedalDisplay component)
  - [x] Medal showcase on profiles (via GameStats)

#### Backend
- [x] Combo detection logic (P4-BE-001)
  - [x] Combo Entity with timeout logic
  - [x] ComboMapper with active combo queries
  - [x] ComboService with bonus calculation
  - [x] Migration Version1012
  - [x] 10 Unit Tests for ComboService
- [x] Medal calculation service (P4-BE-002)
  - [x] MedalService with award logic
  - [x] 5 Medal types with rarity system
  - [x] Weekly medal checking
  - [x] 12 Unit Tests for MedalService
- [x] API endpoints for combo and medals

#### Frontend
- [x] ComboNotification component (P4-FE-001)
- [x] MedalBadge component with rarity display (P4-FE-001)
  - [x] MedalDisplay.vue with rarity colors (common, rare, epic, legendary)
- [x] Combo indicator with timer (P4-FE-001)
  - [x] ComboIndicator.vue with progress tracking
- [x] Achievement notifications (P4-FE-002)
  - [x] AchievementNotification.vue with toast notifications
- [x] Weekly stats display (P4-FE-002)
  - [x] GameStats.vue with comprehensive statistics
  - [x] WeeklyChallenge.vue with progress and rewards

#### Testing
- [x] Unit tests for combo detection (10 tests)
- [x] Unit tests for medal award conditions (12 tests)
- [x] E2E tests for combo and medal features (15+ tests)
- [x] Integration tests for frontend components

**Deliverable**: Enhanced gamification with combo system, weekly medals, achievement notifications, and comprehensive statistics

---

### Phase 5: Smart Scheduling ✅ (Complete)
**Status**: Complete  
**Timeline**: Week 19-22 (Completed ahead of schedule)

#### Features
- [x] User availability settings
  - [x] Set available time per day
  - [x] Different schedules per weekday
  - [x] Energy budget configuration
- [x] Planning modes
  - [x] Weekend Warrior
  - [x] Daily Cleaner
  - [x] Auto-balance
- [x] Smart task distribution
  - [x] Based on available time
  - [x] Prioritize by urgency
  - [x] Balance workload across users
- [x] Daily task list
  - [x] Complete/uncomplete/skip/reschedule actions
  - [x] Energy cost display
  - [x] Priority indicators
- [x] Schedule optimization
  - [x] Workload variance minimization
  - [x] Related task grouping
  - [x] Reassignment suggestions

#### Backend
- [x] TaskSchedulerService with extracted collaborators
  - [x] TaskPriorityScorer
  - [x] AvailabilityCalculator
  - [x] TaskAssignmentAlgorithm
  - [x] ScheduleOptimizer
  - [x] TaskReassignmentSuggester
- [x] AvailabilitySettings entity + migration
- [x] Daily task API endpoints
- [x] SmartScheduler compatibility facade
- [x] 50+ unit tests (TaskSchedulerService + collaborators)

#### Frontend
- [x] AvailabilitySettings.vue (backend integrated)
- [x] DailyTaskList.vue (fully functional)
- [x] Energy budget sliders
- [x] Weekday availability toggles

**Deliverable**: Intelligent task scheduling with availability-based distribution

**Architecture Decision**: See [ADR-005](adr/ADR-005-smart-scheduling.md)

---

### Phase 6: Internationalization (i18n)
**Status**: In Progress (Planning)  
**Timeline**: Week 23-24

#### Features
- [ ] Complete German translation
- [ ] Complete English translation
- [ ] Translation infrastructure
- [ ] Language switcher
- [ ] Locale-aware date/time formatting
- [ ] Translatable task templates
- [ ] Translatable room names (suggestions)

#### Tasks
- [ ] Extract all translatable strings
- [ ] Create .pot template
- [ ] Translate to German
- [ ] Translate to English
- [ ] Test both languages
- [ ] Documentation for translators

**Deliverable**: Fully multilingual app (DE, EN)

---

### Phase 7: Polish & Optimization
**Status**: Planned  
**Timeline**: Week 25-28

#### Performance
- [ ] Frontend optimization
  - [ ] Code splitting
  - [ ] Lazy loading
  - [ ] Virtual scrolling
  - [ ] Image optimization
- [ ] Backend optimization
  - [ ] Query optimization
  - [ ] Caching
  - [ ] Database indexes
- [ ] Load testing
- [ ] Performance profiling

#### UX Improvements
- [ ] Onboarding flow
  - [ ] Welcome wizard
  - [ ] Sample data
  - [ ] Tutorial
- [ ] Improved animations
- [ ] Better error messages
- [ ] Loading states
- [ ] Empty states
- [ ] Keyboard shortcuts
- [ ] Mobile responsiveness

#### Testing
- [ ] Unit tests (PHP)
- [ ] Unit tests (JavaScript)
- [ ] Component tests (Vue)
- [ ] Integration tests
- [ ] E2E tests
- [ ] Accessibility testing

#### Documentation
- [ ] User guide
- [ ] Screenshots
- [ ] Video demo
- [ ] API documentation completion
- [ ] Code documentation

**Deliverable**: Production-ready app

---

### Phase 8: Release Preparation
**Status**: Planned  
**Timeline**: Week 29-30

#### Tasks
- [ ] Security audit
- [ ] Code review
- [ ] Final testing
- [ ] Update all documentation
- [ ] Create release notes
- [ ] Prepare screenshots for app store
- [ ] Submit to Nextcloud App Store
- [ ] Create GitHub release
- [ ] Announcement blog post
- [ ] Social media announcement

**Deliverable**: v1.0.0 Release

---

## Future Enhancements (Post v1.0)

### v1.1 - Statistics & Insights
- [ ] Advanced statistics dashboard
- [ ] Task completion trends
- [ ] Room cleanliness history
- [ ] User performance analytics
- [ ] Export data (CSV, PDF)

### v1.2 - Notifications
- [ ] Task reminder notifications
- [ ] Overdue task alerts
- [ ] Combo opportunity notifications
- [ ] Medal award notifications
- [ ] Email notifications (optional)
- [ ] Push notifications (mobile)

### v1.3 - Integrations
- [ ] Calendar integration (tasks as events)
- [ ] Home Assistant integration
- [ ] IFTTT/Zapier webhooks
- [ ] Smart home device triggers

### v1.4 - Mobile App
- [ ] Native iOS app
- [ ] Native Android app
- [ ] Offline support
- [ ] Push notifications

### v2.0 - Advanced Features
- [ ] Custom game modes
- [ ] Seasonal events
- [ ] Achievement system
- [ ] Custom monster designs
- [ ] Sound effects toggle
- [ ] Theme customization
- [ ] AI-powered task suggestions
- [ ] Voice commands integration

---

## Community Contributions Welcome!

We welcome contributions at any phase. Check [CONTRIBUTING.md](../CONTRIBUTING.md) for guidelines.

### High Priority Areas for Contributors
- Translations (more languages)
- Monster sprite designs
- Sound effects
- Testing
- Documentation improvements
- Bug fixes

---

**Last Updated**: 2026-03-03  
**Current Phase**: Phase 6 (Internationalization) 🔄 Planning
