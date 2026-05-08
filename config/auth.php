<?php

return [
    // Default guard is 'web' (session-based, not DB-querying) because
    // this codebase uses a custom JWT middleware (JwtAuthMiddleware
    // aliased as 'auth.jwt') that calls $request->setUserResolver()
    // directly. Setting the default to 'api' with TokenGuard caused
    // Auth::user() / $request->user() to try `select * from users
    // where api_token = <jwt>` which fails because that column
    // doesn't exist (PATCH 3 fix, 2026-05-08).
    'defaults' => [
        'guard'     => 'web',
        'passwords' => 'users',
    ],

    'guards' => [
        'web' => [
            'driver'   => 'session',
            'provider' => 'users',
        ],
        // 'api' kept as session-driver so Auth::guard('api') doesn't
        // throw "guard not defined" for any legacy code reference,
        // while not triggering DB lookups by api_token.
        'api' => [
            'driver'   => 'session',
            'provider' => 'users',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model'  => App\Models\User::class,
        ],
    ],

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table'    => 'password_reset_tokens',
            'expire'   => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => 10800,
];
