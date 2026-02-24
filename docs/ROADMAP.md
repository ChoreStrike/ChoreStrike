# ChoreStrike Development Roadmap

## Project Phases

### Phase 0: Foundation ✅ (Current)
**Status**: In Progress  
**Timeline**: Week 1-2

- [x] Project setup and structure
- [x] MIT License
- [x] Documentation (README, CONTRIBUTING, CONCEPT, etc.)
- [x] Agent guidelines
- [x] Basic Nextcloud app structure
- [x] Frontend scaffolding (Vue.js 3)
- [ ] Database schema design
- [ ] Initial migrations
- [ ] Development environment guide

**Deliverable**: Project ready for development

---

### Phase 1: Core Task Management (MVP)
**Status**: Planned  
**Timeline**: Week 3-6

#### Backend
- [ ] Database migrations for core tables
  - [ ] Houses
  - [ ] Floors
  - [ ] Rooms
  - [ ] Tasks
  - [ ] Room-Task assignments
- [ ] API Controllers
  - [ ] HouseController
  - [ ] FloorController
  - [ ] RoomController
  - [ ] TaskController
  - [ ] RoomTaskController
- [ ] Service layer
  - [ ] TaskService (CRUD, scheduling)
  - [ ] RoomService (CRUD, cleanliness calculation)
- [ ] Task templates (predefined common tasks)

#### Frontend
- [ ] Dashboard view
  - [ ] House overview
  - [ ] Floor/Room list
  - [ ] Quick stats
- [ ] Room detail view
  - [ ] Task list for room
  - [ ] Add/edit/delete tasks
  - [ ] Mark tasks complete
- [ ] Task manager view
  - [ ] All tasks overview
  - [ ] Filter by status, priority, energy cost
  - [ ] Create custom tasks
- [ ] Settings view
  - [ ] House configuration
  - [ ] Floor/Room management

#### Features
- [ ] Create/edit/delete rooms
- [ ] Organize rooms into floors
- [ ] Create tasks with:
  - [ ] Name, description
  - [ ] Energy cost (1-5)
  - [ ] Priority (1-3 levels)
  - [ ] Frequency (daily, weekly, monthly, custom)
  - [ ] Estimated time
  - [ ] Reminder settings (Nextcloud task sync compatible)
- [ ] Configure per-member availability (presence days)
- [ ] Configure per-day energy budgets (for example weekday/weekend)
- [ ] Assign tasks to rooms
- [ ] Mark tasks as complete
- [ ] Sync completion state with Nextcloud task clients
- [ ] Basic task scheduling (calculate next due date)
- [ ] Task history

**Deliverable**: Functional task management system (single user)

---

### Phase 2: Basic Gamification
**Status**: Planned  
**Timeline**: Week 7-10

#### Game Mechanics
- [ ] Monster spawn system
  - [ ] Calculate monster count based on overdue tasks
  - [ ] Different monster types per category
- [ ] Room cleanliness calculation
  - [ ] Based on task completion status
  - [ ] Visual indicators (colors)
- [ ] House Health system
  - [ ] Aggregate cleanliness across all rooms
  - [ ] Health bar visualization
- [ ] Points system
  - [ ] Calculate contribution score based on energy + priority
  - [ ] Award Team Sparks on task completion
  - [ ] Keep contribution score and Team Sparks as separate metrics in MVP

#### Visual Design
- [ ] Monster sprites (pixel art)
  - [ ] Dust bunny
  - [ ] Slime blob
  - [ ] Pixel spider
  - [ ] Trash gremlin
- [ ] Monster animations
  - [ ] Idle animation
  - [ ] Spawn animation
  - [ ] Defeat animation
- [ ] Task completion animation
  - [ ] Particle effects
  - [ ] Sound effects (optional)
- [ ] Room status visualization
  - [ ] Clean/Due/Overdue/Critical states
  - [ ] Monster overlay on room cards

#### Frontend Components
- [ ] MonsterCanvas component
- [ ] ParticleEffect component
- [ ] HouseHealthBar component
- [ ] CompletionAnimation component

**Deliverable**: Gamified task completion with visual feedback

---

### Phase 3: Multi-User Support
**Status**: Planned  
**Timeline**: Week 11-14

#### Backend
- [ ] User management
  - [ ] HouseUser entity
  - [ ] User roles (owner, member, child)
  - [ ] Invite system
- [ ] Task assignment
  - [ ] Assign tasks to specific users
  - [ ] Task approval for children
- [ ] Contribution score and Team Sparks tracking per user
- [ ] Streak tracking
- [ ] Completed tasks history per user

#### Frontend
- [ ] User management UI
  - [ ] Invite users
  - [ ] Manage roles
  - [ ] Remove users
- [ ] Leaderboard view
  - [ ] Sort by Team Sparks or contribution score
  - [ ] Filter by time period (week, month, all-time)
  - [ ] User stats
- [ ] Task assignment UI
- [ ] User profile/stats

#### Features
- [ ] Invite Nextcloud users to house
- [ ] Assign tasks to users
- [ ] Select localized role-title naming pack (2-3 packs per language)
- [ ] Leaderboard
- [ ] Individual user stats
- [ ] Streak tracking
- [ ] Task approval workflow for children

**Deliverable**: Multi-user household management

---

### Phase 4: Advanced Game Mechanics
**Status**: Planned  
**Timeline**: Week 15-18

#### Features
- [ ] Combo system
  - [ ] Detect tasks completed within time window
  - [ ] Award Team Sparks bonus
  - [ ] Visual combo notification
- [ ] Streak milestone rewards
  - [ ] Absence-aware streak calculation (absence days do not break streak)
  - [ ] Milestone tool rewards
  - [ ] Task Pass reassignment mechanic with receiver reward boost
- [ ] Weekly medals
  - [ ] The Team Anchor (most high-energy tasks)
  - [ ] The Flow Keeper (most consistent effort)
  - [ ] The Room Guardian (focus on specific rooms)
  - [ ] The Team Player (most combos)
  - [ ] The Streak Keeper (longest streak)
- [ ] Medal display
  - [ ] Medal badges
  - [ ] Medal history
  - [ ] Medal showcase

#### Backend
- [ ] Combo detection logic
- [ ] Medal calculation service
- [ ] Weekly medal awards (cron job)
- [ ] Medal storage

#### Frontend
- [ ] ComboNotification component
- [ ] MedalBadge component
- [ ] Medal showcase view
- [ ] Combo indicator

**Deliverable**: Enhanced gamification with rewards

---

### Phase 5: Smart Scheduling
**Status**: Planned  
**Timeline**: Week 19-22

#### Features
- [ ] User availability settings
  - [ ] Set available time per day
  - [ ] Different schedules per weekday
- [ ] Planning modes
  - [ ] Weekend Warrior
  - [ ] Daily Cleaner
  - [ ] Custom distribution
  - [ ] Auto-balance
- [ ] Smart task suggestions
  - [ ] Based on available time
  - [ ] Prioritize by urgency
  - [ ] Balance energy load
- [ ] Daily task list generation
- [ ] Task scheduling optimization

#### Backend
- [ ] TaskScheduler service
  - [ ] Calculate optimal task distribution
  - [ ] Consider user availability
  - [ ] Balance workload
- [ ] Availability settings storage
- [ ] Scheduling algorithms

#### Frontend
- [ ] Availability settings UI
- [ ] Planning mode selector
- [ ] Daily task list view
- [ ] Schedule preview

**Deliverable**: Intelligent task scheduling

---

### Phase 6: Internationalization (i18n)
**Status**: Planned  
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

**Last Updated**: 2026-02-24  
**Current Phase**: Phase 0 (Foundation)
