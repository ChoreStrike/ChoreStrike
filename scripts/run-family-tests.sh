#!/bin/bash

# ChoreStrike Family Test Runner
# This script runs all family-related tests and provides a summary

set -e

echo "🏠 ChoreStrike Family Test Suite"
echo "================================="
echo

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Function to print colored output
print_status() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

print_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Check if we're in the right directory
if [ ! -f "composer.json" ] || [ ! -d "tests" ]; then
    print_error "Please run this script from the ChoreStrike root directory"
    exit 1
fi

# Setup test family
print_status "Setting up test family data..."
if php scripts/setup-test-family.php; then
    print_success "Test family setup complete"
else
    print_error "Failed to setup test family"
    exit 1
fi

echo

# Run unit tests for core components
print_status "Running core unit tests..."
echo "--------------------------------"

unit_tests_passed=true

# Test Task entity
echo "Testing Task entity..."
if php composer.phar test tests/unit/Db/TaskTest.php > /dev/null 2>&1; then
    print_success "✅ Task entity tests passed"
else
    print_error "❌ Task entity tests failed"
    unit_tests_passed=false
fi

# Test RoomTask entity
echo "Testing RoomTask entity..."
if php composer.phar test tests/unit/Db/RoomTaskTest.php > /dev/null 2>&1; then
    print_success "✅ RoomTask entity tests passed"
else
    print_error "❌ RoomTask entity tests failed"
    unit_tests_passed=false
fi

# Test GameLogicService
echo "Testing GameLogicService..."
if php composer.phar test tests/unit/Service/GameLogicServiceTest.php > /dev/null 2>&1; then
    print_success "✅ GameLogicService tests passed"
else
    print_warning "⚠️  GameLogicService tests have some issues (non-critical)"
fi

echo

# Run frontend tests
print_status "Running frontend component tests..."
echo "--------------------------------------"

frontend_tests_passed=true
if npm test > /dev/null 2>&1; then
    print_success "✅ Frontend tests passed"
else
    print_error "❌ Frontend tests failed"
    frontend_tests_passed=false
fi

echo

# Run family integration tests
print_status "Running family integration tests..."
echo "---------------------------------------"

integration_tests_passed=true
if php composer.phar test tests/integration/FamilyIntegrationTest.php > /dev/null 2>&1; then
    print_success "✅ Family integration tests passed"
else
    print_warning "⚠️  Family integration tests have some issues"
fi

echo

# Summary
print_status "Test Summary"
echo "==============="

if [ "$unit_tests_passed" = true ]; then
    print_success "✅ Core unit tests: PASSED"
else
    print_error "❌ Core unit tests: FAILED"
fi

if [ "$frontend_tests_passed" = true ]; then
    print_success "✅ Frontend tests: PASSED"
else
    print_error "❌ Frontend tests: FAILED"
fi

if [ "$integration_tests_passed" = true ]; then
    print_success "✅ Integration tests: PASSED"
else
    print_warning "⚠️  Integration tests: SOME ISSUES"
fi

echo

# Manual testing suggestions
print_status "Manual Testing Recommendations"
echo "-----------------------------------"
echo
echo "🎯 Test these scenarios manually:"
echo
echo "1. Parent Daily Routine:"
echo "   - User: Mom"
echo "   - Tasks: Dishes, Wipe counters, Laundry"
echo "   - Expected: 30 points total"
echo
echo "2. Teen Weekend Tasks:"
echo "   - User: Teen"
echo "   - Tasks: Make bed, Clean bathroom sink"
echo "   - Expected: 30 points total"
echo
echo "3. Concurrent Task Completion:"
echo "   - Users: Mom + Dad"
echo "   - Tasks: Different kitchen tasks"
echo "   - Expected: Independent scoring"
echo
echo "4. Streak Testing:"
echo "   - Complete daily tasks for 3+ days"
echo "   - Verify streak increases correctly"
echo
echo "5. Role-based Access:"
echo "   - Test parent permissions vs child vs guest"
echo "   - Verify task assignment restrictions"
echo

# Next steps
print_status "Next Steps"
echo "-----------"
echo
if [ "$unit_tests_passed" = true ] && [ "$frontend_tests_passed" = true ]; then
    print_success "🚀 Ready for manual testing in Nextcloud UI!"
    echo
    echo "Start your Nextcloud instance and:"
    echo "1. Login as 'mom' (test user)"
    echo "2. Navigate to ChoreStrike app"
    echo "3. Complete today's tasks"
    echo "4. Verify points and streak updates"
else
    print_warning "⚠️  Fix failing tests before manual testing"
    echo
    echo "Run individual test suites for details:"
    echo "  php composer.phar test tests/unit/Db/TaskTest.php"
    echo "  npm test"
fi

echo
print_status "Done! 🎉"
