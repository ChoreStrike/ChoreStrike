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
        
        // Floor API
        ['name' => 'floor#index', 'url' => '/api/v1/floors', 'verb' => 'GET'],
        ['name' => 'floor#show', 'url' => '/api/v1/floors/{id}', 'verb' => 'GET'],
        ['name' => 'floor#create', 'url' => '/api/v1/floors', 'verb' => 'POST'],
        ['name' => 'floor#update', 'url' => '/api/v1/floors/{id}', 'verb' => 'PUT'],
        ['name' => 'floor#delete', 'url' => '/api/v1/floors/{id}', 'verb' => 'DELETE'],
        ['name' => 'floor#reorder', 'url' => '/api/v1/floors/reorder', 'verb' => 'POST'],
        ['name' => 'floor#withRooms', 'url' => '/api/v1/floors/with-rooms', 'verb' => 'GET'],
        
        // Room API
        ['name' => 'room#index', 'url' => '/api/v1/rooms', 'verb' => 'GET'],
        ['name' => 'room#show', 'url' => '/api/v1/rooms/{id}', 'verb' => 'GET'],
        ['name' => 'room#create', 'url' => '/api/v1/rooms', 'verb' => 'POST'],
        ['name' => 'room#update', 'url' => '/api/v1/rooms/{id}', 'verb' => 'PUT'],
        ['name' => 'room#delete', 'url' => '/api/v1/rooms/{id}', 'verb' => 'DELETE'],
        ['name' => 'room#updateCleanliness', 'url' => '/api/v1/rooms/{id}/cleanliness', 'verb' => 'PUT'],
        ['name' => 'room#needingCleaning', 'url' => '/api/v1/rooms/needing-cleaning', 'verb' => 'GET'],
        ['name' => 'room#addTask', 'url' => '/api/v1/rooms/{roomId}/tasks', 'verb' => 'POST'],
        ['name' => 'room#removeTask', 'url' => '/api/v1/rooms/{roomId}/tasks/{taskId}', 'verb' => 'DELETE'],
        ['name' => 'room#completeTask', 'url' => '/api/v1/rooms/complete-task/{roomTaskId}', 'verb' => 'POST'],
        
        // Task API
        ['name' => 'task#index', 'url' => '/api/v1/tasks', 'verb' => 'GET'],
        ['name' => 'task#show', 'url' => '/api/v1/tasks/{id}', 'verb' => 'GET'],
        ['name' => 'task#create', 'url' => '/api/v1/tasks', 'verb' => 'POST'],
        ['name' => 'task#update', 'url' => '/api/v1/tasks/{id}', 'verb' => 'PUT'],
        ['name' => 'task#delete', 'url' => '/api/v1/tasks/{id}', 'verb' => 'DELETE'],
        ['name' => 'task#templates', 'url' => '/api/v1/tasks/templates', 'verb' => 'GET'],
        ['name' => 'task#createFromTemplate', 'url' => '/api/v1/tasks/create-from-template/{templateId}', 'verb' => 'POST'],
        ['name' => 'task#byCategory', 'url' => '/api/v1/tasks/category/{category}', 'verb' => 'GET'],
        ['name' => 'task#search', 'url' => '/api/v1/tasks/search', 'verb' => 'GET'],
        ['name' => 'task#categories', 'url' => '/api/v1/tasks/categories', 'verb' => 'GET'],
        ['name' => 'task#createDefaultTemplates', 'url' => '/api/v1/tasks/create-default-templates', 'verb' => 'POST'],
        
        // Room Task API
        ['name' => 'room_task#index', 'url' => '/api/v1/room-tasks', 'verb' => 'GET'],
        ['name' => 'room_task#show', 'url' => '/api/v1/room-tasks/{id}', 'verb' => 'GET'],
        ['name' => 'room_task#create', 'url' => '/api/v1/room-tasks', 'verb' => 'POST'],
        ['name' => 'room_task#assign', 'url' => '/api/v1/room-tasks/{id}/assign', 'verb' => 'POST'],
        ['name' => 'room_task#unassign', 'url' => '/api/v1/room-tasks/{id}/unassign', 'verb' => 'POST'],
        ['name' => 'room_task#deactivate', 'url' => '/api/v1/room-tasks/{id}/deactivate', 'verb' => 'POST'],
        ['name' => 'room_task#reactivate', 'url' => '/api/v1/room-tasks/{id}/reactivate', 'verb' => 'POST'],
        ['name' => 'room_task#updateDueDate', 'url' => '/api/v1/room-tasks/{id}/due-date', 'verb' => 'PUT'],
        ['name' => 'room_task#complete', 'url' => '/api/v1/room-tasks/{id}/complete', 'verb' => 'POST'],
        ['name' => 'room_task#delete', 'url' => '/api/v1/room-tasks/{id}', 'verb' => 'DELETE'],
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
        
        // User API
        ['name' => 'user#index', 'url' => '/api/v1/users', 'verb' => 'GET'],
        ['name' => 'user#invite', 'url' => '/api/v1/users/invite', 'verb' => 'POST'],
        ['name' => 'user#remove', 'url' => '/api/v1/users/{id}', 'verb' => 'DELETE'],
        
        // Task Sync API
        ['name' => 'task_sync#complete', 'url' => '/api/v1/sync/complete/{roomTaskId}', 'verb' => 'POST'],
        ['name' => 'task_sync#syncFromNextcloud', 'url' => '/api/v1/sync/from-nextcloud', 'verb' => 'POST'],
        ['name' => 'task_sync#getReminders', 'url' => '/api/v1/sync/reminders/{roomTaskId}', 'verb' => 'GET'],
        ['name' => 'task_sync#getPendingSyncs', 'url' => '/api/v1/sync/pending', 'verb' => 'GET'],
        ['name' => 'task_sync#markAsSynced', 'url' => '/api/v1/sync/mark-synced/{completedTaskId}', 'verb' => 'POST'],
        ['name' => 'task_sync#linkTask', 'url' => '/api/v1/sync/link/{roomTaskId}', 'verb' => 'POST'],
        
        // Scheduling API
        ['name' => 'scheduling#getPreferences', 'url' => '/api/v1/scheduling/preferences', 'verb' => 'GET'],
        ['name' => 'scheduling#updateEnergy', 'url' => '/api/v1/scheduling/energy', 'verb' => 'PUT'],
        ['name' => 'scheduling#updateAvailability', 'url' => '/api/v1/scheduling/availability', 'verb' => 'PUT'],
        ['name' => 'scheduling#getMemberAvailability', 'url' => '/api/v1/scheduling/members', 'verb' => 'GET'],
        ['name' => 'scheduling#updateMemberAvailability', 'url' => '/api/v1/scheduling/members/{userId}', 'verb' => 'PUT'],
        ['name' => 'scheduling#getSuggestions', 'url' => '/api/v1/scheduling/suggestions', 'verb' => 'POST'],
    ]
];
