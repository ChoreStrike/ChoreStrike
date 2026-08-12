# ChoreStrike Architecture

## Overview

ChoreStrike is built as a Nextcloud app following the standard Nextcloud app architecture with Vue.js 3 frontend and PHP backend.

## Technology Stack

### Backend
- **PHP 8.2+**: Server-side logic
- **Nextcloud App Framework**: Controllers, Database abstraction
- **Doctrine DBAL**: Database queries
- **Nextcloud coding standard**: Code style (PSR-12 superset with tabs; enforced via `composer cs:check`)

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
│   ├── AppInfo/                # Application.php — DI container wiring, app lifecycle
│   ├── BackgroundJob/          # Cron jobs (DailyNotificationJob, StreakFreezeJob,
│   │                           #   StreakRestockJob) registered in appinfo/info.xml.
│   │                           #   Thin delegates (CS-PLAN-023): each derives
│   │                           #   "yesterday" from the injected ITimeFactory and
│   │                           #   hands the pass to its Service (time seam for
│   │                           #   time-travel testing via occ trigger-jobs).
│   ├── Command/                # occ commands (TestDataSeed, TriggerJobs — test-mode-
│   │                           #   guarded job trigger with --as-of, CS-PLAN-023)
│   ├── Controller/             # Thin API controllers (House, Floor, Room, Task,
│   │                           #   RoomTask, Checklist, Scheduling,
│   │                           #   Minigame, RewardConfig, User, Template, TaskSync,
│   │                           #   Game, Page, Test) + ControllerLoggingTrait +
│   │                           #   ControllerErrorFormatter (sanitized error messages)
│   │                           # D1 (CS-016): the UI consumes `scheduling#*` via the
│   │                           #   scheduling Pinia store as the canonical surface. The
│   │                           #   `smart-schedule#*` routes were REMOVED (CS-016 review,
│   │                           #   2026-07-28) because SmartSchedulerController injected no
│   │                           #   AuthorizationService; the controller/service cluster was
│   │                           #   deleted in CS-PLAN-025 — re-add with auth gating if revived.
│   ├── Db/                     # Entities & mappers (House, Floor, Room, Task, RoomTask,
│   │                           #   CompletedTask, HouseUser, Medal, MinigameScore,
│   │                           #   TaskChecklistItem, TaskAssignee, RoomTaskChecklistProgress,
│   │                           #   AvailabilitySettings)
│   ├── Exception/              # App exceptions (NotFoundException, ForbiddenException) —
│   │                           #   domain-typed (NC 31 dropped the framework equivalents)
│   ├── Migration/              # Schema migrations (Version1000 … Version1027,
│   │                           #   non-contiguous — Version1014 absent)
│   ├── Notification/           # ChoreStrikeNotifier (renders localized notifications;
│   │                           #   registered via Application::registerNotifierService —
│   │                           #   the legacy info.xml <notifications> tag is not wired
│   │                           #   on modern Nextcloud, CS-PLAN-023)
│   ├── Service/                # Business logic (House, Floor, Room, RoomTask,
│   │                           #   Task, GameLogic, Scheduling,
│   │                           #   Minigame, RewardConfig, Template, TaskSync,
│   │                           #   TestData, Authorization, AppLogger, Title,
│   │                           #   Notification, StreakAtRisk, StreakFreeze, StreakRestock).
│   │                           #   The three
│   │                           #   Streak*/AtRisk services hold the job bodies and take an
│   │                           #   injected "yesterday" date (CS-PLAN-023).
│   ├── Templates/              # TaskTemplates.json (built-in task templates)
│   └── Titles/                 # TitleCatalog — 3 packs × 3 tiers, server-authoritative
│                               #   progression signals (CS-PLAN-006)
├── src/                        # Vue 3 frontend
│   ├── main.js                 # Entry point
│   ├── App.vue                 # Root component
│   ├── router.js               # Route definitions (lazy-loaded)
│   ├── components/
│   │   ├── Dashboard/          # HouseHealthBar, HouseholdGoalBar, FloorList, RoomCard,
│   │   │                       #   QuickStats, ArcadeSection
│   │   ├── Tasks/              # TaskList, TaskForm, TaskEditForm, TaskChecklist,
│   │   │                       #   FlexWindowDisplay, TemplateSelector, OverdueClaimDialog
│   │   ├── Minigames/          # SnakeGame, SnakeLeaderboard, BoostShop, Game2048, MemoryGame
│   │   ├── Common/             # Icon, SvgIcon, Emoji, Modal, MedalBadge
│   │   ├── Profile/            # TitleBadge, TitlePicker (CS-PLAN-006)
│   │   └── Scheduling/         # DailyTaskList, AvailabilitySettings, EnergyBudgetSettings,
│   │                           #   SchedulePreview, ScheduleCalendar, SchedulingSuggestions
│   ├── composables/            # useCelebration (confetti + SFX), useToasts
│   ├── constants/              # Shared constants (taskVisibility, titles)
│   ├── services/               # Shared API client (api.js — single URL prefix,
│   │                           #   envelope unwrap, ApiError normalization)
│   ├── store/                  # Pinia stores (house, rooms, roomTasks, tasks, game,
│   │                           #   user, scheduling, checklist, minigame, titles)
│   ├── styles/                 # tokens.css (theme/motion CSS vars), mobile.css
│   ├── views/                  # Dashboard, RoomDetail, TaskManager, Leaderboard, Scheduling, Calendar, Settings
│   └── utils/                  # currentUser, icons, roomIcons, logger, mobile, performance,
│                               #   confetti, sound
├── l10n/                       # Translations
│   ├── templates/
│   │   └── chorestrike.pot     # Translation template
│   ├── de.json                 # German
│   └── en.json                 # English
├── img/                        # App icons & PWA bootstrap
│   ├── app.svg
│   └── manifest.webmanifest    # PWA manifest (CS-PLAN-002 Phase 5)
├── tests/                      # Test suites
│   ├── unit/                   # PHPUnit (PHP) + Jest (JS/Vue)
│   ├── integration/            # PHPUnit integration tests
│   └── e2e/                    # Playwright end-to-end tests
├── docs/                       # Documentation
│   ├── README.md               # Documentation map
│   ├── engineering/            # architecture, development, testing, deployment, review-playbook
│   ├── api/                    # REST API reference + feature-specific notes
│   ├── release/                # roadmap, release-strategy
│   ├── operations/             # realtime-updates
│   ├── testing/                # release-test-plan (authoritative manual release plan + record)
│   ├── concepts/               # Product vision & design concepts
│   ├── standards/              # ai/ (rules, skills) + guidelines/ (+ i18n-workflow)
│   ├── adr/                    # Architecture decision records
│   ├── backlog/                # Task tracking (ACTIVE_TASKS.md, phase-*.md, bugs.md)
│   └── plans/                  # Implementation plans (new/active/onhold/done)
├── .github/                    # Issue & PR templates (Forgejo-compatible)
│   ├── ISSUE_TEMPLATE/
│   └── pull_request_template.md
├── .forgejo/                   # Codeberg/Forgejo CI
│   ├── workflows/release-gate.yml   # Authoritative blocking gate
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
Shared API Client (src/services/api.js)
    ↓
HTTP Request (@nextcloud/axios)
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
Response ({ success, data, error } envelope)
    ↓
Shared API Client unwraps data / throws ApiError
    ↓
Store Mutation
    ↓
Component Update (Reactive)
```

The shared API client (`src/services/api.js`) owns the single canonical URL
prefix (`/apps/chorestrike`), unwraps the `{ success, data, error }` envelope
once, and normalizes errors to an `ApiError` whose message prefers the
backend's already-sanitized `error.message` and falls back to one translated
generic message. All Pinia stores import `api` instead of calling
`@nextcloud/axios` directly.

### Example: Completing a Task

1. **User clicks "Complete" button** → `TaskList.vue`
2. **Component dispatches store action** → `useRoomTasksStore.completeRoomTask(roomTaskId)`
3. **API request** → `POST /apps/chorestrike/api/v1/room-tasks/{id}/complete`
4. **Controller** → `RoomTaskController::complete()`
5. **Service logic** → `RoomService::completeRoomTask()` (transactional) → `GameLogicService::handleTaskCompletion()`
   - Calculate coins (energy × base rate × tier × personal × early multiplier; ×2 on combo)
   - Check for combos (`checkForCombo`) and combo bonus freeze
   - Update room cleanliness
   - Award medals if applicable (`checkAndAwardMedals`)
   - Consume a streak freeze if the streak would otherwise reset
6. **Database update** → Multiple mappers (atomic within the transaction)
7. **Response** → Updated task, coins, streak-freeze/medal/title events, game state
8. **Store update** → Reactive state changes + celebration events emitted
9. **UI updates** → Confetti/SFX (`useCelebration`), room cleanliness updates

## Database Schema

All tables use the lowercase `chorestrike_*` prefix (Nextcloud convention). The
authoritative column list lives in the entity classes under `lib/Db/`; the
summary below covers the core tables. Column types map to the entity PHP type
(`integer` → BIGINT/INT, `string` → VARCHAR, `json` → native JSON column).

### Core Tables

#### `chorestrike_houses`
- `id` (bigint, PK)
- `nextcloud_instance_id` (string) — multi-instance support
- `name` (string)
- `house_health` (int, 0-100)
- `weekly_goal_notified_week` (string, nullable) — de-dup token so the weekly-goal-reached notification fires once per week
- `created_at`, `updated_at` (datetime)

#### `chorestrike_floors`
- `id` (bigint, PK)
- `house_id` (bigint, FK)
- `name` (string)
- `sort_order` (int)
- `created_at` (datetime)

#### `chorestrike_rooms`
- `id` (bigint, PK)
- `floor_id` (bigint, FK)
- `name` (string)
- `cleanliness` (int, 0-100)
- `monster_count` (int) — **legacy column, retained but unused** (the Monsters feature was removed in CS-PLAN-002; `Room::needsCleaning()` is cleanliness-only)
- `last_cleaned` (datetime, nullable)
- `created_at` (datetime)

#### `chorestrike_tasks` (task catalog/templates)
- `id` (bigint, PK)
- `name`, `description` (text, nullable)
- `energy_cost` (int, 1-5) — the energy/effort of a task (the legacy `difficulty` column was renamed; `energy_cost` is the source of truth)
- `priority` (int, 1-3)
- `task_tier` (string: `bronze`/`silver`/`gold`) — coin-tier multiplier source
- `frequency_type` (daily/weekly/monthly/custom), `frequency_value` (int)
- `estimated_minutes` (int)
- `category` (string), `is_template` (boolean)
- `visibility_type` (string, default `public`) — `public` | `private` | `admin-only` (CS-PLAN-005)
- `owner_user_id` (string, nullable) — creator of private/admin-only tasks (Nextcloud UID)
- `is_private` (boolean) — **back-compat shim** derived from `visibility_type` (`private`); `visibility_type` is the source of truth
- `flex_days` (int), scheduling/recurrence fields, `created_at` (datetime)
- Index `idx_tasks_visibility` on `(visibility_type, owner_user_id)`

> **Visibility matrix:** `public` = visible to all house members; `private` = visible only to `owner_user_id`; `admin-only` = visible only to house owners. Enforced in `TaskMapper::findVisibleByHouse()`, `TaskController::index()`/`show()`/`complete()`.

#### `chorestrike_room_tasks` (task instances scheduled into a room)
- `id` (bigint, PK)
- `room_id` (bigint, FK), `task_id` (bigint, FK)
- `next_due` (datetime), `last_completed` (datetime, nullable)
- `assigned_to_user_id` (string, nullable) — legacy single assignee
- `is_active` (boolean), `created_at` (datetime)
- assignment/claim fields (`assignment_type`, `assigned_to_group`, `claimed_by_user`, `was_claimed`, …) — **retained columns** of the legacy group-assignment feature, which was archived in CS-PLAN-011 (routes/controller/service/mapper removed; columns kept harmlessly to avoid data loss on existing installs)
- `ideal_due_date` (datetime, nullable) — drives the early-completion bonus

#### `chorestrike_house_users` (membership + gamification state)
- `id` (bigint, PK)
- `house_id` (bigint, FK), `nextcloud_user_id` (string), `display_name` (string)
- `role` (string: `owner`/`member`/`child`), `joined_at`, `last_activity` (datetime, nullable)
- `contribution_score` (int) — lifetime work done; never decreases; leaderboard/title signal
- `coins` (int) — renewable currency (`total_points` was renamed to `coins` in Version1017)
- `coin_multiplier` (decimal, default 1.0)
- `current_streak` (int), `longest_streak` (int)
- `streak_freezes` (int, default 1)
- `scheduling_preferences` (json) — weekday availability etc.
- `selected_title_pack` (string, nullable), `unlocked_titles` (json) — role-title state (CS-PLAN-006)

> **Sparks removed:** the legacy `current_sparks`/`total_sparks_earned` currency columns (a second, never-awarded currency retired in CS-PLAN-002) were dropped in `Version1026` (CS-PLAN-010). `GameLogicService` awards `coins`, never sparks.

#### `chorestrike_completed_tasks`
- `id` (bigint, PK)
- `room_task_id` (bigint, FK), `user_id` (bigint, FK)
- `completed_at` (datetime)
- `coins_earned` (int) — (`points_earned`/`sparks_earned` renamed to `coins_earned` in Version1017)
- `was_combo` (boolean), `combo_with_user_id` (bigint, FK, nullable)
- teamwork/claim metadata (`is_team_completion`, `participant_count`, `was_claimed_completion`, …)

#### `chorestrike_medals`
- `id` (bigint, PK)
- `user_id` (bigint, FK)
- `medal_type` (string) — gamification: `team_anchor`/`flow_keeper`/`room_guardian`/`team_player`/`streak_keeper`; standard: `gold`/`silver`/`bronze`
- `week_start` (date), `value` (int), `awarded_at` (datetime)

> **Award reality (CS-PLAN-012):** all five gamification medal types are awarded in the live `GameLogicService::checkAndAwardMedals()` flow: `team_anchor` (10+ tasks/week), `streak_keeper` (7+ day streak), `flow_keeper` (4+ distinct active days), `room_guardian` (5+ completions in one room), `team_player` (3+ combo bonuses). `gold`/`silver`/`bronze` are legacy. Note: `lib/Service/MedalService.php` is dead code (archived in CS-PLAN-010) — the live path is `GameLogicService::awardMedalIfNew()`.

### Supporting Tables

| Table | Purpose | Entity |
|-------|---------|--------|
| `chorestrike_minigame_scores` | Minigame scores (game, score, boosts used) | `MinigameScore` |
| `chorestrike_checklist_items` | Task checklist template items | `TaskChecklistItem` |
| `chorestrike_room_task_checklist_progress` | Per-room-task checklist progress | `RoomTaskChecklistProgress` |
| `chorestrike_task_assignees` | Multi-assignee join table (room-task ↔ user) (CS-PLAN-002) | `TaskAssignee` |
| `chorestrike_availability` | Per-user availability: weekday presence, time ranges, energy budget | `AvailabilitySettings` |

### Indexes

```sql
-- Performance indexes
CREATE INDEX idx_room_tasks_next_due ON chorestrike_room_tasks(next_due);
CREATE INDEX idx_room_tasks_room_id ON chorestrike_room_tasks(room_id);
CREATE INDEX idx_completed_tasks_user_id ON chorestrike_completed_tasks(user_id);
CREATE INDEX idx_completed_tasks_completed_at ON chorestrike_completed_tasks(completed_at);
CREATE INDEX idx_house_users_house_id ON chorestrike_house_users(house_id);
CREATE INDEX idx_tasks_visibility ON chorestrike_tasks(visibility_type, owner_user_id);
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

All Pinia stores consume the shared API client (`src/services/api.js`) for HTTP
access; no store imports `@nextcloud/axios` directly. Each store owns exactly
one concern. The canonical store boundaries (post-CS-PLAN-008):

- **`useTasksStore`** — catalog-task concerns only: task templates, categories,
  search. Room-task state/actions live in `useRoomTasksStore`.
- **`useRoomTasksStore`** — the sole owner of room-task state, derived getters,
  and all room-task mutations (assign, complete, deactivate, …).

### Store Structure

#### `useHouseStore`
```javascript
{
  state: {
    house: null,        // the full house object; houseHealth lives on house.houseHealth
    loading: false,
    error: null
  },
  getters: {
    houseHealth()       // derived: state.house?.houseHealth || 100
  },
  actions: {
    fetchHouse(),
    updateHouseName(),
    calculateHouseHealth(),   // GET /api/v1/house/health, persists house.houseHealth
    fetchHouseStats(),
    resetHouse(),
    initializeHouse(),
    clearError(),
    reset()
  }
}
```

> There is **no** standalone `health` state field or `updateHealth()` action —
> house health is derived from `state.house.houseHealth` via the `houseHealth`
> getter and refreshed by `calculateHouseHealth()`. Canonical definition:
> [`src/store/house.js`](../../src/store/house.js).

#### `useRoomsStore`
```javascript
{
  state: {
    rooms: [],
    floors: [],
    currentFloor: null,
    currentRoom: null
  },
  actions: {
    fetchFloorsWithRooms(),
    fetchRooms(),
    createRoom(),
    updateRoomName(),
    deleteRoom()
  },
  getters: {
    roomsByFloor(),
    getRoomById(),
    roomsNeedingCleaning()
  }
}
```

> Overdue/due room-task views are owned by `useRoomTasksStore` (not
> `useRoomsStore`); `useRoomsStore` has no `overdueRooms` getter. Canonical
> definition: [`src/store/rooms.js`](../../src/store/rooms.js).

#### `useTasksStore` (catalog tasks only)
```javascript
{
  state: {
    tasks: [],
    templates: []
  },
  actions: {
    fetchTasks(),
    createTask(),
    updateTask(),
    deleteTask()
  },
  getters: {
    activeTasks(),
    tasksByCategory()
  }
}
```

#### `useGameStore`
```javascript
{
  state: {
    gameState: null,
    leaderboard: [],
    medals: [],
    combos: []
  },
  actions: {
    fetchGameState(),
    fetchMedals(),
    checkForCombo()
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

### E2E Tests (Playwright — live)
- Complete user flows against the real Vue frontend (run
  `git ls-files 'tests/e2e/*.spec.ts' | wc -l` for the current spec count)
- Cross-browser coverage — see [`testing.md`](testing.md) for the browser
  projects and the full E2E matrix

### Contract Tests (CS-PLAN-007 / CS-PLAN-008)
- `tests/unit/RouteMatrixTest.php` — route → controller method existence
  (PHPUnit, parses `appinfo/routes.php`).
- `tests/unit/store/apiContract.spec.js` — store → endpoint contract
  (Jest, same source). Scans `src/store/*.js` for `api.{method}()` calls,
  resolves the effective URL via `API_PREFIX`, and asserts every call matches a
  declared route. Also asserts no store uses the deprecated
  `/index.php/apps/chorestrike` prefix form.
- `tests/unit/services/api.spec.js` — shared API client contract: prefix
  construction, envelope unwrap, `!success` → `ApiError`, HTTP-error
  → `ApiError`, method-verb mapping.

## Continuous Integration (Release Gate)

The authoritative gate is `.forgejo/workflows/release-gate.yml`
(GHA-compatible). It runs on `push` and `pull_request` and fails the
run on any non-zero **blocking** command:

- `npm run lint` (ESLint)
- `npm test` (Jest)
- `npm run build` (Webpack)
- `composer validate --strict`
- `vendor/bin/phpunit --configuration phpunit.unit.xml` (Docker) — Controller + Service + Entity + Mapper + BackgroundJob + Command + Contract + Notification + Migration + l10n suites
- `vendor/bin/phpunit --configuration phpunit.integration.xml` (Docker)
- `npx playwright test --project=chromium --project=chromium-serial-db` (Docker) — blocking E2E, `family` profile (CS-PLAN-020)

A **non-blocking `coverage` job** (CS-PLAN-022) generates PHPUnit coverage
(PCOV driver in `docker/Dockerfile.test`) and Jest coverage (v8 provider),
uploading `build/coverage/**` as a CI artifact. Jest enforces a
`coverageThreshold` (regression-only, set below baseline); PHPUnit coverage
is artifacts-only. See `docs/engineering/testing.md` § Coverage Measurement.

Stylelint (`npm run stylelint`) is a **blocking** gate as of CS-026 (2026-08-12):
the `@nextcloud/stylelint-config` ↔ `stylelint` version mismatch that produced
201 "Unknown rule" errors was resolved by aligning the dependency trio (config
3.x / stylelint 17). It still emits ~26 `use-logical` *warnings* (deferred RTL
sweep → `docs/backlog/backlog.md` TECH-LINT-004); warnings do not fail the gate.
See `docs/engineering/testing.md`.

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
