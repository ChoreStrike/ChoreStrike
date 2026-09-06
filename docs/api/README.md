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

### Authorization & Status Semantics (ADR-010)

- **Membership is invite-only after the first owner.** The five bootstrap
  endpoints (`GET /users/current`, `GET /house`, `POST /users/invite`,
  `GET /setup/catalog`, `POST /setup/apply`) bootstrap the first owner of a
  fresh instance; every other endpoint requires an existing membership
  (`403 FORBIDDEN` for non-members). Removed members stay removed — no read
  re-creates their membership.
- **Denial standard:** visibility denials (nonexistent id, foreign-house id,
  or a task the caller cannot see per the visibility matrix) return
  **`404 RESOURCE_NOT_FOUND`** — anti-enumeration. Role denials on otherwise
  visible resources (child attempting management, non-owner structure change)
  return **`403 FORBIDDEN`**.
- **Task visibility matrix:** `public` → all members; `private` → creator ∪
  explicit assignees; `admin-only` → house owners. Enforced by a single
  service (`TaskAccessPolicy`) across reads, assignment, completion,
  lifecycle, and checklists.
- Rate-limited minigame score saves answer `429 MINIGAME_RATE_LIMITED`
  (>100 submissions per house-local day).

## Endpoints

### House

#### GET /house
Get current user's house information.

**Response:**
```json
{
  "id": 1,
  "name": "My Home",
  "houseHealth": 85,
  "timezone": "Europe/Berlin",
  "createdAt": "2026-02-24T10:00:00Z"
}
```

`timezone` (CS-PLAN-057) is the IANA id used for all calendar-day bucketing
(streaks, freezes, due-today, medal weeks, duplicate guard). Captured from
the creator's Nextcloud timezone at house creation; `UTC` fallback.

#### PUT /house
Update house name and (owner only) timezone.

**Request:**
```json
{
  "name": "Updated Home Name",
  "timezone": "Europe/Berlin"
}
```

- `timezone` is optional; omitting it (or an empty string) leaves the
  current timezone unchanged.
- Unknown IANA identifiers are rejected with 400 `VALIDATION_ERROR`
  ("Unknown timezone") — closed-set validation against the full IANA list.
- Requires house ownership (403 otherwise).
#### GET /house/stats
House overview statistics for the Insights view (CS-PLAN-040).

**Response:**
```json
{
  "house": { "id": 1, "name": "My Home", "houseHealth": 85 },
  "stats": {
    "totalRooms": 6,
    "cleanRooms": 4,
    "dirtyRooms": 2,
    "roomsNeedingCleaning": 1,
    "houseHealth": 85
  }
}
```

#### GET /house/activity?days=&limit=&userId=
Completion activity feed ("who did what when", CS-PLAN-040). Also the
per-member completed-energy source for the Insights fairness table.

**Query parameters:**
- `days` — period length in days, clamped to 1–90 (default 30)
- `limit` — maximum completions, clamped to 1–200 (default 50)
- `userId` — optional Nextcloud user ID filter; must be a member of the house (else 404 `USER_NOT_FOUND`)

**Response:**
```json
{
  "completions": [
    {
      "id": 42,
      "completedAt": "2026-08-21T10:00:00+00:00",
      "wasCombo": false,
      "coinsEarned": 12,
      "comboWithUserId": null,
      "isTeamCompletion": false,
      "participantCount": 1,
      "teamSparkBonus": 0,
      "wasClaimedCompletion": false,
      "claimBonusSparks": 0,
      "houseUserId": 7,
      "displayName": "Alice",
      "taskId": 5,
      "taskTitle": "Vacuum living room",
      "energyCost": 3,
      "roomId": 2,
      "roomName": "Living Room"
    }
  ],
  "total": 1
}
```

**Note:** completions whose room task was deleted later keep appearing with
`taskId`/`taskTitle`/`energyCost`/`roomId`/`roomName` = `null` — the UI
renders a fallback label for them (single-task deletion does not cascade its
completion history).

### Floors

> Floor reads happen through `GET /floors/with-rooms` (the only list/detail
> surface). `GET /floors`, `GET /floors/{id}`, the `POST /floor-create` /
> `POST /floors/create` aliases and `FloorController::index`'s GET-create
> branch were pruned in CS-PLAN-055 (no consumer; closes BUG-ROOM-001).

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
Delete floor (transactionally cascades its rooms and room tasks, CS-PLAN-037).

> **Authorization (CR-053-03):** owner-only — members/children get
> `403 FORBIDDEN`; the floor (and its cascade) stays intact.

### Setup Wizard

First-run house setup (CS-PLAN-027). Both endpoints require house membership;
`apply` additionally requires the owner role (CS-PLAN-061 §3.4). The wizard is
gated to a house with **no floors**; since CS-PLAN-062 that gate is enforced
**inside the setup transaction** (house row lock via `findForUpdate`, then a
floor-count recheck), so a raced second apply is guaranteed `409
HOUSE_ALREADY_SET_UP` with exactly one structure committed.

#### GET /setup/catalog
Localized room types + leveled templates for the wizard and the per-room picker.

**Response:**
```json
{
  "levels": [{ "id": "putzteufel", "label": "Putzteufel", "description": "..." }],
  "roomTypes": [{ "id": "kitchen", "defaultName": "Kitchen", "icon": "Utensils", "templateIds": ["kitchen_counters"] }],
  "templates": [{ "id": "kitchen_counters", "name": "Wipe kitchen counters", "roomTypes": ["kitchen"], "levels": { "putzteufel": { "frequencyType": "daily", "frequencyValue": 1, "estimatedMinutes": 10 }, "putzmuffel": null } }]
}
```

#### POST /setup/apply
Create floors, rooms (with `roomType`), tasks from the leveled catalog, and
per-room assignments — plus the optional house rename — in one transaction
(CS-PLAN-062). Any failure (validation, gate, revalidation, persistence) rolls
back every write of the run.

**Request:**
```json
{
  "houseName": "Family Home",
  "intensityLevel": "putzteufel",
  "options": { "createHouseholdRoom": true, "createHallways": true },
  "floors": [
    { "name": "Ground Floor", "rooms": [ { "name": "Kitchen", "roomType": "kitchen", "icon": "Utensils", "assigneeUserId": "alice" } ] }
  ]
}
```

`houseName` is optional (D1): absent or blank keeps the current name; a
present value is trimmed and length-validated like any other name (invalid →
`400 VALIDATION_ERROR` before any write). Assignee memberships are revalidated
under the house lock at the transaction boundary — an assignee removed after
the pre-check fails the whole apply with `400` and zero persisted rows.

**Response:** `{ "floors": 1, "rooms": 4, "tasks": 12, "roomTasks": 12, "skipped": [], "houseName": "Family Home" }` —
`houseName` echoes the **effective** name so the wizard can confirm it.

> See [house-setup-and-templates.md](../concepts/house-setup-and-templates.md).

### Rooms

> `GET /rooms`, `GET /rooms/{id}`, `GET /rooms/needing-cleaning` and the
> `POST /room-create` / `POST /rooms/create` aliases were pruned in
> CS-PLAN-055 (no production consumer) — room data is delivered by
> `GET /floors/with-rooms`, per-room tasks via `GET /room-tasks?roomId=`.
> The phantom `description` field (no backing column) is gone from the
> `Room` serialization as part of the same prune (pre-release, no BC).

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

> **Authorization (CR-053-03):** owner-only — members/children get
> `403 FORBIDDEN`; the room (and its cascade) stays intact.

### Tasks

#### GET /tasks
List all tasks visible to the current user (role-based visibility filtering applies — private tasks are only visible to their owner, admin-only tasks only to house owners).

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
    "visibilityType": "public",
    "ownerUserId": null
  }
]
```

> **Visibility types:** `public` (visible to all members), `private` (visible only to `ownerUserId`), `admin-only` (visible only to house owners). Only owners can create/modify admin-only tasks.

> The task-definition read/aggregate surface — `GET /tasks/{id}`,
> `GET /tasks/my-private`, `GET /tasks/category/{category}`,
> `GET /tasks/search`, `GET /tasks/categories`, `GET /tasks/daily`,
> `PUT /tasks/{id}/complete|skip|reschedule` and `DELETE /tasks/{id}` — was
> pruned in CS-PLAN-055 (no production consumer). Tasks are consumed via
> `GET /tasks` and mounted on rooms through `GET /room-tasks`. The lifecycle
> `PUT /tasks/{id}/uncomplete` stays (undo path, see Room Tasks).

> **Catalog templates:** the DB-backed `is_template` / `GET /tasks/templates`
> system was removed (CS-PLAN-027 D7). Task templates now come from the
> [setup catalog](#setup-wizard) (`GET /setup/catalog`) and are materialized
> per room via `POST /room-tasks/from-template`. See
> [house-setup-and-templates.md](../concepts/house-setup-and-templates.md).

#### POST /tasks (pruned, CS-PLAN-063)

The definition-only create endpoint was pruned in CS-PLAN-063 (CR-WF-03): its
last consumer moved to the atomic creation aggregate. Creation runs
exclusively through `POST /room-tasks` (custom definition or `taskId` mount)
and `POST /room-tasks/from-template`. Re-adding it requires a consumer and a
new expose decision (CS-PLAN-055 route-consumer rule; guarded by
`RouteMatrixTest`).

#### PUT /tasks/{id}
Update task. Value validation is service-owned (`TaskService::validateTaskData`,
CS-PLAN-063 §3.1) — the controller only shapes the envelope and maps the
owner gate. A 400 carries the localized `details[]` error list.

> **`taskTier` (CR-053-04):** owner-only and value-checked — accepted values
> are `bronze`, `silver`, `gold` (the tier drives the completion coin
> multiplier). A member sending `taskTier` gets `403 FORBIDDEN`; any other
> value gets `400 VALIDATION_ERROR`. All other whitelisted fields remain
> member-writable.

> **Closed constraint set (CS-PLAN-063 §3.1):** every accepted update field
> is range/type-checked — `schedulingMode` ∈ `dynamic|static`, parseable
> `fixedScheduleTime` / `activeFrom` / `activeUntil`, `fixedScheduleDays` as a
> weekday list over `monday`…`sunday` (canonical JSON persisted),
> `flexDays` ∈ [0, 7], `instructions`/`tips` ≤ 2000 chars, and strict types
> (non-strings/floats are rejected with 400, never coerced). Empty strings
> clear a date field.

### Room Tasks

#### POST /room-tasks (creation aggregate, CS-PLAN-063)
Create a visible, valid room task — or nothing at all. Exactly one request
commits the whole aggregate (task definition, room-task materialization,
initial assignee rows) in one service-owned transaction
(`TaskCreationService`); any failure rolls back everything (no orphan
definitions).

Two mutually exclusive payload variants:
- **custom:** `{ roomId, task: {definition…}, assigneeUserIds?, nextDue? }`
  with an inline definition (`name`, `description`, `energyCost`, `priority`,
  `frequencyType`, `frequencyValue`, `estimatedMinutes`, `category`,
  `recurrenceMode`, `activeFrom`, `activeUntil`, `schedulingMode`,
  `fixedScheduleTime`, `fixedScheduleDays`, `flexDays`, `instructions`,
  `visibilityType` — the same closed constraint set as `PUT /tasks/{id}`)
- **mount:** `{ roomId, taskId, assigneeUserIds?, nextDue? }` — reuses an
  existing, actor-visible definition (duplicate mount → 400)

`nextDue` (house-local date) defaults to tomorrow; `flexDays > 0`
materializes an `idealDueDate`. The response is the unified aggregate shape:

```json
{ "task": {…}, "roomTask": {…}, "assignees": [ {…} ] }
```

Gate order: children → `403`; cross-house room → `404` (anti-enumeration);
invisible definition → `404`; `admin-only` creation by a non-owner → `403`;
`private` creation stamps the creator as `ownerUserId`. Inside the
transaction the house row is locked first (documented order house →
room_task → dependents), every assignee membership is revalidated under the
lock (a removed member → 400 and zero writes), and the mount-duplicate guard
is re-checked under the lock.

#### POST /room-tasks/from-template
Create a room task from a catalog template (per-room picker). The `roomId` is
resolved server-side and validated against the caller's house; an
`assigneeUserId` (if set) is checked for membership. Materialized templates
run through the same validation authority, atomic body, and gates as the
custom path (a whitelisted override outside the closed set rejects with 400
before any write).

**Request:**
```json
{
  "roomId": 12,
  "templateId": "kitchen_counters",
  "level": "putzteufel",
  "overrides": { "name": "My counters", "estimatedMinutes": 8 },
  "assigneeUserId": "alice"
}
```

> `level` is optional (default `putzteufel`). `overrides` is optional and
> restricted to `name`, `frequencyType`, `frequencyValue`, `estimatedMinutes`,
> `energyCost`, `priority`.

**Response:** the unified aggregate shape `{task, roomTask, assignees}`
(CS-PLAN-063 D1).

#### GET /room-tasks
List all room-task assignments.

**Query Parameters:**
- `room_id` (optional): Filter by room
- `status` (optional): `due`, `overdue`, `upcoming`

> **Authorization (CR-053-02):** house-scoped — a `room_id` from another
> house is rejected with `404 RESOURCE_NOT_FOUND`, and the `scope_id`
> branch silently filters cross-house entries (skip-on-missing).

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
    "assignees": [],
    "is_overdue": false,
    "days_until_due": 1
  }
]
```

> **`assignees`** is the canonical single assignee representation
> (CS-PLAN-060 WP1 D2): the `chorestrike_task_assignees` join table keyed by
> Nextcloud user IDs. The legacy `room_tasks.assigned_to_user_id` scalar
> column and its payload key were folded out — reads never merge legacy and
> canonical state. Initial single-assignee assignment stays available via
> the `POST /room-tasks` body (`assignedToUserId`, written as a join row).
> See `architecture.md` (`chorestrike_room_tasks`). The standalone
> `POST /room-tasks/{id}/assign` / `…/unassign` and single-assignee
> `POST|DELETE …/assignees` variants were pruned in CS-PLAN-055 — assignment
> goes through `PUT …/assignees` or `POST /room-tasks/bulk-assign`.

#### POST /room-tasks
Assign task to room(s).

**Request:**
```json
{
  "task_id": 1,
  "room_ids": [1, 2, 3],
  "assignedToUserId": null
}
```

#### PUT /room-tasks/{id}/due-date
Update a room task's next-due date / ideal due date.

#### GET /room-tasks/{id}/assignees · PUT /room-tasks/{id}/assignees
Read / replace the multi-assignee set for a room task. Assignee mutations
are validated for house membership server-side.

#### POST /room-tasks/{id}/reactivate
Re-activate a room-task instance (undo path for one-time tasks whose
completion deactivated them; `deactivate` was pruned in CS-PLAN-055).

#### DELETE /room-tasks/{id}
Remove task from room.

> **Lifecycle authorization (CS-PLAN-056, CR-053-06; unified in ADR-010 /
> CS-PLAN-061):** the surviving lifecycle mutations —
> `PUT /tasks/{id}/uncomplete`, `PUT /room-tasks/{id}/due-date`, and
> `POST /room-tasks/{id}/reactivate` — enforce the ONE task-visibility matrix
> via `TaskAccessPolicy`, the same as `POST /room-tasks/{id}/complete`
> (public → any member; private → creator ∪ that room task's explicit
> assignees; admin-only → house owner). **Visibility denials return `404
> RESOURCE_NOT_FOUND`** (anti-enumeration, decision D3) — never a 403 that
> would confirm the id exists; role denials (a child actor) stay
> `403 FORBIDDEN`.

> **Full route list:** the canonical, complete route table is
> [`appinfo/routes.php`](../../appinfo/routes.php) (71 routes spanning `house`,
> `floor`, `room`, `task`, `room_task`, `game`, `user`, `scheduling`,
> `checklist`, `minigame`, `setup`, and `test`). The task-sync ×6 and
> reward-config ×2 HTTP surfaces were pruned in CS-PLAN-055 together with
> 31 further no-consumer/test-only routes (disposition list + guard in
> `RouteMatrixTest`); re-adding a route requires a consumer.
> This document covers the core CRUD + completion
> flows; the `RouteMatrixTest` contract test guards route↔controller drift.

#### POST /room-tasks/{id}/complete
Complete the current room-task occurrence. The completion timestamp is resolved
server-side; the request has no body.

**Response:**
```json
{
  "success": true,
  "data": {
    "roomTask": { "id": 12, "nextDue": "2026-02-25T15:30:00Z", "isActive": true },
    "room": { "id": 3, "cleanliness": 85 },
    "task": { "id": 7, "energyCost": 3, "priority": 2 },
    "rewards": {
      "cleanlinessChange": 30,
      "energyCost": 3,
      "priority": 2,
      "contributionScore": 3,
      "coins": 45,
      "isCombo": true,
      "currentStreak": 4,
      "teamworkBonus": 0,
      "weeklyGoal": { "percent": 72, "achieved": false }
    }
  }
}
```

#### GET /room-tasks/range?from=&to=
Get scheduled and completed tasks within a date range (calendar view). Max span: 60 days.

Recurring tasks are **projected** across the window: each scheduled item carries an
`occurrences` array of `Y-m-d` dates (one per future occurrence in `[from, to]`), so a
daily task appears on every day it recurs. One-time tasks emit a single occurrence.

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
        "flexWindow": { "earliest": "2026-07-26", "ideal": "2026-07-28", "latest": "2026-07-30" },
        "occurrences": ["2026-07-28", "2026-08-04", "2026-08-11"]
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

#### GET /room-tasks/room/{roomId}/stats
Room task statistics for the RoomDetail stats card (CS-PLAN-040). Rejects a
cross-house `roomId` with `404 RESOURCE_NOT_FOUND`.

**Response:**
```json
{
  "totalTasks": 5,
  "activeTasks": 3,
  "overdueTasks": 1,
  "dueTodayTasks": 2,
  "dueSoonTasks": 1,
  "completionRate": 40
}
```

> **`completionRate` semantics (CR-SVC-31 root fix):** share of the room's
> current tasks with at least one real completion row — deactivated/skipped
> tasks do NOT count as completed; an empty room returns `0`.

### Checklists

Checklist endpoints for tasks and room tasks. Business logic and
authorization live in `ChecklistService` (CR-053-01); the guard enforces
**house linkage** (room task → room → floor → house) plus the
**task-visibility matrix** (public → any member; private → task owner;
admin-only → house owner) on every endpoint. Denials return
`404 RESOURCE_NOT_FOUND` (anti-enumeration, same contract as the task
update gate).

| Endpoint | Purpose |
|----------|---------|
| `GET /checklists/task/{taskId}` | List a task's checklist items |
| `POST /checklists/task/{taskId}` | Add an item (`label`, optional `sortOrder` — appends when absent) |
| `PUT /checklists/item/{itemId}` | Relabel an item |
| `DELETE /checklists/item/{itemId}` | Delete an item |
| `POST /checklists/reorder` | Reorder items (`itemOrders`: item id → order map) |
| `GET /checklists/progress/{roomTaskId}` | Progress entries + stats |
| `POST /checklists/toggle/{roomTaskId}/{itemId}` | Toggle an item's progress (auto-creates the progress row — the pruned explicit `initialize`/`complete` pair is not needed) |

**Authorization notes (CR-053-01):**
- Cross-house task/item/room-task ids → `404 RESOURCE_NOT_FOUND` on every
  endpoint (no 403 that would confirm existence).
- Checklist items of private/admin-only tasks follow the visibility matrix:
  invisible to non-entitled members → `404`.
- Item mutations resolve item → task: a foreign item id is rejected with
  `404`; `reorder` requires all item ids to belong to **one** accessible
  task (`400 VALIDATION_ERROR` otherwise) and the payload must map integer
  item ids to integer orders (`400`).
- `toggle` verifies the item belongs to the room task's task checklist
  (`404` otherwise) — progress rows of an unrelated task cannot be flipped.

### Game

> `GET /game/leaderboard` was pruned in CS-PLAN-055 (test-only never became
> production-consumed; the UI leaderboard is built from `GET /users`).

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

### Scheduling

#### GET /scheduling/preferences
Get the current user's scheduling preferences (member-gated).

**Response `data`:**
```json
{
  "userId": 7,
  "energyBudget": { "monday": 10, "tuesday": 10, "wednesday": 10, "thursday": 10, "friday": 10, "saturday": 15, "sunday": 15 },
  "weekdayAvailability": { "monday": true, "tuesday": true, "wednesday": true, "thursday": true, "friday": true, "saturday": true, "sunday": true },
  "maxDailyTasks": null,
  "todayConsumedEnergy": 3
}
```

#### PUT /scheduling/preferences
Save the current user's weekly preferences in **one** bulk request
(CS-PLAN-033, closes CR-016-02; replaced the removed per-day
`PUT /scheduling/energy` and `PUT /scheduling/availability`).

**Request** — sections are optional, but at least one is required; each
provided section must be the **complete week** (exactly the seven canonical
weekday keys). The merge is section-granular: a provided section replaces the
stored one wholesale, absent sections and sibling keys (e.g. `maxDailyTasks`)
stay unchanged.

```json
{
  "weekdayAvailability": { "monday": true, "tuesday": false, "wednesday": true, "thursday": true, "friday": true, "saturday": true, "sunday": true },
  "energyBudget": { "monday": 10, "tuesday": 10, "wednesday": 10, "thursday": 10, "friday": 10, "saturday": 15, "sunday": 15 }
}
```

**Response `data`:** the full persisted preferences (same shape as `GET`).

**Errors:** `400 VALIDATION_ERROR` (neither section provided, partial week,
unknown weekday key, non-bool availability, energy outside 1-20), `403 FORBIDDEN`
(non-member). Persistence is a single validated read-modify-write on one
`house_user` JSON column — inherently atomic, no multi-statement transaction.

#### GET /scheduling/members
Get all member availability (house owner only).

#### PUT /scheduling/members/{userId}
Update another member's weekday availability/energy (house owner only).
#### POST /scheduling/suggestions

Get scheduling suggestions for the current week. The request carries only
`{ "taskCount": <int> }` — the backend derives its recommendations from the
member availability data plus that count (CS-PLAN-043 slimmed the contract;
the former full task array was never used beyond `count($tasks)`).

### Users

> **Title fields on user objects:** every user object returned by `GET /users`
> and `GET /users/current` is enriched with two title fields:
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
Invite a Nextcloud user to the house (owner only; the authorization gates run
before the email lookup — a non-owner cannot probe which accounts exist).

**Request:**
```json
{
  "nextcloud_user_id": "bob",
  "role": "member"
}
```

**Response `data`:** the (created or existing) house-user object of the
invited account — one truthful contract (CS-PLAN-062 D5), so per-recipient
invite feedback can name the joined user. Unknown email → `404
USER_NOT_FOUND`.

#### DELETE /users/{id}
Remove user from house (owner only).

> **Pruned (CS-PLAN-040):** `GET /users/{id}/stats` was fully dead — every
> field it returned is already part of the `GET /users` serialization, which
> since CS-PLAN-040 also includes `streakFreezes` (owned freeze count) and
> `lastFreezeDate` (ISO date of the last freeze consumption, `null` when the
> streak was never frozen) for the freeze badges. The per-user room-task
> endpoints `GET /room-tasks/user/{userId}` and
> `GET /room-tasks/user/{userId}/stats` were pruned with it; per-member
> history lives in `GET /house/activity`.
>
> **Pruned (CS-PLAN-055):** `PUT /users/{id}` (display-name /
> scheduling-preferences profile update) — its only consumer was the dead
> `updateUserProfile` store wrapper; member preferences go through
> `PUT /scheduling/preferences` / `PUT /scheduling/members/{userId}`, and
> role changes through `PUT /users/{id}/role`.

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
        "name": "emptyhouse",
        "description": "Empty house for the first-run wizard journey (family NC accounts, no ChoreStrike rows)",
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
