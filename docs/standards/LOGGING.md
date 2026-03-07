# Logging Standard

> **Last Updated**: 2026-03-07
> **Status**: Active

---

## Purpose

ChoreStrike uses structured, privacy-aware logging to reduce debugging time without leaking sensitive data.

This standard defines:

- Which events must be logged
- Which log level must be used
- Which context is safe to include
- How backend and frontend logging must be implemented
- How Nextcloud log level configuration should be used during development and operations

---

## Goals

- Make production issues reproducible and easier to localize
- Log the root cause once, close to the failure boundary
- Keep logs searchable with stable event names and consistent context keys
- Avoid sensitive payload logging
- Use Nextcloud-native logging for backend events
- Use a shared frontend logger instead of raw `console.*` calls

---

## Non-Goals

- No external telemetry or SaaS logging platform
- No user behavior tracking
- No storage of full request payloads, secrets, or private content
- No noisy debug logging in production by default

---

## Backend Logging Rules

### Log Levels

| Level | Use For |
|------|---------|
| `debug` | Detailed development diagnostics, algorithm branches, request flow tracing |
| `info` | Important successful state changes, lifecycle milestones, one-time compatibility notes |
| `warning` | Recoverable problems, suspicious access attempts, partial failures, skipped operations |
| `error` | Failed requests, uncaught service exceptions, data integrity problems |
| `critical` | Severe failures that threaten app integrity or widespread functionality |

### Required Backend Logging

#### Controllers

Controllers must log:

- Unexpected exceptions at the request boundary
- Validation failures only when they indicate suspicious or repeated misuse
- Owner-only and membership-related authorization failures as warning-level security events

Controllers must not:

- Log full request bodies
- Log passwords, tokens, raw checklist labels, or user-entered descriptions unless strictly necessary
- Duplicate the same exception multiple times in nested layers without adding new context

#### Services

Services must log:

- Domain-important mutations at `info` level when they help explain system state
- Partial batch failures at `warning` level
- Unexpected business logic failures at `error` level when they are swallowed or translated
- Security-relevant membership and ownership decisions at `warning` level

Services should prefer logging only where they add unique business context.

---

## Frontend Logging Rules

Frontend code must use the shared `src/utils/logger.js` helper.

### Allowed Frontend Logging

- `logger.debug()` for development-only tracing
- `logger.info()` for major lifecycle steps during development diagnostics
- `logger.warn()` for recoverable UI degradation
- `logger.error()` for failed API flows or unexpected UI/runtime failures

### Forbidden Frontend Logging

- Raw `console.log()` / `console.error()` / `console.warn()` in production code
- Logging full Axios response objects if they may contain private payloads
- Logging translated UI text as the only diagnostic signal

### Frontend Context Rules

Safe context examples:

- `store: 'rooms'`
- `action: 'fetchFloorsWithRooms'`
- `roomId`, `floorId`, `taskId`, `houseId`
- `status`, `requestUrl`, `errorCode`

Avoid logging:

- Full form objects
- Invitation text or personal notes
- Raw server response bodies unless sanitized

---

## Structured Context Rules

### Required Context Keys

When available, use these stable keys:

- `event`
- `controller`
- `action`
- `service`
- `houseId`
- `roomId`
- `floorId`
- `taskId`
- `roomTaskId`
- `userId`
- `requestId`
- `exception`

### Sensitive Keys That Must Be Redacted

Any context key containing one of the following must be removed or redacted:

- `password`
- `token`
- `secret`
- `authorization`
- `cookie`
- `session`
- `credential`
- `csrf`

### Message Style

Messages must be:

- Short
- Stable
- Action-oriented
- English only

Good examples:

- `Task controller action failed`
- `House membership auto-created for current user`
- `Bulk room task assignment skipped failed item`
- `Authorization check failed`

Bad examples:

- `Something went wrong lol`
- `Task create exploded with weird payload`

---

## Nextcloud Runtime Configuration

Nextcloud log level is configured in `config/config.php`.

```php
'log_type' => 'file',
'loglevel' => 2,
```

### Recommended Values

| Environment | `loglevel` | Meaning |
|------------|------------|---------|
| Local debugging | `0` | Debug |
| Active feature development | `1` | Info |
| Shared dev / staging | `2` | Warning |
| Production | `2` or `3` | Warning or Error |

### Level Mapping

| Nextcloud Value | Meaning |
|----------------|---------|
| `0` | Debug |
| `1` | Info |
| `2` | Warning |
| `3` | Error |
| `4` | Fatal |

Default production recommendation for ChoreStrike: `2`.

Raise to `0` or `1` temporarily during focused incident diagnosis, then revert.

---

## Review Checklist

Before merging logging-related changes, verify:

- [ ] All request-boundary exceptions are logged once with structured context
- [ ] Authorization failures are logged as warning-level security events
- [ ] No raw `console.*` was introduced in production code
- [ ] No sensitive data is written to logs
- [ ] Public logging helpers are documented
- [ ] Logging changes have regression coverage for at least one failure path

---

## Implementation Pattern

### Backend

- Use `AppLogger` as the single app-level logging facade
- Use controller base classes for shared request-boundary exception logging
- Keep service logging focused on business context and partial failures

### Frontend

- Use `src/utils/logger.js`
- Route all diagnostics through the helper to centralize environment behavior

---

## Escalation Guidance

If a problem is:

- **User-facing but recoverable**: log `warning`
- **Request-failing**: log `error`
- **Cross-cutting or data-corrupting**: log `critical`
- **Only useful during active local debugging**: log `debug`

---

## References

- `AGENTS.md`
- `docs/AGENT_GUIDELINES.md`
- `docs/standards/SECURITY.md`
- `docs/backlog/phase-9-code-quality.md`
