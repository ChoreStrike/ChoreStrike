# Development Guide

This document covers local setup, commands, and contributor workflow.

If you are acting as an AI agent, start with [`../../AGENTS.md`](../../AGENTS.md) first. Use this document only when you need environment or execution details.

## Getting Started

### Prerequisites

- **PHP 8.4.x**, with extensions: `pdo`, `mbstring`, `curl`, `gd`, `zip`
- **Node.js 20.19.0 or later within 20.x** and **npm 10.8.2 or later within 10.x**
- **Nextcloud 35 primary / 34 AIO compatibility row** — the promoted current-stable
  baseline (declared in `appinfo/info.xml`; CS-PLAN-082, promoted 2026-10-06)
- **Composer** for PHP dependencies
- **Git** for version control

### Docker Development Environment

For isolated testing, use the included Docker setup:

The canonical commands below provision the pinned current-stable test runtime
directly: **Nextcloud 35.0.2 (internal 35.0.2.2) / PHP 8.4.26 / PostgreSQL
18.6**, frozen in `docker/test-runtime.json` and built by
`docker/Dockerfile.test`. The old disposable NC31/MariaDB canonical stack was
retired on 2026-10-06 after recording evidence; the container names
`chorestrike-test`/`chorestrike-db` and port 8080 are reused by the new stack.
Runtime discovery and verification entry points:

```bash
# Offline inventory validation
npm run test:runtime:validate
# Weekly stable-drift check (exit 2 = update needed); wired in .github/workflows/runtime-check.yml
npm run test:runtime:check
# Asserts the running canonical chorestrike-test/chorestrike-db stack matches the frozen inventory
npm run test:runtime:canonical
```

1. **Start the test environment**
   ```bash
   docker compose -f docker/compose.test.yml up --build
   ```

2. **Access the application**
   - Nextcloud: http://localhost:8080
   - Admin credentials: `admin` / `admin`

3. **Run tests in Docker**
   ```bash
   docker exec chorestrike-test composer test
   docker exec chorestrike-test npm test
   ```

4. **Stop the environment**
   ```bash
   docker compose -f docker/compose.test.yml down
   ```

### Initial Setup

1. **Clone the repository**
   ```bash
   cd /path/to/nextcloud/apps/
   git clone https://github.com/ChoreStrike/ChoreStrike.git
   cd ChoreStrike
   ```

   Do **not** set a repository-local `user.name`/`user.email`. Commits use your
   global git identity; a local override is how the history acquired a second
   contributor identity before the 2026-10-16 migration
   ([ADR-018](../adr/ADR-018-repository-hosting-and-contributor-identity.md)).
   `tests/unit/repo-metadata.spec.js` additionally fails the build if a tracked
   file reintroduces the retired identity or a non-canonical repository URL.

2. **Install PHP dependencies**
   ```bash
   composer install
   ```

3. **Install Node dependencies**
   ```bash
   npm ci --engine-strict --legacy-peer-deps=false
   ```

   The supported build-engine ranges are `node: ^20.19.0` and `npm: ^10.8.2`
   in `package.json`; newer major versions are not currently supported by the
   complete toolchain. CI validates the exact minimum versions. Keep dialogs on
   `~7.2.0`: its files 3 dependency supports Node 20, while dialogs 7.3 introduces
   Node-24-only files 4. Do not bypass peer or engine validation to upgrade it.

   `npm ci` also activates the tracked branch guard (`core.hooksPath=.githooks`,
   via `prepare`): no direct commits on `master`, and a worktree pinned with
   `npm run branch:pin` only accepts commits on its branch.
   `npm run branch:status` lists every worktree with its drift against
   `master`. Rules, worktree location and the explicit `CS_ALLOW_MASTER=1`
   override: the
   [`git-workflow` skill](../standards/ai/skills/git-workflow/SKILL.md#branch-guard-githookspre-commit).

4. **Build frontend**
   ```bash
   npm run dev
   # or for production
   npm run build
   ```

   > `/js/` and `/css/` are pure build outputs and **not tracked in Git**
   > (CS-PLAN-069) — `npm run build` (or `npm run dev`) is mandatory on a
   > fresh clone before the app can load in Nextcloud.

5. **Enable the app in Nextcloud**
   ```bash
   php occ app:enable ChoreStrike
   ```

## Development Workflow

### Frontend Development

**Watch mode** (auto-rebuild on changes):
```bash
npm run watch
```

**Linting:**
```bash
npm run lint
npm run lint:fix
npm run stylelint
npm run stylelint:fix
```

**Testing:**
```bash
npm test
npm run test:watch
```

### Backend Development

**PHP Linting:**
```bash
composer lint
```

**Code Style:**
```bash
composer cs:check
composer cs:fix
```

**Testing:**
```bash
composer test
```

### Database Migrations

**Create a new migration:**
```bash
php occ migrations:generate ChoreStrike
```

This creates a file in `lib/Migration/` with the current timestamp.

**Run migrations:**
```bash
php occ migrations:migrate ChoreStrike
```

**Migration template:**
```php
<?php
namespace OCA\ChoreStrike\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1000Date20260224000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options) {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        
        // Add your schema changes here
        
        return $schema;
    }
}
```

### Migration chain inventory (updated 2026-09-13, includes the CS-PLAN-064 WP5 `Version1029` change)

`lib/Migration/` holds **22 files spanning `Version1000` → `Version1029`**
(version numbers are non-contiguous):

> Version1000, 1001, 1004 (×2 — `20260228131728` and `20260228150000`),
> 1005, 1006, 1007, 1008, 1009, 1011, 1012, 1015, 1016, 1021, 1022, 1023,
> 1024, 1025, 1026, 1027, 1028, 1029.

- **Version1004 appears twice** (two same-version steps with different date
  suffixes); both are part of the chain (order-independent — decision D8,
  CS-PLAN-060 WP1).
- **Absent versions:** 1002, 1003, 1010, 1013, 1014, and 1017–1020. The dead
  no-op steps 1003/1010/1013/1017/1018/1019/1020 were pruned in CS-PLAN-055,
  and Version1002 (`room_tasks.nextcloud_task_id` sync remnant) was folded
  out in CS-PLAN-060 WP1 — all as pre-release fresh-install chain edits (no
  upgrade path, no backfills). Guarded by `FreshInstallMigrationSmokeTest`,
  which also asserts the folded canonical schema (unique instance index, no
  `is_private`/`assigned_to_user_id`/sync-remnant columns).
- **Version1029** (`20260906000000`, CS-PLAN-064 WP5) moved `completed_tasks`
  to the CS-PLAN-064 undo-effect schema — the immutable completion receipt
  (before-state/deltas JSON) that the transactional undo replays.
- **Version1030** (`20260918000000`, CS-PLAN-071 WP0) is the current chain
  head: it creates `commands`, `wallets` and `wallet_entries` (command
  receipts + the persistent house/account financial identity). WP3 extends
  this migration in place with `work_shares`/`room_events`.
- **Fresh-install hooks:** the migration chain only ever runs on a fresh
  install (`php occ migrations:migrate chorestrike` during app
  provisioning); per the pre-release policy there is NO upgrade path.
- Per the pre-release policy, schema fixes **fold into this existing chain**;
  the disposable test database is recreated (`docker compose -f
  docker/compose.test.yml down -v && up -d`) instead of writing upgrade steps.

### Locking and transactions (single house-first order, CS-PLAN-070 §3.5 / CS-PLAN-071 WP0)

`lib/Db/TransactionExecutor` is the **single owner** of
`IDBConnection::beginTransaction()/commit()/rollBack()`. Services scope an
aggregate of mapper calls with `TransactionExecutor::run(callable)` (begin →
callable → commit, roll back on any Throwable); they never inject
`IDBConnection` themselves. Mappers keep their own SQLite-tolerant lock
primitives (`HouseMapper::findForUpdate`,
`HouseUserMapper::findForUpdate`, `RoomTaskMapper::lockForUpdate`,
`TaskMapper::lockForUpdate`, `RoomMapper::lockForUpdate`,
`RoomTaskChecklistProgressMapper::findForUpdate`,
`WalletMapper::findForUpdate`) — each must be called inside an open
transaction.

**Deterministic lock order — frozen by CS-PLAN-070 §3.5, implemented by
CS-PLAN-071 WP0. Every writer follows this order or a subsequence (a subset
acquired in the same relative order); no writer may acquire the house lock
after a child lock:**

```
house → room tasks (by ID) → task definitions (by ID) → rooms (by ID)
      → active membership rows (by ID) → wallets (by ID)
      → occurrence/completion rows (by ID) → request/preference/digest rows (by ID)
```

This **supersedes** the former two-family split (setup/creation
`house → room_task → …` vs the ADR-011/012 completion-only entry order
`room_task → room → house_user`); the ADRs carry supersession notes with
their historical context preserved. Reads and different houses stay
independent. Never hold a DB transaction while sending a Nextcloud
notification (`CompletionPostCommitService` runs outside the transaction).
Recognized transient DB lock/deadlock failures are retried inside
`TransactionExecutor::run()` with a small bounded retry policy; authorization,
validation and conflict failures are never retried.

The checklist first-toggle primitive isolates its speculative insert with a
savepoint inside that caller-owned transaction. A unique-constraint loser rolls
back only the insert and re-locks the winner before flipping it. This matters on
PostgreSQL, where catching the duplicate exception alone leaves the transaction
aborted. Releasing the savepoint never commits the surrounding aggregate; any
unrelated failure propagates to the executor's full rollback. The installed-DB
checklist suite proves both outer commit and outer rollback on all providers.

**SQLite write acquisition (CS-PLAN-071 D5):** SQLite has no row-level
`FOR UPDATE` and no `BEGIN IMMEDIATE` expressible through
`IDBConnection`/Doctrine. `TransactionExecutor` therefore applies a bounded
`busy_timeout` on SQLite, and the contractually FIRST statement of every
writer — the house-lock primitive, whose SQLite implementation is a no-op
self-assignment `UPDATE` — acquires the database-wide write lock at
transaction start, which is BEGIN-IMMEDIATE-equivalent at the application
level (deferred BEGIN takes no write lock, and the first statement is a
write; no read ever precedes the write-acquisition inside a transaction).
The full database matrix is verified in WP9; WP0 proves the mechanism on an
isolated disposable SQLite environment.

#### WP0 writer/lock audit (2026-09-18, baseline `master` @ `1946166`)

| Writer / job / reset path | Entry lock before WP0 | After WP0 |
|---|---|---|
| `AuthorizationService::ensureHouseMember` (first-owner bootstrap) | house | house (unchanged; now also creates the wallet) |
| `HouseSetupService::applySetup` | house | house (unchanged; now also creates wallets) |
| `TaskCreationService::runAggregate` | house | house (unchanged, verified) |
| `SchedulePlanService::apply` | house → room_task asc → task asc → house_user asc | unchanged (verified subsequence) |
| `CompletionService::complete` / `undo` | room_task → room → house_user | house → room_task → room → house_user (supersedes ADR-011/012 entry order) |
| `RoomTaskService::setAssignees` / `bulkAssignTasks` (per item) | room_task | house → room_task |
| `RoomTaskService::updateDueDate` | room_task | house (room-task house) → room_task |
| `RoomTaskService::deleteRoomTask` | none (auto-commit deletes) | house → room_task (tombstone) |
| `ChecklistService::toggleItem` (via progress mapper transaction) | progress row | house → room_task → progress row |
| `ChecklistService` definition mutations (add/update/delete/reorder items) | none | house |
| `HouseService::inviteMember` | none (`createOrGet` auto-commit) | house → membership → wallet |
| `HouseService::removeHouseMember` | none | house → assignees/house_user deletes (wallet preserved) |
| `HouseService::updateUserRole` | none | house → house_user |
| `HouseService::updateHouse` (name/timezone) | none | house (+ `timezone_version` bump on timezone change) |
| `HouseService::resetHouse` | none | house |
| `FloorService::createFloor` / `updateFloorName` / `reorderFloors` | none | house |
| `FloorService::deleteFloor` | none (transaction without locks) | house → tombstone cascade |
| `RoomService::createRoom` / `updateRoomName` | none | house |
| `RoomService::deleteRoom` | none (transaction without locks) | house → tombstone cascade |
| `RoomService::updateRoomCleanliness` | none (CR-SVC-63) | house → room (journal is WP3) |
| `MinigameService::saveScore` / boost purchase | house_user only | house → score/user writes |
| `SchedulingService` preference writes | none | house → house_user |
| `TaskService::updateTask` | none | house → task definition |
| `StreakFreezeService` / `StreakRestockService` jobs | none (auto-commit updates) | house → house_user (converted; retired in WP4) |
| `TestDataService::reset` + seeds | test-mode only | test-mode only; includes `commands`/`wallets`/`wallet_entries`; seeds create wallets |
| `RewardService::createItem` / `updateItem` / `setCatalogEnabled` (WP7) | none | house → owner membership (`findForUpdate` guard) → item/house writes |
| `RewardRequestService::create/approve/reject/cancel/fulfill/correct` (WP7) | none | house → actor membership → wallet (locked before the request row) → request/event rows → digest rows |
| `RewardRequestService::handleRequesterRoleChangedUnderLock` (WP7 hook) | called inside `HouseService::updateUserRole` (house → house_user) | house → house_user → request/event rows |
| `RewardRequestService::handleMembershipRemovedUnderLock` (WP7 hook) | called inside `HouseService::removeHouseMember` (house) | house → wallet (release) → request/event rows |
| `AccountErasureService` reward purge (WP7) | house (existing erasure aggregate) | house → wallet (release) → request/event/item deletes + digest-row deletes |
| `TriggerJobs` paths | delegate to the services above | inherit |
| `GameLogicService` / `MedalService` / `TitleService` / `CompletionEffectService` | inside the completion transaction | inherit (house-first via `CompletionService`) |

#### WP0 structural read-path audit (active-planning filters)

Tombstoned rows (`deleted_at` set / room tasks also `is_active = 0`,
`deleted_token = id`) must disappear from active-planning reads:

- `FloorMapper::findByHouseId` / `countByHouseId` — `deleted_at IS NULL`.
- `RoomMapper::findByHouseId` / `findByFloorId` / `findByIds` /
  `resetByHouseId` — `deleted_at IS NULL`.
- `RoomTaskMapper` — existing `is_active` filters already exclude tombstoned
  room tasks; `exists()` (mount-duplicate guard) filters `deleted_token = 0`.
- History/activity reads deliberately keep resolving names through tombstoned
  rows: `RoomTaskMapper::findByIdsScopedToHouse` (JOIN-only, no active filter),
  `RoomMapper::find`, `FloorMapper::find`, `RoomTaskMapper::find`.

#### WP0 personal-identifier inventory (erasure surface, Q-070-02)

| Table.column | Kind | Erasure handling |
|---|---|---|
| `house_users.nextcloud_user_id`, `.display_name` | raw UID + display name | row deleted |
| `task_assignees.user_id` | raw UID | rows deleted |
| `medals.user_id` | membership id | rows deleted |
| `minigame_scores.user_id` | raw UID | rows deleted |
| `wallets.nextcloud_user_id` | raw UID | row deleted (with `wallet_entries`) |
| `wallet_entries.actor_house_user_id` | membership id | deleted with the wallet |
| `commands.actor_house_user_id` | membership id | nulled (receipt retained) |
| `completed_tasks.user_id` / `.combo_with_user_id` | membership id | nulled (anonymous shared work facts retained) |
| `chk_progress.checked_by_user_id` | membership id | NOT WP0-scoped: dangling attribution is a pre-existing orphan pattern (same as before WP0); adopted by the WP3 completion reshape |

History references internal bigint IDs only (never raw UIDs), so a recreated
same-name account gets a fresh `house_users.id` / `wallets.id` and cannot
rebind prior data.

## Project Structure

See [architecture.md](architecture.md) for detailed structure.

## Coding Standards

### PHP

- Follow the **Nextcloud coding standard** (enforced by `.php-cs-fixer.dist.php`
  → `nextcloud/coding-standard`; superset of PSR-12: **tabs** + same-line
  braces + ordered imports). Run `composer cs:check` before committing;
  `composer cs:fix` auto-applies.
- Use **type hints** for all parameters and return types
- Document all public methods with **PHPDoc**
- Keep methods **small and focused** (< 50 lines)
- Use **dependency injection**

**Example:**
```php
<?php
namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;

class RoomTaskService {
    private RoomTaskMapper $mapper;
    
    public function __construct(RoomTaskMapper $mapper) {
        $this->mapper = $mapper;
    }
    
    /**
     * Update the due date of a room task
     * 
     * @param int $roomTaskId ID of the room task
     * @param \DateTime $nextDue The new due date
     * @return RoomTask The updated room task
     */
    public function updateDueDate(int $roomTaskId, \DateTime $nextDue): RoomTask {
        // Implementation
    }
}
```

> Real method: `RoomTaskService::updateDueDate()` (`lib/Service/RoomTaskService.php`).
> Scheduling preferences are owned by `SchedulingService`
> (`lib/Service/SchedulingService.php`); the owner plan preview/apply
> aggregate is `SchedulePlanService` (`lib/Service/SchedulePlanService.php`,
> CS-PLAN-065 / ADR-012).
>
> **CS-PLAN-072 WP1 / ADR-013:** recurrence authority is the durable
> occurrence timeline owned by `OccurrenceService`
> (`lib/Service/OccurrenceService.php`) — a facade over the
> `lib/Service/Occurrence{RowFactory,ReconcileService,SelectionService,RevisionService,CompletionService,AgreementService}.php`
> collaborator split (RF-072-WP1-18; behavior-neutral, one public seam);
> `room_tasks.next_due` /
> `ideal_due_date` / `last_completed` are **derived projections**, never the
> source of truth. Every due-date/participant mutation must go through the
> common agreement writers (`amendOccurrenceDateUnderLock` /
> `amendParticipantsUnderLock`) — `updateDueDate`, `setAssignees`,
> `replaceAssigneesUnderLock`, `SchedulePlanService::apply` and the house
> timezone edit all do — so the occurrence is amended (identity retained,
> `agreements` evidence recorded, version bumped) instead of a raw projection
> write diverging from the timeline. Completion is a closed-set receipt command
> (`commandId`/`occurrenceId`/`expectedVersion`/`selectionToken`) resolved under
> the house-first lock; a raw `next_due` write is a defect.

### JavaScript/Vue

- Use **ES6+** syntax
- Follow **Vue.js 3 Composition API** style
- Use **meaningful variable names**
- Add **JSDoc** for complex functions
- Prefer **const** over let, avoid var

**Example:**
```vue
<template>
  <div class="task-item">
    <h3>{{ task.name }}</h3>
    <button @click="handleComplete">Complete</button>
  </div>
</template>

<script>
import { ref } from 'vue'

export default {
  name: 'TaskItem',
  
  props: {
    task: {
      type: Object,
      required: true,
    },
  },
  
  emits: ['complete'],
  
  setup(props, { emit }) {
    const handleComplete = () => {
      emit('complete', props.task.id)
    }
    
    return {
      handleComplete,
    }
  },
}
</script>

<style scoped>
.task-item {
  padding: 1rem;
  border: 1px solid var(--color-border);
}
</style>
```

### CSS

- Use **BEM naming** where applicable
- Prefer **CSS variables** for theming
- Keep styles **modular and reusable**
- Use **scoped styles** in Vue components

## Testing

### PHP Unit Tests

Located in `tests/unit/` (PHPUnit). The canonical, working example is
[`tests/unit/Service/GameLogicServiceTest.php`](../../tests/unit/Service/GameLogicServiceTest.php),
which exercises the real coin calculation in
[`GameLogicService::calculateCoins()`](../../lib/Service/GameLogicService.php):

```php
public function calculateCoins(
    Task $task,
    float $personalMultiplier = 1.0,
    bool $isCombo = false,
    float $earlyMultiplier = 1.0
): int
// coins = energy_cost × base rate × task_tier × personal × early
//         (× combo multiplier when $isCombo)
```

**Run tests** (Docker environment up — canonical commands):
```bash
docker compose -f docker/compose.test.yml exec -T nextcloud-test \
  vendor/bin/phpunit --configuration phpunit.unit.xml
```

### Vue Component Tests

Located in `tests/unit/components/`.

**Example** (real, runnable — [`AssigneeChips.spec.js`](../../tests/unit/components/AssigneeChips.spec.js)
tests [`src/components/Tasks/AssigneeChips.vue`](../../src/components/Tasks/AssigneeChips.vue)):
```javascript
import { mount } from '@vue/test-utils'
import AssigneeChips from '../../../src/components/Tasks/AssigneeChips.vue'

describe('AssigneeChips.vue', () => {
  const members = [
    { nextcloudUserId: 'mom', displayName: 'Mom' },
    { nextcloudUserId: 'dad', displayName: 'Dad' },
  ]

  it('renders one chip per member', () => {
    const wrapper = mount(AssigneeChips, {
      props: { modelValue: [], members },
    })
    expect(wrapper.findAll('.assignee-chip')).toHaveLength(2)
  })

  it('emits the full next array when a chip is toggled', async () => {
    const wrapper = mount(AssigneeChips, {
      props: { modelValue: [], members },
    })
    await wrapper.find('[data-testid="assignee-chip-mom"] input').setValue(true)
    expect(wrapper.emitted('update:modelValue')[0]).toEqual([['mom']])
  })
})
```

**Run tests:**
```bash
npm test
```

## Debugging

### Frontend

**Vue DevTools:**
Install the Vue DevTools browser extension for debugging.

**Console Logging (Development only):**
Never use raw `console.*` in `src/` — the project logger wraps levels and
keeps output structured:

```javascript
import { logger } from '../utils/logger.js'

logger.error('Failed to complete task', error, { view: 'RoomDetail', action: 'handleComplete' })
```

**Network Tab:**
Use browser DevTools Network tab to inspect API calls.

### Backend

**Nextcloud Logging:**
```php
$this->logger->debug('Debug message', ['context' => $data]);
$this->logger->info('Info message');
$this->logger->error('Error message', ['exception' => $e]);
```

**View logs:**
```bash
tail -f /path/to/nextcloud/data/nextcloud.log
```

**Set log level in config.php:**
```php
'loglevel' => 0, // 0=Debug, 1=Info, 2=Warn, 3=Error, 4=Fatal
```

## Common Tasks

### Adding a new API endpoint

1. **Add route** in `appinfo/routes.php`:
   ```php
   ['name' => 'task#myNewEndpoint', 'url' => '/api/v1/tasks/new', 'verb' => 'POST'],
   ```

2. **Add controller method** in `lib/Controller/TaskController.php`:
   ```php
   public function myNewEndpoint(string $param): JSONResponse {
       try {
           $result = $this->service->doSomething($param);
           return new JSONResponse(['success' => true, 'data' => $result]);
       } catch (\Exception $e) {
           return new JSONResponse(['success' => false, 'error' => $e->getMessage()], 500);
       }
   }
   ```

3. **Add service method** in `lib/Service/TaskService.php`

4. **Add frontend API call** in `src/services/api.js`:
    ```javascript
    import { post } from './api'

    export async function myNewEndpoint(param) {
        // `post` prefixes the path with API_PREFIX ('/apps/chorestrike'),
        // unwraps the { success, data, error } envelope and throws ApiError
        // on failure — never call axios with a hand-built URL.
        return post('/api/v1/tasks/new', { param })
    }
    ```

### Adding a new Vue component

1. **Create component** in `src/components/`:
   ```vue
   <template>
     <!-- Template -->
   </template>
   
   <script>
   export default {
     name: 'MyComponent',
   }
   </script>
   
   <style scoped>
   /* Styles */
   </style>
   ```

2. **Import and use** in parent component:
   ```vue
   <script>
   import MyComponent from './MyComponent.vue'
   
   export default {
     components: {
       MyComponent,
     },
   }
   </script>
   ```

### Adding translations

1. **Mark strings for translation** in Vue:
   ```javascript
   import { translate as t } from '@nextcloud/l10n'
   
   const message = t('chorestrike', 'Hello World')
   ```

2. **Mark strings in PHP:**
   ```php
   $message = $this->l->t('Hello World');
   ```

3. **Follow the project workflow:**
   - Use `docs/standards/guidelines/i18n.md` (Artifact Workflow section) for the full POT + JSON process.
   - Update `l10n/templates/chorestrike.pot`, `l10n/de.json`, and `l10n/en.json` in the same change set.

4. **Run key sync validation:**
   ```bash
   npm run l10n:check-keys
   ```

## Performance Tips

### Frontend
- Use `v-show` for frequently toggled elements
- Use `v-if` for conditionally rendered elements
- Lazy load routes and components
- Use virtual scrolling for long lists
- Debounce search inputs
- Optimize images (use sprite sheets)

### Backend
- Use eager loading to avoid N+1 queries
- Add database indexes for frequently queried columns
- Cache expensive calculations
- Use pagination for large datasets
- Profile slow queries with Nextcloud's query logger

## Troubleshooting

### App not showing in Nextcloud

1. Check if enabled: `php occ app:list`
2. Enable: `php occ app:enable ChoreStrike`
3. Check logs: `tail -f data/nextcloud.log`

### Frontend not updating

1. Clear browser cache
2. Rebuild: `npm run build`
3. Check webpack errors in console

### Database errors

1. Check migration status: `php occ migrations:status ChoreStrike`
2. Run migrations: `php occ migrations:migrate ChoreStrike`
3. Check database connection in Nextcloud config

### Permission errors

1. Check file permissions: `chown -R www-data:www-data /path/to/nextcloud/apps/ChoreStrike`
2. Check Nextcloud permissions in admin panel

## Git Workflow

### Branch naming
- `feature/task-templates`
- `fix/leaderboard-sort-bug`
- `docs/api-documentation`

### Commit messages
```
feat: Add task completion celebration
fix: Correct coin calculation for combo tasks
docs: Update API documentation
refactor: Simplify room state calculation
test: Add unit tests for TaskScheduler
i18n: Add German translations
```

### Pull requests

The default branch is `master`. The canonical branch and commit workflow lives
in [`docs/standards/ai/skills/git-workflow`](../standards/ai/skills/git-workflow/SKILL.md)
and `AGENTS.md`; in short:

1. Create a feature branch from `master`
2. Make changes and commit (conventional-commit messages)
3. Push to your fork
4. Open a pull request against `master`
5. Wait for review and the release-gate CI (see CI/CD below)
6. Merge when approved

## CI/CD

The authoritative CI workflow is `.github/workflows/release-gate.yml` (GitHub
Actions). It is the single source of truth for the blocking quality gate and is
run **manually** from the Actions tab, because its PHPUnit and browser jobs
need Docker and are subject to the transport flakiness tracked as
TECH-TRANSPORT-001 (CS-PLAN-085 D-085-04). Every push and pull request runs the
Docker-free subset in `.github/workflows/checks.yml`, and the binding gate
before a release remains the local Docker run described in
[`testing.md`](testing.md).

### Blocking gate commands

| Command | Purpose |
|---------|---------|
| `npm run lint` | ESLint, 0 errors required |
| `npm run stylelint` | Stylelint, 0 errors required (`src/` Vue/CSS/SCSS) |
| `npm test` | Jest (Vue components, stores, API contract) |
| `npm run build` | Webpack production build |
| `composer validate --strict` | `composer.json` sanity |
| `composer cs:check` | PHP coding standard (Nextcloud CS), 0 diff required |
| `vendor/bin/phpunit --configuration phpunit.unit.xml` | Backend unit tests (via Docker) |
| `vendor/bin/phpunit --configuration phpunit.integration.xml` | Backend integration tests (via Docker) |

Stylelint (`npm run stylelint`) and the PHP coding standard
(`composer cs:check`) are **enforced/blocking** gates as of CS-026 (the
`@nextcloud/stylelint-config` ↔ `stylelint` version mismatch and the missing
`.php-cs-fixer.dist.php` were both resolved 2026-08-12). Stylelint still emits
~26 `use-logical` *warnings* (deferred RTL sweep →
[`backlog.md`](../backlog.md) TECH-LINT-004); warnings do not fail the gate
(absent `--max-warnings`). See [`testing.md`](testing.md) for the local
reproduction recipe and full CI detail.

## Resources

- [Nextcloud Developer Docs](https://docs.nextcloud.com/server/latest/developer_manual/)
- [Vue.js 3 Docs](https://vuejs.org/)
- [Nextcloud Vue Components](https://nextcloud-vue-components.netlify.app/)
- [Nextcloud Coding Standard](https://github.com/nextcloud/coding-standard) (PSR-12 superset, enforced via `.php-cs-fixer.dist.php`)

## Getting Help

- Open an issue on the [GitHub issue tracker](https://github.com/ChoreStrike/ChoreStrike/issues)
- Check existing documentation
- Ask in Nextcloud developer forums

---

Happy coding! 🎮✨
