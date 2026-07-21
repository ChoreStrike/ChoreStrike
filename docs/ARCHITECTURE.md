# ChoreStrike Architecture

## Overview

ChoreStrike is built as a Nextcloud app following the standard Nextcloud app architecture with Vue.js 3 frontend and PHP backend.

## Technology Stack

### Backend
- **PHP 8.0+**: Server-side logic
- **Nextcloud App Framework**: Controllers, Database abstraction
- **Doctrine DBAL**: Database queries
- **PSR-12**: Coding standard

### Frontend
- **Vue.js 3**: UI framework (Composition API)
- **Pinia**: State management
- **Vue Router**: Client-side routing
- **Nextcloud Vue Components**: UI components
- **Webpack 5**: Build tool

### Database
- **Nextcloud DB Abstraction**: Supports SQLite, MySQL, PostgreSQL
- **Migrations**: Schema versioning

## Directory Structure

```
ChoreStrike/
├── appinfo/                    # Nextcloud app metadata & routing
│   ├── info.xml                # App metadata (id, version, URLs, navigation)
│   └── routes.php              # REST API route definitions
├── lib/                        # PHP backend
│   ├── Controller/             # Thin API controllers (House, Floor, Room, Task,
│   │                           #   RoomTask, Checklist, Scheduling, SmartScheduler,
│   │                           #   Minigame, RewardConfig, User, UserStats, Template,
│   │                           #   TaskSync, Game, Page, Test) + ControllerLoggingTrait
│   ├── Service/                # Business logic (House, HouseUser, Floor, Room, RoomTask,
│   │                           #   Task, GameLogic, Medal, Combo, Scheduling, SmartScheduler,
│   │                           #   TaskScheduler, Minigame, RewardConfig, Template, TaskSync,
│   │                           #   UserStats, TestData, Authorization, AppLogger)
│   ├── Db/                     # Entities & mappers (House, Floor, Room, Task, RoomTask,
│   │                           #   CompletedTask, HouseUser, Medal, Combo, MinigameScore,
│   │                           #   TaskChecklistItem + progress, TaskClaim, TaskParticipant,
│   │                           #   TaskScope, UserStats, AvailabilitySettings)
│   ├── Migration/              # Schema migrations (Version1000 … Version1024)
│   └── Command/                # occ commands (e.g. TestDataSeed)
├── src/                        # Vue 3 frontend
│   ├── main.js                 # Entry point
│   ├── App.vue                 # Root component
│   ├── router.js               # Route definitions (lazy-loaded)
│   ├── components/
│   │   ├── Dashboard/          # HouseHealthBar, FloorList, RoomCard, QuickStats, ArcadeSection
│   │   ├── Tasks/              # TaskList, TaskForm, TaskEditForm, TaskChecklist,
│   │   │                       #   FlexWindowDisplay, TemplateSelector, OverdueClaimDialog
│   │   ├── Minigames/          # SnakeGame, SnakeLeaderboard, BoostShop, Game2048, MemoryGame
│   │   ├── Common/             # Icon, SvgIcon, Emoji, Modal, MedalBadge
│   │   ├── Scheduling/         # DailyTaskList
│   ├── constants/              # Shared constants (taskVisibility)
│   ├── views/                  # Dashboard, RoomDetail, TaskManager, Leaderboard,
│   │                           #   Settings, SchedulingView
│   ├── store/                  # Pinia stores (house, rooms, roomTasks, tasks, game,
│   │                           #   user, scheduling, checklist, minigame)
│   └── utils/                  # currentUser, icons, roomIcons, logger, mobile, performance
├── l10n/                       # Translations
│   ├── templates/
│   │   └── chorestrike.pot     # Translation template
│   ├── de.json                 # German
│   └── en.json                 # English
├── img/                        # App icons
│   └── app.svg
├── tests/                      # Test suites
│   ├── unit/                   # PHPUnit (PHP) + Jest (JS/Vue)
│   ├── integration/            # PHPUnit integration tests
│   └── e2e/                    # Playwright end-to-end tests
├── docs/                       # Documentation
│   ├── README.md               # Documentation map
│   ├── ARCHITECTURE.md         # This file
│   ├── DEVELOPMENT.md          # Dev setup guide
│   ├── TESTING.md              # Test strategy
│   ├── DEPLOYMENT.md           # Install/deploy/rollback guide
│   ├── API.md                  # REST API reference
│   ├── REVIEW_PLAYBOOK.md      # Review workflow
│   ├── ROADMAP.md              # Project roadmap
│   ├── concepts/               # Product vision & design concepts
│   ├── standards/              # ai/ (rules, skills) + guidelines/
│   ├── backlog/                # Task tracking (ACTIVE_TASKS.md, phase-*.md)
│   └── plans/                  # Implementation plans (new/active/onhold/done)
├── .github/                    # Issue & PR templates (Forgejo-compatible)
│   ├── ISSUE_TEMPLATE/
│   └── pull_request_template.md
├── .forgejo/                   # Codeberg/Forgejo CI
│   └── workflows/test.yml
├── private/                    # Local dev only (gitignored)
├── LICENSE
├── README.md
├── SECURITY.md
├── CONTRIBUTING.md
├── CHANGELOG.md
├── .gitignore
├── composer.json
├── composer.lock               # Pinned PHP deps (vendor/ is gitignored)
├── package.json
├── webpack.config.js
└── jest.config.js
```

## Data Flow

### Request Flow (API Call)

```
Frontend (Vue Component)
    ↓
Store Action (Pinia)
    ↓
API Service (axios)
    ↓
HTTP Request
    ↓
Route (routes.php)
    ↓
Controller
    ↓
Service Layer (Business Logic)
    ↓
Mapper (Database Access)
    ↓
Database
    ↓
Response (JSON)
    ↓
Store Mutation
    ↓
Component Update (Reactive)
```

### Example: Completing a Task

1. **User clicks "Complete" button** → `TaskItem.vue`
2. **Component emits event** → `RoomView.vue`
3. **Store action called** → `tasks.completeTask(taskId)`
4. **API request** → `POST /api/v1/room-tasks/{id}/complete`
5. **Controller** → `RoomTaskController::complete()`
6. **Service logic** → `GameLogicService::handleTaskCompletion()`
   - Calculate points
   - Check for combos
   - Update room cleanliness
   - Reduce monster count
   - Award medals if applicable
7. **Database update** → Multiple mappers
8. **Response** → Updated task, points, game state
9. **Store update** → Reactive state changes
10. **UI updates** → Animation plays, monsters disappear

## Database Schema

### Core Tables

#### `ChoreStrike_houses`
- `id` (bigint, PK)
- `nextcloud_instance_id` (string) - For multi-instance support
- `name` (string)
- `house_health` (int, 0-100)
- `created_at` (datetime)
- `updated_at` (datetime)

#### `ChoreStrike_floors`
- `id` (bigint, PK)
- `house_id` (bigint, FK)
- `name` (string)
- `sort_order` (int)
- `created_at` (datetime)

#### `ChoreStrike_rooms`
- `id` (bigint, PK)
- `floor_id` (bigint, FK)
- `name` (string)
- `cleanliness` (int, 0-100)
- `monster_count` (int)
- `last_cleaned` (datetime, nullable)
- `created_at` (datetime)

#### `ChoreStrike_tasks`
- `id` (bigint, PK)
- `name` (string)
- `description` (text, nullable)
- `difficulty` (int, 1-5)
- `priority` (int, 1-3)
- `frequency_type` (enum: daily, weekly, monthly, custom)
- `frequency_value` (int) - e.g., every 3 days
- `estimated_minutes` (int)
- `category` (string) - cleaning, organizing, maintenance
- `is_template` (boolean)
- `is_private` (boolean) - **legacy column** (kept for back-compat; `visibility_type` is the source of truth)
- `visibility_type` (string, default `public`) - `public` | `private` | `admin-only` (CS-PLAN-005)
- `owner_user_id` (string, nullable) - creator of private tasks (Nextcloud UID)
- `created_at` (datetime)
- Index `idx_tasks_visibility` on `(visibility_type, owner_user_id)`

> **Visibility matrix:** `public` = visible to all house members; `private` = visible only to `owner_user_id`; `admin-only` = visible only to house owners. Enforced in `TaskMapper::findVisibleByHouse()`, `TaskController::index()`/`show()`/`complete()`.

#### `ChoreStrike_room_tasks`
- `id` (bigint, PK)
- `room_id` (bigint, FK)
- `task_id` (bigint, FK)
- `next_due` (datetime)
- `last_completed` (datetime, nullable)
- `assigned_to_user_id` (bigint, FK, nullable)
- `is_active` (boolean)
- `created_at` (datetime)

#### `ChoreStrike_house_users`
- `id` (bigint, PK)
- `house_id` (bigint, FK)
- `nextcloud_user_id` (string)
- `display_name` (string)
- `total_points` (int)
- `current_streak` (int)
- `last_activity` (datetime)
- `role` (enum: owner, member, child)
- `joined_at` (datetime)

#### `ChoreStrike_completed_tasks`
- `id` (bigint, PK)
- `room_task_id` (bigint, FK)
- `user_id` (bigint, FK)
- `completed_at` (datetime)
- `points_earned` (int)
- `was_combo` (boolean)
- `combo_with_user_id` (bigint, FK, nullable)

#### `ChoreStrike_medals`
- `id` (bigint, PK)
- `user_id` (bigint, FK)
- `medal_type` (enum: tank, scout, specialist, teamplayer, streak_keeper)
- `week_start` (date)
- `value` (int) - e.g., number of tasks
- `awarded_at` (datetime)

### Indexes

```sql
-- Performance indexes
CREATE INDEX idx_room_tasks_next_due ON ChoreStrike_room_tasks(next_due);
CREATE INDEX idx_room_tasks_room_id ON ChoreStrike_room_tasks(room_id);
CREATE INDEX idx_completed_tasks_user_id ON ChoreStrike_completed_tasks(user_id);
CREATE INDEX idx_completed_tasks_completed_at ON ChoreStrike_completed_tasks(completed_at);
CREATE INDEX idx_house_users_house_id ON ChoreStrike_house_users(house_id);
```

## API Design

### RESTful Principles

- **GET**: Retrieve data
- **POST**: Create new resource
- **PUT**: Update existing resource
- **DELETE**: Remove resource

### Response Format

```json
{
  "success": true,
  "data": {
    // Resource data
  },
  "meta": {
    "timestamp": "2026-02-24T12:00:00Z"
  }
}
```

### Error Format

```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "Task name is required",
    "details": {
      "field": "name"
    }
  }
}
```

## State Management (Pinia)

### Store Structure

#### `useHouseStore`
```javascript
{
  state: {
    house: null,
    health: 100,
    loading: false
  },
  actions: {
    fetchHouse(),
    updateHealth()
  }
}
```

#### `useRoomsStore`
```javascript
{
  state: {
    rooms: [],
    floors: [],
    currentRoom: null
  },
  actions: {
    fetchRooms(),
    createRoom(),
    updateRoom(),
    deleteRoom()
  },
  getters: {
    roomsByFloor(),
    overdueRooms()
  }
}
```

#### `useTasksStore`
```javascript
{
  state: {
    tasks: [],
    templates: [],
    roomTasks: []
  },
  actions: {
    fetchTasks(),
    completeTask(),
    createTask()
  },
  getters: {
    dueTasks(),
    overdueTasks()
  }
}
```

#### `useGameStore`
```javascript
{
  state: {
    monsters: {},
    combos: [],
    medals: []
  },
  actions: {
    checkCombo(),
    awardMedal()
  }
}
```

## Security Considerations

### Authentication
- Nextcloud session-based auth
- CSRF token validation
- User permissions checked in controllers

### Authorization
- Users can only access their house
- Room/task ownership validation
- Admin vs. member permissions

### Input Validation
- All inputs sanitized
- Type checking
- Length limits
- SQL injection prevention (prepared statements)
- XSS prevention (Vue auto-escaping)

## Performance Optimization

### Backend
- Database query optimization
- Eager loading for related entities
- Caching for frequently accessed data
- Pagination for large lists

### Frontend
- Lazy loading routes
- Virtual scrolling for long lists
- Debounced search/filter
- Image sprites for monsters
- CSS animations over JS

### Network
- API response compression
- Batch requests where possible
- Optimistic UI updates
- WebSocket for real-time updates (future)

## Testing Strategy

### Unit Tests (PHP)
- Service logic
- Point calculation
- Task scheduling
- Game mechanics

### Unit Tests (JavaScript)
- Store actions
- Utility functions
- Game logic helpers

### Component Tests (Vue)
- User interactions
- Event emissions
- Prop validation

### Integration Tests
- API endpoints
- Database operations
- Multi-user scenarios

### E2E Tests (Future)
- Complete user flows
- Cross-browser testing

## Deployment

### Development
```bash
npm install
npm run dev
composer install
```

### Production Build
```bash
npm run build
composer install --no-dev --optimize-autoloader
```

### Nextcloud Installation
1. Copy app to `apps/ChoreStrike/`
2. Enable in Nextcloud admin panel
3. Database migrations run automatically

## Monitoring & Logging

### Nextcloud Logging
```php
$this->logger->info('Task completed', [
    'taskId' => $task->getId(),
    'userId' => $user->getId()
]);
```

### Frontend Error Tracking
```javascript
window.addEventListener('error', (event) => {
    // Log to Nextcloud
    console.error('[ChoreStrike]', event.error)
})
```

## Future Enhancements

- **WebSocket**: Real-time updates for multi-user
- **Push Notifications**: Task reminders
- **Mobile App**: Native iOS/Android
- **Statistics Dashboard**: Advanced analytics
- **AI Suggestions**: Smart task scheduling
- **Integrations**: Calendar, Home Assistant, etc.

---

This architecture is designed to be scalable, maintainable, and aligned with Nextcloud best practices.
