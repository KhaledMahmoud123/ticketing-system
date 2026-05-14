<?php

return [
    'table' => 'tickets',
    'middleware' => ['web', 'auth'],
    'route_prefix' => 'tickets',

    'models' => [
        // 'user' => App\Models\User::class,
        // 'student' => App\Models\Student::class,
        // 'parent' => App\Models\Parents::class,
        // 'instructor' => App\Models\Instructor::class,
        // 'applicant' => App\Models\Applicant::class,
    ],

    'owner' => [
        'resolver' => null,
        'request_keys' => [
            'type' => 'TYPE',
            'id' => 'ID',
        ],
        'type_map' => [
            // 'student' => App\Models\Student::class,
            // 'parent' => App\Models\Parents::class,
            // 'instructor' => App\Models\Instructor::class,
            // 'applicant' => App\Models\Applicant::class,
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