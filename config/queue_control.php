<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Workspace Concurrency Cap
    |--------------------------------------------------------------------------
    | Max concurrent running tasks per workspace.
    */
    'workspace_concurrency' => env('QUEUE_WS_CONCURRENCY', 10),

    /*
    |--------------------------------------------------------------------------
    | Agent Concurrency Cap
    |--------------------------------------------------------------------------
    | Max concurrent running tasks per agent.
    */
    'agent_concurrency' => env('QUEUE_AGENT_CONCURRENCY', 5),

    /*
    |--------------------------------------------------------------------------
    | Stale Task Timeout (seconds)
    |--------------------------------------------------------------------------
    | A task in "running" state beyond this threshold is considered stale.
    */
    'stale_task_timeout' => env('QUEUE_STALE_TIMEOUT', 600),

    /*
    |--------------------------------------------------------------------------
    | Priority Queues
    |--------------------------------------------------------------------------
    */
    'queues' => [
        'high' => 'tasks-high',
        'normal' => 'tasks',
        'low' => 'tasks-low',
    ],

    /*
    |--------------------------------------------------------------------------
    | Progress Polling Default (ms)
    |--------------------------------------------------------------------------
    */
    'progress_poll_interval' => env('QUEUE_PROGRESS_POLL_MS', 2000),

];
