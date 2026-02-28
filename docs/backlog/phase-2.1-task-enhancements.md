# Phase 2.1: Task Management Enhancements - Concept Document

**Status**: Planned  
**Timeline**: Week 7-9  
**Goal**: Enhance task management with scheduling modes, templates, checklists, and advanced assignment

---

## Feature 1: Dynamic vs Static Scheduling

### Problem
Currently `nextDue` always shifts based on actual completion time (dynamic). Some tasks need fixed schedules (e.g., "Trash out every Monday 18:00").

### Solution

#### Database Changes
```sql
ALTER TABLE oc_chorestrike_tasks 
ADD COLUMN scheduling_mode VARCHAR(16) DEFAULT 'dynamic' NOT NULL,
ADD COLUMN fixed_schedule_time TIME NULL,
ADD COLUMN fixed_schedule_days VARCHAR(255) NULL;
```

**Fields:**
- `scheduling_mode`: `'dynamic'` | `'static'`
- `fixed_schedule_time`: e.g., `'18:00:00'` (static only)
- `fixed_schedule_days`: JSON array e.g., `'["monday","thursday"]'` (static only)

#### Backend Changes

**Task.php Entity:**
```php
protected $schedulingMode = 'dynamic';
protected $fixedScheduleTime;
protected $fixedScheduleDays;
```

**RoomTask::markCompleted() Logic:**
- Add `schedulingMode` parameter
- If `static`: calculate next occurrence based on fixed schedule
- If `dynamic`: use existing logic (shift from completion time)

**New Method:**
```php
private function calculateNextStaticDue(
    \DateTime $completedAt,
    string $frequencyType,
    int $frequencyValue,
    ?string $fixedScheduleTime,
    ?array $fixedScheduleDays
): \DateTime
```

#### Frontend Changes

**TaskForm.vue:**
- Radio/Select: Dynamic vs Static
- If Static: Time picker + weekday checkboxes (for weekly tasks)
- Validation: Static requires time, weekly+static requires at least 1 day

#### API Changes

**TaskController:**
- Update `create()` and `update()` to accept new fields
- Validation for static mode requirements

#### Migration
**Version1004Date20260228000000.php**

---

## Feature 2: Template Database

### Problem
Users need realistic task suggestions with proper time estimates and frequencies.

### Solution

#### Template Structure
**File:** `lib/Templates/TaskTemplates.json`

```json
{
  "templates": [
    {
      "id": "kitchen_dishes",
      "name": "Empty dishwasher",
      "description": "Unload clean dishes and put away",
      "instructions": "1. Check if dishes are clean\n2. Sort by type\n3. Put away in cabinets",
      "energyCost": 2,
      "priority": 2,
      "frequencyType": "daily",
      "frequencyValue": 1,
      "estimatedMinutes": 10,
      "category": "cleaning",
      "scopeType": "room",
      "scopeLabel": "kitchen",
      "tags": ["kitchen", "daily", "quick"]
    },
    {
      "id": "bathroom_toilet",
      "name": "Clean toilet",
      "description": "Scrub toilet bowl and wipe surfaces",
      "instructions": "1. Apply cleaner\n2. Let sit 5 min\n3. Scrub with brush\n4. Wipe seat and exterior",
      "energyCost": 3,
      "priority": 2,
      "frequencyType": "weekly",
      "frequencyValue": 1,
      "estimatedMinutes": 15,
      "category": "cleaning",
      "scopeType": "room",
      "scopeLabel": "bathroom",
      "tags": ["bathroom", "weekly", "hygiene"]
    }
  ]
}
```

#### Template Categories
- **Kitchen**: Dishes, counters, stove, fridge, trash
- **Bathroom**: Toilet, shower, sink, mirror, floor
- **Bedroom**: Bed making, vacuuming, dusting, laundry
- **Living Room**: Vacuuming, dusting, organizing
- **General**: Windows, floors, trash, recycling

**Target:** ~30-40 templates covering common household tasks

#### Backend Implementation

**New Service:** `TemplateService.php`
```php
class TemplateService {
    public function getTemplates(?string $category = null, ?string $scopeLabel = null): array
    public function importTemplate(int $templateId, int $roomId): Task
    public function searchTemplates(string $query): array
}
```

**TaskController:**
- `GET /api/v1/tasks/templates` - List all templates
- `GET /api/v1/tasks/templates/{id}` - Get template details
- `POST /api/v1/tasks/from-template` - Create task from template

#### Frontend Implementation

**New Component:** `TaskTemplateSelector.vue`
- Search/filter templates
- Category tabs
- Preview with instructions
- "Use Template" button → pre-fills TaskForm

**Integration:**
- TaskForm: "Choose from template" button
- Room setup wizard: Suggest templates based on room name

#### i18n
- Template names/descriptions in `l10n/templates/`
- Fallback to English if translation missing

---

## Feature 3: Sub-Checklists for Tasks

### Problem
Complex tasks need breakdown into steps that can be checked off individually.

### Solution

#### Database Changes
```sql
CREATE TABLE oc_chorestrike_task_checklist_items (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    task_id BIGINT NOT NULL,
    label VARCHAR(255) NOT NULL,
    sort_order INT DEFAULT 0,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (task_id) REFERENCES oc_chorestrike_tasks(id) ON DELETE CASCADE
);

CREATE TABLE oc_chorestrike_room_task_checklist_progress (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    room_task_id BIGINT NOT NULL,
    checklist_item_id BIGINT NOT NULL,
    is_checked BOOLEAN DEFAULT FALSE,
    checked_at DATETIME NULL,
    checked_by_user_id BIGINT NULL,
    FOREIGN KEY (room_task_id) REFERENCES oc_chorestrike_room_tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (checklist_item_id) REFERENCES oc_chorestrike_task_checklist_items(id) ON DELETE CASCADE,
    UNIQUE KEY unique_progress (room_task_id, checklist_item_id)
);
```

#### Backend Entities

**TaskChecklistItem.php:**
```php
class TaskChecklistItem extends Entity {
    protected $taskId;
    protected $label;
    protected $sortOrder;
    protected $createdAt;
}
```

**RoomTaskChecklistProgress.php:**
```php
class RoomTaskChecklistProgress extends Entity {
    protected $roomTaskId;
    protected $checklistItemId;
    protected $isChecked;
    protected $checkedAt;
    protected $checkedByUserId;
}
```

#### API Endpoints

**TaskController:**
- `GET /api/v1/tasks/{id}/checklist` - Get checklist items
- `POST /api/v1/tasks/{id}/checklist` - Add checklist item
- `PUT /api/v1/tasks/{id}/checklist/{itemId}` - Update item
- `DELETE /api/v1/tasks/{id}/checklist/{itemId}` - Delete item

**RoomTaskController:**
- `GET /api/v1/room-tasks/{id}/checklist-progress` - Get progress
- `PUT /api/v1/room-tasks/{id}/checklist/{itemId}/toggle` - Toggle check

#### Frontend Implementation

**TaskForm.vue Enhancement:**
```vue
<div class="checklist-editor">
  <h4>Checklist</h4>
  <draggable v-model="task.checklistItems">
    <div v-for="item in task.checklistItems" :key="item.id" class="checklist-item">
      <input v-model="item.label" placeholder="Step description" />
      <button @click="removeItem(item)">×</button>
    </div>
  </draggable>
  <button @click="addChecklistItem">+ Add Step</button>
</div>
```

**TaskItem.vue Enhancement:**
```vue
<div v-if="task.checklistItems?.length > 0" class="task-checklist">
  <div v-for="item in task.checklistItems" :key="item.id" class="checklist-item">
    <input 
      type="checkbox" 
      :checked="isItemChecked(item.id)"
      @change="toggleChecklistItem(item.id)"
    />
    <label>{{ item.label }}</label>
  </div>
  <div class="checklist-progress">
    {{ checkedCount }}/{{ task.checklistItems.length }} completed
  </div>
</div>
```

#### Completion Logic
- Task can be completed even if checklist not 100% done (flexible)
- Option: Require 100% checklist completion (strict mode)
- Add field `require_checklist_completion` to Task entity

#### Migration
**Version1005Date20260228000000.php**

---

## Feature 4: Enhanced Task Description & Instructions

### Problem
Tasks need detailed instructions for consistency and training new household members.

### Solution

#### Database Changes
```sql
ALTER TABLE oc_chorestrike_tasks 
ADD COLUMN instructions TEXT NULL,
ADD COLUMN tips TEXT NULL,
ADD COLUMN estimated_minutes_min INT NULL,
ADD COLUMN estimated_minutes_max INT NULL;
```

**Fields:**
- `instructions`: Step-by-step guide (Markdown supported)
- `tips`: Helpful hints, tricks, product recommendations
- `estimated_minutes_min/max`: Time range (e.g., 10-15 min)

#### Backend Changes

**Task.php:**
```php
protected $instructions;
protected $tips;
protected $estimatedMinutesMin;
protected $estimatedMinutesMax;

public function getEstimatedTimeRange(): string {
    if ($this->estimatedMinutesMin && $this->estimatedMinutesMax) {
        return "{$this->estimatedMinutesMin}-{$this->estimatedMinutesMax} min";
    }
    return "{$this->estimatedMinutes} min";
}
```

#### Frontend Changes

**TaskForm.vue:**
```vue
<div class="task-details">
  <label>Description</label>
  <textarea v-model="task.description" rows="2"></textarea>
  
  <label>Instructions (Markdown supported)</label>
  <textarea v-model="task.instructions" rows="6"></textarea>
  
  <label>Tips & Tricks</label>
  <textarea v-model="task.tips" rows="3"></textarea>
  
  <label>Estimated Time</label>
  <div class="time-range">
    <input type="number" v-model="task.estimatedMinutesMin" placeholder="Min" />
    <span>-</span>
    <input type="number" v-model="task.estimatedMinutesMax" placeholder="Max" />
    <span>minutes</span>
  </div>
</div>
```

**TaskDetailModal.vue (new):**
- Show full instructions with Markdown rendering
- Collapsible sections (Instructions, Tips)
- Print-friendly view
- "Start Task" button

#### Template Integration
- Templates include pre-written instructions
- Users can customize after import

#### Migration
**Version1006Date20260228000000.php**

---

## Feature 5: Group Assignment System

### Problem
Tasks should be assignable to multiple users or groups (e.g., "Kids", "Parents").

### Solution

#### Database Changes
```sql
CREATE TABLE oc_chorestrike_user_groups (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    house_id BIGINT NOT NULL,
    name VARCHAR(100) NOT NULL,
    color VARCHAR(7) NULL,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (house_id) REFERENCES oc_chorestrike_houses(id) ON DELETE CASCADE
);

CREATE TABLE oc_chorestrike_user_group_members (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    group_id BIGINT NOT NULL,
    user_id BIGINT NOT NULL,
    joined_at DATETIME NOT NULL,
    FOREIGN KEY (group_id) REFERENCES oc_chorestrike_user_groups(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES oc_chorestrike_house_users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_membership (group_id, user_id)
);

CREATE TABLE oc_chorestrike_task_assignments (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    room_task_id BIGINT NOT NULL,
    assignment_type VARCHAR(16) NOT NULL,
    user_id BIGINT NULL,
    group_id BIGINT NULL,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (room_task_id) REFERENCES oc_chorestrike_room_tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES oc_chorestrike_house_users(id) ON DELETE CASCADE,
    FOREIGN KEY (group_id) REFERENCES oc_chorestrike_user_groups(id) ON DELETE CASCADE
);
```

**assignment_type:** `'user'` | `'group'` | `'anyone'`

#### Backend Entities

**UserGroup.php:**
```php
class UserGroup extends Entity {
    protected $houseId;
    protected $name;
    protected $color;
    protected $createdAt;
}
```

**TaskAssignment.php:**
```php
class TaskAssignment extends Entity {
    protected $roomTaskId;
    protected $assignmentType;
    protected $userId;
    protected $groupId;
    protected $createdAt;
}
```

#### API Endpoints

**UserGroupController.php (new):**
- `GET /api/v1/houses/{id}/groups` - List groups
- `POST /api/v1/houses/{id}/groups` - Create group
- `PUT /api/v1/groups/{id}` - Update group
- `DELETE /api/v1/groups/{id}` - Delete group
- `POST /api/v1/groups/{id}/members` - Add member
- `DELETE /api/v1/groups/{id}/members/{userId}` - Remove member

**RoomTaskController Enhancement:**
- `GET /api/v1/room-tasks/{id}/assignments` - Get assignments
- `POST /api/v1/room-tasks/{id}/assignments` - Add assignment
- `DELETE /api/v1/room-tasks/{id}/assignments/{assignmentId}` - Remove

#### Frontend Implementation

**GroupManagement.vue (new):**
- Create/edit groups
- Drag-drop users into groups
- Color coding
- Predefined groups: "Adults", "Kids", "Everyone"

**TaskAssignmentPicker.vue (new):**
```vue
<div class="assignment-picker">
  <h4>Assign to:</h4>
  <div class="assignment-options">
    <button @click="assignToAnyone">Anyone</button>
    <button @click="showUserPicker">Specific User(s)</button>
    <button @click="showGroupPicker">Group(s)</button>
  </div>
  
  <div v-if="showingUserPicker" class="user-list">
    <label v-for="user in users" :key="user.id">
      <input type="checkbox" :value="user.id" v-model="selectedUsers" />
      {{ user.displayName }}
    </label>
  </div>
  
  <div v-if="showingGroupPicker" class="group-list">
    <label v-for="group in groups" :key="group.id">
      <input type="checkbox" :value="group.id" v-model="selectedGroups" />
      <span :style="{color: group.color}">{{ group.name }}</span>
    </label>
  </div>
</div>
```

#### Completion Logic
- If assigned to group: any member can complete
- If assigned to multiple users: any one can complete (OR logic)
- Option: Require all assigned users (AND logic) - add field `require_all_assignees`

#### Migration
**Version1007Date20260228000000.php**

---

## Feature 6: Multi-User Teamwork Enhancement

### Problem
Current combo system only supports 2 users. Need support for team tasks with adjusted point distribution.

### Solution

#### Database Changes
```sql
ALTER TABLE oc_chorestrike_tasks 
ADD COLUMN allows_teamwork BOOLEAN DEFAULT TRUE,
ADD COLUMN min_team_size INT DEFAULT 1,
ADD COLUMN max_team_size INT DEFAULT 1,
ADD COLUMN team_point_multiplier DECIMAL(3,2) DEFAULT 1.00;

CREATE TABLE oc_chorestrike_task_participants (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    completed_task_id BIGINT NOT NULL,
    user_id BIGINT NOT NULL,
    sparks_earned INT NOT NULL,
    role VARCHAR(32) DEFAULT 'participant',
    joined_at DATETIME NOT NULL,
    FOREIGN KEY (completed_task_id) REFERENCES oc_chorestrike_completed_tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES oc_chorestrike_house_users(id) ON DELETE CASCADE
);
```

**Fields:**
- `allows_teamwork`: Can be completed by multiple users simultaneously
- `min_team_size`: Minimum required participants (e.g., 2 for "move furniture")
- `max_team_size`: Maximum participants (e.g., 3)
- `team_point_multiplier`: Point adjustment per person (e.g., 0.67 = 2/3 points each)

#### Point Distribution Examples

**Solo Task (3 sparks):**
- 1 person → 3 sparks

**Team Task (3 sparks, 2 people, multiplier 0.67):**
- Person A → 2 sparks (3 × 0.67)
- Person B → 2 sparks (3 × 0.67)
- Total distributed: 4 sparks (slight bonus for teamwork)

**Team Task (3 sparks, 3 people, multiplier 0.50):**
- Each person → 1.5 sparks
- Total: 4.5 sparks

#### Backend Changes

**GameLogicService Enhancement:**
```php
public function handleTeamTaskCompletion(
    RoomTask $roomTask,
    Task $task,
    array $participantUserIds
): array {
    // Validate team size
    if (count($participantUserIds) < $task->getMinTeamSize()) {
        throw new \Exception('Not enough participants');
    }
    if (count($participantUserIds) > $task->getMaxTeamSize()) {
        throw new \Exception('Too many participants');
    }
    
    // Calculate base sparks
    $baseSparks = $this->pointsCalculator->calculate($task);
    
    // Apply team multiplier
    $sparksPerPerson = (int)($baseSparks * $task->getTeamPointMultiplier());
    
    // Create CompletedTask record
    $completedTask = new CompletedTask();
    $completedTask->setRoomTaskId($roomTask->getId());
    $completedTask->setUserId($participantUserIds[0]); // Primary completer
    $completedTask->setSparksEarned($sparksPerPerson);
    $completedTask->setWasCombo(count($participantUserIds) > 1);
    $this->completedTaskMapper->insert($completedTask);
    
    // Create participant records
    foreach ($participantUserIds as $userId) {
        $participant = new TaskParticipant();
        $participant->setCompletedTaskId($completedTask->getId());
        $participant->setUserId($userId);
        $participant->setSparksEarned($sparksPerPerson);
        $this->taskParticipantMapper->insert($participant);
        
        // Update user sparks
        $this->updateUserSparks($userId, $sparksPerPerson);
    }
    
    return [
        'completedTask' => $completedTask,
        'sparksPerPerson' => $sparksPerPerson,
        'participants' => $participantUserIds
    ];
}
```

#### Frontend Implementation

**TaskCompletionModal.vue (new):**
```vue
<div v-if="task.allowsTeamwork && task.maxTeamSize > 1" class="team-completion">
  <h4>Who helped with this task?</h4>
  <div class="participant-picker">
    <label v-for="user in availableUsers" :key="user.id">
      <input type="checkbox" :value="user.id" v-model="participants" />
      {{ user.displayName }}
    </label>
  </div>
  
  <div class="team-preview">
    <p>Team size: {{ participants.length + 1 }}</p>
    <p>Sparks per person: {{ calculateTeamSparks() }}</p>
  </div>
  
  <button @click="completeAsTeam">Complete as Team</button>
</div>
```

**TaskItem.vue:**
- Show team icon if `allowsTeamwork`
- Show required team size if `minTeamSize > 1`
- "Complete Solo" vs "Complete as Team" buttons

#### Special Cases

**"Four Eyes Principle" for Kids:**
- Set `minTeamSize = 2` for tasks requiring supervision
- One participant must be adult (role-based validation)

**Bonus for Voluntary Teamwork:**
- If `minTeamSize = 1` but multiple people help → bonus multiplier (e.g., 0.8 instead of 0.67)

#### Migration
**Version1008Date20260228000000.php**

---

## Feature 7: Claiming Overdue Tasks

### Problem
Overdue tasks should be claimable by other users with bonus points to encourage completion.

### Solution

#### Database Changes
```sql
ALTER TABLE oc_chorestrike_tasks 
ADD COLUMN can_be_claimed BOOLEAN DEFAULT TRUE,
ADD COLUMN claimable_after_days INT DEFAULT 3,
ADD COLUMN claim_bonus_multiplier DECIMAL(3,2) DEFAULT 1.25;

ALTER TABLE oc_chorestrike_room_tasks
ADD COLUMN claimed_by_user_id BIGINT NULL,
ADD COLUMN claimed_at DATETIME NULL,
ADD COLUMN original_assignee_user_id BIGINT NULL;
```

**Fields:**
- `can_be_claimed`: Allow claiming by others
- `claimable_after_days`: Days overdue before claimable (default 3)
- `claim_bonus_multiplier`: Bonus for claiming (e.g., 1.25 = +25% sparks)
- `claimed_by_user_id`: Who claimed it
- `original_assignee_user_id`: Original assignee (for tracking)

#### Backend Logic

**RoomTask.php:**
```php
public function isClaimable(Task $task): bool {
    if (!$task->getCanBeClaimed()) {
        return false;
    }
    
    if (!$this->isOverdue()) {
        return false;
    }
    
    $daysOverdue = abs($this->getDaysUntilDue());
    return $daysOverdue >= $task->getClaimableAfterDays();
}

public function claim(int $userId): void {
    $this->setOriginalAssigneeUserId($this->getAssignedToUserId());
    $this->setClaimedByUserId($userId);
    $this->setClaimedAt(new \DateTime());
    $this->setAssignedToUserId($userId);
}
```

**GameLogicService:**
```php
public function calculateClaimBonus(Task $task, RoomTask $roomTask): int {
    $baseSparks = $this->pointsCalculator->calculate($task);
    
    if ($roomTask->getClaimedByUserId()) {
        return (int)($baseSparks * $task->getClaimBonusMultiplier());
    }
    
    return $baseSparks;
}
```

#### API Endpoints

**RoomTaskController:**
- `GET /api/v1/room-tasks/claimable` - List claimable tasks
- `POST /api/v1/room-tasks/{id}/claim` - Claim task
- `POST /api/v1/room-tasks/{id}/unclaim` - Release claim (if not completed)

#### Frontend Implementation

**ClaimableTasksList.vue (new):**
```vue
<div class="claimable-tasks">
  <h3>🎯 Bonus Tasks Available!</h3>
  <div v-for="task in claimableTasks" :key="task.id" class="claimable-task">
    <div class="task-info">
      <h4>{{ task.name }}</h4>
      <p>{{ task.room.name }}</p>
      <p class="overdue">{{ task.daysOverdue }} days overdue</p>
    </div>
    <div class="bonus-info">
      <span class="bonus-badge">+{{ task.bonusPercentage }}% Bonus!</span>
      <span class="sparks">{{ task.bonusSparks }} sparks</span>
    </div>
    <button @click="claimTask(task)">Claim Task</button>
  </div>
</div>
```

**Dashboard Integration:**
- "Bonus Tasks" widget showing claimable count
- Notification when tasks become claimable
- Filter: "Show only my tasks" vs "Show all tasks"

#### Notification System

**When task becomes claimable:**
- Notify original assignee: "Your task is now claimable by others"
- Notify other household members: "Bonus task available!"

**When task is claimed:**
- Notify original assignee: "User X claimed your task"
- Show in activity feed

#### Fairness Rules

**Prevent Gaming:**
- Original assignee cannot claim their own task
- Cooldown: Can't claim more than 3 tasks per day
- Limit: Max 30% of weekly tasks can be claimed tasks

**Tracking:**
- Add `claim_count_week` to HouseUser
- Reset weekly

#### Migration
**Version1009Date20260228000000.php**

---

## Implementation Priority

### Phase 2.1 (Quick Wins) - Week 7-8
1. **Dynamic/Static Scheduling** (2 days)
2. **Enhanced Descriptions & Instructions** (1 day)
3. **Template Database** (2 days)
4. **Claiming Overdue Tasks** (2 days)

### Phase 2.2 (Medium-Term) - Week 9-10
5. **Sub-Checklists** (3 days)
6. **Group Assignment System** (3 days)
7. **Multi-User Teamwork** (3 days)

---

## Testing Strategy

### Unit Tests
- Scheduling mode calculation logic
- Team point distribution
- Claim eligibility rules
- Checklist progress tracking

### Integration Tests
- Template import workflow
- Group assignment cascade
- Team completion flow
- Claim notification delivery

### E2E Tests
- Create task with checklist
- Assign to group
- Complete as team
- Claim overdue task

---

## Migration Order

1. Version1004 - Scheduling mode fields
2. Version1005 - Checklist tables
3. Version1006 - Instructions fields
4. Version1007 - Group assignment tables
5. Version1008 - Teamwork fields
6. Version1009 - Claiming fields

---

## i18n Requirements

### New Translation Keys
- Scheduling modes
- Checklist UI
- Team completion messages
- Claim notifications
- Template categories
- Group management

### Template Translations
- Task names
- Instructions
- Tips
- All in `l10n/templates/`

---

**Last Updated**: 2026-02-28  
**Next Review**: After Phase 2.1 completion
