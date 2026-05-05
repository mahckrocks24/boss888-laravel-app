<?php

/**
 * Scale configuration for production deployment.
 * Target: 50k concurrent users across 6 countries.
 * Infrastructure: Digital Ocean managed services.
 */
return [

    // ── Database ─────────────────────────────────────────
    'database' => [
        // Read replicas for all SELECT queries
        'read_replicas' => (int) env('DB_READ_REPLICAS', 2),
        // Connection pool size per worker
        'pool_size' => (int) env('DB_POOL_SIZE', 20),
        // Slow query threshold (ms)
        'slow_query_threshold' => (int) env('DB_SLOW_QUERY_MS', 500),
    ],

    // ── Redis ────────────────────────────────────────────
    'redis' => [
        // Cluster mode for horizontal scaling
        'cluster' => env('REDIS_CLUSTER', 'redis'),
        // Max connections per worker
        'pool_size' => (int) env('REDIS_POOL_SIZE', 50),
        // Default TTLs
        'ttl' => [
            'session' => 86400,        // 24h
            'cache' => 3600,           // 1h
            'rate_limit' => 60,        // 1min
            'agent_reasoning' => 300,  // 5min — cache repeated agent prompts
            'engine_briefing' => 600,  // 10min — cache engine intelligence
            'workspace_status' => 30,  // 30s — frequently polled
        ],
    ],

    // ── Queue Workers ────────────────────────────────────
    'queue' => [
        // Total workers across all servers
        'total_workers' => (int) env('QUEUE_WORKERS', 8),
        // Workers per queue
        'workers_per_queue' => [
            'high' => 2,        // urgent tasks, approvals
            'default' => 4,     // normal engine tasks
            'low' => 1,         // background intelligence, learning
            'llm' => 1,         // LLM calls (rate limited)
        ],
        // Concurrency per worker
        'concurrency' => (int) env('QUEUE_CONCURRENCY', 3),
        // Max execution time per job (seconds)
        'max_execution_time' => (int) env('QUEUE_MAX_TIME', 120),
        // Retry config
        'max_retries' => 4,
        'retry_delays' => [8, 16, 32, 64], // exponential backoff
    ],

    // ── LLM Rate Limiting ────────────────────────────────
    'llm' => [
        // Max concurrent LLM calls across all workers
        'max_concurrent' => (int) env('LLM_MAX_CONCURRENT', 10),
        // Per-workspace rate limit (calls per minute)
        'per_workspace_rpm' => (int) env('LLM_WORKSPACE_RPM', 30),
        // Per-agent rate limit
        'per_agent_rpm' => (int) env('LLM_AGENT_RPM', 10),
        // Response cache TTL (seconds) — identical prompts get cached
        'cache_ttl' => (int) env('LLM_CACHE_TTL', 300),
        // Fallback to template if LLM queue full
        'queue_overflow_action' => 'template_fallback',
    ],

    // ── Response Caching ─────────────────────────────────
    'cache' => [
        // Cache engine list/read responses
        'engine_reads' => true,
        'cache_prefix' => 'lu:cache:',
        // Per-endpoint TTL overrides
        'ttl_overrides' => [
            'workspace/status' => 30,
            'billing/status' => 60,
            'agents' => 300,
            'crm/pipeline' => 15,
            'seo/keywords' => 60,
            'calendar/events' => 30,
            'insights/summary' => 60,
        ],
    ],

    // ── Geographic Distribution ──────────────────────────
    'regions' => [
        'primary' => env('PRIMARY_REGION', 'fra1'),  // Frankfurt (central to all target markets)
        'cdn' => env('CDN_ENABLED', true),
        'target_markets' => ['de', 'ae', 'at', 'ch', 'ph', 'au'],
    ],

    // ── Rate Limiting (per-user) ─────────────────────────
    'rate_limits' => [
        'api_rpm' => (int) env('API_RPM', 120),           // 120 requests/min per user
        'execute_rpm' => (int) env('EXECUTE_RPM', 30),     // 30 engine executions/min
        'llm_rpm' => (int) env('USER_LLM_RPM', 20),       // 20 LLM calls/min per user
        'upload_rpm' => (int) env('UPLOAD_RPM', 10),       // 10 uploads/min
    ],

    // ── Monitoring ───────────────────────────────────────
    'monitoring' => [
        'health_check_interval' => 30,   // seconds
        'stale_task_threshold' => 600,   // 10 minutes
        'alert_queue_depth' => 1000,     // alert if queue exceeds
        'alert_error_rate' => 0.05,      // alert if >5% errors
    ],
];
