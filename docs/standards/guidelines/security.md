# Security Standards

> **Last Updated**: 2026-03-05
> **Audit Status**: ✅ Passed (P8-SECURITY-001)

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

Every controller method validates house membership before data access. Two
primitives exist in `AuthorizationService` (both throw `ForbiddenException`,
caught by every controller → **403**, never 500):

- `ensureHouseMember()` — auto-creates the membership on first access (the
  `getOrCreateHouse` bootstrap/join flow). This is what the standard controller
  preamble uses:
```php
$instanceId = $this->houseService->getInstanceId();
$house = $this->houseService->getOrCreateHouse($instanceId);
$this->authService->ensureHouseMember($house->getId());
```
- `assertHouseMember()` — a pure, read-only gate (no mutation). Use it for
  ordinary authorization checks where auto-join is not intended, e.g.
  `assertUserInHouse($houseId, $targetUserId)` to reject cross-house access on
  endpoints that accept an arbitrary userId (`userTasks`/`userStats`).

> **Defense in depth (ADR-006):** authorization for a mutation is enforced
> **inside the service**, not only in the controller preamble. House-scoped
> mutators take the caller's `houseId` and validate floor-chain ownership +
> assignee membership internally, so a caller that forgets the preamble cannot
> bypass the check. Read-only checks (`requireHouseOwner` / `isHouseOwner`) use
> `assertHouseMember()` and never mutate. See
> [ADR-006](../../adr/ADR-006-service-layer-authorization.md).

### Role-Based Access Control

| Role | Permissions |
|------|-------------|
| `owner` | Full house management, user management, task management |
| `member` | Task management, viewing house data |
| `child` | Task completion, limited management |

**Owner-only operations** use `requireHouseOwner()`:
- Adding/removing house users
- Changing user roles
- Deleting floors/rooms

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
(CR-SVC-48, `TaskSyncController::syncFromNextcloud`).

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

---

## Error Handling

### Exception Catching

All controller methods wrap logic in try-catch:

```php
try {
    // Business logic
} catch (\Exception $e) {
    return new JSONResponse([
        'success' => false,
        'error' => [
            'code' => 'ERROR_CODE',
            'message' => $e->getMessage()
        ]
    ], 500);
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

## Security Audit Results (P8-SECURITY-001)

**Date**: 2026-03-05
**Status**: ✅ PASSED

### Findings

| Category | Status | Notes |
|----------|--------|-------|
| Authorization Annotations | ✅ Pass | No `@PublicPage` annotations found |
| House Membership Checks | ✅ Pass | All controllers use `ensureHouseMember`/`assertHouseMember`/`requireHouseOwner`; auth denials return 403 (CS-PLAN-011) |
| Input Validation | ✅ Pass | `VALIDATION_ERROR` responses present |
| SQL Injection Prevention | ✅ Pass | All queries use `createNamedParameter()` |
| XSS Prevention | ✅ Pass | No `v-html` directives in Vue components |
| Exception Handling | ✅ Pass | All controllers have try-catch blocks |
| CSRF Protection | ✅ Pass | SecurityMiddleware enforces via annotation (no `@NoCSRFRequired` on mutation endpoints) |

### Recommendations

1. **Consider rate limiting** for sensitive endpoints (user management)
2. **Add audit logging** for owner-level operations
3. **Document security assumptions** in API documentation

---

## Reporting Security Issues

If you discover a security vulnerability, please report it privately via GitHub Security Advisories rather than opening a public issue.
