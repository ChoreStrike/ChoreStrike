# API Contract — Preferences & Digest

> CS-PLAN-070 WP5 (ADR-016). All routes are `@NoAdminRequired` and
> membership-gated; errors use the shared
> `{ success: false, error: { code, message } }` envelope.

## Personal Preferences (self-only)

There is deliberately **no** owner read/write of another member's
preferences — `/preferences/me` is the only surface (children included).

### `GET /api/v1/preferences/me`

Returns the caller's own versioned payload (never creates a row —
unsaved members read the neutral defaults at version 0):

```json
{
  "success": true,
  "data": {
    "version": 3,
    "taskDigestEnabled": true,
    "orgDigestEnabled": false,
    "digestTime": "18:00",
    "quietStart": "20:00",
    "quietEnd": "08:00",
    "soundEnabled": false,
    "animationEnabled": false,
    "arcadeVisible": true,
    "catalogVisible": true,
    "ranking": { "state": "pending|effective|none", "generation": 4, "effectiveMonday": "2026-10-05" }
  }
}
```

### `PUT /api/v1/preferences/me`

Whitelisted fields only — `taskDigestEnabled`, `orgDigestEnabled`,
`digestTime`, `quietStart`, `quietEnd`, `soundEnabled`,
`animationEnabled`, `arcadeVisible`, `catalogVisible` — plus:

- `expectedVersion` (int, required): optimistic concurrency against the
  shared `member_prefs.version`.
- `commandId` (UUID, required): receipt identity; a retry with the same
  key + payload replays, a reused key with different input conflicts.

Validation happens before any side effect:

Shape validation precedes command execution; under the house lock, partial
updates are merged with the stored/default values before checking the quiet
range and enabled-digest time invariant. Omitting a field preserves it;
clearing only one saved quiet bound or clearing an enabled digest's time is
invalid.

| Situation | Response |
|---|---|
| Unknown field / bad shape / single quiet bound / enabled digest without time | `400 PREFERENCES_VALIDATION_ERROR` |
| Stale `expectedVersion` | `409 CONFLICT` (client draft preserved) |
| Reused `commandId` with different payload | `409 CONFLICT` |

A first save creates the row **without** ranking consent (RF-076-01).
Every write bumps the shared `version` under the house lock — the same
counter the ranking consent flows use.

## The Digest Notification

There is no API to trigger a digest: the five-minute
`DailyNotificationJob` pass materializes and dispatches automatically.
In the test environment, `occ chorestrike:trigger-jobs
daily-notification --as-of="YYYY-MM-DD[ HH:MM[:SS]]"` runs the SAME
pass with a fixed instant (E2E seam).

Delivered bell shape (rendered by `ChoreStrikeNotifier`):

- `app`: `chorestrike`
- `object_type`: `digest`
- `object_id`: `{houseId}:{houseUserId}:{Y-m-d}` (dedup reinforcement;
  the slot identity is the actual guarantee)
- Subject: count-based (`Daily summary: 3 planned tasks · 2 household
  updates`) — no task names, no object references.
- Link: the app's My Day surface.

Former subjects (`task_due_today`/`task_due_tomorrow`,
`medal_awarded`, `weekly_goal_reached`, `title_unlocked`,
`overdue_taken_over`) are retired with their dispatch callers; the
notifier rejects unknown subjects.

## Preference Lifecycle Interplay (Q-076-03)

- **Withdraw ranking consent** / **house ranking disable**: reset ONLY
  the consent columns (generation 0, Monday/zone cleared) — delivery
  preferences survive.
- **Re-consent**: updates the row in place with a fresh generation from
  the house watermark.
- **Departure / account erasure**: the preference row AND both digest
  tables are membership-keyed and deleted with the membership; a
  rejoining account starts quiet/off.
