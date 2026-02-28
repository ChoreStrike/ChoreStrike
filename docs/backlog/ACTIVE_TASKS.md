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

*No active tasks*

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
