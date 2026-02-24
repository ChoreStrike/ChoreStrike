# ChoreWars API Documentation

## Base URL

All API endpoints are prefixed with `/apps/chorewars/api/v1`

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
      "difficulty": 2,
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

**Response:**
```json
[
  {
    "id": 1,
    "name": "Vacuum",
    "description": "Vacuum all floors",
    "difficulty": 3,
    "priority": 2,
    "frequency_type": "weekly",
    "frequency_value": 1,
    "estimated_minutes": 30,
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
    "difficulty": 2,
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
  "difficulty": 4,
  "priority": 3,
  "frequency_type": "monthly",
  "frequency_value": 1,
  "estimated_minutes": 60,
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
- `status` (optional): `due`, `overdue`, `upcoming`

**Response:**
```json
[
  {
    "id": 1,
    "room_id": 1,
    "task_id": 1,
    "task_name": "Vacuum",
    "difficulty": 3,
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
  "points_earned": 45,
  "was_combo": true,
  "combo_with_user": "John",
  "new_cleanliness": 85,
  "monsters_defeated": 2,
  "next_due": "2026-02-25T15:30:00Z"
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
  "rooms_by_status": {
    "clean": 5,
    "due": 3,
    "overdue": 2
  }
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
    "total_points": 450,
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
    "medal_type": "tank",
    "user_id": 1,
    "user_name": "Alice",
    "value": 8,
    "description": "Completed 8 difficult tasks (4-5 stars)"
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
    "total_points": 450,
    "current_streak": 7,
    "role": "owner",
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

## Error Codes

- `VALIDATION_ERROR`: Invalid input data
- `NOT_FOUND`: Resource not found
- `FORBIDDEN`: Insufficient permissions
- `CONFLICT`: Resource conflict (e.g., duplicate)
- `INTERNAL_ERROR`: Server error

## Rate Limiting

No rate limiting currently implemented. May be added in future versions.

## Webhooks (Future)

Planned for future versions to support real-time notifications.
