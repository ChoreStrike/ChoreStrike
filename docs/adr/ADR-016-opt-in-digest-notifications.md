# ADR-016: Opt-in Daily Digest as the Only Notification Path

**Status**: Accepted
**Date**: 2026-09-29
**Deciders**: ChoreStrike contributors (CS-PLAN-070 WP5, decisions C5a–C5m/D9, Q-076-01/02/03)
**Implements**: CS-PLAN-070 WP5 (Preferences & Digest Delivery)
**Supersedes**: the per-event notification model (task-due reminders, medal/weekly-goal/title bells, overdue-takeover bell — retired with their callers)

## Context

Before WP5 the app dispatched a growing set of per-event Nextcloud
notifications: task-due reminders (a daily job pass), medal awards,
weekly-goal household celebrations, title unlocks and overdue-takeover
alerts. Each was a pressure surface and each needed its own dedup,
throttling and i18n story. The agreed product direction (C5 decision log,
D9) instead wants **one bounded, opt-in daily summary per person** — the
*daily digest* — with everything else staying in-app, and personal
notification preferences as a self-only server-side surface (children
included).

Two hard constraints shaped the design: the **at-most-once doctrine**
(parent §3.6 — never hold a DB transaction across `IManager::notify`;
uncertain outcomes are consumed, never resent) already proven by the
completion aggregate, and **timezone/DST correctness** (ADR-009) for the
delivery schedule.

## Decision

1. **One notification subject, ever.** `NotificationService` carries
   exactly one subject — `digest` — rendered count-based by
   `ChoreStrikeNotifier` (no task names, no object references that could
   leak hidden work). Every former pressure subject is retired WITH its
   dispatch caller (removal, not repair; Q-076-02).
2. **The daily budget is a database identity, not a toggle.**
   `chorestrike_digest_slots` holds at most one slot per member and
   house-local day (unique key), created independent of the opt-in
   toggles so a toggle cycle never mints a new allowance. Slots are
   scheduled at the member's `digest_time` in the house zone with the
   DST rules of ADR-009 (`CalendarService::localTimeToInstant`); the
   dispatch window is the earlier of scheduled + 60 elapsed minutes and
   the local day end (C5c) — lateness is absorbed, never compensated by
   next-day catch-up.
3. **At-most-once dispatch.** The five-minute pass materializes due
   slots, expires missed ones permanently, then claims ONE slot via a
   conditional `pending → dispatching` write under the house-first lock,
   commits the claim BEFORE `IManager::notify`, and marks the slot
   `sent` only on an accepted send. Every uncertain result — exception,
   timeout, crash after the claim — leaves the slot `uncertain` (or
   `dispatching` after a hard crash) and is never resent (parent §3.6).
4. **Quiet hours gate, never shift.** A personal cross-midnight
   `HH:mm` range suppresses the attempt while it lasts (C5c); the slot
   itself stays pending inside its window and expires with it.
5. **The organizational queue is identity rows only.**
   `chorestrike_digest_changes` coalesces per (membership, object type,
   object id, target slot): takeovers (inside the completion
   transaction, RF-076-02), agreement recordings and pause decisions
   (inside their command transactions) write receipts — never rendered
   payloads or free text. Rights and relevance are rechecked at
   dispatch; rows carry only recipients authorized at write time; a
   missed slot expires its rows permanently.
6. **Preferences are self-only and versioned.** One `member_prefs` row
   per membership carries the delivery block AND the ranking-consent
   block (Q-076-03). `/preferences/me` is the only surface — there is
   deliberately no owner read/write of another member's preferences.
   Writes are receipt-protected commands validating the whole payload
   before any side effect; the shared `version` bumps on every write and
   a stale `expectedVersion` answers 409 with the client draft preserved
   (Q-076-01 Option A). A first save creates the row WITHOUT ranking
   consent (RF-076-01); withdraw and house-ranking disable reset only
   the consent columns.
7. **Zone changes re-resolve under a spacing rule.** A house timezone
   edit re-resolves pending slots in the new zone inside the same
   house-first transaction; a prior dispatch (including an uncertain
   one) blocks any further zone-switched dispatch for 24 elapsed hours,
   derived from retained slot history (C5k).
8. **Retirement of the dedicated reminder pipeline.**
   `TaskDueReminderService` is deleted (archived under
   `archive/2026-09/cs-plan-076-retired/`); `DailyNotificationJob`
   becomes the five-minute digest pass; `chorestrike:trigger-jobs
   daily-notification` triggers the SAME pass with an `--as-of`
   instant seam (day or `YYYY-MM-DD HH:MM[:SS]`).

## Consequences

### Positive
- One bounded, predictable notification per person per day — no pressure
  surfaces; parents cannot be bell-spammed on a child's behalf and vice
  versa.
- The at-most-once guarantee is enforced by a database identity plus a
  conditional claim, verified by a two-connection concurrency test.
- DST correctness rides on the audited ADR-009 primitives; gaps resolve
  to the first valid later instant, folds to the first occurrence.
- The self-only preferences surface removes an entire class of
  authorization questions (no foreign preference reads/writes).

### Negative
- The digest is **summary-only**: no per-event push exists anymore. A
  future "urgent" channel would be a new ADR.
- A crashed worker consumes the day's slot without delivery — accepted
  (preferred over duplicate pressure); the `uncertain` status makes this
  visible in diagnostics.
- Zone-change spacing can delay a member's delivery by up to 24 hours
  after a house timezone edit (C5k) — accepted as the privacy-fair
  default.

## Alternatives Considered
- **Per-event notifications with per-user throttling**: rejected — N
  dedup stories instead of one identity, and the pressure model contradicts
  D9.
- **Client-side scheduling (browser notifications)**: rejected — the
  server is the single authority; children's devices cannot be trusted
  to schedule household pushes, and background tabs are unreliable.
- **Deleting slot history on opt-out**: rejected — the 24-elapsed-hour
  spacing rule (C5k) needs retained history to survive toggle cycles.

## References
- Plan: `docs/plans/done/cs-plan-076-wp5-preferences-and-digest-delivery.md`
- `docs/engineering/notifications.md` — the pipeline in detail
- `docs/api/notifications.md` — the digest + preferences API contract
- `docs/concepts/notifications.md` — product intent
- ADR-009 (per-house timezone), ADR-011 (at-most-once aggregate precedent),
  ADR-013 (occurrence agreements), ADR-015 (consent & preference rows)
