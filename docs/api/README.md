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
  fresh instance; other house-data endpoints require an existing membership
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
Update house name and (owner only) timezone and ranking flag.

**Request:**
```json
{
  "name": "Updated Home Name",
  "timezone": "Europe/Berlin",
  "rankingEnabled": true
}
```

- `timezone` is optional; omitting it (or an empty string) leaves the
  current timezone unchanged.
- Unknown IANA identifiers are rejected with 400 `VALIDATION_ERROR`
  ("Unknown timezone") — closed-set validation against the full IANA list.
- `rankingEnabled` (CS-PLAN-070 WP4, C5i/D10b) is optional and owner-only:
  the consent-filtered ranking gate, default off. Disabling removes all
  visible participation immediately and invalidates every consent
  generation; re-enabling starts everyone unconsented
  ([ADR-015](../adr/ADR-015-ranking-consent-and-privacy.md)).
- Requires house ownership (403 otherwise).

#### POST /house/reset
Reset the owner's house health and room state with one receipt-protected
command. Send a new UUID for each intended reset and retain it across an
uncertain network response; replaying the same command never resets work done
after the original request. A reused UUID with different input or a stale
`expectedVersion` returns 409 `CONFLICT`.

```json
{ "commandId": "6f1e2a4c-9b7d-4e01-8a2f-3c5d6e7f8090", "expectedVersion": 4 }
```

`expectedVersion` is optional for callers without a displayed state version;
the Settings client supplies the version from its current house read. Only
the house owner can reset.

#### PUT /house/catalog
Owner-only reward-catalog gate (CS-PLAN-070 WP7,
[CS-PLAN-078](rewards.md)): the narrow versioned writer —
`{ "catalogEnabled": bool, "expectedVersion": int, "commandId": "uuid" }`
where `expectedVersion` is the house `stateVersion`. Default off. Response:
the refreshed house payload. See [rewards.md](rewards.md) for the full
reward surface contract.

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
Completion activity feed ("who did what when", CS-PLAN-040; WP3 §3.6 evidence
shape). Also the per-member completed-energy source for the Insights fairness
table.

**Query parameters:**
- `days` — period length in days, clamped to 1–90 (default 30)
- `limit` — maximum completions, clamped to 1–200 (default 50)
- `userId` — optional Nextcloud user ID filter; must be a member of the house (else 404 `USER_NOT_FOUND`)

**Response (WP3):** frozen work facts, the selected allocation and the
viewer-scoped correction flags — never mutable reward outputs
([ADR-014](../adr/ADR-014-work-accounting-ledger-and-replay.md)):

```json
{
  "completions": [
    {
      "id": 42,
      "completedAt": "2026-08-21T10:00:00+00:00",
      "version": 1,
      "allocationRevision": 2,
      "totalEnergy": 3,
      "undoneAt": null,
      "houseUserId": 7,
      "displayName": "Alice",
      "taskId": 5,
      "taskTitle": "Vacuum living room",
      "energyCost": 3,
      "roomId": 2,
      "roomName": "Living Room",
      "canCorrect": true,
      "allocation": [
        { "userId": "alice", "displayName": "Alice", "sharePct": 100, "energyMinor": 300, "coinMinor": 3000 }
      ]
    }
  ],
  "total": 1
}
```

- `totalEnergy` is the frozen total effort; each allocation entry carries the
  exact minor units (`energyMinor = totalEnergy * sharePct`, `coinMinor` at
  the frozen rate). A departed participant's share serializes with
  `userId: null` (wallet retained, membership gone) and stays auditable.
- `canCorrect` — viewer-scoped: completer or house owner on a still-valid
  completion. The feed exposes targeted correction and undo actions from this
  flag; it is never true for undone rows or unrelated members.
- When the viewer cannot see a private or admin-only task, the household feed
  redacts its task details, frozen effort and selected work shares together;
  the event itself remains visible as an aggregate (`totalEnergy: null`,
  `allocation: []`, `canCorrect: false`).
- Rows whose room task was deleted later keep appearing with
  `taskId`/`taskTitle`/`energyCost`/`roomId`/`roomName` = `null` — the UI
  renders a fallback label (single-task deletion does not cascade its
  completion history).

### Completions (WP3 §3.6 work-accounting API)

Versioned, receipt-protected reads and corrections of ONE completion
([ADR-014](../adr/ADR-014-work-accounting-ledger-and-replay.md)). Every
mutation is a `CommandService` command: the client generates one RFC 4122 v4
`commandId` per logical command and retains it across uncertain retries —
an identical retry replays the stored outcome (`replay: true`) with zero
repeated effects, a changed payload under the same UUID conflicts.

#### GET /completions/{id}
The viewer-safe read model of one completion: frozen work facts
(`completedAt`, `workDate`, `workZone`, `occurrenceDue`, `totalEnergy`,
`rateMinor`), the currently selected `allocation` with the audit `history`
(append-only revisions; a departed-not-erased actor renders as the localized
'Removed member' placeholder) and the viewer-scoped `canCorrect`.

**Response `data` excerpt:**
```json
{
  "completion": { "id": 42, "version": 1, "allocationRevision": 2, "totalEnergy": 3 },
  "allocation": [
    { "userId": "alice", "displayName": "Alice", "sharePct": 100, "energyMinor": 300, "coinMinor": 3000 }
  ],
  "history": [
    { "revision": 0, "action": "complete", "actor": { "userId": "alice", "displayName": "Alice" }, "reasonCode": null },
    { "revision": 1, "action": "attribution", "actor": null, "actorDisplayName": "Removed member", "reasonCode": "wrong_split" }
  ],
  "canCorrect": true
}
```

#### POST /completions/{id}/undo
The targeted completion undo (C3e). Receipt: `commandId` (UUID),
`expectedVersion`, optional bounded `reasonCode` (closed payload — unknown
fields 400). Authority: the recorded completer or a house owner
(participation alone is no authority; a departed completer leaves the owner
as the only authority). A stale `expectedVersion` → `409 CONFLICT`. The
aggregate reverses the **latest selected allocation revision** exactly once:
wallet union deltas (`completion_undo` ledger entries), the occurrence
completion release, the room replay (the undone effect drops out of the
journal fold), and the personal-recognition recompute from the corrected
valid history. Replaying the same `commandId` returns the original identity
with no second effect; an already-undone target answers success with
`undone: false` and zero writes.

**Response `data` excerpt:** `{ "completionId": 42, "undone": true, "replay": false, "roomTask": {…}, "room": {…}, "task": {…}, "rewards": { "completion": {…}, "wallet": {…}, "contributionMinor": 3000 } }`

#### PUT /completions/{id}/attribution
The explicit allocation correction (C3f). Receipt: `commandId` (UUID),
`expectedVersion`, non-empty `participants` (`[{userId, sharePct}]`, 1–100
unique current house members, whole percents summing to exactly 100), the
explicit `allocationReviewed: true` confirmation of any deviation from the
recorded allocation (an unreviewed deviation is a `409 CONFLICT`), and an
optional bounded `reasonCode` (closed payload — unknown fields 400).
Authority: completer or house owner only.

The correction rewrites the **selected allocation** and re-accounts value
with exact wallet-union deltas: the union is keyed by **wallet id from the
share rows** — a departed participant's retained wallet is always part of the
reversal, so a completion pays out exactly the frozen total and never more.
The frozen work facts (`completedAt`, `workDate`, `workZone`,
`occurrenceDue`, `totalEnergy`, `rateMinor`), the room journal and the
occurrence identity stay immutable; personal recognition recomputes from the
corrected valid history. `expectedVersion` staleness → `409 CONFLICT`.


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
> `PUT /tasks/{id}/uncomplete` was likewise pruned in CS-PLAN-064 (decision
> D5) — undo runs exclusively through `POST /room-tasks/{id}/undo` (see Room
> Tasks).

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

**Request body shape (CS-PLAN-066 D6 / BUG-API-001):** the edited definition
fields travel as the TOP-LEVEL JSON body — the client calls
`api.put('/tasks/{id}', updates)` with e.g. `{ "name": "…", "energyCost": 3 }`.
The controller reads its closed payload from the request parameters minus the
route internals (`id`, `_route`); a dispatcher-resolved `array $updates`
argument can never bind from that shape and would 500 on every real HTTP call
(the SchedulingController `memberUpdatesPayload` fix pattern, RF-065-05).

> **`taskTier` (retired, CS-PLAN-070 WP4):** the Coin-era bronze/silver/gold
> tier and its multiplier authority were removed with the frozen-rate wallet
> cutover. The field is no longer accepted by `PUT /tasks/{id}` — a stale
> client sending it gets the generic whitelist rejection (`400 VALIDATION_ERROR`,
> `Unknown or immutable field`).

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

`nextDue` (house-local `Y-m-d` label; resolved to house-local midnight,
RF-058-01) defaults to tomorrow; `flexDays > 0`
materializes an `idealDueDate`. The response is the unified aggregate shape:

```json
{ "task": {…}, "roomTask": {…}, "assignees": ["mom", "dad"] }
```

> **Canonical `assignees` (CS-PLAN-066 D1):** every public `assignees` field —
> read (`GET /room-tasks`), create aggregate, single `PUT …/assignees`, bulk
> assign and the enriched execution rows below — is a unique, deterministically
> ordered Nextcloud **user-ID string array** (ordered by `room_task_id, user_id`).
> The bulk-assign response embeds each returned success's persisted set, so
> clients reconcile exactly the server result, never the requested set.

#### GET /room-tasks/overdue · GET /room-tasks/due-today (CS-PLAN-066 §3.1)
Complete viewer-visible overdue / due-today execution rows. Visibility,
eligibility and ordering are unchanged; the rows are enriched into the same
canonical shape the filtered daily endpoint returns — one contract for every
execution-row consumer (no client-side assignee re-fetch):

```json
[
  {
    "roomTask": { "id": 11, "isActive": true, "nextDue": "…", "status": "overdue", "daysUntilDue": -2 },
    "task": { "id": 4, "name": "Take out kitchen trash", "instructions": "…" },
    "room": { "id": 2, "name": "Living Room" },
    "assignees": ["dad"]
  }
]
```

These endpoints are the authoritative count projections for the Dashboard
counters (`totalOverdueTasks` / `totalDueTodayTasks` read the complete
endpoint results — a scoped room load or the child-limited daily list can
never masquerade as a count source, CS-PLAN-066 D2).

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
List room-task assignments (CS-PLAN-067 D1 selector contract).

**Selector modes** (precedence; a supplied selector never falls back to a
broader mode):
- `scopeId` (optional): the scope's rows intersected with the current house
  and the viewer's visibility (CR-053-02, skip-on-missing).
- `roomId` (optional): one room's visible tasks; a foreign roomId is a
  `404 RESOURCE_NOT_FOUND`.
- neither: **house-wide** — every visible instance of the CURRENT house
  (server-side resolved, never a browser-supplied house ID) in ONE request.
  This is the batched read TaskManager and SchedulingView load instead of
  the former per-room request loop.
- `activeOnly` (optional, default `true`): limit to active instances.

> **Authorization (CR-053-02):** house-scoped — a `roomId` from another
> house is rejected with `404 RESOURCE_NOT_FOUND`, and the `scopeId`
> branch silently filters cross-house entries (skip-on-missing).

**Response `data` excerpt** (camelCase rows; identical shape in every selector mode):
```json
[
  {
    "id": 1,
    "roomId": 1,
    "taskId": 1,
    "nextDue": "2026-02-25T10:00:00Z",
    "lastCompleted": "2026-02-18T10:00:00Z",
    "isActive": true,
    "assignees": ["mom"]
  }
]
```

> **`assignees`** is the canonical single assignee representation
> (CS-PLAN-060 WP1 D2): the `chorestrike_task_assignees` join table keyed by
> Nextcloud user IDs. The legacy `room_tasks.assigned_to_user_id` scalar
> column and its payload key were folded out — reads never merge legacy and
> canonical state. Initial assignment uses the atomic `POST /room-tasks`
> body's `assigneeUserIds` array, including for a single assignee.
> See `architecture.md` (`chorestrike_room_tasks`). The standalone
> `POST /room-tasks/{id}/assign` / `…/unassign` and single-assignee
> `POST|DELETE …/assignees` variants were pruned in CS-PLAN-055 — assignment
> goes through `PUT …/assignees` or `POST /room-tasks/bulk-assign`.

Mounting an existing definition uses `{ "roomId": 1, "taskId": 1,
"assigneeUserIds": [] }` on the same atomic `POST /room-tasks` contract
documented above. There is no snake_case multi-room create body.

#### Execution Proposals and Personal Responsibility

Execution reads (daily, filtered-daily, due-today and overdue) attach a nullable
`proposal` to the selected occurrence:

```json
{
  "occurrenceId": "41:3",
  "basis": "agreement",
  "participants": [{ "userId": "kid", "sharePct": 100 }],
  "obligatedUserIds": ["kid"]
}
```

`participants` previews actual-work shares, including agreed voluntary helpers.
`obligatedUserIds` separately identifies active obligated members of a concrete
agreement in sorted user-ID order; released, excused and helper-only members are
not obligations. For `basis: "assignees"` or `"solo"` it is `null`, so the
canonical `assignees` defaults still determine personal task eligibility. An
empty concrete obligation array is not an unassigned task offered to everyone.
No selectable target means `proposal: null`.

Child daily eligibility applies concrete obligations before the daily limit;
My Day uses the same field for its personal filter, including catch-up cards.
The row's `assignees` remain the future/default assignment set. A one-occurrence
handover must not rewrite them. This projection does not expand the task
visibility policy: private/admin-only visibility is checked before enrichment.

#### PUT /room-tasks/{id}/due-date
Update a room task's next-due date / ideal due date. Payload: `{ "nextDue": … }`.
A bare `Y-m-d` label denotes the **house-local day** (ADR-009): the server
persists its house-local midnight as the UTC instant (RF-058-01 — never the
label tagged at 00:00 UTC, which would land one local day early for houses
west of UTC). Any other parseable datetime is persisted as that instant;
unparseable input → `400 VALIDATION_ERROR`.

#### GET /room-tasks/{id}/assignees · PUT /room-tasks/{id}/assignees
Read / replace the multi-assignee set for a room task. Assignee mutations
are validated for house membership server-side. (Since CS-PLAN-067 §3.4 the
GET route has no frontend consumer — list rows already carry their canonical
`assignees` arrays — but it stays available for API consumers.)

#### POST /room-tasks/{id}/undo — removed in WP3

> **WP3 targeted correction (CS-PLAN-074 WP3, [ADR-014](../adr/ADR-014-work-accounting-ledger-and-replay.md)):**
> this latest-by-room-task route is gone. Undo runs exclusively through
> [`POST /completions/{id}/undo`](#post-completionsidundo) — the exact
> completion targeting contract (C3e) with the receipt envelope, completer/
> owner authority and the latest-selected-allocation reversal.

- **Actor gate:** the recorded completer or a house owner (masterplan decision
  D3; participation alone is no authority). A completer who left the house
  leaves the owner as the only authority.
- **No revertible occurrence:** `200` with `undone: false` and **zero
  writes** — undo is idempotent, and the current state is echoed back for
  client reconciliation.
- **Non-retractions (ADR-011, D8):** medals already awarded, the weekly-goal
  notification claim, and dispatched notifications survive an undo.

#### DELETE /room-tasks/{id}
Remove task from room.

> **Lifecycle authorization (CS-PLAN-056, CR-053-06; unified in ADR-010 /
> CS-PLAN-061):** the surviving lifecycle mutations —
> `PUT /room-tasks/{id}/due-date`, `POST /room-tasks/{id}/complete`, and
> `POST /room-tasks/{id}/undo` — enforce the ONE task-visibility matrix
> via `TaskAccessPolicy` (public → any member; private → creator ∪ that room
> task's explicit assignees; admin-only → house owner). **Visibility denials
> return `404 RESOURCE_NOT_FOUND`** (anti-enumeration, decision D3) — never a
> 403 that would confirm the id exists; role denials (a child actor) stay
> `403 FORBIDDEN`. Completion additionally answers `409 CONFLICT` for inactive
> occurrences and `403 FORBIDDEN` for the same-user day duplicate
> (CS-PLAN-064, ADR-011).

> **Full route list:** the canonical, complete route table is
> [`appinfo/routes.php`](../../appinfo/routes.php) (70 routes spanning `house`,
> `floor`, `room`, `task`, `room_task`, `game`, `user`, `scheduling`,
> `checklist`, `minigame`, `setup`, and `test`). The task-sync ×6 and
> reward-config ×2 HTTP surfaces were pruned in CS-PLAN-055 together with
> 31 further no-consumer/test-only routes (disposition list + guard in
> `RouteMatrixTest`); CS-PLAN-063 pruned `POST /tasks` and CS-PLAN-064 pruned
> `PUT /tasks/{id}/uncomplete` + `POST /room-tasks/{id}/reactivate` while
> adding the undo aggregate. Re-adding a route requires a consumer.
> This document covers the core CRUD + completion
> flows; the `RouteMatrixTest` contract test guards route↔controller drift.

#### POST /room-tasks/{id}/complete
Complete the **shown occurrence** of a room task — one transactional WP3
aggregate (CS-PLAN-072 WP1 receipt + CS-PLAN-074 WP3 work accounting,
[ADR-014](../adr/ADR-014-work-accounting-ledger-and-replay.md)). The work
timestamp is resolved server-side. The request body is a **closed set**;
unknown fields are a `400`:

```json
{
  "commandId": "6f1e2a4c-9b7d-4e01-8a2f-3c5d6e7f8090",
  "occurrenceId": "41:3",
  "expectedVersion": 0,
  "selectionToken": "9a8b7c6d5e4f3a2b1c0d",
  "participants": [
    { "userId": "mom", "sharePct": 50 },
    { "userId": "dad", "sharePct": 50 }
  ],
  "allocationReviewed": true
}
```

- `commandId` — a fresh client RFC 4122 v4 UUID, one per logical completion
  command; replaying it returns the original identity (`replay: true`) with no
  repeated post-commit effects.
- `occurrenceId` — the shown target's public `<scheduleRevisionId>:<ordinal>`
  key (from the row's `occurrence` block on any read endpoint).
- `expectedVersion` / `selectionToken` — the optimistic-concurrency version and
  the server-computed selection hash echoed back.
- `participants` (optional) — the explicit actual-work split: 1–100 unique
  eligible house members with positive whole percents totaling exactly 100.
  Absent → the server resolves the selection's default sharing (proposal
  first, equal eligible-member mirror otherwise; assignee tasks fall back to
  the completer-only default). The completer must take part in the work
  (Q-074-02 self-participation: completing someone else's split unchanged is
  rejected — the actor joins with an equal renormalized share).
- `allocationReviewed` (optional, default `false`) — the explicit confirmation
  when the submitted split deviates from the proposal; an unreviewed deviation
  → `409 CONFLICT`.

The transaction freezes the work evidence (occurrence identity, work
date/zone, total energy, frozen rate), writes the allocation revision +
work shares, posts exact wallet-union deltas to the immutable
`wallet_entries` ledger, appends the `room_events` journal delta (the saved
**requested** effect — the replay clamps), advances the timeline projection
and recomputes the personal recognition of every participant — all under the
documented lock order. Gates run before any write: inactive room task →
`409 CONFLICT`, visibility → `404`/`403` per the standard above, receipt
staleness → `409` ("The task changed since it was displayed…"). Post-commit
effects (weekly-goal read, notification dispatches) are failure-isolated: the
endpoint still answers `200` with `rewards.weeklyGoal = null`.

**Response (WP3 evidence shape):**
```json
{
  "success": true,
  "data": {
    "roomTask": { "id": 12, "nextDue": "2026-02-25T00:00:00Z", "isActive": true },
    "room": { "id": 3, "cleanliness": 85 },
    "task": { "id": 7, "energyCost": 3, "priority": 2 },
    "completionId": 88,
    "replay": false,
    "occurrence": {
      "occurrenceId": "41:4",
      "expectedVersion": 0,
      "selectionToken": "1f2e3d4c5b6a7988",
      "classification": "agreed",
      "plannedDate": "2026-02-25",
      "fulfilled": false
    },
    "rewards": {
      "completion": {
        "id": 88, "version": 0, "allocationRevision": 1,
        "completedAt": "2026-02-25T10:00:00+00:00", "totalEnergy": 3
      },
      "participants": [
        { "userId": "mom", "displayName": "Mom", "sharePct": 50, "energyMinor": 150, "coinMinor": 1500 },
        { "userId": "dad", "displayName": "Dad", "sharePct": 50, "energyMinor": 150, "coinMinor": 1500 }
      ],
      "wallet": { "bookedMinor": 1500, "reservedMinor": 0, "availableMinor": 1500, "fundingHold": false },
      "contributionMinor": 1500,
      "weeklyGoal": { "dueCount": 5, "completedCount": 2, "percent": 40, "targetPercent": 80, "achieved": false, "weekStart": "2026-02-23T00:00:00+00:00", "weekEnd": "2026-03-01T23:59:59+00:00" }
    }
  }
}
```

> `occurrence` is the **fresh** selection block riding back; `completionId` +
> `replay` identify the ledger row and the idempotent replay. The
> `rewards.wallet` / `rewards.contributionMinor` describe the **caller's own**
> totals in exact minor units (100 coin minor = 1 Coin; no coin integers, no
> combo/team-spark fields anywhere — the legacy economy is retired). The owning
> client stores reconcile from this one response without arithmetic.

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

> CS-PLAN-065 §3.4: the client keeps request-order protection on the range
> reader — a scheduling apply refetches the cached window and a stale
> in-flight response can never overwrite the refreshed results.

#### GET /room-tasks/filtered-daily
The My Day payload for the current user (CS-PLAN-057/061), now with two
CS-PLAN-065 changes (decision D4): the deterministic read order is
`nextDue, createdAt, id` (engine-stable child-limit slicing), and every row
embeds its canonical `assignees` list so the client never re-interprets an
unknown set as unassigned after a child limit truncated the list. For child
users the assignment eligibility (assigned to the viewer OR unassigned) is
evaluated **before** the availability gate and the daily limit.

**Response `data`:** list of `{ roomTask, task, room, assignees }` objects.

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
> production-consumed; the consent-filtered ranking has its own
> `GET /ranking` below).
> `game#handleTaskCompletion` was pruned in CS-PLAN-055 — completion runs
> through `POST /room-tasks/{id}/complete` (the CompletionService
> aggregate); the prune is pinned by `RouteMatrixTest`.

#### GET /game/state
Get the current game state: the serialized house members with their
gamification fields (camelCase; WP3 shape — progression only, no Coin
balances: the wallet is exposed exclusively as the caller's own state via
`rewards.wallet` on completion responses and `GET /users/current`).

WP4 §3.4 read contract: the response additionally carries the caller's
rhythm projection (`rhythm` — confirmed/current/best from the valid-history
evaluation plus the `pending` hold indicator), the caller's personal
weekly-goal slice (`myWeeklyGoal`), and the house `weeklyGoal` block with
the explicit `eligible`/`onTime`/`neutral` counts and the `provisional`
flag. All of it is a PURE READ — on an initialized house the endpoint
performs no award writes (minting lives in the TimelineJob closed-week
reconciliation and `POST /game/check-medals`).

**Response `data`:**
```json
{
  "users": [
    {
      "id": 1,
      "nextcloudUserId": "mom",
      "displayName": "Mom",
      "role": "owner",
      "contributionMinor": 12000,
      "currentStreak": 5,
      "longestStreak": 9,
      "currentTitle": { "pack": "family_rhythm", "tierIndex": 1, "labelKey": "titles.family_rhythm.rhythm_6" }
    }
  ],
  "rhythm": { "confirmed": 5, "current": 5, "best": 9, "pending": true },
  "weeklyGoal": {
    "eligible": 7, "onTime": 6, "neutral": 1,
    "percent": 86, "targetPercent": 80, "achieved": false,
    "provisional": true, "weekKey": "2026-09-21",
    "weekStart": "…", "weekEnd": "…"
  },
  "myWeeklyGoal": {
    "eligible": 4, "onTime": 3, "neutral": 0, "provisional": true,
    "weekKey": "2026-09-21", "weekStart": "…", "weekEnd": "…"
  }
}
```

> WP3: `contributionScore` became `contributionMinor` (exact minor units) and
> the coin/streak-freeze fields are retired with the legacy economy
> ([ADR-014](../adr/ADR-014-work-accounting-ledger-and-replay.md)).
> WP4: `selectedTitlePack` left the member payload — the current title is
> always the highest qualifying tier of the single retained rhythm series.

#### GET /game/medals
Get medals for the current week (serialized `Medal` entities, camelCase).
The retained catalog is the rhythm tiers (`rhythm_3/6/12`) plus the
household weekly milestone — the retired activity/combo types were dropped
with the WP4 label cutover.

**Response `data`:**
```json
[
  {
    "id": 4,
    "userId": 1,
    "medalType": "rhythm_3",
    "value": 12,
    "weekStart": "2026-09-07",
    "awardedAt": "2026-09-13 10:12:00",
    "medalLabel": "Rhythm 3",
    "userName": "Mom"
  }
]
```

#### POST /game/check-medals
Re-evaluate the weekly medal thresholds for the requesting member and
persist new awards. Idempotent: a repeat call awards nothing new.

**Response `data`:**
```json
{
  "count": 1,
  "awardedMedals": [
    { "id": 4, "userId": 1, "medalType": "rhythm_3", "value": 10, "weekStart": "2026-09-07", "awardedAt": "2026-09-13 10:12:00", "medalLabel": "Rhythm 3" }
  ]
}
```

### Minigames

House-scoped minigame reads and writes are membership gated. The global,
read-only boost-price catalog requires authentication but no membership
and performs no house/member writes. Game ids are whitelisted
(`snake`); scores must satisfy `0 <= score <= 5000` (server cap) and saves
are rate-limited to **100 per member and house-local day**
(`429 MINIGAME_RATE_LIMITED`). Reads mint nothing: an unknown game id on a
read answers an empty board, only writes validate the catalog.

#### POST /minigames/{gameId}/scores
Save a finished game. The awarded coins are server-computed
(`floor(score / 10)`), never client-supplied.

**Request:** `{ "score": 250, "boostsUsed": ["slow_motion"] }`

**Response `data`:**
```json
{ "score": 250, "gameId": "snake", "boostsUsed": ["slow_motion"], "coinsEarned": 25 }
```

#### GET /minigames/coins
**Response `data`:** `{ "currentCoins": 125 }`

#### GET /minigames/{gameId}/leaderboard?limit=10
House-wide best scores, ordered by score, enriched with `displayName`.

#### GET /minigames/{gameId}/leaderboard/weekly?limit=10
Same shape, restricted to the current house-local medal week.

#### GET /minigames/{gameId}/personal-best
**Response `data`:** `{ "score": 300 }`

#### GET /minigames/{gameId}/rank
**Response `data`:** `{ "rank": 2, "gameId": "snake" }`

#### GET /minigames/{gameId}/recent?limit=5
The requesting member's own scores, ordered by score (shipped behavior) —
never other members' rows.

#### GET /minigames/boosts
Authenticated global boost price catalog (`{ "magnet": 30, … }`), also readable
by a non-member; `MinigameControllerIntegrationTest` pins that no-write contract.
The UI disables
purchases without it — there is no client-side price fallback (UX-013).

#### POST /minigames/boosts/{boostId}/purchase
Debits the exact catalog price atomically.
**Response `data`:** `{ "success": true, "boostId": "magnet", "cost": 30, "remainingCoins": 70 }`
Failure envelopes: `400 PURCHASE_BOOST_ERROR` (unknown boost or
insufficient coins, zero writes).

### Scheduling

#### Occurrence agreement commands (CS-PLAN-073 WP2)

`POST /occurrences/{id}/amend`, `POST /occurrences/{id}/catch-up`,
`POST /occurrences/{id}/restart` — owner-recorded agreement commands over one
occurrence (`{id}` = public occurrence key `<scheduleRevisionId>:<ordinal>`).
Every command body carries `roomTaskId`, `commandId` (UUID, idempotent retry
identity), `occurrenceId`, `expectedVersion`, `selectionToken` (amend/catch-up)
and the explicit `consent: true` attestation (never a confirmation queue);
`amend` accepts an optional `date` and/or explicit `participants`
(`[{userId, role, sharePct}]`, active shares totaling exactly 100),
`catch-up` requires `workDate` (`workZone`, optional `participants`),
`restart` requires `workDate` and only accepts a neutrally suspended interval
predecessor. A single-occurrence amendment never changes the revision's future
defaults. Whole-day capacity of every affected participant — including
concretely agreed helper commitments — plus the original deadline and child
limits are enforced inside the locked aggregate; date-only amendments and
catch-ups without a participant payload validate the **concrete current
occurrence members** (after a handover the actual arrangement, never the
replaced revision defaults), charging the occurrence's frozen energy with
the exact shares. The receipt identity covers
the action and every normalized semantic input (date, canonical participant
set, work appointment): an identical retry replays the original result, the
same UUID with any changed fact or a different action conflicts. A date
amendment moves exactly the occurrence of the submitted key; a participant
change persists the former arrangement as a neutral `released` member row
with consent evidence, re-derives the effective suspension from the new
rows (an explicit feasible 100% agreement before the original deadline is
how a suspended joint occurrence resumes — no automatic redistribution),
and a catch-up appointment keeps its zone on the
replaced rows. Errors: 400 malformed, 403 visible role denial, 404
invisible/foreign target, 409 stale version/token, passed deadline or
business conflict.

`GET|POST /pauses`, `POST /pauses/recognize-illness`,
`POST /pauses/{id}/approve|reject`, `PUT /pauses/{id}` — the revisioned pause
lifecycle. `POST /pauses` creates a personal pause for the caller (child →
pending `requested`, adult → `acknowledged`) or a household pause with
`scope: 'house'` (owner only); `PUT /pauses/{id}` shortens an **acknowledged**
pause (a pending request is decided via approve/reject, never shortened) with
the next-local-day boundary of the pause's saved zone. Within each pause
group the latest acknowledged revision governs every date from its decision
boundary on, so a shortening actually removes its superseded future coverage
while today's and earlier recognized excuses survive it; different groups
compose as a union and an undecided/rejected child shortening leaves
acknowledged coverage unchanged. An **ordinary** recognition excuses an
obligation only when its decision (the acknowledgment/approval time, never a
child's earlier request time) predates the obligation's original exclusive
deadline — a late approval cannot erase an expired miss; only
`POST /pauses/recognize-illness` (owner-only, 404 anti-enumeration for a
foreign/unknown subject) carries the explicit retrospective marker that can
neutralize a qualifying unfulfilled historical obligation. Pause rows carry
stable `pauseGroupId` + monotonic `revision`; mutations echo
`{pauseGroupId, revision, state}` and require `expectedRevision` for
decisions/updates plus a `commandId`. The list is viewer-safe through an
explicit field whitelist: pending personal requests are self/owner data, and
the personal `reasonCode`/`isRetrospectiveIllness` metadata stays
self/owner-only in **every** state — unrelated members receive only the
neutral shared facts (`pauseGroupId`, `revision`, `scope`, `subjectWalletId`,
`subjectUserId`, `state`, `startDate`, `endDate`, `timezone`,
`createdAt`/`updatedAt`); house-scope pauses stay fully visible. Suspending
an occurrence requires ANY obligated (responsible/joint) participant to be
covered — pausing one partner of an explicit joint arrangement suspends the
whole occurrence (retained share snapshot, zero live planned capacity) until
an explicit feasible agreement resumes it, while a helper-only pause releases
just that commitment (Q-073-01). Suspended obligations stay
visible for clarification with their frozen effort snapshot but consume no
live planned capacity and never block selection; overlapping recognized
pauses keep their union provenance (each covering pause referenceable by its
own `pause_ref` evidence row). Virtual (not yet materialized) occurrences
derive the identical suspension from their saved revision's default
participants, so a stopped cron never changes the read answer.

#### GET /scheduling/preferences
Get the current user's scheduling preferences (member-gated).

**Response `data`:**
```json
{
  "userId": 7,
  "energyBudget": { "monday": 10, "tuesday": 10, "wednesday": 10, "thursday": 10, "friday": 10, "saturday": 15, "sunday": 15 },
  "weekdayAvailability": { "monday": true, "tuesday": true, "wednesday": true, "thursday": true, "friday": true, "saturday": true, "sunday": true },
  "maxDailyTasks": null
}
```

> CS-PLAN-065 (decision D7): the reader-less `todayConsumedEnergy` field is
> gone — capacity is derived server-side. `maxDailyTasks` is `null` when
> unset (unlimited) and a finite integer (1-100) when the owner set a child
> daily-task limit (decision D5).

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
Get all member availability (house owner only). Each entry carries the
member's `maxDailyTasks` child limit (`null` = unlimited, integer = finite
bound, decision D5).

#### PUT /scheduling/members/{userId}
Update another member's weekday availability/energy (house owner only).
The closed payload also accepts the child limit (decision D5):
`maxDailyTasks` omitted = unchanged, explicit `null` = unlimited,
integer 1-100 = finite bound (`0` is a validation error — an unavailable
weekday already expresses a day off). Errors: `400 VALIDATION_ERROR`
(unknown field, invalid weekday, limit outside 1-100), `403 FORBIDDEN`
(non-member/non-owner).

#### POST /scheduling/suggestions
**Owner plan preview** (CS-PLAN-065, ADR-012) over real active room tasks —
read-only, deterministic, zero writes. The retired taskCount-only advice
contract was replaced on this URL without a compatibility fallback: a
legacy `{ "taskCount": … }` payload fails the closed shape validation with
`400 VALIDATION_ERROR`.

**Request:**
```json
{
  "startDate": "2026-09-10",
  "roomTaskIds": [11, 12],
  "overrides": { "11": { "assigneeUserIds": ["mom"], "date": "2026-09-11" } }
}
```
`startDate` is a strict house-local `Y-m-d` (today or later), the window is
the seven consecutive days from `startDate`; `roomTaskIds` is a non-empty
list of unique positive ids bounded at 100; `overrides` (optional) is keyed
by selected room task id and may set a complete `assigneeUserIds` list
and/or a `date` inside the window. Override values that violate the closed
shape (unknown fields, duplicate assignees, malformed dates, non-member or
ineligible assignees) are `400`; a syntactically valid override that
violates the task's legal recurrence slots produces an `unplaced` row with
reason `ILLEGAL_OVERRIDE`, not a 400.

**Response `data`:**
```json
{
  "previewVersion": "sha1-content-fingerprint",
  "startDate": "2026-09-10",
  "endDateExclusive": "2026-09-17",
  "timezone": "Europe/Berlin",
  "changes": [
    {
      "roomTaskId": 11, "taskId": 3, "taskName": "…", "roomName": "…",
      "energyCost": 2, "priority": 2, "flexDays": 3,
      "legalDates": ["2026-09-11", "2026-09-12"],
      "before":  { "assignees": [], "dueDate": "2026-09-08" },
      "proposed": { "assignees": ["mom"], "dueDate": "2026-09-11" },
      "warnings": ["DATE_MOVED", "ASSIGNEES_CHANGED"]
    }
  ],
  "unchanged": [ { "roomTaskId": 5, "reason": "UP_TO_DATE", "warnings": [] } ],
  "unplaced":  [ { "roomTaskId": 7, "reason": "NO_CAPACITY", "warnings": [] } ],
  "capacity":  [ { "date": "2026-09-10", "members": { "mom": { "houseUserId": 2, "available": true, "budget": 10, "planned": 3, "remaining": 7, "taskCount": 1 } } } ],
  "warnings": ["EXISTING_OVERLOAD"]
}
```
Reason/warning codes: `UP_TO_DATE`, `CLAIMED`, `NO_LEGAL_SLOT`,
`NO_ELIGIBLE_MEMBER`, `NO_CAPACITY`, `ILLEGAL_OVERRIDE`; `DATE_MOVED`,
`ASSIGNEES_CHANGED`, `EXISTING_OVERLOAD`.

**Errors:** `400 VALIDATION_ERROR`, `403 FORBIDDEN` (non-member or
non-owner actor), `404 RESOURCE_NOT_FOUND` (unknown, cross-house or
invisible task), `409 CONFLICT` (inactive row).

#### POST /scheduling/apply
Apply the confirmed plan as one all-or-nothing command (owner only,
ADR-012; CS-PLAN-073 WP2 runs it as the `scheduling.apply` CommandService
command). The request is the **exact normalized preview input** plus its
`previewVersion`, the client's `commandId` UUID and the owner's explicit
`consent: true` attestation (RF-073-WP2-06):

```json
{
  "startDate": "2026-09-10",
  "roomTaskIds": [11, 12],
  "overrides": {},
  "previewVersion": "sha1-content-fingerprint",
  "commandId": "uuid-v4",
  "consent": true
}
```

The client mints the UUID per preview and RETAINS it across uncertain
retries (a 409 keeps the draft and the same UUID — a retry never repeats a
transition with a fresh identity). The consent attestation records the
owner's agreement with the affected people: it rides the receipt identity
and every written `agreements` evidence row of the batch. The plan is
recomputed under the
documented lock order (house → room_task → task definition → house_user,
ascending ids, then assignee writes) and any drift — assignment, date,
definition, membership, role, preference, timezone, pause, completion or
undo state — rejects the batch with `409 CONFLICT` and zero writes. A
preview that crossed local midnight is stale by its fingerprint and
yields the write-free 409 BEFORE any relative-date validation (CR-SVC-64);
a newly malformed request stays a 400. A confirmed no-change plan performs
zero writes and answers `appliedCount: 0`. Claimed rows are never
rescheduled; `idealDueDate` is not rewritten by an applied move. An
identical replay (same UUID + payload) returns the original identities plus
the current authorized state.

**Response `data`:**
```json
{
  "appliedCount": 1,
  "windowStart": "2026-09-10",
  "windowEndExclusive": "2026-09-17",
  "roomTasks": [ { "id": 11, "…": "full room-task payload", "assignees": ["mom"] } ]
}
```

**Errors:** like preview, plus `409 CONFLICT` for a stale/mismatched
`previewVersion` (retry requires a fresh preview) and `400 VALIDATION_ERROR`
for a missing/oversized `commandId` or a missing/false `consent` attestation
(both validated after the owner gate, so unauthorized actors still receive
their 403).

### Users

> **Title fields on user objects:** every user object returned by `GET /users`
> and `GET /users/current` is enriched with one title field:
> - `currentTitle` (`object|null`) — the server-computed highest qualifying
>   tier of the single retained rhythm series (same shape as `currentTitle`
>   under `GET /users/{id}/titles` below), or null.
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
Get the role-title state for a user: the current title — always the highest
qualifying tier of the single retained rhythm series, derived live from the
valid-history maximum (`longestStreak`; no pack selection since
CS-PLAN-070 WP4, Q-075-02 A) — every qualifying tier, and the fixed
series/tier catalog so the frontend can render progress without a second
call. House-members only.

**Response `data`:**
```json
{
  "currentTitle": {
    "pack": "family_rhythm",
    "packLabelKey": "titles.pack.family_rhythm",
    "tierIndex": 1,
    "tierKey": "rhythm_6",
    "labelKey": "titles.family_rhythm.rhythm_6",
    "threshold": 6
  },
  "unlockedTitles": [/* …tier descriptors… */],
  "catalog": {
    "family_rhythm": { "labelKey": "titles.pack.family_rhythm", "signal": "longestStreak", "tiers": [
      { "key": "rhythm_3", "labelKey": "titles.family_rhythm.rhythm_3", "threshold": 3 },
      { "key": "rhythm_6", "labelKey": "titles.family_rhythm.rhythm_6", "threshold": 6 },
      { "key": "rhythm_12", "labelKey": "titles.family_rhythm.rhythm_12", "threshold": 12 }
    ] }
  }
}
```

#### PUT /users/{id}/titles/select (pruned, CS-PLAN-070 WP4)

The multi-pack selection route was retired with the single-series cutover
(Q-075-02 A): the current title derives from the valid-history maximum, so
there is nothing to select. Re-adding it requires a new expose decision
(guarded by `RouteMatrixTest`).

### Personal Preferences and Digest Delivery (WP5)

`GET /preferences/me` and `PUT /preferences/me` expose only the caller's
membership-owned settings, including children. The write requires a command
UUID and `expectedVersion`; unknown fields and invalid resulting settings are
400s, and stale drafts are 409s. Ranking changes preserve delivery preferences
and advance the same version. Departure/erasure removes delivery state.

The only app-originated Nextcloud notification is the optional combined daily
digest. Awards/titles remain in-app. Timing, quiet hours, source rechecks,
at-most-once outcome handling and the complete payload contract are documented
in [Preferences and Digest](notifications.md).

### Ranking (CS-PLAN-070 WP4)

Consent-filtered weekly chore ranking — the server is the sole ranking
authority; the filter lives server-side, never client-side. No Coins,
speed, contribution or private-task data exists anywhere in the payloads.

#### GET /ranking
The consent-filtered ranking view for the caller's house: the house flag
state, the caller's OWN consent state, the visible entries (effective
consents only) and pending consents without a rank. Non-consenting members
and foreign consent states are never exposed.

- `entries[]`: one per effectively-consented member — the on-time ratio of
  that member's personal agreed occurrences on household-public tasks
  (public-at-agreement flag). Exact rational comparison (no floats); equal
  ratios share a rank and carry `tied`; zero-denominator members render
  `neutral` and unranked. The week is labeled `provisional` while it or any
  included window is open.
- `pending[]`: consented-but-not-yet-effective members with their saved
  activation Monday (C5l) — never ranked.
- `me`: `none | pending | effective` with the caller's generation and
  activation Monday.

```json
{
  "rankingEnabled": true,
  "week": { "weekKey": "2026-09-21", "weekStart": "…", "weekEnd": "…", "provisional": true },
  "me": { "state": "effective", "generation": 3, "effectiveMonday": "2026-09-14" },
  "entries": [
    { "houseUserId": 2, "displayName": "Alice", "eligible": 4, "onTime": 3, "neutral": false, "rank": 1, "tied": true, "isMe": false },
    { "houseUserId": 1, "displayName": "Bob", "eligible": 4, "onTime": 3, "neutral": false, "rank": 1, "tied": true, "isMe": true },
    { "houseUserId": 5, "displayName": "Zoe", "eligible": 0, "onTime": 0, "neutral": true, "rank": null, "tied": false, "isMe": false }
  ],
  "pending": [
    { "houseUserId": 7, "displayName": "Carol", "effectiveMonday": "2026-09-28" }
  ]
}
```

#### POST /ranking/consent
Give the CALLING person's ranking consent (children included — C5i). The
mutation runs under the house-first lock and creates a fresh generation
effective NEXT Monday 00:00 in the house timezone, saved with that zone;
never retroactive. An existing pending generation is replaced.

**Response `data`:** `{ "state": "pending", "generation": 4, "effectiveMonday": "2026-09-28" }`

#### POST /ranking/withdraw
Withdraw the CALLING person's ranking consent. Removes visible
participation immediately and invalidates the generation (re-consent starts
a fresh generation at the next full week). Idempotent.

**Response `data`:** `{ "state": "none", "generation": null, "effectiveMonday": null }`

> **House flag:** the owner-only `rankingEnabled` flag rides the existing
> `PUT /house` update path (optional boolean; default off). Disabling
> removes all visible participation immediately and invalidates every
> generation; re-enabling starts everyone unconsented. Departure deletes
> the departing membership's consent with the row; account erasure purges
> it (`ranking consent is purged with the membership`). See
> [ADR-015](../adr/ADR-015-ranking-consent-and-privacy.md).

### Rewards (CS-PLAN-070 WP7)

The family reward catalog and the approved C4 request lifecycle — the
household gate (`PUT /house/catalog` above), the owner-administered catalog
(`GET|POST /rewards`, `PUT /rewards/{id}`) and the receipt-protected
request lifecycle (`GET|POST /reward-requests`,
`GET /reward-requests/{id}`, `POST /reward-requests/{id}/approve|reject|
cancel|fulfill|correct`). Money is exact integer minor units; requests
freeze the displayed terms; reserve at creation, settle only at confirmed
delivery, refund only an owner-attested false fulfillment. The complete
contract — DTO shapes, actor/state matrix, funding hold and privacy rules —
lives in [rewards.md](rewards.md).

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

Membership mutations `POST /users/invite`, `DELETE /users/{id}` and
`PUT /users/{id}/role` use Nextcloud's public `UserRateLimit(60, 300)`
middleware contract: 60 calls per 300 **elapsed seconds**, independently for
each authenticated Nextcloud user and each endpoint. These are not calendar-day
limits or one shared household counter. Ordinary chore execution is not subject
to these membership limits.

On exhaustion, native middleware rejects the request with HTTP **429 before the
controller mutation**. Its response belongs to Nextcloud, not ChoreStrike's
`success/data/error` envelope; clients must handle the HTTP status without
assuming an app error code. Enforcement requires a working request-shared
Nextcloud cache. An unconfigured `NullCache` does not retain attempts and is not
proof that limits work. See [the native-cache prerequisites and real HTTP tests](../engineering/testing.md#native-user-rate-limits).

The separate scores-only minigame save limit remains 100 accepted saves per
user per house-local calendar day. That service-level rejection uses HTTP 429
and the app error code `MINIGAME_RATE_LIMITED`; it is not the membership
middleware contract.

### Authorization and Operational Evidence

Authenticated endpoints retain house membership checks; owner mutations resolve
current owner authority in services and recheck it under the house-first lock.
Limits supplement those checks, not replace them. Never trust client resource
IDs or an earlier role snapshot as authorization for a later mutation/replay.

The [owner-operation logging contract](../standards/guidelines/logging.md#owner-operation-events)
covers membership, house settings/reset, setup/structure, checklist definitions,
planner/agreement/pause and catalog/reward-request operations. Successful change
events follow commit, use minimal numeric actor/resource context and contain no
authored content or sensitive payload. Denial/rollback is not success; replay or
no-change is not a new committed mutation. Shared command events also cover
authorized self-service actions without implying that those callers are owners.

These events are operational diagnostics, **not** a tamper-proof or exactly-once
durable audit ledger. Nextcloud operators control log level, storage, access and
retention; info events require info logging, and a crash after commit can lose
an event. Persisted domain facts/receipts, not the log stream alone, establish
the authoritative state. See [the security assumptions](../standards/guidelines/security.md).

## Webhooks (Future)

Planned for future versions to support real-time notifications.
