<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Idempotency
    |--------------------------------------------------------------------------
    */
    'idempotency' => [
        'lock_ttl' => env('IDEMPOTENCY_LOCK_TTL', 300), // seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Circuit Breaker (per connector, with defaults)
    |--------------------------------------------------------------------------
    */
    'circuit_breaker' => [
        'default' => [
            'failure_threshold' => env('CB_DEFAULT_THRESHOLD', 5),
            'cooldown_seconds' => env('CB_DEFAULT_COOLDOWN', 60),
            'failure_window' => env('CB_DEFAULT_WINDOW', 300),
        ],
        'creative' => [
            'failure_threshold' => env('CB_CREATIVE_THRESHOLD', 3),
            'cooldown_seconds' => env('CB_CREATIVE_COOLDOWN', 120),
            'failure_window' => env('CB_CREATIVE_WINDOW', 600),
        ],
        'email' => [
            'failure_threshold' => env('CB_EMAIL_THRESHOLD', 5),
            'cooldown_seconds' => env('CB_EMAIL_COOLDOWN', 60),
            'failure_window' => env('CB_EMAIL_WINDOW', 300),
        ],
        'social' => [
            'failure_threshold' => env('CB_SOCIAL_THRESHOLD', 5),
            'cooldown_seconds' => env('CB_SOCIAL_COOLDOWN', 60),
            'failure_window' => env('CB_SOCIAL_WINDOW', 300),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limits
    |--------------------------------------------------------------------------
    */
    'rate_limits' => [
        'workspace' => [
            'per_minute' => env('RL_WS_PER_MINUTE', 30),
            'per_hour' => env('RL_WS_PER_HOUR', 300),
            'per_day' => env('RL_WS_PER_DAY', 3000),
        ],
        'agent' => [
            'per_minute' => env('RL_AGENT_PER_MINUTE', 10),
            'per_hour' => env('RL_AGENT_PER_HOUR', 100),
            'per_day' => env('RL_AGENT_PER_DAY', 1000),
        ],
        'connector' => [
            'default' => [
                'per_minute' => env('RL_CONN_PER_MINUTE', 20),
                'per_hour' => env('RL_CONN_PER_HOUR', 200),
                'per_day' => env('RL_CONN_PER_DAY', 2000),
            ],
            'creative' => [
                'per_minute' => env('RL_CREATIVE_PER_MINUTE', 10),
                'per_hour' => env('RL_CREATIVE_PER_HOUR', 100),
                'per_day' => env('RL_CREATIVE_PER_DAY', 1000),
            ],
            'email' => [
                'per_minute' => env('RL_EMAIL_PER_MINUTE', 15),
                'per_hour' => env('RL_EMAIL_PER_HOUR', 150),
                'per_day' => env('RL_EMAIL_PER_DAY', 1500),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Verification Thresholds
    |--------------------------------------------------------------------------
    */
    'verification' => [
        'creative_min_asset_size' => env('VERIFY_CREATIVE_MIN_SIZE', 1024),
        'enabled' => env('VERIFY_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Debug Mode (Phase 4)
    |--------------------------------------------------------------------------
    | When true, enables /api/debug/* routes even in production.
    | MUST be false in production.
    */
    'debug_enabled' => env('BOSS888_DEBUG_ENABLED', false),

];
