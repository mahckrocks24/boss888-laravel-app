<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * PHASE 1E.1 — TEST DATABASE ISOLATION.
 *
 * Two engineering sessions share this host. The other session runs large suites
 * that drop and rebuild `levelup_test` mid-flight; during Phase 1E I watched four
 * tables vanish inside a single run, four separate times. Results obtained that way
 * are not evidence — they are noise that happens to be shaped like evidence.
 *
 * This trait makes a test suite state, at runtime, which database it is actually
 * connected to, and REFUSE to run anywhere shared. It is a fail-closed assertion:
 * an unrecognised database name is rejected rather than assumed safe.
 *
 * It does not modify, terminate or otherwise interfere with the other session.
 */
trait IsolatedDatabase
{
    /** Databases that must never be used for a destructive test run. */
    private const FORBIDDEN = [
        'levelup_staging',   // PRODUCTION — staging IS production here
        'levelup_test',      // shared between sessions; another session rebuilds it
    ];

    /**
     * A session-isolated test database must match this.
     *
     * `levelup_<session>_test` rather than `levelup_test_<session>`, because the
     * repository already has app/Core/Safety/ProductionDatabaseGuard, which requires
     * a POSITIVE match on /_test$/ before any suite may run. Conforming to that
     * existing control is better than widening it: it was added after `--env=testing`
     * resolved to production on 2026-07-27, and it is shared with the other session.
     */
    private const SESSION_PATTERN = '/^levelup_[a-z0-9]+_test$/';

    protected function assertIsolatedDatabase(): void
    {
        $connection = DB::connection()->getName();
        $database = DB::connection()->getDatabaseName();

        if (in_array($database, self::FORBIDDEN, true)) {
            $this->fail(sprintf(
                "REFUSING TO RUN: connected to '%s' (connection '%s').\n"
                . "This suite mutates schema and rows. '%s' is %s.\n"
                . 'Run with: php artisan test -c phpunit.p1e1.xml   (or ./vendor/bin/phpunit -c phpunit.p1e1.xml)',
                $database, $connection, $database,
                $database === 'levelup_staging'
                    ? 'PRODUCTION'
                    : 'shared with another engineering session and is rebuilt underneath us'
            ));
        }

        // Fail closed: only an explicitly session-scoped database is acceptable.
        // An unrecognised name is refused rather than assumed safe.
        if (preg_match(self::SESSION_PATTERN, $database) !== 1) {
            $this->fail(sprintf(
                "REFUSING TO RUN: database '%s' is not a session-isolated test database.\n"
                . "Expected %s (e.g. levelup_p1e1_test), and never the shared 'levelup_test'.\n"
                . 'Run with: ./vendor/bin/phpunit -c phpunit.p1e1.xml',
                $database, self::SESSION_PATTERN
            ));
        }
    }

    /** For the report: the connection actually in use. */
    protected function isolatedDatabaseName(): string
    {
        return DB::connection()->getDatabaseName();
    }
}
