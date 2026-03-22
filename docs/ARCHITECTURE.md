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
├── appinfo/
│   ├── info.xml              # App metadata
│   ├── routes.php            # API routes
│   └── database.xml          # Database schema (legacy)
├── lib/
│   ├── Controller/           # API controllers
│   │   ├── PageController.php
│   │   ├── HouseController.php
│   │   ├── RoomController.php
│   │   ├── TaskController.php
│   │   ├── RoomTaskController.php
│   │   ├── GameController.php
│   │   └── UserController.php
│   ├── Service/              # Business logic
│   │   ├── HouseService.php
│   │   ├── RoomService.php
│   │   ├── TaskService.php
│   │   ├── GameLogicService.php
│   │   ├── PointsCalculator.php
│   │   └── TaskScheduler.php
│   ├── Db/                   # Database entities & mappers
│   │   ├── House.php
│   │   ├── HouseMapper.php
│   │   ├── Floor.php
│   │   ├── FloorMapper.php
│   │   ├── Room.php
│   │   ├── RoomMapper.php
│   │   ├── Task.php
│   │   ├── TaskMapper.php
│   │   ├── RoomTask.php
│   │   ├── RoomTaskMapper.php
│   │   ├── CompletedTask.php
│   │   ├── CompletedTaskMapper.php
│   │   ├── HouseUser.php
│   │   ├── HouseUserMapper.php
│   │   ├── Medal.php
│   │   └── MedalMapper.php
│   └── Migration/            # Database migrations
│       └── Version1000Date20260224000000.php
├── src/
│   ├── main.js               # Entry point
│   ├── App.vue               # Root component
│   ├── router.js             # Route definitions
│   ├── components/           # Vue components
│   │   ├── Dashboard/
│   │   │   ├── HouseHealthBar.vue
│   │   │   ├── FloorList.vue
│   │   │   ├── RoomCard.vue
│   │   │   └── QuickStats.vue
│   │   ├── Room/
│   │   │   ├── RoomView.vue
│   │   │   ├── RoomHeader.vue
│   │   │   ├── TaskList.vue
│   │   │   └── MonsterCanvas.vue
│   │   ├── Task/
│   │   │   ├── TaskItem.vue
│   │   │   ├── TaskForm.vue
│   │   │   ├── TaskTemplateSelector.vue
│   │   │   └── CompletionAnimation.vue
│   │   ├── Game/
│   │   │   ├── Monster.vue
│   │   │   ├── ParticleEffect.vue
│   │   │   ├── ComboNotification.vue
│   │   │   └── MedalBadge.vue
│   │   ├── Settings/
│   │   │   ├── HouseSettings.vue
│   │   │   ├── UserManagement.vue
│   │   │   └── NotificationSettings.vue
│   │   └── Common/
│   │       ├── LoadingSpinner.vue
│   │       ├── EmptyState.vue
│   │       └── ConfirmDialog.vue
│   ├── views/                # Page-level components
│   │   ├── Dashboard.vue
│   │   ├── RoomDetail.vue
│   │   ├── TaskManager.vue
│   │   ├── Leaderboard.vue
│   │   └── Settings.vue
│   ├── store/                # Pinia stores
│   │   ├── house.js
│   │   ├── rooms.js
│   │   ├── tasks.js
│   │   ├── game.js
│   │   └── user.js
│   ├── services/             # API & utilities
│   │   ├── api.js
│   │   ├── animations.js
│   │   ├── gameLogic.js
│   │   └── notifications.js
│   └── assets/               # Static assets
│       ├── sprites/
│       │   ├── dust-bunny.png
│       │   ├── slime-blob.png
│       │   └── pixel-spider.png
│       └── sounds/           # Optional
│           ├── complete.mp3
│           └── combo.mp3
├── l10n/                     # Translations
│   ├── templates/
│   │   └── ChoreStrike.pot
│   ├── de.po
│   ├── de.json
│   ├── en.po
│   └── en.json
├── img/                      # App icons
│   └── app.svg
├── tests/                    # Tests
│   ├── Unit/
│   │   ├── Controller/
│   │   ├── Service/
│   │   └── Db/
│   └── Integration/
├── docs/                     # Documentation
│   ├── README.md             # Documentation entry points
│   ├── ARCHITECTURE.md
│   ├── DEVELOPMENT.md
│   ├── TESTING.md
│   ├── REVIEW_PLAYBOOK.md
│   ├── standards/
│   │   └── README.md
│   ├── backlog/
│   │   └── ACTIVE_TASKS.md
│   ├── API.md
│   └── AGENT_GUIDELINES.md  # Legacy stub only
├── .github/                  # GitHub specific
│   ├── workflows/
│   │   ├── lint.yml
│   │   └── test.yml
│   └── ISSUE_TEMPLATE/
├── private/                  # Local dev (gitignored)
│   ├── config.local.php
│   └── test-data/
├── LICENSE
├── README.md
├── CONTRIBUTING.md
├── .gitignore
├── composer.json
├── package.json
├── webpack.config.js
├── babel.config.js
├── .eslintrc.js
└── stylelint.config.js
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
- `created_at` (datetime)

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
    updateMonsters(),
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
