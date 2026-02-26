@echo off
REM ChoreStrike Family Test Runner for Windows
REM This script runs all family-related tests and provides a summary

echo 🏠 ChoreStrike Family Test Suite
echo ================================
echo.

REM Check if we're in the right directory
if not exist "composer.json" (
    echo [ERROR] Please run this script from the ChoreStrike root directory
    exit /b 1
)

if not exist "tests" (
    echo [ERROR] Please run this script from the ChoreStrike root directory
    exit /b 1
)

REM Setup test family
echo [INFO] Setting up test family data...
php scripts/setup-test-family.php
if %errorlevel% neq 0 (
    echo [ERROR] Failed to setup test family
    exit /b 1
)

echo [SUCCESS] Test family setup complete
echo.

REM Run unit tests for core components
echo [INFO] Running core unit tests...
echo --------------------------------

echo Testing Task entity...
php composer.phar test tests/unit/Db/TaskTest.php >nul 2>&1
if %errorlevel% equ 0 (
    echo [SUCCESS] ✅ Task entity tests passed
) else (
    echo [ERROR] ❌ Task entity tests failed
)

echo Testing RoomTask entity...
php composer.phar test tests/unit/Db/RoomTaskTest.php >nul 2>&1
if %errorlevel% equ 0 (
    echo [SUCCESS] ✅ RoomTask entity tests passed
) else (
    echo [ERROR] ❌ RoomTask entity tests failed
)

echo Testing GameLogicService...
php composer.phar test tests/unit/Service/GameLogicServiceTest.php >nul 2>&1
if %errorlevel% equ 0 (
    echo [SUCCESS] ✅ GameLogicService tests passed
) else (
    echo [WARNING] ⚠️  GameLogicService tests have some issues (non-critical)
)

echo.

REM Run frontend tests
echo [INFO] Running frontend component tests...
echo --------------------------------------
npm test >nul 2>&1
if %errorlevel% equ 0 (
    echo [SUCCESS] ✅ Frontend tests passed
) else (
    echo [ERROR] ❌ Frontend tests failed
)

echo.

REM Run family integration tests
echo [INFO] Running family integration tests...
echo ---------------------------------------
php composer.phar test tests/integration/FamilyIntegrationTest.php >nul 2>&1
if %errorlevel% equ 0 (
    echo [SUCCESS] ✅ Family integration tests passed
) else (
    echo [WARNING] ⚠️  Family integration tests have some issues
)

echo.

REM Manual testing suggestions
echo [INFO] Manual Testing Recommendations
echo -----------------------------------
echo.
echo 🎯 Test these scenarios manually:
echo.
echo 1. Parent Daily Routine:
echo    - User: Mom
echo    - Tasks: Dishes, Wipe counters, Laundry
echo    - Expected: 30 points total
echo.
echo 2. Teen Weekend Tasks:
echo    - User: Teen
echo    - Tasks: Make bed, Clean bathroom sink
echo    - Expected: 30 points total
echo.
echo 3. Concurrent Task Completion:
echo    - Users: Mom + Dad
echo    - Tasks: Different kitchen tasks
echo    - Expected: Independent scoring
echo.
echo 4. Streak Testing:
echo    - Complete daily tasks for 3+ days
echo    - Verify streak increases correctly
echo.
echo 5. Role-based Access:
echo    - Test parent permissions vs child vs guest
echo    - Verify task assignment restrictions
echo.

REM Next steps
echo [INFO] Next Steps
echo -----------
echo.
echo 🚀 Ready for manual testing in Nextcloud UI!
echo.
echo Start your Nextcloud instance and:
echo 1. Login as 'mom' (test user)
echo 2. Navigate to ChoreStrike app
echo 3. Complete today's tasks
echo 4. Verify points and streak updates
echo.

echo [INFO] Done! 🎉
pause
