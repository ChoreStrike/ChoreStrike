# Task Model and Nextcloud Sync

## Goal

Define task behavior so users can plan, complete, and track chores consistently across ChoreStrike and the Nextcloud task ecosystem.

## Task Definition

Each task includes:

- title
- description (optional)
- room or room group
- recurrence settings
- due date or next due date
- priority
- energy cost (1-5)
- reminder settings (**planned — never implemented**: the column-less
  `reminderType`/`reminderMinutesBefore` entity fields and the whole
  `task_sync` HTTP surface were pruned in CS-PLAN-055; see *Reminder
  Configuration* below)
- assignment (optional)

## Creation Model (CS-PLAN-038, atomic since CS-PLAN-063)

Every user-facing task creation path materializes a **room task** — a task
definition alone is never user-visible output. Since CS-PLAN-063 the whole
aggregate (definition, room task, initial assignees) commits or rolls back
together in **exactly one request** — `POST /room-tasks` (atomic
`TaskCreationService` transaction; no orphan definitions on failure):

1. the definition form (cascading floor→room picker — required, locked when
   created from a room's context; member-chip assignees; optional first due
   date; flex window; optional instructions; concise preview) submits
   `{roomId, task, assigneeUserIds, nextDue}` once,
2. template creation via the per-room picker (`POST
   /room-tasks/from-template`) runs the same atomic body and returns the
   same `{task, roomTask, assignees}` aggregate shape,
3. mounting an existing definition (`{roomId, taskId}`) is the third variant
   of the same endpoint.

Success means everything persisted; failure means nothing did and the form
stays open with the input preserved (no partial-success toasts). Both create
paths share one closed validation authority (service-owned), children are
gated out of the creation surface (`canManageTasks`), and catalog-template
prefill fills the form from `GET /setup/catalog` filtered by the selected
room's type. The former definition-only `POST /tasks` and the three-request
create chain were pruned (CS-PLAN-063; guarded by `RouteMatrixTest`).

Scope model: **custom tasks are always room-scoped** (the old room/floor/house
scope selector was removed — decision D1). Whole-floor/house materialization is
a template/wizard capability, not a manual-form capability. Editing a room task
from its room shows a slim modal (due date + assignees) that persists exactly
what it displays; the full definition form is for editing the definition only.
Assignment is multi-assign via member chips — there is no free-text assignee
input anywhere.

## Today View (CS-PLAN-038)

`/today` ("My Day") is the single check-things-off surface: an *Overdue*
section and a *Due Today* section with ≥44 px complete buttons. It composes
`GET /room-tasks/filtered-daily` (child-aware: weekday availability +
max-daily-tasks limits) with the overdue list, kept in a dedicated store slice
so Today items never bleed into the shared room-task lists. The default filter
is **My tasks** (assigned to me or unassigned/claimable); "Everyone" is one
tap away, and the Dashboard *Due Today*/*Overdue* cards link into the view.
Completing a recurring task moves its next due date out of today — the row
leaves the list (one-time tasks keep their row with a completed state).
Celebration feedback (confetti, sounds, toasts) fires globally via the
`chorestrike:task-completed` event.

## Reminder Configuration

> **Status (verified 2026-08-29, CS-PLAN-055):** not implemented. What existed
> was an API-only stub: the `task_sync` routes ×6 + `TaskSyncController`/
> `TaskSyncService` (completion mirror, reminder read endpoint, pending-sync
> queue, mark-synced, link) had no UI consumer and were **pruned**. The
> `Task` entity carried `reminderType`/`reminderMinutesBefore` without any
> backing columns (the only reader was `TaskSyncService`) — deleted, too.
> Re-introducing this surface needs a real CalDAV/Task interaction design and
> a consumer (FEAT-UX-021 closed as moot by the prune).

Reminder behavior must be configurable per task.

### Minimum reminder settings (design intent — unimplemented)

- no reminder
- at due time
- before due time (for example 10m, 30m, 1h, 1d)
- repeated reminder while overdue (optional)

### Sync rule (design intent — unimplemented)

Reminder fields in ChoreStrike must stay synchronized with Nextcloud Tasks-compatible reminder fields so reminders are visible on mobile task clients.

## Completion Rules

A task instance can be completed from:

1. ChoreStrike UI (dashboard, room detail, task list)
2. *(planned)* Connected task clients through Nextcloud task completion
   status — see the prune note above; there is no external-completion path
   today.

### Completion synchronization (design intent — unimplemented)

- Completing in ChoreStrike marks the linked task as completed in Nextcloud tasks.
- Completing in a synced task client marks the task as completed in ChoreStrike.
- Completion timestamps should be normalized to server time and stored with timezone-aware values.

## Conflict Handling (design intent — unimplemented)

If two clients update the same task close in time:

- prefer latest update timestamp
- keep an event log entry for audit/debug purposes
- do not silently drop completion state changes

## Mobile Behavior (design intent — unimplemented)

- Reminder and completion status must work in standard mobile task workflows.
- ChoreStrike-specific visuals are optional on mobile clients; task state consistency is mandatory.

## Open Decisions

- Exact mapping strategy to Nextcloud Tasks fields where multiple reminder formats exist.
- Whether recurring completion is generated immediately on completion or by scheduler job.
- Whether the pruned Nextcloud-sync surface is revived at all, and with which
  transport (the removal recorded in `docs/api/README.md` and the
  `RouteMatrixTest` CS-055 guard stands until a consumer exists).
