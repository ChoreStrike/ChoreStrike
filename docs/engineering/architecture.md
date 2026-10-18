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
│   │                           #   Minigame, ArcadeCatalog, ArcadeAccess
│   │                           #   (derived chore unlocks, ADR-019),
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
│   │   ├── Dashboard/          # HouseHealthBar, HouseholdGoalBar, FloorList, RoomCard,
│   │   │                       #   QuickStats
│   │   ├── Tasks/              # TaskList, TaskListItem, TaskListDetails, TaskListBulkBar,
│   │   │                       #   TaskEditForm, AssigneeChips, TaskChecklist, RoomPicker,
│   │   │                       #   TaskTemplatePicker, FlexWindowDisplay, OverdueClaimDialog
│   │   ├── Minigames/          # ArcadeCatalog, GameShell (+Overlay, Dpad), GameLeaderboard,
│   │   │                       #   ArcadeGateSetting (owner switch), gameRegistry,
│   │   │                       #   games/<id>/ (pure logic + a canvas render.js or a DOM
│   │   │                       #   Board.vue, one lazy chunk per game; CS-PLAN-086)
│   │   ├── Common/             # Icon, SvgIcon, Emoji, Modal, MedalBadge
│   │   ├── Profile/            # TitleBadge (single retained series; the
│   │   │                       #   TitlePicker retired with CS-PLAN-070 WP4)
│   │   ├── Rewards/            # RewardCatalogEditor (owner Settings section),
│   │   │                       #   RewardRequestList, RewardRequestDetails
│   │   │                       #   (CS-PLAN-070 WP8)
│   │   └── Scheduling/         # SelfAvailabilitySettings, AvailabilitySettings, AvailabilityEditModal,
│   │                           #   EnergyBudgetSettings, BudgetAnalysis, WeekBudgetGrid,
│   │                           #   SchedulePlanner, ScheduleCalendar, OvercapacityWarning
│   ├── composables/            # useCelebration (confetti + SFX), useToasts,
│   │                           #   useStoreReset (central identity reset, WP6/WP8)
│   ├── constants/              # Shared constants (taskVisibility, titles, scheduling)
│   ├── services/               # Shared API client (api.js — single URL prefix,
│   │                           #   envelope unwrap, ApiError normalization)
│   ├── store/                  # Pinia stores (house, rooms, roomTasks, tasks, game,
│   │                           #   user, scheduling, checklist, minigame, titles,
│   │                           #   ranking, setup, preferences, pauses, rewards)
│   │                           #   + userAutoInit bootstrap plugin
│   ├── styles/                 # tokens.css (theme/motion CSS vars), mobile.css
│   ├── views/                  # Dashboard, Today, Insights, RoomDetail, TaskManager,
│   │                           #   Leaderboard, Scheduling, Calendar, Settings, Rewards
│   └── utils/                  # currentUser, dateKeys, icons, roomIcons, logger,
│                               #   confetti, sound, energyBudgets
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
│   │                           #   here by GitHub/Forgejo, CS-PLAN-069)
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
2. **Component dispatches store action** → `useRoomTasksStore.completeRoomTask(roomTaskId)`; the store resolves the shown occurrence receipt (`commandId` UUID + the cached row's `occurrence` block) and posts it (CS-PLAN-072 WP1)
3. **API request** → `POST /apps/chorestrike/api/v1/room-tasks/{id}/complete` with the closed-set receipt body
4. **Controller** → `RoomTaskController::complete()` (validates the receipt closed set)
5. **Service logic** → `CompletionService::complete()` (WP3 transaction aggregate, ADR-014) authorizes, executes-or-replays the `commandId` via `CommandService`, then under the documented lock order (house → room_task → room → memberships → wallets → completion rows): resolves the target via `OccurrenceService::resolveForCompletionUnderLock` (version + canonical-selection + `selectionToken` check), freezes the work evidence (`completed_tasks.work_*`, `total_energy`, `rate_minor`), writes the selected allocation (`work_shares` + `allocation_reviewed`), posts the exact wallet-union deltas to `wallet_entries` (`WalletService::postEntries`), appends the requested `room_events` delta, applies the timeline effects (`applyCompletionUnderLock`) and recomputes the personal recognition of every participant (`RhythmService` / `WeeklyGoalService`). `OccurrenceService` (ADR-013) is the single recurrence authority; `next_due`/`ideal_due_date`/`last_completed` are derived projections. Internally the authority is a facade over cohesive collaborators (RF-072-WP1-18): `OccurrenceRowFactory` (row drafts/windows), `OccurrenceReconcileService` (frontier/materialization/job), `OccurrenceSelectionService` (write-free selection derivation/DTO block), `OccurrenceRevisionService` (revision lifecycle), `OccurrenceCompletionService` (completion/undo seam) and `OccurrenceAgreementService` (C2 amendments/capacity).
   - Exact minor-unit math throughout (`E * P` energy minor, `10 * E * P` coin minor at the frozen rate) — no coin integers, no combo/team-spark/boost dimensions (legacy economy retired, ADR-014)
   - The room cleanliness/monster change is the **saved requested effect**; the replayed cache clamps (`RoomReplayService`)
   - Medals are NOT awarded here — they stay read-triggered via `MedalService::checkAndAwardMedals`
6. **Database update** → Multiple mappers (atomic within the transaction)
7. **Response** → Updated task, the fresh selection `occurrence` block, `completionId`/`replay`, the frozen completion evidence + selected allocation, the caller's own wallet totals in minor units, the weekly-goal projection
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
- `contribution_minor` (bigint) — lifetime work in exact minor units (100 per energy); leaderboard/fairness/title signal
- `current_streak` (int), `longest_streak` (int) — derived records recomputed from the valid planned-day history (WP3 `RhythmService`, ADR-014)
- `scheduling_preferences` (json) — weekday availability etc.
- `selected_title_pack` (string, nullable), `unlocked_titles` (json) — role-title state (CS-PLAN-006)
- `wallet_id` (bigint, nullable) — the member's wallet binding (WP0/WP3)

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
- `schedule_rev_id`, `occurrence_ordinal` — the attributed occurrence identity (ADR-013)
- `work_date` (date), `work_zone` (string) — house-local date + zone
- `occurrence_due` (date) — the occurrence's planned date
- `total_energy` (int, frozen at completion), `rate_minor` (int — coin minor per energy, frozen)
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
| `chorestrike_chk_progress` | Per-occurrence checklist progress keyed by `(schedule_rev_id, ordinal, item_id)` (WP1) | `RoomTaskChecklistProgress` |
| `chorestrike_task_assignees` | Canonical multi-assignee join table (room-task ↔ Nextcloud user) (CS-PLAN-002; sole assignee store since CS-PLAN-060 WP1 D2) | `TaskAssignee` |
| `chorestrike_commands` | CommandService command log (house, actor, UUID, action, payload hash, result refs) — the execute-or-replay/conflict authority (WP3 generalized the receipt to every mutation) | `CommandReceipt` |
| `chorestrike_wallets` | Persistent per-house work wallets (WP0/WP3: one per member, binding under lock; survive departure, never re-bind erased facts; cached booked/reserved/available + funding-hold watermark) | `Wallet` |
| `chorestrike_wallet_entries` | Immutable value ledger (wallet, source kind/id/revision, signed `delta_minor`, reason, actor; unique key = idempotency identity) | `WalletEntry` |
| `chorestrike_work_shares` | Per-completion allocation revisions (wallet, share pct, energy/coin minor, actor, allocation_reviewed) — every selected revision auditable | `WorkShare` |
| `chorestrike_completion_revs` | Append-only completion audit chain (revision counters, actor house-user id, action complete/attribution/undo, bounded reason_code) | `CompletionRevision` |
| `chorestrike_room_events` | Journaled room truth (baseline/requested-delta/manual-delta/absolute-reset, command UUID, `(effective_at, id)` order, saved baselines; the room cache is a replayed projection) | `RoomEvent` |
| `chorestrike_schedule_revs` | Immutable schedule revisions (cadence/window defaults, agreed frontier) — WP1 timeline authority | `ScheduleRevision` |
| `chorestrike_occurrences` | Durable occurrence rows (public key `rev:ordinal`, saved windows, frozen energy, suspension state/evidence, restart links) | `Occurrence` |
| `chorestrike_occ_members` | Per-occurrence participant facts (role, obligation, exact share, agreed work appointment, active/released/excused disposition) | `OccurrenceMember` |
| `chorestrike_agreements` | Agreement evidence log (kind, actor, old/new state, consent, pause_ref) | `Agreement` |
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
    houseHealth(),        // derived: state.house?.houseHealth ?? 100
    houseName(),
    hasHouse(),
    timezone()            // house timezone with 'UTC' fallback
  },
  actions: {
    fetchHouse(),
    updateHouseName(name, timezone),  // PUT /house (owner-gated)
    fetchInsights(),
    fetchActivity(days, limit),
    calculateHouseHealth(),           // GET /api/v1/house/health, persists house.houseHealth
    resetHouse(),
    clearError(),
    reset()
  }
}
```

> There is **no** standalone `health` state field or `updateHealth()` action —
> house health is derived from `state.house.houseHealth` via the `houseHealth`
> getter and refreshed by `calculateHouseHealth()`. Canonical definition:
> [`src/store/house.js`](../../src/store/house.js). (The former
> `fetchHouseStats`/`initializeHouse` examples were fictional — removed,
> CS-PLAN-068.)

Full house reads are latest-wins. A superseded caller waits for the winning
pending read rather than resolving against an empty store; only the owning
promise clears its pending/loading state. Mutations and identity reset still
invalidate old results. A partial health refresh waits for a pending full read
so it cannot discard the required name/timezone payload. Settings initializes
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
    error: null,
    planStatus: 'idle',       // idle|loading|ready|applying|applied|refreshFailed|stale|error
    planPreview: null,        // last preview payload (changes/unchanged/unplaced/capacity/warnings)
    planInput: null,          // the exact normalized input the preview was computed from
    planCommandId: null,      // receipt UUID: minted per preview, RETAINED across apply retries (WP2 §3.5)
    planConsent: false        // explicit owner consent attestation; reset per fresh preview, kept across 409 retries (WP2 §3.3.2)
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
    setPlanConsent(),              // records/clears the consent attestation for the current preview
    applyPlan(),                   // POST /scheduling/apply (input + previewVersion + commandId + consent) + reconciliation + refresh
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
`AgreementService` recordings, `PauseService` decisions).

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

### Aggregate Idiom: Scheduling Preview/Apply (CS-PLAN-065, ADR-012)

`SchedulePlanService` owns the owner-only plan aggregate: a deterministic,
write-free **preview** (`POST /scheduling/suggestions`) whose content
fingerprint (`previewVersion`, SHA-1 over canonically sorted request + row
state + member roles/preferences + timezone + capacity rows + proposal) is
confirmed by **apply** (`POST /scheduling/apply`).

- **Lock order (a subsequence of the single house-first order, CS-PLAN-071 —
  supersedes the ADR-011/012 two-family split):** `house → room_task
  (ascending ids) → task definition (ascending ids) → house_user (ascending
  ids) → assignee writes`. All writers — including completion/undo, now
  `house → room_task → room → house_user` — enter through the house lock and
  serialize; no ordering cycle can form.
- **Never apply against drifted state:** apply recomputes the identical plan
  from freshly locked rows; any fingerprint mismatch is a `409 CONFLICT`
  with zero writes. Two applies of one preview serialize into one commit and
  one clean conflict.
- **Planning rules** (contract detail in [ADR-012](../adr/ADR-012-actionable-household-scheduling.md)
  and [`docs/api/README.md`](../api/README.md)): legal recurrence/flex slots
  first, eligibility before capacity, availability/energy/child limits as
  hard constraints, claimed rows untouched. WP2 (CS-PLAN-073) refined the
  capacity rules: preserved/overridden JOINT sets are validated as the full
  proposed exact-share vector (every member with their own share), a
  same-day replacement removes exactly the row's old allocation before the
  check, and apply is a CommandService command carrying the receipt
  `commandId` and the owner's explicit `consent: true` attestation (fingerprint
  v2 additionally covers the house pause/agreement facts and the house-local
  evaluation day — CR-SVC-64 midnight crossings are a write-free 409 before
  the relative-date rejection).
- **Mapper lock primitives:** `RoomTaskMapper::lockForUpdate` /
  `HouseUserMapper::findForUpdate` / `HouseMapper::findForUpdate` exist as
  the MySQL/PostgreSQL `FOR UPDATE` + SQLite self-assignment pattern;
  `TaskMapper::lockForUpdate` follows the same shape. Any new writer that
  targets rows these aggregates lock must be audited against the order
  above before it ships.

### Aggregate Idiom: Work Accounting, Corrections & Replay (CS-PLAN-074 WP3, ADR-014)

The WP3 completion coordinator (`CompletionService`) is the ONE transaction
boundary and lock-order owner for completing, undoing and attributing work;
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
- **`CompletionService`** — the completion/undo/attribution aggregates.
  Completion freezes the work evidence (`completed_tasks.work_*`,
  `total_energy`, `rate_minor`, occurrence identity) and writes the selected
  allocation (`work_shares` per revision, `allocation_reviewed` evidence).
  **Undo** reverses the latest selected allocation revision exactly once —
  wallet union deltas keyed by wallet id, the occurrence completion release,
  the room replay and the recognition recompute. **Attribution**
  (`PUT /completions/{id}/attribution`) rewrites the selected allocation for
  completer/owner authority with the explicit deviation confirmation; the
  union is built from the **share rows by wallet id**, so a departed
  participant's retained wallet is always part of the reversal and a
  completion pays out exactly the frozen total. The frozen work facts, the
  room journal and the occurrence identity stay immutable under correction;
  the audit chain (`completion_revs`) is append-only and a departed-not-erased
  actor renders as a localized placeholder.
- **`WalletService`** — the money seam: one wallet per member (binding under
  lock; departed members retain wallet + balance), immutable `wallet_entries`
  ledger postings with the `(wallet, source-kind, source-id, source-revision)`
  idempotency identity, cached `booked/reserved/available` balance maintained
  transactionally (never re-summed at read), safe-integer accumulation
  enforced, per-house funding-hold watermark maintenance.
- **`RoomReplayService`** — the room journal truth: `baseline` / requested
  `delta` / `manual_delta` / absolute `reset` events fold in `(effective_at,
  id)` order with clamps; undo invalidates exactly its own completion effect
  (the correction execution time never becomes `last_cleaned`); a later reset
  stays authoritative over earlier corrections; the room cache columns are
  derived projections (`resyncRoomCache`), never the source of truth.
- **`RhythmService` + `WeeklyGoalService`** — the recognition projections:
  streaks recompute from the valid planned-day history (per-day outcomes:
  fulfilled / pending-neutral / expired-miss; a correction shrinks the
  longest record only to the corrected valid-history maximum), the weekly
  goal evaluates the union of due and validly completed room tasks per
  house-local week. No cached increment/decrement corrections anywhere.
- **`EffortAllocationService` / `TaskAccessPolicy`** — exact minor-unit share
  math (`E * P` energy minor, `10 * E * P` coin minor at the frozen rate) and
  the viewer-scoped authorization matrix reused by every aggregate.
- **`ActivityService`** — the batched history/read-model cluster (frozen
  facts, selected allocation, viewer-scoped `canCorrect`).
- **`RoomTaskReadService`** — the batched instance-read/enrichment cluster:
  house-/room-/scope-wide fetches, the visibility matrix (batched task +
  assignee reads), `{roomTask, task, room}` row assembly (the single owner of
  the daily enrichment), filtered-daily ("My Day"), due-today/overdue rows and
  the house timezone resolution (CS-PLAN-066 D1 one-owner rule; unchanged by
  WP3).
- **`CompletionPostCommitService`** — the guarded post-commit boundary:
  weekly-goal read and notification dispatches; failures are logged and
  failure-isolated, never failing a committed aggregate.

The legacy `CompletionEffectService` snapshot restore and the
`GameLogicService` reward orchestration (coins/combo/team-spark/boosts) are
retired with the legacy economy (ADR-014); `GameLogicService` keeps only the
viewer-safe game-state serialization.

Frontend counterpart (WP3 §3.7): the activity feed exposes the authorized
older-completion correction; `ParticipationDialog` is reused for attribution
correction (recorded-split pre-fill, correter need not participate, a 409
keeps the draft); `UndoSnackbar` remains the completion-state restore surface
from TodayView; `src/utils/minorUnits.js` renders exact minor units (no Coin
column anywhere).

### Aggregate Idiom: Agreements, Pauses & Capacity (CS-PLAN-073 WP2, ADR-013 §WP2)

The WP2 surfaces keep the single house-first lock order and the CommandService
receipt convention; every command authorizes BEFORE the receipt replay and
commits domain facts, versions, evidence and the receipt in ONE transaction:

- **`AgreementService`** — the owner-recorded agreement command aggregate
  (`occurrence#amend / catch-up / restart`): closed payload validation,
  receipt identity over the ACTION plus every normalized semantic input
  (date, canonical participant set, work appointment), the command-boundary
  staleness gates (unfulfilled target → expected version → recomputed
  authoritative `selectionToken` → effective projection-stamped suspension)
  and the whole-day capacity gates. Capacity/consent validation follows the
  arrangement the writer will actually persist: date-only amendments and
  catch-ups without a participant payload resolve the CONCRETE active
  occurrence members under the lock (`assertArrangedDayCapacity`,
  RF-073-WP2-27 — revision defaults remain the basis only for virtual
  derivations and the restart successor), and a participant write re-derives
  the effective suspension from the new rows so an explicit feasible
  agreement resumes a suspended occurrence with persisted facts agreeing
  (RF-073-WP2-24). Date amendments move EXACTLY the resolved
  occurrence (`amendOccurrenceDateForTargetUnderLock`) and report the
  resulting version; the self-selecting writer variant remains for the
  planner's "next obligation" semantics.
- **`OccurrenceAgreementService`** — the writer set: participant amendments
  persist neutral releases (former active members keep a `released` row with
  their role/share snapshot, obligation dropped — one row per wallet) and
  derive the evidence kind from the actual responsibility change
  (`handover` only when the active responsible set changes); catch-up
  appointments (date + zone) ride the replaced rows; every writer bumps the
  occurrence version and records `agreements` evidence with the consent
  attestation.
- **`PauseService` + `PauseProjectionService`** — revisioned pause groups and
  the projection math: only recognized (acknowledged) revisions have effects,
  resolved with PER-GROUP PRECEDENCE — the latest acknowledged revision
  governs every date from its next-local-day decision boundary (derived from
  the persisted `updated_at` decision fact), so shortenings remove their
  superseded future coverage (RF-073-WP2-23) while pending/rejected
  revisions govern nothing; an ordinary recognition excuses only when its
  decision predates the obligation's original exclusive deadline
  (RF-073-WP2-25 — the retrospective-illness marker is the sole exception,
  and reads, command writes and job materialization apply the same gate).
  Pauses of different groups compose as a union with full per-pause
  provenance (each covering pause owns an evidence row with its
  `pause_ref`); suspension triggers when ANY active obligated participant is
  covered — joint work suspends as a whole until an explicit feasible
  agreement resumes it, while a helper-only pause releases just that
  commitment (RF-073-WP2-24). Materialized occurrences resolve participants
  from their concrete member rows and virtual twins from the saved revision
  defaults, deriving identical facts (RF-073-WP2-26); work-appointment
  derivation runs over the member snapshots INDEPENDENTLY of pause presence.
  The viewer list is an explicit field whitelist — personal reason and
  illness marker are self/owner data in every state (RF-073-WP2-28).
  Departure cancels pending personal
  requests and stops future personal effects (Q-073-03).
- **`DailyCapacityService`** — the whole-day minor-unit evaluator shared by
  the planner, the agreement gates and the pause projection: exact shares,
  occurrence-identity dedupe, frozen energies from the SAVED occurrence
  (INNER join — no mutable-definition fallback), saved-zone time basis,
  suspended-consumes-nothing, helper commitments included, and
  `assertCommitmentsFit(..., $replacedOccurrence)` which removes the replaced
  allocation before testing new commitments. `withSharedCollection()` scopes
  ONE batched collection per evaluation pass (no cross-pass memo staleness).

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
collaborators).

Dispositions: **accepted** (cohesive unit or static/data surface — splitting
would damage cohesion), **named** (exception ratified in CS-PLAN-067 §3.5),
**remainder** (open decomposition candidate, owning backlog item cited).

#### Backend (`lib/`)

| File | Lines | Cohesive responsibility | Disposition |
|------|-------|--------------------------|-------------|
| `Controller/RoomTaskController.php` | 939 | Room-task route surface (selector modes, lifecycle, stats, range, WP1 occurrence/receipt seam); thin validate→service→serialize methods only | **named** (CS-PLAN-067 §3.5: assessed per-method, independently of service orchestration) |
| `Controller/SchedulingController.php` | 466 | Scheduling route surface (preferences, preview, apply incl. the WP2 consent attestation); thin methods | accepted (endpoint breadth, no logic depth) |
| `Controller/UserController.php` | 428 | Member/profile route surface; thin methods (persistence delegated to HouseService, RF-058-04) | accepted (endpoint breadth) |
| `Controller/HouseController.php` | 411 | House route surface (read, settings, the catalog and arcade gates, stats, activity, health, reset); every stored house payload adds the app-config arcade flag; thin methods | accepted (endpoint breadth) |
| `Controller/RoomController.php` | 338 | Room route surface incl. the WP3 journal/manual-delta/reset seam; thin methods | accepted (endpoint breadth) |
| `Controller/ChecklistController.php` | 307 | Checklist route surface incl. the WP1 occurrence-identity progress/toggle endpoints; thin methods | accepted (endpoint breadth) |
| `Service/SchedulePlanService.php` | 1234 | WP6 preview/apply aggregate: fingerprint revalidation, capacity check, lock order, member-day writes (WP1: capacity from the occurrence authority; WP2: exact-share joint placement, replacement-first capacity, consent attestation, injected-clock seam) | **named** (CS-PLAN-067 §3.5: cohesive fingerprint/capacity aggregate; budgets only, no optimizer rewrite) |
| `Service/CompletionService.php` | 1447 | WP3 completion/undo/attribution aggregate plus WP5 transactional takeover queue: frozen work evidence, exact wallet-union deltas, recognition and journal writes | **named** (CS-PLAN-067 §3.5 / ADR-014: one visible ordered aggregate; further splitting would hide the lock/order contract) |
| `Service/RoomTaskService.php` | 846 | Room-task mutations, bulk-assign partial-success semantics, room stats, calendar-range projection (WP1: agreement-writer seam) | **remainder** (CR-SVC-15: range/stats projection extraction candidate) |
| `Service/RoomTaskReadService.php` | 758 | Batched read/enrichment cluster (house/room/scope/daily/due/claimable + timezone); WP8 product; WP1 occurrence-block enrichment; WP2 batched pause-fingerprint reads | accepted (read-surface breadth, one owner per D1) |
| `Service/TaskCreationService.php` | 453 | WP4 atomic creation aggregate (custom/mount/template, one transaction; WP1: initial schedule revision) | accepted (transaction aggregate) |
| `Service/HouseSetupService.php` | 492 | WP3 first-run setup transaction aggregate (first-run gate, structure, invites; WP1: schedule initialization) | accepted (transaction aggregate) |
| `Service/TaskService.php` | 538 | Task-definition CRUD + validation set (WP1: definition edits revise schedules) | **remainder** (CR-SVC-15: `createTask` 24 positional params → param object) |
| `Service/HouseService.php` | 531 | House aggregate: membership, stats, timezone revision passes, ranking flag and consent-only reset, membership-keyed preference/digest cleanup | accepted (cohesive aggregate) |
| `Service/ChecklistService.php` | 415 | Checklist domain: occurrence-scoped progress derivation, toggles, reset/restore seam (WP1) | accepted (cohesive checklist domain) |
| `Service/SchedulingService.php` | 304 | Scheduling preferences/availability domain surface | accepted (cohesive domain) |
| `Service/TemplateService.php` | 311 | Task catalog templates + level presets | accepted (catalog surface) |
| `Service/RewardConfigService.php` | — | Static reward configuration registry — the WP4 retirement of the weekly-goal/threshold override keys dropped it BELOW the guideline (164 non-blank lines); entry retired | removed from the inventory (2026-09-27) |
| `Service/MedalService.php` | 316 | Retained rhythm/weekly recognition, correction reconciliation and in-app weekly receipt; WP5 removes external award bells | accepted (cohesive recognition domain, one award writer) |
| `Service/RoomService.php` | 328 | Room domain: structure mutations, cleanliness manual-delta command and reset through the WP3 room journal, derived cache updates | accepted (cohesive room domain + the journal writer seam) |
| `Service/RankingService.php` | 428 | Consent-filtered exact-ratio ranking and next-Monday generations; WP5 preserves delivery settings through every in-place consent transition | accepted (cohesive ranking authority, ADR-015) |
| `Service/TestDataService.php` | 635 | Test-only seeding/reset tooling, including the WP5 digest table inventory | accepted (test tooling, explicitly classified) |
| `Service/DigestService.php` | 361 | WP5 five-minute pass, current-source/visibility relevance, house-first claim, commit-before-notify and outcome bookkeeping | accepted (one visible at-most-once delivery choreography; scheduling and persistence remain in their existing owners) |
| `Service/OccurrenceService.php` | 458 | Single-recurrence-authority facade: stable public seam, constants and transaction convention over the six collaborators below (CS-PLAN-072 WP1, RF-072-WP1-18 split; WP3 completion-resolution delegation) | accepted (delegation-only facade — the one authority every consumer talks to) |
| `Service/OccurrenceSelectionService.php` | 551 | Write-free selection/read derivation, occurrence DTO block, selection token, projections (WP2: neutral-suspension stamp, agreed work appointment, token fact set) | accepted (cohesive collaborator of the occurrence authority) |
| `Service/OccurrenceReconcileService.php` | 441 | Frontier, reconciliation, materialization, interval pending, end-of-life, `next_due` projection, timeline-job pass (WP2: command-boundary stamp persistence) | accepted (cohesive collaborator of the occurrence authority) |
| `Service/RewardRequestService.php` | 995 | WP7 C4 request lifecycle: one ordered lock/authorization/receipt/effect/queue sequence per command, the role/departure/erasure hooks and the viewer-safe serialization |
| `Service/RewardService.php` | 424 | WP7 catalog owner: item CRUD with strict terms, the household-gate command and the scope matrix |
| `Service/OccurrenceRevisionService.php` | 385 | Rule inputs, revision-1 creation, edit/zone/tombstone revision lifecycle | accepted (cohesive collaborator of the occurrence authority) |
| `Service/OccurrenceCompletionService.php` | 384 | Completion resolution (key/version/token), timeline effects, undo seam (WP3: share-row resolution for the correction aggregates) | accepted (cohesive collaborator of the occurrence authority) |
| `Service/OccurrenceAgreementService.php` | 707 | C2 date/participant amendments + the shared whole-day child-capacity counter (WP2: explicit-target date writer, persisted neutral releases, appointment-carrying catch-up, derived evidence kinds) | accepted (cohesive collaborator of the occurrence authority — the single writer set for occurrence agreements; splitting would spread one lock choreography across files) |
| `Service/AgreementService.php` | 816 | WP2 agreement command aggregate: closed validation, authorization/receipt/staleness/capacity gates, suspension restamp and WP5 transactional queue writes | accepted (D2-style visible ordered transaction choreography) |
| `Service/PauseService.php` | 728 | Revisioned pause lifecycle, viewer projection, deadline-aware effects, departure and WP5 transactional decision queue writes | accepted (one ordered lock/revision/effect/queue sequence per command) |
| `Service/PauseProjectionService.php` | 485 | WP2 projection derivation: per-group revision precedence + decision-boundary math (RF-073-WP2-23), recognition-eligibility gate (RF-073-WP2-25), recognized coverage union, suspension decision (any-obligated rule, RF-073-WP2-24), virtual/materialized participant resolution (RF-073-WP2-26), work-appointment derivation, frozen zone-intersection math | accepted (cohesive projection math, single owner of the Q-073-02 rules) |
| `Service/DailyCapacityService.php` | 529 | WP2 whole-day capacity evaluator: exact minor-unit shares, occurrence-identity dedupe, saved-zone time basis, commitment gates with replacement removal, shared-collection pass | accepted (cohesive evaluator — one owner of the §3.2 accounting rules) |
| `Db/RoomTask.php` | 362 | Central entity: domain accessors, status computation, JSON serialization (legacy recurrence engine removed by CS-PLAN-072 WP1, RF-072-WP1-12/16) | accepted (entity surface; data-driven) |
| `Db/RoomTaskMapper.php` | 503 | SQL primitives for the central instance table (house/room/scope/daily/locks) | accepted (SQL primitive collection) |
| `Db/CompletedTaskMapper.php` | 567 | Ledger SQL: stats aggregates, occurrence lookups, weekly windows, completion revisions | accepted (SQL primitive collection) |

#### Frontend (`src/`)

| File | Lines | Cohesive responsibility | Disposition |
|------|-------|--------------------------|-------------|
| `views/Settings.vue` | 1154 | Settings composition plus the WP5 minimal self-only notification section; the full preference component/redesign stays WP6 | accepted (section-per-concern template breadth; each section is independent UI) |
| `components/Setup/SetupWizard.vue` | 816 | WP3 setup journey step machine | accepted (cohesive step machine; step extraction only with a reviewed UI contract) |
| `views/RoomDetail.vue` | 874 | Room composition root (stats, scoped list, dialogs) — rows carried by shared TaskList units | accepted (composition root) |
| `views/TodayView.vue` | 690 | My Day surface (WP7 exit + WP3: explicit-split completion, undo, proposal pre-fill) | accepted (composition root) |
| `components/Tasks/TaskEditForm.vue` | 891 | Unified create/edit form contract (UX-001): field validation surface | accepted (one form contract; field breadth) |
| `components/Tasks/TaskChecklist.vue` | 521 | Checklist surface: lazy discovery, toggles, sub-tasks | accepted (cohesive checklist domain) |
| `components/Tasks/TaskListItem.vue` | 420 | WP8 row unit: status/content/controls/a11y incl. scoped CSS | accepted (row surface) |
| `components/Tasks/TaskList.vue` | 357 | WP8 orchestrator: sorting, bulk selection, expansion (was ~1,160) | accepted (orchestrator, extracted units carry the rest) |
| `components/Common/ParticipationDialog.vue` | 339 | The actual-work split form (WP3 §3.7 reuse: completion proposal pre-fill + attribution-correction mode, validation surface) | accepted (one form contract across both flows) |
| `components/Tasks/FlexWindowDisplay.vue` | 334 | Flex-window bounds presentation | accepted (presentational) |
| `components/Scheduling/SchedulePlanner.vue` | 765 | WP6 planning surface (member grids, preview/apply, dialogs); WP2: exact-share labels, consent attestation, neutral/overload capacity clarifications | accepted (planning composition root) |
| `components/Scheduling/AvailabilityEditModal.vue` | 469 | Owner availability grid editor | accepted (grid editor surface) |
| `components/Scheduling/ScheduleCalendar.vue` | 391 | Week grid + capacity/overcapacity display | accepted (presentational grid) |
| `components/Members/MemberList.vue` | 412 | Member management surface | accepted (template+CSS dominant) |
| `components/Profile/TitlePicker.vue` | — | Title pack/tier picker — retired with the CS-PLAN-070 WP4 single-series cutover | removed from the inventory (2026-09-27; archived under the gitignored `archive/`) |
| `components/Dashboard/FloorList.vue` | 462 | Floor/room dashboard list | accepted (presentational) |
| `components/Dashboard/RoomCard.vue` | 445 | Room status card | accepted (presentational) |
| `components/Dashboard/QuickStats.vue` | 372 | Stat cards | accepted (presentational) |
| `components/Minigames/SnakeGame.vue`, `SnakeLeaderboard.vue` | — | Single-game Snake and its board — replaced by the shared game shell (CS-PLAN-086 WP-A) | removed from the inventory (2026-10-17; archived under the gitignored `archive/`) |
| `components/Minigames/GameShell.vue` | 420 | Shared frame of every arcade game: loop, shell-bound input, responsive canvas or a game's DOM board (tap input, round timer), overlays, live region with a game's own end copy, result save | accepted (one frame for all games; ~150 lines scoped CSS) |
| `views/Leaderboard.vue` | 496 | Consent-filtered ranking surface (opt-in/closed state, entries/pending, provisional week) with the weekly-medal display — no unconditional member ranking, contribution column or freeze indicator (CS-PLAN-070 WP4) | accepted (presentational) |
| `views/TaskManager.vue` | 614 | Task manager composition root (filters, stats bar, modals) | accepted (composition root) |
| `views/Dashboard.vue` | 351 | Dashboard composition | accepted (composition root) |
| `views/CalendarView.vue` | 301 | Calendar composition | accepted (composition root) |
| `views/InsightsView.vue` | 531 | Insights composition root + the WP3 §3.6 history-correction session (feed action, reused dialog, 409 draft retention) | accepted (composition root) |
| `store/roomTasks.js` | 1064 | Canonical instance store: projections, generations, reconciliation (CS-PLAN-066 D3 one-owner rule); WP1 receipt contract + pending-command cache; WP3 completion correction actions | accepted (D3: splitting would duplicate cache/reconciliation ownership) |
| `store/scheduling.js` | 522 | Scheduling state: preferences, capacity/overcapacity getters | accepted (cohesive store) |
| `store/rooms.js` | 341 | Floor/room state (WP3 journal receipt flows) | accepted (cohesive store) |
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
