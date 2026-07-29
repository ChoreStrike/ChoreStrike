# Feature Backlog

> New features and UX improvements that are not bugs or code-quality items.

---

## [P1] Week / Month (30-day) Calendar View for Tasks

**Status**: Planned — drafted as CS-PLAN-021 (`docs/plans/new/cs-plan-021-task-calendar-view.md`, 2026-07-29; awaiting plan review + `new/ → active/`)
**Assignee**: unassigned
**Estimated Effort**: L
**Source**: User request 2026-07-27

### Description

Add a calendar-based overview showing tasks across a week or month (rolling 30 days).
The current UI only offers list views (room detail, task manager, daily list). Users
need a temporal overview to plan ahead — e.g. "which chores pile up next week?" or
"how is the month distributed?".

### Acceptance Criteria

- Toggle between week and month (30-day) view
- Tasks rendered on their `next_due` / `ideal_due_date` dates
- Recurring tasks shown on each occurrence (respecting `flex_days` window)
- Completed instances visually distinguished (checkmark / muted)
- Click a day to see its task list (reuse existing list components)
- Mobile-friendly: horizontal scroll or swipe between weeks
- Overdue tasks highlighted on their original due date
- No new backend endpoint required initially — derive from existing
  `GET /api/v1/room-tasks/daily?date=` or a new lightweight
  `GET /api/v1/room-tasks/range?from=&to=` endpoint

### Dependencies / Notes

- Builds on `RoomTaskMapper::findDailyTasks()` / `findActiveDueIdsBetweenByHouse()`
- Consider a dedicated range-query endpoint to avoid N daily round-trips
- i18n: all new strings via `t()`, update `l10n/`
- Design: align with Nextcloud design guidelines (calendar-like grid)

---

## [P2] Task-Due Push Reminders (extend existing notification system)

**Status**: Planned
**Assignee**: unassigned
**Estimated Effort**: S
**Source**: User request 2026-07-27

### Description

Extend the existing `DailyNotificationJob` / `NotificationService` infrastructure to
send "task X is due today" and "task Y is due tomorrow" push notifications via the
Nextcloud bell + mobile push. No CalDAV sync needed — the Nextcloud notification
system already delivers to the mobile app without ChoreStrike running in background.

### Acceptance Criteria

- New notification subjects: `task_due_today`, `task_due_tomorrow`
- `ChoreStrikeNotifier` renders localized subject + message with rich params (task name, room)
- `DailyNotificationJob` (or a new dedicated job) queries tasks due today/tomorrow per user
- Respects user notification preferences (opt-out per category)
- No duplicate notifications if task is already completed
- Unit tests for job + notifier rendering

### Dependencies / Notes

- Reuses `NotificationService::dispatch()` pattern (best-effort, never breaks task flow)
- `RoomTaskMapper::findDailyTasks()` / `findDueTodayByHouse()` already exist
- Lower priority than calendar view but high user value for mobile engagement
