<?php

return [
    'routes' => [
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

        // House API
        ['name' => 'house#get', 'url' => '/api/v1/house', 'verb' => 'GET'],
        ['name' => 'house#update', 'url' => '/api/v1/house', 'verb' => 'PUT'],
        ['name' => 'house#stats', 'url' => '/api/v1/house/stats', 'verb' => 'GET'],
        ['name' => 'house#activity', 'url' => '/api/v1/house/activity', 'verb' => 'GET'],
        ['name' => 'house#health', 'url' => '/api/v1/house/health', 'verb' => 'GET'],
        ['name' => 'house#reset', 'url' => '/api/v1/house/reset', 'verb' => 'POST'],
        // CS-PLAN-070 WP7 (CS-PLAN-078 §3.3): the owner-only, versioned
        // reward-catalog gate writer (narrow refinement of the shared
        // house settings; PUT /house stays unversioned).
        ['name' => 'house#updateCatalog', 'url' => '/api/v1/house/catalog', 'verb' => 'PUT'],

        // Floor API
        ['name' => 'floor#create', 'url' => '/api/v1/floors', 'verb' => 'POST'],
        ['name' => 'floor#withRooms', 'url' => '/api/v1/floors/with-rooms', 'verb' => 'GET'],
        ['name' => 'floor#reorder', 'url' => '/api/v1/floors/reorder', 'verb' => 'POST'],
        ['name' => 'floor#update', 'url' => '/api/v1/floors/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
        ['name' => 'floor#delete', 'url' => '/api/v1/floors/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],

        // Room API
        ['name' => 'room#create', 'url' => '/api/v1/rooms', 'verb' => 'POST'],
        ['name' => 'room#update', 'url' => '/api/v1/rooms/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
        ['name' => 'room#delete', 'url' => '/api/v1/rooms/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],
        ['name' => 'room#updateCleanliness', 'url' => '/api/v1/rooms/{id}/cleanliness', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],

        // Task API
        // NOTE: static single-segment routes MUST precede the parameterized
        // `{id}` routes — Nextcloud's router matches in registration order
        // (shadowing rule fixed by CS-PLAN-023; guarded by RouteMatrixTest).
        // Pruned 2026-09-05 (CS-PLAN-063): `task#create` (POST /tasks) lost
        // its last consumer to the atomic creation aggregate — creation now
        // runs exclusively through POST /room-tasks (custom/mount) and
        // POST /room-tasks/from-template.
        ['name' => 'task#index', 'url' => '/api/v1/tasks', 'verb' => 'GET'],
        ['name' => 'task#update', 'url' => '/api/v1/tasks/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
        // Pruned 2026-09-06 (CS-PLAN-064, decision D5): `task#uncomplete`
        // (PUT /tasks/{id}/uncomplete) lost its last consumer to the undo
        // aggregate — undo now runs exclusively through POST
        // /room-tasks/{id}/undo.

        // Room Task API
        // NOTE: static single-segment routes MUST precede `{id}` (same
        // shadowing rule as the Task API above; fixed by CS-PLAN-023).
        ['name' => 'room_task#index', 'url' => '/api/v1/room-tasks', 'verb' => 'GET'],
        ['name' => 'room_task#overdue', 'url' => '/api/v1/room-tasks/overdue', 'verb' => 'GET'],
        ['name' => 'room_task#dueToday', 'url' => '/api/v1/room-tasks/due-today', 'verb' => 'GET'],
        ['name' => 'room_task#fromTemplate', 'url' => '/api/v1/room-tasks/from-template', 'verb' => 'POST'],
        ['name' => 'room_task#range', 'url' => '/api/v1/room-tasks/range', 'verb' => 'GET'],
        ['name' => 'room_task#filteredDaily', 'url' => '/api/v1/room-tasks/filtered-daily', 'verb' => 'GET'],
        // Static single-segment route — must stay ahead of the `{id}` block.
        ['name' => 'room_task#bulkAssign', 'url' => '/api/v1/room-tasks/bulk-assign', 'verb' => 'POST'],
        ['name' => 'room_task#create', 'url' => '/api/v1/room-tasks', 'verb' => 'POST'],
        ['name' => 'room_task#delete', 'url' => '/api/v1/room-tasks/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],
        ['name' => 'room_task#complete', 'url' => '/api/v1/room-tasks/{id}/complete', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
        ['name' => 'room_task#assignees', 'url' => '/api/v1/room-tasks/{id}/assignees', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
        ['name' => 'room_task#setAssignees', 'url' => '/api/v1/room-tasks/{id}/assignees', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
        // CS-PLAN-064 WP5: the one idempotent undo aggregate (decision D5)
        // — replaced by the WP3 targeted completion correction: undo runs
        // exclusively through POST /completions/{id}/undo (exact
        // completion targeting; the latest-by-room-task route is gone
        // without a compatibility alias).
        ['name' => 'room_task#updateDueDate', 'url' => '/api/v1/room-tasks/{id}/due-date', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
        ['name' => 'room_task#roomStats', 'url' => '/api/v1/room-tasks/room/{roomId}/stats', 'verb' => 'GET', 'requirements' => ['roomId' => '\d+']],

        // Completion correction API (CS-PLAN-070 WP3 §3.6): versioned,
        // receipt-protected reads of one completion and targeted undo /
        // attribution — the exact correction contract (C3e/C3f).
        ['name' => 'completion#show', 'url' => '/api/v1/completions/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
        ['name' => 'completion#undo', 'url' => '/api/v1/completions/{id}/undo', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
        ['name' => 'completion#attribute', 'url' => '/api/v1/completions/{id}/attribution', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],

        // Pruned 2026-08-21 (CS-PLAN-040): `userTasks` read only the legacy
        // single-assignee column (misleading since multi-assignee) and
        // `userStats` carried the CR-SVC-31-flawed completionRate — both had
        // no consumer. `user#stats` was fully dead (every field already in
        // the GET /users serialization).

        // Pruned 2026-08-29 (CS-PLAN-055, 39 routes — guard in RouteMatrixTest):
        // dead create aliases (floor/room#createAlias/#createExplicit), no-
        // consumer reads (floor/room#index/#show, task/room_task#show, task
        // #byCategory/#search/#categories/#myPrivate/#daily/#delete, room
        // #needingCleaning, user#update), dead room-task variants (#assign/
        // #unassign/#addAssignee/#removeAssignee/#dueWithinDays/#overdueClaims/
        // #deactivate), test-only lifecycle/game routes (task#complete/#skip/
        // #reschedule, game#leaderboard), the checklist #initializeProgress/#
        // getCompleteChecklist pair, the whole task-sync surface and the
        // reward-config HTTP surface. Re-add a route only when a consumer
        // exists.

        // Game API
        ['name' => 'game#state', 'url' => '/api/v1/game/state', 'verb' => 'GET'],
        ['name' => 'game#medals', 'url' => '/api/v1/game/medals', 'verb' => 'GET'],
        ['name' => 'game#checkMedals', 'url' => '/api/v1/game/check-medals', 'verb' => 'POST'],

        // User API
        ['name' => 'user#index', 'url' => '/api/v1/users', 'verb' => 'GET'],
        ['name' => 'user#current', 'url' => '/api/v1/users/current', 'verb' => 'GET'],
        ['name' => 'user#invite', 'url' => '/api/v1/users/invite', 'verb' => 'POST'],
        ['name' => 'user#remove', 'url' => '/api/v1/users/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],
        ['name' => 'user#updateRole', 'url' => '/api/v1/users/{id}/role', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
        ['name' => 'user#titles', 'url' => '/api/v1/users/{id}/titles', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
        // Pruned 2026-09-27 (CS-PLAN-070 WP4, Q-075-02 A): `user#selectTitle`
        // retired with the multi-pack surface — `currentTitle` is always the
        // highest qualifying tier of the single retained rhythm series.

        // Consent-filtered ranking API (CS-PLAN-070 WP4, C5i/C5l/D10b):
        // visible entries are effective consents only; consent/withdraw are
        // self-only mutations activating the next full week.
        ['name' => 'ranking#index', 'url' => '/api/v1/ranking', 'verb' => 'GET'],
        ['name' => 'ranking#consent', 'url' => '/api/v1/ranking/consent', 'verb' => 'POST'],
        ['name' => 'ranking#withdraw', 'url' => '/api/v1/ranking/withdraw', 'verb' => 'POST'],

        // Self-only personal preferences API (CS-PLAN-070 WP5, C5h):
        // `/preferences/me` is the ONLY surface — no owner read/write of
        // another member's preferences exists by design.
        ['name' => 'preference#show', 'url' => '/api/v1/preferences/me', 'verb' => 'GET'],
        ['name' => 'preference#update', 'url' => '/api/v1/preferences/me', 'verb' => 'PUT'],

        // Reward catalog API (CS-PLAN-070 WP7, CS-PLAN-078 §3.3):
        // owner-administered catalog, optional household gate default-off.
        // Static single-segment routes precede the `{id}` route (shadowing
        // rule). PUT /house/catalog lives in the House API block above.
        ['name' => 'reward#index', 'url' => '/api/v1/rewards', 'verb' => 'GET'],
        ['name' => 'reward#create', 'url' => '/api/v1/rewards', 'verb' => 'POST'],
        ['name' => 'reward#update', 'url' => '/api/v1/rewards/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],

        // Reward request lifecycle API (WP7 C4): viewer-safe reads plus the
        // receipt-protected approve/reject/cancel/fulfill/correct commands.
        ['name' => 'reward_request#index', 'url' => '/api/v1/reward-requests', 'verb' => 'GET'],
        ['name' => 'reward_request#create', 'url' => '/api/v1/reward-requests', 'verb' => 'POST'],
        ['name' => 'reward_request#show', 'url' => '/api/v1/reward-requests/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
        ['name' => 'reward_request#approve', 'url' => '/api/v1/reward-requests/{id}/approve', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
        ['name' => 'reward_request#reject', 'url' => '/api/v1/reward-requests/{id}/reject', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
        ['name' => 'reward_request#cancel', 'url' => '/api/v1/reward-requests/{id}/cancel', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
        ['name' => 'reward_request#fulfill', 'url' => '/api/v1/reward-requests/{id}/fulfill', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
        ['name' => 'reward_request#correct', 'url' => '/api/v1/reward-requests/{id}/correct', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],

        // Scheduling API
        ['name' => 'scheduling#getPreferences', 'url' => '/api/v1/scheduling/preferences', 'verb' => 'GET'],
        ['name' => 'scheduling#updatePreferences', 'url' => '/api/v1/scheduling/preferences', 'verb' => 'PUT'],
        ['name' => 'scheduling#getMemberAvailability', 'url' => '/api/v1/scheduling/members', 'verb' => 'GET'],
        ['name' => 'scheduling#updateMemberAvailability', 'url' => '/api/v1/scheduling/members/{userId}', 'verb' => 'PUT', 'requirements' => ['userId' => '[a-zA-Z0-9_.@-]+']],
        // CS-PLAN-065 (ADR-012): the taskCount-only advice contract was
        // replaced on the same URL by the real-task owner preview; apply is
        // the one atomic confirmation. No legacy fallback.
        ['name' => 'scheduling#preview', 'url' => '/api/v1/scheduling/suggestions', 'verb' => 'POST'],
        ['name' => 'scheduling#apply', 'url' => '/api/v1/scheduling/apply', 'verb' => 'POST'],

        // Occurrence agreement API (CS-PLAN-073 WP2 §3.5): owner-recorded
        // amendments, catch-up and restart as CommandService commands.
        // `{id}` is the public occurrence key `<scheduleRevisionId>:<ordinal>`.
        ['name' => 'occurrence#amend', 'url' => '/api/v1/occurrences/{id}/amend', 'verb' => 'POST', 'requirements' => ['id' => '\d+:\d+']],
        ['name' => 'occurrence#catchUp', 'url' => '/api/v1/occurrences/{id}/catch-up', 'verb' => 'POST', 'requirements' => ['id' => '\d+:\d+']],
        ['name' => 'occurrence#restart', 'url' => '/api/v1/occurrences/{id}/restart', 'verb' => 'POST', 'requirements' => ['id' => '\d+:\d+']],

        // Pause API (CS-PLAN-073 WP2 §3.4/§3.5): revisioned pause lifecycle.
        ['name' => 'pause#index', 'url' => '/api/v1/pauses', 'verb' => 'GET'],
        ['name' => 'pause#create', 'url' => '/api/v1/pauses', 'verb' => 'POST'],
        ['name' => 'pause#recognizeIllness', 'url' => '/api/v1/pauses/recognize-illness', 'verb' => 'POST'],
        ['name' => 'pause#approve', 'url' => '/api/v1/pauses/{id}/approve', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
        ['name' => 'pause#reject', 'url' => '/api/v1/pauses/{id}/reject', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
        ['name' => 'pause#update', 'url' => '/api/v1/pauses/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],

        // Smart Scheduler API — removed 2026-07-28 (CS-016 review: the
        // SmartSchedulerController had no AuthorizationService, so all 7
        // endpoints were reachable by any authenticated user). The whole
        // smart-scheduler cluster + orphan services were deleted in
        // CS-PLAN-025 (CR-SVC-08); the UI consumes only scheduling#*.
        // Re-add with AuthorizationService gating if the smart-schedule UX
        // is revived. See docs/adr/ADR-005-smart-scheduling.md (Superseded).

        // Template API — removed (CS-PLAN-027 D7): the dead JSON
        // TemplateController endpoints were superseded by the setup catalog
        // (setup#catalog) and the per-room from-template endpoint
        // (room_task#fromTemplate). The v2 catalog lives in TemplateService.

        // Checklist API
        ['name' => 'checklist#getTaskItems', 'url' => '/api/v1/checklists/task/{taskId}', 'verb' => 'GET', 'requirements' => ['taskId' => '\d+']],
        ['name' => 'checklist#addTaskItem', 'url' => '/api/v1/checklists/task/{taskId}', 'verb' => 'POST', 'requirements' => ['taskId' => '\d+']],
        ['name' => 'checklist#updateTaskItem', 'url' => '/api/v1/checklists/item/{itemId}', 'verb' => 'PUT', 'requirements' => ['itemId' => '\d+']],
        ['name' => 'checklist#deleteTaskItem', 'url' => '/api/v1/checklists/item/{itemId}', 'verb' => 'DELETE', 'requirements' => ['itemId' => '\d+']],
        ['name' => 'checklist#reorderTaskItems', 'url' => '/api/v1/checklists/reorder', 'verb' => 'POST'],
        ['name' => 'checklist#getRoomTaskProgress', 'url' => '/api/v1/checklists/progress/{roomTaskId}', 'verb' => 'GET', 'requirements' => ['roomTaskId' => '\d+']],
        ['name' => 'checklist#toggleItem', 'url' => '/api/v1/checklists/toggle/{roomTaskId}/{itemId}', 'verb' => 'POST', 'requirements' => ['roomTaskId' => '\d+', 'itemId' => '\d+']],

        // Claiming Overdue Tasks API (Feature 7)
        // filteredDaily lives in the Room Task API block above — it must
        // precede the `{id}` routes (shadowing rule).

        // Test Data API (only available in test mode — see
        // docs/standards/guidelines/security.md for the test-only contract;
        // never set NEXTCLOUD_TEST_MODE in production).
        ['name' => 'test#profiles', 'url' => '/api/v1/test/profiles', 'verb' => 'GET'],
        ['name' => 'test#seed', 'url' => '/api/v1/test/seed/{profile}', 'verb' => 'POST', 'requirements' => ['profile' => '[a-z0-9-]+']],
        ['name' => 'test#reset', 'url' => '/api/v1/test/reset', 'verb' => 'POST'],

        // Minigame API
        ['name' => 'minigame#leaderboard', 'url' => '/api/v1/minigames/{gameId}/leaderboard', 'verb' => 'GET', 'requirements' => ['gameId' => '[a-z0-9-]+']],
        ['name' => 'minigame#weeklyLeaderboard', 'url' => '/api/v1/minigames/{gameId}/leaderboard/weekly', 'verb' => 'GET', 'requirements' => ['gameId' => '[a-z0-9-]+']],
        ['name' => 'minigame#saveScore', 'url' => '/api/v1/minigames/{gameId}/scores', 'verb' => 'POST', 'requirements' => ['gameId' => '[a-z0-9-]+']],
        ['name' => 'minigame#personalBest', 'url' => '/api/v1/minigames/{gameId}/personal-best', 'verb' => 'GET', 'requirements' => ['gameId' => '[a-z0-9-]+']],
        ['name' => 'minigame#userRank', 'url' => '/api/v1/minigames/{gameId}/rank', 'verb' => 'GET', 'requirements' => ['gameId' => '[a-z0-9-]+']],
        ['name' => 'minigame#recentScores', 'url' => '/api/v1/minigames/{gameId}/recent', 'verb' => 'GET', 'requirements' => ['gameId' => '[a-z0-9-]+']],
        // CS-PLAN-070 WP3 (T10/D7d): the boost catalog/purchase and Coin
        // balance routes are removed — the arcade is scores-only and never
        // touches the family wallet.

        // Reward Config API — removed (CS-PLAN-055): `reward_config#index` /
        // `reward_config#update` had no production consumer; the two E2E
        // specs that used them were reworked onto test-seed knobs. The
        // service itself stays (GameLogic/House/StreakRestock/Title read it).

        // Setup Wizard API (CS-PLAN-027) — first-run house setup
        ['name' => 'setup#catalog', 'url' => '/api/v1/setup/catalog', 'verb' => 'GET'],
        ['name' => 'setup#apply', 'url' => '/api/v1/setup/apply', 'verb' => 'POST'],
    ]
];
