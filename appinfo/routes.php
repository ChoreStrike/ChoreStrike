<?php

return [
    'routes' => [
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
        
        // House API
        ['name' => 'house#get', 'url' => '/api/v1/house', 'verb' => 'GET'],
        ['name' => 'house#update', 'url' => '/api/v1/house', 'verb' => 'PUT'],
        ['name' => 'house#stats', 'url' => '/api/v1/house/stats', 'verb' => 'GET'],
        ['name' => 'house#health', 'url' => '/api/v1/house/health', 'verb' => 'GET'],
        ['name' => 'house#reset', 'url' => '/api/v1/house/reset', 'verb' => 'POST'],
        ['name' => 'floor#createAlias', 'url' => '/api/v1/floor-create', 'verb' => 'POST'],
        ['name' => 'room#createAlias', 'url' => '/api/v1/room-create', 'verb' => 'POST'],
        
        // Floor API
        ['name' => 'floor#create', 'url' => '/api/v1/floors', 'verb' => 'POST'],
        ['name' => 'floor#index', 'url' => '/api/v1/floors', 'verb' => 'GET'],
        ['name' => 'floor#createExplicit', 'url' => '/api/v1/floors/create', 'verb' => 'POST'],
        ['name' => 'floor#withRooms', 'url' => '/api/v1/floors/with-rooms', 'verb' => 'GET'],
        ['name' => 'floor#reorder', 'url' => '/api/v1/floors/reorder', 'verb' => 'POST'],
        ['name' => 'floor#update', 'url' => '/api/v1/floors/{id}', 'verb' => 'PUT'],
        ['name' => 'floor#delete', 'url' => '/api/v1/floors/{id}', 'verb' => 'DELETE'],
        ['name' => 'floor#show', 'url' => '/api/v1/floors/{id}', 'verb' => 'GET'],
        
        // Room API
        ['name' => 'room#create', 'url' => '/api/v1/rooms', 'verb' => 'POST'],
        ['name' => 'room#index', 'url' => '/api/v1/rooms', 'verb' => 'GET'],
        ['name' => 'room#show', 'url' => '/api/v1/rooms/{id}', 'verb' => 'GET'],
        ['name' => 'room#createExplicit', 'url' => '/api/v1/rooms/create', 'verb' => 'POST'],
        ['name' => 'room#update', 'url' => '/api/v1/rooms/{id}', 'verb' => 'PUT'],
        ['name' => 'room#delete', 'url' => '/api/v1/rooms/{id}', 'verb' => 'DELETE'],
        ['name' => 'room#updateCleanliness', 'url' => '/api/v1/rooms/{id}/cleanliness', 'verb' => 'PUT'],
        ['name' => 'room#needingCleaning', 'url' => '/api/v1/rooms/needing-cleaning', 'verb' => 'GET'],
        
        // Task API
        ['name' => 'task#index', 'url' => '/api/v1/tasks', 'verb' => 'GET'],
        ['name' => 'task#show', 'url' => '/api/v1/tasks/{id}', 'verb' => 'GET'],
        ['name' => 'task#create', 'url' => '/api/v1/tasks', 'verb' => 'POST'],
        ['name' => 'task#update', 'url' => '/api/v1/tasks/{id}', 'verb' => 'PUT'],
        ['name' => 'task#delete', 'url' => '/api/v1/tasks/{id}', 'verb' => 'DELETE'],
        ['name' => 'task#templates', 'url' => '/api/v1/tasks/templates', 'verb' => 'GET'],
        ['name' => 'task#createFromTemplate', 'url' => '/api/v1/tasks/create-from-template/{templateId}', 'verb' => 'POST'],
        ['name' => 'task#createDefaultTemplates', 'url' => '/api/v1/tasks/create-default-templates', 'verb' => 'POST'],
        ['name' => 'task#byCategory', 'url' => '/api/v1/tasks/category/{category}', 'verb' => 'GET'],
        ['name' => 'task#search', 'url' => '/api/v1/tasks/search', 'verb' => 'GET'],
        ['name' => 'task#categories', 'url' => '/api/v1/tasks/categories', 'verb' => 'GET'],
        ['name' => 'task#daily', 'url' => '/api/v1/tasks/daily', 'verb' => 'GET'],
        ['name' => 'task#complete', 'url' => '/api/v1/tasks/{id}/complete', 'verb' => 'PUT'],
        ['name' => 'task#uncomplete', 'url' => '/api/v1/tasks/{id}/uncomplete', 'verb' => 'PUT'],
        ['name' => 'task#skip', 'url' => '/api/v1/tasks/{id}/skip', 'verb' => 'PUT'],
        ['name' => 'task#reschedule', 'url' => '/api/v1/tasks/{id}/reschedule', 'verb' => 'PUT'],
        
        // Room Task API
        ['name' => 'room_task#index', 'url' => '/api/v1/room-tasks', 'verb' => 'GET'],
        ['name' => 'room_task#show', 'url' => '/api/v1/room-tasks/{id}', 'verb' => 'GET'],
        ['name' => 'room_task#create', 'url' => '/api/v1/room-tasks', 'verb' => 'POST'],
        ['name' => 'room_task#delete', 'url' => '/api/v1/room-tasks/{id}', 'verb' => 'DELETE'],
        ['name' => 'room_task#complete', 'url' => '/api/v1/room-tasks/{id}/complete', 'verb' => 'POST'],
        ['name' => 'room_task#assign', 'url' => '/api/v1/room-tasks/{id}/assign', 'verb' => 'POST'],
        ['name' => 'room_task#unassign', 'url' => '/api/v1/room-tasks/{id}/unassign', 'verb' => 'POST'],
        ['name' => 'room_task#deactivate', 'url' => '/api/v1/room-tasks/{id}/deactivate', 'verb' => 'POST'],
        ['name' => 'room_task#reactivate', 'url' => '/api/v1/room-tasks/{id}/reactivate', 'verb' => 'POST'],
        ['name' => 'room_task#updateDueDate', 'url' => '/api/v1/room-tasks/{id}/due-date', 'verb' => 'PUT'],
        ['name' => 'room_task#overdue', 'url' => '/api/v1/room-tasks/overdue', 'verb' => 'GET'],
        ['name' => 'room_task#dueToday', 'url' => '/api/v1/room-tasks/due-today', 'verb' => 'GET'],
        ['name' => 'room_task#dueWithinDays', 'url' => '/api/v1/room-tasks/due-within-days', 'verb' => 'GET'],
        ['name' => 'room_task#userTasks', 'url' => '/api/v1/room-tasks/user/{userId}', 'verb' => 'GET'],
        ['name' => 'room_task#roomStats', 'url' => '/api/v1/room-tasks/room/{roomId}/stats', 'verb' => 'GET'],
        ['name' => 'room_task#userStats', 'url' => '/api/v1/room-tasks/user/{userId}/stats', 'verb' => 'GET'],
        
        // Game API
        ['name' => 'game#state', 'url' => '/api/v1/game/state', 'verb' => 'GET'],
        ['name' => 'game#leaderboard', 'url' => '/api/v1/game/leaderboard', 'verb' => 'GET'],
        ['name' => 'game#medals', 'url' => '/api/v1/game/medals', 'verb' => 'GET'],
        ['name' => 'game#checkMedals', 'url' => '/api/v1/game/check-medals', 'verb' => 'POST'],
        
        // User API
        ['name' => 'user#index', 'url' => '/api/v1/users', 'verb' => 'GET'],
        ['name' => 'user#current', 'url' => '/api/v1/users/current', 'verb' => 'GET'],
        ['name' => 'user#invite', 'url' => '/api/v1/users/invite', 'verb' => 'POST'],
        ['name' => 'user#remove', 'url' => '/api/v1/users/{id}', 'verb' => 'DELETE'],
        ['name' => 'user#updateRole', 'url' => '/api/v1/users/{id}/role', 'verb' => 'PUT'],
        ['name' => 'user#stats', 'url' => '/api/v1/users/{id}/stats', 'verb' => 'GET'],

        // Scheduling API
        ['name' => 'scheduling#getPreferences', 'url' => '/api/v1/scheduling/preferences', 'verb' => 'GET'],
        ['name' => 'scheduling#updateEnergy', 'url' => '/api/v1/scheduling/energy', 'verb' => 'PUT'],
        ['name' => 'scheduling#updateAvailability', 'url' => '/api/v1/scheduling/availability', 'verb' => 'PUT'],
        ['name' => 'scheduling#getMemberAvailability', 'url' => '/api/v1/scheduling/members', 'verb' => 'GET'],
        ['name' => 'scheduling#updateMemberAvailability', 'url' => '/api/v1/scheduling/members/{userId}', 'verb' => 'PUT'],
        ['name' => 'scheduling#getSuggestions', 'url' => '/api/v1/scheduling/suggestions', 'verb' => 'POST'],

        // Smart Scheduler API (Phase 2)
        ['name' => 'smart_scheduler#getSchedulePreview', 'url' => '/api/v1/smart-schedule/preview', 'verb' => 'GET'],
        ['name' => 'smart_scheduler#generateSchedule', 'url' => '/api/v1/smart-schedule/generate', 'verb' => 'POST'],
        ['name' => 'smart_scheduler#getMemberAvailability', 'url' => '/api/v1/smart-schedule/availability', 'verb' => 'GET'],
        ['name' => 'smart_scheduler#updateMyAvailability', 'url' => '/api/v1/smart-schedule/availability', 'verb' => 'PUT'],
        ['name' => 'smart_scheduler#updateMyEnergyBudget', 'url' => '/api/v1/smart-schedule/energy-budget', 'verb' => 'PUT'],
        ['name' => 'smart_scheduler#getSchedulingSuggestions', 'url' => '/api/v1/smart-schedule/suggestions', 'verb' => 'GET'],
        ['name' => 'smart_scheduler#applySuggestion', 'url' => '/api/v1/smart-schedule/apply', 'verb' => 'POST'],

        // Template API
        ['name' => 'template#index', 'url' => '/api/v1/templates', 'verb' => 'GET'],
        ['name' => 'template#categories', 'url' => '/api/v1/templates/categories', 'verb' => 'GET'],
        ['name' => 'template#byCategory', 'url' => '/api/v1/templates/category/{category}', 'verb' => 'GET'],
        ['name' => 'template#byRoomType', 'url' => '/api/v1/templates/room-type/{roomType}', 'verb' => 'GET'],
        ['name' => 'template#search', 'url' => '/api/v1/templates/search', 'verb' => 'GET'],
        ['name' => 'template#suggestions', 'url' => '/api/v1/templates/suggestions/{roomName}', 'verb' => 'GET'],
        ['name' => 'template#import', 'url' => '/api/v1/templates/import/{templateId}', 'verb' => 'POST'],
        ['name' => 'template#bulkImport', 'url' => '/api/v1/templates/bulk-import', 'verb' => 'POST'],
        ['name' => 'template#statistics', 'url' => '/api/v1/templates/statistics', 'verb' => 'GET'],

        // Checklist API
        ['name' => 'checklist#getTaskItems', 'url' => '/api/v1/checklists/task/{taskId}', 'verb' => 'GET'],
        ['name' => 'checklist#addTaskItem', 'url' => '/api/v1/checklists/task/{taskId}', 'verb' => 'POST'],
        ['name' => 'checklist#updateTaskItem', 'url' => '/api/v1/checklists/item/{itemId}', 'verb' => 'PUT'],
        ['name' => 'checklist#deleteTaskItem', 'url' => '/api/v1/checklists/item/{itemId}', 'verb' => 'DELETE'],
        ['name' => 'checklist#reorderTaskItems', 'url' => '/api/v1/checklists/reorder', 'verb' => 'POST'],
        ['name' => 'checklist#getRoomTaskProgress', 'url' => '/api/v1/checklists/progress/{roomTaskId}', 'verb' => 'GET'],
        ['name' => 'checklist#toggleItem', 'url' => '/api/v1/checklists/toggle/{roomTaskId}/{itemId}', 'verb' => 'POST'],
        ['name' => 'checklist#initializeProgress', 'url' => '/api/v1/checklists/initialize/{roomTaskId}', 'verb' => 'POST'],
        ['name' => 'checklist#getCompleteChecklist', 'url' => '/api/v1/checklists/complete/{roomTaskId}', 'verb' => 'GET'],

        // Task Sync API
        ['name' => 'task_sync#complete', 'url' => '/api/v1/task-sync/complete', 'verb' => 'POST'],
        ['name' => 'task_sync#syncFromNextcloud', 'url' => '/api/v1/task-sync/sync-from-nextcloud', 'verb' => 'POST'],
        ['name' => 'task_sync#getReminders', 'url' => '/api/v1/task-sync/{roomTaskId}/reminders', 'verb' => 'GET'],
        ['name' => 'task_sync#getPendingSyncs', 'url' => '/api/v1/task-sync/pending', 'verb' => 'GET'],
        ['name' => 'task_sync#markAsSynced', 'url' => '/api/v1/task-sync/{completedTaskId}/mark-synced', 'verb' => 'POST'],
        ['name' => 'task_sync#linkTask', 'url' => '/api/v1/task-sync/link', 'verb' => 'POST'],

        // Group Assignment API (Feature 5)
        ['name' => 'room_task#assignToGroup', 'url' => '/api/v1/room-tasks/{id}/assign-to-group', 'verb' => 'POST'],
        ['name' => 'room_task#claimForUser', 'url' => '/api/v1/room-tasks/{id}/claim', 'verb' => 'POST'],
        ['name' => 'room_task#unclaim', 'url' => '/api/v1/room-tasks/{id}/unclaim', 'verb' => 'POST'],
        ['name' => 'room_task#groupTasks', 'url' => '/api/v1/room-tasks/group/{groupId}', 'verb' => 'GET'],
        ['name' => 'room_task#visibleToUser', 'url' => '/api/v1/room-tasks/visible-to-user', 'verb' => 'GET'],

        // Multi-User Teamwork API (Feature 6)
        ['name' => 'room_task#joinTask', 'url' => '/api/v1/room-tasks/{id}/join', 'verb' => 'POST'],
        ['name' => 'room_task#leaveTask', 'url' => '/api/v1/room-tasks/{id}/leave', 'verb' => 'POST'],
        ['name' => 'room_task#getParticipants', 'url' => '/api/v1/room-tasks/{id}/participants', 'verb' => 'GET'],
        ['name' => 'room_task#completeWithTeam', 'url' => '/api/v1/room-tasks/{id}/complete-with-team', 'verb' => 'POST'],

        // Claiming Overdue Tasks API (Feature 7)
        ['name' => 'room_task#claimOverdue', 'url' => '/api/v1/room-tasks/{id}/claim-overdue', 'verb' => 'POST'],
        ['name' => 'room_task#overdueClaims', 'url' => '/api/v1/room-tasks/overdue-claims', 'verb' => 'GET'],
        ['name' => 'room_task#claimHistory', 'url' => '/api/v1/room-tasks/{id}/claim-history', 'verb' => 'GET'],
        ['name' => 'room_task#claimStats', 'url' => '/api/v1/users/{userId}/claim-stats', 'verb' => 'GET'],

        // Test Data API (only available in test mode)
        ['name' => 'test#profiles', 'url' => '/api/v1/test/profiles', 'verb' => 'GET'],
        ['name' => 'test#seed', 'url' => '/api/v1/test/seed/{profile}', 'verb' => 'GET'],
        ['name' => 'test#reset', 'url' => '/api/v1/test/reset', 'verb' => 'GET'],

        // Minigame API
        ['name' => 'minigame#leaderboard', 'url' => '/api/v1/minigames/{gameId}/leaderboard', 'verb' => 'GET'],
        ['name' => 'minigame#weeklyLeaderboard', 'url' => '/api/v1/minigames/{gameId}/leaderboard/weekly', 'verb' => 'GET'],
        ['name' => 'minigame#saveScore', 'url' => '/api/v1/minigames/{gameId}/scores', 'verb' => 'POST'],
        ['name' => 'minigame#personalBest', 'url' => '/api/v1/minigames/{gameId}/personal-best', 'verb' => 'GET'],
        ['name' => 'minigame#userRank', 'url' => '/api/v1/minigames/{gameId}/rank', 'verb' => 'GET'],
        ['name' => 'minigame#recentScores', 'url' => '/api/v1/minigames/{gameId}/recent', 'verb' => 'GET'],
        ['name' => 'minigame#boosts', 'url' => '/api/v1/minigames/boosts', 'verb' => 'GET'],
        ['name' => 'minigame#purchaseBoost', 'url' => '/api/v1/minigames/boosts/{boostId}/purchase', 'verb' => 'POST'],
        ['name' => 'minigame#sparks', 'url' => '/api/v1/minigames/sparks', 'verb' => 'GET'],

        // Reward Config API
        ['name' => 'reward_config#index', 'url' => '/api/v1/reward-config', 'verb' => 'GET'],
        ['name' => 'reward_config#update', 'url' => '/api/v1/reward-config', 'verb' => 'PUT'],
    ]
];
