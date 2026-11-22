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

Every house payload (`GET`/`PUT /house`, `PUT /house/catalog`,
`PUT /house/arcade-gate`, `POST /house/reset`) also carries
`arcadeGateEnabled` — the household arcade unlock gate, stored in app config
rather than on the house row (CS-PLAN-086, see `PUT /house/arcade-gate`) —
and `taskCategories`, the household's task categories (CS-PLAN-090 WP-I, see
`PUT /house/task-categories`). Since ADR-021 (CS-PLAN-091 WP-B5) it also
carries `bonusPeriod` — the running bonus period, else the next planned one
(`{ "id": 3, "startsOn": "Y-m-d", "endsOn": "Y-m-d" }`, house-local days, both
inclusive) or `null` — and `cyclesFrom`, the first house-local day of derived
windows.

#### PUT /house/members/{userId}/coin-factor
Owner only (ADR-021 §11, CS-PLAN-091 WP-B5). Closed body `{ "commandId": "<uuid>", "factor": 1 }`
with the whole number `factor` 1, 2 or 3 (`400 VALIDATION_ERROR` otherwise, also
for an unknown field); a non-member is `404`, another role `403`. The factor
applies to coins of completions recorded from now on; completed work keeps the
factors frozen on it. Answers with the member; the member list (`GET /users`)
carries each member's `coinFactor`. A repeated `commandId` replays.

#### POST /house/bonus-periods · DELETE /house/bonus-periods/{id}
Owner only (ADR-021 §11, CS-PLAN-091 WP-B5). `POST` takes
`{ "commandId": "<uuid>", "startsOn": "Y-m-d", "days": 1 }` (`days` 1–3,
`startsOn` today or at most 366 days ahead, house-local; `400 VALIDATION_ERROR`
otherwise) and answers `409 CONFLICT` when the period would overlap another one
that is not cancelled. During a period every member's coins count × 1.5, on top
of the coin factor; a completion takes the percent of its work day and keeps
it. `DELETE` cancels a period that has not ended; its `commandId` is a query
parameter (`?commandId=<uuid>`). An ended period answers `409 CONFLICT`, an
unknown one `404`, and cancelling a cancelled period again changes nothing.
Both are receipt-protected and answer `{ "bonusPeriod": … }`: the period the
house shows now — the running one, else the next planned one, or `null` — the
same value as `GET /house` carries (RF-091-10). A period started for a later
day while another one runs therefore answers with the running one.

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

#### PUT /house/arcade-gate
Owner-only arcade unlock gate (CS-PLAN-086 D-086-02,
[ADR-019](../adr/ADR-019-arcade-unlocks-from-completion-history.md)):
`{ "arcadeGateEnabled": bool }`. On (default), arcade games unlock through
finished chores; off, every game is open for every member. Idempotent by
value, last write wins; the only HTTP writer of the `arcade_gate_enabled`
app-config flag. Response: the house payload with the new
`arcadeGateEnabled`. Failure envelopes: `403 FORBIDDEN` for non-owners
(checked before the payload), `400 VALIDATION_ERROR` for a non-boolean value,
`500 HOUSE_UPDATE_ERROR`.

#### PUT /house/arcade-thresholds
Owner-only arcade thresholds (CS-PLAN-090 WP-J, D-090-66,
[ADR-019](../adr/ADR-019-arcade-unlocks-from-completion-history.md)):
`{ "thresholds": { "spots": 0, "socks": 3, "snake": 10, "tiles": 25, "dust": 50 } }`
names every game of the catalog with a whole number from 0 to 500 (the
finished chores that open it while the gate is on). Stored as the typed
`arcade_thresholds` app-config array; until an owner saves, every game reads
the `ArcadeCatalog` default shown above. Audited, last write wins. Response:
the owner's catalog as `GET /minigames/catalog` returns it. Failure envelopes:
`403 FORBIDDEN` for non-owners (checked before the payload),
`400 VALIDATION_ERROR` for a payload that is not an object of game ids, an
unknown or missing game, or a value that is not a whole number in range,
`500 ARCADE_SETTINGS_ERROR`.

#### POST /house/members/{userId}/arcade-reset
Owner-only reset of one member's arcade progress for all games (D-090-63);
the client asks for a confirmation first. The member's start point becomes
the highest committed completion id, under the house-row lock like a score
save; from then on only completions above it count, so locked games unlock
anew. Highscores stay. The start point lives in the member's per-user app
config (`arcade_reset`) and is deleted with the account. Audited. No body.
Response: the progress list of `GET /house/arcade-progress`. Failure
envelopes: `403 FORBIDDEN` for non-owners and for a `userId` that is not a
member of the house (no write), `500 ARCADE_SETTINGS_ERROR`.

#### GET /house/arcade-progress
Owner-only list of every member's finished chores since their last reset,
sorted by display name (never by contribution):
`[{ "userId": string, "displayName": string, "finishedChores": int, "resetAt": string|null }]`,
where `resetAt` is the ISO 8601 time of the last reset, `null` if there was
none. One grouped count for all members. Failure envelopes: `403 FORBIDDEN`
for non-owners, `500 ARCADE_SETTINGS_ERROR`.

#### PUT /house/task-categories
Owner-only writer of the household's task categories (CS-PLAN-090 WP-I,
FEAT-UX-053). Every member reads the list as `taskCategories` in the house
payload: `{ "revision": int, "items": [{ "key": string, "label": string|null }] }`.
Until an owner saves a list, it holds the six built-in keys `cleaning`,
`kitchen`, `bathroom`, `organizing`, `maintenance` and `other` at revision 0.
A `label` of `null` keeps the translated name of a built-in key.

**Request:** the whole ordered list and the revision the client read.
```json
{
  "revision": 0,
  "items": [
    { "key": "kitchen", "label": "Kitchen & dining" },
    { "key": null, "label": "Garden" },
    { "key": "other", "label": null }
  ]
}
```

- An item without a key adds a category; the server generates its key.
  A listed key keeps its category, possibly renamed or moved. A key that is
  left out deletes the category.
- `other` must be the last item and keeps its name. The list may have at most
  20 items, and each name 1 to 40 characters, unique regardless of case. A
  built-in that keeps its name counts with its name in the saving owner's
  language, so no second "Other" or "Kitchen" appears beside it.
- Stored task rows are never rewritten. A task whose key is no longer listed
  counts as `other` in the client's lists and filters. A task write
  (`POST /room-tasks`, `PUT /room-tasks/{id}`) accepts only a listed key and
  answers `400` with "Unknown task category" otherwise. A new task without a
  category gets `other`, and a template keeps its key while it is listed.
- Response: the saved list with the next revision. Failure envelopes:
  `403 FORBIDDEN` for non-owners (checked before the payload),
  `400 VALIDATION_ERROR` for a malformed envelope or a list that breaks a rule,
  `409 CONFLICT` when the list changed since `revision`,
  `500 TASK_CATEGORIES_ERROR`.

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
    "houseHealth": 63
  }
}
```

`stats.houseHealth` is computed on this read: the mean cleanliness of the rooms,
rounded, and 100 for a house without rooms. The `houseHealth` of the `house`
object, here and in `GET /house`, is the last stored value (a reset writes 100)
and is not maintained any more (CS-PLAN-091 RF-091-11; FEAT-UX-071 redefines
both).

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
([ADR-014](../adr/ADR-014-work-accounting-ledger-and-replay.md)) — plus what
happened besides completions in the same period (`events`):

```json
{
  "completions": [
    {
      "id": 42,
      "occurrenceId": "17:3",
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
      "roomTaskId": 12,
      "workDate": "2026-08-21",
      "completionClass": "on_time",
      "canCorrect": true,
      "canAttribute": false,
      "recordedForOthers": false,
      "allocation": [
        { "userId": "alice", "displayName": "Alice", "sharePct": 100, "energyMinor": 300, "coinMinor": 3000 }
      ]
    }
  ],
  "total": 1,
  "events": [
    { "type": "postponed", "at": "2026-08-21T09:00:00+00:00", "houseUserId": 9, "displayName": "Kid", "roomTaskId": 14, "taskTitle": "Tidy room", "postponeCount": 2, "postponeLimit": 3 },
    { "type": "date_changed", "at": "2026-08-20T15:40:00+00:00", "houseUserId": 7, "displayName": "Alice", "roomTaskId": 12, "taskTitle": "Vacuum living room", "completionId": 41 },
    { "type": "absence", "at": "2026-08-20T17:05:00+00:00", "houseUserId": 9, "displayName": "Kid", "scope": "personal", "subjectUserId": "kid", "subjectDisplayName": "Kid", "from": "2026-08-24", "to": "2026-08-28" }
  ]
}
```

- `occurrenceId` names the stored occurrence (schedule revision and ordinal) of
  a completion from the occurrence timeline; it is `null` for every completion
  since the derived windows (ADR-021) and for one from before occurrences
  existed.
- `totalEnergy` is the frozen total effort; each allocation entry carries the
  exact minor units (`energyMinor = totalEnergy * sharePct`, `coinMinor` at
  the frozen rate; returned since CS-PLAN-090 WP-E, the activity list shows
  their sum as the coins a completion paid). A departed participant's share serializes with
  `userId: null` (wallet retained, membership gone) and stays auditable.
- `houseUserId`/`displayName` name who **recorded** the completion; the
  people credited are the `allocation`. `recordedForOthers` is true when the
  recorder is not among them (ADR-021 §10). `workDate` is the house-local day
  the work counts on, which a date correction moves.
- `canCorrect` — viewer-scoped, on a still-valid completion: the house owner,
  or the member who recorded it while it is the latest valid entry of its room
  task (ADR-021 §9, D-091-23). It gates the deletion (undo) and the date
  correction. `canAttribute` gates the change of the people and is the
  owner's alone. Neither is true for undone rows or for a task the viewer
  cannot see. Visibility is decided per room task (ADR-010: a private task by
  its creator and that room task's assignees).
- `events` — newest first, at most `limit`, of the same `days`: `postponed`
  (`postponeCount` of `postponeLimit`), `date_changed` and `deleted` (with their
  `completionId`), `absence` and `absence_removed` (`from`/`to` house-local
  days, `scope` `personal` or `house`, the subject when it is a person and
  still a member). An absence shows once it was acknowledged; a request that
  never was shows neither way. `houseUserId`/`displayName` name who did it
  (who entered or removed an absence; the absent person is the subject); an
  erased or departed member renders through the placeholder. `taskTitle` is
  `null` for a task the viewer cannot see or that is gone. With `userId`,
  only that member's events, filtered before the `limit`. Nothing is stored
  for them: a postponement is read from its command receipt, a date change
  and a deletion from the completion's audit chain, an absence from its pause
  revisions.
- When the viewer cannot see a private or admin-only task, the household feed
  redacts its task details, frozen effort and selected work shares, with the
  coins they paid, together;
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

> **Derived windows ([ADR-021](../adr/ADR-021-derived-due-windows.md), CS-PLAN-091 on
> `feat/simplified-core-bc`):** the read model carries the cycle facts (`cycleKey`,
> `windowStartOn`, `windowEndOn`, `completionClass` = `on_time` | `late` | `bonus`) and
> `recordedBy` when someone recorded the work for other people (WP-B2). The rights
> follow ADR-021 §9 (WP-B3, D-091-23): the owner corrects any entry; a member who is
> not the owner may delete or re-date only the **latest valid completion of the room
> task that they recorded**; the people are the **owner's alone** to change. The read
> model names the frozen coin multipliers (WP-B5): `bonusPercent` (100 or 150) on the
> completion and `coinFactor` on every share of `allocation`; a completion from before
> the multipliers reads 100 and 1, which is what it was paid. A change of people is
> priced with these frozen values, an undo reverses the amounts its shares recorded.
> `recordedBy` of a recorder who left the house is `{ "userId": null, "displayName":
> "Former member" }`. When the recorder and everyone credited have left, the
> entry no longer tells whether it was recorded for others (both read as a
> former member), so `recordedBy` is `null`.

#### GET /completions/{id}
The viewer-safe read model of one completion: frozen work facts
(`completedAt`, `workDate`, `workZone`, `occurrenceDue`, `totalEnergy`,
`rateMinor`), the currently selected `allocation` with the audit `history`
(append-only revisions with the actions `complete`, `attribution`, `date` and
`undo`; a departed-not-erased actor renders as the localized 'Former member'
placeholder), `recordedBy` (`{ userId, displayName }` when the recorder is not
among the people credited, else `null`) and the viewer-scoped `canCorrect`
(delete, re-date) and `canAttribute` (the people).

**Response `data` excerpt:**
```json
{
  "completion": { "id": 42, "version": 1, "allocationRevision": 2, "totalEnergy": 3 },
  "allocation": [
    { "userId": "alice", "displayName": "Alice", "sharePct": 100, "energyMinor": 300, "coinMinor": 3000 }
  ],
  "history": [
    { "revision": 0, "action": "complete", "actor": { "userId": "alice", "displayName": "Alice" }, "reasonCode": null },
    { "revision": 1, "action": "attribution", "actor": null, "actorDisplayName": "Former member", "reasonCode": "wrong_split" }
  ],
  "recordedBy": null,
  "canCorrect": true,
  "canAttribute": true
}
```

#### POST /completions/{id}/undo
The targeted completion undo (C3e). Receipt: `commandId` (UUID),
`expectedVersion`, optional bounded `reasonCode` (closed payload — unknown
fields 400). Authority (ADR-021 §9): the house owner for any entry; any other
member only for the latest valid completion of the room task that they
recorded (`403 FORBIDDEN` otherwise; participation alone is no authority, and
a departed recorder leaves the owner as the only authority). A stale
`expectedVersion` → `409 CONFLICT`. The
aggregate reverses the **latest selected allocation revision** exactly once:
wallet union deltas (`completion_undo` ledger entries), the reopening of the
cycle the completion closed (its row is deleted; the window is derived again
and `cycle.expectedVersion` moves on), the room replay (the undone effect drops out of the
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
Authority: the house owner alone (ADR-021 §9, D-091-23; `403 FORBIDDEN` for
everyone else, the recorder included). Every member of the new split must be
allowed to see the task (`403`). The response carries no `cycle` block: an
attribution moves no cycle, and the client keeps the target it holds.

The correction rewrites the **selected allocation** and re-accounts value
with exact wallet-union deltas: the union is keyed by **wallet id from the
share rows** — a departed participant's retained wallet is always part of the
reversal, so a completion pays out exactly the frozen total and never more.
The frozen work facts (`completedAt`, `workDate`, `workZone`,
`occurrenceDue`, `totalEnergy`, `rateMinor`), the room journal and the
cycle facts stay immutable; personal recognition recomputes from the
corrected valid history. `expectedVersion` staleness → `409 CONFLICT`.

#### PUT /completions/{id}/date
The date correction of one completion (ADR-021 §9, CS-PLAN-091 WP-B3). Closed receipt:
`{ "commandId": "<uuid>", "expectedVersion": 1, "date": "Y-m-d" }` — a
house-local day that is not in the future and lies strictly between the days
of the room task's neighbouring valid completions (never on or before the
previous one, never on or after the next one, D-091-26), and not the day the
entry already has (`400 VALIDATION_ERROR` with the translated reason).
Authority: the owner for any completion; any other member only for the latest
valid completion of the room task that they recorded (`403 FORBIDDEN`
otherwise). Whether the entry is still the latest is decided under the house
lock; a repeated `commandId` replays the committed result even after a newer
entry was recorded (D-091-63). A deleted entry and `expectedVersion`
staleness → `409 CONFLICT`.

The command appends a `date` revision to the audit chain and moves the work
day; the work instant (`completedAt`) keeps its clock time on that day and
never lies ahead of now. It re-derives the completion's classification
against the frozen window of its cycle, the cycle row, the room journal
replay (the completion's event moves to the corrected instant) and the
recognitions; under an after-completion rule also the frozen window and the
classification of the cycle that followed the entry, and the current window
(a bounded task ends or becomes active again with it). A completion from
before the derived model keeps its stored class; its stored occurrence is
read against the corrected instant. Shares, energy and coins stay, and so do
the facts of a takeover (claim and digest receipt, D-091-61). The answer has
the shape of the undo's, with the fresh `cycle` block.

#### GET /room-tasks/{id}/history?limit=
The history of one task (CS-PLAN-091 WP-B3): its valid completions, newest
first, in the item shape of `GET /house/activity` with the viewer's
`canCorrect`/`canAttribute`. `limit` is clamped to 1–50 (default 10). Every
member who may see the task; a foreign, deleted or invisible room task →
`404 RESOURCE_NOT_FOUND`. Answer: `{ "completions": [ … ] }`.


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

> **Unique names (FEAT-UX-044, CS-PLAN-088 D-088-19):** create, rename and
> the setup wizard store a name already used by another live room of the
> house — compared trimmed and case-insensitively — with the next free
> " 2", " 3", … appended (a taken "Bath 2" continues as "Bath 3" when "Bath"
> exists). The response carries the final name. Existing duplicates stay
> until renamed; saving a room's unchanged name is no rename.

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
> system was removed (CS-PLAN-027 D7). Task templates come from the
> [setup catalog](#setup-wizard) (`GET /setup/catalog`): the one form fills a
> template into its fields and creates the task through `POST /room-tasks`
> (CS-PLAN-091 WP-B6 retired `POST /room-tasks/from-template`). See
> [house-setup-and-templates.md](../concepts/house-setup-and-templates.md).

#### POST /tasks · PUT /tasks/{id} (pruned)

The definition-only create was pruned in CS-PLAN-063 (CR-WF-03) and the
definition-only update in CS-PLAN-091 WP-B6: a task is created with
`POST /room-tasks` and edited with `PUT /room-tasks/{id}`, each one atomic
save of the whole form. Both routes answer `404`. Re-adding one requires a
consumer and a new expose decision (CS-PLAN-055 route-consumer rule; guarded
by `RouteMatrixTest`). The closed constraint set of a definition is documented
with the create below.

### Room Tasks

#### POST /room-tasks (creation aggregate, CS-PLAN-063, CS-PLAN-091 WP-B6)
The one form's create (FEAT-UX-040): a visible, valid room task — or nothing
at all. One request commits the whole aggregate (definition, sub-tasks, room
task, initial assignees, the first window and an optional sleep) in one
service-owned transaction (`TaskCreationService`); any failure rolls back
everything (no orphan definitions). Closed body; an unknown field is a
`400 VALIDATION_ERROR`:

```json
{
  "roomId": 12,
  "task": { "name": "Wipe the counters", "energyCost": 2, "frequencyType": "weekly",
            "frequencyValue": 1, "flexDays": 1 },
  "assigneeUserIds": ["mom"],
  "checklistItems": [{ "label": "Clear the counter" }, { "label": "Wipe it" }],
  "firstDate": "2026-11-20",
  "sleeping": false
}
```

- `roomId` and `task` are required. `task` is the definition in the closed
  constraint set below; absent fields take their defaults. A definition with
  `isVoluntary` is a bonus task and has no window.
- `checklistItems` are the sub-tasks in their order (at most 50, a label of at
  most 255 characters). A new task has none to keep, so an entry with an `id`
  is a `400`.
- `sleeping: true` creates the task asleep, with an optional `wakeOn` as in
  `PUT /room-tasks/{id}/sleep`; a `wakeOn` without it is a `400`.
- `firstDate` (ADR-021, D-091-19; a real house-local `Y-m-d` calendar day at
  most 366 days from today, D-091-45; a translated `400 VALIDATION_ERROR`
  otherwise) is the centre of the first window and defaults to tomorrow; for a
  fixed rule it is also the day its rule days are counted from. It replaced
  `nextDue`, which is no longer read.

The response is the unified aggregate shape. The room task carries the
projection: `nextDue` is the start of the last day of the first window,
`idealDueDate` the start of its planned day, `anchorOn` the first date.

```json
{ "task": {…}, "roomTask": {…}, "assignees": ["mom", "dad"] }
```

Gate order: children → `403`; cross-house room → `404` (anti-enumeration);
`admin-only` creation by a non-owner → `403`; `private` creation stamps the
creator as `ownerUserId`. Inside the transaction the house row is locked first
(documented order house → room_task → dependents) and every assignee
membership is revalidated under the lock (a removed member → `400` and zero
writes). The mount variant (`{ roomId, taskId }`) and
`POST /room-tasks/from-template` went with CS-PLAN-091 WP-B6: the form fills a
catalog template into its fields and creates through this body.

> **Canonical `assignees` (CS-PLAN-066 D1):** every public `assignees` field —
> read (`GET /room-tasks`), the create and edit aggregates and the enriched
> execution rows below — is a unique, deterministically ordered Nextcloud
> **user-ID string array** (ordered by `room_task_id, user_id`).

> **Closed constraint set (CS-PLAN-063 §3.1):** the definition fields are
> `name`, `description`, `energyCost`, `priority`, `frequencyType`,
> `frequencyValue`, `estimatedMinutes`, `category`, `scopeType`,
> `recurrenceMode`, `activeFrom`, `activeUntil`, `isVoluntary`,
> `schedulingMode`, `fixedScheduleTime`, `fixedScheduleDays`, `fixedMonthDay`
> (1–31, a fixed monthly rule), `flexDays`, `instructions`, `tips` and
> `visibilityType`. Any other field — the retired `taskTier` (CS-PLAN-070 WP4)
> included — answers `400 VALIDATION_ERROR` (`Unknown or immutable field`).
> Value validation is service-owned (`TaskService::validateTaskData`); a `400`
> carries the localized `details[]` error list. Every value is
> range/type-checked — `name` ≤ 255 characters, `description` a string of
> ≤ 2000 characters (D-091-111), `energyCost` ∈ [1, 20], `schedulingMode` ∈
> `dynamic|static`, parseable `fixedScheduleTime` / `activeFrom` /
> `activeUntil`, `fixedScheduleDays` as a weekday list over `monday`…`sunday`
> (canonical JSON persisted), `flexDays` ∈ [0, cap], `instructions`/`tips` ≤
> 2000 chars, and strict types (non-strings/floats are rejected with 400, never
> coerced). Empty strings clear a date field, and an empty `description`,
> `instructions` or `tips` is stored as no text. `estimatedMinutes` stays accepted
> and stored, but no client shows it and it feeds no calculation; energy is
> the only effort measure (FEAT-UX-046).
>
> **Flex cap (FEAT-UX-039, CS-PLAN-088 D-088-12):** `flexDays` may be at most
> half the recurrence period, rounded up: period = `frequencyValue` × unit
> days (daily 1, weekly 7, monthly 30; `custom` one week); a fixed weekly rule
> on several weekdays uses the smallest gap between them (Mon+Thu → 3 → cap 2);
> a one-time task keeps 7; 366 is the sanity bound. Examples: every 10 days → 5,
> every 2 weeks → 7, every 6 months → 90, daily → 1. An edit re-checks the cap
> against the merged definition only when `flexDays` or a rule input
> (`frequencyType`, `frequencyValue`, `recurrenceMode`, `schedulingMode`,
> `fixedScheduleDays`) changes, so a stored value above the cap stays valid on
> unrelated edits. A changed `flexDays` widens or narrows the current window at
> once (ADR-021 §1); closed cycles keep the window they recorded.

#### PUT /room-tasks/{id} (edit aggregate, ADR-021, CS-PLAN-091 WP-B6)
One save of the one form is one atomic write (BUG-UX-011, FEAT-UX-040): the
definition, its sub-tasks, the room, the assignees, the first date and the
sleep switch change together or not at all. The body is the top-level JSON
object (the BUG-API-001 pattern: the controller reads the request parameters
without the route's `id`). Closed body:

```json
{
  "commandId": "<uuid>",
  "expectedVersion": 4,
  "task": { "name": "Wipe the counters", "flexDays": 2 },
  "roomId": 12,
  "assigneeUserIds": ["mom"],
  "checklistItems": [{ "id": 31, "label": "Clear the counter" }, { "label": "Wipe it" }],
  "firstDate": "2026-11-20",
  "sleeping": false,
  "scope": "thisRoom"
}
```

`expectedVersion` comes from the row's `cycle` block. Every other field is
optional and an absent one stays as it is; `task` follows the closed
constraint set above. `checklistItems` replaces the list: an entry with the
`id` of one of the task's sub-tasks keeps it (relabelled, in the new order),
an entry without one adds a sub-task, and a sub-task the list leaves out goes
— its ticks stay as facts and no longer count. An `id` that is not one of the
task's sub-tasks is a `400`.

- **Who:** adults and the owner who may see the task. A child is refused with
  `403`; a task the member cannot see, a foreign or a deleted room task answer
  `404`. Admin-only visibility is set and unset by the owner only (`403`). A
  task that becomes private is claimed by the member who saves it; an assignee
  who edits a private task leaves its owner as it is, and only that owner
  changes its visibility (`403` for an assignee, D-091-109). A save never takes
  the member's own sight of the task: leaving the assignees of a private task
  that is not one's own is a `403` and writes nothing (D-091-108).
- **Shared definitions (FEAT-UX-043):** a save that changes the definition's
  values or its sub-tasks while several rooms share the definition needs a
  `scope`. `allRooms` edits the shared definition; `thisRoom` splits off a copy
  that only this room task uses from now on — the ticks of its current cycle
  follow their sub-tasks, the other rooms keep the definition as it was.
  Without a scope the save answers `409 SCOPE_REQUIRED` and writes nothing;
  the client asks and sends it again. A save that changes neither needs no
  scope. A member who may not see every room task of the definition (a private
  task, ADR-010) is never asked: the save splits off for this room whatever
  the scope says, and the others stay untouched (D-091-110).
- **Move:** a different `roomId` moves the room task to another room of the
  house (a foreign room → `404`, a room that already holds the definition →
  `409 CONFLICT`). Its completions and closed cycles belong to the room task
  and move with it; the room journal keeps every entry in the room where it
  happened.
- **First date:** `firstDate` (same range as the create) moves the first
  window only until the first completion; a different date afterwards answers
  `409 CONFLICT`. From then on the date correction of the latest entry moves
  the window (D-091-19). A bonus task has no window and ignores it.
- **Assignees:** the list replaces the old set; every member is checked under
  the house lock (a member who left → `400`, nothing written). A changed set
  bumps `cycle.expectedVersion`. Capacity never refuses an assignment
  (ADR-022): a child's day limit only trims what is offered, never what the
  child owes.
- **Sleep:** `sleeping` and `wakeOn` as in `PUT /room-tasks/{id}/sleep`. A save
  that wakes the task wakes it first, so a first date of the same save holds;
  an unchanged sleep state is never checked again.
- **Receipt:** a version that moved on answers `409 STALE_TARGET`, an inactive
  task `409 CONFLICT`. A repeated `commandId` with the same body replays
  (`replay: true`); with another body it is a `409 CONFLICT`. Execution and
  replay decide with the member as they are now: whoever left the house, lost
  sight of the task or became a child gets the refusal, never the stored
  answer. A committed save writes one audit event.

The answer is `{ task, roomTask, assignees, checklistItems, cycle, replay }`:
the definition the room task uses now (the copy after a split), the room task
with its `assignees` and `cycle`, the sub-tasks in their order, and the fresh
`cycle` block the form shows its read-only next window from. A task is
deleted from the form with [`DELETE /room-tasks/{id}`](#delete-room-tasksid)
(D-091-86).

> **Edits of the rule, flex or energy (BUG-UX-014, ADR-021 §1):** open work is
> not frozen. A changed rule, `flexDays` or `energyCost` applies to the current
> cycle of every room the edited definition is mounted in at once: its window,
> its `cycle.expectedVersion` and the `nextDue` projection follow in the same
> transaction. A client refreshes its rows after the save; a completion with
> the older version answers `409 STALE_TARGET`. Completed work keeps its
> `totalEnergy`. A rule that becomes fixed, or a fixed rule whose days change,
> counts its days from the day of the edit (D-091-28).

#### POST /room-tasks/{id}/postpone (ADR-021 §6, CS-PLAN-091 WP-B3)
Closed body `{ "commandId": "<uuid>", "cycleKey": "c88", "expectedVersion": 4 }`.
Every member who may see the task, children included (`404` for a task the
member cannot see). Moves the current window by half the flex days, rounded
up, at most three times per cycle; `409 NOT_POSTPONABLE` without flex days or
after the third press, `409 WINDOW_ENDED` once the window has ended,
`409 STALE_TARGET` for a key or version that is no longer current (a bonus or
sleeping task has no cycle to name), `409 CONFLICT` for an inactive task. The
postponement belongs to its cycle key: the next cycle is not affected, and an
undo of the completion reopens the cycle postponed as it was, its count of
presses included (D-091-62). A repeated `commandId` replays (`replay: true`).
The answer is `{ roomTask, cycle, replay }` with the fresh `cycle` block; the
recent activity shows the postponement.

#### PUT /room-tasks/{id}/sleep (ADR-021 §7, CS-PLAN-091 WP-B4)
Closed body `{ "commandId": "<uuid>", "expectedVersion": 4, "sleeping": true,
"wakeOn": "Y-m-d" }` — `expectedVersion` from the row's `cycle` block, no
`cycleKey`; `wakeOn` optional, a real house-local day after today and at most
366 days ahead (`400 VALIDATION_ERROR` otherwise). Adults and the owner who may
see the task (`403` for a child, `404` for a task the member cannot see);
`409 STALE_TARGET` for a version that moved on, `409 CONFLICT` for an inactive
task. A sleeping task has no cycle, is never overdue and appears in no list of
due work; fixed cycles it had already missed are recorded first, and the
current cycle ends with its postponement (D-091-82). Sending
`sleeping: true` for a sleeping task only moves the wake date. On `wakeOn` (the
timeline job, also when it runs late), on `sleeping: false` or on a completion
the task wakes with a fresh cycle: an after-completion or one-time task hangs
on the wake day, a fixed rule continues on its next rule day and keeps its
parity; the sleep time counts no missed cycle. A bounded fixed rule with no
rule day left ends when it wakes; its completion while asleep is refused with
`409 TASK_ENDED`. A repeated `commandId` replays.
The answer is `{ roomTask, cycle, replay }` with the fresh `cycle` block.

#### GET /room-tasks/bonus (ADR-021 §8, CS-PLAN-091 WP-B4)
The bonus tasks *Today* lists in their own section: the active, awake,
viewer-visible room tasks whose definition is a bonus task. Rows have the
shape of the filtered daily rows (`roomTask`, `task`, `room`, `assignees`,
`cycle` = `{ "state": "bonus", "expectedVersion": n }`, `openWindow`,
`checklistItemCount`) plus `creditedToday`: the user ids the task already
counted for on this house-local day, who cannot be credited again today. A
child reads its own and the unassigned bonus tasks; neither the daily limit
nor availability applies, because bonus work is never planned.

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
    "assignees": ["dad"],
    "cycle": { "cycleKey": "d2026-11-16", "expectedVersion": 1, "windowStartOn": "2026-11-16",
               "windowEndOn": "2026-11-16", "centreOn": "2026-11-16", "state": "overdue",
               "postponeCount": 0, "postponeLimit": 3, "canPostpone": false },
    "checklistItemCount": 0
  }
]
```

`overdue` lists the rows whose cycle is `overdue` — one row per task, however
long ago its window ended (ADR-021 §1) — and `due-today` the rows whose cycle
is `open`.

These endpoints are the authoritative count projections for the task
counters (`totalOverdueTasks` / `totalDueTodayTasks` read the complete
endpoint results — a scoped room load or the child-limited daily list can
never masquerade as a count source, CS-PLAN-066 D2).

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
  This is the batched read the task list loads instead of the former
  per-room request loop.
- `activeOnly` (optional, default `true`): limit to active instances; `false`
  adds finished/deactivated instances (the TaskManager *Completed* filter).
  Deleted (tombstoned) room tasks are never listed (CS-PLAN-084 D-084-07).

**Cycle block (ADR-021):** every room-task row and every execution row carries
the task's one current cycle, derived on read:

```json
{ "cycleKey": "c88", "expectedVersion": 4, "windowStartOn": "2026-11-21",
  "windowEndOn": "2026-11-23", "centreOn": "2026-11-22", "state": "open",
  "postponeCount": 0, "postponeLimit": 3, "canPostpone": true }
```

- `cycleKey` is `d<Y-m-d>` for a cycle that hangs on a day (the first date, a
  fixed rule day) and `c<completionId>` for the cycle that follows a
  completion of an after-completion rule. `cycleKey` and `expectedVersion` are
  the receipt of `POST /room-tasks/{id}/complete`.
- `centreOn` is the planned day, `windowStartOn`/`windowEndOn` the flex days
  around it (house-local days, a postponement included).
- `state` is `upcoming` (the window has not opened), `open` or `overdue` (the
  window ended; one red entry per task). A bonus task carries only
  `{ "state": "bonus", "expectedVersion": n }`, a sleeping task
  `{ "state": "asleep", "expectedVersion": n, "wakeOn": "Y-m-d" | null }` —
  asleep wins over bonus; both are completed with the version and without a
  `cycleKey`. A task without a cycle (a one-time task that is done, an inactive
  one) carries `cycle: null`.
- `canPostpone` is the precondition of the postpone command; `postponeCount`
  says how often the cycle was moved, at most `postponeLimit` times.

**`status` (CS-PLAN-084 D-084-08)** derives from it: `overdue` for an `overdue`
cycle, `due_today` for an `open` one — also when the planned day has passed
inside an open flex window — and `due_soon`/`upcoming` before the window
opens. The client's overdue badge, overdue filter and claim affordance follow
the same state. The row's `nextDue` is only the projection the list queries
sort by: the start of the window's last day.

The canonical `assignees` decide who a row belongs to; there is no per-cycle
handover.

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
    "anchorOn": "2026-02-11",
    "isActive": true,
    "status": "due_today",
    "assignees": ["mom"],
    "cycle": { "cycleKey": "c88", "expectedVersion": 4, "…": "…" }
  }
]
```

> **`assignees`** is the canonical single assignee representation
> (CS-PLAN-060 WP1 D2): the `chorestrike_task_assignees` join table keyed by
> Nextcloud user IDs. The legacy `room_tasks.assigned_to_user_id` scalar
> column and its payload key were folded out — reads never merge legacy and
> canonical state. Assignment travels in the form's `assigneeUserIds` array
> — `POST /room-tasks` and `PUT /room-tasks/{id}` — also for a single
> assignee.
> See `architecture.md` (`chorestrike_room_tasks`). The standalone
> `POST /room-tasks/{id}/assign` / `…/unassign` and single-assignee
> `POST|DELETE …/assignees` variants were pruned in CS-PLAN-055, and
> `GET|PUT /room-tasks/{id}/assignees` and `POST /room-tasks/bulk-assign` in
> CS-PLAN-091 WP-B6 (D-091-87).

#### Who a row belongs to and who is credited

The `proposal` block went with the agreements (ADR-021, CS-PLAN-091 WP-B1). A
row belongs to its canonical `assignees`: Today's personal filter and the
child's daily eligibility read them, and an unassigned row is offered to
everyone. A completion without `participants` credits equal shares of the
assignees, or the recorder for unassigned work; the client sends it for a
held Done button and opens its picker (who did it, which sub-tasks are done)
for a short tap. Private and admin-only
visibility is checked before any enrichment.

#### PUT /room-tasks/{id}/due-date · PUT /room-tasks/{id}/last-done — removed

Both went with ADR-021 (CS-PLAN-091 WP-B1, D-091-19); the routes answer `404`.
The next window is never edited directly: the first date sets it until the
first completion, afterwards the work itself does. Retained `last_done`
journal events keep replaying in the room journal.

#### POST /room-tasks/{id}/undo — removed in WP3

> **WP3 targeted correction (CS-PLAN-074 WP3, [ADR-014](../adr/ADR-014-work-accounting-ledger-and-replay.md)):**
> this latest-by-room-task route is gone. Undo runs exclusively through
> [`POST /completions/{id}/undo`](#post-completionsidundo) — the exact
> completion targeting contract (C3e) with the receipt envelope, completer/
> owner authority and the latest-selected-allocation reversal.

- **Actor gate:** the recorded completer or a house owner (masterplan decision
  D3; participation alone is no authority). A completer who left the house
  leaves the owner as the only authority.
- **No revertible completion:** `200` with `undone: false` and **zero
  writes** — undo is idempotent, and the current state is echoed back for
  client reconciliation.
- **Non-retractions (ADR-011, D8):** medals already awarded, the weekly-goal
  notification claim, and dispatched notifications survive an undo.

#### DELETE /room-tasks/{id}
Remove the task from its room (a tombstone, CS-PLAN-084 D-084-07): it leaves
every list, and the work done on it stays in the history. The client deletes
from the one form after a confirmation (D-091-86).

> **Lifecycle authorization (CS-PLAN-056, CR-053-06; unified in ADR-010 /
> CS-PLAN-061):** the lifecycle mutations —
> `POST /room-tasks/{id}/complete` and `POST /completions/{id}/undo` —
> enforce the ONE task-visibility matrix
> via `TaskAccessPolicy` (public → any member; private → creator ∪ that room
> task's explicit assignees; admin-only → house owner). **Visibility denials
> return `404 RESOURCE_NOT_FOUND`** (anti-enumeration, decision D3) — never a
> 403 that would confirm the id exists; role denials (a child actor) stay
> `403 FORBIDDEN`. Completion additionally answers `409 CONFLICT` for an
> inactive room task and the coded conflicts of its receipt (below).

> **Full route list:** the canonical, complete route table is
> [`appinfo/routes.php`](../../appinfo/routes.php) (93 routes spanning `house`,
> `floor`, `room`, `task`, `room_task`, `game`, `user`, `scheduling`,
> `checklist`, `minigame`, `setup`, and `test`). The task-sync ×6 and
> reward-config ×2 HTTP surfaces were pruned in CS-PLAN-055 together with
> 31 further no-consumer/test-only routes (disposition list + guard in
> `RouteMatrixTest`); CS-PLAN-063 pruned `POST /tasks` and CS-PLAN-064 pruned
> `PUT /tasks/{id}/uncomplete` + `POST /room-tasks/{id}/reactivate` while
> adding the undo aggregate. CS-PLAN-091 WP-B6 pruned `PUT /tasks/{id}`,
> `POST /room-tasks/from-template`, `POST /room-tasks/bulk-assign`,
> `GET|PUT /room-tasks/{id}/assignees` and the four checklist-item writes for
> the one form's two aggregates. CS-PLAN-091 WP-B7 pruned the four routes
> that had lost their client: `PUT /rooms/{id}/cleanliness` (its journal
> entries keep replaying), `GET /room-tasks/room/{roomId}/stats`,
> `PUT /pauses/{id}` (shortening an absence; stored shortened revisions keep
> governing) and `GET /house/health` (the statistics compute the health).
> Re-adding a route requires a consumer.
> This document covers the core CRUD + completion
> flows; the `RouteMatrixTest` contract test guards route↔controller drift.

#### POST /room-tasks/{id}/complete

Complete the **current cycle** of a room task — one transactional aggregate
(ADR-021 §4 receipt + CS-PLAN-074 WP3 work accounting,
[ADR-014](../adr/ADR-014-work-accounting-ledger-and-replay.md)). The work
timestamp is resolved server-side. The request body is a **closed set**;
unknown fields are a `400`:

```json
{
  "commandId": "6f1e2a4c-9b7d-4e01-8a2f-3c5d6e7f8090",
  "cycleKey": "c88",
  "expectedVersion": 4,
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
- `cycleKey` / `expectedVersion` — from the row's `cycle` block. `cycleKey` is
  absent for a bonus task, which has no cycle; a value that is not a cycle key
  is a `400 VALIDATION_ERROR`.
- `participants` (optional) — the explicit actual-work split: 1–100 unique
  eligible house members with positive whole percents totaling exactly 100.
  Absent → equal shares of the assignees, or the recorder for unassigned
  work. The recorder need not take part (ADR-021 §10; Q-074-02 reversed):
  anyone records work for anyone, and the completion keeps who recorded it.
  Every credited member must be allowed to see the task — public: every
  member; private: its creator and the room task's assignees; admin-only:
  the owner — else `403 FORBIDDEN` (D-091-20).
- `allocationReviewed` (optional, default `false`) — the explicit confirmation
  when the submitted split deviates from the default; an unreviewed deviation
  → `409 CONFLICT`.

The target is the current cycle **at any time** — before, inside or after its
window (D-091-25). The conflicts carry a machine-readable `error.code`:

| Code | When |
|------|------|
| `STALE_TARGET` | `cycleKey` is not the current cycle, or `expectedVersion` moved on (an edit of the rule, flex, energy, assignees or first date; another completion or undo) |
| `ALREADY_COMPLETED` | a new command on a cycle that a valid completion already closed |
| `SAME_DAY` | the room task already has a valid completion on this house-local day (not for a bonus task) |
| `BONUS_ALREADY_TODAY` | a bonus task already counted for one of the people to credit on this house-local day (ADR-021 §8); the same rule refuses the owner's change of people onto such a member |
| `TASK_ENDED` | a sleeping task whose bounded rule ended while it slept: the completion would wake it into no cycle; waking it (`PUT /room-tasks/{id}/sleep`) closes it (D-091-83) |

The transaction freezes the work evidence (the cycle key and its final window,
the classification `on_time` — before or inside the window — `late` or
`bonus`, work date/zone, total energy from the definition, frozen rate),
writes the allocation revision + work shares, posts exact wallet-union deltas
to the immutable `wallet_entries` ledger, appends the `room_events` journal
delta (the saved **requested** effect — the replay clamps), records the closed
cycle exactly once (`chorestrike_cycles`) — first recording as missed the fixed
cycles that stopped being current before it and that the timeline job had not
reached yet (D-091-43) — moves the `nextDue` projection to
the next cycle and recomputes the personal recognition of every participant —
all under the documented lock order. Every sub-task of the cycle is marked
done in the same transaction; a tick that was already set keeps who set it.
A one-time task ends with its cycle; a bonus task closes none. Late work of
an assigned task that none of its assignees is credited for is a takeover:
the room task records who took it over, and the first assignee gets the
digest receipt. Gates run before any write: inactive room task →
`409 CONFLICT`, visibility → `404`/`403` per the standard above. Post-commit
effects (weekly-goal read, notification dispatches) are failure-isolated: the
endpoint still answers `200` with `rewards.weeklyGoal = null`.

A bonus task takes any number of completions a day, each person once; its
completions, their undo and their date corrections leave its `expectedVersion`
as it is, so several members complete it with the version their rows showed
(D-091-84). A sleeping task is woken by its completion (WP-B4). The completion freezes the
coin factor of every member and the bonus percent of its work day, and pays
each share `10 × energy × sharePct × factor × bonusPercent / 100` coin minor,
rounded down once (WP-B5, D-091-15); energy and the recognitions never see a
multiplier. `rewards.completion.bonusPercent` names the percent.

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
    "cycle": {
      "cycleKey": "c88", "expectedVersion": 5, "windowStartOn": "2026-03-04",
      "windowEndOn": "2026-03-04", "centreOn": "2026-03-04", "state": "upcoming",
      "postponeCount": 0, "canPostpone": false
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

> `cycle` is the **next** current cycle riding back (`null` when the task has none left), on a
> replay as well, read fresh: the first attempt committed, so the shown cycle is closed. `completionId` +
> `replay` identify the ledger row and the idempotent replay. The
> `rewards.wallet` / `rewards.contributionMinor` describe the **caller's own**
> totals in exact minor units (100 coin minor = 1 Coin; no coin integers, no
> combo/team-spark fields anywhere — the legacy economy is retired). The owning
> client stores reconcile from this one response without arithmetic.

#### GET /room-tasks/range?from=&to=
Get scheduled and completed tasks within a date range (calendar view). Max span: 60 days.

Every scheduled item carries its `assignees` (user ids) and its `cycles`
inside `[from, to]`: the current cycle and the ones the rule yields after it —
a fixed rule's next rule days, an after-completion rule's next planned days as
if each cycle were done on its planned day. A one-time task has one cycle.
Each entry is `{ date, cycleKey, windowStartOn, windowEndOn, current, late }`:
`date` is the planned day, `cycleKey` is set for the current cycle only (the
others are projections and no completion target), and `late` marks a current
cycle whose window has ended. The calendar marks exactly those days overdue —
a past planned day inside an open flex window is still due (CR-084-01). A
task without a cycle planned inside the range is left out. Today does not read
this route (CS-PLAN-090 WP-B). The range row's `roomTask` is the plain entity;
every other response that serializes a room task reports `status` from its
cycle (CR-084-03).

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
        "assignees": ["mom"],
        "cycles": [
          { "date": "2026-07-28", "cycleKey": "c41", "windowStartOn": "2026-07-26", "windowEndOn": "2026-07-30", "current": true, "late": false },
          { "date": "2026-08-04", "cycleKey": null, "windowStartOn": "2026-08-02", "windowEndOn": "2026-08-06", "current": false, "late": false }
        ]
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
> reader — a stale in-flight response can never overwrite a newer one.

#### GET /room-tasks/filtered-daily
The Today payload for the current user (CS-PLAN-057/061): every
viewer-visible row whose cycle is `open`, in the read order
`nextDue, createdAt, id`, each with its canonical `assignees` list
(CS-PLAN-065 decision D4).

**Today's work is what the live suggestion places on today (ADR-022,
CS-PLAN-091 WP-C).** Such a row carries `suggestedFor`, the user ids the
suggestion places it on (every assignee of a team task), and `overload`, true
when it fits no day of its window. These rows come first. The day's first read
stores today's list (ADR-022 decision 3); nothing else is written.

**Also possible (BUG-UX-005, CS-PLAN-088):** after them follow the other
viewer-visible rows whose cycle is `open` — placed on another day, or no
longer on today's list. They carry `openWindow: true` and neither
`suggestedFor` nor `overload`.

**A child** reads nothing on a weekday it is not available on. Otherwise it
reads the rows suggested to it, at most its owner-set daily limit (work that
fits the day before overload), and as `openWindow` rows its own work above the
limit and the open rows that are its own or unassigned (decision D4). The limit
never hides what the child owes (BUG-UX-013). Unassigned work that today's
suggestion places on someone else is not offered to the child.

**Response `data`:** `{ rows, load }`.

- **`rows`**: list of `{ roomTask, task, room, assignees, cycle, openWindow,
  checklistItemCount }` objects, with `suggestedFor` and `overload` on the rows
  of today's suggestion.
- **`load`**: the viewer's load of today, as the suggestion that placed the
  rows booked it — one `members` row of `GET /scheduling/suggestion`
  (`{ userId, day, budget, placed, doneMinor, preferred, absent }`), or `null`
  for a viewer the plan does not know. *Today* shows `placed` against `budget`
  and says so when more is placed than the day holds. A team task counts for
  the member who carries it, overdue work for the one member it counts against.
  After a write the client reads the route again while *Today* is shown and
  takes only the load: the rows of today's list keep their place.

**Checklist count (BUG-UX-003, CS-PLAN-088 D-088-19):** the filtered-daily,
due-today and overdue rows carry `checklistItemCount`, the number of checklist
items of the row's definition (0 without items), from one grouped query; My
Day offers its checklist toggle only above zero.

### Checklists

Checklist endpoints for tasks and room tasks. Business logic and
authorization live in `ChecklistService` (CR-053-01); the guard enforces
**house linkage** (room task → room → floor → house) plus the
**task-visibility matrix** (public → any member; private → task owner;
admin-only → house owner) on every endpoint. Denials return
`404 RESOURCE_NOT_FOUND` (anti-enumeration, the ADR-010 contract of the edit
aggregate).

| Endpoint | Purpose |
|----------|---------|
| `GET /checklists/task/{taskId}` | List a task's checklist items |
| `GET /checklists/progress/{roomTaskId}?cycleKey=` | The ticks and stats of the current cycle |
| `POST /checklists/toggle/{roomTaskId}/{itemId}` | Toggle a sub-task of the current cycle (auto-creates the progress row — the pruned explicit `initialize`/`complete` pair is not needed) |

**Authorization notes (CR-053-01):**
- Cross-house task/item/room-task ids → `404 RESOURCE_NOT_FOUND` on every
  endpoint (no 403 that would confirm existence).
- Checklist items of private/admin-only tasks follow the visibility matrix:
  invisible to non-entitled members → `404`.
- The sub-tasks themselves are written by the one form (`checklistItems` of
  `POST /room-tasks` and `PUT /room-tasks/{id}`); the add, relabel, delete and
  reorder routes went with CS-PLAN-091 WP-B6.
- `toggle` verifies the item belongs to the room task's task checklist
  (`404` otherwise) — progress rows of an unrelated task cannot be flipped.

**Progress per cycle (ADR-021 §3):** ticks belong to the current cycle of the
room task and live in `chorestrike_cycle_progress`. Both endpoints answer
`{ items | progress, stats, cycle: { cycleKey, windowStartOn, windowEndOn } }`.
The optional `cycleKey` (query parameter of the read, body field of the
toggle) names the cycle the client shows and `expectedRevision` (toggle body)
the highest progress revision it has seen; a value that is not a cycle key is
a `400 VALIDATION_ERROR`. A read for a key that is no longer current answers
empty with `cycle: null`; a toggle for it, or with a stale revision, answers
`409 STALE_TARGET`. Partly ticked work stays saved and the task stays open
(D-089-02); the next cycle starts without ticks, and the ticks of a closed
cycle stay as stored. Progress keyed by an occurrence
(`chorestrike_chk_progress`) stays as history and is no longer written.

The completion command marks every sub-task of its cycle done (ADR-021 §4);
ticking only some of them goes through the toggle and leaves the task open.

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

Scores-only since the CS-PLAN-070 WP3 cutover ([ADR-014](../adr/ADR-014-work-accounting-ledger-and-replay.md)):
no minigame route mints, spends or reads Coins, wallet values or contribution.
Every route is house-scoped and membership gated (`403 FORBIDDEN` for
non-members, checked first). Game ids come from the server catalog
(`ArcadeCatalog`: `spots`, `socks`, `snake`, `tiles` and `dust`); an unknown id is a
`400 VALIDATION_ERROR` on reads and writes (CS-PLAN-086). Read `limit`
parameters are clamped to `1..50`. Saves are rate-limited to **100 per member
and house-local day** (`429 MINIGAME_RATE_LIMITED`). Ordered reads break score
ties by ascending id.

Games unlock as milestones of finished chores
([ADR-019](../adr/ADR-019-arcade-unlocks-from-completion-history.md)): a
member's count is the number of valid completions in whose selected
allocation revision they hold a share (helpers count; undone and revised-away
work does not), since the member's last owner reset if there was one
(`POST /house/members/{userId}/arcade-reset`). The thresholds are the
owner's (`PUT /house/arcade-thresholds`) or the catalog defaults. The state is
derived on every read, so an undo can re-lock a game. Saves for a locked game
are refused; every read stays open, so a locked game's leaderboard remains
readable.

#### GET /minigames/catalog
The requesting member's arcade: every game in catalog order with its unlock
threshold and lock state.

**Response `data`:**
```json
{
  "gateEnabled": true,
  "finishedChores": 7,
  "games": [{ "gameId": "snake", "threshold": 10, "unlocked": false }]
}
```
`gateEnabled` is the owner switch (`PUT /house/arcade-gate`); while it is
off, every game is `unlocked`. `finishedChores` counts since the member's last
reset, and `threshold` is the effective value (an owner's, else the default).
Failure envelopes: `403 FORBIDDEN`, `500 MINIGAME_CATALOG_ERROR`.

#### POST /minigames/{gameId}/scores
Save a finished game.

**Request:** `{ "score": 250, "durationMs": 48000 }` — both are required;
`durationMs` is the round's active play time (pauses excluded).

Per-game rules (`ArcadeCatalog`): `0 <= score <= maxScore` (Spot Sprint:
3000, Sock Match: 800, Snake: 20000, Tile Breaker: 50000, Dust Runner:
36000), `1 <= durationMs <= maxDurationMs` (Spot Sprint: 65 seconds for its
60-second round, Sock Match: 24 hours for its untimed round, Snake, Tile
Breaker and Dust Runner: one hour), and the score must be reachable in that
time: `score <= burstPoints + pointsPerSecond * durationMs / 1000` (Spot
Sprint: 100 + 50 per second, Sock Match: 800 + 1 per second, Snake: 100 + 20
per second, Tile Breaker: 300 + 20 per second, Dust Runner: 100 + 10 per
second). Sock Match is untimed and never
rewards speed; a lucky perfect round lasts only seconds, so its burst covers
the whole maximum and the ceiling is the binding rule.

**Response `data`:**
```json
{ "id": 42, "score": 250, "gameId": "snake", "createdAt": "2026-10-16T18:04:11+00:00" }
```
Failure envelopes, all with zero writes: `400 VALIDATION_ERROR` (unknown game,
missing `score` or `durationMs`, score or duration out of bounds),
`400 MINIGAME_IMPLAUSIBLE_SCORE` (score not reachable in the reported time),
`403 MINIGAME_LOCKED` (the member has not unlocked the game; checked under the
house lock), `429 MINIGAME_RATE_LIMITED`, `500 SAVE_SCORE_ERROR`.

#### GET /minigames/{gameId}/leaderboard?limit=10
House-wide best scores, ordered by score, each
`{ id, userId, displayName, score, createdAt }`.

#### GET /minigames/{gameId}/leaderboard/weekly?limit=10
Same shape, restricted to the current house-local week (Monday 00:00 → now).

#### GET /minigames/{gameId}/personal-best
**Response `data`:** `{ "id": 42, "score": 300, "createdAt": "…" }`, or `null`
before the first save.

#### GET /minigames/{gameId}/rank
**Response `data`:** `{ "rank": 2, "gameId": "snake" }` (`0` = no score yet).

#### GET /minigames/{gameId}/recent?limit=5
The requesting member's own scores, ordered by score (shipped behavior) —
never other members' rows; each `{ id, score, createdAt }`.

### Scheduling

> **Removed in CS-PLAN-091 WP-B1:** the occurrence agreement commands
> (`POST /occurrences/{id}/amend`, `…/catch-up`, `…/restart`; ADR-021 §13) and
> the owner's preview and apply (`POST /scheduling/suggestions`,
> `POST /scheduling/apply`; ADR-022). The routes answer `404`; stored
> agreements stay as history. No scheduling route refuses at a budget any more.
> `GET /scheduling/suggestion` (CS-PLAN-091 WP-C) replaces preview and apply.

#### GET /scheduling/preferences
Get the current user's scheduling preferences (member-gated).

**Response `data`:**
```json
{
  "userId": 7,
  "energyBudget": { "monday": 10, "tuesday": 10, "wednesday": 10, "thursday": 10, "friday": 10, "saturday": 15, "sunday": 15 },
  "weekdayAvailability": { "monday": true, "tuesday": true, "wednesday": true, "thursday": true, "friday": true, "saturday": true, "sunday": true },
  "preferredWeekdays": ["wednesday", "saturday"],
  "maxDailyTasks": null
}
```

> CS-PLAN-065 (decision D7): the reader-less `todayConsumedEnergy` field is
> gone — capacity is derived server-side. `maxDailyTasks` is `null` when
> unset (unlimited) and a finite integer (1-100) when the owner set a child
> daily-task limit (decision D5). `preferredWeekdays` (ADR-022 decision 5) is
> the list of weekdays the suggestion fills first, Monday first, empty until
> the member chose days.

#### PUT /scheduling/preferences
Save the current user's weekly preferences in **one** bulk request
(CS-PLAN-033, closes CR-016-02; replaced the removed per-day
`PUT /scheduling/energy` and `PUT /scheduling/availability`).

**Request** — sections are optional, but at least one is required.
`weekdayAvailability` and `energyBudget` must each be the **complete week**
(exactly the seven canonical weekday keys); `preferredWeekdays` (ADR-022
decision 5) is a list of canonical weekday keys, possibly empty. The merge is
section-granular: a provided section replaces the stored one wholesale, absent
sections and sibling keys (e.g. `maxDailyTasks`) stay unchanged.

```json
{
  "weekdayAvailability": { "monday": true, "tuesday": false, "wednesday": true, "thursday": true, "friday": true, "saturday": true, "sunday": true },
  "energyBudget": { "monday": 10, "tuesday": 10, "wednesday": 10, "thursday": 10, "friday": 10, "saturday": 15, "sunday": 15 },
  "preferredWeekdays": ["wednesday", "saturday"]
}
```

**Response `data`:** the full persisted preferences (same shape as `GET`).

**Errors:** `400 VALIDATION_ERROR` (no section provided, partial week,
unknown weekday key, non-bool availability, energy outside 1-20, preferred days
that are no list of weekdays), `403 FORBIDDEN`
(non-member). Persistence is a single validated read-modify-write on one
`house_user` JSON column — inherently atomic, no multi-statement transaction.

#### GET /scheduling/suggestion?from=&to= (ADR-022, CS-PLAN-091 WP-C)
The live suggestion for a range of house-local days: both real `Y-m-d` days,
`to` not before `from` (`400 VALIDATION_ERROR` otherwise). The range is cut to
the days from today to 13 days after today before anything is read, so a
client whose day ran over midnight still gets the days that remain; a range
wholly outside answers empty `days`, `overdue` and `members`. Every answer
names the horizon, so no client copies it. Member-gated
(`403 FORBIDDEN`). Nothing is written except today's per-house snapshot on the
day's first read (ADR-022 decision 3). The calendar reads it; *Today* reads `GET /room-tasks/filtered-daily`.

**Response `data`:**
```json
{
  "days": [
    {
      "day": "2026-11-23",
      "entries": [
        { "roomTaskId": 11, "cycleKey": "c88", "userIds": ["kid"], "energy": 2, "overload": false,
          "windowStartOn": "2026-11-22", "windowEndOn": "2026-11-24", "taskName": "Vacuum", "roomId": 4, "roomName": "Hall" }
      ]
    }
  ],
  "overdue": [
    { "roomTaskId": 12, "cycleKey": "d2026-11-20", "userIds": ["kid"], "energy": 3, "overload": false,
      "windowStartOn": "2026-11-20", "windowEndOn": "2026-11-20", "taskName": "Bathroom", "roomId": 5, "roomName": "Bath" }
  ],
  "members": [
    { "userId": "kid", "day": "2026-11-23", "budget": 6, "placed": 5, "doneMinor": 0, "preferred": false, "absent": false }
  ],
  "horizonDays": 14,
  "horizonEndOn": "2026-12-06"
}
```

- **`days`**: one object per day of the range. An entry is a cycle the
  suggestion places on that day. `cycleKey` is the completion target of a
  current cycle and `null` for a cycle the rule yields after it (a projection).
  `userIds` names whom it is suggested to: the member who carries it, then the
  other assignees of a team task (a task with several assignees). `overload`
  is true when it fits no day of its window.
- **`overdue`**: the cycles whose window ended, when the range starts today.
  They are not placed; they count against today's energy of the first member
  in `userIds`. While every candidate is absent today, unassigned work names
  nobody and assigned work keeps naming its assignees.
- **`members`**: the placed energy against the day's energy — the viewer's own
  rows, and every member's for the owner. `placed` on today includes the
  overdue work, `doneMinor` is today's valid work in energy hundredths,
  `budget` is 0 on a weekday the member is not available on.
- **`horizonDays`**, **`horizonEndOn`**: how many house-local days the
  suggestion looks ahead, today included, and the last of them. Present in
  every answer, also for a range wholly outside the horizon.

Entries and overdue cycles are filtered by task visibility (ADR-010); a load is
a sum of energy and names no task. Loads and the `overload` mark of an entry
someone else carries count all work, private tasks included (ADR-022 §6). Today's entries are the stored list with the
leave and join rules of ADR-022 decision 3; later days are computed live.

#### GET /scheduling/members
Get all member availability (house owner only). Each entry carries the
member's `preferredWeekdays` and `maxDailyTasks` child limit (`null` =
unlimited, integer = finite bound, decision D5).

#### PUT /scheduling/members/{userId}
Update another member's weekday availability/energy (house owner only).
The closed payload also accepts `preferredWeekdays` (a list of weekday keys
that replaces the stored one) and the child limit (decision D5):
`maxDailyTasks` omitted = unchanged, explicit `null` = unlimited,
integer 1-100 = finite bound (`0` is a validation error — an unavailable
weekday already expresses a day off). Errors: `400 VALIDATION_ERROR`
(unknown field, invalid weekday, preferred days that are no list of weekdays,
limit outside 1-100), `403 FORBIDDEN` (non-member/non-owner).

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

> **Owner targets (FEAT-UX-015, CS-PLAN-088 D-088-19):** another owner can be
> removed like any member (same departure cleanup, re-checked under the
> house lock); the acting owner remains, so the house keeps an owner. Removing
> oneself answers `403 CANNOT_REMOVE_OWNER` — leaving the house is a separate,
> not yet implemented flow. Removal is durable: membership after the first
> owner is invite-only (ADR-010).

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

`GET /preferences/me` also returns `colorScheme`, the caller's colour scheme
(CS-PLAN-090 WP-K): `standard`, `ocean`, `forest`, `sunset`, `berry` or
`slate`. It is no field of the versioned row and `PUT /preferences/me` does not
accept it.

#### PUT /preferences/me/color-scheme

Set the CALLING member's own colour scheme (children included). A per-user
setting in Nextcloud's user app config, not a fact: no command UUID, no
version, the last save wins on every device. `standard` removes the stored
value. Account erasure and the test-mode reset delete it.

**Request:** `{ "colorScheme": "forest" }`

**Response `data`:** `{ "colorScheme": "forest" }`

| Status | Code | When |
|---|---|---|
| 400 | `COLOR_SCHEME_VALIDATION_ERROR` | anything but a listed scheme key; nothing is written |
| 403 | `FORBIDDEN` | the caller is no member of the house |

The page itself delivers the scheme as initial state `color-scheme` (read with
`@nextcloud/initial-state`), so the app's first paint has it.

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
| `STALE_TARGET` | 409 | The cycle key or cycle version a completion or a sub-task toggle names is no longer current (ADR-021 §4) — reload and retry |
| `ALREADY_COMPLETED` | 409 | ADR-021: the cycle already has a valid completion |
| `SAME_DAY` | 409 | ADR-021: the room task was already completed on this house-local day |
| `BONUS_ALREADY_TODAY` | 409 | ADR-021: the member was already credited on this bonus task today |
| `TASK_ENDED` | 409 | ADR-021: a sleeping task whose bounded rule ended cannot be completed; waking it closes it |
| `NOT_POSTPONABLE` | 409 | ADR-021: the task has no flex days, or the cycle was postponed three times |
| `WINDOW_ENDED` | 409 | ADR-021: the window has ended; a red task cannot be postponed |
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
