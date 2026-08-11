# Phase 5 API Documentation

> Smart Scheduling endpoints for daily tasks and member availability

> **DEPRECATED (CS-016 review, 2026-07-28):** the `/api/v1/smart-schedule/*`
> endpoints documented below were **removed** from `appinfo/routes.php`.
> `SmartSchedulerController` injected no `AuthorizationService`, so all 7
> endpoints were reachable by any authenticated user. The UI consumes only the
> `scheduling#*` surface (decision D1=a). The controller/service/class cluster
> was **deleted** in CS-PLAN-025 (2026-08-09, CR-SVC-08); the `scheduling#*`
> endpoints are the canonical surface — see `docs/api/README.md`. This document
> is kept for historical reference only.

## Daily Tasks API

### GET /api/v1/tasks/daily

Returns today's scheduled tasks for the authenticated user with full details.

**Response** (200 OK):
```json
{
  "success": true,
  "data": {
    "date": "2026-03-03",
    "tasks": [
      {
        "id": 1,
        "name": "Clean Kitchen",
        "roomName": "Kitchen",
        "energyCost": 5,
        "priority": 3,
        "completed": false,
        "isOverdue": false,
        "allowTeamwork": true,
        "order": 1
      }
    ]
  }
}
```

**Error Responses**:
- `404 RESOURCE_NOT_FOUND` - House not found for user
- `403 FORBIDDEN` - User not a house member

---

### PUT /api/v1/tasks/{id}/complete

Marks a task as completed.

**Response** (200 OK):
```json
{
  "success": true,
  "message": "Task completed successfully",
  "data": {
    "id": 1,
    "completed": true,
    "completedAt": "2026-03-03T14:30:00+00:00"
  }
}
```

**Error Responses**:
- `404 RESOURCE_NOT_FOUND` - Task not found or not accessible
- `403 FORBIDDEN` - Task not assigned to current user

---

### PUT /api/v1/tasks/{id}/uncomplete

Reverts a completed task to pending status.

**Response** (200 OK):
```json
{
  "success": true,
  "message": "Task uncompleted successfully",
  "data": {
    "id": 1,
    "completed": false
  }
}
```

---

### PUT /api/v1/tasks/{id}/skip

Skips a task (moves due date forward by 1 day).

**Response** (200 OK):
```json
{
  "success": true,
  "message": "Task skipped successfully",
  "data": {
    "id": 1,
    "nextDue": "2026-03-04"
  }
}
```

---

### PUT /api/v1/tasks/{id}/reschedule

Reschedules a task to a new date.

**Request Body**:
```json
{
  "date": "2026-03-05"
}
```

**Response** (200 OK):
```json
{
  "success": true,
  "message": "Task rescheduled successfully",
  "data": {
    "id": 1,
    "nextDue": "2026-03-05"
  }
}
```

**Error Responses**:
- `400 VALIDATION_ERROR` - Invalid date format or date in the past

---

## Smart Schedule API

### GET /api/v1/smart-schedule/availability

Returns all house members' availability and energy budgets.

**Response** (200 OK):
```json
{
  "success": true,
  "data": [
    {
      "userId": "alice.nextcloud",
      "houseUserId": 1,
      "userName": "Alice",
      "energyBudget": {
        "monday": 10,
        "tuesday": 10,
        "wednesday": 10,
        "thursday": 10,
        "friday": 10,
        "saturday": 15,
        "sunday": 15
      },
      "weekdayAvailability": {
        "monday": true,
        "tuesday": true,
        "wednesday": false,
        "thursday": true,
        "friday": true,
        "saturday": true,
        "sunday": false
      }
    }
  ]
}
```

**Field Semantics**:
- `userId` - Nextcloud user ID (public identifier)
- `houseUserId` - Internal database ID (for service references)

---

### PUT /api/v1/smart-schedule/availability

Updates current user's weekday availability.

**Request Body**:
```json
{
  "monday": true,
  "tuesday": true,
  "wednesday": false,
  "thursday": true,
  "friday": true,
  "saturday": true,
  "sunday": false
}
```

**Validation**:
- All values must be boolean
- At least one day must be `true`

**Response** (200 OK):
```json
{
  "success": true,
  "message": "Availability updated successfully"
}
```

---

### PUT /api/v1/smart-schedule/energy-budget

Updates current user's energy budget per day.

**Request Body**:
```json
{
  "monday": 10,
  "tuesday": 8,
  "wednesday": 12,
  "thursday": 10,
  "friday": 8,
  "saturday": 15,
  "sunday": 20
}
```

**Validation**:
- All values must be integers between 1 and 20

**Response** (200 OK):
```json
{
  "success": true,
  "message": "Energy budget updated successfully"
}
```

---

### GET /api/v1/smart-schedule/preview

Returns a preview of the schedule for the next N days.

**Query Parameters**:
- `days` (optional, default: 7) - Number of days to preview

**Response** (200 OK):
```json
{
  "success": true,
  "data": {
    "dateRange": {
      "start": "2026-03-03",
      "end": "2026-03-10"
    },
    "taskCount": 18,
    "memberCount": 3,
    "dailyCapacity": {
      "2026-03-03": 25
    },
    "potentialConflicts": []
  }
}
```

---

### GET /api/v1/smart-schedule/suggestions

Returns smart scheduling suggestions derived from generated assignment data.

**Response** (200 OK):
```json
{
  "success": true,
  "data": {
    "monday": {
      "availableMembers": 2,
      "totalEnergy": 20,
      "recommendedTasks": 4
    },
    "tuesday": {
      "availableMembers": 3,
      "totalEnergy": 30,
      "recommendedTasks": 6
    }
  }
}
```

---

### POST /api/v1/smart-schedule/apply

Applies one scheduling suggestion by assigning and rescheduling a task.

**Request Body**:
```json
{
  "taskId": 42,
  "assignedTo": "alice",
  "scheduledFor": "2026-03-15"
}
```

**Response** (200 OK):
```json
{
  "success": true,
  "message": "Suggestion applied successfully",
  "data": {
    "taskId": 42,
    "assignedTo": "alice",
    "scheduledFor": "2026-03-15"
  }
}
```

**Error Responses**:
- `400 VALIDATION_ERROR` - Invalid assignee/date or unavailable member
- `404 RESOURCE_NOT_FOUND` - House not found for user

---

## Authorization Semantics

Phase 5 implements "Option-3" API semantics for all endpoints:

| Status | Meaning | When Used |
|--------|---------|-----------|
| `404 RESOURCE_NOT_FOUND` | Resource hidden or does not exist | Task exists but user has no access, or task not found |
| `403 FORBIDDEN` | Resource accessible but action forbidden | User can see task but cannot modify it (not assigned) |
| `400 VALIDATION_ERROR` | Invalid input data | Malformed dates, invalid availability values |

This approach prevents information leakage while providing clear error semantics.
