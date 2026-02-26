# Family Test Setup Guide

This guide explains how to use the standardized test family setup for ChoreStrike development and testing.

## Overview

The test family provides a realistic scenario with:
- **6 Family Members**: 2 parents (owners), 2 children, 1 grandparent, 1 friend
- **6 Rooms**: Kitchen, living room, 2 bedrooms, 2 bathrooms
- **12 Task Types**: Various household chores with different difficulties
- **15+ Scheduled Tasks**: Daily and recurring tasks

## Family Structure

### Members

| Role | Name | Age | Access | Max Daily Tasks |
|------|------|-----|--------|-----------------|
| Owner | Mom | 35 | Full | 5 |
| Owner | Dad | 37 | Full | 4 |
| Child | Teen | 15 | Limited | 3 |
| Child | Kid | 8 | Limited | 2 |
| Guest | Grandma | 65 | Limited | 2 |
| Guest | Friend | 30 | Very Limited | 1 |

### Rooms

| Room | Cleanliness | Floor |
|------|-------------|-------|
| Kitchen | 75% | 1 |
| Living Room | 60% | 1 |
| Master Bedroom | 80% | 2 |
| Kids Bedroom | 40% | 2 |
| Master Bathroom | 70% | 1 |
| Kids Bathroom | 50% | 1 |

### Task Categories

#### Kitchen Tasks
- **Do the dishes** (Energy: 3, Time: 30min, Priority: High, Daily)
- **Wipe kitchen counters** (Energy: 2, Time: 15min, Priority: Medium, Daily)
- **Take out kitchen trash** (Energy: 2, Time: 10min, Priority: High, Daily)

#### Living Room Tasks
- **Vacuum living room** (Energy: 4, Time: 20min, Priority: Medium, Weekly)
- **Dust furniture** (Energy: 2, Time: 15min, Priority: Low, Weekly)
- **Tidy up toys** (Energy: 1, Time: 10min, Priority: Low, Daily)

#### Bedroom Tasks
- **Make bed** (Energy: 1, Time: 5min, Priority: Low, Daily)
- **Clean bedroom** (Energy: 3, Time: 30min, Priority: Medium, Weekly)
- **Do laundry** (Energy: 4, Time: 60min, Priority: Medium, Weekly)

#### Bathroom Tasks
- **Clean toilet** (Energy: 3, Time: 15min, Priority: High, Weekly)
- **Clean bathroom sink** (Energy: 2, Time: 10min, Priority: Medium, Weekly)
- **Restock bathroom supplies** (Energy: 1, Time: 5min, Priority: Low, Weekly)

## Usage

### 1. Automated Setup

```bash
# Setup test family (keeps existing data)
php scripts/setup-test-family.php

# Clean setup (removes existing test data first)
php scripts/setup-test-family.php --clean
```

### 2. Run Integration Tests

```bash
# Run all family integration tests
php composer.phar test tests/integration/FamilyIntegrationTest.php

# Run specific scenario
php composer.phar test --filter testParentDailyRoutine
```

### 3. Manual Testing Scenarios

#### Scenario 1: Parent Daily Routine
- **User**: Mom
- **Tasks**: Dishes, Wipe counters, Laundry
- **Expected Points**: 30
- **Test**: Complete all tasks, verify points and streak

#### Scenario 2: Teen Weekend Tasks
- **User**: Teen (15 years)
- **Tasks**: Make bed, Clean bathroom sink
- **Expected Points**: 30
- **Test**: Weekend availability, limited task access

#### Scenario 3: Kid Simple Tasks
- **User**: Kid (8 years)
- **Tasks**: Make bed, Tidy toys
- **Expected Points**: 20
- **Test**: Age-appropriate tasks, lower limits

#### Scenario 4: Guest Helping
- **User**: Grandma
- **Tasks**: Dust furniture
- **Expected Points**: 20
- **Test**: Guest permissions, limited access

#### Scenario 5: Concurrent Completion
- **Users**: Mom + Dad
- **Tasks**: Different kitchen tasks
- **Test**: Multiple users, independent scoring

## Test Data Files

### Core Fixtures
- `tests/fixtures/FamilyTestData.php` - Complete test data generation
- `tests/integration/FamilyIntegrationTest.php` - Integration test suite

### Setup Scripts
- `scripts/setup-test-family.php` - Automated setup script

## Testing Features

### Points Calculation
- Base points: Energy cost × 10
- Priority multiplier: High (1.5×), Medium (1.2×), Low (1.0×)
- Room cleanliness multiplier: Dirty room (1.5×)

### Streak System
- Daily completion increases streak
- Weekday availability affects streak calculation
- Missed available days reset streak

### Task Scheduling
- Daily tasks: Every day
- Weekly tasks: Once per week
- Recurring vs one-time tasks

### User Roles
- **Owner**: Full access, can manage house
- **Child**: Limited access, age restrictions
- **Guest**: Very limited access, no management

## API Testing

Use the test family to test API endpoints:

```bash
# Get today's tasks for teen
curl -X GET "http://localhost/api/v1/roomtasks?user=teen&date=today"

# Complete a task
curl -X POST "http://localhost/api/v1/roomtasks/dishes_today/complete" \
  -H "Content-Type: application/json" \
  -d '{"userId": "mom"}'

# Get user stats
curl -X GET "http://localhost/api/v1/users/teen/stats"
```

## Manual Testing Checklist

### Basic Functionality
- [ ] Users can see their assigned tasks
- [ ] Task completion updates points
- [ ] Streak increases with daily completion
- [ ] Room cleanliness affects points

### Role-Based Access
- [ ] Parents can assign tasks to anyone
- [ ] Children only see age-appropriate tasks
- [ ] Guests have limited task access
- [ ] Owners can manage house settings

### Task Scheduling
- [ ] Daily tasks appear every day
- [ ] Weekly tasks appear weekly
- [ ] Recurring tasks reactivate after completion
- [ ] One-time tasks deactivate after completion

### Gamification
- [ ] Points calculate correctly
- [ ] Leaderboard updates properly
- [ ] Medals award for achievements
- [ ] Streaks track correctly

## Troubleshooting

### Common Issues

**Tasks not appearing**
- Check user role and permissions
- Verify task scheduling (daily vs weekly)
- Ensure room tasks are active

**Points not calculating**
- Verify energy cost and priority settings
- Check room cleanliness multiplier
- Ensure task completion is recorded

**Streak not updating**
- Check last activity timestamp
- Verify weekday availability settings
- Ensure task was completed on different day

### Reset Test Data

```bash
# Remove all test data and start fresh
php scripts/setup-test-family.php --clean
```

## Extending the Test Family

To add new test scenarios:

1. Update `FamilyTestData.php` with new users/tasks
2. Add scenarios to `getUserScenarios()`
3. Create new integration tests in `FamilyIntegrationTest.php`
4. Update documentation

## Production Deployment

Before deploying to production:
1. Remove or disable test data generation
2. Ensure test users don't exist in production
3. Verify test house doesn't interfere with real houses
