# Logging Standard

> **Last Updated**: 2026-08-20
> **Status**: Active

---

## Purpose

Structured, privacy-aware logging: make production issues reproducible, log the
root cause **once**, close to the failure boundary, with stable event names and
context keys — without leaking sensitive data. No external telemetry, no user
tracking, no full request payloads.

## Backend Rules

- Use `AppLogger` as the single logging facade; controller base classes handle shared request-boundary exception logging.
- Log levels: `debug` (dev diagnostics) · `info` (state changes, lifecycle milestones) · `warning` (recoverable problems, suspicious access, partial batch failures, security-relevant membership/ownership decisions) · `error` (failed requests, swallowed/translated exceptions, data integrity) · `critical` (app-integrity-threatening failures).
- **Controllers:** unexpected exceptions at the boundary; authorization failures as `warning`-level security events. No full request bodies; no duplicated nested-layer exception logging.
- **Services:** domain mutations at `info`; partial failures at `warning`; swallowed business failures at `error`; security decisions at `warning`. Log only where you add unique business context.

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
- [ ] No raw `console.*`, no sensitive data in logs
- [ ] Logging changes cover at least one failure path with a regression test

## References

[`security.md`](./security.md) · [`../../backlog/backlog.md`](../../backlog/backlog.md)
