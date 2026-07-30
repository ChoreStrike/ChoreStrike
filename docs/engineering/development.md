# Development Guide

This document covers local setup, commands, and contributor workflow.

If you are acting as an AI agent, start with [`../../AGENTS.md`](../../AGENTS.md) first. Use this document only when you need environment or execution details.

## Getting Started

### Prerequisites

- **PHP 8.0+** with extensions: `pdo`, `mbstring`, `curl`, `gd`, `zip`
- **Node.js 20+** and npm 10+
- **Nextcloud 27+** development instance
- **Composer** for PHP dependencies
- **Git** for version control

### Docker Development Environment

For isolated testing, use the included Docker setup:

1. **Start the test environment**
   ```bash
   docker compose -f docker-compose.test.yml up --build
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
   docker compose -f docker-compose.test.yml down
   ```

### Initial Setup

1. **Clone the repository**
   ```bash
   cd /path/to/nextcloud/apps/
   git clone https://github.com/ChoreStrike/ChoreStrike.git
   cd ChoreStrike
   ```

2. **Install PHP dependencies**
   ```bash
   composer install
   ```

3. **Install Node dependencies**
   ```bash
   npm install
   ```

4. **Build frontend**
   ```bash
   npm run dev
   # or for production
   npm run build
   ```

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

## Project Structure

See [architecture.md](architecture.md) for detailed structure.

## Coding Standards

### PHP

- Follow **PSR-12** coding standard
- Use **type hints** for all parameters and return types
- Document all public methods with **PHPDoc**
- Keep methods **small and focused** (< 50 lines)
- Use **dependency injection**

**Example:**
```php
<?php
namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskMapper;

class TaskService {
    private TaskMapper $mapper;
    
    public function __construct(TaskMapper $mapper) {
        $this->mapper = $mapper;
    }
    
    /**
     * Calculate the next due date for a task
     * 
     * @param Task $task The task to calculate for
     * @return \DateTime The next due date
     */
    public function calculateNextDue(Task $task): \DateTime {
        // Implementation
    }
}
```

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

**Run tests:**
```bash
vendor/bin/phpunit
```

### Vue Component Tests

Located in `tests/unit/`

**Example:**
```javascript
import { mount } from '@vue/test-utils'
import TaskItem from '@/components/Task/TaskItem.vue'

describe('TaskItem.vue', () => {
  const mockTask = {
    id: 1,
    name: 'Test Task',
    difficulty: 3,
  }
  
  it('renders task name', () => {
    const wrapper = mount(TaskItem, {
      props: { task: mockTask },
    })
    
    expect(wrapper.text()).toContain('Test Task')
  })
  
  it('emits complete event when button clicked', async () => {
    const wrapper = mount(TaskItem, {
      props: { task: mockTask },
    })
    
    await wrapper.find('button').trigger('click')
    
    expect(wrapper.emitted('complete')).toBeTruthy()
    expect(wrapper.emitted('complete')[0]).toEqual([1])
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
```javascript
if (process.env.NODE_ENV === 'development') {
  console.log('[ChoreStrike]', debugInfo)
}
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
   export async function myNewEndpoint(param) {
       const response = await axios.post('/apps/ChoreStrike/api/v1/tasks/new', { param })
       return response.data
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
   - Use `docs/standards/i18n-workflow.md` for the full POT + JSON process.
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

The authoritative CI workflow is `.forgejo/workflows/release-gate.yml`
(Forgejo Actions / Codeberg CI, GHA-compatible). It is the single source of
truth for the blocking quality gate and runs on every `push` and `pull_request`.

### Blocking gate commands

| Command | Purpose |
|---------|---------|
| `npm run lint` | ESLint, 0 errors required |
| `npm test` | Jest (Vue components, stores, API contract) |
| `npm run build` | Webpack production build |
| `composer validate --strict` | `composer.json` sanity |
| `vendor/bin/phpunit --configuration phpunit.unit.xml` | Backend unit tests (via Docker) |
| `vendor/bin/phpunit --configuration phpunit.integration.xml` | Backend integration tests (via Docker) |

Stylelint is **advisory/non-blocking** until the
`@nextcloud/stylelint-config` ↔ `stylelint` upstream version mismatch is
resolved (see `docs/backlog/archive/2026-07.md`). See [`testing.md`](testing.md) for the
local reproduction recipe and full CI detail.

## Resources

- [Nextcloud Developer Docs](https://docs.nextcloud.com/server/latest/developer_manual/)
- [Vue.js 3 Docs](https://vuejs.org/)
- [Nextcloud Vue Components](https://nextcloud-vue-components.netlify.app/)
- [PSR-12 Standard](https://www.php-fig.org/psr/psr-12/)

## Getting Help

- Open an issue on the [Codeberg tracker](https://github.com/ChoreStrike/ChoreStrike/issues)
- Check existing documentation
- Ask in Nextcloud developer forums

---

Happy coding! 🎮✨
