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
