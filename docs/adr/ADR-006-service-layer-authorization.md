# ADR-006: Service-Layer Authorization (Defense in Depth)

**Status**: Accepted (bootstrap-preamble semantics superseded by [ADR-010](ADR-010-invite-only-membership-visibility-matrix.md), 2026-09-03)  
**Date**: 2026-08-12  
**Deciders**: Development Team  
**Source**: [Full Review of `lib/Service`](../backlog/archive/CODE_REVIEW_2026-08-04.md) (CR-SVC-01, CR-SVC-06) · landed by [CS-PLAN-025](../plans/done/cs-plan-025-service-security-concurrency-cleanup.md)

> **Update (2026-09-03, ADR-010):** the auto-join semantics of
> `ensureHouseMember()` described below were superseded — membership is
> **invite-only after the first owner**. `ensureHouseMember()` is now the
> bootstrap-only primitive (auto-creates ONLY while the house has zero
> members), and exactly five designated bootstrap endpoints
> (`user#current`, `house#get`, `user#invite`, `setup#catalog`, `setup#apply`)
> call it; every other endpoint uses the pure `assertHouseMember()`. The
> defense-in-depth principle of this ADR — authorization enforced inside the
> service — is unchanged and was extended by the unified task-visibility
> policy (`TaskAccessPolicy`) and the actor policy
> (`assertCanManageTasks`). See ADR-010 for the current model.

## Context

ChoreStrike's thin-controller convention means controllers run the standard
authorization preamble (`getOrCreateHouse` + `ensureHouseMember`) before calling a
service. Historically that controller preamble was the **only** place house-scoping
was enforced. The 2026-08-04 Full Review found two defects that this produced:

- **CR-SVC-01 (P0 IDOR).** `RoomTaskService::createRoomTask(...)` trusted the
  controller to have validated that the target room belongs to the caller's house.
  Any caller that forgot the preamble — or called the service from a new path — could
  create a task in **another house's room** and stamp an arbitrary non-member as the
  assignee. Controller-only gating is fragile: the security boundary lives one
  forgotten preamble away from a cross-house write.
- **CR-SVC-06 (P1 mutating read-only check).** `requireHouseOwner()` /
  `isHouseOwner()` were implemented on top of `ensureHouseMember()`, which
  **auto-creates** a `house_users` membership on a miss. An authorization *check*
  therefore had a persistent side effect — a denied owner request could leave a
  phantom membership row behind.

`AGENTS.md` already states "Authorization … Validate house membership / ownership on
every mutation. Never trust client input." but did not say **where** the authoritative
check must live.

## Decision

**Authorization for a mutation is enforced inside the service, not only in the
controller.** The service is the authoritative security boundary; the controller
preamble remains as the first line of defense but is no longer the sole gate.

1. **House-scoped mutations take the caller's `houseId` and validate internally.**
   `createRoomTask(...)` now accepts the caller's `int $houseId`, resolves the target
   room's house through the floor chain (`room → floor → house`), and rejects
   (`NotFoundException`) when it differs from `$houseId`. A non-null
   `$assignedToUserId` is asserted to be a member of `$houseId`
   (`HouseUserMapper::findByHouseAndUser(...) !== null`, else
   `InvalidArgumentException`). The single live caller (`RoomTaskController::create`)
   passes `$house->getId()`.
2. **Read-only authorization checks must not mutate.** `requireHouseOwner()` /
   `isHouseOwner()` use the read-only `assertHouseMember()` (`AuthorizationService`),
   never `ensureHouseMember()`. A denied check leaves no row behind. Membership is
   still ensured upstream (the `getOrCreateHouse` + `ensureHouseMember` bootstrap) so
   the happy path is unchanged; only the phantom-row side effect is removed.

   > Accepted tradeoff (CR-SVC-47): the five owner-only endpoints that bootstrap a
   > fresh install (`getOrCreateHouse` + `requireHouseOwner`) call `ensureHouseMember()`
   > **before** the owner check so the first user becomes owner regardless of which
   > endpoint is reached first. `requireHouseOwner` itself stays read-only.

## Consequences

### Positive
- A new caller that forgets the controller preamble cannot bypass authorization —
  the service rejects cross-house input regardless of entry point.
- Matches and operationalizes `AGENTS.md` ("Never trust client input"; "Validate
  house membership / ownership on every mutation").
- Owner checks are pure: a denial has no persistence side effect.

### Negative
- Services need the mappers / `houseId` to validate (small extra dependency surface).
- The membership concept appears in two layers (controller preamble + service check).
  This is intentional defense in depth, not duplication to be removed.

### Neutral
- The same pattern has since been applied to further mutators:
  CR-SVC-09 (`RoomTaskService::assignTask`/`bulkAssignTasks` take `houseId`
  and validate ownership + assignee membership internally) and CR-SVC-10
  (`RoomService::completeRoomTask` resolves membership via the nullable
  `findByHouseAndUser()` and re-checks it **inside** the transaction, right
  after `lockForUpdate`) — both landed by [CS-PLAN-029](../plans/done/cs-plan-029-service-hardening.md).
  Remaining unscoped mutators are tracked as CR-SVC-21/22 in
  [`backlog.md`](../backlog/backlog.md).

## References

- [`docs/standards/guidelines/security.md`](../standards/guidelines/security.md) —
  authorization primitives (`ensureHouseMember` / `assertHouseMember` /
  `requireHouseOwner`).
- [`docs/plans/done/cs-plan-025-service-security-concurrency-cleanup.md`](../plans/done/cs-plan-025-service-security-concurrency-cleanup.md) — implementation record.
