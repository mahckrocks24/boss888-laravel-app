<?php
/**
 * P2-C — process-parallel concurrency worker.
 *
 * Executed as an INDEPENDENT OS PROCESS, N at a time, against the isolated chat
 * test database. This is what makes the P2-C evidence genuinely parallel rather
 * than the interleaved simulation P2-B produced: each invocation is its own PHP
 * process, its own Laravel boot, its own database connection and its own
 * transaction. Nothing is shared except the database — which is exactly the
 * contention the unique index and the conditional updates have to survive.
 *
 * Usage:
 *   php parallel_worker.php <workspaceId> <idempotencyKey> <content> <mode> <barrierTs>
 *
 * mode: ok | fail | slow
 * barrierTs: unix microtime float — all workers spin until this instant, so
 *            they hit the database as close to simultaneously as the OS allows.
 *
 * Prints one JSON line to stdout. Never throws out of the process.
 */

$root = '/var/www/levelup-staging';
require $root . '/vendor/autoload.php';

$workspaceId = (int) ($argv[1] ?? 0);
$key         = (string) ($argv[2] ?? '');
$content     = (string) ($argv[3] ?? 'parallel probe');
$mode        = (string) ($argv[4] ?? 'ok');
$barrier     = (float) ($argv[5] ?? 0);

// Force the isolated test target BEFORE the framework boots.
putenv('APP_ENV=testing');
putenv('DB_DATABASE=levelup_chat_test');
$_ENV['APP_ENV'] = 'testing';
$_ENV['DB_DATABASE'] = 'levelup_chat_test';
putenv('CHAT_IDEMPOTENCY_ENABLED=true');
$_ENV['CHAT_IDEMPOTENCY_ENABLED'] = 'true';
putenv('CHAT_IDEMPOTENCY_SURFACES=s8_public_chatbot,s4_seo_assistant');
$_ENV['CHAT_IDEMPOTENCY_SURFACES'] = 's8_public_chatbot,s4_seo_assistant';

$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

// Refuse to run anywhere but the isolated test database.
$target = config('database.connections.' . config('database.default') . '.database');
if ($target !== 'levelup_chat_test') {
    echo json_encode(['pid' => getmypid(), 'error' => "refusing: target={$target}"]), "\n";
    exit(1);
}

// Spin to the barrier so the workers collide.
if ($barrier > 0) {
    while (microtime(true) < $barrier) {
        usleep(200);
    }
}

$pid = getmypid();
$result = ['pid' => $pid, 'ok' => false, 'replay' => null, 'error_code' => null, 'correlation_id' => null];

try {
    $coordinator = app(\App\Core\Chat\ChatExecutionCoordinator::class);

    $outcome = $coordinator->run([
        'workspace_id'      => $workspaceId,
        'surface'           => 's8_public_chatbot',
        'conversation_type' => 'chatbot_session',
        'conversation_id'   => 'sess_parallel',
        'idempotency_key'   => $key,
        'content'           => $content,
        'estimated_credits' => 1,
        'message_store'     => 'agent_messages',
    ], [
        'persistUserMessage' => function () use ($workspaceId) {
            return DB::table('agent_messages')->insertGetId([
                'workspace_id' => $workspaceId, 'agent_slug' => 'sarah',
                'sender' => 'user', 'role' => 'user', 'content' => 'PARALLEL_USER',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        },
        'execute' => function () use ($mode, $pid) {
            // Every provider invocation leaves a durable trace, so the parent
            // can count executions across processes.
            DB::table('p2c_provider_calls')->insert(['pid' => $pid, 'created_at' => now()]);

            if ($mode === 'slow') {
                usleep(400000);   // hold `processing` so competitors see it in flight
            }
            if ($mode === 'fail') {
                return ['ok' => false, 'error_code' => 'CHAT_PROVIDER_UNAVAILABLE',
                        'retryable' => true, 'http_status' => 503];
            }

            return ['ok' => true, 'body' => ['reply' => 'parallel answer'],
                    'provider' => 'deepseek', 'model' => 'deepseek-v4-flash',
                    'usage' => ['tokens' => 42]];
        },
        'persistFinalMessage' => function ($exec) use ($workspaceId) {
            return DB::table('agent_messages')->insertGetId([
                'workspace_id' => $workspaceId, 'agent_slug' => 'sarah',
                'sender' => 'agent', 'role' => 'agent', 'content' => 'PARALLEL_FINAL',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        },
    ]);

    $result['ok']             = (bool) ($outcome['ok'] ?? false);
    $result['replay']         = (bool) ($outcome['replay'] ?? false);
    $result['error_code']     = $outcome['error_code'] ?? null;
    $result['correlation_id'] = $outcome['correlation_id'] ?? null;
} catch (\Throwable $e) {
    $result['error_code'] = 'EXCEPTION';
    $result['exception']  = substr($e->getMessage(), 0, 200);
}

echo json_encode($result), "\n";
exit(0);
