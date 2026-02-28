# Active Tasks Registry

> **Purpose**: Central tracking of all tasks currently being worked on by AI agents or developers.
> **Update Rule**: Every agent MUST update this file when starting, completing, or pausing work.

## Currently Active

<!-- Format: ### [Agent/Developer Name] - [Task ID] - [Task Title]
**Status**: In Progress | Blocked | Review Ready
**Started**: YYYY-MM-DD HH:MM
**Last Update**: YYYY-MM-DD HH:MM
**Branch**: branch-name (if applicable)
**Related Files**: List of main files being modified
**Blockers**: Any blockers (or "None")
**Notes**: Brief progress notes
-->

### Cascade - P1-FE-001 - Dashboard View (Vue.js)
**Status**: Review Ready
**Started**: 2026-02-28 13:26
**Last Update**: 2026-02-28 13:27
**Branch**: main
**Related Files**: src/views/Dashboard.vue, src/components/Dashboard/, src/store/house.js, src/store/rooms.js
**Blockers**: None
**Notes**: Dashboard View ALREADY IMPLEMENTED. Includes: HouseHealthBar, QuickStats, FloorList with rooms. Fetches data from house and rooms stores. E2E tests exist.

---

## Recently Completed (Last 7 Days)

<!-- Moved here automatically when task status changes to "Done" or "Review Ready" -->

---

## Workflow States

- **In Progress**: Actively being worked on
- **Blocked**: Waiting for external input/decision
- **Review Ready**: Code complete, awaiting review
- **Done**: Completed and reviewed

## Agent Instructions

### When Starting a Task
1. Add entry under "Currently Active" with all required fields
2. Update task status in corresponding backlog file
3. Create/switch to feature branch if needed
4. Commit this file: `git add docs/backlog/ACTIVE_TASKS.md && git commit -m "docs: Start work on [Task ID]"`

### During Work
1. Update "Last Update" timestamp every 30-60 minutes of active work
2. Update "Notes" with significant progress
3. Commit updates periodically

### When Completing
1. Change status to "Review Ready"
2. List all modified files
3. Move entry to "Recently Completed"
4. Update corresponding backlog file
5. Commit: `git commit -m "docs: Complete [Task ID] - ready for review"`

### When Blocked
1. Change status to "Blocked"
2. Document blocker clearly
3. Notify user if human decision needed

---

**Last Updated**: 2026-02-28
