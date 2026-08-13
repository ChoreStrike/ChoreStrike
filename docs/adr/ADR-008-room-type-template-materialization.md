# ADR-008: Room-Type Template Materialization (No Floor/House Scope Columns)

**Status**: Accepted
**Date**: 2026-08-13
**Deciders**: ChoreStrike contributors

## Context

CS-PLAN-027 introduced a room-type-based cleaning template catalog and a
first-run setup wizard. Templates naturally apply at three granularity levels:

- a **single room** (e.g. clean the toilet → bathroom),
- a **whole floor** (e.g. vacuum the stairs, run the robot vacuum),
- the **whole house** (e.g. laundry, trash, mail, plants).

The question was how to model the floor/house granularity. The existing
`Task.scopeType` (`room`/`floor`/`house`) plus a `scopeId` column already
exists, but almost no query actually uses floor/house scope — every schedulable
unit is a `RoomTask` linked to a `room_id`. Adding true floor/house scope to the
wizard would have meant new scope columns, touching every house-scoped query, a
new materialization path, and new UI — a large change for a feature whose value
is onboarding speed.

## Decision

Materialize floor/house-wide chores as **ordinary rooms** via dedicated room
types, with **no schema change for scopes**:

- `hallway` room type — one `Hallway & Stairs` room per floor hosts floor-wide
  chores (robot vacuum, stairs, floor wiping).
- `household` room type — one `Household & Co.` room (ground floor, or the
  basement floor when present) hosts house-wide chores (laundry, trash, mail,
  plants, appliance maintenance).

Every task the wizard and the per-room picker create is `scopeType = room` and
is linked through `RoomTask` exactly like any other room task. A room also gains
a `room_type` column (`generic` by default) so templates can be filtered by room
type.

## Consequences

### Positive
- Zero new scope machinery: all existing room-scoped queries, the calendar
  projection, and the daily/overdue schedulers keep working unchanged.
- The wizard creates floors → rooms → tasks in a single transaction with one
  uniform code path.
- Floor/house chores are first-class rooms, so they get room cleanliness,
  assignees, checklists, and completion rewards like any other room.
- `room_type` enables template filtering and room-type-based defaults (icon,
  name) without touching the task scope model.

### Negative
- Floor/house chores are physically one room per floor / per house; renaming or
  deleting that room removes the chores. Acceptable because rooms are fully
  editable/deletable and the wizard toggles let users opt out.
- A house with many floors gets many `hallway` rooms. Bounded by the floor cap
  (6) and insignificant for a household app.

### Neutral
- `Task.scopeType` `floor`/`house` remain in the enum for the legacy scope
  mechanism (`RoomTask.scopeId`) but the v2 catalog never emits them.

## Alternatives Considered
- **True floor/house scope columns on RoomTask**: rejected — large change
  touching all house-scoped queries and the recurrence projection for marginal
  benefit; the room-based materialization covers the same UX.
- **A separate `household_chores` table**: rejected — duplicates the RoomTask
  model and the scheduling/reward logic.

## References
- CS-PLAN-027 §3 (decision D2), `HouseSetupService`, `TemplateService`
- [ADR-003: Task Scope System](ADR-003-task-scope-system.md) (the scope model
  this decision deliberately does not extend for the wizard)
