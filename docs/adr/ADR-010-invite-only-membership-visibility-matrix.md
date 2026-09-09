# ADR-010: Invite-Only House Membership and the Unified Task-Visibility Matrix

**Status**: Accepted
**Date**: 2026-09-03
**Deciders**: ChoreStrike maintainers (recorded in CS-PLAN-061 WP2 review)

## Context

The pre-WP2 authorization model had three structural defects:

1. **Auto-join membership mutation on reads.** `ensureHouseMember()` auto-created a
   membership on *any* endpoint that touched a house id — including target-derived
   ids (e.g. a foreign `floorId` or a global `house_user` id). Any authenticated
   Nextcloud user on the same instance silently became a member of a house by
   probing ids. Membership removal was therefore not durable: the removed user's
   next `user#current` resurrected the row.
2. **Two competing visibility implementations.** The definition list
   (`TaskMapper::findVisibleByHouse`) said *private = creator only*, while the
   completion and lifecycle gates said *private = explicit assignees only*.
   A private task's creator could not reactivate their own task, and an assignee
   could complete a task whose definition they could not even see.
3. **A 403/404 enumeration oracle.** Foreign-house and nonexistent ids produced
   different responses (403 vs 404) on the same endpoint, letting an attacker
   confirm which global ids exist.

CS-PLAN-061 WP2 was tasked with replacing this with one membership model, one
visibility matrix, and one denial standard.

## Decision

### D1 — Invite-only membership after the first owner

`AuthorizationService::ensureHouseMember()` becomes the **one-time bootstrap**
primitive: it auto-creates a membership **only while the house has zero members**
(that user becomes the `owner`), inside the existing lock-and-count transaction
(CR-053-07). Once members exist, a miss is a denial
(`ForbiddenException('Not a member of this house')`) and **no row is created**;
the message is identical to `assertHouseMember()` so no new enumeration vector
opens up.

Only five **designated bootstrap endpoints** may call it — the frontend's boot
path guarantees one of them fires first for every journey:

| Endpoint | Reason |
|---|---|
| `user#current` | the auto-init call every screen makes first |
| `house#get` | first-journey house read |
| `user#invite` | the owner invites the next member |
| `setup#catalog` | first-run wizard entry |
| `setup#apply` | first-run wizard apply |

All other endpoints (~52 call sites across 11 controllers) use the pure
`assertHouseMember()`. Owner-gated mutations (`requireHouseOwner`) happen in
services (ADR-006 extension) with controller checks kept as defense in depth.

### D2 — One visibility matrix, one implementation

A single `TaskAccessPolicy` service is the source of truth:

| visibility | who sees it |
|---|---|
| `public` | every house member |
| `private` | the **creator** (`owner_user_id`) ∪ the **explicit assignees** (canonical `task_assignees` join table) |
| `admin-only` | house owners only |

Owners do **not** implicitly see private tasks. Granularity:

- **Definition lists** (`findVisibleByHouse`, `TaskService`): definition-level —
  an assignee of any room task sees the definition (SQL `EXISTS` through
  `room_tasks`, no N+1).
- **Room-task lists** (`RoomTaskService` read paths, batch filter
  `filterVisibleRoomTasks`): per-room-task — a member sees exactly their own
  assigned room tasks of a private definition, not another member's chore
  instance; the creator sees all of theirs.
- **Checklists**: definition-level via `TaskAccessPolicy::canViewTask`.

Completion, lifecycle (due-date; the former reactivate/uncomplete pair was
folded into the one undo aggregate by CS-PLAN-064/ADR-011), assignment, and
definition mutation all delegate to the same policy — the "creator can't
reactivate their own private task" divergence is gone.

### D3 — Denial standard: visibility → 404, role → 403

- Visibility denials (foreign/nonexistent id, or an id the caller cannot see)
  throw the app `NotFoundException` → **HTTP 404** `RESOURCE_NOT_FOUND`
  anti-enumeration shape.
- Role/actor denials on otherwise visible resources (child attempting
  management, non-owner structure mutation) throw `ForbiddenException` →
  **HTTP 403** `FORBIDDEN`.

### D4/D5 — Actor matrix

- **Owner**: everything, including structure (floors/rooms/house), definition
  mutations, checklist item editing, role management, invites, removal.
- **Member**: task-domain management (create/edit/delete/assign) within the
  visibility matrix; execution surface.
- **Child**: execution surface only — complete, the lifecycle executions
  (due-date/undo (CS-PLAN-064 replaced the reactivate+uncomplete pair with the
  single undo aggregate), visibility-gated like every lifecycle mutation), checklist progress, own preferences and minigames. The
  management surface (create/update/delete/assign) is enforced by
  `AuthorizationService::assertCanManageTasks()` in every task-domain
  mutation (child → 403).

### Supporting hardening (CR-053-18 cluster, same work package)

Route `requirements` constrain `{id}` to `\d+`, `gameId` to `[a-z0-9-]+`, etc.;
`saveScore` whitelists game ids and caps submissions at 100/house-day (429
`MINIGAME_RATE_LIMITED`); weekday preference keys are lowercased before
persisting and accepted case-insensitively; overdue-claim notifications carry
the claimer's uid (rich `user` id) + display name; medal notifications key their
objectId `{weekStart}:{medalType}` so distinct medals no longer collapse in the
bell; due-reminder passes skip orphaned room tasks instead of aborting the run;
frontend `ApiError` carries first-class `status`/`code`, the user store models
`membership: unknown|member|denied` with role getters, a denied membership
renders an app-wide `NotAuthorizedState`, and the frontend logger redacts
`ApiError` to `{name, status, code, message}`.

## Consequences

### Positive
- Membership removal is durable; no read can resurrect a removed member.
- The 403/404 enumeration oracle is closed; foreign, nonexistent, and invisible
  ids are indistinguishable (404).
- One matrix: creator ∪ assignees everywhere; no lifecycle divergence.
- Children get a safe execution-only surface enforced in the service layer.
- Frontend can branch on stable error codes and model authorization state
  explicitly instead of parsing messages.

### Negative
- Code that previously relied on "open the app → auto-join" for the *second*
  user now shows an explicit not-authorized state until invited (intended UX).
- More endpoints answer 404 where they previously answered 403; API consumers
  must treat 404 as "not found or not visible".
- `TaskAccessPolicy` is a new dependency of six services (small DI surface
  growth).

### Neutral
- Pre-release: no data migration needed; fresh installs only.

## Alternatives Considered

- **Keep auto-join but restrict it to instance-house endpoints** — rejected:
  removal would still not be durable, and the auto-join-on-read semantics made
  authorization state untrustworthy.
- **Per-room-task private definitions** (private task = only its assignees,
  no creator special-case) — rejected: breaks the creator's ability to manage
  their own private task and contradicts the lifecycle surface.
- **Owners see all private tasks** — rejected: strict privacy default; a
  private task is private even from the household owner.
- **Per-room-task scoping for definition lists too** — rejected: the definition
  list exists to render forms/badges; definition-level there, room-task-level
  for concrete chore lists, is the coherent split.

## References

- Plan: [`docs/plans/done/cs-plan-061-wp2-membership-visibility-privacy.md`](../plans/done/cs-plan-061-wp2-membership-visibility-privacy.md) (§3.1–§3.9)
- Supersedes parts of: [ADR-006](ADR-006-service-layer-authorization.md) (bootstrap preamble semantics)
- Implementation: `lib/Service/AuthorizationService.php`, `lib/Service/TaskAccessPolicy.php`
- Tests: `tests/integration/Controller/InviteOnlyMembershipTest.php`,
  `tests/integration/Controller/TaskAuthorizationTest.php`,
  `tests/integration/Controller/PrivateTaskAuthorizationTest.php`,
  `tests/unit/Service/TaskAccessPolicyTest.php`
