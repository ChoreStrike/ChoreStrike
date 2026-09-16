# Security Standards

> **Last Updated**: 2026-09-02
> **Last Audit**: 2026-03-05 (P8-SECURITY-001, passed — record in `archive/`; open recommendations tracked in the backlog)

---

## Security Architecture

ChoreStrike follows Nextcloud's app isolation model and implements defense-in-depth security practices.

### Nextcloud Isolation (Built-in Protections)

- **Own database tables**: All tables prefixed with `oc_chorestrike_*`
- **Exception handling**: Uncaught app exceptions don't crash Nextcloud
- **Route isolation**: App routes are namespaced (`/apps/chorestrike/`)
- **No direct filesystem access**: Uses Nextcloud's filesystem abstraction

---

## Authorization

### Annotation Requirements

All API endpoints use `@NoAdminRequired` annotation (requires authenticated user).

**❌ Never use `@PublicPage`** - This would bypass authentication entirely.

### House Membership Checks

House membership is **invite-only after the first owner** (ADR-010). Three
primitives exist in `AuthorizationService` (denials throw `ForbiddenException`;
the normal response is **403**, never an unexpected 500):

- `ensureHouseMember()` — **bootstrap-only** (ADR-010, decision D1): creates a
  membership ONLY while the house has zero members (that user becomes the
  owner). Once members exist, a miss is a denial (`'Not a member of this
  house'`) and NO row is created. Only the five designated bootstrap endpoints
  may call it: `user#current`, `house#get`, `user#invite`, `setup#catalog`,
  `setup#apply`:
```php
$instanceId = $this->houseService->getInstanceId();
$house = $this->houseService->getOrCreateHouse($instanceId);
// Designated bootstrap endpoint only (ADR-010, D1):
$this->authService->ensureHouseMember($house->getId());
```
- `assertHouseMember()` — the pure, read-only gate for every ordinary
  authorization check (no mutation, no auto-join). This is the standard
  controller preamble since CS-PLAN-061:
```php
$instanceId = $this->houseService->getInstanceId();
$house = $this->houseService->getOrCreateHouse($instanceId);
$houseUser = $this->authService->assertHouseMember($house->getId());
```
- `assertUserInHouse($houseId, $targetUserId)` — rejects cross-house access on
  endpoints that accept an arbitrary target user ID.

Because membership is invite-only, **removal is durable**: no ordinary read can
recreate a removed member's row; they see the not-authorized state until
re-invited.

### Denial Standard (403 vs 404)

Decision D3 (ADR-010) fixes one standard for every endpoint:

- **Visibility denials → 404**: a foreign, nonexistent, or not-visible id is
  answered with the same 404 `RESOURCE_NOT_FOUND` envelope via
  `ControllerErrorFormatter::notFoundResponse()` — anti-enumeration. This
  includes task-visibility denials from `TaskAccessPolicy` on read AND
  lifecycle/assignment paths (a private task you cannot see "does not exist").
- **Role denials → 403**: an otherwise visible resource the caller may not
  mutate (child attempting management, non-owner structure change) is a 403
  `FORBIDDEN`.

For **task visibility** the single matrix lives in
`OCA\Service\TaskAccessPolicy` (ADR-010, decision D2): `public` → all members;
`private` → creator ∪ explicit assignees (definition lists at definition
granularity, room-task lists per room task, owners get no blanket access);
`admin-only` → house owners. Never re-implement the matrix inline — delegate.

> **Defense in depth (ADR-006):** authorization for a mutation is enforced
> **inside the service**, not only in the controller preamble. House-scoped
> mutators take the caller's `houseId` and validate floor-chain ownership +
> assignee membership internally, so a caller that forgets the preamble cannot
> bypass the check. Read-only checks (`requireHouseOwner` / `isHouseOwner`) use
> `assertHouseMember()` and never mutate. See
> [ADR-006](../../adr/ADR-006-service-layer-authorization.md) and
> [ADR-010](../../adr/ADR-010-invite-only-membership-visibility-matrix.md).

### Role-Based Access Control

| Role | Permissions |
|------|-------------|
| `owner` | Full house management, user management, task management, checklist item editing, structure (floors/rooms/house) |
| `member` | Task-domain management (create/edit/delete/assign) within the visibility matrix, viewing house data |
| `child` | Execution surface only: complete/undo/due-date/checklist progress, own scheduling preferences, minigames |

The child/actor boundary (decision D5, ADR-010) is enforced in the service
layer via `AuthorizationService::assertCanManageTasks($houseUser)` — every
task-domain mutation (create/update/delete/assign) calls it; children get a
403.

**Owner-only operations** use `requireHouseOwner()`:
- Adding/removing house users
- Changing user roles
- Creating/updating/deleting floors and rooms
- Updating house settings, applying the setup wizard
- Checklist definition mutations (item add/update/delete/reorder)

---

## Input Validation

### Controller-Level Validation

All user input is validated before processing:

```php
if (empty(trim($name))) {
    return new JSONResponse([
        'success' => false,
        'error' => [
            'code' => 'VALIDATION_ERROR',
            'message' => 'Name is required'
        ]
    ], 400);
}
```

### Type Validation

PHP type hints enforce parameter types:

```php
public function create(int $floorId, string $name): JSONResponse
```

### Role Validation

Roles are validated against allowed values:

```php
if (!in_array($role, ['owner', 'member', 'child'], true)) {
    // Return validation error
}
```

### Client-Supplied Dates

Date strings from the client are parsed at the controller boundary and bounded
against the future (`now + 1h` clock-skew tolerance) before reaching a service —
malformed or far-future input returns 400 `VALIDATION_ERROR` instead of a 500
from the `DateTime` constructor or an indefinitely pushed `next_due`
(`RoomTaskController::updateDueDate`, `TaskController::create`; the former
`TaskSyncController::syncFromNextcloud` CR-SVC-48 example was pruned together
with the task-sync surface in CS-PLAN-055).

### No Mutations on GET (BUG-ROOM-001)

State changes ride POST/PUT/DELETE routes only. The historical GET-create
branches (`FloorController::index` / `RoomController::index` sniffing the
query/body for create payloads to work around a POST→GET rewrite) were
CSRF-able and died with their no-consumer index routes in CS-PLAN-055; the
`RouteMatrixTest` CS-055 guard keeps the pruned aliases (`/floor-create`,
`/rooms/create`, …) from being re-registered without a deliberate consumer
decision.

---

## SQL Injection Prevention

### Prepared Statements

All database queries use Doctrine QueryBuilder with named parameters:

```php
$qb->select('*')
   ->from($this->getTableName())
   ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
```

**❌ Never** concatenate user input into SQL strings.

---

## XSS Prevention

### Vue.js Auto-Escaping

Vue templates automatically escape interpolated values:

```vue
<!-- Safe: Auto-escaped -->
<div>{{ user.name }}</div>
```

### v-html Prohibition

**❌ Never use `v-html`** with user-provided content. No `v-html` directives exist in the codebase.

---

## CSRF Protection

Nextcloud's `SecurityMiddleware` automatically validates CSRF tokens for POST/PUT/DELETE requests unless the method carries `@NoCSRFRequired`. This is annotation-based and independent of the controller base class (`Controller` vs `OCSController`). All ChoreStrike API controllers extend `Controller` (CS-PLAN-020) and rely on the middleware's default CSRF enforcement.

### Test-Tooling Routes (CS-PLAN-068 D5)

The three test-tooling routes (`/api/v1/test/seed`, `/api/v1/test/reset`,
`/api/v1/test/profiles`) are the only **mutation-capable test API** surface
carrying `@NoCSRFRequired`; `PageController::index` also has that annotation
for the read-only page entry. These test routes intentionally support
authenticated Basic-auth fixtures without a browser CSRF token. Browser
contexts still prime and send request tokens on ordinary API mutations:

- They keep `@NoAdminRequired` (never `@PublicPage`): an authenticated
  session is mandatory even in test mode.
- They are hard-gated by `NEXTCLOUD_TEST_MODE=true` **in the web server's
  process**; unset/`false`/invalid values answer `403 NOT_TEST_MODE`
  before any mutation, so a production deployment without the env var has
  a closed endpoint even though the route is registered.
- The surface clears ChoreStrike app tables only (never Nextcloud accounts
  or other apps' data) and is plain tooling, not an app contract — it may
  change between releases without a compatibility path (pre-release rule).
- Evidence layers: `TestModeApiTest` (in-process refusal/enabled
  dispatch), `RouteMatrixTest` (annotations), and the live fixture probes
  in the Playwright setup.

---

## Error Handling

### Exception Catching

Unexpected failures are logged through `AppLogger` and translated through the
shared safe formatter; never return the original exception message:

```php
try {
    // Validate, delegate, shape the response.
} catch (ForbiddenException $e) {
    return ControllerErrorFormatter::forbiddenResponse($e);
} catch (NotFoundException $e) {
    return ControllerErrorFormatter::notFoundResponse($e);
} catch (\InvalidArgumentException $e) {
    // Use only when this endpoint's validation messages are audited as safe.
    return ControllerErrorFormatter::validation($e);
} catch (\Throwable $e) {
    $this->logControllerException('update', $e);
    return ControllerErrorFormatter::serverErrorResponse($e, 'UPDATE_FAILED');
}
```

### No Stack Traces in Production

Error responses contain only error codes and messages, not stack traces.

---

## Security Checklist

### Before Release

- [x] All endpoints use `@NoAdminRequired` (not `@PublicPage`)
- [x] All mutations check house membership
- [x] Owner-only operations check owner role
- [x] All user input validated
- [x] All database queries use prepared statements
- [x] No `v-html` with user content
- [x] All controller methods have exception handling
- [x] No hardcoded secrets or API keys

### Code Review Checklist

- [ ] New endpoints have authorization checks
- [ ] New database queries use `createNamedParameter()`
- [ ] New Vue components don't use `v-html`
- [ ] New controller methods validate input
- [ ] New services don't expose sensitive data

---

## Reporting Security Issues

If you discover a security vulnerability, follow the private Codeberg/maintainer
channel in [`SECURITY.md`](../../SECURITY.md). Never open a public issue with
sensitive details.
