# Task Model and Nextcloud Sync

## Goal

Define task behavior so users can plan, complete, and track chores consistently across ChoreStrike and the Nextcloud task ecosystem.

## Task Definition

Each task includes:

- title
- description (optional)
- scope (`room`, `garden`, `home`, `outside`, `work`)
- scope label (optional, for custom naming)
- recurrence settings
- due date or next due date
- priority
- energy cost (1-5)
- reminder settings
- assignment (optional)
- voluntary flag (optional)

## Scope Model

Tasks are not limited to rooms.

- `room`: classic room-bound task (for example kitchen cleanup)
- `garden`: outdoor area maintenance (for example mowing lawn)
- `home`: whole-home tasks (for example snow shoveling at the entrance)
- `outside`: external errands (for example shopping)
- `work`: optional work-related productivity tasks

The scope type defines where the task belongs conceptually.
The scope label allows household-specific naming without changing the base type.

## Recurrence and Lifecycle Modes

Recurrence behavior is split into two dimensions:

1. **Frequency** (`daily`, `weekly`, `monthly`, `custom`) + value
2. **Lifecycle mode** (`one_time`, `recurring`)

### Lifecycle mode behavior

- `one_time`: completing the task deactivates the assignment.
- `recurring`: completing the task schedules the next due date based on frequency.

### Time-bounded recurring tasks

Recurring tasks can optionally define an active window:

- `active_from` (optional start)
- `active_until` (optional end)

If the next calculated due date exceeds `active_until`, the task assignment is deactivated.

### Infinite recurring tasks

Recurring tasks with no `active_until` are treated as infinite by default.

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
