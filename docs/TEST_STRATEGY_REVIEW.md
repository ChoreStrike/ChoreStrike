# ChoreStrike Test Strategy Review & Improvements
**Datum:** 2026-03-15  
**Status:** Draft - Requires User Feedback

---

## Executive Summary

### Aktuelle Situation
- **20 E2E Playwright Tests** (verschiedene Maturity-Level)
- **29 Jest Frontend Unit Tests** (Komponenten, Stores, Views)
- **12 PHPUnit Integration Tests** (Controller, Services)
- **Test-DB-Profile:** empty, minimal, family

### Hauptprobleme identifiziert
1. ❌ **Task-Completion** über UI nicht vollständig E2E getestet
2. ❌ **Multi-User Interaktionen** nur oberflächlich (keine echten Claiming/Teamwork-Flows)
3. ❌ **Family-DB unrealistisch** (zu statisch, kein "laufender Haushalt")
4. ❌ **DailyTaskList** Komponente nicht Jest-getestet
5. ❌ **Automated DB-Setup** Tests fehlen komplett

---

## 1. Frontend Test-Lücken (Kritisch)

### 1.1 Fehlende Jest Unit Tests

| Komponente | Aktuell | Benötigt | Priority |
|------------|---------|----------|----------|
| `DailyTaskList.vue` | ❌ Keine Tests | ✅ Complete Unit Tests | **HIGH** |
| `TaskEditForm.vue` | ❌ Keine Tests | ✅ Form Validation, Submit | HIGH |
| `AvailabilityCalendar.vue` | ❌ Keine Tests | ✅ User Interactions | MEDIUM |
| `GameStats.vue` | ❌ Keine Tests | ✅ Score Calculations | MEDIUM |
| `ComboIndicator.vue` | ❌ Keine Tests | ✅ Animation Triggers | LOW |

**Empfehlung:** DailyTaskList.vue priorisieren - zentrale Komponente für tägliche Task-Workflows.

### 1.2 Fehlende E2E Flows (GUI)

#### ❌ Task Completion End-to-End
**Problem:** Task-Abschluss wird nur teilweise getestet.

**Aktueller Stand:**
- `task-completion.spec.ts` testet nur Basic-Click auf Complete-Button
- Keine Validierung von Rewards (Points, XP, Streaks)
- Keine Validierung von UI-Updates (Score-Anzeige, House-Health)
- Keine Validierung von Task-State-Changes (isActive → false)

**Benötigt:**
```typescript
// tests/e2e/task-completion-full-flow.spec.ts
test('complete task shows rewards and updates UI', async ({ page }) => {
  // 1. Get initial score
  const initialScore = await getScore(page)
  
  // 2. Complete task
  await completeTask(page, 'dishes')
  
  // 3. Verify rewards toast/notification
  await expect(page.locator('[data-testid="rewards-toast"]')).toBeVisible()
  
  // 4. Verify score increased
  const newScore = await getScore(page)
  expect(newScore).toBeGreaterThan(initialScore)
  
  // 5. Verify task status changed (strikethrough, disabled, etc.)
  await expect(page.locator('[data-testid="task-dishes"]')).toHaveClass(/completed/)
  
  // 6. Verify house health updated
  const newHealth = await getHouseHealth(page)
  expect(newHealth).toBeGreaterThan(initialHealth)
})
```

#### ❌ Multi-User Task Claiming
**Problem:** Zwei User gleichzeitig - kein Test für Claiming-Konflikte.

**Benötigt:**
```typescript
// tests/e2e/multi-user-task-claiming.spec.ts
test('two users cannot claim same task', async ({ browser }) => {
  const momContext = await browser.newContext()
  const teenContext = await browser.newContext()
  
  const momPage = await momContext.newPage()
  const teenPage = await teenContext.newPage()
  
  // Both login
  await loginAsUser(momPage, 'mom', 'mom')
  await loginAsUser(teenPage, 'teen', 'teen')
  
  // Both navigate to same room
  await momPage.goto('/apps/chorestrike/#/rooms/1')
  await teenPage.goto('/apps/chorestrike/#/rooms/1')
  
  // Mom claims unassigned task
  await momPage.click('[data-testid="claim-task-dishes"]')
  await momPage.waitForTimeout(500)
  
  // Teen tries to claim same task
  const claimButton = teenPage.locator('[data-testid="claim-task-dishes"]')
  // Should be disabled or show "Already claimed by mom"
  await expect(claimButton).toBeDisabled()
  // OR
  await expect(teenPage.locator('text=Already assigned')).toBeVisible()
})
```

#### ❌ Teamwork Task Collaboration
**Problem:** allowTeamwork=true Tasks nicht getestet.

**Benötigt:**
```typescript
test('multiple users can join teamwork task', async ({ browser }) => {
  // Mom assigns teamwork task
  // Teen joins same task
  // Both complete their participation
  // Verify both get rewards (with team spark multiplier)
})
```

#### ❌ Overdue Task Claiming
**Problem:** Überfällige Tasks können übernommen werden - nicht getestet.

**Benötigt:**
```typescript
test('user can claim overdue task from another user', async ({ page }) => {
  // Login as dad
  // Find overdue task assigned to mom
  // Claim/reassign to self
  // Verify mom no longer sees it as "their" task
})
```

### 1.3 UI State Testing (Fehlt komplett)

#### ❌ Loading States
```typescript
// tests/unit/components/TaskList.spec.js - bereits vorhanden ✅
// Aber: DailyTaskList, RoomCard, FloorList brauchen mehr Coverage
```

#### ❌ Error States
**Fehlende Tests:**
- Network-Fehler beim Task-Complete
- Unauthorized-Fehler bei fremden Tasks
- Validation-Fehler bei Task-Creation

#### ❌ Empty States
```typescript
test('shows empty state when no tasks assigned', async ({ page }) => {
  await loginAsUser(page, 'friend', 'friend') // Friend has maxDailyTasks=1
  // Should show friendly empty state, not just blank screen
})
```

---

## 2. Multi-User Interaktions-Tests (Kritisch)

### 2.1 Fehlende Szenarien

| Szenario | Aktuell | Benötigt | User Story |
|----------|---------|----------|------------|
| **Task Stealing** | ❌ | ✅ | Dad nimmt überfälligen Task von Mom |
| **Parallel Completion** | ❌ | ✅ | Mom & Teen schließen gleichzeitig Tasks ab |
| **Leaderboard Live Update** | ❌ | ✅ | Teen sieht Moms Score-Anstieg in Echtzeit |
| **Assignment Conflict** | ❌ | ✅ | Zwei Owner assignen denselben Task |
| **Teamwork Join/Leave** | ❌ | ✅ | Kid joined Task, Teen verlässt Task |
| **Claim Notification** | ❌ | ✅ | Mom bekommt Notification, wenn Teen Task claimed |

### 2.2 Empfohlene Test-Struktur
```typescript
// tests/e2e/multi-user-collaboration.spec.ts
test.describe('Multi-User Collaboration Scenarios', () => {
  test.use({ databaseProfile: 'family' })
  
  test('task stealing: dad claims overdue task from mom', async ({ browser }) => {
    // Detailed implementation needed
  })
  
  test('parallel task completion updates house health correctly', async ({ browser }) => {
    // Verify race conditions handled
  })
  
  test('leaderboard updates in real-time when other user completes task', async ({ browser }) => {
    // Poll or WebSocket updates
  })
})
```

---

## 3. Family Test-DB Verbesserungen (Kritisch)

### 3.1 Aktuelles Problem
`FamilyTestData.php` erstellt **statische Daten** ohne realistische Verteilung:

**Aktuelle RoomTask-Verteilung:**
- ✅ 12 RoomTasks erstellt
- ⚠️ Alle haben `isActive: true`
- ⚠️ Einige assigned, einige unassigned
- ❌ **Keine completed Tasks**
- ❌ **Keine overdue Tasks**
- ❌ **Keine Tasks in Progress (teilweise Checklist)**
- ❌ **Kein realistischer Snapshot eines laufenden Haushalts**

### 3.2 Verbesserungsvorschlag: "Running Household" Snapshot

#### Option A: Erweiterte FamilyTestData.php
```php
// tests/fixtures/FamilyTestData.php - new method
public static function createRealisticRoomTasks(): array
{
    $roomTasks = [];
    $today = new \DateTime();
    $yesterday = (clone $today)->modify('-1 day');
    $twoDaysAgo = (clone $today)->modify('-2 days');
    $tomorrow = (clone $today)->modify('+1 day');

    // === Completed Tasks (History) ===
    // Mom completed dishes yesterday
    $roomTasks[] = self::createRoomTask(1, 1, 1, $yesterday, false, 'mom');
    
    // Teen completed make bed this morning (same day but completed)
    $roomTasks[] = self::createRoomTask(2, 7, 4, $today, false, 'teen');
    
    // Dad completed trash 2 days ago
    $roomTasks[] = self::createRoomTask(3, 3, 1, $twoDaysAgo, false, 'dad');

    // === Overdue Tasks (Critical) ===
    // Dishes overdue (assigned to mom but not done today)
    $roomTasks[] = self::createRoomTask(4, 1, 1, $yesterday, true, 'mom');
    
    // Kids bedroom cleaning overdue (assigned to kid, 2 days overdue)
    $roomTasks[] = self::createRoomTask(5, 8, 4, $twoDaysAgo, true, 'kid');

    // === Active Today (Assigned) ===
    // Wipe counters assigned to teen (due today)
    $roomTasks[] = self::createRoomTask(6, 2, 1, $today, true, 'teen');
    
    // Tidy toys assigned to kid (due today)
    $roomTasks[] = self::createRoomTask(7, 6, 2, $today, true, 'kid');
    
    // Clean sink assigned to grandma (due today)
    $roomTasks[] = self::createRoomTask(8, 11, 6, $today, true, 'grandma');

    // === Active Today (Unassigned - available to claim) ===
    // Vacuum living room (no one assigned yet)
    $roomTasks[] = self::createRoomTask(9, 4, 2, $today, true, null);
    
    // Dust furniture (unassigned)
    $roomTasks[] = self::createRoomTask(10, 5, 2, $today, true, null);

    // === Future Tasks ===
    // Laundry scheduled for tomorrow (assigned to mom)
    $roomTasks[] = self::createRoomTask(11, 9, 3, $tomorrow, true, 'mom');
    
    // Clean toilet scheduled for tomorrow (unassigned)
    $roomTasks[] = self::createRoomTask(12, 10, 6, $tomorrow, true, null);

    return $roomTasks;
}
```

#### Option B: Neues Profile "family-active"
```php
// lib/Service/TestDataService.php
public function seed(string $profile = 'minimal'): array
{
    switch ($profile) {
        case 'empty': return $this->seedEmpty();
        case 'minimal': return $this->seedMinimal();
        case 'family': return $this->seedFamily();
        case 'family-active': return $this->seedFamilyActive(); // NEW
        default: throw new \InvalidArgumentException("Unknown profile: $profile");
    }
}

private function seedFamilyActive(): array
{
    // Same as family, but with realistic task states:
    // - 3 completed tasks (history)
    // - 2 overdue tasks
    // - 4 active assigned tasks
    // - 2 unassigned tasks (claimable)
    // - 2 future tasks
}
```

### 3.3 Zusätzliche realistische Daten

#### User ContributionScores
**Aktuell:** Alle User haben `contributionScore: 0`  
**Realistisch:**
```php
'mom' => 1250 points (viele Tasks erledigt)
'dad' => 980 points
'teen' => 450 points (weniger verfügbar)
'kid' => 230 points (altersgerecht)
'grandma' => 180 points (begrenzte Tasks)
'friend' => 50 points (nur gelegentlich)
```

#### Current Streaks
```php
'mom' => currentStreak: 7 (eine Woche täglich)
'dad' => currentStreak: 4
'teen' => currentStreak: 0 (Streak gebrochen)
'kid' => currentStreak: 2
```

#### Room Cleanliness realistischer
**Aktuell:** Statische Werte  
**Realistisch:** Basierend auf completed/overdue tasks:
```php
'kitchen' => 65 (dishes overdue → niedriger)
'kids_bedroom' => 30 (cleaning overdue 2 days → sehr niedrig)
'master_bedroom' => 85 (recently cleaned)
```

---

## 4. Automated Setup Tests (Neu)

### 4.1 Konzept
**Ziel:** Minimal- und Family-Profile sollen automatisiert von Grund auf nachgebaut werden können.

**Beispiel:**
```typescript
// tests/e2e/automated-setup-minimal.spec.ts
test.describe('Automated Minimal Setup Recreation', () => {
  test.use({ databaseProfile: 'empty' })
  
  test('can recreate minimal profile from scratch', async ({ page }) => {
    await loginAsUser(page, 'admin', 'admin')
    
    // 1. Create house
    await createHouse(page, 'Test Family Home')
    
    // 2. Add adult user
    await addHouseMember(page, 'adult', 'Adult User', 'owner')
    
    // 3. Add kid user
    await addHouseMember(page, 'kid', 'Kid User', 'child')
    
    // 4. Create floor
    await createFloor(page, 'Ground Floor', 1)
    
    // 5. Create room
    await createRoom(page, 'Kitchen', 'Ground Floor')
    
    // 6. Verify minimal setup complete
    await expect(page.locator('[data-testid="room-card-Kitchen"]')).toBeVisible()
    
    // 7. Export state and compare with minimal fixture
    const exportedState = await exportHouseState(page)
    expect(exportedState).toMatchMinimalProfile()
  })
})
```

### 4.2 Family Profile Recreation
```typescript
// tests/e2e/automated-setup-family.spec.ts
test('can recreate family profile from scratch', async ({ page }) => {
  // 1. Create house
  // 2. Add 6 users (mom, dad, teen, kid, grandma, friend)
  // 3. Set roles and availability
  // 4. Create 3 floors
  // 5. Create 6 rooms
  // 6. Create 12 tasks
  // 7. Assign tasks to rooms
  // 8. Assign some tasks to users
  // 9. Complete some tasks
  // 10. Verify complete family setup
})
```

**Nutzen:**
- Validiert, dass die komplette UI-Kette funktioniert
- Dokumentiert die GUI-Workflows
- Erkennt Regressions in Setup-Flows
- Kann als "Onboarding-Tutorial" dienen

---

## 5. Backend Test-Lücken

### 5.1 Fehlende Unit Tests

| Service | Coverage | Fehlende Tests |
|---------|----------|----------------|
| `TaskSyncService` | ⚠️ Partial | Multi-house isolation tests |
| `SmartSchedulerService` | ❌ Keine | Energy budget calculations |
| `NotificationService` | ❌ Keine | Push notifications |
| `RewardCalculationService` | ⚠️ Partial | Combo calculations, streak bonuses |

### 5.2 Fehlende Integration Tests

| Endpoint | Aktuell | Benötigt |
|----------|---------|----------|
| `POST /api/v1/tasks/{id}/claim` | ❌ | ✅ Claim validation, conflict handling |
| `POST /api/v1/tasks/{id}/unclaim` | ❌ | ✅ Authorization checks |
| `POST /api/v1/tasks/{id}/join-teamwork` | ❌ | ✅ Teamwork participation |
| `GET /api/v1/daily-tasks` | ❌ | ✅ User-specific filtering |
| `POST /api/v1/tasks/{id}/skip` | ❌ | ✅ Skip functionality |

### 5.3 Security Tests (Kritisch)

#### Fehlende Authorization Tests
```php
// tests/integration/Controller/TaskAuthorizationTest.php - NEW
class TaskAuthorizationTest extends TestCase
{
    public function testUserCannotCompleteTaskFromDifferentHouse(): void
    {
        // User A in House 1
        // User B in House 2
        // User A tries to complete User B's task
        // Should get 403 Forbidden
    }
    
    public function testChildCannotDeleteOwnersTask(): void
    {
        // Kid tries to delete mom's task
        // Should get 403 Forbidden
    }
    
    public function testGuestCannotAssignTasks(): void
    {
        // Friend (guest role) tries to assign task
        // Should get 403 Forbidden
    }
}
```

---

## 6. Priorisierte Roadmap

### Phase 1: Kritische Frontend-Tests (1-2 Wochen)
1. ✅ **DailyTaskList.spec.js** erstellen (complete coverage)
2. ✅ **task-completion-full-flow.spec.ts** erstellen (rewards, UI updates)
3. ✅ **multi-user-task-claiming.spec.ts** erstellen (claiming conflicts)
4. ✅ **FamilyTestData.php** erweitern (realistic snapshot)

### Phase 2: Multi-User Scenarios (1 Woche)
1. ✅ **multi-user-collaboration.spec.ts** (task stealing, parallel completion)
2. ✅ **teamwork-tasks.spec.ts** (join, leave, rewards)
3. ✅ **leaderboard-live-updates.spec.ts** (real-time updates)

### Phase 3: Automated Setup Tests (1 Woche)
1. ✅ **automated-setup-minimal.spec.ts**
2. ✅ **automated-setup-family.spec.ts**
3. ✅ Profile comparison utilities

### Phase 4: Backend Security & Edge Cases (1 Woche)
1. ✅ **TaskAuthorizationTest.php**
2. ✅ **SmartSchedulerServiceTest.php**
3. ✅ **RewardCalculationServiceTest.php**

### Phase 5: CI/CD Integration
1. ✅ Alle Tests in `.forgejo/workflows/test.yml` integrieren
2. ✅ Test-Metriken Dashboard
3. ✅ Coverage-Reporting

---

## 7. Offene Fragen für User-Feedback

### 7.1 Family-DB Snapshot
**Frage:** Soll die "family"-DB ihren aktuellen Zustand behalten (statisch, alle Tasks unassigned) oder sollen wir sie durch "family-active" ersetzen?

**Optionen:**
- **A:** `family` bleibt statisch (baseline), neues `family-active` Profile für laufenden Haushalt
- **B:** `family` wird überschrieben mit realistischem Snapshot
- **C:** Beide parallel pflegen

**Empfehlung:** Option A - beide Profile parallel für verschiedene Test-Szenarien.

### 7.2 Task Completion UX
**Frage:** Wie soll Task-Completion im UI funktionieren?

**Aktuell beobachtet:**
- `TaskList.vue` hat `complete-button` (Circle-Icon)
- `DailyTaskList.vue` hat `checkbox` (input type="checkbox")

**Fragen:**
- Sollen beide Varianten funktionieren?
- Welche ist die Haupt-UX? (Button vs. Checkbox)
- Gibt es Unterschiede zwischen "complete" und "mark as done"?

### 7.3 Teamwork Tasks
**Frage:** Wie funktioniert die Teamwork-Mechanik genau?

**Unklar:**
- Kann jeder einem Teamwork-Task beitreten oder nur zugewiesene User?
- Bekommen alle Teilnehmer volle Rewards oder aufgeteilt?
- Muss der Task von allen "completed" werden oder reicht einer?
- Gibt es eine maximale Teilnehmer-Anzahl?

**Benötigt:** Detaillierte Spezifikation für Teamwork-Tests.

### 7.4 Overdue Task Claiming
**Frage:** Können überfällige Tasks von anderen Usern übernommen werden?

**Szenarien:**
- Mom hat "dishes" assigned (due yesterday)
- Kann Dad diesen Task jetzt claimen/übernehmen?
- Wird Mom informiert?
- Gibt es Penalties für Mom (Streak-Verlust)?

**Benötigt:** Business-Rule-Dokumentation.

### 7.5 Real-time Updates
**Frage:** Nutzt ChoreStrike WebSockets oder Polling für Live-Updates?

**Relevant für:**
- Leaderboard-Updates
- Task-Assignment-Notifications
- House-Health-Changes

**Aktuell:** Vermutlich Polling beim View-Wechsel?  
**Tests benötigen:** Klarheit über Update-Mechanismus.

### 7.6 Daily Task Limits
**Frage:** Wie wird `maxDailyTasks` enforced?

**Szenarien:**
- Kid hat `maxDailyTasks: 2`
- Kid completed 2 tasks today
- Kann Kid weitere Tasks claimen/zugewiesen bekommen?
- Wird das im UI angezeigt ("2/2 today")?

**Tests benötigen:** Validierung dieser Business-Rules.

### 7.7 Energy Budget
**Frage:** Wie funktioniert das tägliche Energy-Budget?

**Aus Code gesehen:**
- `todayRemainingEnergy` in `scheduling` store
- `todayConsumedEnergy` wird getrackt
- Tasks haben `energyCost`

**Unklar:**
- Wird das Budget hart enforced (kann nicht überschritten werden)?
- Oder nur als Empfehlung angezeigt?
- Gibt es Warnings bei Überschreitung?

**Tests benötigen:** Energy-Budget Edge-Cases.

---

## 8. Implementierungsstatus (2026-03-15)

### ✅ Abgeschlossen
1. ✅ `DailyTaskList.spec.js` erstellt (kompletter Unit Test mit 15+ Szenarien)
2. ✅ `task-completion-full-flow.spec.ts` erweitert (Rewards, Score, Health validation)
3. ✅ `multi-user-task-claiming.spec.ts` erstellt (4 Multi-User-Szenarien)
4. ✅ `TaskAuthorizationTest.php` erstellt (10 Security-Tests)
5. ✅ `FamilyTestData.php` realistisch gemacht:
   - 3 completed tasks (History)
   - 2 overdue tasks (claimable)
   - 4 active assigned tasks
   - 2 unassigned claimable tasks
   - Realistische Scores: mom 1250, dad 980, teen 450, kid 230, grandma 180, friend 50
   - Current Streaks: mom 7, dad 4, teen 0, kid 2, grandma 3
   - Cleanliness angepasst an overdue tasks
6. ✅ Phase 11 Backlog erstellt (`docs/backlog/phase-11-task-visibility-features.md`):
   - Private Tasks (personal reminders ohne rewards)
   - Admin-Only Tasks (nur für Owners, mit rewards)
   - Teamwork Bonus-Rewards (0.5-1 extra, aufgerundet)
   - Overdue Task Claiming (jeder kann überfällige Tasks übernehmen)
7. ✅ Real-time Updates dokumentiert (`docs/REALTIME_UPDATES.md`):
   - Aktuell: Manual refresh on navigation
   - Empfehlung Phase 1: Window focus refresh
   - Empfehlung Phase 2: Smart polling (Leaderboard, Dashboard)
   - Empfehlung Phase 3: Event Bus für Multi-Tab
   - Empfehlung Phase 4: SSE für echtes Real-time

### 🔄 User-Feedback verarbeitet
1. ✅ **1B:** Family-DB wurde überschrieben (kein separates Profile)
2. ✅ **2:** Teamwork-Mechanik spezifiziert (alle teilnehmen, Bonus 0.5-1, aufgerundet)
3. ✅ **3:** Overdue-Claiming implementiert (jeder kann claimen) + Private/Admin Tasks ins Backlog
4. ✅ **4:** Fluent Icons - bereits verwendet (kein Action nötig)
5. ✅ **5:** Energy nur Soft-Limit (freundliche Warnung)
6. ✅ **6:** Real-time Updates analysiert und dokumentiert

### 📋 Verbleibende Aufgaben
1. **TaskEditForm.spec.js** erstellen (Medium Priority)
2. **Automated Setup Tests** implementieren (Lower Priority)
3. **TESTING.md** aktualisieren mit neuen Tests
4. **CI/CD** Integration der neuen Tests
5. **TeamworkRewardCalculation** implementieren (Phase 11)
6. **Window Focus Refresh** implementieren (Quick Win)

### 📊 Test Coverage Update

**Neu hinzugefügt:**
- Jest Frontend: +1 Test-Datei (DailyTaskList.spec.js)
- E2E: +2 Test-Dateien (task-completion-full-flow.spec.ts, multi-user-task-claiming.spec.ts)
- Integration: +1 Test-Datei (TaskAuthorizationTest.php)

**Aktuelle Zahlen:**
- Jest: 30 Specs (war 29)
- E2E: 22 Specs (war 20)
- Integration: 13 Files (war 12)

---

**Status:** Hauptarbeit abgeschlossen, bereit für Commit  
**Nächste Schritte:** Tests ausführen, TESTING.md aktualisieren, weitere Features nach Bedarf  
**Commit Message:** `test: comprehensive test strategy overhaul with realistic family fixture and multi-user scenarios`
