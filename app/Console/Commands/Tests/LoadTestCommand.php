<?php

namespace App\Console\Commands\Tests;

use Illuminate\Console\Command;
use App\Models\User;
use App\Models\Workspace;
use App\Models\Credit;
use App\Models\Agent;
use App\Core\TaskSystem\TaskService;
use App\Services\PerformanceCollector;
use Illuminate\Support\Str;

class LoadTestCommand extends Command
{
    protected $signature = 'boss888:load-test
        {--tasks=500 : Number of tasks to dispatch}
        {--workspaces=5 : Number of test workspaces}
        {--wait=120 : Seconds to wait for completion}';

    protected $description = 'Phase 5: Queue stress test — dispatches tasks across workspaces and monitors completion';

    public function handle(TaskService $taskService, PerformanceCollector $perfCollector): int
    {
        $this->guardInfrastructure();

        $taskCount = (int) $this->option('tasks');
        $wsCount = (int) $this->option('workspaces');
        $waitSec = (int) $this->option('wait');

        $this->info("═══ Boss888 Load Test ═══");
        $this->info("Tasks: {$taskCount} | Workspaces: {$wsCount} | Wait: {$waitSec}s");
        $this->newLine();

        // Setup test workspaces
        $workspaces = $this->setupWorkspaces($wsCount);
        $startTime = microtime(true);
        $startMarker = now();

        // Dispatch tasks with mixed priorities
        $this->info("Dispatching {$taskCount} tasks...");
        $bar = $this->output->createProgressBar($taskCount);

        $priorities = ['low', 'normal', 'normal', 'high', 'urgent'];
        $actions = ['create_lead']; // Use CRM internal action for safe testing

        for ($i = 0; $i < $taskCount; $i++) {
            $ws = $workspaces[$i % $wsCount];
            $priority = $priorities[array_rand($priorities)];

            try {
                $taskService->create($ws->id, [
                    'engine' => 'crm',
                    'action' => $actions[array_rand($actions)],
                    'payload' => ['name' => "Load Test Lead {$i}", 'source' => 'load_test'],
                    'source' => $i % 3 === 0 ? 'agent' : 'manual',
                    'assigned_agents' => $i % 3 === 0 ? ['dmm'] : null,
                    'priority' => $priority,
                ]);
            } catch (\Throwable $e) {
                // Rate limit or throttle — expected under load
            }

            $bar->advance();
        }

        $bar->finish();
        $dispatchTime = round(microtime(true) - $startTime, 2);
        $this->newLine(2);
        $this->info("Dispatch complete in {$dispatchTime}s");

        // Wait for completion
        $this->info("Waiting {$waitSec}s for workers to process...");
        $this->output->createProgressBar($waitSec);

        for ($s = 0; $s < $waitSec; $s++) {
            sleep(1);
            $this->output->write('.');

            // Check if all done
            $remaining = \App\Models\Task::whereIn('status', ['pending', 'queued', 'running', 'verifying'])
                ->where('created_at', '>=', $startMarker)
                ->count();

            if ($remaining === 0 && $s > 10) {
                $this->newLine();
                $this->info("All tasks completed after {$s}s!");
                break;
            }
        }

        $this->newLine(2);

        // Collect metrics
        $metrics = $perfCollector->collect($startMarker);

        $this->info('═══ Results ═══');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total dispatched', $taskCount],
                ['Completed', $metrics['task_metrics']['completed']],
                ['Failed', $metrics['task_metrics']['failed']],
                ['Blocked', $metrics['task_metrics']['blocked']],
                ['Degraded', $metrics['task_metrics']['degraded']],
                ['Failure rate', $metrics['task_metrics']['failure_rate_percent'] . '%'],
                ['Avg exec time', $metrics['task_metrics']['avg_execution_time_seconds'] . 's'],
                ['Avg queue wait', $metrics['task_metrics']['avg_queue_wait_seconds'] . 's'],
                ['Total retries', $metrics['task_metrics']['total_retries']],
                ['Dispatch time', "{$dispatchTime}s"],
                ['Stale tasks', $metrics['queue_metrics']['stale_tasks']],
                ['Idempotent skips', $metrics['idempotency_metrics']['duplicate_skips']],
                ['Credit orphans', $metrics['credit_metrics']['orphaned_reservations']],
            ]
        );

        // Pass/fail
        $passed = $metrics['task_metrics']['failure_rate_percent'] < 10
            && $metrics['credit_metrics']['orphaned_reservations'] === 0
            && $metrics['queue_metrics']['stale_tasks'] === 0;

        $this->newLine();
        if ($passed) {
            $this->info('✅ LOAD TEST PASSED');
        } else {
            $this->error('❌ LOAD TEST FAILED — review metrics above');
        }

        return $passed ? 0 : 1;
    }

    private function setupWorkspaces(int $count): array
    {
        $user = User::firstOrCreate(
            ['email' => 'loadtest@boss888.test'],
            ['name' => 'Load Test User', 'password' => bcrypt('loadtest')]
        );

        $workspaces = [];
        for ($i = 0; $i < $count; $i++) {
            $ws = Workspace::create([
                'name' => "LoadTest WS {$i}",
                'slug' => 'loadtest-' . Str::random(6),
                'created_by' => $user->id,
            ]);
            $ws->users()->attach($user->id, ['role' => 'owner']);
            Credit::create(['workspace_id' => $ws->id, 'balance' => 50000, 'reserved_balance' => 0]);

            $agents = Agent::where('status', 'active')->get();
            foreach ($agents as $agent) {
                $ws->agents()->attach($agent->id, ['enabled' => true]);
            }

            $workspaces[] = $ws;
        }

        $this->info("Created {$count} test workspaces");
        return $workspaces;
    }

    private function guardInfrastructure(): void
    {
        if (config('queue.default') === 'sync') {
            $this->error('QUEUE_CONNECTION=sync detected. Phase 5 requires redis.');
            exit(1);
        }
        if (config('cache.default') === 'array') {
            $this->error('CACHE_DRIVER=array detected. Phase 5 requires redis.');
            exit(1);
        }
        if (config('database.default') === 'sqlite') {
            $this->error('DB_CONNECTION=sqlite detected. Phase 5 requires mysql.');
            exit(1);
        }
    }
}
