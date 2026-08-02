<?php

namespace App\Core\Engineer888\Deployment;

use App\Core\Engineer888\Signals\ErrorSignal;
use App\Core\Engineer888\Signals\PlatformSignal;
use App\Core\Engineer888\Signals\Shell;
use App\Core\Engineer888\Signals\WorkloadSignal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;

/**
 * Non-destructive post-deployment checks.
 *
 * EVERY CHECK HERE IS A READ. Nothing writes, nothing dispatches, nothing
 * migrates. Proving the application can write by writing something is how test
 * data ends up in production, and a verification tool that mutates the thing it
 * verifies cannot be run when it matters most.
 *
 * The signal collectors from Sprint 1 are reused rather than reimplemented —
 * they already handle a failed sensor by degrading instead of throwing, which is
 * the behaviour a verifier needs.
 *
 * Statuses: PASS · FAIL · WARN · UNKNOWN. UNKNOWN is not a soft pass; it means
 * the check could not be performed and the verdict must account for that.
 */
final class SmokeChecks
{
    public const PASS = 'PASS';
    public const FAIL = 'FAIL';
    public const WARN = 'WARN';
    public const UNKNOWN = 'UNKNOWN';

    /** Configuration keys checked for PRESENCE only. Values are never read. */
    private const REQUIRED_CONFIG = [
        'app.key', 'app.env', 'database.default',
        'queue.default', 'cache.default',
    ];

    public function __construct(
        private string $repoPath,
        private array $urls = [],
    ) {
        $this->urls = $urls === [] ? [
            'public site'    => 'https://levelupgrowth.io',
            'application'    => 'https://levelupgrowth.io/app/',
        ] : $urls;
    }

    /** @return array<int,array<string,mixed>> */
    public function run(array $relevantFamilies = []): array
    {
        $checks = [];

        foreach ($this->http() as $check) { $checks[] = $check; }
        $checks[] = $this->database();
        $checks[] = $this->redis();
        foreach ($this->platform() as $check) { $checks[] = $check; }
        $checks[] = $this->migrations();
        $checks[] = $this->configuration();
        foreach ($this->routes() as $check) { $checks[] = $check; }
        $checks[] = $this->errors();
        foreach ($this->workload() as $check) { $checks[] = $check; }

        if ($relevantFamilies !== []) {
            foreach ($checks as $index => $check) {
                $checks[$index]['relevant_to_release'] = in_array($check['family'], $relevantFamilies, true);
            }
        }

        return $checks;
    }

    /** @return array<int,array<string,mixed>> */
    private function http(): array
    {
        $out = [];

        foreach ($this->urls as $label => $url) {
            $started = microtime(true);
            try {
                // GET only. No form posts, no state-changing verbs.
                $response = Http::timeout(20)->withoutRedirecting()->get($url);
                $ms = (int) round((microtime(true) - $started) * 1000);
                $status = $response->status();
                $ok = $status >= 200 && $status < 400;

                $out[] = $this->check(
                    'http:' . $label, 'http', $ok ? self::PASS : self::FAIL,
                    "HTTP {$status} in {$ms}ms",
                    // Never the body: it can contain customer data or tokens.
                    ['url' => $url, 'status' => $status, 'latency_ms' => $ms]
                );
            } catch (\Throwable $e) {
                $out[] = $this->check('http:' . $label, 'http', self::FAIL,
                    'request failed: ' . substr($e->getMessage(), 0, 100), ['url' => $url]);
            }
        }

        return $out;
    }

    private function database(): array
    {
        try {
            $started = microtime(true);
            DB::select('select 1');
            $ms = (int) round((microtime(true) - $started) * 1000);

            return $this->check('database:connectivity', 'database', self::PASS,
                "responded in {$ms}ms", ['database' => DB::connection()->getDatabaseName()]);
        } catch (\Throwable $e) {
            return $this->check('database:connectivity', 'database', self::FAIL,
                substr($e->getMessage(), 0, 120));
        }
    }

    private function redis(): array
    {
        try {
            $pong = Redis::connection()->ping();

            return $this->check('redis:connectivity', 'queue', self::PASS,
                'ping ' . (is_string($pong) ? $pong : 'ok'));
        } catch (\Throwable $e) {
            return $this->check('redis:connectivity', 'queue', self::FAIL,
                substr($e->getMessage(), 0, 120));
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function platform(): array
    {
        $platform = (new PlatformSignal())->collect();
        $out = [];

        $down = $platform['supervisor_down'] ?? [];
        $out[] = $this->check('workers:supervisor', 'queue',
            $down === [] ? self::PASS : self::FAIL,
            $down === []
                ? ($platform['supervisor_running'] ?? '?') . '/' . ($platform['supervisor_programs'] ?? '?') . ' running'
                : 'not running: ' . implode(', ', (array) $down));

        $processes = (int) ($platform['queue_worker_processes'] ?? 0);
        $out[] = $this->check('workers:processes', 'queue',
            $processes > 0 ? self::PASS : self::FAIL, $processes . ' queue:work process(es)');

        if (isset($platform['queue_pending_total'])) {
            $pending = (int) $platform['queue_pending_total'];
            $out[] = $this->check('queue:depth', 'queue',
                $pending > 500 ? self::WARN : self::PASS, $pending . ' pending');
        } else {
            $out[] = $this->check('queue:depth', 'queue', self::UNKNOWN,
                (string) ($platform['queue_depth_reason'] ?? 'redis unreadable'));
        }

        $heartbeat = $platform['scheduler_heartbeat_age_seconds'] ?? null;
        $out[] = $heartbeat === null
            ? $this->check('scheduler:heartbeat', 'scheduler', self::UNKNOWN,
                (string) ($platform['scheduler_heartbeat_reason'] ?? 'no heartbeat source'))
            : $this->check('scheduler:heartbeat', 'scheduler',
                $heartbeat > 900 ? self::FAIL : self::PASS, "last output {$heartbeat}s ago");

        if (isset($platform['disk_used_percent'])) {
            $used = (int) $platform['disk_used_percent'];
            $out[] = $this->check('platform:disk', 'platform',
                $used >= 92 ? self::FAIL : ($used >= 85 ? self::WARN : self::PASS),
                $used . '% used, ' . ($platform['disk_free_gb'] ?? '?') . 'GB free');
        }

        return $out;
    }

    /**
     * Migration CONSISTENCY, never migration execution. A verifier that runs
     * migrations is a deployer, and this sprint is explicitly not that.
     */
    private function migrations(): array
    {
        // Computed from the database, not by parsing console output. The first
        // version grepped for the word "pending" and counted
        // create_pending_invites_table as pending because the word is in its
        // NAME. Migration files minus applied rows is exact and has no such
        // failure mode.
        try {
            $applied = array_flip(DB::table('migrations')->pluck('migration')->all());
        } catch (\Throwable $e) {
            return $this->check('migrations:consistency', 'migration', self::UNKNOWN,
                'migrations table unreadable: ' . substr($e->getMessage(), 0, 80));
        }

        $files = glob($this->repoPath . '/database/migrations/*.php') ?: [];
        if ($files === []) {
            return $this->check('migrations:consistency', 'migration', self::UNKNOWN,
                'no migration files found on disk');
        }

        $pending = [];
        foreach ($files as $path) {
            $name = pathinfo($path, PATHINFO_FILENAME);
            if (! isset($applied[$name])) { $pending[] = $name; }
        }
        sort($pending);

        return $this->check('migrations:consistency', 'migration',
            $pending === [] ? self::PASS : self::WARN,
            $pending === []
                ? 'no pending migrations'
                : count($pending) . ' pending (NOT executed by this check): ' . implode(', ', array_slice($pending, 0, 3)),
            ['pending' => $pending]);
    }

    /**
     * Configuration is checked for PRESENCE only. No value is read, logged or
     * stored — a verification report that leaks APP_KEY is worse than no report.
     */
    private function configuration(): array
    {
        $missing = [];
        foreach (self::REQUIRED_CONFIG as $key) {
            $value = config($key);
            if ($value === null || $value === '') { $missing[] = $key; }
        }

        return $this->check('config:required-keys', 'configuration',
            $missing === [] ? self::PASS : self::FAIL,
            $missing === []
                ? count(self::REQUIRED_CONFIG) . ' required keys are set (values never read)'
                : 'missing: ' . implode(', ', $missing),
            ['checked' => self::REQUIRED_CONFIG, 'missing' => $missing]);
    }

    /** @return array<int,array<string,mixed>> */
    private function routes(): array
    {
        try {
            $routes = Route::getRoutes();
            $count = count($routes->getRoutes());
        } catch (\Throwable $e) {
            return [$this->check('routes:resolve', 'routing', self::FAIL,
                'the route collection could not be built: ' . substr($e->getMessage(), 0, 100))];
        }

        $out = [$this->check('routes:resolve', 'routing', $count > 0 ? self::PASS : self::FAIL,
            $count . ' routes registered')];

        // Controller resolution: a route pointing at a missing class only fails
        // when someone requests it, which in production means a customer finds it.
        $unresolvable = [];
        foreach ($routes->getRoutes() as $route) {
            $action = $route->getActionName();
            if (! str_contains($action, '@')) { continue; }
            [$class] = explode('@', $action, 2);
            if ($class !== 'Closure' && ! class_exists($class)) {
                $unresolvable[] = $route->uri() . ' -> ' . $class;
                if (count($unresolvable) >= 10) { break; }
            }
        }

        $out[] = $this->check('routes:controllers', 'routing',
            $unresolvable === [] ? self::PASS : self::FAIL,
            $unresolvable === []
                ? 'every route action resolves to a loadable class'
                : count($unresolvable) . ' unresolvable: ' . implode('; ', array_slice($unresolvable, 0, 3)));

        return $out;
    }

    private function errors(): array
    {
        $signal = (new ErrorSignal(
            storage_path('logs/laravel.log'),
            0,
            true,                      // baseline: this reads history, not a delta
            (string) config('app.env')
        ))->collect();

        if (! ($signal['available'] ?? false)) {
            return $this->check('errors:recent', 'errors', self::UNKNOWN,
                (string) ($signal['reason'] ?? 'log unreadable'));
        }

        $critical = ($signal['counts']['CRITICAL'] ?? 0) + ($signal['counts']['EMERGENCY'] ?? 0) + ($signal['counts']['ALERT'] ?? 0);

        return $this->check('errors:recent', 'errors',
            $critical > 0 ? self::FAIL : ($signal['total'] > 0 ? self::WARN : self::PASS),
            $signal['total'] . ' production-channel entries in the scanned window'
                . ($signal['foreign_total'] > 0 ? ', ' . $signal['foreign_total'] . ' excluded from other channels' : ''),
            ['counts' => $signal['counts'], 'scanned_bytes' => $signal['bytes_scanned']]);
    }

    /** @return array<int,array<string,mixed>> */
    private function workload(): array
    {
        $work = (new WorkloadSignal())->collect();
        if (! ($work['available'] ?? false)) {
            return [$this->check('tasks:recent', 'workload', self::UNKNOWN,
                (string) ($work['reason'] ?? 'tasks unreadable'))];
        }

        $failed = (int) ($work['tasks_by_status']['failed'] ?? 0);
        $stale = (int) ($work['tasks_stale_running'] ?? 0);

        return [
            $this->check('tasks:failed-24h', 'workload', $failed > 0 ? self::FAIL : self::PASS,
                $failed . ' failed in the last 24h'),
            $this->check('tasks:stale', 'workload', $stale > 0 ? self::WARN : self::PASS,
                $stale . ' running but untouched for over 2h'),
        ];
    }

    /** @return array<string,mixed> */
    private function check(string $name, string $family, string $status, string $detail, array $evidence = []): array
    {
        return [
            'name'     => $name,
            'family'   => $family,
            'status'   => $status,
            'detail'   => $detail,
            'evidence' => $evidence,       // sanitised by construction: no bodies, no values
            'mutating' => false,           // every check in this class is a read
        ];
    }
}
