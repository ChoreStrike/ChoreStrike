# ChoreStrike API Documentation

## Base URL

All API endpoints are prefixed with `/apps/chorestrike/api/v1`

The API reflects the MVP concept decisions:

- energy-based effort model (`energy_cost`)
- separate `contribution_score` and `team_sparks`
- absence-aware streak handling
- localized role-title packs

## Authentication

All requests require Nextcloud session authentication. CSRF tokens are validated automatically.

## Response Format

### Success Response
```json
{
  "success": true,
  "data": { ... }
}
```

### Error Response
```json
{
  "success": false,
  "error": {
    "code": "ERROR_CODE",
    "message": "Human readable message"
  }
}
```

## Endpoints

### House

#### GET /house
Get current user's house information.

**Response:**
```json
{
  "id": 1,
  "name": "My Home",
  "house_health": 85,
  "created_at": "2026-02-24T10:00:00Z"
}
```

#### PUT /house
Update house information.

**Request:**
```json
{
  "name": "Updated Home Name"
}
```

### Floors

#### GET /floors
List all floors in the house.

**Response:**
```json
[
  {
    "id": 1,
    "house_id": 1,
    "name": "Ground Floor",
    "sort_order": 0,
    "room_count": 3
  }
]
```

#### POST /floors
Create a new floor.

**Request:**
```json
{
  "name": "First Floor",
  "sort_order": 1
}
```

#### PUT /floors/{id}
Update floor.

#### DELETE /floors/{id}
Delete floor (only if empty).

### Rooms

#### GET /rooms
List all rooms.

**Query Parameters:**
- `floor_id` (optional): Filter by floor

**Response:**
```json
[
  {
    "id": 1,
    "floor_id": 1,
    "name": "Kitchen",
    "cleanliness": 75,
    "monster_count": 3,
    "last_cleaned": "2026-02-23T14:30:00Z",
    "overdue_tasks": 2,
    "due_tasks": 1
  }
]
```

#### GET /rooms/{id}
Get room details with tasks.

**Response:**
```json
{
  "id": 1,
  "name": "Kitchen",
  "cleanliness": 75,
  "monster_count": 3,
  "tasks": [
    {
      "id": 1,
      "name": "Empty dishwasher",
      "energy_cost": 2,
      "priority": 1,
      "next_due": "2026-02-24T12:00:00Z",
      "is_overdue": false
    }
  ]
}
```

#### POST /rooms
Create a new room.

**Request:**
```json
{
  "floor_id": 1,
  "name": "Living Room"
}
```

#### PUT /rooms/{id}
Update room.

#### DELETE /rooms/{id}
Delete room.

### Tasks

#### GET /tasks
List all tasks.

**Query Parameters:**
- `is_template` (boolean): Filter templates
- `scope_type` (optional): `room`, `garden`, `home`, `outside`, `work`
- `recurrence_mode` (optional): `one_time`, `recurring`

**Response:**
```json
[
  {
    "id": 1,
    "name": "Vacuum",
    "description": "Vacuum all floors",
    "energy_cost": 3,
    "priority": 2,
    "frequency_type": "weekly",
    "frequency_value": 1,
    "recurrence_mode": "recurring",
    "active_from": null,
    "active_until": null,
    "estimated_minutes": 30,
    "scope_type": "room",
    "scope_label": "Kitchen",
    "is_voluntary": false,
    "reminder": {
      "mode": "before_due",
      "minutes_before": 30,
      "repeat_while_overdue": false
    },
    "category": "cleaning",
    "is_template": false
  }
]
```

#### GET /tasks/templates
Get predefined task templates.

**Response:**
```json
[
  {
    "id": "template_1",
    "name": "Empty Dishwasher",
    "energy_cost": 2,
    "priority": 1,
    "frequency_type": "daily",
    "estimated_minutes": 10,
    "category": "cleaning"
  }
]
```

#### POST /tasks
Create a new task.

**Request:**
```json
{
  "name": "Clean windows",
  "description": "Clean all windows",
  "energy_cost": 4,
  "priority": 3,
  "frequency_type": "monthly",
  "frequency_value": 1,
  "recurrence_mode": "one_time",
  "active_from": "2026-12-01T00:00:00Z",
  "active_until": "2027-03-01T00:00:00Z",
  "estimated_minutes": 60,
  "scope_type": "outside",
  "scope_label": "Errands",
  "is_voluntary": true,
  "reminder": {
    "mode": "before_due",
    "minutes_before": 1440,
    "repeat_while_overdue": false
  },
  "category": "cleaning"
}
```

#### PUT /tasks/{id}
Update task.

#### DELETE /tasks/{id}
Delete task.

### Room Tasks

#### GET /room-tasks
List all room-task assignments.

**Query Parameters:**
- `room_id` (optional): Filter by room
- `scope_id` (optional): Filter by task scope
- `status` (optional): `due`, `overdue`, `upcoming`

**Response:**
```json
[
  {
    "id": 1,
    "room_id": 1,
    "scope_id": 3,
    "task_id": 1,
    "task_name": "Vacuum",
    "energy_cost": 3,
    "priority": 2,
    "next_due": "2026-02-25T10:00:00Z",
    "last_completed": "2026-02-18T10:00:00Z",
    "assigned_to_user_id": null,
    "is_overdue": false,
    "days_until_due": 1
  }
]
```

#### POST /room-tasks
Assign task to room(s).

**Request:**
```json
{
  "task_id": 1,
  "scope_id": 3,
  "room_ids": [1, 2, 3],
  "assigned_to_user_id": null
}
```

#### PUT /room-tasks/{id}
Update room task assignment.

#### DELETE /room-tasks/{id}
Remove task from room.

#### POST /room-tasks/{id}/complete
Mark task as completed.

**Request:**
```json
{
  "completed_at": "2026-02-24T15:30:00Z"
}
```

**Response:**
```json
{
  "contribution_score_earned": 12,
  "team_sparks_earned": 18,
  "was_combo": true,
  "combo_with_user": "John",
  "streak": {
    "current": 7,
    "milestone_reached": true,
    "milestone_reward": "task_pass"
  },
  "new_cleanliness": 85,
  "monsters_defeated": 2,
  "next_due": "2026-02-25T15:30:00Z",
  "is_active": true
}
```

For tasks with `recurrence_mode = "one_time"`, completion deactivates the room-task assignment (`is_active = false`).

#### POST /room-tasks/{id}/pass
Use one Task Pass to reassign a task to another available member.

**Request:**
```json
{
  "to_user_id": 2,
  "reason": "streak_reward"
}
```

**Response:**
```json
{
  "room_task_id": 1,
  "from_user_id": 1,
  "to_user_id": 2,
  "receiver_reward_multiplier": 2.0,
  "remaining_task_passes": 0
}
```

### Game

#### GET /game/state
Get current game state.

**Response:**
```json
{
  "house_health": 85,
  "total_monsters": 12,
  "active_combos": 1,
  "current_streak": 7,
  "streak_rule": {
    "absence_days_break_streak": false,
    "eligible_day_requires_completion": true
  },
  "rooms_by_status": {
    "clean": 5,
    "due": 3,
    "overdue": 2
  }
}
```

#### GET /game/streaks
Get streak status for all members with absence-aware calculation details.

**Response:**
```json
[
  {
    "user_id": 1,
    "display_name": "Alice",
    "current_streak": 7,
    "last_eligible_day": "2026-02-24",
    "absence_days_skipped": 3,
    "next_milestone": 14,
    "available_task_passes": 1
  }
]
```

#### GET /game/role-title-packs
List available localized role-title packs and the currently selected pack.

MVP scope: built-in packs only. Custom packs are post-MVP.

**Response:**
```json
{
  "selected_pack": "home_helpers",
  "packs": [
    {
      "id": "home_helpers",
      "labels": {
        "en": ["Helper", "Organizer", "Care Captain"],
        "de": ["Helfer", "Organisator", "Fuersorge Captain"]
      }
    },
    {
      "id": "team_energy",
      "labels": {
        "en": ["Spark Starter", "Flow Keeper", "Team Anchor"],
        "de": ["Funkenstarter", "Flow Hueter", "Team Anker"]
      }
    },
    {
      "id": "cozy_home",
      "labels": {
        "en": ["Cozy Buddy", "Room Ranger", "Home Guardian"],
        "de": ["Wohlfuehl Buddy", "Raum Ranger", "Zuhause Hueter"]
      }
    }
  ]
}
```

#### PUT /game/role-title-pack
Select active role-title pack for the household.

**Request:**
```json
{
  "pack_id": "team_energy"
}
```

#### GET /game/leaderboard
Get user leaderboard.

**Query Parameters:**
- `period` (optional): `week`, `month`, `all_time` (default: `week`)

**Response:**
```json
[
  {
    "user_id": 1,
    "display_name": "Alice",
    "contribution_score": 320,
    "team_sparks": 450,
    "tasks_completed": 23,
    "current_streak": 7,
    "rank": 1
  }
]
```

#### GET /game/medals
Get medals for current week.

**Response:**
```json
[
  {
    "medal_type": "team_anchor",
    "user_id": 1,
    "user_name": "Alice",
    "value": 8,
    "description": "The Team Anchor (most high-energy tasks)"
  },
  {
    "medal_type": "flow_keeper",
    "user_id": 1,
    "user_name": "Alice",
    "value": 8,
    "description": "The Flow Keeper (most consistent effort)"
  }
]
```

### Users

#### GET /users
List all users in the house.

**Response:**
```json
[
  {
    "id": 1,
    "nextcloud_user_id": "alice",
    "display_name": "Alice",
    "contribution_score": 320,
    "team_sparks": 450,
    "current_streak": 7,
    "role": "owner",
    "role_title": "Team Anchor",
    "availability": {
      "monday": true,
      "tuesday": true,
      "wednesday": true,
      "thursday": true,
      "friday": true,
      "saturday": false,
      "sunday": false
    },
    "joined_at": "2026-01-01T00:00:00Z"
  }
]
```

#### POST /users/invite
Invite a Nextcloud user to the house.

**Request:**
```json
{
  "nextcloud_user_id": "bob",
  "role": "member"
}
```

#### DELETE /users/{id}
Remove user from house (owner only).

## State Transitions (MVP)

### Streak progression

For each member, the streak engine evaluates only eligible days:

- eligible day: member is available on that weekday
- non-eligible day: member is absent by configuration

Rules:

1. Completing at least one eligible task on an eligible day increments the streak by 1.
2. Non-eligible days are skipped and never break streaks.
3. Missing completion on an eligible day resets streak to 0.
4. Streak milestone rewards are granted once per milestone threshold.

### Task Pass state rules

`POST /room-tasks/{id}/pass` is allowed only when all conditions are true:

- caller owns at least one available Task Pass
- room task is active and not completed
- target member is available on task day
- task is assignable and not locked by system policy

On success:

- assignment moves from `from_user_id` to `to_user_id`
- caller Task Pass count decrements by 1
- receiver reward multiplier for this task is set to configured value (default `2.0`)

## Validation Rules

### `POST /room-tasks/{id}/pass`

- `to_user_id` is required and must be a member of the same house
- `to_user_id` must differ from current assignee
- `reason` is optional, max length 120

### `PUT /game/role-title-pack`

- `pack_id` is required
- `pack_id` must match an existing built-in localized pack identifier

### Reminder object (`tasks` create/update)

- `reminder.mode` in: `none`, `at_due`, `before_due`
- `reminder.minutes_before` required only when mode is `before_due`
- `reminder.minutes_before` must be between `1` and `10080`
- `reminder.repeat_while_overdue` must be boolean

### Task scope and lifecycle (`tasks` create/update)

- `scope_type` in: `room`, `garden`, `home`, `outside`, `work`
- `scope_label` optional, max length 255
- `recurrence_mode` in: `one_time`, `recurring`
- `active_from` optional ISO datetime
- `active_until` optional ISO datetime
- `active_until` must be equal or greater than `active_from` when both are present
- `is_voluntary` must be boolean

## Endpoint Error Matrix

### `POST /room-tasks/{id}/pass`

- `NOT_FOUND`: room task or target user does not exist
- `FORBIDDEN`: caller cannot reassign this task
- `RULE_VIOLATION`: no Task Pass available, target unavailable, or task not passable
- `VALIDATION_ERROR`: invalid payload

### Scheduling

Manage member availability and energy budgets for fair task scheduling.

#### `GET /scheduling/preferences`

Get current user's scheduling preferences.

**Response:**
```json
{
  "userId": 1,
  "energyBudget": {
    "monday": 10,
    "tuesday": 10,
    "wednesday": 10,
    "thursday": 10,
    "friday": 10,
    "saturday": 15,
    "sunday": 15
  },
  "weekdayAvailability": {
    "monday": true,
    "tuesday": true,
    "wednesday": true,
    "thursday": true,
    "friday": true,
    "saturday": true,
    "sunday": true
  }
}
```

#### `PUT /scheduling/energy`

Update energy budget for a specific day.

**Request:**
```json
{
  "day": "monday",
  "energy": 12
}
```

**Validation:**
- `energy` must be between 1 and 20

#### `PUT /scheduling/availability`

Update availability for a specific day.

**Request:**
```json
{
  "day": "saturday",
  "available": false
}
```

#### `GET /scheduling/members`

Get all member availability (house owner only).

**Response:**
```json
[
  {
    "userId": 1,
    "userName": "Alice",
    "energyBudget": { ... },
    "weekdayAvailability": { ... }
  }
]
```

### Task Sync

Synchronize task completion with Nextcloud Tasks (CalDAV).

#### `POST /sync/complete/{roomTaskId}`

Complete a room task and sync to Nextcloud Tasks.

**Request:**
```json
{
  "nextcloudTaskId": 123
}
```

**Response:**
```json
{
  "roomTaskId": 1,
  "taskName": "Clean Kitchen",
  "completedAt": "2026-02-24T10:00:00Z",
  "nextDue": "2026-03-03",
  "synced": false
}
```

#### `POST /sync/from-nextcloud`

Sync completion from Nextcloud Task back to ChoreStrike.

**Request:**
```json
{
  "nextcloudTaskId": 123,
  "completedAt": "2026-02-24T10:00:00Z",
  "roomTaskId": 1
}
```

#### `GET /sync/reminders/{roomTaskId}`

Get reminder mapping for a room task.

**Response:**
```json
{
  "type": "absolute",
  "value": "2026-02-24T10:00:00Z"
}
```

#### `GET /sync/pending`

Get pending completions that need to be synced to Nextcloud.

#### `POST /sync/link/{roomTaskId}`

Link a room task to a Nextcloud task.

**Request:**
```json
{
  "nextcloudTaskId": 123
}
```

### Scheduling Error Codes

- `VALIDATION_ERROR`: energy value not between 1-20
- `FORBIDDEN`: not house owner for member availability access

### Task Sync Error Codes

- `SYNC_COMPLETE_ERROR`: failed to complete task sync
- `SYNC_FROM_NEXTCLOUD_ERROR`: failed to sync from Nextcloud
- `TASK_NOT_FOUND`: room task not found
- `LINK_ERROR`: failed to link tasks

## Audit Event Schema

The system records immutable gameplay events for diagnostics and fairness reviews.

Common fields:

- `event_id` (uuid)
- `event_type` (string)
- `house_id` (int)
- `actor_user_id` (int)
- `created_at` (ISO 8601)
- `payload` (json object)

### Event types

- `task_pass_used`
  - payload: `room_task_id`, `from_user_id`, `to_user_id`, `receiver_reward_multiplier`, `remaining_task_passes`
- `streak_incremented`
  - payload: `user_id`, `new_streak`, `eligible_date`
- `streak_reset`
  - payload: `user_id`, `previous_streak`, `missed_eligible_date`
- `streak_milestone_reached`
  - payload: `user_id`, `milestone`, `reward_type`, `reward_amount`
- `role_title_pack_changed`
  - payload: `previous_pack_id`, `new_pack_id`

## Error Codes

- `VALIDATION_ERROR`: Invalid input data
- `NOT_FOUND`: Resource not found
- `FORBIDDEN`: Insufficient permissions
- `CONFLICT`: Resource conflict (e.g., duplicate)
- `RULE_VIOLATION`: Action blocked by gameplay rules (e.g., no Task Pass left)
- `INTERNAL_ERROR`: Server error

## Rate Limiting

No rate limiting currently implemented. May be added in future versions.

## Pagination

List endpoints support pagination via query parameters:

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `limit` | integer | 50 | Maximum items per page (max: 100) |
| `offset` | integer | 0 | Number of items to skip |

**Example:**
```
GET /tasks?limit=20&offset=40
```

**Paginated Response:**
```json
{
  "success": true,
  "data": [...],
  "pagination": {
    "total": 150,
    "limit": 20,
    "offset": 40,
    "has_more": true,
    "next_offset": 60
  }
}
```

## HTTP Status Codes

| Status | Meaning | Usage |
|--------|---------|-------|
| 200 | OK | Successful GET, PUT, DELETE |
| 201 | Created | Successful POST (resource created) |
| 400 | Bad Request | Validation errors, malformed JSON |
| 401 | Unauthorized | Not authenticated or session expired |
| 403 | Forbidden | Insufficient permissions |
| 404 | Not Found | Resource does not exist |
| 409 | Conflict | Resource conflict (e.g., duplicate name) |
| 422 | Unprocessable Entity | Business rule violation |
| 500 | Internal Server Error | Unexpected server error |
| 503 | Service Unavailable | Maintenance or overload |

## Quick Reference

### Common Operations

**Setup a new house:**
```bash
# 1. Get or create house
GET /house

# 2. Create floors
POST /floors
{ "name": "Ground Floor", "sort_order": 0 }

# 3. Create rooms
POST /rooms
{ "floor_id": 1, "name": "Kitchen" }

# 4. Create tasks from templates
GET /tasks/templates
POST /tasks
{ "name": "Vacuum", "energy_cost": 2, ... }

# 5. Assign tasks to rooms
POST /room-tasks
{ "task_id": 1, "room_id": 1 }
```

**Daily workflow:**
```bash
# Get overdue tasks
GET /room-tasks?status=overdue

# Complete a task
POST /room-tasks/{id}/complete

# Check leaderboard
GET /game/leaderboard

# View medals
GET /game/medals
```

### API Versioning

Current version: **v1**

Version is specified in the URL path: `/apps/chorestrike/api/v1/`

Future versions will use `/api/v2/`, etc. v1 will be supported for at least 6 months after v2 release.

## SDKs and Tools

### Official SDKs
- JavaScript/TypeScript: `@chorestrike/sdk` (planned)
- Python: `chorestrike-python` (planned)

### Community SDKs
None yet - contributions welcome!

## Support

### Getting Help
- GitHub Issues: [github.com/chorestrike/issues](https://github.com/chorestrike/issues)
- Documentation: [docs.chorestrike.com](https://docs.chorestrike.com)
- API Status: [status.chorestrike.com](https://status.chorestrike.com)

### Reporting Issues
When reporting API issues, please include:
- Request URL and method
- Request headers (redact sensitive data)
- Request body (if applicable)
- Response status code
- Response body
- Timestamp of request

---

**Last Updated**: 2026-02-24

**Document Version**: 1.0

**API Version**: v1
