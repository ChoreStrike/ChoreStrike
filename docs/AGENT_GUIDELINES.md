# Agent Guidelines für ChoreStrike Entwicklung

Dieses Dokument definiert, wie AI-Agents bei der Entwicklung von ChoreStrike agieren sollen.

## 🎯 Projekt-Kontext

**ChoreStrike** ist eine Open-Source Nextcloud-Erweiterung für gamifiziertes Haushaltsaufgaben-Management.

### Kernprinzipien
1. **Open Source First**: Alles ist öffentlich (MIT Lizenz)
2. **Privacy**: Keine externen Dienste, alles lokal in Nextcloud
3. **Motivieren, nicht bestrafen**: Positive Gamification
4. **Erwachsenentauglich**: Professionelles Design trotz Spielmechanik
5. **Mehrsprachigkeit**: Von Anfang an i18n-ready

## 📁 Projekt-Struktur

### Public vs. Private Trennung

```
ChoreStrike/
├── public/              # Alles was ins Repository kommt
│   ├── src/            # Quellcode
│   ├── docs/           # Dokumentation
│   ├── l10n/           # Übersetzungen
│   └── tests/          # Tests
└── private/            # Lokale Entwicklung (gitignored)
    ├── config.local.php
    ├── test-data/
    └── notes/
```

**Wichtig**: Agents dürfen NIEMALS private Daten (API-Keys, Passwörter, persönliche Notizen) in public-Bereiche schreiben.

## 🤖 Agent-Verhalten

### 1. Code-Generierung

#### DO ✅
- Nextcloud App API Standards befolgen
- Vue.js 3 Composition API verwenden
- TypeScript für bessere Type-Safety nutzen (wo möglich)
- PSR-12 für PHP Code
- Kommentare in Englisch schreiben
- i18n-Strings für alle UI-Texte verwenden
- Accessibility (a11y) beachten
- Performance optimieren (lazy loading, etc.)
- Use structured logging with shared backend/frontend helpers

#### DON'T ❌
- Keine hardcodierten Strings in UI-Komponenten
- Keine externen API-Calls ohne Zustimmung
- Keine Inline-Styles (CSS-Klassen verwenden)
- Keine console.log() in Production-Code
- Keine sensiblen Daten in Code committen
- Do not log tokens, secrets, cookies, or full request payloads

### Backend
```php
// Shared application logging
$this->appLogger->debug('Task creation flow started', [
    'service' => 'TaskService',
    'taskId' => $task->getId(),
]);

$this->appLogger->logException('Task controller action failed', $e, [
    'controller' => 'TaskController',
    'action' => 'create',
    'taskId' => $task->getId(),
], $this->request);

// Adjust Nextcloud log level in config/config.php when needed
```

### Logging Rules

- Backend must use `AppLogger` for structured Nextcloud logging
- Frontend must use `src/utils/logger.js` instead of raw `console.*`
- Log unexpected request-boundary exceptions once with stable context keys
- Log authorization failures as warning-level security events
- Keep messages short, stable, and in English
- Follow `docs/standards/LOGGING.md` for level selection and redaction rules

### 2. Datenbankdesign

#### Prinzipien
- Nextcloud DB Abstraction Layer verwenden
- Migrations für Schema-Änderungen
- Indizes für häufige Queries
- Foreign Keys für Integrität
- Soft Deletes wo sinnvoll

#### Beispiel Migration
```php
public function changeSchema(IOutput $output, Closure $schemaClosure, array $options) {
    /** @var ISchemaWrapper $schema */
    $schema = $schemaClosure();
    
    if (!$schema->hasTable('ChoreStrike_rooms')) {
        $table = $schema->createTable('ChoreStrike_rooms');
        $table->addColumn('id', Types::BIGINT, [
            'autoincrement' => true,
            'notnull' => true,
        ]);
        $table->addColumn('name', Types::STRING, [
            'notnull' => true,
            'length' => 200,
        ]);
        // ... weitere Felder
        $table->setPrimaryKey(['id']);
    }
    
    return $schema;
}
```

### 3. Frontend-Entwicklung

#### Vue.js Komponenten-Struktur
```vue
<template>
  <!-- Template -->
</template>

<script>
export default {
  name: 'ComponentName',
  props: {
    // Props mit Validierung
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
- Pinia für globalen State (Nextcloud Standard)
- Props/Emits für Komponenten-Kommunikation
- Provide/Inject für tief verschachtelte Daten

### 4. Gamification-Logik

#### Monster-Spawn-Algorithmus
```javascript
function calculateMonsterCount(task) {
  const now = Date.now();
  const dueDate = task.next_due;
  const overdueDays = Math.max(0, (now - dueDate) / (1000 * 60 * 60 * 24));
  
  // Basis: Schwierigkeit der Aufgabe
  const baseMonsters = task.difficulty;
  
  // Multiplikator basierend auf Priorität
  const priorityMultiplier = {
    1: 2.0,   // Hoch
    2: 1.0,   // Normal
    3: 0.5    // Niedrig
  }[task.priority];
  
  // Exponentielles Wachstum, aber gedeckelt
  const monsters = Math.min(
    baseMonsters * priorityMultiplier * Math.pow(1.5, overdueDays),
    20  // Max 20 Monster pro Raum
  );
  
  return Math.floor(monsters);
}
```

#### Punkte-Berechnung
```javascript
function calculatePoints(task, wasCombo = false) {
  const basePoints = task.difficulty * 10;
  const priorityBonus = {
    1: 1.5,   // Hoch: +50%
    2: 1.0,   // Normal: +0%
    3: 0.8    // Niedrig: -20%
  }[task.priority];
  
  const comboMultiplier = wasCombo ? 1.5 : 1.0;
  
  return Math.round(basePoints * priorityBonus * comboMultiplier);
}
```

### 5. Internationalisierung

#### String-Extraktion
```javascript
// Vue Component
import { translate as t } from '@nextcloud/l10n'

export default {
  setup() {
    const welcomeText = t('ChoreStrike', 'Welcome to ChoreStrike!')
    const taskCount = (n) => n('ChoreStrike', 
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

### 7. Performance-Richtlinien

#### Frontend
- Lazy Loading für Routen
- Virtual Scrolling für lange Listen
- Debouncing für Search/Filter
- Image Sprites für Monster
- CSS Animations statt JS (wo möglich)

#### Backend
- Query-Optimierung (N+1 vermeiden)
- Caching für häufige Abfragen
- Batch-Operations
- Pagination für große Datensätze

### 8. Security

#### Input Validation
```php
// Immer validieren
public function createRoom(string $name, int $floorId) {
    if (strlen($name) < 1 || strlen($name) > 200) {
        throw new ValidationException('Invalid room name');
    }
    
    // Prüfen ob Floor existiert und User Zugriff hat
    $floor = $this->floorMapper->find($floorId);
    if (!$this->hasAccess($floor)) {
        throw new ForbiddenException();
    }
    
    // ...
}
```

#### SQL Injection Prevention
```php
// NIEMALS direkte String-Konkatenation
// ❌ BAD
$sql = "SELECT * FROM rooms WHERE name = '" . $name . "'";

// ✅ GOOD - Query Builder verwenden
$qb = $this->db->getQueryBuilder();
$qb->select('*')
   ->from('ChoreStrike_rooms')
   ->where($qb->expr()->eq('name', $qb->createNamedParameter($name)));
```

### 9. Dokumentation

#### Code-Kommentare
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
- Neue Features dokumentieren
- Breaking Changes hervorheben
- Migration-Guides bei API-Änderungen

### 10. Git Workflow

#### Commit Messages
```
feat: Add monster spawn animation
fix: Correct point calculation for combo tasks
docs: Update API documentation
refactor: Simplify room state calculation
test: Add unit tests for TaskScheduler
i18n: Add German translations for settings
```

Format: `<type>: <description>`

Types:
- `feat`: Neue Features
- `fix`: Bugfixes
- `docs`: Dokumentation
- `refactor`: Code-Umstrukturierung
- `test`: Tests
- `i18n`: Übersetzungen
- `perf`: Performance-Verbesserungen
- `chore`: Maintenance

#### Branch-Strategie
- `main`: Stable releases
- `develop`: Development branch
- `feature/*`: Feature branches
- `fix/*`: Bugfix branches

## 🎨 Design-Entscheidungen

### Wann welche Technologie?

#### Animationen
- **CSS Transitions**: Einfache State-Changes (hover, active)
- **CSS Animations**: Loops, einfache Sequenzen
- **Vue Transitions**: Component mount/unmount
- **Canvas/WebGL**: Komplexe Monster-Animationen (viele Sprites)

#### State Management
- **Component State**: UI-only State
- **Pinia Store**: App-weiter State (tasks, rooms, user)
- **Backend**: Source of Truth für alle Daten

### Accessibility Checklist
- [ ] Keyboard-Navigation funktioniert
- [ ] Screen-Reader Labels vorhanden
- [ ] Kontrast-Verhältnisse eingehalten (WCAG AA)
- [ ] Focus-Indikatoren sichtbar
- [ ] ARIA-Attribute wo nötig

## 🔄 Iterativer Entwicklungsprozess

### Bei neuen Features

1. **Konzept klären**
   - Welches Problem löst es?
   - Wie passt es zur Gesamt-Vision?
   - Gibt es einfachere Lösungen?

2. **Design entwerfen**
   - UI-Mockup (kann einfach sein)
   - Datenmodell
   - API-Endpoints

3. **Implementieren**
   - Backend zuerst (API)
   - Frontend danach
   - Tests parallel

4. **Review**
   - Code-Qualität
   - Performance
   - UX
   - i18n vollständig?

5. **Dokumentieren**
   - Code-Kommentare
   - API-Docs
   - User-Docs (wenn nötig)

## 🐛 Debugging-Strategie

### Frontend
```javascript
// Development-Mode Logging
if (process.env.NODE_ENV === 'development') {
  console.log('[ChoreStrike]', debugInfo)
}

// Vue DevTools nutzen
// Browser Console für Network/Performance
```

### Backend
```php
// Nextcloud Logging
$this->logger->debug('Task created', ['taskId' => $task->getId()]);
$this->logger->error('Failed to save task', ['exception' => $e]);

// Log-Level in config.php anpassen
```

## 📊 Metriken & Monitoring

### Was tracken?
- Anzahl aktiver Häuser/User
- Durchschnittliche Tasks pro Haus
- Completion-Rate
- Beliebte Task-Typen
- Performance-Metriken (Load-Times)

**Wichtig**: Nur anonymisierte, aggregierte Daten. Keine User-Tracking.

## 🚀 Release-Prozess

### Versioning (Semantic Versioning)
- `MAJOR.MINOR.PATCH`
- `1.0.0`: Erste stabile Version
- `1.1.0`: Neue Features (backwards compatible)
- `1.1.1`: Bugfixes
- `2.0.0`: Breaking Changes

### Changelog pflegen
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

## 🤝 Zusammenarbeit mit Humans

### Wann nachfragen?
- Unklare Requirements
- Design-Entscheidungen (UX)
- Breaking Changes
- Sicherheits-relevante Änderungen
- Lizenz-Fragen

### Wann selbstständig entscheiden?
- Code-Struktur (wenn Best Practices klar)
- Bug-Fixes (offensichtliche Fehler)
- Performance-Optimierungen
- Test-Coverage erhöhen
- Refactoring (ohne Behavior-Change)

## 📚 Ressourcen

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

**Wichtigste Regel**: Wenn unsicher → nachfragen! Lieber einmal zu viel kommunizieren als eine falsche Annahme treffen. 🤖✨
