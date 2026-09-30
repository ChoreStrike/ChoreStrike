# Notification Pipeline (Digest Delivery)

> How ChoreStrike notifies — the one bounded, opt-in daily summary.
> Decision record: [ADR-016](../adr/ADR-016-opt-in-digest-notifications.md).
> Product intent: [concepts/notifications.md](../concepts/notifications.md).

## The Model in One Paragraph

ChoreStrike sends at most **one Nextcloud notification per person per
house-local day** — the daily digest. It combines two opt-in categories:
the **task summary** (your planned/agreed-catch-up work for today) and
**organizational updates** (takeovers, agreement recordings, pause
decisions). Awards, titles, streaks and in-app state never dispatch
anything external. There are no per-event bells.

## The Delivery Schedule

- `member_prefs.digest_time` (`HH:mm`, default `18:00`) is resolved in
  the house timezone via `CalendarService::localTimeToInstant()` with
  the DST rules of ADR-009 (spring gap → first valid later instant;
  autumn fold → first occurrence).
- The dispatch window is `min(scheduled + 60 elapsed minutes, local day
  end)` — cron lateness inside the window is absorbed; a missed slot
  expires permanently (never caught up the next day).
- Slots live in `chorestrike_digest_slots` with the unique
  (house, membership, local_day) key — the **daily budget identity**.
  They are materialized for opted-in members covering today/tomorrow,
  independently of the opt-in toggles: a toggle cycle never creates a
  new allowance, and opt-out never deletes history (the zone-change
  spacing rule reads it).
- A pending slot whose pref revision or zone drifted re-resolves.
- Materialization, re-resolution, expiry and claims share the house-first
  lock. A due-scan snapshot is never the authority: claim-time rereads verify
  the current schedule/window/day and preferences. Retained different-zone
  attempts impose the 24-elapsed-hour floor on new slots as well as revised
  ones; ordinary same-zone DST days keep the chosen wall time.

## The Dispatch Pass (Five Minutes)

`DailyNotificationJob` → `DigestService::processDueSlots(now)`:

1. **Materialize** due/upcoming slots (`DigestScheduleService`).
2. **Expire** slots whose window ended (`status → skipped`) together
   with their queued rows (`disposition → expired`), plus queued rows of
   fully elapsed days without a slot.
3. **Dispatch** one due slot at a time:
   - Recheck inside ONE house-first transaction: membership active,
     category opt-ins still enabled, outside personal quiet hours, and
     dispatch-time relevance (task content computed now from occurrence
     reads; org rows from the queue). Empty content → `skipped` — no
     empty summary is ever sent.
   - **Claim**: conditional `pending → dispatching` write with a token.
   - Commit. Only then call `IManager::notify` (persist-before-notify).
   - Outcome: accepted → `sent` + queued rows `consumed`; any throwable
     → `uncertain`. Uncertain/dispatching-after-crash slots are
     **consumed and never resent**.
- Pre-claim failures leave the slot pending (retry inside the window).

## The Organizational Queue

`chorestrike_digest_changes` holds identity rows only — object type
(`takeover`/`agreement`/`pause`), object id, revision, category, source
time, target slot, disposition. Writers run INSIDE their existing source
transaction (the house lock is already held):

- `CompletionService` — overdue takeover (RF-076-02: the queue write
  joins the completion transaction; a rollback leaves no queued change).
- `AgreementService` — amend/catch-up/restart recordings, recipients =
  affected consent subjects minus the actor.
- `PauseService` — acknowledge/reject/shorten/retrospective
  recognition/household pause; personal pauses notify the subject,
  household pauses every member.

The unique (membership, object_type, object_id, target_slot) key
coalesces retries; the cutoff rule sends changes at/after the slot's
resolved instant to the NEXT slot, even before cron first materializes it.
Takeover rows reference the immutable completion identity, not a task name or
a reusable room-task identity. Dispatch resolves current source facts, drops
undone/deleted/superseded sources, applies the existing task visibility policy,
and restricts personal pause facts to their recipient. No source name or reason
is rendered in the notification.

## Quiet Hours

`quiet_start`/`quiet_end` (`HH:mm`, may cross midnight; equal values =
full-day quiet) gate the dispatch attempt only — slots are never
shifted. A slot whose whole window sits inside quiet hours expires like
any missed slot.

## Retirement Notes (Pre-Release)

- `TaskDueReminderService` is deleted (archived under
  `archive/2026-09/cs-plan-076-retired/` together with the retired
  release-notifications E2E spec).
- The former subjects (`task_due_today`, `medal_awarded`,
  `weekly_goal_reached`, `title_unlocked`, `overdue_taken_over`) are
  removed from `NotificationService` and `ChoreStrikeNotifier`;
  negative pins in the unit suites keep them out.
- `chorestrike:trigger-jobs daily-notification` triggers the same
  pass the cron job runs; `--as-of` accepts `YYYY-MM-DD[ HH:MM[:SS]]`.
