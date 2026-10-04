# Logging Standard

> **Last Updated**: 2026-08-27
> **Status**: Active

---

## Purpose

Structured, privacy-aware logging: make production issues reproducible, log the
root cause **once**, close to the failure boundary, with stable event names and
context keys — without leaking sensitive data. No external telemetry, no user
tracking, no full request payloads.

## Backend Rules

- Use `AppLogger` as the single logging facade; controller base classes handle shared request-boundary exception logging.
- **`AppLogger` is a required, non-nullable constructor dependency** (CS-PLAN-052): no `?AppLogger = null` defaults, no null-guards around log calls, no `?->`. A wiring mistake must fail loudly at construction, never degrade to permanently silent logging. Controllers using `ControllerLoggingTrait` must call `initializeControllerLogger()` from their constructor.
- Services adopt `AppLogger` when they have a real failure or best-effort-skip site (transaction rollback boundaries, expected-race swallows). Services with no failure path stay logger-free — a dead dependency is worse than none (CS-PLAN-052 survey).
- Log levels: `debug` (dev diagnostics) · `info` (state changes, lifecycle milestones) · `warning` (recoverable problems, suspicious access, partial batch failures, security-relevant membership/ownership decisions) · `error` (failed requests, swallowed/translated exceptions, data integrity) · `critical` (app-integrity-threatening failures).
- **Controllers:** unexpected exceptions at the boundary; authorization failures as `warning`-level security events. No full request bodies; no duplicated nested-layer exception logging.
- **Services:** domain mutations at `info`; partial failures at `warning`; swallowed business failures at `error`; security decisions at `warning`. Log only where you add unique business context. Transaction rollback boundaries use `logException()` before the rethrow; expected-race swallows use `warning` (idempotent outcome unchanged, but observable).

### Owner-Operation Events

Use `AppLogger::audit()` for operational owner-change events. Unlike diagnostic
methods, this path does not add a Nextcloud UID/session snapshot. Call sites use
stable event/action/outcome metadata and numeric actor/resource IDs only; no
email, names, authored text, reason text, medical details, credentials or payload.
Emit success only after the transaction has committed. A denied or rolled-back
command must not emit success; receipt replay must not emit another committed
event. Explicit replay/no-change outcomes are not new mutations.

Membership, house settings, setup, floor/room structure, checklist definitions,
planner availability, catalog and reward-request lifecycle use this contract.
`CommandService` emits the shared `house.command.committed` event for reset,
schedule application, occurrence agreements and pause commands. These events
identify the committed receipt, not its authored contents, and include self-service
commands on those same surfaces without implying owner authority.

This is operational logging, not a tamper-proof or exactly-once audit ledger.
Nextcloud operators own log level, storage, access and retention; info events
are filtered by the normal warning-level setting and are only available when
info logging is enabled. A crash after commit can lose an event. Do not claim
that the event stream alone proves a complete history of persisted changes.

## Frontend Rules

- Use `src/utils/logger.js` exclusively — **no raw `console.*`** in production code.
- `debug` dev tracing · `info` lifecycle steps · `warn` recoverable UI degradation · `error` failed API flows.
- Safe context: `store`, `action`, ids (`roomId`, `taskId`, …), `status`, `requestUrl`, `errorCode`. Avoid full form objects, invitation text, raw response bodies, full Axios responses.

## Structured Context

Stable keys when available: `event`, `controller`, `action`, `service`, `houseId`, `roomId`, `floorId`, `taskId`, `roomTaskId`, `userId`, `requestId`, `exception`.

Any key containing `password`, `token`, `secret`, `authorization`, `cookie`, `session`, `credential`, or `csrf` must be removed or redacted.

Messages: short, stable, action-oriented, English. ✅ `Task controller action failed` · ❌ `Something went wrong lol`.

## Nextcloud Runtime Configuration

`loglevel` in `config/config.php`: `2` (warning) is the shared-dev/production default; raise to `0`–`1` temporarily during focused diagnosis, then revert. (`0` debug, `1` info, `2` warning, `3` error, `4` fatal.)

## Review Checklist

- [ ] Request-boundary exceptions logged once with structured context
- [ ] Authorization failures logged as `warning`-level security events
- [ ] `AppLogger` wired as required (non-nullable) — no null-guards, no `?->`
- [ ] New rollback/race-swallow failure paths log with stable `event` + `service` keys
- [ ] No raw `console.*`, no sensitive data in logs
- [ ] Logging changes cover at least one failure path with a regression test

## References

[`security.md`](./security.md) · [`../../backlog/backlog.md`](../../backlog/backlog.md)
