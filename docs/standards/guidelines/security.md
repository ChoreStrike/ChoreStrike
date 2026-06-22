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

Every controller method validates house membership before data access:

```php
$instanceId = $this->houseService->getInstanceId();
$house = $this->houseService->getOrCreateHouse($instanceId);
$this->authService->requireHouseMember($house->getId());
```

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

Nextcloud's OCSController automatically validates CSRF tokens for POST/PUT/DELETE requests via the `@NoAdminRequired` annotation.

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
| House Membership Checks | ✅ Pass | All controllers use `requireHouseMember`/`requireHouseOwner` |
| Input Validation | ✅ Pass | `VALIDATION_ERROR` responses present |
| SQL Injection Prevention | ✅ Pass | All queries use `createNamedParameter()` |
| XSS Prevention | ✅ Pass | No `v-html` directives in Vue components |
| Exception Handling | ✅ Pass | All controllers have try-catch blocks |
| CSRF Protection | ✅ Pass | OCSController handles automatically |

### Recommendations

1. **Consider rate limiting** for sensitive endpoints (user management)
2. **Add audit logging** for owner-level operations
3. **Document security assumptions** in API documentation

---

## Reporting Security Issues

If you discover a security vulnerability, please report it privately via GitHub Security Advisories rather than opening a public issue.
