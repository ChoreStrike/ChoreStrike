# Manual Test Checklist

> **Version**: 1.0.0
> **Last Updated**: 2026-03-05

---

## Test Environment Setup

- [ ] Fresh Nextcloud instance available
- [ ] Test user accounts created (owner, member, child)
- [ ] Test database populated with sample data

---

## Core Functionality Tests

### House Management

| Test | Steps | Expected Result | Status |
|------|-------|-----------------|--------|
| House creation | Open app for first time | House created automatically | [ ] |
| House name update | Edit house name | Name updated successfully | [ ] |
| House membership | Check member list | Current user listed as owner | [ ] |

### Floor Management

| Test | Steps | Expected Result | Status |
|------|-------|-----------------|--------|
| Create floor | Add new floor with name | Floor appears in list | [ ] |
| Edit floor | Rename floor | Name updated | [ ] |
| Delete floor | Delete floor with rooms | Floor and rooms removed | [ ] |
| Delete empty floor | Delete floor without rooms | Floor removed | [ ] |
| Floor order | Reorder floors | Order persists | [ ] |

### Room Management

| Test | Steps | Expected Result | Status |
|------|-------|-----------------|--------|
| Create room | Add room to floor | Room appears under floor | [ ] |
| Edit room | Rename room | Name updated | [ ] |
| Delete room | Delete room with tasks | Room and tasks removed | [ ] |
| Room cleanliness | Complete tasks in room | Cleanliness percentage updates | [ ] |

### Task Management

| Test | Steps | Expected Result | Status |
|------|-------|-----------------|--------|
| Create task | Add task to room | Task appears in room | [ ] |
| Task details | Set frequency, difficulty | Settings saved correctly | [ ] |
| Complete task | Mark task as done | Task marked complete, coins earned | [ ] |
| Skip task | Skip a task | Task skipped, no penalty | [ ] |
| Reschedule task | Change due date | Due date updated | [ ] |
| Task recurrence | Complete recurring task | New instance created | [ ] |
| Checklist items | Add/complete checklist | Progress tracked correctly | [ ] |

### User Management

| Test | Steps | Expected Result | Status |
|------|-------|-----------------|--------|
| Invite user | Invite by Nextcloud ID | User added as member | [ ] |
| Role assignment | Set user as child | Role updated | [ ] |
| Remove user | Remove member from house | User removed | [ ] |
| Owner transfer | Transfer ownership | New owner has full access | [ ] |

---

## Gamification Tests

### Sparks & Combos

| Test | Steps | Expected Result | Status |
|------|-------|-----------------|--------|
| Earn sparks | Complete task | Sparks added to user total | [ ] |
| Combo detection | Complete multiple tasks quickly | Combo indicator shows | [ ] |
| Combo bonus | Complete task during combo | Bonus sparks awarded | [ ] |
| Combo break | Wait too long between tasks | Combo ends | [ ] |

### Achievements & Medals

| Test | Steps | Expected Result | Status |
|------|-------|-----------------|--------|
| First task medal | Complete first task | Medal awarded | [ ] |
| Streak medal | Maintain streak | Medal awarded at milestone | [ ] |
| Achievement notification | Earn achievement | Notification appears | [ ] |

### Leaderboard

| Test | Steps | Expected Result | Status |
|------|-------|-----------------|--------|
| View leaderboard | Open leaderboard tab | Users ranked by sparks | [ ] |
| Streak leaderboard | View streak ranking | Users ranked by streak | [ ] |

---

## Smart Scheduling Tests

| Test | Steps | Expected Result | Status |
|------|-------|-----------------|--------|
| Open scheduling view | Click "Scheduling" in nav | Scheduling view with 5 sections renders | [ ] |
| View member availability | Open scheduling → Availability section | Member cards with weekday grid shown | [ ] |
| Edit own availability | Click "Edit" on own member card → toggle days → Save | Availability toggles persist after reload | [ ] |
| Apply quick template | Edit availability → click "Balanced" template | All days toggled on, energy set to 10/15 | [ ] |
| Set energy budget | Adjust energy slider for a day → Save | Energy value persists after reload | [ ] |
| Apply energy template | Click "Heavy Schedule" template | All sliders move to 15/20 | [ ] |
| View schedule calendar | Open scheduling → Calendar section | Week grid with member rows and capacity shown | [ ] |
| Navigate weeks | Click next/previous week buttons | Week range label updates | [ ] |
| Load schedule preview | Click "Load Preview" in Preview section | Suggestions or empty state shown | [ ] |
| Load scheduling suggestions | Click "Load Suggestions" in Suggestions section | Suggestions list or empty state shown | [ ] |

---

## Edge Cases & Error Handling

### Input Validation

| Test | Steps | Expected Result | Status |
|------|-------|-----------------|--------|
| Empty name | Submit empty name field | Validation error shown | [ ] |
| Invalid date | Enter invalid date | Error handled gracefully | [ ] |
| Special characters | Use emojis in names | Saved correctly | [ ] |
| Long names | Enter very long name | Truncated or handled | [ ] |

### Permission Errors

| Test | Steps | Expected Result | Status |
|------|-------|-----------------|--------|
| Child creates floor | Child user tries to create floor | Permission denied | [ ] |
| Member removes user | Member tries to remove user | Permission denied | [ ] |
| Non-member access | Try to access without membership | Access denied | [ ] |

### Data Integrity

| Test | Steps | Expected Result | Status |
|------|-------|-----------------|--------|
| Concurrent edits | Two users edit same task | No data corruption | [ ] |
| Network disconnect | Lose connection during save | Graceful error handling | [ ] |
| Session timeout | Session expires mid-task | Login redirect | [ ] |

---

## Performance Tests

| Test | Steps | Expected Result | Status |
|------|-------|-----------------|--------|
| Many tasks | Create 100+ tasks | App remains responsive | [ ] |
| Many users | Add 10+ users | User list loads quickly | [ ] |
| Long session | Use app for 1+ hour | No memory leaks | [ ] |

---

## Browser Compatibility

| Browser | Version | Status | Notes |
|---------|---------|--------|-------|
| Chrome | Latest | [ ] | |
| Firefox | Latest | [ ] | |
| Safari | Latest | [ ] | |
| Edge | Latest | [ ] | |

---

## Mobile Responsiveness

| Test | Steps | Expected Result | Status |
|------|-------|-----------------|--------|
| Phone portrait | View on phone | UI adapts correctly | [ ] |
| Phone landscape | Rotate phone | Layout adjusts | [ ] |
| Tablet | View on tablet | Optimal layout shown | [ ] |
| Touch interactions | Tap, swipe elements | Touch works correctly | [ ] |

---

## Test Results Summary

**Date**: _______________
**Tester**: _______________
**Environment**: _______________

| Category | Tests Passed | Tests Failed | Notes |
|----------|--------------|--------------|-------|
| Core Functionality | | | |
| Gamification | | | |
| Smart Scheduling | | | |
| Edge Cases | | | |
| Performance | | | |
| Browser Compatibility | | | |
| Mobile | | | |

**Overall Status**: [ ] Pass [ ] Fail

**Critical Issues Found**:
1.
2.
3.

**Minor Issues Found**:
1.
2.
3.
