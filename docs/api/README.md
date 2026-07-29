# ChoreStrike API Documentation

## Base URL

All API endpoints are prefixed with `/apps/chorestrike/api/v1`

## Authentication

All requests require Nextcloud session authentication. CSRF tokens are validated automatically.

**Note:** Test Data API endpoints are exempt from CSRF checks and available only in test mode.

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

**Important:** API error handling distinguishes between:
- **`code`**: Stable, machine-readable error identifier (e.g., `VALIDATION_ERROR`, `USER_NOT_FOUND`). This code is **contract-stable** and will not change between versions. Clients should use this for programmatic error handling.
- **`message`**: Localized, human-readable description. This text is translated based on the user's Nextcloud language setting and may change between versions. **Do not parse or rely on message text for logic.**

Example localized error (German locale):
```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "E-Mail ist erforderlich"
  }
}
```

Same error (English locale):
```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "Email is required"
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
  "tasks": [
    {
      "id": 1,
      "name": "Empty dishwasher",
      "energyCost": 2,
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
List all tasks visible to the current user (role-based visibility filtering applies — private tasks are only visible to their owner, admin-only tasks only to house owners).

**Query Parameters:**
- `is_template` (boolean): Filter templates

**Response:**
```json
[
  {
    "id": 1,
    "name": "Vacuum",
    "description": "Vacuum all floors",
    "energyCost": 3,
    "priority": 2,
    "frequency_type": "weekly",
    "frequency_value": 1,
    "estimated_minutes": 30,
    "category": "cleaning",
    "is_template": false,
    "visibilityType": "public",
    "isPrivate": false,
    "ownerUserId": null
  }
]
```

> **Visibility types:** `public` (visible to all members), `private` (visible only to `ownerUserId`), `admin-only` (visible only to house owners). Only owners can create/modify admin-only tasks.

#### GET /tasks/my-private
List the current user's private tasks only.

**Response:** Same shape as `GET /tasks`, filtered to `visibilityType='private'` owned by the current user.

#### GET /tasks/templates
Get predefined task templates.

**Response:**
```json
[
  {
    "id": "template_1",
    "name": "Empty Dishwasher",
    "energyCost": 2,
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
  "energyCost": 4,
  "priority": 3,
  "frequency_type": "monthly",
  "frequency_value": 1,
  "estimated_minutes": 60,
  "category": "cleaning",
  "visibilityType": "public"
}
```

> **visibilityType** (optional, default `public`): One of `public`, `private`, `admin-only`. `admin-only` requires house-owner role. Private tasks automatically record the creator as `ownerUserId`. The legacy `isPrivate` boolean is still accepted for backwards compatibility (`isPrivate=true` with `visibilityType='public'` resolves to `private`).

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
    "energyCost": 3,
    "priority": 2,
    "next_due": "2026-02-25T10:00:00Z",
    "last_completed": "2026-02-18T10:00:00Z",
    "assigned_to_user_id": null,
    "is_overdue": false,
    "days_until_due": 1
  }
]
```

> **`assigned_to_user_id`** is the legacy single-assignee field, retained
> alongside the multi-assignee `chorestrike_task_assignees` table (CS-PLAN-002).
> It is still set via the POST body (`assignedToUserId`) and the assign endpoint,
> returned as `assignedToUserId`, and merged into the assignee set by
> `TaskAssigneeMapper`. See `architecture.md` (`chorestrike_room_tasks`).

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

#### PUT /room-tasks/{id}/due-date
Update a room task's next-due date / ideal due date.

#### PUT /room-tasks/{id}/assignees
Replace the multi-assignee set for a room task (`POST`/`DELETE` on the same
sub-path add/remove a single assignee; `GET` reads them). Assignee mutations
are validated for house membership server-side.

#### POST /room-tasks/{id}/deactivate · POST /room-tasks/{id}/reactivate
Deactivate / re-activate a room-task instance.

#### DELETE /room-tasks/{id}
Remove task from room.

> **Full route list:** the canonical, complete route table is
> [`appinfo/routes.php`](../../appinfo/routes.php) (132 routes spanning `house`,
> `floor`, `room`, `task`, `room_task`, `game`, `user`, `scheduling`,
> `smart_scheduler`, `template`, `checklist`, `task_sync`, `minigame`,
> `reward_config`, and `test`). This document covers the core CRUD + completion
> flows; the `RouteMatrixTest` contract test guards route↔controller drift.

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
  "coins_earned": 45,
  "was_combo": true,
  "combo_with_user": "John",
  "new_cleanliness": 85,
  "next_due": "2026-02-25T15:30:00Z"
}
```

#### GET /room-tasks/range?from=&to=
Get scheduled and completed tasks within a date range (calendar view). Max span: 60 days.

**Parameters:**
- `from` (required): Start date, `Y-m-d` format.
- `to` (required): End date, `Y-m-d` format.

**Response:**
```json
{
  "success": true,
  "data": {
    "scheduled": [
      {
        "roomTask": { "id": 1, "nextDue": "2026-07-28", "isActive": true },
        "task": { "id": 5, "name": "Vacuum", "energyCost": 3 },
        "room": { "id": 2, "name": "Living Room" },
        "flexWindow": { "earliest": "2026-07-26", "ideal": "2026-07-28", "latest": "2026-07-30" }
      }
    ],
    "completed": [
      {
        "roomTaskId": 3,
        "taskId": 7,
        "taskName": "Dishes",
        "roomId": 1,
        "roomName": "Kitchen",
        "completedAt": "2026-07-28 14:30:00",
        "completedByUserId": 2
      }
    ]
  }
}
```

**Errors:** `400 VALIDATION_ERROR` (unparseable date, `from > to`, span > 60 days), `403 FORBIDDEN` (non-member).

### Game

#### GET /game/state
Get current game state.

**Response:**
```json
{
  "house_health": 85,
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
    "coins": 450,
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
    "value": 12,
    "description": "Completed 12+ tasks this week"
  }
]
```

### Users

> **Title fields on user objects:** every user object returned by `GET /users`, the active-user endpoint, and the leaderboard is enriched with two title fields:
> - `selectedTitlePack` (`string|null`) — the pack the user has chosen to display (null = none chosen).
> - `currentTitle` (`object|null`) — the server-computed highest tier the user qualifies for within `selectedTitlePack` (same shape as `currentTitle` under `GET /users/{id}/titles` below), or null.
>
> The full `unlockedTitles` list is **not** on the generic user payload — fetch it via `GET /users/{id}/titles`.

#### GET /users
List all users in the house.

**Response:**
```json
[
  {
    "id": 1,
    "nextcloud_user_id": "alice",
    "display_name": "Alice",
    "coins": 450,
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

#### GET /users/{id}/titles
Get the role-title state for a user: the current title (server-computed from
live stats), every tier the user qualifies for across all packs, the selected
pack, and the full pack/tier catalog (with per-house threshold overrides) so
the frontend can render the picker without a second call. House-members only.

**Response `data`:**
```json
{
  "currentTitle": {
    "pack": "team_energy",
    "packLabelKey": "titles.pack.team_energy",
    "tierIndex": 1,
    "tierKey": "flow_keeper",
    "labelKey": "titles.team_energy.flow_keeper",
    "threshold": 30
  },
  "unlockedTitles": [/* …tier descriptors… */],
  "selectedTitlePack": "team_energy",
  "catalog": {
    "home_helpers": { "labelKey": "…", "signal": "contributionScore", "tiers": [/* … */] }
  }
}
```

#### PUT /users/{id}/titles/select
Select the title pack the user displays. Titles are identity, not admin — the
requester must be the target user or a house owner. Validates the pack key
against the catalog.

**Request:**
```json
{ "pack": "team_energy" }
```

## Error Codes

The following stable error codes are returned by the API. **Note:** The `message` field is localized and may vary; always use `code` for programmatic handling.

| Code | HTTP Status | Description |
|------|-------------|-------------|
| `VALIDATION_ERROR` | 400 | Invalid input data (e.g., missing required field, invalid format) |
| `RESOURCE_NOT_FOUND` | 404 | Requested resource does not exist or user lacks visibility |
| `USER_NOT_FOUND` | 404 | Specified Nextcloud user does not exist |
| `FORBIDDEN` | 403 | User lacks permission for this action |
| `CONFLICT` | 409 | Resource conflict (e.g., duplicate entry) |
| `USERS_ERROR` | 500 | Internal error during users operation |
| `INTERNAL_ERROR` | 500 | Generic server error |

### Smart Scheduling Error Codes

| Code | HTTP Status | Description |
|------|-------------|-------------|
| `NO_HOUSE_FOUND` | 404 | Current user has no associated house |

### Placeholder Interpolation

Some validation messages include dynamic values using `{placeholder}` syntax. The localized `message` field will have placeholders replaced with actual values:

**Example:**
- Error code: `VALIDATION_ERROR`
- Message template: `"Invalid day: {day}"`
- Localized result (EN): `"Invalid day: monday"`
- Localized result (DE): `"Ungültiger Tag: monday"`

Clients should **not** parse these messages; they are for display purposes only.

## Test Data API

**Requires an authenticated Nextcloud session** (`@NoAdminRequired`) **and test
mode** (`NEXTCLOUD_TEST_MODE=true` on the PHP process). A generic `CI` env var
does **not** activate test mode (CS-PLAN-019). The canonical seed path is
`occ chorestrike:test-data:seed`; these HTTP endpoints are a secondary
convenience for authenticated test tooling.

### GET /test/profiles

Get available test data profiles.

**Response:**
```json
{
  "success": true,
  "data": {
    "profiles": [
      {
        "name": "empty",
        "description": "Clean database (no data)",
        "users": 0,
        "rooms": 0,
        "tasks": 0
      },
      {
        "name": "minimal",
        "description": "1 House, 1 Floor, 1 Room, 2 Users",
        "users": 2,
        "rooms": 1,
        "tasks": 0
      },
      {
        "name": "family",
        "description": "Complete family: 6 Users, 3 Floors, 6 Rooms, 12 Tasks",
        "users": 6,
        "rooms": 6,
        "tasks": 12
      }
    ]
  }
}
```

### POST /test/seed/{profile}

Seed database with a specific profile. (Mutating verbs are POST, not GET —
CS-PLAN-019 hardening.)

**Path Parameters:**
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `profile` | string | No | Profile name (default: `minimal`) |

**Response:**
```json
{
  "success": true,
  "data": {
    "profile": "family",
    "data": {
      "house": { "id": 1, "name": "Family Home" },
      "floors": [...],
      "rooms": [...],
      "users": [...],
      "tasks": [...],
      "roomTasks": [...]
    }
  }
}
```

### POST /test/reset

Reset database (equivalent to seeding with `empty` profile).

**Response:**
```json
{
  "success": true,
  "message": "Database reset complete",
  "data": {
    "profile": "empty",
    "data": []
  }
}
```

### Test Data Error Codes

| Code | HTTP Status | Description |
|------|-------------|-------------|
| `NOT_TEST_MODE` | 403 | Test data API only available in test mode |
| `INVALID_PROFILE` | 400 | Invalid profile name provided |
| `SEED_ERROR` | 500 | Error during database seeding |
| `RESET_ERROR` | 500 | Error during database reset |

## Rate Limiting

No rate limiting currently implemented. May be added in future versions.

## Webhooks (Future)

Planned for future versions to support real-time notifications.
