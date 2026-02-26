# 🎯 Manual Testing Checklist - Test Family

## ✅ Pre-Flight Check

- [x] Unit Tests: Task entity (21/21 passing)
- [x] Unit Tests: RoomTask entity (2/2 passing) 
- [x] Frontend Tests: Components (55/55 passing)
- [x] Test Family Setup: Complete

## 🏠 Test Family Setup Verified

### Family Members
- **Mom** (Owner, 35) - Full access, 5 max daily tasks
- **Dad** (Owner, 37) - Full access, 4 max daily tasks  
- **Teen** (Child, 15) - Limited access, 3 max daily tasks
- **Kid** (Child, 8) - Limited access, 2 max daily tasks
- **Grandma** (Guest, 65) - Limited access, 2 max daily tasks
- **Friend** (Guest, 30) - Very limited access, 1 max daily task

### Today's Tasks (7 active)
1. **Do the dishes** - Kitchen - Assigned: Mom (Energy: 3, Priority: High)
2. **Wipe kitchen counters** - Kitchen - Assigned: Teen (Energy: 2, Priority: Medium)
3. **Take out kitchen trash** - Kitchen - Assigned: Dad (Energy: 2, Priority: High)
4. **Tidy up toys** - Living Room - Assigned: Kid (Energy: 1, Priority: Low)
5. **Make bed** - Kids Bedroom - Assigned: Teen (Energy: 1, Priority: Low)
6. **Make bed** - Master Bedroom - Assigned: Kid (Energy: 1, Priority: Low)
7. **Clean bathroom sink** - Kids Bathroom - Assigned: Teen (Energy: 2, Priority: Medium)

## 🎮 Manual Test Scenarios

### Scenario 1: Parent Daily Routine
**User**: Mom  
**Tasks**: Do the dishes (3 points), Wipe counters (2 points), Laundry (4 points)  
**Expected**: 30 points total, streak +1

**Steps**:
1. Login as Mom
2. Navigate to ChoreStrike
3. Find "Do the dishes" task
4. Mark as complete
5. Verify: +30 points, streak increases
6. Check task disappears/moves to completed

### Scenario 2: Teen Weekend Tasks  
**User**: Teen  
**Tasks**: Make bed (1 point), Clean bathroom sink (2 points)  
**Expected**: 30 points total, limited to weekend availability

**Steps**:
1. Login as Teen
2. Verify only weekend tasks visible
3. Complete "Make bed"
4. Complete "Clean bathroom sink" 
5. Verify: +30 points, appropriate for age

### Scenario 3: Kid Simple Tasks
**User**: Kid  
**Tasks**: Make bed (1 point), Tidy toys (1 point)  
**Expected**: 20 points total, age-appropriate tasks only

**Steps**:
1. Login as Kid
2. Verify simple tasks only
3. Complete both tasks
4. Verify: +20 points, no complex tasks

### Scenario 4: Concurrent Completion
**Users**: Mom + Dad simultaneously  
**Tasks**: Different kitchen tasks  
**Expected**: Independent scoring, no conflicts

**Steps**:
1. Mom completes "Do the dishes"
2. Dad completes "Take out trash" 
3. Verify both get points independently
4. Check leaderboard updates correctly

### Scenario 5: Streak Testing
**User**: Any family member  
**Expected**: Streak increases with daily completion

**Steps**:
1. Complete task today
2. Check streak = 1
3. Complete task tomorrow  
4. Check streak = 2
5. Skip available day
6. Check streak resets to 1

### Scenario 6: Role-Based Access
**Test**: Permission differences

**Owner (Mom/Dad)**:
- [ ] Can assign tasks to anyone
- [ ] Can manage house settings
- [ ] Can see all room statistics

**Child (Teen/Kid)**:
- [ ] Limited to age-appropriate tasks
- [ ] Cannot assign tasks to others
- [ ] Limited house management access

**Guest (Grandma/Friend)**:
- [ ] Very limited task access
- [ ] No management permissions
- [ ] Can only complete assigned tasks

## 🔍 Edge Cases to Test

### Task Scheduling
- [ ] Daily tasks reappear next day
- [ ] Weekly tasks appear weekly
- [ ] Recurring tasks reactivate after completion
- [ ] One-time tasks stay completed

### Room Cleanliness Impact
- [ ] Dirty room gives more points (1.5x multiplier)
- [ ] Clean room gives normal points
- [ ] Room cleanliness updates after tasks

### Points Calculation
- [ ] Energy cost × 10 = base points
- [ ] Priority multipliers applied correctly
- [ ] Room cleanliness multipliers applied
- [ ] Total points calculated correctly

### User Interface
- [ ] Tasks load correctly for each user
- [ ] Completion animations work
- [ ] Leaderboard updates in real-time
- [ ] Mobile responsive design works

## 📊 Expected Results Summary

| Scenario | User | Tasks | Points | Streak |
|----------|------|-------|--------|--------|
| Parent Daily | Mom | 3 | 30 | +1 |
| Teen Weekend | Teen | 2 | 30 | +1 |
| Kid Simple | Kid | 2 | 20 | +1 |
| Concurrent | Mom+Dad | 2 each | 30 each | +1 each |
| Streak Test | Any | Daily | Varies | Increases |

## 🚀 Ready for Testing

The test family is ready! You can now:

1. **Start Nextcloud** with ChoreStrike app
2. **Create test users** or use existing ones
3. **Load test data** using `php scripts/setup-test-family.php`
4. **Run through scenarios** above
5. **Document any issues** found

## 📝 Test Results Template

```
Scenario: [Name]
Date: [Date]
Tester: [Your Name]
Results:
- Points calculated: ✅/❌
- Streak updated: ✅/❌  
- UI responsive: ✅/❌
- Issues found: [List]
Notes: [Additional observations]
```

Good luck testing! 🎉
