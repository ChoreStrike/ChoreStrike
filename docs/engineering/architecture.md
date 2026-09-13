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
│   │                           #   RoomTask, Checklist, Scheduling, Setup,
│   │                           #   Minigame, User, Game, Page, Test) +
│   │                           #   ControllerLoggingTrait +
│   │                           #   ControllerErrorFormatter (sanitized error messages)
│   │                           #   (RewardConfigController and TaskSyncController were
│   │                           #   pruned in CS-PLAN-055 — no consumer; re-add with an
│   │                           #   AuthorizationService gate + RouteMatrixTest pin if revived)
│   │                           # D1 (CS-016): the UI consumes `scheduling#*` via the
│   │                           #   scheduling Pinia store as the canonical surface. The
│   │                           #   `smart-schedule#*` routes were REMOVED (CS-016 review,
│   │                           #   2026-07-28) because SmartSchedulerController injected no
│   │                           #   AuthorizationService; the controller/service cluster was
│   │                           #   deleted in CS-PLAN-025 — re-add with auth gating if revived.
│   ├── Db/                     # Entities & mappers (House, Floor, Room, Task, RoomTask,
│   │                           #   CompletedTask, HouseUser, Medal, MinigameScore,
│   │                           #   TaskChecklistItem, TaskAssignee, RoomTaskChecklistProgress)
│   │                           #   (AvailabilitySettings entity/mapper pruned in CS-PLAN-055)
│   ├── Exception/              # App exceptions (NotFoundException, ForbiddenException) —
│   │                           #   domain-typed (NC 31 dropped the framework equivalents)
│   ├── Migration/              # Schema migrations (Version1000 … Version1028, non-
│   │                           #   contiguous — 1014/1017–1020 absent; dead no-op steps
│   │                           #   1003/1010/1013/1017/1018/1019/1020 pruned CS-PLAN-055,
│   │                           #   pre-release fresh-install edit — no back-compat)
│   ├── Notification/           # ChoreStrikeNotifier (renders localized notifications;
│   │                           #   registered via Application::registerNotifierService —
│   │                           #   the legacy info.xml <notifications> tag is not wired
│   │                           #   on modern Nextcloud, CS-PLAN-023)
│   ├── Service/                # Business logic (House, Floor, Room, RoomTask,
│   │                           #   Task, GameLogic, Scheduling,
│   │                           #   Minigame, RewardConfig, Template,
│   │                           #   TestData, Authorization, AppLogger, Title,
│   │                           #   Notification, StreakAtRisk, StreakFreeze,
│   │                           #   StreakRestock, TaskDueReminder). The daily
│   │                           #   job services (Streak*/AtRisk/TaskDueReminder)
│   │                           #   hold the job bodies and take an injected
│   │                           #   reference date (CS-PLAN-023 / CS-PLAN-030).
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
│   │   └── Scheduling/         # SelfAvailabilitySettings, AvailabilitySettings, AvailabilityEditModal,
│   │                           #   EnergyBudgetSettings, BudgetAnalysis, WeekBudgetGrid,
│   │                           #   SchedulePlanner, ScheduleCalendar, OvercapacityWarning
│   ├── composables/            # useCelebration (confetti + SFX), useToasts
│   ├── constants/              # Shared constants (taskVisibility, titles, scheduling)
│   ├── services/               # Shared API client (api.js — single URL prefix,
│   │                           #   envelope unwrap, ApiError normalization)
│   ├── store/                  # Pinia stores (house, rooms, roomTasks, tasks, game,
│   │                           #   user, scheduling, checklist, minigame, titles,
│   │                           #   setup) + userAutoInit bootstrap plugin
│   ├── styles/                 # tokens.css (theme/motion CSS vars), mobile.css
│   ├── views/                  # Dashboard, Today, Insights, RoomDetail, TaskManager,
│   │                           #   Leaderboard, Scheduling, Calendar, Settings
│   └── utils/                  # currentUser, dateKeys, icons, roomIcons, logger,
│                               #   confetti, sound, energyBudgets
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
│   ├── standards/              # ai/ (rules, skills) + guidelines/
│   ├── adr/                    # Architecture decision records
│   ├── backlog/                # Task tracking (ACTIVE_TASKS.md, backlog.md, archive/)
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
5. **Service logic** → `CompletionService::complete()` (transaction aggregate, CS-PLAN-064/067) → `GameLogicService::handleTaskCompletion()` (reward phase) → post-commit effects via `CompletionPostCommitService`
   - Calculate coins (energy × base rate × tier × personal × early multiplier; ×2 on combo)
   - Check for combos (`checkForCombo`) and combo bonus freeze
   - Update room cleanliness
   - Write the single ledger row with the complete effect record (medals are NOT awarded here — they stay read-triggered via `MedalService::checkAndAwardMedals`)
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
- `room_type` (string, default `generic`) — room-type key for template filtering (CS-PLAN-027); see [house-setup-and-templates.md](../concepts/house-setup-and-templates.md)
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
- `category` (string) — the DB-backed `is_template` column was removed (CS-PLAN-027 D7); catalog templates now live in `lib/Templates/`
- `visibility_type` (string, default `public`) — `public` | `private` | `admin-only` (CS-PLAN-005); the sole visibility representation — the former `is_private` shim column was folded out (CS-PLAN-060 WP1 D1)
- `owner_user_id` (string, nullable) — creator of private/admin-only tasks (Nextcloud UID)
- `flex_days` (int), scheduling/recurrence fields, `created_at` (datetime)
- Index `idx_tasks_visibility` on `(visibility_type, owner_user_id)`

> **Visibility matrix:** `public` = visible to all house members; `private` = visible only to `owner_user_id`; `admin-only` = visible only to house owners. Enforced in `TaskMapper::findVisibleByHouse()`, `TaskController::index()`/`show()`/`complete()`, and on mutation in `TaskService::deleteTask()`/`updateTask()` (service-side matrix, CS-PLAN-031 CR-SVC-17) with controller gates in `TaskController::delete()`/`update()` (anti-enumeration 404s).

#### `chorestrike_room_tasks` (task instances scheduled into a room)
- `id` (bigint, PK)
- `room_id` (bigint, FK), `task_id` (bigint, FK)
- `next_due` (datetime), `last_completed` (datetime, nullable)
- `is_active` (boolean), `created_at` (datetime)
- claim fields (`claimed_by_user`, `was_claimed`, `original_assignee`, `claimed_at`) — the live overdue-takeover/claim feature; the legacy single-assignee column (`assigned_to_user_id`) and the abandoned group-assignment remnants (`assignment_type`, `assigned_to_group`, `assigned_by_user_id`, `assigned_at`) were folded out — `chorestrike_task_assignees` is the canonical assignee store (CS-PLAN-060 WP1 D2/D9)
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

> **Award reality (CS-PLAN-012, updated CS-PLAN-067 §3.2):** all five gamification medal types are awarded in the live `MedalService::checkAndAwardMedals()` flow (extracted from `GameLogicService`, award timing unchanged — read-triggered): `team_anchor` (10+ tasks/week), `streak_keeper` (7+ day streak), `flow_keeper` (4+ distinct active days), `room_guardian` (5+ completions in one room), `team_player` (3+ combo bonuses). `gold`/`silver`/`bronze` are legacy. `MedalService` also owns the house medal listing (`getMedals`).

### Supporting Tables

| Table | Purpose | Entity |
|-------|---------|--------|
| `chorestrike_minigame_scores` | Minigame scores (game, score, boosts used) | `MinigameScore` |
| `chorestrike_chk_items` | Task checklist template items (Version1005 name) | `TaskChecklistItem` |
| `chorestrike_chk_progress` | Per-room-task checklist progress (Version1005 name) | `RoomTaskChecklistProgress` |
| `chorestrike_task_assignees` | Canonical multi-assignee join table (room-task ↔ Nextcloud user) (CS-PLAN-002; sole assignee store since CS-PLAN-060 WP1 D2) | `TaskAssignee` |

> `chorestrike_availability` (orphan `AvailabilitySettings` entity/mapper) and
> the never-referenced `chorestrike_task_scopes` / `chorestrike_task_parts` /
> `chorestrike_claims` tables were removed from the fresh-install chain in
> CS-PLAN-055 (pre-release, no drop steps).

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

### Bulk Endpoints

Batch operations are single-call endpoints backed by set-based service
writes, not frontend loops. `POST /api/v1/room-tasks/bulk-assign`
(CS-PLAN-042 / CR-SVC-61) takes `{ roomTaskIds: number[], userId: string }`,
pre-validates all ids against the caller's house in one query
(`findByIdsScopedToHouse`) and skips foreign/missing ids per item while a
non-member target user fails the whole request up front (400). The response
carries only the successfully assigned room tasks; the TaskList selection
bar derives its partial-success toast from the diff.

### Single-Surface Rule (structure editing)

Since CS-PLAN-042 there is exactly **one** UI surface for house structure
mutations: the Settings structure section (owner-gated rendering; floor
create/rename/delete/reorder + inline room add/delete). The Dashboard
FloorList is strictly read-only (navigation), and RoomDetail exposes only
per-room rename/delete in its header. New structure affordances belong in
Settings — do not re-introduce a second editing surface.

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

### Error Code Conventions

- Standard shapes go through `ControllerErrorFormatter`: `validation()`
  (400 `VALIDATION_ERROR`, curated message surfaced verbatim),
  `forbiddenResponse()` (403, code always `FORBIDDEN`), `safeMessage()`
  (hides non-domain exception detail).
- Endpoint-specific semantics get a **custom code** built inline at the
  catch site (precedent: `CANNOT_REMOVE_OWNER` 403 on `DELETE /users/{id}`).
  `forbiddenResponse()` cannot carry custom codes and there is no 409
  formatter, so custom envelopes are always built inline with a
  server-side `t()`-wrapped message.
- Domain exceptions map to stable codes: services throw
  `ForbiddenException` (403, e.g. self-demotion → `CANNOT_DEMOTE_SELF`),
  `ConflictException` (409, e.g. last-owner demotion →
  `CANNOT_DEMOTE_LAST_OWNER`), `\InvalidArgumentException` (400
  `VALIDATION_ERROR`), `DoesNotExistException` (404 `USER_NOT_FOUND`).
- The shared API client (`src/services/api.js`) surfaces
  `error.message` from the envelope as the `ApiError` message, so custom
  codes' messages reach the UI toasts end-to-end without extra handling.

## State Management (Pinia)

All Pinia stores consume the shared API client (`src/services/api.js`) for HTTP
access; no store imports `@nextcloud/axios` directly. Each store owns exactly
one concern. The canonical store boundaries (post-CS-PLAN-008):

- **`useTasksStore`** — catalog-task concerns only: task templates, categories,
  search. Room-task state/actions live in `useRoomTasksStore`.
- **`useRoomTasksStore`** — the sole owner of room-task state, derived getters,
  and all room-task mutations (assign, complete, deactivate, …).

### Client-State Contract (CS-PLAN-066 §3.2/§3.3)

Every read/mutation in the core stores follows one canonical pattern so a
stale response can never win and a committed write is never misreported:

- **Request generations, scoped per resource/query.** Each fetch increments a
  generation (`requestSeq`, or a per-room map for room-scoped loads) and only
  the result of the CURRENT generation may land. A global counter is never
  shared across sibling resources (two parallel room loads must both succeed).
  `reset()` bumps every generation BEFORE clearing, so an in-flight response
  cannot repopulate identity/state after a house or user change.
- **Shared loading is refcounted.** `pendingReads` backs the shared `loading`
  flag; only the LAST in-flight read clears it (a stale finally can never
  clear a newer request's loading state).
- **Accepted mutations invalidate pre-mutation reads** of their slice
  (bump the generation) and land the authoritative response.
- **Count projections are endpoint-backed.** Dashboard counters read dedicated
  `dueTodayTasks` / `overdueTasks` slices holding complete viewer-visible
  endpoint results (D2) — scoped room loads and the child-limited daily list
  can never masquerade as count sources.
- **Coordinated derived refresh (D3).** After an accepted instance mutation,
  the owning store refreshes ONLY the loaded derived projections, exactly once
  per transition. A refresh failure never fails the committed action: failed
  slices stay marked stale, `refreshFailed` turns on, and the retry is a
  read-only re-run (no write replay). Views may surface a refresh-only retry
  banner (TodayView) — never a write replay.
- **Reconcile-before-events (D10).** Completion/undo reconcile every owning
  store (instances, definitions, rooms, game/user/wallet) from the one
  authoritative payload BEFORE any celebration event fires; the title unlock
  re-syncs the titles store before the event and fires at most once per
  response (`rewards.newTitle`, explicit and nullable).
- **Weekly-goal celebration suppression (D5).** The game store tracks the
  hydrated week identity; celebrations fire only for a same-week false→true
  transition on a CONFIRMED completion response — first load, remount,
  unchanged refetch, week rollover and undo hydrate silently.

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

#### `useSchedulingStore`
```javascript
{
  state: {
    memberAvailability: [],   // owner-only member preference list (with maxDailyTasks)
    energyBudget: {},         // current user's weekly budgets
    weekdayAvailability: {},  // current user's weekly availability
    maxDailyTasks: Infinity,  // null (unset) from the API → Infinity (unlimited, D5)
    loading: false,
    error: null,
    planStatus: 'idle',       // idle|loading|ready|applying|applied|refreshFailed|stale|error
    planPreview: null,        // last preview payload (changes/unchanged/unplaced/capacity/warnings)
    planInput: null           // the exact normalized input the preview was computed from
  },
  getters: {
    totalWeeklyEnergy(),
    getCapacityForDay(),
    plannedEnergyForDay(),
    overcapacityDays()
  },
  actions: {
    fetchUserPreferences(),
    saveWeeklyPreferences(),       // personal bulk PUT — cannot change maxDailyTasks (D5)
    fetchMemberAvailability(),
    updateMemberPreferences(),     // owner member PUT (optionally maxDailyTasks)
    previewPlan(),                 // POST /scheduling/suggestions (read-only, sequenced)
    markPlanStale(),
    applyPlan(),                   // POST /scheduling/apply + reconciliation + refresh
    refreshAfterApply(),
    resetPlan(),
    initializeScheduling(),
    clearError(),
    reset()
  }
}
```

> Plan-failure state is deliberately scoped: `previewPlan`/`applyPlan` never
> set the shared view-wide `error` (SchedulingView replaces its whole content
> on that field) — the planner component carries its own status banner and
> toasts (CS-PLAN-065 §3.4). After a committed apply, the store reconciles
> the returned room tasks into `useRoomTasksStore` caches, refetches the
> filtered-daily/overdue slices and the cached range window, then emits
> `chorestrike:schedule-applied` for TodayView/CalendarView local reloads.
> The room-task store keeps per-resource request sequences on the
> filtered-daily and range readers so a pre-apply response can never
> overwrite post-apply refreshed results.

## Notifications (Nextcloud bell + mobile push)

All ChoreStrike notifications are dispatched through
`NotificationService` (thin wrapper around `OCP\Notification\IManager`) and
rendered by `ChoreStrikeNotifier` (registered INotifier; rich-subject
parameters for user-controlled strings like task names). The subject
constants live on `NotificationService` and are referenced from both sides —
a subject without a matching notifier case fails at render time.

### Subject catalog

| Subject | Trigger point | objectType / objectId |
|---------|--------------|----------------------|
| `overdue_taken_over` | `RoomService` (overdue takeover) | `room_task` / roomTaskId |
| `medal_awarded` | `MedalService::awardMedalIfNew` (CS-PLAN-067 §3.2) | `medal` / weekStart |
| `weekly_goal_reached` | `HouseService` (per house-week, deduped) | `weekly_goal` / weekStart |
| `title_unlocked` | `RoomService` post-commit (CR-SVC-12) | `title` / pack-qualified tier id |
| `streak_at_risk` | `StreakAtRiskService` (daily job) | `streak` / `{pass-day Y-m-d}:{streak}` |
| `task_due_today` / `task_due_tomorrow` | `TaskDueReminderService` (daily job, CS-PLAN-030) | `task_due` / `{roomTaskId}:{run-day Y-m-d}` |

### Dedup rule (day-qualified objectIds)

Nextcloud deduplicates on `(app, user, objectType, objectId)`. Any
notification that may legitimately repeat on later days MUST day-qualify its
objectId (`{identity}:{Y-m-d}`) — a bare streak number made consecutive
days' at-risk reminders collapse into one bell entry whenever the value
repeated (CR-SVC-27). Run-date (not due-date) keying keeps the "due
tomorrow" reminder of day D−1 and the "due today" reminder of day D as
separate entries while same-day re-runs dedup.

### Daily job passes

`DailyNotificationJob` (06:00 server time, 24 h interval) is a thin
delegate that runs, in order: `StreakAtRiskService::process(yesterday)` and
`TaskDueReminderService::process(today)`. Task-due reminders go to
**assignees only** (join table + legacy column; unassigned tasks are
visible in the daily dashboard instead), skip users unavailable on the due
weekday, and need no completed-task guard (completion advances `next_due`
or deactivates the row). Both passes are triggerable on demand with an
injected reference day via `occ chorestrike:trigger-jobs --type
daily-notification --as-of YYYY-MM-DD` (test mode only); `--as-of` sets
"today", from which "yesterday" is derived for the streak passes.

## Security Considerations

### Authentication
- Nextcloud session-based auth
- CSRF token validation
- User permissions checked in controllers

### Authorization
- Users can only access their house
- Room/task ownership validation
- Admin vs. member permissions
- **Owner invariants** (enforced in `HouseService`, not the UI): a house
  always keeps at least one owner and no owner can lose the role by their
  own action. `updateUserRole` rejects self-demotion (403
  `CANNOT_DEMOTE_SELF`) and last-owner demotion (409
  `CANNOT_DEMOTE_LAST_OWNER`, backed by `countOwnersForHouse`);
  `removeHouseMember` rejects owner targets (403 `CANNOT_REMOVE_OWNER`).
  Known gap: ownership handover requires promoting a co-owner first, and
  the former owner still cannot demote themselves afterwards (BUG-SEC-002).

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

### Batch Query Patterns (N+1 elimination)

> Canonical patterns established by CS-PLAN-036 (read paths) and extended
> to the write paths by CS-PLAN-037. When a service loop calls a
> mapper per row, convert it to one of these idioms — all of them are
> proven in-repo and covered by real-DB regression tests
> (`tests/integration/Db/BatchQueryRegressionTest.php`,
> `tests/integration/Db/WriteBatchRegressionTest.php`).

1. **IN-clause id→row map** — fetch many rows by id in one query, return a map.
   Pattern: `TaskAssigneeMapper::findUserIdsByRoomTasks`, `TaskMapper::findByIds`,
   `RoomMapper::findByIds`, `MedalMapper::findByUserIds`,
   `HouseUserMapper::findByHouseIdAndNextcloudUserIds`,
   `RoomTaskMapper::findByIdsScopedToHouse` (house-scoped variant).
   - Always `array_fill_keys`/`[]`-default + **empty-input early return**
     (an empty IN () is a syntax error on MariaDB/SQLite).
   - Use `$qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY))`
     (or `PARAM_STR_ARRAY` for string ids).
   - **Missing ids are tolerated**: absent map key = skip. Consumers must not
     assume every requested id resolves (skip-on-missing semantics, e.g.
     `RoomService::getRoomWithTasks`, `bulkAssignTasks`' foreign-id skip).
   - Callers look up with `$map[$id] ?? $default` — never re-fetch per row.

2. **Grouped aggregate (single GROUP BY → PHP map)** — replace per-user
   aggregates with one grouped query; users with no rows are absent from the
   map and default to zero/empty in PHP.
   Pattern: `CompletedTaskMapper::countByUserOnDateGrouped` (consumed by
   `StreakFreezeService`/`StreakAtRiskService`), `getUserStatsByHouse`,
   `getMaxRoomCompletionCountByHouse` (consumed by `checkAndAwardMedals`).
   - Keep grouped siblings **expression-identical** to their per-user
     counterparts (`getUserStats`, `getMaxRoomCompletionCountByUser`) — the
     equivalence is pinned by a real-DB regression test.
   - ⚠️ Aggregate expressions (`COUNT(*) AS x`, `SUM(...)`), must be wrapped
     in `$qb->createFunction(...)` — raw select strings get quoted as column
     names and fail on the real DB (mock tests cannot catch this).

3. **House-scoped JOIN** — when the fact table has no denormalized
   `house_id`, scope through the ownership chain in one query instead of
   per-row ownership checks.
   Pattern: `CompletedTaskMapper::findCompletionsBetweenByHouse`,
   `RoomMapper::findByHouseId`.

**Write-path idioms (CS-PLAN-037)** — the same conversion for loops that
UPDATE/DELETE per row:

4. **House/parent-scoped subquery UPDATE/DELETE** — one statement scoped by
   `WHERE … IN (SELECT …)` instead of a fetch-then-loop.
   Pattern: `RoomMapper::resetByHouseId` (floors-of-house subquery UPDATE,
   replaces the `resetHouse` floors→rooms loop), CR-SVC-26 precedents
   `TaskAssigneeMapper::deleteByUserForHouse`,
   `RoomTaskMapper::clearAssignedUserForHouse`.
   - Build the subquery on a separate builder; bind the scope parameter on
     the OUTER builder (`createNamedParameter`) and embed the subquery via
     `$qb->createFunction($subQb->getSQL())` so the parameter is bound
     exactly once where execution happens.
   - `IN (SELECT …)` is SQL-92 (portable); avoid the non-portable
     `UPDATE … JOIN`.

5. **Bulk DELETE by id list** — one statement over the loop's id set.
   Pattern: `CompletedTaskMapper::deleteByRoomTaskIds`,
   `TaskAssigneeMapper::deleteByRoomTasks`,
   `RoomTaskChecklistProgressMapper::deleteByRoomTaskIds`,
   `RoomTaskMapper::deleteByRoomIds`, `RoomMapper::deleteByFloorId`.
   - Same empty-input early return as idiom 1; ids are `intval`-cast.
   - **There are no FK constraints** — a delete cascade must be explicit, in
     dependency order (progress → assignees → completions → room tasks →
     rooms), and transactional. The shared helper is
     `RoomService::deleteRoomTaskDependents` (used by `deleteRoom`,
     `deleteRoomTask`, `deleteFloor`); D2 decision: the completion history
     is deleted with its task (medals/streaks/coins are denormalized
     snapshots and stay valid).

6. **CASE-WHEN bulk UPDATE** — per-row `SET` values differing by id become
   one statement: `SET sort_order = CASE id WHEN … THEN … ELSE
   sort_order END WHERE id IN (…)`.
   Pattern: `FloorMapper::updateSortOrders` (consumed by
   `FloorService::reorderFloors`, which builds the return payload from the
   already-fetched entities instead of re-fetching per row).
   - Only for validated scalar values (ints): inline via
     `sprintf('%d', …)` inside `createFunction` — never user strings.
   - The `ELSE` arm keeps untouched rows stable; the IN clause scopes the
     statement.

**What stays per-row on purpose:** medal existence checks + inserts (unique-
constraint race handling in `MedalService::awardMedalIfNew`), per-award
notifications, per-item error isolation (e.g. the freeze write in
`StreakFreezeService::process`, the per-item `assignToUser` writes in
`bulkAssignTasks` — the API promises item-granular skip behavior), and
genuine single-entity lookups.

### Service Idiom: Config Value Registry (CS-PLAN-049)

When a service exposes many scalar IAppConfig-backed values, do **not**
hand-mirror each key across const/getter/setter/`getAllConfig`/`updateConfig`
(4–5 coordinated edits per key — a proven drift risk, CR-SVC-16). Instead,
define every key exactly once in a private `REGISTRY` const (type, stored
default string, clamp bounds, camelCase API field) and drive two generic
helpers (`readSpec` = read→cast→clamp, `writeSpec` = cast→clamp→write) plus
the `getAllConfig()`/`updateConfig()` loops from it. Typed public
getters/setters stay as one-line delegates (stable API, zero consumer churn);
dynamic-shaped keys (e.g. per-pack title thresholds with a catalog fallback)
stay outside the registry. Pattern: `RewardConfigService` — pinned by
table-driven per-key default/clamp specs and a `getAllConfig` shape pin
(`tests/unit/Service/RewardConfigServiceTest.php`). Clamping on read *and*
write is the CR-SVC-19 rule.

### Service Idiom: Calendar-Day & Timezone Conventions (CS-PLAN-057, ADR-009)

All calendar-day semantics (streaks, freezes, at-risk, due-today, energy windows, duplicate-completion guard, medal/leaderboard weeks) bucket in the **house timezone** (chorestrike_houses.timezone, ADR-009) — never at server-TZ midnight (a Nextcloud server runs UTC).

- CalendarService is the single day/week arithmetic source: dayBounds(now, tz) returns the half-open [start, end) window of the house-local day, weekStart(now, tz) the house-local Monday 00:00 (correct Sunday semantics — never PHP 'monday this week', which returns the NEXT Monday on Sundays), dayKey(now, tz) the house-local Y-m-d label.
- **Boundary DateTimes are UTC-normalized.** Doctrine formats bound parameters in the value's own timezone and every stored timestamp is a UTC wall-clock string, so a boundary must carry its instant in UTC to compare correctly. Day/week keys stay house-local labels.
- Services resolve the timezone once per request/job pass (CalendarService::getTimeZone()) and thread it explicitly down: mapper day-window methods take [start, end) bounds parameters (no hand-rolled setTime(0,0,0) preambles in mappers); entity day-math methods (RoomTask::isDueToday/getDaysUntilDue/projectOccurrences, HouseUser::calculateStreakWithAbsence) take an optional ?DateTimeZone. Never do bare new \DateTime(...) day-math outside CalendarService callers.
- Background jobs resolve the single instance house (HouseMapper::findOneForJobs()) per pass; no house yet → the pass is a no-op. Multi-house is a documented future (per-house passes), not built.
- Frontend mirror: src/utils/dateKeys.js (dateKeyInZone, todayKeyInZone, addDaysToKey, weekdayOfKey) is the single day-key convention; the house store exposes timezone (UTC fallback while the house payload is loading). Never slice ISO strings (slice(0, 10)) or build browser-local keys for day bucketing.
- Grep gate: no bare new \DateTime('…') day-math outside CalendarService callers; no UTC-slice or browser-local day keys in src/ (outside dateKeys.js).

### Aggregate Idiom: Scheduling Preview/Apply (CS-PLAN-065, ADR-012)

`SchedulePlanService` owns the owner-only plan aggregate: a deterministic,
write-free **preview** (`POST /scheduling/suggestions`) whose content
fingerprint (`previewVersion`, SHA-1 over canonically sorted request + row
state + member roles/preferences + timezone + capacity rows + proposal) is
confirmed by **apply** (`POST /scheduling/apply`).

- **Lock order (refines, never inverts ADR-011):** `house → room_task
  (ascending ids) → task definition (ascending ids) → house_user (ascending
  ids) → assignee writes`. Creation/setup writers take the house lock first
  and serialize; completion/undo (`room_task → room → house_user`) and all
  other writers block on row locks apply already holds — no ordering cycle.
- **Never apply against drifted state:** apply recomputes the identical plan
  from freshly locked rows; any fingerprint mismatch is a `409 CONFLICT`
  with zero writes. Two applies of one preview serialize into one commit and
  one clean conflict.
- **Planning rules** (contract detail in [ADR-012](../adr/ADR-012-actionable-household-scheduling.md)
  and [`docs/api/README.md`](../api/README.md)): legal recurrence/flex slots
  first, eligibility before capacity, availability/energy/child limits as
  hard constraints, full effort per assignee, replace-not-add accounting,
  claimed rows untouched.
- **Mapper lock primitives:** `RoomTaskMapper::lockForUpdate` /
  `HouseUserMapper::findForUpdate` / `HouseMapper::findForUpdate` exist as
  the MySQL/PostgreSQL `FOR UPDATE` + SQLite self-assignment pattern;
  `TaskMapper::lockForUpdate` follows the same shape. Any new writer that
  targets rows these aggregates lock must be audited against the order
  above before it ships.

### Aggregate Idiom: Completion/Undo & Read Decomposition (CS-PLAN-064/067)

The completion/undo aggregate (`CompletionService`) and the room-task read
cluster (`RoomTaskReadService`) decompose into focused service owners while
keeping one transaction boundary, one lock order and one ledger contract
(decision D2). Extracted participants share the caller's connection and never
open, commit or roll back their own transaction:

- **`RoomTaskReadService`** — the batched read/enrichment cluster: house-/
  room-/scope-wide instance fetches, the visibility matrix (`TaskAccessPolicy`,
  batched task + assignee reads), `{roomTask, task, room}` row assembly
  (`rowsForRoomTasks` — the single owner of the formerly duplicated daily
  enrichment), filtered-daily ("My Day") assembly, due-today/overdue rows and
  the house timezone resolution. `RoomTaskController::index()` serves the
  house-wide selector from it (D1); `RoomTaskService` keeps mutations, the
  ownership seams, room stats and the calendar-range projection.
- **`CompletionEffectService`** — effect-record snapshot/restore (ADR-011
  JSON contract): `snapshot()` before any mutation, `restore()` on undo
  (including LIFO title removal and the checklist rewrite). "Restore, never
  recalculate."
- **`CompletionPostCommitService`** — the guarded post-commit boundary (D7):
  weekly-goal read, overdue-takeover dispatch with membership re-check,
  weekly-goal claim+dispatch, title-unlock notification; failures log
  (`completion_post_commit_degraded` / `undo_post_commit_degraded`) and set
  `rewards.degraded`, never fail a committed aggregate.
- **`MedalService`** — the weekly medal cluster (evaluation predicates,
  race-safe `awardMedalIfNew`, `createMedal`) plus the house medal listing
  (`getMedals`); award timing unchanged (read-triggered, never inside the
  completion transaction).
- **`handleTaskCompletion` ledger contract (§3.2):** the caller-owned
  `CompletedTask` row is REQUIRED — `GameLogicService` fills the reward
  outputs but never inserts; `CompletionService::complete()` inserts exactly
  one ledger row with `occurrence_due` + `before_state` + `deltas`. The
  former test-only self-inserting branch was deleted.

Frontend counterpart (§3.4): the TaskList surface decomposes into
`TaskListItem` (row), `TaskListBulkBar` (selection bar), `TaskListDetails`
(assignee picker + checklist wiring) and `utils/taskDisplay.js` (pure
display helpers); TaskList stays the orchestrator owning sorting, the bulk
selection state machine, the optimistic assignee drafts (CS-PLAN-065 §3.5)
and expansion. `AssigneeChips` is a fully controlled component (members via
prop, no eager fetch — the per-row member fetch was the flagged anti-pattern).

### Size Guideline Exception Inventory (CS-PLAN-067 D6, 2026-09-13)

The production size guideline is **~300 lines per file / ~30 lines per
method** (project-rules §1, KISS). It is applied through cohesive extraction
plus this **named exception inventory** — never a repository-wide
line-count rewrite, and never a blanket "pre-existing" exemption. Every
production file in `lib/` and `src/` above the guideline is listed here
with its cohesive responsibility and disposition. Physical lines include
docblocks, templates and scoped CSS; measured 2026-09-13 at `master`
post-WP8. WP8 already reduced its named hotspots (`TaskList.vue`
~1,160 → 357, `GameLogicService` 517 → 349, `RoomTaskService` 833 → 643,
`CompletionService` 772 → 546 via extracted collaborators).

Dispositions: **accepted** (cohesive unit or static/data surface — splitting
would damage cohesion), **named** (exception ratified in CS-PLAN-067 §3.5),
**remainder** (open decomposition candidate, owning backlog item cited).

#### Backend (`lib/`)

| File | Lines | Cohesive responsibility | Disposition |
|------|-------|--------------------------|-------------|
| `Controller/RoomTaskController.php` | 868 | Room-task route surface (selector modes, lifecycle, stats, range); thin validate→service→serialize methods only | **named** (CS-PLAN-067 §3.5: assessed per-method, independently of service orchestration) |
| `Controller/SchedulingController.php` | 456 | Scheduling route surface (preferences, preview, apply); thin methods | accepted (endpoint breadth, no logic depth) |
| `Controller/UserController.php` | 442 | Member/profile route surface; thin methods | accepted (endpoint breadth) |
| `Controller/MinigameController.php` | 343 | Minigame route surface; thin methods | accepted (endpoint breadth) |
| `Controller/RoomController.php` | 309 | Room route surface; thin methods | accepted (endpoint breadth) |
| `Service/SchedulePlanService.php` | 1031 | WP6 preview/apply aggregate: fingerprint revalidation, capacity check, lock order, member-day writes | **named** (CS-PLAN-067 §3.5: cohesive fingerprint/capacity aggregate; budgets only, no optimizer rewrite) |
| `Service/CompletionService.php` | 546 | Completion/undo aggregate owner: transaction boundary, lock order, occurrence/actor gates (effects/post-commit extracted to collaborators) | accepted (D2: the visible ordered aggregate with a specific documented size exception; further splitting would hide the transaction) |
| `Service/RoomTaskService.php` | 643 | Room-task mutations, bulk-assign partial-success semantics, room stats, calendar-range projection | **remainder** (CR-SVC-15: range/stats projection extraction candidate) |
| `Service/RoomTaskReadService.php` | 396 | Batched read/enrichment cluster (house/room/scope/daily/due/claimable + timezone); WP8 product | accepted (read-surface breadth, one owner per D1) |
| `Service/GameLogicService.php` | 349 | Completion-reward orchestration (coins/combo/streak/freezes/teamwork/titles); medal cluster extracted to `MedalService` | accepted (cohesive reward cluster) |
| `Service/TaskCreationService.php` | 421 | WP4 atomic creation aggregate (custom/mount/template, one transaction) | accepted (transaction aggregate) |
| `Service/HouseSetupService.php` | 439 | WP3 first-run setup transaction aggregate (first-run gate, structure, invites) | accepted (transaction aggregate) |
| `Service/TaskService.php` | 423 | Task-definition CRUD + validation set | **remainder** (CR-SVC-15: `createTask` 24 positional params → param object) |
| `Service/HouseService.php` | 385 | House aggregate: membership, weekly-goal progress, house stats | accepted (cohesive aggregate) |
| `Service/MinigameService.php` | 361 | Minigame domain logic (boosts, scores, energy spend) | accepted (cohesive domain) |
| `Service/NotificationService.php` | 308 | Notifier dispatch cases per notification subject | accepted (dispatch-table breadth) |
| `Service/TemplateService.php` | 311 | Task catalog templates + level presets | accepted (catalog surface) |
| `Service/RewardConfigService.php` | 476 | Static reward configuration registry | **named** (CS-PLAN-067 §3.5 / CS-PLAN-049: static data table — not split to meet a physical target) |
| `Service/TestDataService.php` | 512 | Test-only seeding tooling (documented exemption, WP1 D6) | accepted (test tooling, explicitly classified) |
| `Db/RoomTask.php` | 684 | Central entity: domain accessors, status computation, JSON serialization | accepted (entity surface; data-driven) |
| `Db/RoomTaskMapper.php` | 540 | SQL primitives for the central instance table (house/room/scope/daily/locks) | accepted (SQL primitive collection) |
| `Db/CompletedTaskMapper.php` | 523 | Ledger SQL: stats aggregates, occurrence lookups, weekly windows | accepted (SQL primitive collection) |

#### Frontend (`src/`)

| File | Lines | Cohesive responsibility | Disposition |
|------|-------|--------------------------|-------------|
| `views/Settings.vue` | 928 | Settings composition (house/floors/rooms/members/roles/energy sections) | accepted (section-per-concern template breadth; each section is independent UI) |
| `components/Setup/SetupWizard.vue` | 816 | WP3 setup journey step machine | accepted (cohesive step machine; step extraction only with a reviewed UI contract) |
| `views/RoomDetail.vue` | 786 | Room composition root (stats, scoped list, dialogs) — rows carried by shared TaskList units | accepted (composition root) |
| `views/TodayView.vue` | 602 | My Day surface (WP7 exit: instructions, execution rows, undo) | accepted (composition root) |
| `components/Tasks/TaskEditForm.vue` | 855 | Unified create/edit form contract (UX-001): field validation surface | accepted (one form contract; field breadth) |
| `components/Tasks/TaskChecklist.vue` | 521 | Checklist surface: lazy discovery, toggles, sub-tasks | accepted (cohesive checklist domain) |
| `components/Tasks/TaskListItem.vue` | 420 | WP8 row unit: status/content/controls/a11y incl. scoped CSS | accepted (row surface) |
| `components/Tasks/TaskList.vue` | 357 | WP8 orchestrator: sorting, bulk selection, expansion (was ~1,160) | accepted (orchestrator, extracted units carry the rest) |
| `components/Tasks/FlexWindowDisplay.vue` | 334 | Flex-window bounds presentation | accepted (presentational) |
| `components/Scheduling/SchedulePlanner.vue` | 676 | WP6 planning surface (member grids, preview/apply, dialogs) | accepted (planning composition root) |
| `components/Scheduling/AvailabilityEditModal.vue` | 469 | Owner availability grid editor | accepted (grid editor surface) |
| `components/Scheduling/ScheduleCalendar.vue` | 391 | Week grid + capacity/overcapacity display | accepted (presentational grid) |
| `components/Members/MemberList.vue` | 412 | Member management surface | accepted (template+CSS dominant) |
| `components/Profile/TitlePicker.vue` | 380 | Title pack/tier picker | accepted (presentational) |
| `components/Dashboard/FloorList.vue` | 462 | Floor/room dashboard list | accepted (presentational) |
| `components/Dashboard/RoomCard.vue` | 445 | Room status card | accepted (presentational) |
| `components/Dashboard/QuickStats.vue` | 372 | Stat cards | accepted (presentational) |
| `components/Dashboard/ArcadeSection.vue` | 409 | Minigame catalog entry | accepted (presentational) |
| `components/Minigames/SnakeGame.vue` | 612 | Complete Snake game (loop, rendering, scoring) | accepted (self-contained game domain) |
| `components/Minigames/Game2048.vue` | 416 | Complete 2048 game | accepted (self-contained game domain) |
| `components/Minigames/BoostShop.vue` | 355 | Boost shop surface | accepted (presentational) |
| `components/Minigames/SnakeLeaderboard.vue` | 344 | Snake leaderboard | accepted (presentational) |
| `components/Minigames/MemoryGame.vue` | 306 | Memory game | accepted (self-contained game domain) |
| `views/Leaderboard.vue` | 512 | Ranking surface with medal display | accepted (presentational) |
| `views/TaskManager.vue` | 530 | Task manager composition root (filters, stats bar, modals) | accepted (composition root) |
| `views/Dashboard.vue` | 327 | Dashboard composition | accepted (composition root) |
| `views/CalendarView.vue` | 301 | Calendar composition | accepted (composition root) |
| `store/roomTasks.js` | 827 | Canonical instance store: projections, generations, reconciliation (CS-PLAN-066 D3 one-owner rule) | accepted (D3: splitting would duplicate cache/reconciliation ownership) |
| `store/scheduling.js` | 467 | Scheduling state: preferences, capacity/overcapacity getters | accepted (cohesive store) |
| `store/minigame.js` | 379 | Minigame state | accepted (cohesive store) |
| `store/rooms.js` | 335 | Floor/room state | accepted (cohesive store) |
| `store/user.js` | 311 | Member/role state | accepted (cohesive store) |
| `utils/icons.js` | 571 | Static icon registry | accepted (static data table — not split to meet a physical target) |

Maintenance rule: a file newly crossing the guideline must either be
extracted along a cohesive seam in the change that grows it, or receive an
individual entry here in the same change. The two **remainder** rows are
owned by CR-SVC-15; no other open decomposition debt is implied by this
inventory.

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
$this->appLogger->info('Task completed', [
    'event' => 'task_completed',
    'taskId' => $task->getId(),
    'userId' => $user->getId()
]);
```

### Frontend Error Tracking
```javascript
import { logger } from '@/utils/logger'

window.addEventListener('error', (event) => {
    logger.error('Unhandled frontend error', event.error, {
        event: 'window_error',
    })
})
```

## Future Enhancements

- **WebSocket**: Real-time updates for multi-user
- **Push Notifications**: Task-due reminders ship via the Nextcloud notification system (see [Notifications](#notifications-nextcloud-bell--mobile-push)); richer per-category preferences remain future work
- **Mobile App**: Native iOS/Android
- **Statistics Dashboard**: Advanced analytics
- **AI Suggestions**: Smart task scheduling
- **Integrations**: Calendar, Home Assistant, etc.

---

This architecture is designed to be scalable, maintainable, and aligned with Nextcloud best practices.
