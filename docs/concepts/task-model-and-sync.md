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
- reminder settings
- assignment (optional)

## Creation Model (CS-PLAN-038)

Every user-facing task creation path materializes a **room task** — a task
definition alone is never user-visible output. The unified flow is:

1. definition (`POST /tasks`) — includes a **required room** (cascading
   floor→room picker; locked when created from a room's context) and optional
   member-chip assignees,
2. room task (`POST /room-tasks` with `{roomId, taskId}`),
3. assignees (`PUT /room-tasks/{id}/assignees`).

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

Reminder behavior must be configurable per task.

### Minimum reminder settings

- no reminder
- at due time
- before due time (for example 10m, 30m, 1h, 1d)
- repeated reminder while overdue (optional)

### Sync rule

Reminder fields in ChoreStrike must stay synchronized with Nextcloud Tasks-compatible reminder fields so reminders are visible on mobile task clients.

## Completion Rules

A task instance can be completed from:

1. ChoreStrike UI (dashboard, room detail, task list)
2. Connected task clients through Nextcloud task completion status

### Completion synchronization

- Completing in ChoreStrike marks the linked task as completed in Nextcloud tasks.
- Completing in a synced task client marks the task as completed in ChoreStrike.
- Completion timestamps should be normalized to server time and stored with timezone-aware values.

## Conflict Handling

If two clients update the same task close in time:

- prefer latest update timestamp
- keep an event log entry for audit/debug purposes
- do not silently drop completion state changes

## Mobile Behavior

- Reminder and completion status must work in standard mobile task workflows.
- ChoreStrike-specific visuals are optional on mobile clients; task state consistency is mandatory.

## Open Decisions

- Exact mapping strategy to Nextcloud Tasks fields where multiple reminder formats exist.
- Whether recurring completion is generated immediately on completion or by scheduler job.
