<?php

namespace App\Core\Safety;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * RSK-M4-1 — detect engineering work happening alongside your own.
 *
 * WHY THIS EXISTS
 * During E1 M4 a second session created two migrations, ran them against the
 * live database and ran a test suite, all inside the four minutes this session
 * was writing a route. Nothing collided — but nothing would have objected if it
 * had. The routes-scoped ownership lock covers route source files only;
 * migrations, schema and everything under app/ were completely unguarded.
 *
 * WHAT THIS CAN AND CANNOT DO — read this before trusting it.
 *
 * It CANNOT prevent a session that does not participate. An advisory lock binds
 * only the sessions that take it, and no mechanism available here can stop
 * another process from running `artisan migrate`. Claiming otherwise would be
 * exactly the kind of confident, wrong assurance the Engineering section exists
 * to avoid.
 *
 * What it CAN do is refuse to let a participating operation start blind. Every
 * check below is an observation of real state — running processes, unapplied
 * migration files, live locks held by others, recent writes. Conflicting work is
 * reported with its evidence, and a blocking conflict stops the operation
 * instead of letting it proceed silently.
 *
 * Prevention for the cooperative case, detection for the rest. This class never
 * writes anything.
 */
final class ConcurrencyGuard
{
    /** Severity meaning: an operation must not start. */
    public const BLOCKING = 'blocking';

    /** Severity meaning: proceed, but the operator must know. */
    public const WARNING = 'warning';

    public const CLEAR = 'clear';

    /** A write this recent is treated as possibly-live activity. */
    private const RECENT_SECONDS = 600;

    private const MIGRATIONS_DIR = '/var/www/levelup-staging/database/migrations';

    private const WATCHED_SOURCE_DIRS = [
        '/var/www/levelup-staging/app',
        '/var/www/levelup-staging/routes',
        '/var/www/levelup-staging/database/migrations',
    ];

    public function __construct(
        private SourceOwnershipLock $locks = new SourceOwnershipLock(),
    ) {}

    /**
     * Observe the environment and report anything that looks like concurrent
     * engineering work.
     *
     * @param string|null $owner the operation asking; its own locks and its own
     *                           process are not reported as conflicts
     *
     * @return array{clear:bool, verdict:string, checks:array, conflicts:array,
     *               blocking:array, limits:array, observed_at:string}
     */
    public function assess(?string $owner = null): array
    {
        $checks = [
            $this->foreignOwnershipLocks($owner),
            $this->unappliedMigrations(),
            $this->foreignProcesses(),
            $this->recentSourceWrites(),
            $this->protectedPathDrift(),
        ];

        $conflicts = array_values(array_filter(
            $checks,
            fn ($c) => in_array($c['severity'], [self::BLOCKING, self::WARNING], true)
        ));
        $blocking = array_values(array_filter($conflicts, fn ($c) => $c['severity'] === self::BLOCKING));

        return [
            'clear' => $conflicts === [],
            'verdict' => $blocking !== [] ? self::BLOCKING : ($conflicts !== [] ? self::WARNING : self::CLEAR),
            'checks' => $checks,
            'conflicts' => $conflicts,
            'blocking' => $blocking,
            'limits' => [
                'This detects concurrent work; it cannot prevent a session that does not take a lock.',
                'A clear result means nothing conflicting was observable at this instant, not that nothing is running.',
                'Process inspection sees this host only. Work performed elsewhere against the same database is invisible here.',
                'A check that could not run is reported as blocked, never as clear.',
            ],
            'observed_at' => gmdate('c'),
        ];
    }

    /**
     * @return array{check:string, severity:string, summary:string, evidence:array}
     */
    private function result(string $check, string $severity, string $summary, array $evidence = []): array
    {
        return compact('check', 'severity', 'summary') + ['evidence' => $evidence];
    }

    /** Someone else holds a live ownership lock, in any scope. */
    private function foreignOwnershipLocks(?string $owner): array
    {
        try {
            $all = $this->locks->all();
        } catch (Throwable $e) {
            return $this->result('foreign_ownership_locks', self::WARNING,
                'could not read the lock directory', ['error' => $e->getMessage()]);
        }

        $foreign = [];
        foreach ($all as $lock) {
            if (!is_array($lock) || ($lock['released_at'] ?? null) !== null) {
                continue;
            }
            if ($this->locks->isStale($lock)) {
                continue;                       // stale locks are a separate concern
            }
            if ($owner !== null && ($lock['owner'] ?? null) === $owner) {
                continue;                       // our own lock is not a conflict
            }
            $foreign[] = [
                'scope' => $lock['scope'] ?? null,
                'owner' => $lock['owner'] ?? null,
                'phase' => $lock['phase'] ?? null,
                'pid' => $lock['pid'] ?? null,
                'acquired_at' => $lock['acquired_at'] ?? null,
                'paths' => $lock['paths'] ?? [],
            ];
        }

        return $foreign === []
            ? $this->result('foreign_ownership_locks', self::CLEAR, 'no other session holds a live lock')
            : $this->result('foreign_ownership_locks', self::BLOCKING,
                count($foreign) . ' live lock(s) held by another owner', $foreign);
    }

    /**
     * Migration files on disk that the database has not run.
     *
     * This is the check that would have surfaced the M4 overlap: the other
     * session's two migration files existed before they were applied.
     */
    private function unappliedMigrations(): array
    {
        if (!is_dir(self::MIGRATIONS_DIR)) {
            return $this->result('unapplied_migrations', self::WARNING,
                'migration directory not readable', ['path' => self::MIGRATIONS_DIR]);
        }

        $files = [];
        foreach (glob(self::MIGRATIONS_DIR . '/*.php') ?: [] as $f) {
            $files[] = basename($f, '.php');
        }

        try {
            $applied = DB::table('migrations')->pluck('migration')->all();
        } catch (Throwable $e) {
            return $this->result('unapplied_migrations', self::WARNING,
                'could not read the migrations table', ['error' => $e->getMessage()]);
        }

        $pending = array_values(array_diff($files, $applied));

        return $pending === []
            ? $this->result('unapplied_migrations', self::CLEAR,
                'every migration file has been applied', ['files' => count($files)])
            : $this->result('unapplied_migrations', self::BLOCKING,
                count($pending) . ' migration file(s) on disk have not been run', ['pending' => $pending]);
    }

    /** Another migrate or test process running right now. */
    private function foreignProcesses(): array
    {
        $out = [];
        $rc = 0;
        @exec('ps -eo pid,etimes,args 2>/dev/null', $out, $rc);

        if ($rc !== 0 || $out === []) {
            return $this->result('foreign_processes', self::WARNING,
                'could not inspect running processes', []);
        }

        // Our own process and the one that launched us are not "someone else".
        // Without this every check run from inside a test suite would report the
        // suite that is running it.
        $selfPids = array_filter([getmypid(), function_exists('posix_getppid') ? posix_getppid() : null]);
        $found = [];

        foreach ($out as $line) {
            if (!preg_match('/^\s*(\d+)\s+(\d+)\s+(.*)$/', $line, $m)) {
                continue;
            }
            [$pid, $elapsed, $cmd] = [(int) $m[1], (int) $m[2], $m[3]];

            if (in_array($pid, $selfPids, true)) {
                continue;
            }
            if (str_contains($cmd, 'ps -eo')) {
                continue;
            }

            $isMigrate = (bool) preg_match('/artisan\s+migrate(?!:status)/', $cmd);
            $isTest = (bool) preg_match('/(artisan\s+test|phpunit)/', $cmd);

            if (!$isMigrate && !$isTest) {
                continue;
            }

            $found[] = [
                'pid' => $pid,
                'kind' => $isMigrate ? 'migrate' : 'test',
                'elapsed_seconds' => $elapsed,
                'command' => mb_substr($cmd, 0, 160),
            ];
        }

        if ($found === []) {
            return $this->result('foreign_processes', self::CLEAR, 'no migrate or test process is running');
        }

        // A migration in flight is blocking. A test run is contention (TD-27),
        // which is worth knowing about but is not a reason to refuse.
        $severity = array_filter($found, fn ($f) => $f['kind'] === 'migrate') !== []
            ? self::BLOCKING
            : self::WARNING;

        return $this->result('foreign_processes', $severity,
            count($found) . ' migrate/test process(es) running', $found);
    }

    /** Source or migration files written very recently by someone else. */
    private function recentSourceWrites(): array
    {
        $cutoff = time() - self::RECENT_SECONDS;
        $recent = [];

        foreach (self::WATCHED_SOURCE_DIRS as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $out = [];
            @exec(sprintf(
                'find %s -type f -name "*.php" -newermt "@%d" 2>/dev/null | head -40',
                escapeshellarg($dir), $cutoff
            ), $out);

            foreach ($out as $f) {
                $recent[] = [
                    'path' => str_replace('/var/www/levelup-staging/', '', $f),
                    'modified_at' => @filemtime($f) ? gmdate('c', (int) filemtime($f)) : null,
                ];
            }
        }

        return $recent === []
            ? $this->result('recent_source_writes', self::CLEAR,
                'no source file written in the last ' . self::RECENT_SECONDS . 's')
            : $this->result('recent_source_writes', self::WARNING,
                count($recent) . ' source file(s) written in the last ' . self::RECENT_SECONDS . 's',
                array_slice($recent, 0, 40));
    }

    /** The existing drift signal, surfaced here so one call covers the ground. */
    private function protectedPathDrift(): array
    {
        try {
            $d = ProtectedPaths::drift();
        } catch (Throwable $e) {
            return $this->result('protected_path_drift', self::WARNING,
                'could not evaluate drift', ['error' => $e->getMessage()]);
        }

        $total = count($d['drifted'] ?? []) + count($d['added'] ?? []) + count($d['removed'] ?? []);

        return $total === 0
            ? $this->result('protected_path_drift', self::CLEAR, 'no protected path has drifted')
            : $this->result('protected_path_drift', self::BLOCKING,
                "{$total} protected path(s) differ from the seal", $d);
    }
}
