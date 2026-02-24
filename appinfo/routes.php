<?php

return [
    'routes' => [
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
        
        // House API
        ['name' => 'house#get', 'url' => '/api/v1/house', 'verb' => 'GET'],
        ['name' => 'house#update', 'url' => '/api/v1/house', 'verb' => 'PUT'],
        
        // Floor API
        ['name' => 'floor#index', 'url' => '/api/v1/floors', 'verb' => 'GET'],
        ['name' => 'floor#create', 'url' => '/api/v1/floors', 'verb' => 'POST'],
        ['name' => 'floor#update', 'url' => '/api/v1/floors/{id}', 'verb' => 'PUT'],
        ['name' => 'floor#delete', 'url' => '/api/v1/floors/{id}', 'verb' => 'DELETE'],
        
        // Room API
        ['name' => 'room#index', 'url' => '/api/v1/rooms', 'verb' => 'GET'],
        ['name' => 'room#show', 'url' => '/api/v1/rooms/{id}', 'verb' => 'GET'],
        ['name' => 'room#create', 'url' => '/api/v1/rooms', 'verb' => 'POST'],
        ['name' => 'room#update', 'url' => '/api/v1/rooms/{id}', 'verb' => 'PUT'],
        ['name' => 'room#delete', 'url' => '/api/v1/rooms/{id}', 'verb' => 'DELETE'],
        
        // Task API
        ['name' => 'task#index', 'url' => '/api/v1/tasks', 'verb' => 'GET'],
        ['name' => 'task#show', 'url' => '/api/v1/tasks/{id}', 'verb' => 'GET'],
        ['name' => 'task#create', 'url' => '/api/v1/tasks', 'verb' => 'POST'],
        ['name' => 'task#update', 'url' => '/api/v1/tasks/{id}', 'verb' => 'PUT'],
        ['name' => 'task#delete', 'url' => '/api/v1/tasks/{id}', 'verb' => 'DELETE'],
        ['name' => 'task#complete', 'url' => '/api/v1/tasks/{id}/complete', 'verb' => 'POST'],
        ['name' => 'task#templates', 'url' => '/api/v1/tasks/templates', 'verb' => 'GET'],
        
        // Room Task API
        ['name' => 'room_task#index', 'url' => '/api/v1/room-tasks', 'verb' => 'GET'],
        ['name' => 'room_task#create', 'url' => '/api/v1/room-tasks', 'verb' => 'POST'],
        ['name' => 'room_task#update', 'url' => '/api/v1/room-tasks/{id}', 'verb' => 'PUT'],
        ['name' => 'room_task#delete', 'url' => '/api/v1/room-tasks/{id}', 'verb' => 'DELETE'],
        ['name' => 'room_task#complete', 'url' => '/api/v1/room-tasks/{id}/complete', 'verb' => 'POST'],
        
        // Game API
        ['name' => 'game#state', 'url' => '/api/v1/game/state', 'verb' => 'GET'],
        ['name' => 'game#leaderboard', 'url' => '/api/v1/game/leaderboard', 'verb' => 'GET'],
        ['name' => 'game#medals', 'url' => '/api/v1/game/medals', 'verb' => 'GET'],
        
        // User API
        ['name' => 'user#index', 'url' => '/api/v1/users', 'verb' => 'GET'],
        ['name' => 'user#invite', 'url' => '/api/v1/users/invite', 'verb' => 'POST'],
        ['name' => 'user#remove', 'url' => '/api/v1/users/{id}', 'verb' => 'DELETE'],
    ]
];
