# ChoreStrike Real-time Updates - Technical Analysis

**Status:** Research Document  
**Date:** 2026-03-15

---

## Current Implementation

### Update Mechanism
Based on code analysis, ChoreStrike currently uses **manual polling on view navigation**.

**Evidence:**
- No WebSocket usage detected in codebase
- No Server-Sent Events (SSE) detected
- No `setInterval` polling in stores
- Views call `fetch*` methods in `mounted()` lifecycle hooks

### How It Works

```javascript
// src/views/Dashboard.vue
async mounted() {
  await this.initializeDashboard()
}

methods: {
  async initializeDashboard() {
    await this.houseStore.fetchHouse()
    await this.roomsStore.fetchFloorsWithRooms()
  }
}
```

**Update Triggers:**
- User navigates to a view → `mounted()` fires → data fetches
- User performs action → store action updates local state AND refetches
- Manual refresh (F5)

### Stores Use Local State
```javascript
// src/store/roomTasks.js
async completeRoomTask(roomTaskId, userId = null) {
  const response = await axios.post(...)
  
  // Local state update
  const index = this.roomTasks.findIndex(rt => rt.id === roomTaskId)
  if (index !== -1) {
    this.roomTasks[index] = response.data.data.roomTask
  }
}
```

**Implication:** User A completing a task updates User A's local state immediately, but User B only sees the update when they:
1. Navigate to another view and back
2. Manually refresh
3. Perform an action that triggers a refetch

---

## Real-time Update Scenarios

### Scenario 1: Leaderboard Updates
**Current:** User A completes task → User B sees updated leaderboard only on next navigation to leaderboard view  
**Expected:** User B sees leaderboard update within seconds

### Scenario 2: Task Claiming
**Current:** User A claims task → User B still sees task as claimable until refetch  
**Expected:** Task disappears from User B's available list immediately

### Scenario 3: House Health
**Current:** User A completes task → User B's dashboard shows old health until refresh  
**Expected:** Health bar animates up on all connected clients

---

## Options for Real-time Updates

### Option 1: Polling (Easiest)
**Pros:**
- Simple to implement
- Works everywhere (no special server config)
- Nextcloud already has AJAX infrastructure

**Cons:**
- Network overhead (constant requests)
- ~5-30 second delay
- Scales poorly with many users

**Implementation:**
```javascript
// src/store/scheduling.js
let pollingInterval = null

export const useSchedulingStore = defineStore('scheduling', {
  actions: {
    startPolling() {
      if (pollingInterval) return
      
      pollingInterval = setInterval(() => {
        this.fetchDailyTasks()
      }, 10000) // Every 10 seconds
    },
    
    stopPolling() {
      if (pollingInterval) {
        clearInterval(pollingInterval)
        pollingInterval = null
      }
    }
  }
})

// In views/Dashboard.vue
mounted() {
  this.schedulingStore.startPolling()
}

beforeUnmount() {
  this.schedulingStore.stopPolling()
}
```

**When to Fetch:**
- Only poll on views that need real-time data (Dashboard, Leaderboard)
- Pause polling when tab is inactive (`document.visibilityState`)
- Implement exponential backoff if server errors

---

### Option 2: Nextcloud Event Bus (Medium)
**Pros:**
- Nextcloud has built-in event bus (`@nextcloud/event-bus`)
- Can emit events across tabs (same browser)
- No server changes needed

**Cons:**
- Only works for same-user, multi-tab scenarios
- Doesn't sync between different users
- Not true real-time for multi-user

**Implementation:**
```javascript
import { subscribe, emit } from '@nextcloud/event-bus'

// In store action after completing task
async completeRoomTask(roomTaskId) {
  const response = await axios.post(...)
  emit('chorestrike:task-completed', { roomTaskId, rewards: response.data.data.rewards })
}

// In Dashboard component
mounted() {
  subscribe('chorestrike:task-completed', this.handleTaskCompleted)
}

methods: {
  handleTaskCompleted(event) {
    // Refetch house health, update UI
    this.houseStore.fetchHouse()
  }
}
```

**Use Cases:**
- Sync state across multiple ChoreStrike tabs
- Refresh leaderboard when user completes task in another tab

---

### Option 3: Server-Sent Events (Harder)
**Pros:**
- True server-push
- Efficient (long-lived HTTP connection)
- Built-in browser support

**Cons:**
- Requires backend changes
- Nextcloud app compatibility unclear
- Connection management complexity

**Implementation:**
```php
// lib/Controller/EventStreamController.php
public function stream(): StreamingResponse {
  return new StreamingResponse(function() {
    while (true) {
      // Check for updates
      $updates = $this->eventService->getPendingUpdates($this->userId);
      
      if (!empty($updates)) {
        echo "data: " . json_encode($updates) . "\n\n";
        flush();
      }
      
      sleep(2);
    }
  });
}
```

```javascript
// src/utils/eventStream.js
const eventSource = new EventSource('/apps/chorestrike/api/v1/events')

eventSource.addEventListener('task-completed', (event) => {
  const data = JSON.parse(event.data)
  // Update stores
})
```

---

### Option 4: WebSockets (Advanced)
**Pros:**
- True bidirectional real-time
- Lowest latency
- Industry standard

**Cons:**
- Nextcloud doesn't support WebSockets natively
- Requires separate server or reverse proxy
- Complex infrastructure

**Not Recommended** for Nextcloud app.

---

## Recommendation

### Phase 1: Enhanced Manual Refresh (Immediate)
Keep current system but improve:
- Add "Refresh" button to Dashboard
- Auto-refresh on window focus after 30+ seconds away
- Optimistic UI updates (update local state before server confirms)

```javascript
// src/views/Dashboard.vue
mounted() {
  window.addEventListener('focus', this.handleWindowFocus)
}

methods: {
  handleWindowFocus() {
    const lastFetch = this.houseStore.lastFetchTime
    const now = Date.now()
    
    if (now - lastFetch > 30000) { // 30 seconds
      this.initializeDashboard()
    }
  }
}
```

### Phase 2: Smart Polling (E2E Tests Need This)
Implement polling for:
- Leaderboard view (every 15 seconds)
- Dashboard quick stats (every 30 seconds)
- Active tasks list (every 10 seconds when on screen)

**Use `document.visibilityState` to pause when tab inactive:**
```javascript
let pollingInterval = null

function startPolling() {
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
      clearInterval(pollingInterval)
    } else {
      pollingInterval = setInterval(fetchData, 10000)
    }
  })
}
```

### Phase 3: Event Bus for Multi-Tab (Future)
Use `@nextcloud/event-bus` to sync state across user's own tabs.

### Phase 4: Server-Sent Events (Future, if needed)
Only if multi-user collaboration becomes critical feature.

---

## Testing Implications

### E2E Tests
**Current assumption:** E2E tests need explicit page reloads or navigation to see updates.

**With polling:**
```typescript
// tests/e2e/multi-user-leaderboard.spec.ts
test('mom sees dad score update in real-time', async ({ browser }) => {
  const momPage = await momContext.newPage()
  const dadPage = await dadContext.newPage()
  
  // Mom opens leaderboard
  await momPage.goto('/apps/chorestrike/#/leaderboard')
  const initialScore = await momPage.locator('[data-user="dad"] .score').textContent()
  
  // Dad completes task
  await dadPage.goto('/apps/chorestrike/#/rooms/1')
  await dadPage.click('[data-testid="complete-task-dishes"]')
  
  // Wait for polling cycle (max 15 seconds + network)
  await momPage.waitForTimeout(20000)
  
  // Mom's leaderboard should auto-update
  const newScore = await momPage.locator('[data-user="dad"] .score').textContent()
  expect(parseInt(newScore)).toBeGreaterThan(parseInt(initialScore))
})
```

**Without polling:** Tests must call `await page.reload()` or navigate away and back.

---

## Next Steps

1. **Document current behavior** in test docs ✅ (this doc)
2. **Add window focus refresh** to Dashboard (quick win)
3. **Implement polling** for Leaderboard (E2E test enabler)
4. **Update E2E tests** to account for polling delays
5. **Consider event bus** for multi-tab UX

---

## Related Files

- `src/store/*.js` - All stores that fetch data
- `src/views/Dashboard.vue` - Main view
- `src/views/Leaderboard.vue` - Real-time critical
- `docs/TESTING.md` - Test strategy and implications
