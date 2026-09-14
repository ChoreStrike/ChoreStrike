# ADR-003: Task Scope System (Beyond Rooms)

**Status**: Accepted (historical) — see the scope annotation below
**Date**: 2026-02-24
**Deciders**: Development Team

> **Current-scope annotation (CS-PLAN-068, 2026-09).** This decision is
> preserved as history. The shipped system does **not** use the five-value
> scope model: the current model is the three-level
> **`room` → `floor` → `house`** hierarchy under the invite-only membership
> and visibility rules of
> [ADR-010](ADR-010-invite-only-membership-visibility-matrix.md) — every
> task materialization is a room task (`chorestrike_room_tasks.room_id` →
> `chorestrike_rooms` → `chorestrike_floors` → `chorestrike_houses`), and
> `scope_id` remained a reserved column that never grew scope entities. A
> `scope_type` column with the five values below never shipped;
> garden/outside/work tasks are modeled as ordinary rooms on a floor today.
> See
> [`docs/concepts/task-model-and-sync.md`](../concepts/task-model-and-sync.md)
> for the current contract.

## Context

Initially, ChoreStrike was designed with tasks strictly tied to rooms. However, real-world household tasks often don't fit neatly into room boundaries. Tasks like "mow the lawn," "take out trash," "grocery shopping," or "car maintenance" need a home in the system but don't belong to a specific room.

## Decision

We will implement a **flexible task scope system** with five scope types:
1. **room** - Traditional room-based tasks (default)
2. **garden** - Outdoor/garden tasks
3. **home** - Whole-house tasks (e.g., "pay bills")
4. **outside** - Tasks outside the property (e.g., "grocery shopping")
5. **work** - Work-related tasks if users want to track them

Tasks have a `scope_type` field and an optional `scope_label` for custom naming (e.g., "Front Garden" vs "Back Garden").

## Consequences

### Positive

- **Real-World Flexibility**: Accommodates all types of household tasks, not just room cleaning
- **User Freedom**: Users can organize tasks in ways that make sense for their household
- **Future Extensibility**: Easy to add new scope types if needed
- **Backward Compatible**: Existing room-based tasks continue to work (default scope is "room")
- **Better Task Organization**: Tasks can be grouped logically beyond physical room boundaries

### Negative

- **Increased Complexity**: More fields in the database and UI
- **UI Challenges**: Need to design interfaces that work for both room-based and non-room tasks
- **Migration Consideration**: Existing data needs scope_type defaulted to "room"
- **Validation Complexity**: Different scopes may have different validation rules

### Neutral

- **Room-Task Relationship**: `room_tasks` table still exists but `room_id` may be a placeholder for non-room scopes
- **Scope ID Field**: Added `scope_id` to `room_tasks` for future extensibility (e.g., linking to garden entities)

## Implementation Details

### Database Schema
```sql
-- In chorestrike_tasks table
scope_type VARCHAR(20) DEFAULT 'room'
scope_label VARCHAR(255) NULL

-- In chorestrike_room_tasks table
scope_id BIGINT NULL  -- For future use with non-room scope entities
```

### Scope Types Enum
```php
const SCOPE_TYPES = ['room', 'garden', 'home', 'outside', 'work'];
```

### Validation
- `scope_type` must be one of the defined types
- `scope_label` is optional but recommended for non-room scopes
- Room-based tasks (`scope_type='room'`) must have a valid `room_id`
- Non-room tasks can have `room_id` point to a placeholder or null

## Alternatives Considered

### Strict Room-Only Model
- **Pros**: Simpler database schema, easier UI
- **Cons**: Cannot handle real-world tasks that don't fit in rooms, forces users into artificial constraints
- **Rejected**: Too limiting for actual household task management

### Separate Tables for Each Scope
- **Pros**: Clean separation of concerns
- **Cons**: Massive duplication of code, harder to query across all tasks, more complex migrations
- **Rejected**: Over-engineering for the problem at hand

### Tags/Categories Only
- **Pros**: Very flexible
- **Cons**: No structured organization, harder to enforce business rules, poor UX for filtering
- **Rejected**: Too unstructured, doesn't solve the core problem

## Future Considerations

- **Scope Entities**: May add dedicated tables for gardens, vehicles, etc. in the future
- **Custom Scopes**: Could allow users to define their own scope types
- **Scope-Specific Features**: Different scopes might have different gamification rules

## References

- Database Migration: `lib/Migration/Version1000Date20260224000000.php`
- Task Entity: `lib/Db/Task.php`
- `TASK_SCOPES.md`: the planned concept companion was never created; the
  current task model contract lives in
  [`docs/concepts/task-model-and-sync.md`](../concepts/task-model-and-sync.md)
