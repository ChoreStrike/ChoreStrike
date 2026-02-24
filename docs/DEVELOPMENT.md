# Development Guide

## Getting Started

### Prerequisites

- **PHP 8.0+** with extensions: `pdo`, `mbstring`, `curl`, `gd`, `zip`
- **Node.js 20+** and npm 10+
- **Nextcloud 27+** development instance
- **Composer** for PHP dependencies
- **Git** for version control

### Initial Setup

1. **Clone the repository**
   ```bash
   cd /path/to/nextcloud/apps/
   git clone https://github.com/yourorg/chorewars.git
   cd chorewars
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
   php occ app:enable chorewars
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
php occ migrations:generate chorewars
```

This creates a file in `lib/Migration/` with the current timestamp.

**Run migrations:**
```bash
php occ migrations:migrate chorewars
```

**Migration template:**
```php
<?php
namespace OCA\ChoreWars\Migration;

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

See [ARCHITECTURE.md](ARCHITECTURE.md) for detailed structure.

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
namespace OCA\ChoreWars\Service;

use OCA\ChoreWars\Db\Task;
use OCA\ChoreWars\Db\TaskMapper;

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

Located in `tests/Unit/`

**Example:**
```php
<?php
namespace OCA\ChoreWars\Tests\Unit\Service;

use OCA\ChoreWars\Service\PointsCalculator;
use PHPUnit\Framework\TestCase;

class PointsCalculatorTest extends TestCase {
    private PointsCalculator $calculator;
    
    protected function setUp(): void {
        $this->calculator = new PointsCalculator();
    }
    
    public function testCalculateBasePoints() {
        $points = $this->calculator->calculate(3, 2, false);
        $this->assertEquals(30, $points);
    }
    
    public function testCalculateComboBonus() {
        $points = $this->calculator->calculate(3, 2, true);
        $this->assertEquals(45, $points);
    }
}
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
  console.log('[ChoreWars]', debugInfo)
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
       const response = await axios.post('/apps/chorewars/api/v1/tasks/new', { param })
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
   
   const message = t('chorewars', 'Hello World')
   ```

2. **Mark strings in PHP:**
   ```php
   $message = $this->l->t('Hello World');
   ```

3. **Extract strings:**
   ```bash
   # This generates/updates l10n/templates/chorewars.pot
   npm run l10n:extract
   ```

4. **Translate:**
   - Copy `.pot` to `de.po`, `en.po`, etc.
   - Translate strings
   - Compile to `.json`

## Performance Tips

### Frontend
- Use `v-show` for frequently toggled elements
- Use `v-if` for conditionally rendered elements
- Lazy load routes and components
- Use virtual scrolling for long lists
- Debounce search inputs
- Optimize images (use sprites for monsters)

### Backend
- Use eager loading to avoid N+1 queries
- Add database indexes for frequently queried columns
- Cache expensive calculations
- Use pagination for large datasets
- Profile slow queries with Nextcloud's query logger

## Troubleshooting

### App not showing in Nextcloud

1. Check if enabled: `php occ app:list`
2. Enable: `php occ app:enable chorewars`
3. Check logs: `tail -f data/nextcloud.log`

### Frontend not updating

1. Clear browser cache
2. Rebuild: `npm run build`
3. Check webpack errors in console

### Database errors

1. Check migration status: `php occ migrations:status chorewars`
2. Run migrations: `php occ migrations:migrate chorewars`
3. Check database connection in Nextcloud config

### Permission errors

1. Check file permissions: `chown -R www-data:www-data /path/to/nextcloud/apps/chorewars`
2. Check Nextcloud permissions in admin panel

## Git Workflow

### Branch naming
- `feature/task-templates`
- `fix/monster-spawn-bug`
- `docs/api-documentation`

### Commit messages
```
feat: Add monster spawn animation
fix: Correct point calculation for combo tasks
docs: Update API documentation
refactor: Simplify room state calculation
test: Add unit tests for TaskScheduler
i18n: Add German translations
```

### Pull requests
1. Create feature branch from `develop`
2. Make changes and commit
3. Push to your fork
4. Open PR against `develop`
5. Wait for review and CI checks
6. Merge when approved

## CI/CD

GitHub Actions workflows in `.github/workflows/`:

- **lint.yml**: Runs linters on every push
- **test.yml**: Runs tests on every PR
- **build.yml**: Builds app for releases

## Resources

- [Nextcloud Developer Docs](https://docs.nextcloud.com/server/latest/developer_manual/)
- [Vue.js 3 Docs](https://vuejs.org/)
- [Nextcloud Vue Components](https://nextcloud-vue-components.netlify.app/)
- [PSR-12 Standard](https://www.php-fig.org/psr/psr-12/)

## Getting Help

- Open an issue on GitHub
- Check existing documentation
- Ask in Nextcloud developer forums

---

Happy coding! 🎮✨
