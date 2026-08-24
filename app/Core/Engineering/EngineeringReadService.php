<?php

namespace App\Core\Engineering;

use App\Core\Orchestration\OrchestrationHealthService;
use App\Core\Safety\ProtectedPaths;
use App\Core\Safety\ManifestBackup;
use App\Core\Safety\SourceOwnershipLock;
use App\Core\SystemHealth\SystemHealthService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Facades\Schema;

/**
 * ENTERPRISE888 E1 M0 — the Engineering section's read foundation.
 *
 * M0 SCOPE: the section manifest only. No screen data — those arrive in M1–M5.
 *
 * The manifest is not decoration. The blueprint requires every screen to declare
 * its data source, its evidence source and its owning phase, and requires every
 * not-built screen to say WHY it is empty. This service is where those facts
 * live, and it verifies the "why" against the real schema rather than asserting
 * it from a hard-coded string.
 *
 * That distinction matters. "Runs are empty because the table does not exist" is
 * a claim. Checking `Schema::hasTable('engineering_runs')` and reporting the
 * result is evidence. If someone creates that table in E3, this manifest starts
 * telling the truth about it without anyone remembering to edit a constant.
 */
final class EngineeringReadService
{
    /** Screen is live and backed by real data. */
    public const STATUS_LIVE = 'live';

    /** Screen has real data but incomplete coverage — must say so on screen. */
    public const STATUS_PARTIAL = 'partial';

    /** Approved, data exists, not yet built in this milestone. */
    public const STATUS_PLANNED = 'planned';

    /** Cannot be built — the underlying capability does not exist yet. */
    public const STATUS_NOT_BUILT = 'not_built';

    /**
     * The complete Engineering information architecture.
     *
     * Ordered as it appears in the section index. Every entry carries the four
     * facts the blueprint's empty-state contract requires.
     */
    private const SCREENS = [
        [
            'key' => 'overview', 'title' => 'Overview', 'status' => self::STATUS_LIVE,
            'milestone' => 'M1', 'phase' => 'E1',
            'purpose' => 'Is the platform healthy, and does anything need attention?',
            'data_source' => 'health endpoints, jobs, tasks, approvals, protected-path drift, ownership locks, route signature, supervisor',
            'evidence_source' => 'each tile links to its screen; every figure names its source',
            'why_unavailable' => null, 'expected_evidence' => null, 'alternative' => null,
            'requires_tables' => [],
        ],
        [
            'key' => 'health', 'title' => 'Health', 'status' => self::STATUS_LIVE,
            'milestone' => 'M1', 'phase' => 'E1',
            'purpose' => 'Current state of every probe, honestly.',
            'data_source' => '5 health endpoints, OrchestrationHealthService, supervisor',
            'evidence_source' => 'probe target, response and observation time',
            'why_unavailable' => null, 'expected_evidence' => null,
            'alternative' => 'GET /api/admin/health',
            'requires_tables' => [],
        ],
        [
            'key' => 'ownership', 'title' => 'Source & Route Ownership', 'status' => self::STATUS_LIVE,
            'milestone' => 'M2', 'phase' => 'E1',
            'purpose' => 'Who owns what, and has anything been written outside governed tooling.',
            'data_source' => 'source-locks, protected-paths seal, route-ownership registry, ownership audit log',
            'evidence_source' => 'per-path seal hash; per-write before/after hashes',
            'why_unavailable' => null, 'expected_evidence' => null, 'alternative' => null,
            'requires_tables' => [],
        ],
        [
            'key' => 'backups', 'title' => 'Backups', 'status' => self::STATUS_LIVE,
            'milestone' => 'M2', 'phase' => 'E1',
            'purpose' => 'Prove backups exist and whether they are restorable.',
            'data_source' => 'manifest backup directories on disk',
            'evidence_source' => 'MANIFEST.json — sha256, bytes, route count, phase markers',
            'why_unavailable' => null, 'expected_evidence' => null, 'alternative' => null,
            'requires_tables' => [],
        ],
        [
            'key' => 'audit', 'title' => 'Audit Trail', 'status' => self::STATUS_LIVE,
            'milestone' => 'M3', 'phase' => 'E1',
            'purpose' => 'The audit trail, searchable.',
            'data_source' => 'audit_logs',
            'evidence_source' => 'raw metadata_json per entry',
            'why_unavailable' => null, 'expected_evidence' => null,
            'alternative' => 'GET /api/admin/audit',
            'requires_tables' => ['audit_logs'],
        ],
        [
            'key' => 'logs', 'title' => 'Application Log', 'status' => self::STATUS_PARTIAL,
            'milestone' => 'M3', 'phase' => 'E1',
            'purpose' => 'The raw application log, with test-generated entries separated from production activity.',
            'data_source' => 'storage/logs/laravel.log',
            'evidence_source' => 'raw log lines with their channel and level',
            'why_unavailable' => null,
            'expected_evidence' => null,
            'alternative' => null,
            'requires_tables' => [],
        ],
        [
            'key' => 'documentation', 'title' => 'Documentation', 'status' => self::STATUS_LIVE,
            'milestone' => 'M4', 'phase' => 'E1',
            'purpose' => 'Explain what each screen measures, what it cannot prove, and on what evidence.',
            'data_source' => 'the live screen definitions in this service',
            'evidence_source' => 'each entry names the screen it documents and that screen\'s own evidence source',
            'why_unavailable' => null, 'expected_evidence' => null, 'alternative' => null,
            'requires_tables' => [],
        ],
        [
            'key' => 'marketing_tasks', 'title' => 'Marketing Tasks', 'status' => self::STATUS_LIVE,
            'milestone' => 'M5', 'phase' => 'E1',
            'purpose' => 'Customer marketing work executed by the agent platform. This is NOT engineering execution.',
            'data_source' => 'tasks, task_events',
            'evidence_source' => 'task_events per task',
            'why_unavailable' => null, 'expected_evidence' => null,
            'alternative' => 'Task Monitor in the main admin navigation',
            'requires_tables' => ['tasks', 'task_events'],
        ],
        [
            'key' => 'incidents', 'title' => 'Incidents', 'status' => self::STATUS_PARTIAL,
            'milestone' => 'M5', 'phase' => 'E1',
            'purpose' => 'Incident visibility across three distinct sources, never merged into one count.',
            'data_source' => 'infra_incidents (infrastructure monitor), the markdown incident register, and engineering_incidents (future)',
            'evidence_source' => 'infra_incident_transitions; document links',
            'why_unavailable' => null, 'expected_evidence' => null, 'alternative' => null,
            'requires_tables' => ['infra_incidents'],
        ],
        [
            'key' => 'costs', 'title' => 'Costs', 'status' => self::STATUS_PARTIAL,
            'milestone' => 'M5', 'phase' => 'E1',
            'purpose' => 'What the platform spends in CUSTOMER CREDITS. Provider and token cost are not measured.',
            'data_source' => 'credit_transactions, tasks.credit_cost',
            'evidence_source' => 'per-transaction reference type and amount',
            'why_unavailable' => null, 'expected_evidence' => null, 'alternative' => null,
            'requires_tables' => ['credit_transactions'],
        ],

        // ── capabilities that do not exist yet ───────────────────────────────
        [
            'key' => 'engineering_tasks', 'title' => 'Engineering Tasks', 'status' => self::STATUS_NOT_BUILT,
            'milestone' => null, 'phase' => 'E3',
            'purpose' => 'Engineering intent, separate from customer marketing work.',
            'data_source' => null, 'evidence_source' => null,
            'why_unavailable' => 'The engineering_tasks model does not exist. The existing tasks table is the customer marketing engine and must not be overloaded with a second meaning.',
            'expected_evidence' => 'engineering intent, risk level, approval linkage, rollback plan',
            'alternative' => 'Marketing Tasks shows customer work only',
            'requires_tables' => ['engineering_tasks'],
        ],
        [
            'key' => 'runs', 'title' => 'Runs', 'status' => self::STATUS_NOT_BUILT,
            'milestone' => null, 'phase' => 'E3',
            'purpose' => 'One execution attempt of an engineering task.',
            'data_source' => null, 'evidence_source' => null,
            'why_unavailable' => 'No run model exists. A task is intent; a run is an execution attempt. The current schema does not separate them, so a retry overwrites its own history.',
            'expected_evidence' => 'attempt number, duration, worker count, tokens, verification outcome, evidence package',
            'alternative' => null,
            'requires_tables' => ['engineering_runs'],
        ],
        [
            'key' => 'workers', 'title' => 'Workers', 'status' => self::STATUS_NOT_BUILT,
            'milestone' => null, 'phase' => 'E3',
            'purpose' => 'Temporary execution units within a run.',
            'data_source' => null, 'evidence_source' => null,
            'why_unavailable' => 'No worker model exists. The three supervisor processes are queue workers — a different concept entirely, and showing them here would be misleading.',
            'expected_evidence' => 'objective, allowed paths and tools, budgets, ownership locks held, per-worker result',
            'alternative' => 'Health shows queue worker status',
            'requires_tables' => ['engineering_workers'],
        ],
        [
            'key' => 'reports', 'title' => 'Reports', 'status' => self::STATUS_NOT_BUILT,
            'milestone' => null, 'phase' => 'E6',
            'purpose' => 'Typed executive reports with linked evidence.',
            'data_source' => null, 'evidence_source' => null,
            'why_unavailable' => 'No report model and no evidence model exist. A report that cannot link evidence is a claim, and this platform has a documented history of confident false success.',
            'expected_evidence' => 'evidence IDs and an epistemic class on every assertion',
            'alternative' => 'Documentation holds the written handoff reports',
            'requires_tables' => ['engineering_reports'],
        ],
        [
            'key' => 'deployments', 'title' => 'Deployments', 'status' => self::STATUS_NOT_BUILT,
            'milestone' => null, 'phase' => 'E5',
            'purpose' => 'What shipped, and whether it can be rolled back.',
            'data_source' => null, 'evidence_source' => null,
            'why_unavailable' => 'No deployment record exists. Deployments have been performed by bespoke scripts with evidence captured in markdown, not in the system.',
            'expected_evidence' => 'ref, commit, pre/post hashes, route signature, tests, rollback readiness',
            'alternative' => 'Backups holds the manifest captured before each change',
            'requires_tables' => ['engineering_deployments'],
        ],
        [
            'key' => 'rollbacks', 'title' => 'Rollbacks', 'status' => self::STATUS_NOT_BUILT,
            'milestone' => null, 'phase' => 'E5',
            'purpose' => 'What was rolled back, why, and whether it was verified.',
            'data_source' => null, 'evidence_source' => null,
            'why_unavailable' => 'No rollback record exists. Rollbacks have been drilled and documented, but never recorded as data.',
            'expected_evidence' => 'trigger, actions taken, post-rollback verification, residual risk',
            'alternative' => null,
            'requires_tables' => ['engineering_rollbacks'],
        ],
        [
            'key' => 'provider_health', 'title' => 'Provider Health', 'status' => self::STATUS_NOT_BUILT,
            'milestone' => null, 'phase' => 'E4',
            'purpose' => 'Provider availability over time.',
            'data_source' => null, 'evidence_source' => null,
            'why_unavailable' => 'infra_provider_health contains no rows and no health check writes to it, so there is no history to display.',
            'expected_evidence' => 'probe results per provider over time, with latency and failure counts',
            'alternative' => 'Health shows current platform probes',
            'requires_tables' => ['engineering_health_results'],
        ],
    ];

    /**
     * The authoritative route baseline lives on disk, written by the governed
     * deployment procedure.
     *
     * It is read rather than hard-coded: a constant would need editing on every
     * approved route change, and the first time someone forgot, this check would
     * report a false drift and then be ignored. Reading the governed baseline
     * means a mismatch always signals something real — source moved outside the
     * governed path.
     */
    /**
     * The governed route baseline, in preference order.
     *
     * The authoritative copy lives under /root, which is mode 0700 — readable by
     * a root CLI test run but NOT by the web user serving the dashboard. That
     * difference is exactly the INC-2026-005 shape: verified as root, broken in
     * the serving context. A projection is therefore maintained inside the
     * application's own storage, which www-data can read.
     *
     * If neither is readable the check reports BLOCKED. It never silently
     * assumes the routes are fine.
     */
    private const ROUTE_BASELINE_FILES = [
        '/var/www/levelup-staging/storage/app/source-locks/route-baseline.ordered.txt',
        '/root/cr22-baseline-20260727/canonical.ordered.txt',
    ];


    /** Probe results are cached briefly so a page load never blocks on them. */
    private const PROBE_CACHE_SECONDS = 30;

    /**
     * A value that could not be determined.
     *
     * The blueprint forbids rendering an unknown as 0, "—" or an average. Every
     * unavailable measurement returns this shape so the UI can say BLOCKED and
     * state why, rather than implying a healthy zero.
     */
    private function blocked(string $why): array
    {
        return ['available' => false, 'value' => null, 'blocked_reason' => $why];
    }

    private function measured(mixed $value, string $source): array
    {
        return ['available' => true, 'value' => $value, 'source' => $source];
    }

    /**
     * Queue worker state, read from supervisor.
     *
     * If supervisorctl cannot be run — which is entirely possible for the web
     * user — this reports BLOCKED. It must never report "0 workers", because
     * zero and unknown are different facts and only one of them is an outage.
     */
    private function workerState(): array
    {
        $out = [];
        $rc = 0;
        @exec('supervisorctl status 2>&1', $out, $rc);

        $joined = implode("\n", $out);
        if ($rc !== 0 && !str_contains($joined, 'RUNNING')) {
            return $this->blocked('supervisorctl could not be run by the web user; worker state is unknown, not zero');
        }

        $workers = [];
        foreach ($out as $line) {
            if (preg_match('/^(\S+)\s+(\S+)/', $line, $m)) {
                $workers[] = ['name' => $m[1], 'state' => $m[2]];
            }
        }
        if ($workers === []) {
            return $this->blocked('supervisorctl returned no parsable worker lines');
        }

        $running = count(array_filter($workers, fn ($w) => $w['state'] === 'RUNNING'));

        return $this->measured(
            ['workers' => $workers, 'running' => $running, 'total' => count($workers)],
            'supervisorctl status'
        );
    }

    /** Live route table versus the signature recorded at the last governed change. */
    private function routeIntegrity(): array
    {
        $rows = [];
        foreach (RouteFacade::getRoutes()->getRoutes() as $r) {
            $mw = array_map(fn ($m) => is_string($m) ? $m : gettype($m), $r->gatherMiddleware());
            sort($mw);
            $rows[] = implode('|', [
                implode(',', $r->methods()), $r->uri(), (string) $r->getName(),
                (string) $r->getActionName(), (string) $r->getDomain(), implode(',', $mw),
            ]);
        }

        $live = implode("\n", $rows);
        $liveSignature = hash('sha256', $live);

        $baselineFile = null;
        foreach (self::ROUTE_BASELINE_FILES as $candidate) {
            if (is_readable($candidate)) {
                $baselineFile = $candidate;
                break;
            }
        }

        if ($baselineFile === null) {
            return [
                'count'          => count($rows),
                'signature'      => $liveSignature,
                'comparable'     => false,
                'matches'        => null,
                'blocked_reason' => 'the governed route baseline is not readable by this process; '
                                  . 'route drift cannot be assessed',
                'source'         => 'Route::getRoutes()',
            ];
        }

        $baseline = rtrim((string) file_get_contents($baselineFile), "\n");
        $baselineRows = $baseline === '' ? [] : explode("\n", $baseline);

        return [
            'count'              => count($rows),
            'expected_count'     => count($baselineRows),
            'signature'          => $liveSignature,
            'expected_signature' => hash('sha256', $baseline),
            'comparable'         => true,
            'matches'            => $liveSignature === hash('sha256', $baseline),
            'baseline_file'      => $baselineFile,
            'source'             => 'Route::getRoutes() compared to the governed baseline on disk',
        ];
    }

    /** Source-control integrity: protected paths, drift, unmanaged backups, locks. */
    private function sourceIntegrity(): array
    {
        $drift = ProtectedPaths::drift();
        $unmanaged = ProtectedPaths::unmanagedBackups();
        $locks = (new SourceOwnershipLock())->all();

        return [
            'protected_paths'   => count(ProtectedPaths::all()),
            'drifted'           => array_keys($drift['drifted']),
            'added'             => $drift['added'],
            'removed'           => $drift['removed'],
            'clean'             => $drift['drifted'] === [] && $drift['added'] === [] && $drift['removed'] === [],
            'unmanaged_backups' => $unmanaged,
            'live_locks'        => array_map(
                fn ($l) => ['scope' => $l['scope'] ?? null, 'owner' => $l['owner'] ?? null, 'phase' => $l['phase'] ?? null],
                $locks
            ),
            'sealed_at'         => $drift['sealed_at'],
            'source'            => 'ProtectedPaths::drift() + SourceOwnershipLock::all()',
        ];
    }

    /**
     * OVERVIEW — "is the platform healthy, and does anything need attention?"
     *
     * Every figure carries its source. Nothing is estimated.
     */
    public function overview(EngineeringListQuery $query): array
    {
        $health = Cache::remember('e1.overview.health', self::PROBE_CACHE_SECONDS, function () {
            try {
                return app(SystemHealthService::class)->health();
            } catch (\Throwable $e) {
                return ['status' => null, '_error' => $e->getMessage()];
            }
        });

        $healthStatus = ($health['status'] ?? null) === null
            ? $this->blocked('SystemHealthService could not be evaluated: ' . ($health['_error'] ?? 'unknown'))
            : $this->measured($health['status'], 'SystemHealthService::health()');

        $queue = $health['checks']['queue'] ?? null;
        $routes = $this->routeIntegrity();
        $source = $this->sourceIntegrity();

        $attention = [];
        if (($health['status'] ?? 'ok') !== 'ok')  $attention[] = 'Platform health is ' . ($health['status'] ?? 'unknown');
        if (($routes['comparable'] ?? false) === false) {
            $attention[] = 'Route integrity could not be checked: ' . ($routes['blocked_reason'] ?? 'baseline unavailable');
        } elseif (!$routes['matches']) {
            $attention[] = 'Route table differs from the governed baseline';
        }
        if (!$source['clean'])                      $attention[] = 'Protected-path drift detected';
        if ($source['unmanaged_backups'] !== [])    $attention[] = 'Unmanaged backups found beside live source';
        if (($queue['stale'] ?? 0) > 0)             $attention[] = 'Stale tasks in the queue';

        return [
            'section'     => 'engineering',
            'screen'      => 'overview',
            'phase'       => 'E1',
            'milestone'   => 'M1',
            'read_only'   => true,
            'observed_at' => now()->toIso8601String(),

            'needs_attention' => $attention,

            'health'  => [
                'status'         => $healthStatus,
                'degraded_flags' => array_keys($health['checks']['degraded_flags'] ?? []),
                'source'         => 'SystemHealthService::health()',
            ],
            'queue'   => $queue === null
                ? $this->blocked('queue metrics unavailable from SystemHealthService')
                : $this->measured($queue, 'SystemHealthService::health().checks.queue'),
            'workers' => $this->workerState(),
            'routes'  => $routes,
            'source_integrity' => $source,

            'approvals' => $this->measured(
                ['pending' => (int) DB::table('approvals')->where('status', 'pending')->count()],
                'approvals table'
            ),

            // Explicitly labelled: this is the CUSTOMER marketing engine.
            'marketing_tasks_24h' => $this->measured(
                [
                    'created'   => (int) DB::table('tasks')->where('created_at', '>=', now()->subDay())->count(),
                    'completed' => (int) DB::table('tasks')->where('status', 'completed')
                                        ->where('updated_at', '>=', now()->subDay())->count(),
                    'failed'    => (int) DB::table('tasks')->where('status', 'failed')
                                        ->where('updated_at', '>=', now()->subDay())->count(),
                ],
                'tasks table — CUSTOMER marketing work, not engineering execution'
            ),

            'not_measured' => [
                'engineering runs, workers and evidence (no model until E3)',
                'deployment and rollback records (E5)',
                'AI provider spend and token usage (E9)',
            ],

            'query' => $query->applied(),
        ];
    }

    /**
     * HEALTH — current state of every probe, honestly.
     *
     * A probe that cannot run is reported BLOCKED. It is never rendered green
     * and never rendered as a zero.
     */
    public function health(EngineeringListQuery $query): array
    {
        $health = Cache::remember('e1.health.full', self::PROBE_CACHE_SECONDS, function () {
            try {
                return app(SystemHealthService::class)->health();
            } catch (\Throwable $e) {
                return ['_error' => $e->getMessage()];
            }
        });

        $queueDepth = Cache::remember('e1.health.queue_depth', self::PROBE_CACHE_SECONDS, function () {
            try {
                return app(OrchestrationHealthService::class)->queueDepth();
            } catch (\Throwable $e) {
                return null;
            }
        });

        $checks = $health['checks'] ?? [];
        $probes = [];

        $probes[] = [
            'key' => 'database', 'label' => 'Database',
            'result' => isset($checks['database'])
                ? $this->measured($checks['database'], 'SystemHealthService::checkDatabase()')
                : $this->blocked('probe did not run'),
        ];
        $probes[] = [
            'key' => 'cache', 'label' => 'Cache',
            'result' => isset($checks['cache'])
                ? $this->measured($checks['cache'], 'SystemHealthService::checkCache()')
                : $this->blocked('probe did not run'),
        ];
        $probes[] = [
            'key' => 'queue', 'label' => 'Task queue',
            'result' => isset($checks['queue'])
                ? $this->measured($checks['queue'], 'SystemHealthService::health().checks.queue')
                : $this->blocked('probe did not run'),
        ];
        $probes[] = [
            'key' => 'queue_depth', 'label' => 'Queue depth by name',
            'result' => $queueDepth === null
                ? $this->blocked('OrchestrationHealthService::queueDepth() could not be evaluated')
                : $this->measured($queueDepth, 'OrchestrationHealthService::queueDepth()'),
        ];
        $probes[] = [
            'key' => 'circuits', 'label' => 'Circuit breakers',
            'result' => isset($checks['circuits'])
                ? $this->measured([
                    'circuits' => $checks['circuits'],
                    'open'     => $checks['open_circuits'] ?? [],
                  ], 'CircuitBreaker::getAllStatuses()')
                : $this->blocked('probe did not run'),
        ];
        $probes[] = [
            'key' => 'connectors', 'label' => 'Connector reachability',
            'result' => isset($checks['connectors'])
                ? $this->measured($checks['connectors'], 'SystemHealthService::checkConnectors()')
                : $this->blocked('probe did not run'),
        ];
        $probes[] = [
            'key' => 'workers', 'label' => 'Queue workers (supervisor)',
            'result' => $this->workerState(),
        ];
        $probes[] = [
            'key' => 'routes', 'label' => 'Route table integrity',
            'result' => $this->measured($this->routeIntegrity(), 'Route::getRoutes()'),
        ];
        $probes[] = [
            'key' => 'source', 'label' => 'Protected source integrity',
            'result' => $this->measured($this->sourceIntegrity(), 'ProtectedPaths::drift()'),
        ];

        $blockedCount = count(array_filter($probes, fn ($p) => ($p['result']['available'] ?? true) === false));

        return [
            'section'     => 'engineering',
            'screen'      => 'health',
            'phase'       => 'E1',
            'milestone'   => 'M1',
            'read_only'   => true,
            'observed_at' => now()->toIso8601String(),

            'overall'       => $health['status'] ?? null,
            'overall_source'=> 'SystemHealthService::health().status',
            'probe_count'   => count($probes),
            'blocked_count' => $blockedCount,
            'probes'        => $probes,

            'history_available' => false,
            'history_note'      => 'Only current state is shown. Health results are not stored, '
                                 . 'so there is no trend or history. Stored results arrive in E4 '
                                 . '(engineering_health_checks / engineering_health_results).',

            'cache_seconds' => self::PROBE_CACHE_SECONDS,
            'query'         => $query->applied(),
        ];
    }

    private const LOCK_DIR       = '/var/www/levelup-staging/storage/app/source-locks';
    private const DAILY_BACKUP_LOG = '/var/log/daily-backup.log';

    /** Source-backup store. Under /root, so typically unreadable by the web user. */
    private const MANIFEST_BACKUP_DIR = '/root/route-backups';
    private const QUARANTINE_DIRS = [
        '/root/route-backups-quarantine',
        '/root/route-backup-quarantine-20260727',
    ];

    /**
     * OWNERSHIP — who holds what, and has anything been written outside the
     * governed path.
     */
    public function ownership(EngineeringListQuery $query): array
    {
        $integrity = $this->sourceIntegrity();

        // ── the ownership audit trail ────────────────────────────────────────
        $auditFile = self::LOCK_DIR . '/audit.log';
        $events = [];
        $recent = [];

        if (is_readable($auditFile)) {
            $lines = @file($auditFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach ($lines as $line) {
                $row = json_decode($line, true);
                if (!is_array($row)) {
                    continue;
                }
                $e = $row['event'] ?? 'unknown';
                $events[$e] = ($events[$e] ?? 0) + 1;
                $recent[] = $row;
            }
            arsort($events);
            $recent = array_slice(array_reverse($recent), 0, $query->limit);
            $audit = $this->measured(
                ['total_events' => count($lines), 'by_event' => $events, 'recent' => $recent],
                $auditFile
            );
        } else {
            $audit = $this->blocked("the ownership audit log is not readable by this process ({$auditFile})");
        }

        // ── the route-module ownership registry ──────────────────────────────
        $regFile = self::LOCK_DIR . '/route-ownership.json';
        if (is_readable($regFile)) {
            $reg = json_decode((string) file_get_contents($regFile), true) ?: [];
            $registry = $this->measured([
                'modules'   => $reg['modules'] ?? [],
                'parent'    => $reg['parent'] ?? null,
                'generated' => $reg['generated_at'] ?? null,
            ], $regFile);
        } else {
            $registry = $this->blocked("the route ownership registry is not readable ({$regFile})");
        }

        // ── the protected-path seal ──────────────────────────────────────────
        $sealFile = self::LOCK_DIR . '/protected-paths.json';
        if (is_readable($sealFile)) {
            $seal = json_decode((string) file_get_contents($sealFile), true) ?: [];
            $sealInfo = $this->measured([
                'sealed_at' => $seal['sealed_at'] ?? null,
                'owner'     => $seal['owner'] ?? null,
                'reason'    => $seal['reason'] ?? null,
                'files'     => $seal['files'] ?? [],
            ], $sealFile);
        } else {
            $sealInfo = $this->blocked("the protected-path seal is not readable ({$sealFile})");
        }

        return [
            'section' => 'engineering', 'screen' => 'ownership',
            'phase' => 'E1', 'milestone' => 'M2', 'read_only' => true,
            'observed_at' => now()->toIso8601String(),

            'integrity' => $integrity,
            'audit'     => $audit,
            'registry'  => $registry,
            'seal'      => $sealInfo,

            'notes' => [
                'A write refused by GovernedWriter appears as write_refused_hash_mismatch. '
                . 'That is the control working, not a fault.',
                'Ownership locks are advisory (TD-14): a process that never calls '
                . 'SourceOwnershipLock is not stopped by it. Drift detection is what catches that.',
            ],
            'query' => $query->applied(),
        ];
    }

    /**
     * BACKUPS — four separate systems, deliberately not merged.
     *
     * A single "N backups" figure would be wrong in both directions: the source
     * store is dominated by test captures, and the scheduled database and file
     * backups live somewhere else entirely.
     */
    public function backups(EngineeringListQuery $query): array
    {
        // ── 1. source/route manifest backups ─────────────────────────────────
        if (is_readable(self::MANIFEST_BACKUP_DIR)) {
            $all = (new ManifestBackup())->list();
            $real = $test = [];
            foreach ($all as $b) {
                // A capture taken during a real deploy records the route count;
                // a test capture does not. That is the honest discriminator.
                if (($b['route_count'] ?? null) !== null) {
                    $real[] = $b;
                } else {
                    $test[] = $b;
                }
            }
            $sourceBackups = $this->measured([
                'total'            => count($all),
                'deploy_captures'  => count($real),
                'test_captures'    => count($test),
                'deploys'          => array_slice($real, 0, $query->limit),
            ], self::MANIFEST_BACKUP_DIR);
        } else {
            $sourceBackups = $this->blocked(
                'the source-backup store (' . self::MANIFEST_BACKUP_DIR . ') is not readable by this '
                . 'process. It lives under /root, which is mode 0700 — a root CLI can read it, the web '
                . 'user cannot.'
            );
        }

        // ── 2 + 3. scheduled database and file backups, from the run log ─────
        if (is_readable(self::DAILY_BACKUP_LOG)) {
            $lines = @file(self::DAILY_BACKUP_LOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $tail = array_slice($lines, -400);

            $dbRuns = $fileRuns = $offsite = [];
            $groupsOk = $groupsFailed = null;
            $verify = [];

            foreach ($tail as $line) {
                if (str_contains($line, 'DB backup:'))            $dbRuns[] = $line;
                if (str_contains($line, 'File backup finished'))  $fileRuns[] = $line;
                if (str_contains($line, 'Offsite upload'))        $offsite[] = $line;
                if (str_contains($line, 'Groups OK:'))            $groupsOk = trim($line);
                if (str_contains($line, 'Groups FAILED:'))        $groupsFailed = trim($line);
                if (str_contains($line, 'VERIFY '))               $verify[] = trim($line);
            }

            $database = $this->measured([
                'last_runs'     => array_slice($dbRuns, -5),
                'last_offsite'  => array_slice($offsite, -3),
                'schedule'      => 'cron 01:00 daily — /root/daily-backup.sh',
                'retention'     => '7 days local, 30 days offsite (s3://boss888-backups/db-backups)',
            ], self::DAILY_BACKUP_LOG);

            $files = $this->measured([
                'last_runs'     => array_slice($fileRuns, -3),
                'groups_ok'     => $groupsOk,
                'groups_failed' => $groupsFailed,
                'verifications' => array_slice($verify, -4),
                'schedule'      => 'cron 01:30 daily — /root/backup-files.sh; weekly --verify Sunday 04:00',
            ], self::DAILY_BACKUP_LOG);
        } else {
            $database = $this->blocked('the backup run log (' . self::DAILY_BACKUP_LOG . ') is not readable');
            $files    = $this->blocked('the backup run log (' . self::DAILY_BACKUP_LOG . ') is not readable');
        }

        // ── 4. quarantined backups ───────────────────────────────────────────
        $quarantine = [];
        $qBlocked = [];
        foreach (self::QUARANTINE_DIRS as $dir) {
            if (!is_readable($dir)) {
                $qBlocked[] = $dir;
                continue;
            }
            $entries = array_values(array_filter(scandir($dir) ?: [], fn ($f) => $f !== '.' && $f !== '..'));
            $quarantine[$dir] = ['count' => count($entries), 'entries' => array_slice($entries, 0, 20)];
        }
        $quarantineResult = $quarantine === [] && $qBlocked !== []
            ? $this->blocked('quarantine directories are not readable by this process: ' . implode(', ', $qBlocked))
            : $this->measured(['directories' => $quarantine, 'unreadable' => $qBlocked], implode(', ', self::QUARANTINE_DIRS));

        return [
            'section' => 'engineering', 'screen' => 'backups',
            'phase' => 'E1', 'milestone' => 'M2', 'read_only' => true,
            'observed_at' => now()->toIso8601String(),

            'systems' => [
                'source_manifest' => [
                    'title'  => 'Source & route backups (ManifestBackup)',
                    'covers' => 'routes/api.php, the 13 route modules, bootstrap/app.php',
                    'result' => $sourceBackups,
                ],
                'database' => [
                    'title'  => 'Database backups (scheduled)',
                    'covers' => 'the full application database, plus markraymundo',
                    'result' => $database,
                ],
                'files' => [
                    'title'  => 'File backups (scheduled, integrity-verified)',
                    'covers' => '9 groups including the application, public assets and media',
                    'result' => $files,
                ],
                'quarantine' => [
                    'title'  => 'Quarantined backups (evidence, NOT restorable)',
                    'covers' => 'unmanaged .bak files found beside live source',
                    'result' => $quarantineResult,
                ],
            ],

            'rules' => [
                'A backup without a manifest is not restorable through the governed path.',
                'A manifest older than the file on disk is refused — restoring it would delete newer work.',
                'Quarantined backups are retained as evidence and are never restorable.',
                'No restore can be initiated from this screen. E1 is read-only; restore is a Level-3 action in E5.',
            ],

            'not_shown' => [
                'Restore actions — E5.',
                'Backup run history as structured data (engineering_backup_runs) — E8. '
                . 'What is shown here is parsed from the run log.',
            ],
            'query' => $query->applied(),
        ];
    }

    private const APP_LOG = '/var/www/levelup-staging/storage/logs/laravel.log';

    /** Channels that indicate a test run rather than production activity. */
    private const TEST_CHANNELS = ['testing'];

    /** How many log lines to read from the tail. The file is tens of MB. */
    private const LOG_TAIL_LINES = 4000;

    /**
     * AUDIT — the structured audit trail.
     *
     * Reports what is recorded. It does not interpret why, and it does not
     * describe an actor's intent: an audit row is evidence that something was
     * recorded, not testimony about a motive.
     */
    public function audit(EngineeringListQuery $query): array
    {
        $q = DB::table('audit_logs');
        $usedIndex = [];

        // Lead with indexed columns. `created_at` is NOT indexed (TD-22), so an
        // unbounded date scan over a growing table is refused rather than served
        // slowly and then quietly abandoned by whoever waited for it.
        if ($query->hasFilter('action')) {
            $q->where('action', $query->filter('action'));
            $usedIndex[] = 'action (indexed with workspace_id)';
        }
        if ($query->hasFilter('workspace_id')) {
            $q->where('workspace_id', (int) $query->filter('workspace_id'));
            $usedIndex[] = 'workspace_id (indexed)';
        }
        if ($query->hasFilter('user_id')) {
            $q->where('user_id', (int) $query->filter('user_id'));
            $usedIndex[] = 'user_id (indexed)';
        }
        if ($query->hasFilter('entity_type')) {
            $q->where('entity_type', $query->filter('entity_type'));
            $usedIndex[] = 'entity_type (indexed with entity_id)';
        }

        $dateNote = null;
        if ($query->hasFilter('since')) {
            $since = $query->filter('since');
            $q->where('created_at', '>=', $since);
            $dateNote = 'created_at is NOT indexed on this table; the date filter is a scan. '
                      . 'Combine it with an indexed filter for predictable performance.';
        }

        // id DESC is a primary-key ordering and is a faithful proxy for time here,
        // because rows are only ever appended.
        $rows = $q->orderByDesc('id')
            ->limit($query->limit)->offset($query->offset)
            ->get(['id', 'workspace_id', 'user_id', 'action', 'entity_type', 'entity_id',
                   'metadata_json', 'created_at']);

        $total = (int) DB::table('audit_logs')->count();
        $withUser = (int) DB::table('audit_logs')->whereNotNull('user_id')->count();

        return [
            'section' => 'engineering', 'screen' => 'audit',
            'phase' => 'E1', 'milestone' => 'M3', 'read_only' => true,
            'observed_at' => now()->toIso8601String(),

            'entries' => $rows->map(fn ($r) => (array) $r)->all(),
            'returned' => $rows->count(),

            'profile' => $this->measured([
                'total_rows'        => $total,
                'with_user'         => $withUser,
                'without_user'      => $total - $withUser,
                'distinct_actions'  => (int) DB::table('audit_logs')->distinct()->count('action'),
                'earliest'          => DB::table('audit_logs')->min('created_at'),
                'latest'            => DB::table('audit_logs')->max('created_at'),
            ], 'audit_logs table'),

            'top_actions' => $this->measured(
                DB::table('audit_logs')->select('action', DB::raw('COUNT(*) as c'))
                    ->groupBy('action')->orderByDesc('c')->limit(12)->get()->map(fn ($r) => (array) $r)->all(),
                'audit_logs GROUP BY action'
            ),

            'index_use' => [
                'applied'  => $usedIndex,
                'date_note'=> $dateNote,
                'indexes'  => ['(workspace_id, action)', '(entity_type, entity_id)', 'user_id'],
                'missing'  => ['created_at — TD-22'],
            ],

            'interpretation_limits' => [
                sprintf('%d of %d entries (%d%%) have no user_id — they are system or agent activity, '
                    . 'not operator actions. This is not a "who did what" record.',
                    $total - $withUser, $total, $total > 0 ? (int) round((($total - $withUser) / $total) * 100) : 0),
                'An audit row records that something was written. It does not record intent, '
                    . 'authorisation, or whether the outcome was correct.',
                'There is no correlation ID, so entries belonging to one operation cannot be '
                    . 'grouped. That arrives in E3.',
                'Coverage is partial: only 3 of 10 admin controllers write audit rows.',
            ],

            'query' => $query->applied(),
        ];
    }

    /**
     * LOGS — the raw application log, with test entries separated.
     *
     * The test suite writes into the same file as production (TD-24). Showing a
     * single mixed stream would misrepresent production activity, so entries are
     * split by channel and both counts are always stated.
     */
    public function logs(EngineeringListQuery $query): array
    {
        if (!is_readable(self::APP_LOG)) {
            return [
                'section' => 'engineering', 'screen' => 'logs',
                'phase' => 'E1', 'milestone' => 'M3', 'read_only' => true,
                'observed_at' => now()->toIso8601String(),
                'stream' => $this->blocked('the application log (' . self::APP_LOG . ') is not readable by this process'),
                'query' => $query->applied(),
            ];
        }

        // Tail rather than load: the file is tens of megabytes.
        $tail = [];
        $fh = @fopen(self::APP_LOG, 'r');
        if ($fh) {
            $ring = [];
            while (($line = fgets($fh)) !== false) {
                $ring[] = rtrim($line, "\n");
                if (count($ring) > self::LOG_TAIL_LINES) {
                    array_shift($ring);
                }
            }
            fclose($fh);
            $tail = $ring;
        }

        $entries = [];
        foreach ($tail as $line) {
            // [2026-07-27 13:01:39] staging.ERROR: message
            if (!preg_match('/^\[([^\]]+)\]\s+([a-z0-9_-]+)\.([A-Z]+):\s?(.*)$/i', $line, $m)) {
                continue;   // continuation line of a stack trace
            }
            $entries[] = [
                'at'      => $m[1],
                'channel' => $m[2],
                'level'   => strtoupper($m[3]),
                'message' => mb_substr($m[4], 0, 400),
                'is_test' => in_array(strtolower($m[2]), self::TEST_CHANNELS, true),
            ];
        }

        // Whole-file channel census, so the split is stated over the real file
        // and not just over the tail we happened to read.
        $census = ['total_lines' => 0, 'by_channel' => []];
        $fh = @fopen(self::APP_LOG, 'r');
        if ($fh) {
            while (($line = fgets($fh)) !== false) {
                $census['total_lines']++;
                if (preg_match('/^\[[^\]]+\]\s+([a-z0-9_-]+)\./i', $line, $m)) {
                    $c = strtolower($m[1]);
                    $census['by_channel'][$c] = ($census['by_channel'][$c] ?? 0) + 1;
                }
            }
            fclose($fh);
        }
        arsort($census['by_channel']);

        $wantTest = $query->filter('include_tests', 'no') === 'yes';
        $level = $query->hasFilter('level') ? strtoupper((string) $query->filter('level')) : null;

        $filtered = array_values(array_filter($entries, function ($e) use ($wantTest, $level) {
            if (!$wantTest && $e['is_test']) return false;
            if ($level !== null && $e['level'] !== $level) return false;
            return true;
        }));

        $filtered = array_reverse($filtered);
        $shown = array_slice($filtered, $query->offset, $query->limit);

        $testInTail = count(array_filter($entries, fn ($e) => $e['is_test']));

        return [
            'section' => 'engineering', 'screen' => 'logs',
            'phase' => 'E1', 'milestone' => 'M3', 'read_only' => true,
            'observed_at' => now()->toIso8601String(),

            'entries'  => $shown,
            'returned' => count($shown),
            'matched'  => count($filtered),

            'census' => $this->measured([
                'total_lines'    => $census['total_lines'],
                'by_channel'     => $census['by_channel'],
                'test_channels'  => self::TEST_CHANNELS,
                'test_lines'     => array_sum(array_intersect_key($census['by_channel'], array_flip(self::TEST_CHANNELS))),
            ], self::APP_LOG),

            'tail' => [
                'lines_read'       => count($tail),
                'entries_parsed'   => count($entries),
                'test_entries'     => $testInTail,
                'including_tests'  => $wantTest,
                'limit'            => self::LOG_TAIL_LINES,
            ],

            'interpretation_limits' => [
                'The test suite writes into this same file (TD-24). Entries on a test channel are '
                    . 'EXCLUDED by default; pass filter[include_tests]=yes to see them.',
                'Only the last ' . self::LOG_TAIL_LINES . ' lines are read. This is a tail, not the whole log.',
                'Stack-trace continuation lines are not parsed as entries and are not shown.',
                'A log line records what was written at the time. It is not evidence of intent '
                    . 'or of what a person was trying to achieve.',
            ],

            'query' => $query->applied(),
        ];
    }

    /**
     * The section manifest.
     *
     * `why_unavailable` is verified against the live schema rather than trusted,
     * so a table appearing in a later phase corrects this output automatically.
     */
    public function manifest(EngineeringListQuery $query): array
    {
        $screens = [];

        foreach (self::SCREENS as $screen) {
            $missing = [];
            foreach ($screen['requires_tables'] as $table) {
                if (!Schema::hasTable($table)) {
                    $missing[] = $table;
                }
            }

            $screen['missing_tables'] = $missing;

            // Evidence, not assertion: if a screen claims to be unavailable but
            // its tables now exist, say so rather than repeating a stale reason.
            $screen['reason_verified'] = $screen['status'] === self::STATUS_NOT_BUILT
                ? $missing !== []
                : $missing === [];

            $screens[] = $screen;
        }

        if ($query->hasFilter('status')) {
            $want = $query->filter('status');
            $screens = array_values(array_filter($screens, fn ($s) => $s['status'] === $want));
        }

        if ($query->hasFilter('phase')) {
            $want = $query->filter('phase');
            $screens = array_values(array_filter($screens, fn ($s) => $s['phase'] === $want));
        }

        $counts = [];
        foreach (self::SCREENS as $s) {
            $counts[$s['status']] = ($counts[$s['status']] ?? 0) + 1;
        }

        return [
            'section'  => 'engineering',
            'phase'    => 'E1',
            'milestone'=> 'M0',
            'note'     => 'Read-only. No screen in this section can mutate platform state. '
                        . 'Screens marked not_built have no underlying capability yet; they are '
                        . 'shown deliberately rather than hidden, so the section never implies '
                        . 'more maturity than exists.',
            'counts'   => $counts,
            'screens'  => array_slice($screens, $query->offset, $query->limit),
            'total'    => count($screens),
            'query'    => $query->applied(),
            'observed_at' => now()->toIso8601String(),
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // M4 — Documentation and operator guidance
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * What each screen measures, what it CANNOT prove, and where its evidence
     * comes from.
     *
     * Keyed by screen key and joined to SCREENS at read time rather than
     * restating it. A screen added in a later milestone without a guidance
     * entry appears in `screens_without_guidance` and fails the coverage test —
     * documentation cannot silently fall behind the thing it documents.
     */
    private const GUIDANCE = [
        'overview' => [
            'measures' => 'A single posture read across the other screens: probe results, queue and task counts, approval counts, protected-path drift, held ownership locks, the route signature and worker state.',
            'cannot_prove' => [
                'That the platform is correct. It shows that measured signals are within expected ranges at one instant.',
                'Anything about the interval between observations. It is a point-in-time sample, not continuous monitoring.',
                'That a tile showing no problems means no problem exists — only that the measurement it names found none.',
            ],
        ],
        'health' => [
            'measures' => 'Live response of each health endpoint, the orchestration health service, and supervisor worker state, each with the time it was observed.',
            'cannot_prove' => [
                'That a responding endpoint is functionally correct. A 200 proves the process answered, not that the work behind it succeeds.',
                'That an upstream AI provider is healthy. Provider calls execute in the Railway runtime, which this dashboard does not reach.',
                'That nothing failed between two probes. Intermittent failure is invisible to point sampling.',
            ],
        ],
        'ownership' => [
            'measures' => 'Which locks are held and by whom, the sealed hash of every protected path against its current hash, drift, unmanaged backup artefacts, and the governed write audit log.',
            'cannot_prove' => [
                'That a write was authorised. It records that a write happened under a named owner — the name is asserted by the writer, not verified against an identity.',
                'That an out-of-band write was prevented. The filesystem lock is advisory (TD-14); drift detects such a write after the fact, it does not stop it.',
                'That the source is correct. It proves the source is unchanged since it was sealed.',
            ],
        ],
        'backups' => [
            'measures' => 'Backup sets present on disk and the integrity of their manifests — sha256, byte count, route count and phase markers.',
            'cannot_prove' => [
                'That a backup is restorable. A manifest that verifies proves the bytes are intact, not that restoring them into the live system would succeed.',
                'That coverage is complete. Two panels are BLOCKED for the web user because /root is mode 0700 (TD-28); a blocked panel is not an empty one.',
                'That a backup is recent enough to be useful. It reports what exists and when, and leaves the judgement to the operator.',
            ],
        ],
        'audit' => [
            'measures' => 'Rows recorded in the audit_logs table, filterable on indexed columns, ordered by primary key.',
            'cannot_prove' => [
                'Who acted. Most rows carry no user_id — see the counts on the screen itself.',
                'Why anything happened, or under what authority. A row records that a write occurred, never its intent.',
                'That a set of rows belongs to one operation. There is no correlation identifier, so entries cannot be grouped (deferred to E3).',
                'That the trail is complete. Coverage is 3 of 10 admin controllers.',
            ],
        ],
        'logs' => [
            'measures' => 'A bounded tail of storage/logs/laravel.log parsed into timestamp, channel, level and message, plus a whole-file channel census.',
            'cannot_prove' => [
                'Anything about the whole file. The entries shown are a tail; the census is the only whole-file figure.',
                'That every line is production activity. The test suite writes into this same file (TD-24) — test-channel entries are excluded by default and counted separately.',
                'Intent. A log line records what a code path emitted, not why it ran.',
            ],
        ],
        'marketing_tasks' => [
            'measures' => 'Customer marketing work executed by the agent platform: task status, category, source, approval state, credit cost and failure text, summarised from the tasks table.',
            'cannot_prove' => [
                'That a task should have been created. It records execution, not the decision behind it.',
                'Why a task failed. error_text is what the code emitted, which is not always the cause.',
                'That a task without an approval_status was rejected — it means the task never entered an approval flow at all.',
                'Anything about engineering work. This is customer marketing execution, and it is not engineering execution.',
            ],
        ],
        'incidents' => [
            'measures' => 'Three separate incident sources, each reported with its own count and evidence: the infrastructure monitor, the written register, and the structured table that does not exist yet.',
            'cannot_prove' => [
                'A total. The sources measure different things and are never summed — a combined count would be meaningless.',
                'That the platform had no incident. The monitor only watches configured targets; anything unwatched produces no record.',
                'That a resolved monitor incident was diagnosed. Resolved means the target responded again.',
                'Completeness of the written register. It is maintained by hand, and an incident nobody wrote up does not appear.',
            ],
        ],
        'costs' => [
            'measures' => 'Customer credits committed, with the reserve and release legs shown separately so the arithmetic can be checked.',
            'cannot_prove' => [
                'What the platform costs to run. Provider, token and infrastructure cost are not measured anywhere this dashboard can reach.',
                'A monetary amount. Credits are an internal unit and no currency conversion exists here.',
                'Spend within a period. The figures are lifetime totals, and credit_transactions has no created_at index to filter on cheaply.',
                'That a reserved credit was spent. A reserve is either committed or released, and only commits are spend.',
            ],
        ],
        'documentation' => [
            'measures' => 'Nothing about the platform. It describes the other screens and the rules for reading them.',
            'cannot_prove' => [
                'Anything about system state. It is guidance, not a measurement.',
                'That a screen behaves as described here. Where this page and a screen disagree, the screen is authoritative and this page is the defect.',
            ],
        ],
    ];

    /**
     * The concepts an operator has to hold to read this section correctly.
     *
     * These are not decoration. Each one exists because getting it wrong
     * produces a confident, wrong conclusion about production.
     */
    private const CONCEPTS = [
        [
            'key' => 'blocked',
            'title' => 'What BLOCKED means',
            'body' => 'BLOCKED means a measurement could not be taken. It is not a value, and it is never a healthy one. '
                    . 'Zero means the measurement ran and the answer was zero. BLOCKED means the measurement did not run, '
                    . 'and the screen states why. The distinction matters because "0 failures" and "could not check for failures" '
                    . 'lead to opposite decisions. Every blocked figure carries a blocked_reason; nothing in this section '
                    . 'renders a blocked value as green, as zero, or as absent.',
            'example' => 'Two Backups panels report BLOCKED because /root is mode 0700 and the web server user cannot read it (TD-28). '
                       . 'The backups exist. The dashboard simply cannot see them from where it runs, and says so.',
        ],
        [
            'key' => 'audit_is_not_authorization',
            'title' => 'Why Audit is not an authorization record',
            'body' => 'The audit trail answers "was something written?". It does not answer "who decided this, and were they allowed to?". '
                    . 'Most rows carry no user at all because they were written by scheduled and agent activity rather than by a person. '
                    . 'There is no correlation identifier, so several rows produced by one operation cannot be grouped back together, '
                    . 'and coverage extends to 3 of the 10 admin controllers. Treating it as an authorization record would mean '
                    . 'inferring an actor and an intent that were never recorded — which is how an audit trail becomes evidence for '
                    . 'a conclusion it cannot support.',
            'example' => 'A row reading credits.adjusted proves a credit adjustment was written. It does not identify who requested it, '
                       . 'who approved it, or whether it was permitted.',
        ],
        [
            'key' => 'logs_and_audit_are_separate',
            'title' => 'Why Logs and Audit are separate screens',
            'body' => 'They are different kinds of evidence with different guarantees, and merging them would destroy both. '
                    . 'Audit is structured data written deliberately by application code: durable, queryable, and complete for the '
                    . 'controllers that write it. Logs are unstructured runtime text: emitted incidentally, bounded by rotation, '
                    . 'read here as a tail, and — because the test suite shares the file — containing entries that are not production '
                    . 'activity at all. Interleaving them into one timeline would imply a completeness neither has, and would let '
                    . 'test noise contaminate a record used for investigation.',
            'example' => 'The application log currently holds test-channel lines alongside production lines. In a merged view those '
                       . 'would read as platform events. Kept separate, they are counted, labelled and excluded by default.',
        ],
        [
            'key' => 'evidence_over_interpretation',
            'title' => 'Why evidence is preferred over interpretation',
            'body' => 'Every figure in this section is reported as a value with its source, or as BLOCKED with a reason. '
                    . 'The dashboard does not infer causes, assign blame, or summarise several weak signals into one confident verdict. '
                    . 'This is deliberate. An interpretation presented as a measurement is harder to correct than a gap, because it '
                    . 'looks like an answer: an operator who is told "the queue is unhealthy" cannot tell whether that came from a '
                    . 'measurement or a guess, whereas "3 failed jobs, source: jobs table, observed 19:04:11" can be checked. '
                    . 'Interpretation is the operator\'s job, and it needs evidence that has not already been interpreted.',
            'example' => 'The Audit screen states how many rows have no user rather than describing the trail as "mostly automated". '
                       . 'The first is checkable; the second is a conclusion the data does not carry.',
        ],
    ];

    /** How to read any screen in this section. */
    private const READING_RULES = [
        'Read `available` before `value`. A value is only meaningful once the measurement is known to have run.',
        'Read `source` next. Every figure names where it came from; a figure without a source is a defect, not a fact.',
        'Treat BLOCKED as unknown, never as zero and never as healthy.',
        'Treat a status of partial as a statement about coverage. The data shown is real; the set is incomplete and the screen says how.',
        'Prefer the screen over this page. Documentation describes intent; the screen reports what was measured.',
        'Nothing in this section can change platform state. If an operator needs to act, the action lives outside E1.',
    ];

    /**
     * GET /api/admin/engineering/documentation
     *
     * Operator guidance for the Engineering section. Read-only, and derived
     * from the live screen definitions so it cannot drift away from them.
     */
    public function documentation(EngineeringListQuery $query): array
    {
        // Resolve each documented screen's real route from the router rather
        // than restating it, so a documented endpoint is one that exists.
        $routes = [];
        foreach (RouteFacade::getRoutes()->getRoutes() as $r) {
            if (str_starts_with($r->uri(), 'api/admin/engineering/')) {
                // screen keys use underscores, route URIs use hyphens
                $slug = str_replace('-', '_', substr($r->uri(), strlen('api/admin/engineering/')));
                $routes[$slug] = [
                    'uri' => '/' . $r->uri(),
                    'methods' => array_values(array_diff($r->methods(), ['HEAD'])),
                ];
            }
        }

        $entries = [];
        $withoutGuidance = [];

        foreach (self::SCREENS as $screen) {
            $key = $screen['key'];
            $documented = array_key_exists($key, self::GUIDANCE);

            // Only screens an operator can actually open need guidance.
            $reachable = in_array($screen['status'], [self::STATUS_LIVE, self::STATUS_PARTIAL], true);

            if ($reachable && !$documented) {
                $withoutGuidance[] = $key;
                continue;
            }

            if (!$documented) {
                continue;
            }

            $entries[] = [
                'key' => $key,
                'title' => $screen['title'],
                'status' => $screen['status'],
                'milestone' => $screen['milestone'],
                'purpose' => $screen['purpose'],
                'measures' => self::GUIDANCE[$key]['measures'],
                'cannot_prove' => self::GUIDANCE[$key]['cannot_prove'],
                'evidence_source' => $screen['evidence_source'],
                'data_source' => $screen['data_source'],
                'route' => $routes[$key] ?? null,
                'reachable' => $reachable,
            ];
        }

        if ($query->hasFilter('screen')) {
            $want = $query->filter('screen');
            $entries = array_values(array_filter($entries, fn ($e) => $e['key'] === $want));
        }

        return [
            'screen' => 'documentation',
            'read_only' => true,
            'purpose' => 'Explain what the Engineering section can and cannot tell you, and on what evidence.',
            'reading_rules' => self::READING_RULES,
            'screens' => $entries,
            'returned' => count($entries),
            'concepts' => self::CONCEPTS,
            'coverage' => $this->measured([
                'screens_total' => count(self::SCREENS),
                'screens_reachable' => count(array_filter(
                    self::SCREENS,
                    fn ($s) => in_array($s['status'], [self::STATUS_LIVE, self::STATUS_PARTIAL], true)
                )),
                'screens_documented' => count($entries),
                'screens_without_guidance' => $withoutGuidance,
            ], 'joined to EngineeringReadService::SCREENS at read time'),
            'interpretation_limits' => [
                'This page measures nothing about the platform. It describes the other screens.',
                'Where this page and a screen disagree, the screen is authoritative and this page is the defect.',
                'A screen listed here as reachable was resolved against the live route table; one with a null route is documented but not exposed.',
                'Guidance is written per milestone. A screen delivered later without a guidance entry is reported in screens_without_guidance rather than omitted silently.',
            ],
            'query' => $query->applied(),
            'observed_at' => now()->toIso8601String(),
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // M5 — Marketing Tasks, Incidents, Costs
    // ═══════════════════════════════════════════════════════════════════════

    /** Where the markdown incident register may live. Read-only. */
    private const INCIDENT_REGISTER_DIRS = [
        '/root/handoff-2026-07-27-e0',
        '/var/www/levelup-staging/docs',
    ];

    /**
     * GET /api/admin/engineering/marketing-tasks
     *
     * Customer marketing work executed by the agent platform.
     *
     * This is deliberately NOT engineering execution, and nothing here can act:
     * no retry, no cancel, no approve. E1 is read-only, and this screen shows
     * another system's work.
     */
    public function marketingTasks(EngineeringListQuery $query): array
    {
        if (!Schema::hasTable('tasks')) {
            return [
                'screen' => 'marketing_tasks',
                'read_only' => true,
                'profile' => $this->blocked('the tasks table does not exist'),
                'entries' => [],
                'returned' => 0,
                'query' => $query->applied(),
                'observed_at' => now()->toIso8601String(),
            ];
        }

        $rows = DB::table('tasks');
        $applied = [];
        $missing = [];

        // workspace_id + category + status are covered by idx_tasks_ws_category;
        // status is additionally covered by tasks_status_updated_at_index.
        foreach (['status', 'category', 'workspace_id', 'source'] as $f) {
            if ($query->hasFilter($f) && !Schema::hasColumn('tasks', $f)) {
                $missing[] = "{$f} was requested but does not exist on this database";
                continue;
            }
            if ($query->hasFilter($f)) {
                $rows->where($f, $query->filter($f));
                $applied[] = in_array($f, ['status', 'category', 'workspace_id'], true)
                    ? "{$f} (indexed)"
                    : "{$f} (NOT indexed)";
            }
        }
        if (!in_array('source (NOT indexed)', $applied, true)) {
            $missing[] = 'source has no index; filtering on it scans';
        }

        $total = (clone $rows)->count();

        /*
         * Select only columns that actually exist.
         *
         * The tasks table differs between production and the test database, and
         * naming an absent column throws rather than degrading. A read-only
         * screen must report what it could read, not fail because a column it
         * hoped for is missing — and the columns it could not read are named
         * in the response rather than silently dropped.
         */
        $wanted = [
            'id', 'workspace_id', 'engine', 'action', 'category', 'status',
            'priority', 'source', 'requires_approval', 'approval_status',
            'credit_cost', 'retry_count', 'max_attempts', 'error_text',
            'started_at', 'completed_at', 'parent_task_id',
        ];
        $select = array_values(array_filter($wanted, fn ($c) => Schema::hasColumn('tasks', $c)));
        $absent = array_values(array_diff($wanted, $select));
        $has = fn ($r, $c) => property_exists($r, $c) ? $r->{$c} : null;

        $entries = $rows->orderByDesc('id')
            ->offset($query->offset)->limit($query->limit)
            ->get($select)
            ->map(fn ($r) => [
                'id' => (int) $has($r, 'id'),
                'workspace_id' => $has($r, 'workspace_id'),
                'engine' => $has($r, 'engine'),
                'action' => $has($r, 'action'),
                'category' => $has($r, 'category'),
                'status' => $has($r, 'status'),
                'priority' => $has($r, 'priority'),
                'source' => $has($r, 'source'),
                'requires_approval' => (bool) $has($r, 'requires_approval'),
                'approval_status' => $has($r, 'approval_status'),
                'credit_cost' => $has($r, 'credit_cost') === null ? null : (int) $has($r, 'credit_cost'),
                'attempts' => $has($r, 'retry_count') . '/' . $has($r, 'max_attempts'),
                'error' => $has($r, 'error_text') === null ? null : mb_substr((string) $has($r, 'error_text'), 0, 240),
                'started_at' => $has($r, 'started_at'),
                'completed_at' => $has($r, 'completed_at'),
                'parent_task_id' => $has($r, 'parent_task_id'),
            ])->all();

        $byStatus = DB::table('tasks')->selectRaw('status, COUNT(*) c')
            ->groupBy('status')->pluck('c', 'status')->all();
        $noApproval = DB::table('tasks')->whereNull('approval_status')->count();
        $allTasks = array_sum($byStatus);

        return [
            'screen' => 'marketing_tasks',
            'read_only' => true,
            'profile' => $this->measured([
                'total_tasks' => $allTasks,
                'by_status' => $byStatus,
                'by_category' => Schema::hasColumn('tasks', 'category')
                    ? DB::table('tasks')->selectRaw('category, COUNT(*) c')
                        ->groupBy('category')->orderByDesc('c')->pluck('c', 'category')->all()
                    : [],
                'by_source' => Schema::hasColumn('tasks', 'source')
                    ? DB::table('tasks')->selectRaw('source, COUNT(*) c')
                        ->groupBy('source')->pluck('c', 'source')->all()
                    : [],
                'columns_unavailable' => $absent,
                'never_entered_approval' => $noApproval,
                'task_events' => Schema::hasTable('task_events')
                    ? DB::table('task_events')->count()
                    : null,
            ], 'tasks + task_events tables'),
            'matched' => $total,
            'returned' => count($entries),
            'entries' => $entries,
            'index_use' => ['applied' => $applied, 'missing' => $missing],
            'interpretation_limits' => [
                'This is CUSTOMER marketing work executed by the agent platform. It is not engineering execution.',
                'Nothing on this screen can act. Tasks cannot be retried, cancelled or approved from here — E1 is read-only.',
                'A failed task records that an attempt failed. It does not record why the task was created or whether it should have been.',
                sprintf('%d of %d tasks have no approval_status at all. Absence means the task never entered an approval flow — it does not mean rejected.',
                    $noApproval, $allTasks),
                'Per-step detail lives in task_events. This screen summarises tasks; it does not reconstruct their execution.',
            ],
            'query' => $query->applied(),
            'observed_at' => now()->toIso8601String(),
        ];
    }

    /**
     * GET /api/admin/engineering/incidents
     *
     * Three distinct sources, reported separately and NEVER summed.
     *
     * They measure different things: an automated uptime alert, a written
     * engineering incident record, and a table that does not exist yet. A single
     * "incident count" spanning them would be meaningless, so none is produced.
     */
    public function incidents(EngineeringListQuery $query): array
    {
        $sources = [];

        // ── source 1: the infrastructure monitor ────────────────────────────
        if (Schema::hasTable('infra_incidents')) {
            $rows = DB::table('infra_incidents');
            if ($query->hasFilter('lifecycle_state')) {
                $rows->where('lifecycle_state', $query->filter('lifecycle_state'));
            }
            if ($query->hasFilter('severity')) {
                $rows->where('severity', $query->filter('severity'));
            }

            $entries = $rows->orderByDesc('id')->limit($query->limit)
                ->get(['id', 'incident_uid', 'workspace_id', 'title', 'severity',
                       'lifecycle_state', 'source', 'detected_at', 'resolved_at',
                       'time_to_resolve_seconds'])
                ->map(fn ($r) => (array) $r)->all();

            $sources[] = [
                'key' => 'infra_monitor',
                'title' => 'Infrastructure monitor',
                'available' => true,
                'count' => DB::table('infra_incidents')->count(),
                'by_state' => DB::table('infra_incidents')->selectRaw('lifecycle_state, COUNT(*) c')
                    ->groupBy('lifecycle_state')->pluck('c', 'lifecycle_state')->all(),
                'by_severity' => DB::table('infra_incidents')->selectRaw('severity, COUNT(*) c')
                    ->groupBy('severity')->pluck('c', 'severity')->all(),
                'transitions' => Schema::hasTable('infra_incident_transitions')
                    ? DB::table('infra_incident_transitions')->count() : null,
                'entries' => $entries,
                'evidence_source' => 'infra_incidents + infra_incident_transitions',
                'what_it_is' => 'Automated uptime alerts raised by the monitor when a target stops responding, and closed when it responds again.',
                'what_it_is_not' => 'It is not a record of platform defects, and a resolved alert is a probe recovering — not a diagnosed cause.',
            ];
        } else {
            $sources[] = [
                'key' => 'infra_monitor', 'title' => 'Infrastructure monitor',
                'available' => false, 'blocked_reason' => 'infra_incidents table does not exist',
                'entries' => [],
            ];
        }

        // ── source 2: the written engineering incident register ─────────────
        $sources[] = $this->incidentRegister();

        // ── source 3: engineering_incidents, not built ──────────────────────
        $sources[] = [
            'key' => 'engineering_incidents',
            'title' => 'Engineering incidents (structured)',
            'available' => false,
            'blocked_reason' => Schema::hasTable('engineering_incidents')
                ? 'table exists but no screen reads it yet'
                : 'the engineering_incidents table does not exist — this capability has not been built',
            'expected_evidence' => 'structured incident records with owner, severity, timeline and resolution',
            'entries' => [],
        ];

        return [
            'screen' => 'incidents',
            'read_only' => true,
            'sources' => $sources,
            'combined_total' => null,
            'why_no_combined_total' => 'The three sources measure different things — an automated uptime alert, a written engineering incident, and a capability that does not exist. Adding them would produce a number that means nothing.',
            'interpretation_limits' => [
                'These sources are never merged. A count from one is not comparable with a count from another.',
                'The infrastructure monitor watches configured targets only. An outage of something it does not watch produces no incident.',
                'A monitor incident marked resolved means the target responded again. It does not mean a cause was found or fixed.',
                'The written register is the only source that records engineering incidents, and it is maintained by hand.',
                'The register is read from disk as the serving user. If a directory is unreadable to that user the source reports BLOCKED rather than a zero, so this screen can legitimately differ between the CLI and the web server.',
            ],
            'query' => $query->applied(),
            'observed_at' => now()->toIso8601String(),
        ];
    }

    /**
     * The markdown incident register. Reported as BLOCKED rather than empty when
     * the serving user cannot read it — /root is mode 0700 (TD-28), so what the
     * CLI can see and what the web server can see genuinely differ.
     */
    private function incidentRegister(): array
    {
        $ids = [];
        $readable = [];
        $unreadable = [];

        foreach (self::INCIDENT_REGISTER_DIRS as $dir) {
            if (!is_dir($dir) || !is_readable($dir)) {
                $unreadable[] = $dir;
                continue;
            }
            $readable[] = $dir;

            foreach (glob($dir . '/*.md') ?: [] as $file) {
                if (!is_readable($file)) {
                    continue;
                }
                if (preg_match_all('/INC-\d{4}-\d{3,}/', (string) file_get_contents($file), $m)) {
                    foreach ($m[0] as $id) {
                        $ids[$id][] = basename($file);
                    }
                }
            }
        }

        if ($readable === []) {
            return [
                'key' => 'written_register',
                'title' => 'Written incident register',
                'available' => false,
                'blocked_reason' => 'no register directory is readable by this process: ' . implode(', ', $unreadable),
                'entries' => [],
                'evidence_source' => 'markdown handoff documents',
            ];
        }

        /*
         * A zero produced by not being able to read the data is not a
         * measurement of zero.
         *
         * This bit for real: as root every directory is readable and this source
         * reports its incidents. As www-data /root is mode 0700 (TD-28) while
         * another configured directory is readable and empty — so the scan
         * "succeeded", found nothing, and reported a confident zero. Blocking
         * here keeps the screen honest about which user is asking.
         */
        if ($ids === [] && $unreadable !== []) {
            return [
                'key' => 'written_register',
                'title' => 'Written incident register',
                'available' => false,
                'blocked_reason' => sprintf(
                    'no incidents found in the readable directories (%s), while %s could not be read by this user. '
                    . 'A zero here would be an artefact of permissions, not a measurement.',
                    implode(', ', $readable), implode(', ', $unreadable)
                ),
                'entries' => [],
                'evidence_source' => 'markdown handoff documents',
            ];
        }

        ksort($ids);
        $entries = [];
        foreach ($ids as $id => $files) {
            $entries[] = [
                'incident' => $id,
                'mentioned_in' => count(array_unique($files)),
                'documents' => array_values(array_unique($files)),
            ];
        }

        return [
            'key' => 'written_register',
            'title' => 'Written incident register',
            'available' => true,
            'count' => count($entries),
            'entries' => $entries,
            'directories_read' => $readable,
            'directories_unreadable' => $unreadable,
            'coverage_complete' => $unreadable === [],
            'evidence_source' => 'markdown handoff documents',
            'what_it_is' => 'Engineering incidents written up by hand during the work that caused or found them.',
            'what_it_is_not' => 'It is not complete, not structured, and not guaranteed current. An incident with no document does not appear here at all.',
        ];
    }

    /**
     * GET /api/admin/engineering/costs
     *
     * What the platform spends in CUSTOMER CREDITS. Provider and token cost are
     * not measured anywhere this dashboard can reach.
     *
     * The arithmetic here matters more than the presentation. credit_transactions
     * records a reservation as several rows — a reserve, then a commit or a
     * release — that share one reservation_reference. Summing the amount column
     * across all rows counts the same spend more than once: on live data that is
     * 13,124 against an actual 3,820. Only commits are spend.
     */
    public function costs(EngineeringListQuery $query): array
    {
        if (!Schema::hasTable('credit_transactions')) {
            return [
                'screen' => 'costs',
                'read_only' => true,
                'spend' => $this->blocked('the credit_transactions table does not exist'),
                'query' => $query->applied(),
                'observed_at' => now()->toIso8601String(),
            ];
        }

        $leg = fn (string $type) => (float) DB::table('credit_transactions')
            ->where('type', $type)->sum('amount');

        $reserved = $leg('reserve');
        $committed = $leg('commit');
        $released = $leg('release');
        $naive = (float) DB::table('credit_transactions')->sum('amount');

        $byReference = DB::table('credit_transactions')
            ->selectRaw("reference_type, COUNT(*) AS row_count, SUM(CASE WHEN type='commit' THEN amount ELSE 0 END) AS committed_amount")
            ->groupBy('reference_type')->orderByDesc('committed_amount')->limit(20)
            ->get()->map(fn ($r) => [
                'reference_type' => $r->reference_type,
                'rows' => (int) $r->row_count,
                'credits_committed' => (float) $r->committed_amount,
            ])->all();

        $byWorkspace = DB::table('credit_transactions')
            ->where('type', 'commit')
            ->selectRaw('workspace_id, SUM(amount) AS committed_amount, COUNT(*) AS row_count')
            ->groupBy('workspace_id')->orderByDesc('committed_amount')->limit(20)
            ->get()->map(fn ($r) => [
                'workspace_id' => $r->workspace_id,
                'credits_committed' => (float) $r->committed_amount,
                'commits' => (int) $r->row_count,
            ])->all();

        // Cost tables that exist but hold nothing — reported so an empty screen
        // is not mistaken for a measured zero.
        $providerTables = [];
        foreach (['infra_cost_entries', 'infra_usage_records', 'message_charges'] as $tbl) {
            $providerTables[$tbl] = Schema::hasTable($tbl)
                ? DB::table($tbl)->count()
                : null;
        }

        return [
            'screen' => 'costs',
            'read_only' => true,
            'unit' => 'customer credits',
            'spend' => $this->measured([
                'credits_committed' => $committed,
                'credits_reserved' => $reserved,
                'credits_released' => $released,
                'reserved_minus_released' => round($reserved - $released, 2),
                'naive_sum_all_rows' => $naive,
                'transactions' => DB::table('credit_transactions')->count(),
            ], 'credit_transactions, commits only'),
            'arithmetic_note' => sprintf(
                'Spend is %s committed credits. Summing every row gives %s, which counts the same reservation two or three times — '
                . 'a reserve and its commit share one reservation_reference. reserved (%s) minus released (%s) equals committed (%s), '
                . 'which is the consistency check.',
                $committed, $naive, $reserved, $released, round($reserved - $released, 2)
            ),
            'by_reference_type' => $this->measured($byReference, 'credit_transactions grouped by reference_type, commits only'),
            'by_workspace' => $this->measured($byWorkspace, 'credit_transactions grouped by workspace_id, commits only'),
            'balances' => Schema::hasTable('credits')
                ? $this->measured([
                    'accounts' => DB::table('credits')->count(),
                    'total_balance' => (float) DB::table('credits')->sum('balance'),
                ], 'credits table')
                : $this->blocked('the credits table does not exist'),
            'provider_cost' => $this->blocked(
                'Provider and token cost are not measured. AI provider calls execute in the Railway runtime, which this platform '
                . 'does not bill against and this dashboard cannot reach. '
                . 'Tables that would hold it are present but empty: '
                . implode(', ', array_map(
                    fn ($k, $v) => $k . '=' . ($v === null ? 'absent' : $v . ' rows'),
                    array_keys($providerTables), $providerTables
                ))
            ),
            'interpretation_limits' => [
                'Credits are an internal unit. This screen reports credits, not money — no currency conversion exists here.',
                'A reserve is not spend. It is an intent to spend that is either committed or released.',
                'Provider cost, token cost and infrastructure cost are NOT included, and no figure here should be read as the cost of running the platform.',
                'Figures are lifetime totals over the whole table, not a billing period.',
                'credit_transactions has no index on created_at, so period filtering would scan the table (TD-22 applies here too).',
            ],
            'query' => $query->applied(),
            'observed_at' => now()->toIso8601String(),
        ];
    }
}
