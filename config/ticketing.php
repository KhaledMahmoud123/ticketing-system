<?php

return [
    'table' => 'tickets',
    'middleware' => ['web', 'auth'],
    'api_middleware' => [
        'auth:sanctum',
        \App\Http\Middleware\CheckBearerToken::class
    ],
    'route_prefix' => 'tickets',

    'models' => [
        // 'user' => App\Models\User::class,
        // 'student' => App\Models\Student::class,
        // 'staff' => App\Models\Instructor::class,
    ],

    'owner' => [
        'resolver' => null,
        'request_keys' => [
            'type' => 'TYPE',
            'id' => 'ID',
        ],
        'type_map' => [
            'STUD' => App\Models\Student::class,
            // 'STAFF' => App\Models\Instructor::class,
        ],
    ],

    'access' => [
        'resolver' => null,
        'field' => 'access_key',
        'manage_permission' => 'tickets-management.ticket-types.manage',
        'super_admin_permission' => 'ticketing.manage-all',
    ],

    'statuses' => ['open', 'in_progress', 'closed'],
    'priorities' => ['low', 'medium', 'high'],
];