# Project Backlog

This directory contains the project backlog and task tracking for ChoreStrike.

## Purpose

The backlog tracks:
- **Active tasks** (currently being worked on) → `ACTIVE_TASKS.md`
- Planned features (by phase)
- Known bugs
- Technical debt
- Future enhancements

## Structure

```
backlog/
├── README.md (this file)
├── ACTIVE_TASKS.md          ← LIVE tracking (update constantly!)
├── bugs.md                  ← Bug backlog
├── phase-1-core-tasks.md    ← Phase 1 features
├── phase-2-gamification.md  ← Phase 2 features
└── ...                      ← Additional phase files
```

## Workflow

### For Coding Agents
1. **Before starting:** Check `ACTIVE_TASKS.md` for conflicts
2. **When starting:** Add task to `ACTIVE_TASKS.md` with "In Progress" status
3. **During work:** Update `ACTIVE_TASKS.md` every 30-60 min
4. **When complete:** Change status to "Review Ready", list modified files
5. **Update backlog:** Mark task done in phase/bug file

### For Review Agents
1. **Check:** `ACTIVE_TASKS.md` for "Review Ready" tasks
2. **Review:** Follow `docs/engineering/review-playbook.md` together with the active review rules in `AGENTS.md`
3. **Fix minor issues:** Immediately (< 10 lines)
4. **Ask user:** For unclear issues
5. **Move to backlog:** For major refactors
6. **Complete:** Move to "Recently Completed" in `ACTIVE_TASKS.md`

See `AGENTS.md` and `docs/engineering/review-playbook.md` for the active workflow details.

## Backlog Item Format

Each item should include:

```markdown
## [PRIORITY] Feature/Bug Title

**Status**: [Planned | In Progress | Blocked | Done]  
**Assignee**: [Name or unassigned]  
**Estimated Effort**: [S/M/L/XL]  
**Phase**: [1-8 or Future]

### Description
What needs to be done?

### Acceptance Criteria
- [ ] Criterion 1
- [ ] Criterion 2
- [ ] Criterion 3

### Dependencies
- Depends on: [Other tasks]
- Blocks: [Other tasks]

### Notes
Additional context, links, etc.
```

## Priority Levels

- **P0 (Critical)**: Blocking, must be done immediately
- **P1 (High)**: Important for current phase
- **P2 (Medium)**: Should have
- **P3 (Low)**: Nice to have
- **P4 (Future)**: Deferred to later phases

## Current Status

See [roadmap.md](../release/roadmap.md) for the overall project roadmap.

Currently in **Phase 0: Foundation**

---

**Note**: Keep backlog items updated. Move completed items to CHANGELOG.md.
