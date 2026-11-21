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
│   │                           #   Minigame, User, Game, Page, Test, Pause,
│   │                           #   Preference, ArcadeSettings and TaskCategory —
│   │                           #   the last two owner-only, CS-PLAN-090 —, Completion,
│   │                           #   RoomTaskCycle (postpone, history, sleep), RoomTaskEdit
│   │                           #   (the one form's save, CS-PLAN-091 WP-B6) and
│   │                           #   CoinMultiplier (owner-only, CS-PLAN-091 WP-B5)) +
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
│   │                           #   TaskChecklistItem, TaskAssignee, RoomTaskChecklistProgress,
│   │                           #   Cycle, CycleProgress and BonusPeriod of ADR-021)
│   │                           #   (AvailabilitySettings entity/mapper pruned in CS-PLAN-055)
│   ├── Exception/              # App exceptions (NotFoundException, ForbiddenException) —
│   │                           #   domain-typed (NC 31 dropped the framework equivalents)
│   ├── Migration/              # Schema migrations (Version1000 … Version1033, non-
│   │                           #   contiguous — 1014/1017–1020 absent; dead no-op steps
│   │                           #   1003/1010/1013/1017/1018/1019/1020 pruned CS-PLAN-055,
│   │                           #   pre-release fresh-install edit — no back-compat; the 26
│   │                           #   retained-baseline steps are immutable), then
│   │                           #   Version1034 (data only, the first step on the retained
│   │                           #   baseline, CS-PLAN-090 D-090-16), Version1035 (the
│   │                           #   schema of the derived windows), Version1036 (their data
│   │                           #   cutover) and Version1037 (the coin multipliers),
│   │                           #   CS-PLAN-091
│   ├── Notification/           # ChoreStrikeNotifier (renders localized notifications;
│   │                           #   registered via Application::registerNotifierService —
│   │                           #   the legacy info.xml <notifications> tag is not wired
│   │                           #   on modern Nextcloud, CS-PLAN-023)
│   ├── Service/                # Business logic (House, Floor, Room, RoomTask,
│   │                           #   Task, GameLogic, Scheduling,
│   │                           #   Minigame, ArcadeCatalog, ArcadeAccess
│   │                           #   (derived chore unlocks, ADR-019) with
│   │                           #   ArcadeStartPointStore (per-person reset start
│   │                           #   points), TaskCategory (the household's category
│   │                           #   list), ColorScheme (per-person scheme),
│   │                           #   RewardConfig, Template,
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
│   │   ├── Today/              # TodayStatusLine (CS-PLAN-090 WP-B),
│   │   │                       #   TodayBonusSection and TodayBonusRow (the bonus tasks),
│   │   │                       #   BonusDaysBanner (a running bonus period; CS-PLAN-091)
│   │   ├── Absence/            # AbsenceRangeForm, AbsenceOwnerPanel (CS-PLAN-090 WP-A)
│   │   ├── Settings/           # SettingsCategory and one component per category: Title,
│   │   │                       #   NotificationPreferences, Appearance, Planning, House,
│   │   │                       #   Structure, TaskCategory, Arcade, DangerZone (CS-PLAN-090);
│   │   │                       #   RewardSettings with CoinFactorSettings and
│   │   │                       #   BonusDaysSettings above the catalog (CS-PLAN-091 WP-B5)
│   │   ├── Insights/           # HouseStatsCards, MemberFairnessTable, ActivityFeed,
│   │   │                       #   RankingPanel (the former Leaderboard page),
│   │   │                       #   CorrectionReview (CS-PLAN-090 WP-E)
│   │   ├── Setup/              # SetupWizard, SetupWizardEntry (the way in, on Today
│   │   │                       #   and under Floors & Rooms in Settings)
│   │   ├── Tasks/              # The one row: TaskRow (Today and the task list) with
│   │   │                       #   TaskRowDetails (opened in place); the one form: TaskForm
│   │   │                       #   with TaskRhythmField, TaskWindowNote, SubTaskEditor,
│   │   │                       #   TaskFormMore, TaskTemplateFill, TaskScopeDialog
│   │   │                       #   (CS-PLAN-091 WP-B6); TaskList, AssigneeChips,
│   │   │                       #   TaskChecklist (ticks only), RoomPicker (the form's room
│   │   │                       #   and the list's floor/room quick filter), TaskFilterBar
│   │   │                       #   with TaskPeopleFilter, TaskEnergyField,
│   │   │                       #   TaskMeta, TaskGroupList, TaskPager, CalendarControls,
│   │   │                       #   CompletionPicker (a short tap on Done), PostponeButton,
│   │   │                       #   TaskHistory (CS-PLAN-091 WP-B2/WP-B3), SleepControl (waking
│   │   │                       #   a sleeping task from its row, WP-B4)
│   │   ├── Minigames/          # ArcadeCatalog, GameShell (+Header, Overlay, Dpad), GameLeaderboard,
│   │   │                       #   ArcadeGateSetting (owner switch), ArcadeThresholdSettings and
│   │   │                       #   ArcadeResetList (owner thresholds and reset), gameRegistry,
│   │   │                       #   board.js (arcade palette + sizing), draw.js (canvas
│   │   │                       #   primitives), effectsLayer.js (pooled particles, popups,
│   │   │                       #   shake, flash), games/grid.js (shared directions and
│   │   │                       #   cursor), games/<id>/ (pure logic + a canvas render.js
│   │   │                       #   or a DOM Board.vue, one lazy chunk per game; CS-PLAN-086)
│   │   ├── Common/             # Icon, SvgIcon, Emoji, Modal, MedalBadge, SegmentedSwitch
│   │   ├── Profile/            # TitleBadge (single retained series; the
│   │   │                       #   TitlePicker retired with CS-PLAN-070 WP4)
│   │   ├── Rewards/            # RewardCatalogEditor (owner Settings section),
│   │   │                       #   RewardRequestList, RewardRequestDetails
│   │   │                       #   (CS-PLAN-070 WP8), RewardWalletBanner
│   │   └── Scheduling/         # SelfAvailabilitySettings, AvailabilitySettings, AvailabilityEditModal,
│   │                           #   EnergyBudgetSettings, BudgetAnalysis, WeekBudgetGrid,
│   │                           #   ScheduleCalendar, OvercapacityWarning
│   ├── composables/            # useCelebration (confetti + SFX), useToasts,
│   │                           #   useStoreReset (central identity reset, WP6/WP8),
│   │                           #   useTaskCompletion (the one completion call of every task
│   │                           #   surface: a hold, or the split of a row's picker),
│   │                           #   useTaskFormState (the state of the one form),
│   │                           #   useColorScheme (the viewer's colour scheme on <body>)
│   ├── constants/              # Shared constants (taskVisibility, titles, scheduling)
│   ├── services/               # Shared API client (api.js — single URL prefix,
│   │                           #   envelope unwrap, ApiError normalization)
│   ├── store/                  # Pinia stores (house, rooms, roomTasks, taskCycle, tasks,
│   │                           #   taskForm (the form's two saves), game, user, scheduling,
│   │                           #   checklist, minigame, titles,
│   │                           #   ranking, setup, preferences, pauses, rewards)
│   │                           #   + userAutoInit bootstrap plugin
│   ├── styles/                 # tokens.css (theme/motion CSS vars), mobile.css,
│   │                           #   feedback.css (completion feedback of a row),
│   │                           #   schemes.css (the colour scheme palettes, CS-PLAN-090 WP-K),
│   │                           #   settings.css (form styles of the Settings categories)
│   ├── views/                  # Today (the landing page), Insights, TaskManager (the one task
│   │                           #   list: by due date, by room or by person),
│   │                           #   Scheduling (the weekly plan), Calendar, Settings, Rewards,
│   │                           #   Arcade, Absence
│   └── utils/                  # currentUser, dateKeys, icons, roomIcons, logger,
│                               #   confetti, sound, energyBudgets, todaySections (the
│                               #   sections of Today as pure functions), taskGrouping and
│                               #   taskFilters (the groups and filters of the task list),
│                               #   taskCategories (category names and options),
│                               #   colorSchemes (the scheme list and its initial state),
│                               #   commandId (the command UUID of every receipt),
│                               #   taskFormModel (the one form's rhythm and fields)
├── l10n/                       # Translations
│   ├── templates/
│   │   └── chorestrike.pot     # Translation template
│   ├── de.json                 # German
│   └── en.json                 # English
├── img/                        # App icons & PWA bootstrap (design.md §4)
│   ├── app.svg, app-dark.svg   # Monochrome mascot glyph: header / light surfaces
│   ├── mascot-*.png            # PWA icons (192, 512, maskable 512)
│   └── manifest.json           # PWA manifest, linked by Nextcloud's page layout
├── tests/                      # Test suites
│   ├── unit/                   # PHPUnit (PHP) + Jest (JS/Vue)
│   ├── integration/            # PHPUnit integration tests
│   ├── e2e/                    # Playwright end-to-end tests
│   └── docs-screenshots/       # Documentation capture (npm run docs:screenshots), no gate
├── docs/                       # Documentation
│   ├── README.md               # Documentation map
│   ├── CONTRIBUTING.md         # Community docs (with CODE_OF_CONDUCT.md,
│   │                           #   SECURITY.md, THIRD_PARTY.md — auto-detected
│   │                           #   here by GitHub, CS-PLAN-069)
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
├── .github/                    # GitHub templates and CI
│   ├── DISCUSSION_TEMPLATE/    # q-a.yml: form for the Q&A category
│   ├── ISSUE_TEMPLATE/
│   ├── pull_request_template.md
│   ├── CODEOWNERS
│   ├── dependabot.yml
│   └── workflows/
│       ├── checks.yml               # Automatic push/PR gate (no Docker)
│       ├── release-gate.yml         # Full blocking gate (manual dispatch)
│       ├── test.yml                 # Profile/browser smoke (manual dispatch)
│       ├── full-matrix.yml          # All browser projects (manual dispatch)
│       └── runtime-check.yml        # Weekly runtime drift (scheduled)
├── private/                    # Local dev only (gitignored)
├── LICENSE
├── README.md
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
2. **Component dispatches store action** → `useRoomTasksStore.completeRoomTask(roomTaskId)`; the store resolves the receipt of the current cycle (`commandId` UUID + `cycleKey` and `expectedVersion` from the cached row's `cycle` block) and posts it (ADR-021 §4)
3. **API request** → `POST /apps/chorestrike/api/v1/room-tasks/{id}/complete` with the closed-set receipt body
4. **Controller** → `RoomTaskController::complete()` (validates the receipt closed set)
5. **Service logic** → `CompletionService::complete()` (WP3 transaction aggregate, ADR-014) authorizes, executes-or-replays the `commandId` via `CommandService`, then under the documented lock order (house → room_task → room → memberships → wallets → completion rows): resolves the target via `CycleService::resolveTargetUnderLock` (the current cycle derived by `WindowService`, its key and the cycle version; `STALE_TARGET`, `ALREADY_COMPLETED` or `SAME_DAY` otherwise), freezes the work evidence (`completed_tasks.work_*`, the cycle key and its final window, the classification, `total_energy` from the definition, `rate_minor`), writes the selected allocation (`work_shares` + `allocation_reviewed`), posts the exact wallet-union deltas to `wallet_entries` (`WalletService::postEntries`), appends the requested `room_events` delta, closes the cycle (`CycleService::closeUnderLock`: one `chorestrike_cycles` row, the `next_due` projection of the next cycle) and recomputes the personal recognition of every participant (`RhythmService` / `WeeklyGoalService`). `WindowService` (ADR-021) is the single recurrence authority; nothing stores a future appointment.
   - Exact minor-unit math throughout (`E * P` energy minor, `10 * E * P * factor * bonusPercent / 100` coin minor at the frozen rate, rounded down once; the member's coin factor and the bonus percent of the work day are frozen on the completion, ADR-021 §11) — no coin integers, no combo/team-spark/boost dimensions (legacy economy retired, ADR-014)
   - The room cleanliness/monster change is the **saved requested effect**; the replayed cache clamps (`RoomReplayService`)
   - Medals are NOT awarded here — they stay read-triggered via `MedalService::checkAndAwardMedals`
6. **Database update** → Multiple mappers (atomic within the transaction)
7. **Response** → Updated task, the `cycle` block of the next current cycle, `completionId`/`replay`, the frozen completion evidence + selected allocation, the caller's own wallet totals in minor units, the weekly-goal projection
8. **Store update** → Every owning store reconciles from the one authoritative payload before any event fires (CS-PLAN-066 D10); a `409` triggers `refreshDerivedProjections()` instead of a blind retry
9. **UI updates** → Room cleanliness/monster and wallet/contribution readouts update from the reconciled stores (no confetti coin mint; the WP3 cutover retired the reward-celebration payload)

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
- `cycles_from` (date, nullable) — the first house-local day of the derived windows (ADR-021 §12): set at creation, or by the cutover step for a house from before it. Recognitions read stored occurrences before it and cycles from it on
- `created_at`, `updated_at` (datetime)

#### `chorestrike_wgoal_receipts`
- `house_id` (bigint) and `week_start` (date) — unique planned-week notification claim
- `claimed_at` (datetime) — durable at-most-once dispatch identity across retries, historical corrections and missed job runs

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
- `energy_cost` (int, 1-20, `EffortAllocationService::ENERGY_MAX`) — the energy/effort of a task (the legacy `difficulty` column was renamed; `energy_cost` is the source of truth)
- `priority` (int, 1-3)
- `task_tier` (string: `bronze`/`silver`/`gold`) — coin-tier multiplier source
- `frequency_type` (daily/weekly/monthly/custom), `frequency_value` (int)
- `estimated_minutes` (int)
- `category` (string) — the DB-backed `is_template` column was removed (CS-PLAN-027 D7); catalog templates now live in `lib/Templates/`
- `visibility_type` (string, default `public`) — `public` | `private` | `admin-only` (CS-PLAN-005); the sole visibility representation — the former `is_private` shim column was folded out (CS-PLAN-060 WP1 D1)
- `owner_user_id` (string, nullable) — creator of private/admin-only tasks (Nextcloud UID)
- `flex_days` (int), scheduling/recurrence fields, `fixed_month_day` (smallint 1–31, nullable: the day a fixed monthly rule runs on), `created_at` (datetime)
- `is_voluntary` (boolean) — a bonus task: no window, no cycle (ADR-021 §8)
- Index `idx_tasks_visibility` on `(visibility_type, owner_user_id)`

> **Visibility matrix:** `public` = visible to all house members; `private` = visible only to `owner_user_id`; `admin-only` = visible only to house owners. Enforced on reads in `TaskMapper::findVisibleByHouse()` and per room task in `RoomTaskAccess` (ADR-010), and on mutation in `TaskService::prepareUpdate()` under `RoomTaskEditService` (the one form's save, CS-PLAN-091 WP-B6) and in `TaskCreationService`; a denial of visibility is the anti-enumeration 404.

#### `chorestrike_room_tasks` (task instances scheduled into a room)
- `id` (bigint, PK)
- `room_id` (bigint, FK), `task_id` (bigint, FK)
- `next_due` (datetime), `last_completed` (datetime, nullable) — `next_due` is a **projection** of the current window's last day for the list queries that sort and filter by it; every command that changes a window updates it in its own transaction and the timeline job repairs what the passing of time changes (ADR-021 §3)
- `anchor_on` (date, nullable) — the first date of an after-completion or one-time task until a valid completion anchors it, and the day a fixed rule counts from
- `cycle_key` (string 40, nullable), `postpone_count` (smallint), `postpone_days` (int) — the postponement of the cycle named by `cycle_key` (`PostponeService`; a completion resets it)
- `cycle_version` (bigint) — bumps with every change of the current cycle; the staleness guard of the completion receipt
- `sleeping_since` (datetime, nullable), `wake_on` (date, nullable) — a sleeping task has no cycle; `SleepService` writes both, the timeline job wakes it on `wake_on` (ADR-021 §7)
- `is_active` (boolean), `created_at` (datetime)
- claim fields (`claimed_by_user`, `was_claimed`, `original_assignee`, `claimed_at`) — the live overdue-takeover/claim feature; the legacy single-assignee column (`assigned_to_user_id`) and the abandoned group-assignment remnants (`assignment_type`, `assigned_to_group`, `assigned_by_user_id`, `assigned_at`) were folded out — `chorestrike_task_assignees` is the canonical assignee store (CS-PLAN-060 WP1 D2/D9)
- `ideal_due_date` (datetime, nullable) — projection of the planned day of the current window

#### `chorestrike_house_users` (membership + gamification state)
- `id` (bigint, PK)
- `house_id` (bigint, FK), `nextcloud_user_id` (string), `display_name` (string)
- `role` (string: `owner`/`member`/`child`), `joined_at`, `last_activity` (datetime, nullable)
- `contribution_minor` (bigint) — lifetime work in exact minor units (100 per energy); leaderboard/fairness/title signal
- `current_streak` (int), `longest_streak` (int) — derived records: the base below plus the cycles since the cutover (`RhythmService`, ADR-014, ADR-021 §12)
- `streak_base`, `longest_streak_base` (int, nullable) — the streaks a member held when the derived windows started (D-091-04)
- `scheduling_preferences` (json) — weekday availability etc.
- `selected_title_pack` (string, nullable), `unlocked_titles` (json) — role-title state (CS-PLAN-006)
- `wallet_id` (bigint, nullable) — the member's wallet binding (WP0/WP3)
- `coin_factor` (smallint, default 1) — the owner-set coin factor 1, 2 or 3; coins only (`CoinMultiplierService`, ADR-021 §11)

> **Legacy economy retired (WP3, ADR-014):** the `coins`, `coin_multiplier`,
> `streak_freezes`, `contribution_score` columns and the combo/teamwork/claim
> completion metadata are gone from the fresh-install chain (Version1030);
> value lives in `chorestrike_wallets`/`chorestrike_wallet_entries` as exact
> minor units. Earlier currencies (`current_sparks`/`total_sparks_earned`,
> dropped in Version1026) stay retired.

#### `chorestrike_completed_tasks` (WP3 frozen work evidence)
- `id` (bigint, PK)
- `room_task_id` (bigint, FK), `user_id` (bigint, FK) — the completer house user (immutable audit)
- `completed_at` (datetime, immutable), `undone_at` (datetime, nullable)
- `cycle_key` (string 40, nullable), `window_start_on`, `window_end_on` (date, nullable) — the cycle the completion closed and its final window, frozen (ADR-021 §3); null for bonus work and for completions from before the derived windows
- `completion_class` (string) — frozen at completion: `on_time`, `late` or `bonus`; `agreed`, `late`, `voluntary` on completions of the occurrence timeline
- `schedule_rev_id`, `occurrence_ordinal`, `occurrence_due` — the occurrence identity and planned date of a completion from the occurrence timeline (ADR-013); not written any more
- `work_date` (date), `work_zone` (string) — house-local date + zone
- `total_energy` (int, frozen at completion), `rate_minor` (int — coin minor per energy, frozen)
- `bonus_percent` (smallint, nullable), `coin_factors` (json, nullable) — the bonus percent of the work day (100 or 150) and the coin factor of every member by wallet id, frozen at completion (ADR-021 §11); empty on completions from before the multipliers, which read as 100 and × 1
- `completion_version` (int), `allocation_revision` (int) — optimistic concurrency + selected allocation pointer
- Corrections never rewrite these facts: attribution appends a new allocation
  revision; undo reverses the latest selected revision once (ADR-014)

#### `chorestrike_medals`
- `id` (bigint, PK)
- `user_id` (bigint, FK)
- `medal_type` (string) — gamification: `team_anchor`/`flow_keeper`/`room_guardian`/`team_player`/`streak_keeper`; standard: `gold`/`silver`/`bronze`
- `week_start` (date), `value` (int), `awarded_at` (datetime)

> **Award reality (CS-PLAN-012, updated CS-PLAN-067 §3.2):** all five gamification medal types are awarded in the live `MedalService::checkAndAwardMedals()` flow (extracted from `GameLogicService`, award timing unchanged — read-triggered): `team_anchor` (10+ tasks/week), `streak_keeper` (7+ day streak), `flow_keeper` (4+ distinct active days), `room_guardian` (5+ completions in one room), `team_player` (3+ combo bonuses). `gold`/`silver`/`bronze` are legacy. `MedalService` also owns the house medal listing (`getMedals`).

### Supporting Tables

| Table | Purpose | Entity |
|-------|---------|--------|
| `chorestrike_minigame_scores` | Minigame scores (play-only economy since WP3 — no boosts) | `MinigameScore` |
| `chorestrike_chk_items` | Task checklist template items (Version1005 name) | `TaskChecklistItem` |
| `chorestrike_cycles` | Closed cycles (ADR-021 §3, D-091-17): one row per room task and cycle key under a unique key — `done` (projects the cycle facts of its completion; an undo deletes it) or `missed` (a fixed-day cycle whose window ended, written once by the timeline job); final window, week, outcome, class, energy and the wallets it counted for | `Cycle` |
| `chorestrike_bonus_periods` | Bonus periods of a house (ADR-021 §11): `starts_on` and `ends_on` as house-local days, both inclusive; a cancelled period keeps its row with `cancelled_at`. A completion on such a day pays coins × 1.5 | `BonusPeriod` |
| `chorestrike_cycle_progress` | Sub-task ticks per cycle, unique `(room_task_id, cycle_key, checklist_item_id)`; the ticks of a closed cycle stay | `CycleProgress` |
| `chorestrike_chk_progress` | Checklist progress keyed by an occurrence `(schedule_rev_id, ordinal, item_id)` — history; no writer since ADR-021 | `RoomTaskChecklistProgress` |
| `chorestrike_task_assignees` | Canonical multi-assignee join table (room-task ↔ Nextcloud user) (CS-PLAN-002; sole assignee store since CS-PLAN-060 WP1 D2) | `TaskAssignee` |
| `chorestrike_commands` | CommandService command log (house, actor, UUID, action, payload hash, result refs) — the execute-or-replay/conflict authority (WP3 generalized the receipt to every mutation) | `CommandReceipt` |
| `chorestrike_wallets` | Persistent per-house work wallets (WP0/WP3: one per member, binding under lock; survive departure, never re-bind erased facts; cached booked/reserved/available + funding-hold watermark) | `Wallet` |
| `chorestrike_wallet_entries` | Immutable value ledger (wallet, source kind/id/revision, signed `delta_minor`, reason, actor; unique key = idempotency identity) | `WalletEntry` |
| `chorestrike_work_shares` | Per-completion allocation revisions (wallet, share pct, energy/coin minor, actor, allocation_reviewed) — every selected revision auditable | `WorkShare` |
| `chorestrike_completion_revs` | Append-only completion audit chain (revision counters, actor house-user id, action complete/attribution/date/undo, bounded reason_code) | `CompletionRevision` |
| `chorestrike_room_events` | Journaled room truth (baseline/requested-delta/manual-delta/absolute-reset, command UUID, `(effective_at, id)` order, saved baselines; the room cache is a replayed projection) | `RoomEvent` |
| `chorestrike_schedule_revs` | Schedule revisions of the occurrence timeline (cadence/window defaults, agreed frontier) — history; no writer since ADR-021, read by the cutover step | `ScheduleRevision` |
| `chorestrike_occurrences` | Stored occurrences (public key `rev:ordinal`, saved windows, energy, suspension state/evidence) — history; no writer since ADR-021. The recognitions read them for the time before `houses.cycles_from` | `Occurrence` |
| `chorestrike_occ_members` | Per-occurrence participant facts (role, obligation, exact share, disposition) — history, read with the stored occurrences | `OccurrenceMember` |
| `chorestrike_agreements` | Agreement evidence log (kind, actor, old/new state, consent, pause_ref) — history; no writer since ADR-021, still anonymized on account erasure | `Agreement` |
| `chorestrike_pauses` | Revisioned pause groups (personal/house scope, requested/acknowledged/rejected/cancelled states, ranges, illness flag) | `Pause` |
| `chorestrike_reward_items` | Owner-administered catalog wishes (WP7: title/description CONTENT, positive `price_minor`, archived flag, optimistic version, nullable creator membership/wallet provenance; archived, never deleted) | `RewardItem` |
| `chorestrike_reward_requests` | C4 request lifecycle (WP7: requester wallet identity, frozen title/description/price snapshot, state, version, approval provenance incl. role revision, stored settlement/refund entry references) | `RewardRequest` |
| `chorestrike_request_events` | Append-only request audit trail (WP7: unique `(request_id, revision)` identity, action, old/new state, nullable actor refs, role revisions, bounded reason code, financial entry reference, source command UUID; retained until authorized erasure) | `RewardRequestEvent` |

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
writes, not frontend loops. The one bulk mutation,
`POST /api/v1/room-tasks/bulk-assign` (CS-PLAN-042 / CR-SVC-61), went with
CS-PLAN-091 WP-B6 (D-091-87): assignment travels in the one form's save. The
batched reads (the house-wide `GET /room-tasks`) follow the same rule.

### Single-Surface Rule (structure editing)

Since CS-PLAN-042 there is exactly **one** UI surface for house structure
mutations: the Settings structure section (owner-gated rendering; floor
create/rename/delete/reorder + inline room add/rename/delete). The room page with
its rename and delete actions went into the task list (CS-PLAN-090 WP-C), which only
shows a room's state. New structure affordances belong in
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
  and all room-task mutations (assign, complete, deactivate, …), including the
  bonus rows of *Today* with whom each paid today (`bonusTasks`, CS-PLAN-091
  WP-B4).
- **`useHouseStore`** — the house payload with its running or next bonus period
  and the owner's bonus-period commands (CS-PLAN-091 WP-B5).
- **`useUserStore`** — the current user and the members with their coin factor,
  and the owner's coin-factor command (CS-PLAN-091 WP-B5).
- **`useTaskCycleStore`** (CS-PLAN-091 WP-B3, WP-B4) — the postpone, sleep and
  date-change commands and the history of one task. It holds no room-task state: every
  answer's fresh `cycle` block is reconciled through `useRoomTasksStore`, and a
  deletion is that store's targeted undo. (Its read of the shown target goes
  through a private action of `useRoomTasksStore`; RF-091-09 makes it public.)

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
- **Count projections are endpoint-backed.** The task counters read dedicated
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

The root `App.vue` mounts routed views only after `users/current` confirms
`membership === 'member'`. Unknown membership shows a loading indicator (or a
retryable error after a failed read); denied membership retains the dedicated
authorization state. This ordering matters on a fresh installation: protected
task reads must not race the first-owner transaction. It does not replace or
weaken the backend membership checks.

#### `useHouseStore`
```javascript
{
  state: {
    house: null,          // the full house object; houseHealth lives on house.houseHealth
    insights: null,       // /insights payload (stats, fairness rows, medals)
    activity: [],         // activity feed rows (house store owns the feed source)
    loading: false,
    activityLoading: false,
    error: null,
    activityError: null
  },
  getters: {
    houseName(),
    hasHouse(),
    timezone(),           // house timezone with 'UTC' fallback
    rankingEnabled(), catalogEnabled(), arcadeGateEnabled(),  // household gates
    taskCategories()      // the category list, defaults until an owner saved one (WP-I)
  },
  actions: {
    fetchHouse(),
    updateHouseName(name, timezone),  // PUT /house (owner-gated)
    updateRankingEnabled(enabled), updateCatalogEnabled(enabled), updateArcadeGateEnabled(enabled),
    saveTaskCategories(items),        // PUT /house/task-categories (owner-gated)
    fetchInsights(),
    fetchActivity(days, limit),
    resetHouse(),
    clearError(),
    reset()
  }
}
```

> There is **no** standalone `health` state field or `updateHealth()` action.
> The house payload carries `houseHealth`; the one surface that shows it,
> Insights, reads it from the insights payload. The client of
> `GET /api/v1/house/health` went with the Dashboard's health bar (CS-PLAN-090
> WP-B); the route stays until Release B reworks the surface. Canonical definition:
> [`src/store/house.js`](../../src/store/house.js). (The former
> `fetchHouseStats`/`initializeHouse` examples were fictional — removed,
> CS-PLAN-068.)

Full house reads are latest-wins. A superseded caller waits for the winning
pending read rather than resolving against an empty store; only the owning
promise clears its pending/loading state. Mutations and identity reset still
invalidate old results. Settings initializes
its house draft from that payload before the independent floor read completes,
keeps controls disabled until ready, and offers retry on initialization failure.
The localized display fallback is never a name to persist in timezone/ranking
updates.
Structure controls remain behind their own initial-read loading indicator, so
cached rows cannot briefly offer actions before hydration disables them again.

#### `useRoomsStore`
Structure reads are invalidated both when a mutation starts and when its
successful response is reconciled. A read started during the write can contain
the old database snapshot and must not erase an accepted result. Creates also
deduplicate by ID if an overlapping read already returned the new row;
completion/undo room reconciliation invalidates earlier reads as well.

A superseded structure caller waits for the latest valid full read, or starts
a replacement when a mutation invalidated every available snapshot. It never
reports a partial or empty cache as successful initial hydration. The latest
settled read promise is retained so older callers share its result or failure;
request errors are not retried automatically. Reset has a separate generation:
old read/write callbacks cannot change the new generation's data, error, loading
count or read validity. A cancelled pre-reset read returns `null`; Settings
does not mark that result as ready.

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

#### `useTasksStore` (the definitions of the visible tasks)
```javascript
{
  state: {
    tasks: []
  },
  actions: {
    fetchTasks(),
    upsertTask()   // the definition a create, a save or a completion answered
  },
  getters: {
    activeTasks(),
    getTaskById()
  }
}
```

Definitions are written only through the one form: `useTaskFormStore`
(`create()`, `save()`) sends the two aggregates and hands their answers to the
room-task, definition and checklist stores.

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

#### `useRewardsStore` (CS-PLAN-070 WP8)
```javascript
{
  state: {
    catalog: { active: pageCtx, admin: pageCtx },   // paged reads, ascending id cursor
    requests: { mine: pageCtx, house: pageCtx },    // paged reads, descending id cursor + state filter
    detail: { request, audit, … },                  // one authorized request + on-demand audit
    saving: false,
    error: null,
    conflict: false,        // definitive 409: sources re-read, caller drafts survive
    unresolvedCommand: null,// uncertain outcome: SAME UUID/payload replay (memory only)
    refreshError: null      // command committed, follow-up read failed (visible, non-fatal)
  },
  actions: {
    fetchCatalogPage(scope, {reset}),
    fetchRequestPage(scope, {state, reset}),
    fetchRequestDetail(id), clearDetail(),
    createCatalogItem(fields), updateCatalogItem(item, changes),
    createRequest(item),
    approveRequest(r), rejectRequest(r, reason?), cancelRequest(r, reason?),
    fulfillRequest(r), correctRequest(r, reason),
    retryUnresolved(), dismissUnresolved(), clearError(), reset()
  }
}
```

> The store owns READ PAGES and COMMAND TRANSPORT — never accounting:
> the wallet display source stays `game.wallet` (`fetchGameState()`), the
> household catalog gate flag stays in the house store (narrow versioned
> `updateCatalogEnabled` writer), and no balance/reservation is ever
> incremented locally. Every command mints one RFC 4122 v4 UUID and keeps
> it with the normalized payload until the outcome is known: a definitive
> 4xx discards the identity (a 409 additionally re-reads the touched
> authorized sources), while a transport/5xx outcome retains it for an
> explicit same-key replay — never an automatic fresh-key resend. Each
> page context carries its own read generation (`seq`), so a delayed
> response can never cross a scope/filter switch or survive `reset()`
> (client-state contract above). API contract:
> [`docs/api/rewards.md`](../api/rewards.md); canonical definition:
> [`src/store/rewards.js`](../../src/store/rewards.js).

#### `useSchedulingStore`
```javascript
{
  state: {
    memberAvailability: [],   // owner-only member preference list (with maxDailyTasks)
    energyBudget: {},         // current user's weekly budgets
    weekdayAvailability: {},  // current user's weekly availability
    maxDailyTasks: Infinity,  // null (unset) from the API → Infinity (unlimited, D5)
    loading: false,
    error: null
  },
  getters: {
    totalWeeklyEnergy(),
    getCapacityForDay(),
    plannedEnergyForDay(),         // energy of the cycles planned for a day (cycle.centreOn)
    overcapacityDays()
  },
  actions: {
    fetchUserPreferences(),
    saveWeeklyPreferences(),       // personal bulk PUT — cannot change maxDailyTasks (D5)
    fetchMemberAvailability(),
    updateMemberPreferences(),     // owner member PUT (optionally maxDailyTasks)
    initializeScheduling(),
    clearError(),
    reset()
  }
}
```

> The owner's preview and apply went with ADR-022 (CS-PLAN-091 WP-B1): nothing
> is saved as a plan. The store keeps the preferences and the load view; the
> live suggestion of WP-C reads them. The room-task store keeps per-resource
> request sequences on the filtered-daily and range readers so an older
> response can never overwrite a newer one.

## Notifications (Nextcloud bell + mobile push)

All ChoreStrike notifications are the ONE opt-in daily digest, dispatched
through `NotificationService` (the single choke point around
`OCP\Notification\IManager`) and rendered by `ChoreStrikeNotifier`
(registered INotifier). The single subject constant
(`SUBJECT_DIGEST = 'digest'`) lives on `NotificationService`; the
notifier rejects unknown subjects. Full pipeline:
[notifications.md](notifications.md); decision record: ADR-016
(CS-PLAN-070 WP5 — the per-event bell model was retired with its
callers).

### Subject catalog

| Subject | Trigger point | objectType / objectId |
|---------|--------------|----------------------|
| `digest` | `DigestService::processDueSlots` (five-minute job; at-most-one per member and day) | `digest` / `{houseId}:{houseUserId}:{Y-m-d}` |

The digest content is category/count-based only (planned/agreed-catch-up
task count + queued organizational updates) — the notifier resolves no
object references that could leak hidden work.

### Delivery contract (at-most-once)

The dispatch is a slot-identity claim (CS-PLAN-070 WP5, parent §3.6):
`chorestrike_digest_slots` holds the unique (house, membership,
local_day) daily budget; the pass claims one due slot via a conditional
`pending → dispatching` write under the house-first lock, commits BEFORE
`IManager::notify`, and marks `sent` only on acceptance. Uncertain
outcomes (exception, timeout, crash after the claim) are consumed and
never resent. Missed windows (60 elapsed minutes, capped at the local
day end) expire permanently; personal quiet hours gate the attempt
without shifting the slot. The organizational queue
(`chorestrike_digest_changes`) coalesces per
(membership, object, target slot) with identity rows written inside the
source transactions (`CompletionService` takeover,
`PauseService` decisions).

### Daily job pass

`DailyNotificationJob` is a five-minute TimedJob delegating to
`DigestService::processDueSlots(now)`. The retired freeze/at-risk and
task-due passes are gone (CS-PLAN-070 WP3/WP5 cutover). The pass is
triggerable on demand with an injected reference instant via
`occ chorestrike:trigger-jobs --type daily-notification --as-of
"YYYY-MM-DD[ HH:MM[:SS]]]"` (test mode only) — the SAME pass the cron
job runs.

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
  `removeHouseMember` rejects only the acting owner as target (403
  `CANNOT_REMOVE_OWNER`); another owner is removed with the ordinary
  departure cleanup, and the actor remains an owner (FEAT-UX-015,
  CS-PLAN-088 D-088-19).
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
     `RoomService::getRoomWithTasks`).
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

5. **Bulk statement over an id list / scope** — one UPDATE (or DELETE)
   over the loop's id set instead of per-row statements.
   Pattern: `RoomTaskMapper::tombstoneByRoomIds`,
   `RoomMapper::tombstoneByFloorId` (structural deletion tombstones —
   live rows only, Q-70-01),
   `TaskAssigneeMapper::deleteByRoomTasks`,
   `RoomTaskChecklistProgressMapper::deleteByRoomTaskIds`.
   - Same empty-input early return as idiom 1; ids are `intval`-cast.
   - **There are no FK constraints** — every cascade must be explicit, in
     dependency order (progress → assignees → room tasks → rooms), and
     transactional. Structural deletes tombstone instead of deleting:
     the shared helper is `RoomService::deleteRoomTaskPlanningState`
     (used by `deleteRoom`, `deleteRoomTask`, `deleteFloor`); it removes
     active-planning state (assignees, checklist progress) while
     `completed_tasks` evidence is RETAINED (CS-PLAN-071 Q-70-01
     decision D4, superseding the former D2 completion-deletion claim).

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
`StreakFreezeService::process`), and
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
- Background jobs resolve the single instance house (HouseMapper::findOneForJobs()) per pass; no house yet → the pass is a no-op. Several houses per instance are out of scope (operator decision 2026-10-26, FEAT-UX-042 rejected); they would need per-house passes.
- Frontend mirror: src/utils/dateKeys.js (dateKeyInZone, todayKeyInZone, addDaysToKey, weekdayOfKey) is the single day-key convention; the house store exposes timezone (UTC fallback while the house payload is loading). Never slice ISO strings (slice(0, 10)) or build browser-local keys for day bucketing.
- Grep gate: no bare new \DateTime('…') day-math outside CalendarService callers; no UTC-slice or browser-local day keys in src/ (outside dateKeys.js).

### Write/Identity Foundation (CS-PLAN-071 WP0)

WP0 freezes the structural substrate every later family-rhythm package builds
on; the domain behavior stays dormant until its owning package lands.

- **Single house-first lock order** (full contract + audited writer inventory:
  [development.md](development.md) "Locking and transactions"): `house →
  room tasks → task definitions → rooms → active membership rows → wallets →
  occurrence/completion rows → request/preference/digest rows`. Every writer
  follows the order or a subsequence. This **supersedes** the former
  two-family split (setup `house → room_task → …` vs the ADR-011/012
  completion-only entry order `room_task → room → house_user`) — the ADRs
  carry supersession notes and keep their historical context. WP7 places the
  reward-item/request/event rows in the final child-row group: no writer
  locks a reward row before its house, and lifecycle commands read the
  request only to resolve the wallet binding, then take the wallet lock
  BEFORE the request row lock (fulfill/correct), keeping the documented
  subsequence.
- **`TransactionExecutor`** stays the only transaction owner; it adds a
  bounded SQLite `busy_timeout` and a small bounded retry for recognized
  transient lock/deadlock failures (never for authorization/validation/
  conflicts). On SQLite the contractually-first house-lock statement (the
  no-op self-assignment UPDATE) is the immediate write acquisition.
- **Identity tables (dormant in WP0):** `commands` (receipts — replay on
  identical payload, 409 on changed payload via `CommandService`; no
  production endpoint adopts `commandId` before WP2/WP3), `wallets` (the
  persistent house/account financial identity — created with membership,
  preserved on departure, reattached on re-invitation, deleted only by
  erasure; balances stay zero and no API exposes wallets), `wallet_entries`
  (ledger; unique `(wallet, source-kind, source-id, revision)` idempotency
  key, no runtime writer before WP3). `house_users.wallet_id` binds the
  identity; it grants no access and leaks through no endpoint.
- **Tombstone structural deletion (Q-070-01):** floors/rooms carry
  `deleted_at`, room tasks additionally `deleted_token` (re-keyed
  `(room, task, deleted_token)` uniqueness allows re-created links).
  Deletion cascades tombstone; `task_assignees` + checklist progress die,
  `completed_tasks` evidence is retained and undo stays authorized across
  tombstones. Active-planning reads filter tombstones; history/activity
  reads resolve names through the rows.
- **Account erasure (Q-070-02):** `UserDeletedEvent` →
  `AccountErasureService` deletes memberships/assignees/medals/scores and
  the wallet with its ledger per house (house-first), nulls receipt actors
  and completion actor references, and sweeps already-departed wallets.
  WP7 (CS-PLAN-078): the same house-first aggregate first releases/cancels
  the person's reward holds, purges their reward wishes/requests/events,
  removes queued `reward_request` references from ALL recipients and
  anonymizes their actor references on other retained records (wallet-id
  provenance also finds already-departed actors).
  Anonymized activity actors render via the localized "Former member"
  placeholder; a recreated same-UID account gets fresh ids and cannot
  rebind prior data.

### Aggregate Idiom: Derived Due Windows (CS-PLAN-091 WP-B1, ADR-021)

Every room task has **one current cycle**, computed on read from its rule and
a few stored inputs. Nothing materializes a future appointment; the owner's
preview/apply plan (ADR-012) and the occurrence agreements (ADR-013) are
retired, their tables stay as history.

- **`WindowService`** — the single recurrence authority, pure reads. From
  the definition (`CycleRule`: after completion, fixed weekdays, fixed day of
  the month, one-time, bonus; interval, flex days, bounds), the room task
  (`anchor_on`, postponement, sleep state) and its valid completions
  (`CycleHistory`, one grouped query per batch) it derives the `CurrentCycle`:
  key, planned day and window in house-local days. An after-completion or
  one-time cycle hangs on its first date (`d<Y-m-d>`) until a valid completion
  fulfils it, then on the latest valid completion (`c<completionId>`, the
  highest id, D-091-26). A fixed rule yields its rule days from the anchor;
  a cycle stays current until it is done, or until its window has ended and
  the next one has opened. It also builds the `cycle` block of every row and
  the cycles of a calendar range. Lists, Today, the calendar, the completion
  and the job read windows only through it.
- **`CycleService`** — the writers, all under the caller's house-first locks:
  `resolveTargetUnderLock` (the completion's receipt check; it first records
  the fixed cycles that stopped being current and the job had not reached,
  D-091-43), `closeUnderLock` / `reopenUnderLock` (the `done` row of a
  completion and its removal by an undo, which records a fixed cycle that does
  not become current again as missed), `startUnderLock` (the first date),
  and `ruleChangedUnderLock` (the anchor policy of a rule edit, D-091-44: a
  fixed rule whose days change is anchored on the centre of the replaced
  rule's current cycle, so parity and the monthly day stay). Three
  collaborators carry the rest (RF-091-10): **`CycleProjection`** reads the
  current cycle (`currentFor`) and owns `changedUnderLock`, which every
  window-changing command ends with — it bumps `cycle_version`, ends a task
  that has no cycle left and projects `next_due` (last day of the window) and
  `ideal_due_date` (planned day) — plus the house re-projection;
  **`CycleMisses`** records the fixed cycles that stopped being current;
  **`CycleSleep`** puts a task to sleep and wakes it.
  `postponeUnderLock` moves the current window by half the flex days, at most
  three times, and stores the postponement for that cycle key only, so the
  next cycle starts fresh (ADR-021 decision 6); the row's `canPostpone` and the
  command decide through `WindowService::postponeRefusal()`, and a command's
  receipt is compared with the current cycle in `assertTarget()`.
  `CycleSleep::sleepUnderLock` / `wakeUnderLock` put a task to sleep (the
  fixed cycles it had already missed are recorded first, a repeated call only
  moves the wake date) and wake it on a fresh anchor (`WindowService::wakeAnchor()`: the wake
  day, or a fixed rule's next rule day, so parity and the month day stay;
  ADR-021 decision 7). `redatedUnderLock` is the cycle seam of a date
  correction: the completion side has already saved the entry and moved the
  frozen window of the entry that followed it
  (`CompletionDateCorrection::moveFollower()`); the closed-cycle rows project
  those frozen facts again (decision 9). A command that moves several room
  tasks (a definition edit) reads their completion inputs once
  (`historiesFor`).
- **`CycleAdvanceService`** — the timeline job's pass (`advanceHouse()`): a
  write-free batch check finds the room tasks that need work; each gets its
  own house-first transaction that records the fixed-day cycles that stopped
  being current as `missed` — `CycleMapper::insertIfAbsent` under the unique
  key, so concurrent passes write each cycle once — and repairs the
  projection. An after-completion task is never missed: it stays one red,
  overdue cycle until it is done. A sleeping task whose `wake_on` has come
  wakes on that date, also when the job runs later. `CycleService` stays the
  only writer of cycle rows.
- **`RoomTaskCommand`** — the skeleton of a receipt-protected command on one
  room task (RF-091-10): the house lock, the receipt, the guard that decides
  with the member as they are now (on execution and on replay), the shared
  start of every aggregate (`lockTarget`: lock the room task, refuse a deleted
  or inactive one, compare the version) and the answer read fresh with its
  `cycle` block. Postpone, sleep and the form's save run through it; the save
  is an audited command.
- **`PostponeService`** — the postpone command: the receipt
  (`room_task.postpone`) and the access gate (every member who may see the
  task, children included). The recent activity reads postponements from
  these receipts.
- **`SleepService`** — the sleep command (`room_task.sleep`): adults and the
  owner who may see the task; `CycleSleep` owns the rule, and the form's save
  reuses `applyUnderLock`.
- **`RoomTaskEditService`** — the one form's save (`room_task.edit`,
  CS-PLAN-091 WP-B6): under one lock the definition (in place, or split off as
  a copy for *this room*, FEAT-UX-043), its sub-tasks (`SubTaskWriter`), the
  move, the assignees, then exactly one window change — the first date, the
  rule, the assignees or a plain update — and the sleep switch (a wake comes
  before the window change, going to sleep after it). Before any write it
  refuses a save that would take the member's own sight of the task, and a
  member who may not see every room task of a shared definition splits
  silently (ADR-010, D-091-108, D-091-110). The creation side is
  `TaskCreationService`; both read the body through `TaskFormPayload`,
  `SubTaskList` and `SleepSwitch`, and store definition values through the one
  normalizer of `TaskService`.
- **`RoomTaskAccess`** — the one resolution of a room task for a member who
  acts on it or reads its history: scoped to the house, not deleted, visible
  under the ADR-010 matrix, otherwise the same `404`; and the acting
  membership read fresh.
- **`CycleReceipt`** — the boundary validation of the receipt a cycle command
  sends (complete, postpone, sleep, the form's save): the closed body, the command id, the cycle
  key the command requires or allows, and `expectedVersion` as a whole number
  or its canonical decimal string (D-091-75).
- **`TaskResponsibility`** — who a room task counts for: its assignees who
  are members, as memberships or wallet ids. The one resolution behind the
  closed-cycle rows, the recognition facts and the recognition recompute of a
  correction.
- **`RecognitionFactsService`** — the one reader of what the weekly goal, the
  rhythm and the ranking count (`RecognitionFact`), from two sources split at
  the house's `cycles_from` day so nothing counts twice: stored occurrences
  whose window ended before it (read as stored), and from it on the closed
  cycles plus the open current cycle of every active room task. A cycle
  counts in the week its final window ends and for the assignees it had when
  it closed; an open red cycle counts as missed while it is open. The rhythm
  continues from `streak_base` (D-091-04). An evaluation that reads several
  weeks or members begins a `RecognitionPass` (`beginPass`) and hands it to
  every read: the active room tasks with their definitions, completion inputs
  and responsible wallets, and the absences, are then read once — the closed-
  week scan of the timeline job, the medal reads and `GET /game/state` do so.
  A pass is a snapshot of one evaluation; a re-evaluation under the house lock
  reads fresh.
- **Lock order (the single house-first order, CS-PLAN-071):** `house →
  room_task (ascending ids) → task definition (ascending ids) → house_user
  (ascending ids) → assignee writes`; completion/undo take `house → room_task
  → room → house_user`. All writers enter through the house lock and
  serialize; no ordering cycle can form.
- **Mapper lock primitives:** `RoomTaskMapper::lockForUpdate` /
  `HouseUserMapper::findForUpdate` / `HouseMapper::findForUpdate` exist as
  the MySQL/PostgreSQL `FOR UPDATE` + SQLite self-assignment pattern;
  `TaskMapper::lockForUpdate` follows the same shape. Any new writer that
  targets rows these aggregates lock must be audited against the order
  above before it ships.
- **Cutover (`Version1036`, data only):** a populated installation gets
  `cycles_from` = the house-local day of the update (D-091-41), the streak
  bases, the anchor of every room task with a live schedule (none where a
  valid completion anchors, D-091-27) and the monthly day of fixed monthly
  definitions. It rewrites no stored fact and only fills empty columns.

### Aggregate Idiom: Work Accounting, Corrections & Replay (CS-PLAN-074 WP3, ADR-014)

The WP3 completion coordinator (`CompletionService`, with
`CompletionDateCommand` for re-dating) is the ONE transaction boundary and
lock-order owner for completing, undoing, attributing and re-dating work;
its collaborators share the caller's connection and never open, commit or
roll back their own transaction. Lock order (single house-first order;
`CommandService` holds the house row): room_task → room → active memberships
→ wallets (stable id) → occurrence/completion rows → dependents. Every
mutation is a `CommandService` command — a client-generated UUID receipt
replayed idempotently before any write, authorization enforced before the
replay.

- **`CommandService`** — the command log: execute-or-replay with a unique
  `(house_id, command_id)` row committed in the same transaction as the
  domain facts; an identical retry returns the stored outcome with zero
  repeated effects, a changed payload under the same UUID conflicts (CR-SVC-64).
- **`CompletionService`** — the three ledger aggregates: completion, undo and
  attribution. Completion freezes the work evidence (`completed_tasks.work_*`,
  `total_energy`, `rate_minor`, the cycle facts, the coin multipliers) and
  writes the selected allocation (`work_shares` per revision,
  `allocation_reviewed` evidence).
  The recorder need not be among the people credited (ADR-021 decision 10);
  every credited member must be allowed to see the task. The completion marks
  every sub-task of its cycle done. Late work of an assigned task that none of
  its assignees is credited for is a takeover (claim fields, digest receipt).
  **Undo** reverses the latest selected allocation revision exactly once —
  wallet union deltas keyed by wallet id, the reopening of the closed cycle,
  the room replay and the recognition recompute. **Attribution**
  (`PUT /completions/{id}/attribution`) rewrites the selected allocation for
  owner authority with the explicit deviation confirmation; the
  union is built from the **share rows by wallet id**, so a departed
  participant's retained wallet is always part of the reversal and a
  completion pays out exactly the frozen total. The frozen work facts, the
  room journal and the cycle facts stay immutable under attribution;
  the audit chain (`completion_revs`) is append-only and a departed-not-erased
  actor renders as a localized placeholder. A bonus task pays each person at
  most once per house-local day, on completion and on the owner's change of
  people (`CreditPolicy`, D-091-68).
- **`CompletionDateCommand`** — the **date correction**
  (`PUT /completions/{id}/date`), the one audited change of a work fact:
  `CompletionDateCorrection` owns the day rule (not in the future, strictly
  between the days of the neighbouring valid entries, so the latest valid
  completion stays the one with the highest id, D-091-26), sets the corrected
  work facts — the work instant keeps its clock time on the new day — and
  moves the frozen window of the entry that followed (`moveFollower()`); the
  command then moves the completion's room event, replays the room, hands the
  cycle seam to `CycleService::redatedUnderLock` and re-derives the
  recognition. Shares, ledger rows and the frozen multipliers are untouched.
- **The collaborators of the three aggregates and the date command (RF-091-07):**
  `CompletionAccess` resolves a completion and its room task in the house,
  authorizes a correction from live rows and is the one correction skeleton
  (`lockTarget()`: lock the room task, re-read the completion, lock the room,
  authorize, compare the version; the result is a `CorrectionTarget`);
  `CompletionLocks` takes the membership and wallet locks in stable id order;
  `CompletionEvidence` writes the share revision with its exact minor amounts
  and the audit row; `CompletionRecognition::recompute()` is the one tail that
  derives the recognitions again; `CompletionReadModel` answers the routes
  (the read model of one completion, the shared response shape, *recorded for
  others*); `ParticipantAllocation` validates and defaults the people credited.
- **`CreditPolicy`** — who may be credited: only a member who may see the task
  (D-091-20), and on a bonus task each person once per house-local day
  (`409 BONUS_ALREADY_TODAY`); also whom each bonus row already paid today.
- **`CoinMultiplierService`** — the owner's coin factor of a member and the
  bonus periods of the house (receipt-protected owner commands, the role
  checked before the house lock and again under it, D-091-71), and `freeze()`:
  the factor of every member and the percent of the work day that a
  completion stores (ADR-021 decision 11).
- **`CompletionAuthority`** — who may correct an entry (ADR-021 decision 9,
  D-091-23): the owner any entry; every other member the date and the deletion
  of the latest valid entry of a task that they recorded; the people are the
  owner's alone. The three correction commands and every read model that
  offers the actions (`canCorrect`, `canAttribute`) decide through it.
- **`WalletService`** — the money seam: one wallet per member (binding under
  lock; departed members retain wallet + balance), immutable `wallet_entries`
  ledger postings with the `(wallet, source-kind, source-id, source-revision)`
  idempotency identity, cached `booked/reserved/available` balance maintained
  transactionally (never re-summed at read), safe-integer accumulation
  enforced, per-house funding-hold watermark maintenance.
- **`RoomReplayService`** — the room journal truth: `baseline` / requested
  `delta` / `manual_delta` / absolute `reset` / `last_done` (stamp only, newest
  per room task recorded after the latest reset, FEAT-UX-045) events fold in
  `(effective_at, id)` order with
  clamps; undo invalidates exactly its own completion effect
  (the correction execution time never becomes `last_cleaned`); a later reset
  stays authoritative over earlier corrections; the room cache columns are
  derived projections (`resyncRoomCache`), never the source of truth.
- **`RhythmService` + `WeeklyGoalService`** — the recognition projections
  over `RecognitionFactsService`: streaks recompute from the streak base and
  the per-day outcomes of the cycles since the cutover (kept / still open /
  missed; a correction shrinks the longest record only to the corrected
  maximum), the weekly goal evaluates the facts of one house-local week. No
  cached increment/decrement corrections anywhere.
- **`EffortAllocationService` / `TaskAccessPolicy`** — exact minor-unit share
  math (`E * P` energy minor, `10 * E * P * factor * bonusPercent / 100` coin
  minor at the frozen rate, rounded down once, D-091-15) and the viewer-scoped
  authorization matrix reused by every aggregate.
- **`ActivityService`** — the batched history/read-model cluster (frozen
  facts, selected allocation, who recorded it, the viewer-scoped `canCorrect`
  and `canAttribute`), for the household feed and for the history of one task.
- **`ActivityEventService`** — what the feed shows besides completions:
  postponements, date changes, deletions and absences. Nothing is stored for
  it: each kind is read from the fact its command already writes — the
  command receipt, the completion's audit chain, the pause revisions.
- **`RoomTaskReadService`** — the batched instance-read/enrichment cluster:
  house-/room-/scope-wide fetches, the visibility matrix (batched task +
  assignee reads), `{roomTask, task, room}` row assembly (the single owner of
  the daily enrichment), the batched `cycle` blocks and the status derived
  from them, filtered-daily ("My Day"), due-today/overdue rows, the bonus rows
  of *Today* with `creditedToday` and the house timezone resolution
  (CS-PLAN-066 D1 one-owner rule). A list read answers a `RoomTaskList`, the
  room tasks with the definitions its visibility decision read.
- **`CompletionPostCommitService`** — the guarded post-commit boundary:
  weekly-goal read and notification dispatches; failures are logged and
  failure-isolated, never failing a committed aggregate. A completion runs
  `runForCompletion()`, an undo and a date correction `runForCorrection()`.

The legacy `CompletionEffectService` snapshot restore and the
`GameLogicService` reward orchestration (coins/combo/team-spark/boosts) are
retired with the legacy economy (ADR-014); `GameLogicService` keeps the
viewer-safe game-state serialization and `recognitionsFor()`, the one
recognition pass of `GET /game/state`.

Frontend counterpart (WP3 §3.7, CS-PLAN-091 WP-B2/WP-B3): the activity feed
exposes the authorized older-completion correction; `ParticipationDialog` is
reused for attribution correction (recorded-split pre-fill, correter need not
participate, a 409 keeps the draft) and, inside `CompletionPicker`, for the
short tap on Done (who did it, which sub-tasks are done); `TaskHistory` lists
one task's entries with the date change and the deletion the server grants and
reads them again after a completion or an undo of that task; `UndoSnackbar`
remains the completion-state restore surface from TodayView;
`src/utils/minorUnits.js` renders exact minor units (no Coin column anywhere).

### Aggregate Idiom: Absences (CS-PLAN-073 WP2, ADR-020, ADR-021 §5)

The absence commands keep the single house-first lock order and the
CommandService receipt convention; every command authorizes BEFORE the receipt
replay and commits domain facts, versions and the receipt in ONE transaction.

- **`PauseService`** — revisioned pause groups (personal and house scope):
  enter, approve/reject a request from before ADR-020, shorten, delete and
  retrospective illness. An absence writes nothing on the work: no window
  moves, no cycle version changes, and the work stays possible. The viewer
  list is an explicit field whitelist — personal reason and illness marker
  are self/owner data in every state (RF-073-WP2-28). Departure cancels
  pending personal requests (Q-073-03).
- **`PauseProjectionService`** — the projection math: only recognized
  (acknowledged) revisions have effects, resolved with PER-GROUP PRECEDENCE —
  the latest acknowledged revision governs every date from its next-local-day
  decision boundary, so a shortening or deletion frees the following days
  while today's recognized excuse stays (RF-073-WP2-23). For derived windows
  `walletsAbsentThroughout` decides **on read**: a window that lies entirely
  inside the absence of a responsible member is neutral for that member —
  neither kept nor missed (D-091-03) — also when the absence was entered
  after the window ended. It is asked for the members the window counted
  for, so a closed cycle keeps its neutrality after a departure (D-091-33).
  `excusedWalletsOn` keeps the timeliness rule for the digest's day view.
- **Capacity** refuses nothing (ADR-022): the day limit of a child only trims
  the unassigned work it is offered. The scheduling view shows the planned
  energy of the current cycles against the budgets until WP-C replaces it
  with the live suggestion (D-091-29).

### Aggregate Idiom: Reward Catalog & Request Lifecycle (CS-PLAN-070 WP7, ADR-017)

The WP7 surfaces follow the same receipt + house-first-lock convention with
one WP7-specific seam and three money moments:

- **`CommandService::runCommand()` under-lock authorization guard (WP7 seam):**
  commands accept an optional final guard callback, invoked after the house
  lock and BEFORE the receipt lookup on BOTH execution and replay. It checks
  fresh actor identity, visibility and CURRENT action authority - never
  business version/funding/state preconditions, which the committed receipt
  already answers. All WP7 commands supply it, so a demoted owner or
  departed requester can never replay an old receipt into an effect.
- **`RewardService`** - catalog reads/writes: owner-only item CRUD with
  whitelisted fields, strict integer `priceMinor`, control-character
  rejection, item-version optimistic concurrency, the narrow household-gate
  command (`PUT /house/catalog`, house `stateVersion` contract) and the
  scope matrix (`active` gated empty state vs owner `admin` scope).
- **`RewardRequestService`** - the C4 lifecycle: reserve at creation
  (`A >= P`, `R += P`), settle only at separately confirmed delivery
  (`B -= P` and `R -= P` together, `reward_settlement` ledger keyed by
  request id + effect revision), refund only an owner-attested false
  fulfillment against the server's stored settlement entry (exact original
  debit, once, `reward_refund`). Request terms freeze at creation; the
  funding hold is derived (`booked < reserved`), never stored. The current-
  role authority matrix is re-checked in the guard AND the aggregate; role
  rebases (`HouseService::updateUserRole` hook) move unfulfilled requests
  to pending when the requester becomes a child, departure releases holds
  before access ends, and erasure purges requests/events/wishes plus every
  recipient's queued digest reference. Lock order: house -> actor membership
  -> wallet (locked before the request row) -> request/event rows ->
  preference/digest rows.
- **WalletService WP7 primitives** - `reserveReward` / `releaseReward` /
  `settleReward` / `refundReward` under the caller's house-first
  transaction; `postEntries` now returns the written entry ids so the
  request rows can store their settlement/refund references.

### Size Guideline Exception Inventory (CS-PLAN-067 D6, 2026-09-13)

The production size guideline is **~300 lines per file / ~30 lines per
method** (project-rules §1, KISS). It is applied through cohesive extraction
plus this **named exception inventory** — never a repository-wide
line-count rewrite, and never a blanket "pre-existing" exemption. Every
production file in `lib/` and `src/` above the guideline is listed here
with its cohesive responsibility and disposition. Counts are
**non-blank lines** (code, comments, docblocks, templates and scoped CSS
excluding empty lines; blank lines are formatting, not size); measured
2026-09-13 at `master` post-WP8, re-measured 2026-09-14 after the
CS-PLAN-058 final-review fixes, 2026-09-22 after the CS-PLAN-072 WP1
occurrence-timeline package incl. the RF-072-WP1-18 collaborator split and
2026-09-24 after the CS-PLAN-073 WP2 agreements/capacity/pauses package
incl. its RF-073-WP2 fix round (RF-073-WP2-17) and 2026-09-25 after the
CS-PLAN-073 RF-23..28 fix round (the four WP2 evaluator/command files),
and re-measured 2026-09-27 after the CS-PLAN-074 WP3 work-accounting and
replay package (RF-074-WP3-01 re-measurement: the WP3 retirement of the
legacy economy dropped `GameLogicService` 349→54, `MinigameService`,
`NotificationService`, `MinigameController`, `Game2048`, `MemoryGame`,
`BoostShop` (retired), `ArcadeSection`, `minigame.js` and `user.js` below
the guideline, while the WP3 completion coordinator grew to the named
aggregate below), with a final re-measurement the same day after the WP3
fix/follow-up rounds (`CompletionService` 1478→1435, `HouseService`
477→489, `TestDataService` 577→649, `RoomTask` 357→362,
`CompletedTaskMapper` 525→567, `Dashboard.vue` 327→351, `InsightsView.vue`
342→531, `roomTasks.js` 1038→1064, plus the two newly crossing WP3 files
`MedalService` and `RoomService` added below), and re-measured 2026-09-27
after the CS-PLAN-070 WP4 rhythm/goals/ranking package: the retirement of
the reward-config registry keys dropped `RewardConfigService` 328→164 below
the guideline, the retired `TitlePicker.vue` left the inventory, the new
`RankingService` entered it, `HouseService` 489→512 (house ranking flag +
consent wipe on the existing update path) and `Settings.vue` 928→906
(TitlePicker surface removed). WP5's changed files were re-measured on
2026-09-30 during Standard B: the shared preference/queue hooks grew the
completion/house/agreement/pause aggregates, consent and award retirement
shrunk ranking/medal code, and the minimal personal Settings section grew
the composition root. `DigestService` is the additional named exception:
one visible persist-before-notify choreography with bounded relevance reads.
WP8 already reduced its named hotspots
(`TaskList.vue` ~1,160 → 357, `GameLogicService` 517 → 349,
`RoomTaskService` 833 → 643, `CompletionService` 772 → 546 via extracted
collaborators). Re-measured in full on 2026-11-15 on the `0.8.0-alpha.5`
candidate (CS-PLAN-090 final Full review): every row in non-blank lines, also
the rows CS-PLAN-090 had written in total lines; `CalendarView.vue` fell below
the guideline, and the files that had crossed it without a row got one.
Re-measured on 2026-11-18 after CS-PLAN-091 WP-B1 (derived windows): the
occurrence timeline, the agreement commands, the plan aggregate and the
capacity evaluator left with ten service files and the planner component;
`PauseProjectionService`, `TaskListDetails.vue` and `TodayTaskRow.vue` fell
below the guideline; `CycleService`, `RecognitionFactsService` and
`RecurrenceCalculator` crossed it. The interim Full review of the same day
re-measured the files its fixes touched; `CycleService` grew with the
missed-cycle recording and the anchor policy of rule edits and is a
remainder now (its job pass is the extraction candidate, RF-091-05).
Re-measured on 2026-11-18 after CS-PLAN-091 WP-B2 and WP-B3 with RF-091-03 to
RF-091-05: the job pass left `CycleService` for `CycleAdvanceService`
(RF-091-05), the postponement and the date seam joined it; `CompletionService`
grew with the date-correction aggregate while its day rule, the correction
rights and the responsibility resolution went into collaborators; the
`TodayView`, `TaskManager`, `ParticipationDialog` and the stores shrank with
the picker and the shared command id; `ActivityFeed.vue` and `TodayTaskRow.vue`
crossed the guideline. The interim Full review of WP-B2 and WP-B3 (2026-11-18)
re-measured the files its fixes touched: `CompletionService` 1528 → 1546 (the
rights levels of a correction and the replay guard of the date correction),
`CycleService` 412 → 416, `ActivityFeed.vue` 377 → 393 (an absence names its
person in whole sentences).
Re-measured on 2026-11-18 by the interim Full review of CS-PLAN-091 WP-B4 and
WP-B5 with RF-091-07 and RF-091-08: `CompletionService` 1546 → 676 (the date
correction, the correction skeleton, the locks, the evidence, the read model
and the split left for collaborators), `CycleService` 416 → 511 (sleeping,
waking and the bonus seam), the stores grew with the bonus rows, the bonus days
and the coin factor; `CompletionController`, `WindowService` and
`ActivityService` crossed the guideline and got rows.
Re-measured in full on 2026-11-19 after CS-PLAN-091 WP-B6 with RF-091-09 and
RF-091-10: the one form and the one row replaced `TaskEditForm.vue`,
`TaskListItem.vue`, `TodayTaskRow.vue`, the details view and the bulk bar;
`CycleService` 511 → 316 (projection, misses and sleep left for
collaborators), `RoomTaskController` 915 → 639 and `RoomTaskService` 716 → 451
(the assignee, bulk and template routes went), `TaskList.vue`,
`TaskChecklist.vue`, `TaskCreationService`, `ChecklistService`,
`ChecklistController`, `CompletionController` and `ActivityService` fell below
the guideline; `TaskForm.vue` and `TaskMapper` crossed it and got rows.
The operator's GUI review of WP-B6 (2026-11-21) re-measured what it touched:
`TaskFilterBar.vue` 347 → 321 (person, floor and room left its panel for the
people filter and the room picker). The interim Full review of the same day
re-measured the files its fixes touched: `TaskService` 525 → 556 (the one value
normalizer of create and edit, the length limits, the owner rule of a private
task), `RoomTaskMapper` 508 → 544 (the set lock of a shared definition),
`RoomTaskReadService` 567 → 577 (the one cycle-block read), `HouseSetupService`
499 → 513 with its seeding extracted from `applySetup`, `RoomTaskService`
451 → 424 and `RoomTaskController` 639 → 630 (dead code), `TaskForm.vue`
310 → 315.

Dispositions: **accepted** (cohesive unit or static/data surface — splitting
would damage cohesion), **named** (exception ratified in CS-PLAN-067 §3.5),
**remainder** (open decomposition candidate, owning backlog item cited).

#### Backend (`lib/`)

| File | Lines | Cohesive responsibility | Disposition |
|------|-------|--------------------------|-------------|
| `Controller/RoomTaskController.php` | 630 | Room-task route surface (selector modes, creation, lifecycle, stats, range, the bonus rows, the cycle receipt of the completion); thin validate→service→serialize methods only | **named** (CS-PLAN-067 §3.5: assessed per-method, independently of service orchestration) |
| `Controller/SchedulingController.php` | 301 | Scheduling route surface (own preferences, member availability); thin methods | accepted (endpoint breadth, no logic depth) |
| `Controller/UserController.php` | 367 | Member/profile route surface; thin methods (persistence delegated to HouseService, RF-058-04) | accepted (endpoint breadth) |
| `Controller/HouseController.php` | 425 | House route surface (read, settings, the catalog and arcade gates, stats, activity, health, reset); every stored house payload adds the app-config arcade flag, the task categories and the bonus period; thin methods | accepted (endpoint breadth) |
| `Controller/RoomController.php` | 338 | Room route surface incl. the WP3 journal/manual-delta/reset seam; thin methods | accepted (endpoint breadth) |
| `Service/CompletionService.php` | 647 | The three WP3 ledger aggregates — completion, undo, attribution — with the WP5 transactional takeover queue and the frozen coin multipliers; the date correction, the correction skeleton, the locks, the evidence, the recognition tail, the read model and the split are collaborators (RF-091-07) | **named** (CS-PLAN-067 §3.5 / ADR-014: one visible ordered aggregate per command; further splitting would hide the lock/order contract) |
| `Service/RoomTaskService.php` | 424 | Room-task ownership checks, deletion, room stats, calendar-range projection of the cycles | **remainder** (CR-SVC-15: range/stats projection extraction candidate) |
| `Service/RoomTaskReadService.php` | 577 | Batched read/enrichment cluster (house/room/scope/daily/due/bonus + timezone); WP8 product; batched cycle blocks and the status derived from them | accepted (read-surface breadth, one owner per D1) |
| `Service/HouseSetupService.php` | 513 | WP3 first-run setup transaction aggregate (first-run gate, structure, a definition per room and template, invites; first windows of the seeded tasks, ADR-021) | accepted (transaction aggregate) |
| `Service/TaskService.php` | 556 | The definition's validation set and its one update path: prepared, applied under lock, the changed fields, and the revision of every mounted room task (a rule, flex or energy edit moves its current cycle) | accepted (one validation authority; the former `createTask` candidate of CR-SVC-15 left with CS-PLAN-063) |
| `Service/HouseService.php` | 679 | House aggregate: membership, stats, timezone revision passes, ranking flag and consent-only reset, membership-keyed preference/digest cleanup | accepted (cohesive aggregate) |
| `Service/SchedulingService.php` | 320 | Scheduling preferences/availability domain surface | accepted (cohesive domain) |
| `Service/TemplateService.php` | 314 | Task catalog templates + level presets | accepted (catalog surface) |
| `Service/RewardConfigService.php` | — | Static reward configuration registry — the WP4 retirement of the weekly-goal/threshold override keys dropped it BELOW the guideline (164 non-blank lines); entry retired | removed from the inventory (2026-09-27) |
| `Service/CycleService.php` | 316 | Writers of the derived windows: completion target check, closing and reopening a cycle, postponement, the bonus seam, the cycle seam of a date correction, first date and the anchor policy of rule edits (ADR-021); projection, misses and sleep are `CycleProjection`, `CycleMisses` and `CycleSleep` (RF-091-10) | accepted (the writer of cycle rows; every method runs under the caller's lock order) |
| `Service/WindowService.php` | 336 | The single recurrence authority: the current cycle per mode, flex and postponement, the missed fixed cycles, the calendar range, the row's `cycle` block, the postponability rule and the wake anchor (ADR-021) | accepted (pure derivation; one read path for every consumer) |
| `Service/RecognitionFactsService.php` | 307 | The one reader of what the recognitions count: stored occurrences before the cutover, closed and open cycles after it, absences neutral on read (ADR-021 §5, §12) | accepted (cohesive read model; splitting the two sources would hide the no-double-count rule) |
| `Service/RecurrenceCalculator.php` | 317 | Pure calendar math: interval steps, fixed weekdays and month days with clamping, day-key helpers; the enumeration of stored revisions goes with CS-PLAN-091 WP-B7 | accepted (pure functions, data-driven) |
| `Service/MedalService.php` | 335 | Retained rhythm/weekly recognition, correction reconciliation and in-app weekly receipt; WP5 removes external award bells | accepted (cohesive recognition domain, one award writer) |
| `Service/RoomService.php` | 366 | Room domain: structure mutations, cleanliness manual-delta command and reset through the WP3 room journal, derived cache updates | accepted (cohesive room domain + the journal writer seam) |
| `Service/RankingService.php` | 378 | Consent-filtered exact-ratio ranking and next-Monday generations; WP5 preserves delivery settings through every in-place consent transition | accepted (cohesive ranking authority, ADR-015) |
| `Service/TestDataService.php` | 678 | Test-only seeding/reset tooling, including the WP5 digest table inventory | accepted (test tooling, explicitly classified) |
| `Service/DigestService.php` | 345 | WP5 five-minute pass, current-source/visibility relevance, house-first claim, commit-before-notify and outcome bookkeeping | accepted (one visible at-most-once delivery choreography; scheduling and persistence remain in their existing owners) |
| `Service/RewardRequestService.php` | 974 | WP7 C4 request lifecycle: one ordered lock/authorization/receipt/effect/queue sequence per command, the role/departure/erasure hooks and the viewer-safe serialization |
| `Service/RewardService.php` | 409 | WP7 catalog owner: item CRUD with strict terms, the household-gate command and the scope matrix |
| `Service/PauseService.php` | 758 | Revisioned pause lifecycle with the ADR-020 deletion, viewer projection, departure and WP5 transactional decision queue writes | accepted (one ordered lock/revision/queue sequence per command) |
| `Db/RoomTask.php` | 413 | Central entity: domain accessors, the stored inputs of the derived window, JSON serialization (legacy recurrence engine removed by CS-PLAN-072 WP1; its ideal-date helpers go with CS-PLAN-091 WP-B7) | accepted (entity surface; data-driven) |
| `Db/RoomTaskMapper.php` | 544 | SQL primitives for the central instance table (house/room/scope/daily/locks) | accepted (SQL primitive collection) |
| `Db/CompletedTaskMapper.php` | 716 | Ledger SQL: stats aggregates, occurrence lookups of old rows, cycle anchors, the neighbours and the history of one room task, weekly windows, completion revisions, the erasure of a wallet from the frozen coin factors | accepted (SQL primitive collection) |
| `Db/OccurrenceMapper.php` | 333 | SQL primitives for the stored occurrences (lookups by key, task, revision and house; half-open day bounds, RF-090-57); its writer primitives go with CS-PLAN-091 WP-B7 | accepted (SQL primitive collection) |
| `Db/TaskMapper.php` | 306 | SQL primitives for the definitions: visibility-scoped reads, the named-argument insert and the copy a split takes (CS-PLAN-091 WP-B6) | accepted (SQL primitive collection) |
| `Service/FloorService.php` | 330 | Floor domain: structure mutations and their ordering | accepted (cohesive domain) |

#### Frontend (`src/`)

| File | Lines | Cohesive responsibility | Disposition |
|------|-------|--------------------------|-------------|
| `components/Settings/StructureSettings.vue` | 574 | Floors and rooms, the single surface that edits the house structure, with its dialogs; one category of Settings since the split per category (CS-PLAN-090 WP-D; `Settings.vue` itself has 296 lines with the categories of WP-I to WP-K, was 1,238) | accepted (one cohesive editing surface; template and CSS dominant) |
| `components/Insights/RankingPanel.vue` | 540 | The consent-filtered ranking with its medals as a section of Insights (the Leaderboard page it was, CS-PLAN-090 WP-E), with a loading and an error state of its own | accepted (presentational; CSS dominant) |
| `components/Setup/SetupWizard.vue` | 872 | WP3 setup journey step machine | accepted (cohesive step machine; step extraction only with a reviewed UI contract) |
| `views/TodayView.vue` | 567 | The landing page (CS-PLAN-090 WP-B): composes status line, the bonus-days banner, overdue block, due and possible rows and the bonus section (CS-PLAN-091 WP-B4/WP-B5; its length is the GUI review's of WP-B6, D-091-74); the rows, the status line, the section rules (`utils/todaySections.js`) and the completion flow (`useTaskCompletion`) are extracted units (was 1,084) | accepted (composition root; template and CSS dominant) |
| `components/Tasks/TaskForm.vue` | 315 | The one form for a new and an existing task (CS-PLAN-091 WP-B6): the fields' composition, the save with the scope question, the delete with its confirmation; the state is `useTaskFormState`, the model `utils/taskFormModel.js`, the field groups are components | accepted (one form contract; template dominant) |
| `components/Insights/ActivityFeed.vue` | 383 | The household's completions by day with the events between them (postponements, date changes, deletions, absences) and the correction actions the server grants | accepted (one list surface; template and CSS dominant) |
| `components/Common/ParticipationDialog.vue` | 325 | The actual-work split form: the picker of a short tap (the household, who a hold would credit ticked, equal shares, the people a bonus task already paid today disabled) and the attribution-correction mode, validation surface | accepted (one form contract across both flows) |
| `components/Scheduling/AvailabilityEditModal.vue` | 474 | Owner availability grid editor | accepted (grid editor surface) |
| `components/Scheduling/ScheduleCalendar.vue` | 399 | Week grid + capacity/overcapacity display | accepted (presentational grid) |
| `components/Members/MemberList.vue` | 416 | Member management surface | accepted (template+CSS dominant) |
| `components/Profile/TitlePicker.vue` | — | Title pack/tier picker — retired with the CS-PLAN-070 WP4 single-series cutover | removed from the inventory (2026-09-27; archived under the gitignored `archive/`) |
| `components/Minigames/SnakeGame.vue`, `SnakeLeaderboard.vue` | — | Single-game Snake and its board — replaced by the shared game shell (CS-PLAN-086 WP-A) | removed from the inventory (2026-10-17; archived under the gitignored `archive/`) |
| `components/Minigames/GameShell.vue` | 579 | Shared frame of every arcade game: loop, shell-bound input (directions, taps, a paddle's held keys and pointer), responsive canvas or a game's DOM board, overlays, live region with a game's own end copy, result save, the event effects (sounds through `useGameEffects`, the board's `effectsLayer`, the overlay's Sound/Effects switches; WP-G); the score header lives in `GameShellHeader.vue` | accepted (one frame for all games; ~45 lines scoped CSS) |
| `views/TaskManager.vue` | 494 | The one task list (CS-PLAN-090 WP-C): composes filter bar, stats bar, grouping switch, the list by due date or the grouped list, and the one form for a new task; the grouping and filter rules (`utils/taskGrouping.js`, `utils/taskFilters.js`), the grouped list and the pager are extracted units | accepted (composition root) |
| `views/CalendarView.vue` | — | Calendar composition; its header controls moved to `Tasks/CalendarControls.vue` (CS-PLAN-090 WP-D), which took it BELOW the guideline (244 non-blank lines); entry retired | removed from the inventory (2026-11-15) |
| `views/AbsenceView.vue` | 352 | The Absence page (CS-PLAN-090 WP-A): every absence with its delete action; the range form and the owner panel are `Absence/AbsenceRangeForm.vue` and `Absence/AbsenceOwnerPanel.vue` | accepted (composition root; template and CSS dominant) |
| `views/Rewards.vue` | 510 | The reward catalog (CS-PLAN-070 WP8): both gates, the list and the request dialog with its frozen terms; the wallet banner is `Rewards/RewardWalletBanner.vue` (CS-PLAN-090) | accepted (one page contract; template and CSS dominant) |
| `components/Rewards/RewardCatalogEditor.vue` | 624 | Owner catalog editor in Settings (CS-PLAN-070 WP8): strict terms, archive, 409 draft retention | accepted (one form contract) |
| `components/Rewards/RewardRequestList.vue` | 499 | The own requests and, for owners, the decision queue (CS-PLAN-070 WP8) | accepted (one list surface; template and CSS dominant) |
| `components/Rewards/RewardRequestDetails.vue` | 691 | One request with its lifecycle actions and corrections (CS-PLAN-070 WP8) | accepted (one detail surface; template and CSS dominant) |
| `components/Tasks/TaskFilterBar.vue` | 321 | The filters of the task list: scope buttons with the people filter, the floor/room quick filter and the collapsible panel of detail filters (CS-PLAN-084 WP-D, CS-PLAN-091 WP-B6) | accepted (one form surface) |
| `components/Settings/NotificationPreferences.vue` | 323 | The personal notification and feedback settings (digests and their time, quiet hours, sound, animation, catalog and arcade visibility) | accepted (one form contract) |
| `services/api.js` | 312 | The shared API client: one URL prefix, envelope unwrap, ApiError normalization and the named endpoint helpers | accepted (one client surface) |
| `store/rewards.js` | 572 | Catalog and request state with the WP8 command receipts | accepted (cohesive store) |
| `store/user.js` | 359 | The current user, the house members with their coin factor and the own wallet | accepted (cohesive store) |
| `views/InsightsView.vue` | 496 | Insights composition root (CS-PLAN-090 WP-E: key figures, fairness bars, the ranking section and the activity list; the correction review is `Insights/CorrectionReview.vue`) + the WP3 §3.6 history-correction session (feed action, reused dialog, 409 draft retention) | accepted (composition root) |
| `store/roomTasks.js` | 1107 | Canonical instance store: projections (the bonus rows included), generations, reconciliation (CS-PLAN-066 D3 one-owner rule); WP1 receipt contract + pending-command cache; WP3 completion correction actions | accepted (D3: splitting would duplicate cache/reconciliation ownership) |
| `store/scheduling.js` | 378 | Scheduling state: preferences, capacity/overcapacity getters | accepted (cohesive store) |
| `store/house.js` | 449 | House state: the house payload with its gates and bonus period, insights, the activity feed, the reset and bonus-days receipts | accepted (cohesive store) |
| `store/rooms.js` | 340 | Floor/room state (WP3 journal receipt flows) | accepted (cohesive store) |
| `utils/icons.js` | 580 | Static icon registry | accepted (static data table — not split to meet a physical target) |

Maintenance rule: a file newly crossing the guideline must either be
extracted along a cohesive seam in the change that grows it, or receive an
individual entry here in the same change. The **remainder** row of
`RoomTaskService` is owned by CR-SVC-15; no other open decomposition debt is
implied by this inventory.

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

The authoritative gate is `.github/workflows/release-gate.yml`. It is run
**manually** from the Actions tab (CS-PLAN-085 D-085-04) and fails the run on
any non-zero **blocking** command below. The Docker-free subset runs
automatically on every push in `.github/workflows/checks.yml`, and the binding
gate before a release is the local Docker run:

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
