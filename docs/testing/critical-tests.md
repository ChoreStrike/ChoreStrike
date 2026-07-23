# Critical Tests Priority List

## Priority 1: MUST PASS (Security + Authorization)

### New Security Regression Coverage (2026-02-25)
- `tests/unit/Service/SchedulingServiceTest.php`
  - Status: ✅ PASSING
  - Critical checks:
    - House-scoped resolution by Nextcloud user ID
    - Update blocking for cross-house member modifications

- `tests/unit/Service/TaskSyncServiceTest.php`
  - Status: ✅ PASSING
  - Critical checks:
    - Room-task house boundary validation
    - Pending sync filtering per house

### Existing Core Security Baseline
- `tests/integration/Controller/AuthorizationIntegrationTest.php`
  - Status: ⚠️ Partially blocked by integration test harness mismatch
  - Critical checks covered by test design:
    - Authenticated access required
    - House membership required
    - Owner role enforcement

## Priority 2: SHOULD PASS (Business Logic Stability)

- `tests/unit/Db/TaskTest.php` - ✅ PASSING
- `tests/unit/Db/RoomTaskTest.php` - ✅ PASSING
- `tests/unit/Service/GameLogicServiceTest.php` - ✅ PASSING
- `tests/unit/Service/HouseServiceTest.php` - ✅ PASSING
- `tests/unit/Service/RoomServiceTest.php` - ✅ PASSING
- `tests/unit/Service/FloorServiceTest.php` - ✅ PASSING

## Priority 3: NEXT AUTOMATION BATCH (High Value)

1. Add controller integration tests for:
   - `SchedulingController` (owner-only member availability, member-only own preferences)
   - `TaskSyncController` (house-scoped sync actions)
2. Refactor outdated integration tests that still target old controller signatures:
   - `TaskControllerIntegrationTest`
   - `UserControllerIntegrationTest`
   - `RoomTaskControllerIntegrationTest`
3. Add negative-path regression tests for cross-house resource access in all controllers.

## Recommended Execution Order

```bash
# P1 security regressions (run first)
php vendor/bin/phpunit tests/unit/Service/SchedulingServiceTest.php
php vendor/bin/phpunit tests/unit/Service/TaskSyncServiceTest.php

# P2 core domain confidence
php vendor/bin/phpunit tests/unit/Db/TaskTest.php
php vendor/bin/phpunit tests/unit/Db/RoomTaskTest.php
php vendor/bin/phpunit tests/unit/Service/GameLogicServiceTest.php
php vendor/bin/phpunit tests/unit/Service/HouseServiceTest.php
php vendor/bin/phpunit tests/unit/Service/RoomServiceTest.php
php vendor/bin/phpunit tests/unit/Service/FloorServiceTest.php
```

## Known Test Harness Constraints

1. Some integration tests still use outdated constructor contracts and planned API shapes.
2. Mapper-heavy tests require full Nextcloud/OCP-compatible runtime and are less stable outside Docker test setup.
3. Local (non-Docker) runs may require mock bootstrap alignment for selected OCP classes.
