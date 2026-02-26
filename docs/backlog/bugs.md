# Bug Backlog

## [P0] Frontend/Backend app ID mismatch breaks API and asset loading

**Status**: Planned  
**Assignee**: Unassigned  
**Estimated Effort**: M  
**Phase**: 1

### Description
The frontend uses `chorewars` in API paths and router base URLs, while the app metadata and backend controllers use `chorestrike`.
This causes 404 errors and broken app bootstrap behavior.

### Acceptance Criteria
- [ ] All frontend API calls use the same app ID as backend routes.
- [ ] Template script/style loading uses the same app ID as `PageController` and built asset names.
- [ ] Router base path uses the canonical app ID.
- [ ] A smoke test verifies dashboard load and one API call succeeds.

### Notes
- See `appinfo/info.xml`, `templates/main.php`, `src/main.js`, `src/router.js`, and all stores under `src/store/`.

---

## [P0] Missing route definitions for many implemented endpoints

**Status**: Planned  
**Assignee**: Unassigned  
**Estimated Effort**: M  
**Phase**: 1

### Description
Multiple controller methods are implemented and consumed by frontend stores, but missing in `appinfo/routes.php`.
This produces unreachable functionality and can mask authorization gaps.

### Acceptance Criteria
- [ ] Every frontend-consumed endpoint has a corresponding route.
- [ ] Every public controller method intended for API use is routed or removed.
- [ ] Route coverage test validates expected method + path matrix.

### Notes
- Missing groups include scheduling, task sync, advanced room task actions, task search/category endpoints, floor/room helper endpoints, and current user endpoint.

---

## [P0] Potential IDOR on room-task and task resource IDs

**Status**: Planned  
**Assignee**: Unassigned  
**Estimated Effort**: L  
**Phase**: 1

### Description
Several controller methods authorize only house membership of the current user but then operate on arbitrary resource IDs without verifying resource-to-house ownership.
An attacker with valid membership could potentially access or modify resources outside their house by ID guessing.

### Acceptance Criteria
- [ ] Every ID-based endpoint validates resource house ownership before action.
- [ ] Authorization checks are centralized in service methods for reuse.
- [ ] Regression tests cover cross-house access denial (read/write/delete/complete).

### Notes
- High-risk areas: `TaskController`, `RoomTaskController`, and user-targeting completion flows.

---

## [P1] User impersonation vector in completion endpoints

**Status**: Planned  
**Assignee**: Unassigned  
**Estimated Effort**: M  
**Phase**: 1

### Description
Completion APIs accept `userId` from client request and pass it into scoring logic.
Without strict validation, a member can submit completions for another user.

### Acceptance Criteria
- [ ] Completion uses authenticated user by default.
- [ ] Any delegated completion requires explicit owner/admin authorization and audit trail.
- [ ] Regression tests verify members cannot submit completions for other users.

### Notes
- Affects `RoomController::completeTask` and `RoomTaskController::complete` flows.

---

## [P1] Internal exception messages are returned to API clients

**Status**: Planned  
**Assignee**: Unassigned  
**Estimated Effort**: S  
**Phase**: 1

### Description
Controllers frequently return raw exception messages in JSON errors.
This leaks internal details and makes reconnaissance easier.

### Acceptance Criteria
- [ ] API responses return stable, user-safe error messages.
- [ ] Internal details are logged server-side only.
- [ ] Error-code mapping exists for common domain failures.

### Notes
- Broadly present across controller layer.

---

## [P1] Authorization integration tests are outdated and not aligned with controller behavior

**Status**: Planned  
**Assignee**: Unassigned  
**Estimated Effort**: M  
**Phase**: 1

### Description
Current authorization integration tests expect exceptions where controllers now return JSON responses.
Some expected exception types/messages no longer match service behavior.

### Acceptance Criteria
- [ ] Tests assert response code/payload semantics for controllers.
- [ ] Service-level tests assert exact exception classes/messages where relevant.
- [ ] Security regression tests are added for IDOR and impersonation paths.

### Notes
- See `tests/integration/Controller/AuthorizationIntegrationTest.php`.
