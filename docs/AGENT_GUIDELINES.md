# Agent Guidelines for ChoreWars Development

This document defines how AI agents should operate while building ChoreWars.

## 🎯 Project Context

**ChoreWars** is an open-source Nextcloud extension for gamified household task management.

### Core Principles
1. **Open Source First**: Everything is public (MIT license).
2. **Privacy**: No external services, all data stays in Nextcloud.
3. **Motivate, do not punish**: Positive gamification.
4. **Family-friendly design**: Playful but professional experience.
5. **Multilingual by default**: i18n-ready from day one.

## Project Structure

### Public vs. Private Separation

```
ChoreWars/
├── public/              # Everything committed to the repository
│   ├── src/            # Source code
│   ├── docs/           # Documentation
│   ├── l10n/           # Translations
│   └── tests/          # Tests
└── private/            # Local development data (gitignored)
    ├── config.local.php
    ├── test-data/
    └── notes/
```

**Important**: Agents must NEVER write private data (API keys, passwords, personal notes) into public areas.

## Agent Behavior

### 1. Code Generation

#### DO ✅
- Follow Nextcloud App API standards
- Use Vue.js 3 Composition API
- Use TypeScript for stronger type safety where practical
- Follow PSR-12 for PHP code
- Write comments in English
- Use i18n strings for all UI text
- Consider accessibility (a11y)
- Optimize performance (lazy loading, etc.)

#### DON'T ❌
- No hardcoded strings in UI components
- No external API calls without explicit approval
- No inline styles (use CSS classes)
- No console.log() in production code
- No sensitive data in commits

### 2. Database Design

#### Principles
- Use Nextcloud DB abstraction layer
- Use migrations for schema changes
- Add indexes for frequent queries
- Use foreign keys for data integrity
- Use soft deletes where appropriate

#### Example Migration
```php
public function changeSchema(IOutput $output, Closure $schemaClosure, array $options) {
    /** @var ISchemaWrapper $schema */
    $schema = $schemaClosure();
    
    if (!$schema->hasTable('chorewars_rooms')) {
        $table = $schema->createTable('chorewars_rooms');
        $table->addColumn('id', Types::BIGINT, [
            'autoincrement' => true,
            'notnull' => true,
        ]);
        $table->addColumn('name', Types::STRING, [
            'notnull' => true,
            'length' => 200,
        ]);
        // ... more fields
        $table->setPrimaryKey(['id']);
    }
    
    return $schema;
}
```

### 3. Frontend Development

#### Vue.js Component Structure
```vue
<template>
  <!-- Template -->
</template>

<script>
export default {
  name: 'ComponentName',
  props: {
    // Props with validation
  },
  emits: ['event-name'],
  setup(props, { emit }) {
    // Composition API
  }
}
</script>

<style scoped>
/* Scoped styles */
</style>
```

#### State Management
- Use Pinia for global state (Nextcloud standard)
- Use props/emits for component communication
- Use provide/inject for deeply nested data

### 4. Gamification Logic

#### Monster Spawn Algorithm
```javascript
function calculateMonsterCount(task) {
  const now = Date.now();
  const dueDate = task.next_due;
  const overdueDays = Math.max(0, (now - dueDate) / (1000 * 60 * 60 * 24));
  
  // Base value: task energy cost
  const baseMonsters = task.energy_cost;
  
  // Multiplier based on priority
  const priorityMultiplier = {
    1: 2.0,
    2: 1.0,
    3: 0.5
  }[task.priority];
  
  // Exponential growth with a cap
  const monsters = Math.min(
    baseMonsters * priorityMultiplier * Math.pow(1.5, overdueDays),
    20  // Maximum 20 monsters per room
  );
  
  return Math.floor(monsters);
}
```

#### Reward Calculation
```javascript
function calculateRewards(task, wasCombo = false) {
  const baseContribution = task.energy_cost * 5;
  const baseTeamSparks = task.energy_cost * 10;
  const priorityBonus = {
    1: 1.5,
    2: 1.0,
    3: 0.8
  }[task.priority];
  
  const comboMultiplier = wasCombo ? 1.5 : 1.0;
  
  return {
    contributionScore: Math.round(baseContribution * priorityBonus),
    teamSparks: Math.round(baseTeamSparks * priorityBonus * comboMultiplier)
  };
}
```

### 5. Internationalization

#### String Extraction
```javascript
// Vue Component
import { translate as t } from '@nextcloud/l10n'

export default {
  setup() {
    const welcomeText = t('chorewars', 'Welcome to ChoreWars!')
    const taskCount = (n) => n('chorewars', 
      '%n task', 
      '%n tasks', 
      count
    )
  }
}
```

#### PHP Backend
```php
use OCP\IL10N;

class TaskController extends Controller {
    private IL10N $l;
    
    public function __construct($appName, IRequest $request, IL10N $l) {
        parent::__construct($appName, $request);
        $this->l = $l;
    }
    
    public function create() {
        $message = $this->l->t('Task created successfully');
    }
}
```

### 6. Testing

#### Unit Tests (PHP)
```php
use PHPUnit\Framework\TestCase;

class TaskServiceTest extends TestCase {
    public function testCalculateNextDue() {
        $service = new TaskService();
        $task = new Task();
        $task->setFrequencyType('daily');
        
        $nextDue = $service->calculateNextDue($task);
        
        $this->assertInstanceOf(\DateTime::class, $nextDue);
    }
}
```

#### Component Tests (Vue)
```javascript
import { mount } from '@vue/test-utils'
import TaskItem from './TaskItem.vue'

describe('TaskItem', () => {
  it('emits complete event when clicked', async () => {
    const wrapper = mount(TaskItem, {
      props: { task: mockTask }
    })
    
    await wrapper.find('.complete-btn').trigger('click')
    
    expect(wrapper.emitted('complete')).toBeTruthy()
  })
})
```

### 7. Performance Guidelines

#### Frontend
- Lazy loading for routes
- Virtual scrolling for long lists
- Debouncing for search/filter
- Image sprites for monsters
- Prefer CSS animations over JS where possible

#### Backend
- Query optimization (avoid N+1)
- Caching for frequent lookups
- Batch operations
- Pagination for large datasets

### 8. Security

#### Input Validation
```php
// Always validate
public function createRoom(string $name, int $floorId) {
    if (strlen($name) < 1 || strlen($name) > 200) {
        throw new ValidationException('Invalid room name');
    }
    
    // Check that floor exists and user has access
    $floor = $this->floorMapper->find($floorId);
    if (!$this->hasAccess($floor)) {
        throw new ForbiddenException();
    }
    
    // ...
}
```

#### SQL Injection Prevention
```php
// NEVER use direct string concatenation
// ❌ BAD
$sql = "SELECT * FROM rooms WHERE name = '" . $name . "'";

// GOOD - use query builder
$qb = $this->db->getQueryBuilder();
$qb->select('*')
   ->from('chorewars_rooms')
   ->where($qb->expr()->eq('name', $qb->createNamedParameter($name)));
```

### 9. Documentation

#### Code Comments
```php
/**
 * Calculate the next due date for a task based on its frequency
 * 
 * @param Task $task The task to calculate for
 * @param \DateTime|null $fromDate Starting point (default: now)
 * @return \DateTime The next due date
 * @throws InvalidFrequencyException If frequency type is invalid
 */
public function calculateNextDue(Task $task, ?\DateTime $fromDate = null): \DateTime {
    // Implementation
}
```

#### README Updates
- Document new features
- Highlight breaking changes
- Add migration guides for API changes

### 10. Git Workflow

#### Commit Messages
```
feat: Add monster spawn animation
fix: Correct reward calculation for combo tasks
docs: Update API documentation
refactor: Simplify room state calculation
test: Add unit tests for TaskScheduler
i18n: Add German translations for settings
```

Format: `<type>: <description>`

Types:
- `feat`: New features
- `fix`: Bug fixes
- `docs`: Documentation
- `refactor`: Code restructuring
- `test`: Tests
- `i18n`: Translations
- `perf`: Performance improvements
- `chore`: Maintenance

#### Branch Strategy
- `main`: Stable releases
- `develop`: Development branch
- `feature/*`: Feature branches
- `fix/*`: Bugfix branches

## Design Decisions

### When to Use Which Technology

#### Animations
- **CSS Transitions**: Simple state changes (hover, active)
- **CSS Animations**: Loops and simple sequences
- **Vue Transitions**: Component mount/unmount
- **Canvas/WebGL**: Complex monster animations (many sprites)

#### State Management
- **Component State**: UI-only State
- **Pinia Store**: App-wide state (tasks, rooms, users)
- **Backend**: Source of truth for all persistent data

### Accessibility Checklist
- [ ] Keyboard navigation works
- [ ] Screen reader labels are present
- [ ] Contrast ratios meet WCAG AA
- [ ] Focus indicators are visible
- [ ] ARIA attributes are used where needed

## Iterative Development Process

### For New Features

1. **Clarify the concept**
   - Which problem does it solve?
   - How does it fit the overall vision?
   - Is there a simpler solution?

2. **Draft the design**
   - UI mockup (can be simple)
   - Data model
   - API endpoints

3. **Implement**
   - Backend first (API)
   - Frontend after backend contract is stable
   - Tests in parallel

4. **Review**
   - Code quality
   - Performance
   - UX
   - i18n complete?

5. **Document**
   - Code comments
   - API docs
   - User docs where needed

## Debugging Strategy

### Frontend
```javascript
// Development-Mode Logging
if (process.env.NODE_ENV === 'development') {
  console.log('[ChoreWars]', debugInfo)
}

// Use Vue DevTools
// Use browser console for network/performance
```

### Backend
```php
// Nextcloud Logging
$this->logger->debug('Task created', ['taskId' => $task->getId()]);
$this->logger->error('Failed to save task', ['exception' => $e]);

// Adjust log level in config.php
```

## Metrics and Monitoring

### What to track
- Number of active houses/users
- Average tasks per house
- Completion rate
- Most used task types
- Performance metrics (load times)

**Important**: Only anonymized, aggregated data. No user-level tracking.

## Release Process

### Versioning (Semantic Versioning)
- `MAJOR.MINOR.PATCH`
- `1.0.0`: First stable release
- `1.1.0`: New features (backward compatible)
- `1.1.1`: Bug fixes
- `2.0.0`: Breaking Changes

### Maintain Changelog
```markdown
## [1.1.0] - 2026-03-15

### Added
- Multi-room task support
- Combo bonus system
- German translation

### Fixed
- Monster spawn calculation bug
- Mobile layout issues

### Changed
- Improved task scheduling algorithm
```

## Collaboration with Humans

### When to ask
- Unclear requirements
- UX/design decisions
- Breaking changes
- Security-relevant changes
- License questions

### When to decide autonomously
- Code structure when best practices are clear
- Obvious bug fixes
- Performance optimizations
- Improve test coverage
- Refactoring without behavior changes

## Resources

### Nextcloud Development
- [Nextcloud App Tutorial](https://docs.nextcloud.com/server/latest/developer_manual/app_development/tutorial.html)
- [Nextcloud App API](https://docs.nextcloud.com/server/latest/developer_manual/app_publishing_maintenance/app_upgrade_guide/)

### Vue.js
- [Vue 3 Documentation](https://vuejs.org/)
- [Nextcloud Vue Components](https://nextcloud-vue-components.netlify.app/)

### Best Practices
- [PSR-12 Coding Standard](https://www.php-fig.org/psr/psr-12/)
- [WCAG Accessibility Guidelines](https://www.w3.org/WAI/WCAG21/quickref/)

---

**Most important rule**: If unsure, ask. Prefer one extra clarification over one wrong assumption. 🤖✨
