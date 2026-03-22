# Alpha Test Results - 2026-03-22

## Test Environment
- **Profile**: Family (6 users, 3 floors, 6 rooms, 12 tasks)
- **Docker**: `docker-compose.test.yml` with `TEST_DB_PROFILE=family`
- **Nextcloud**: 31.0.14.1
- **ChoreStrike**: Latest from master

## Test Summary

| Test Case | Status | Notes |
|-----------|--------|-------|
| Docker startup with family profile | ✅ PASS | Container started successfully |
| Test data seeding (family) | ✅ PASS | 6 users, 3 floors, 6 rooms, 12 tasks created |
| Mom (owner) login | ✅ PASS | Login successful |
| Mom sees all tasks | ✅ PASS | 12 tasks visible |
| Teen (child) login | ✅ PASS | Login successful |
| Teen sees filtered tasks | ❌ FAIL | Shows all 12 tasks (should filter by availability) |
| Kid (child, 2-task limit) login | ✅ PASS | Login successful |
| Kid sees max 2 tasks | ❌ FAIL | Shows all 12 tasks (should limit to 2) |

## Bugs Found

### BUG-001: Migration columns missing from database
**Severity**: Critical
**Description**: The migration `Version1018Date20260321000000` was registered but the schema changes were not applied. The columns `task_tier` and `flex_days` were missing from `oc_chorestrike_tasks`, and `ideal_due_date` was missing from `oc_chorestrike_room_tasks`.
**Root Cause**: Migration registered in `oc_migrations` but `preSchemaChange` changes not executed.
**Fix Applied**: Manual ALTER TABLE statements to add missing columns.
**Status**: Fixed (manual workaround)

### BUG-002: Child role task visibility not filtered
**Severity**: High
**Description**: Users with role `child` see all tasks instead of filtered view based on:
  - `weekdayAvailability` (should only see tasks on available days)
  - `maxDailyTasks` (kid should see max 2 tasks)
**Expected**: Teen (weekend-only) should see tasks only for Sat/Sun. Kid (maxDailyTasks=2) should see max 2 tasks.
**Actual**: Both see all 12 tasks.
**Status**: Open - requires backend/frontend filter implementation

### BUG-003: CSRF check failed on direct logout navigation
**Severity**: Low
**Description**: Direct navigation to `/logout` causes CSRF error.
**Workaround**: Use settings menu logout link, or clear localStorage/sessionStorage and navigate to login.
**Status**: Known Nextcloud behavior

## Test Data Verified

### Users Created
| User | Role | maxDailyTasks | weekdayAvailability |
|------|------|---------------|---------------------|
| mom | owner | 5 | All days |
| dad | owner | 4 | All days |
| teen | child | 3 | Sat, Sun only |
| kid | child | 2 | Sat, Sun only |
| grandma | member | 2 | Mon-Thu, Sat, Sun |
| friend | member | 1 | Sat only |

### Tasks Created (12 total)
- Do the dishes (daily, kitchen, assigned to mom)
- Wipe kitchen counters (daily, kitchen, assigned to teen)
- Take out trash (daily, kitchen, assigned to dad)
- Tidy up toys (daily, living room, assigned to kid)
- Make bed (daily, kids bedroom, assigned to kid)
- Make bed (daily, master bedroom, assigned to teen)
- Clean bathroom sink (weekly, kids bathroom, assigned to teen)
- Vacuum living room (weekly, living room, unassigned)
- Dust furniture (weekly, living room, assigned to grandma)
- Clean bedroom (weekly, master bedroom, unassigned)
- Do laundry (weekly, master bedroom, assigned to mom)
- Clean toilet (weekly, master bathroom, unassigned)

## Recommendations

1. **BUG-002 Priority**: Implement role-based task filtering before alpha release
2. **Migration Fix**: Investigate why `preSchemaChange` migrations don't execute in Docker test environment
3. **Automated Test**: Add E2E test for child role task visibility limits

## Next Steps

1. Fix BUG-002 (role-based task filtering)
2. Add regression test for migration schema application
3. Continue multi-user permission testing (edit/delete permissions)
