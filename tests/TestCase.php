<?php

namespace Tests;

use App\Core\Engineer888\Coordination\DatabaseAssignment;
use App\Core\Safety\ProductionDatabaseGuard;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * Base test case.
 *
 * P2-C — every test run asserts its database target BEFORE a connection is
 * opened. On 2026-07-27 `php artisan migrate --env=testing` fell back to `.env`
 * (no `.env.testing` existed) and ran against production. The guard makes that
 * class of mistake abort instead of proceeding silently.
 *
 * See TEST-ENVIRONMENT-SAFETY-SPECIFICATION.md and incident INC-2026-002.
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * The guard runs HERE, not in setUp(), and the difference is not cosmetic.
     *
     * Laravel's TestCase::setUp() boots the application and then calls
     * setUpTraits(), which is where RefreshDatabase executes migrate:fresh.
     * A guard placed after parent::setUp() therefore fires AFTER the database
     * has already been dropped. On 2026-07-30 a test process inherited
     * DB_DATABASE=levelup_staging from its parent through $_SERVER — which
     * phpunit's <env force="true"> does not override — RefreshDatabase emptied
     * production, and only then did this guard raise its exception. It reported
     * the disaster it existed to prevent.
     *
     * setUpTraits() runs with the application booted and no trait yet executed,
     * which is the only point where the check can still refuse.
     */
    protected function setUpTraits()
    {
        // Fail closed. If the target cannot be proven to be a test database,
        // no trait in this suite may touch it.
        ProductionDatabaseGuard::assertSafeTestTarget('phpunit');

        // Second question, different from the first: is this database MINE?
        // On 2026-07-30 `levelup_test` passed the guard above — it is not
        // production and it looks like a test database — and dropping it still
        // destroyed a concurrent engineer's migration. Looking like a test
        // database is not the same as being the one assigned to this session.
        //
        // Enforced only for sessions that declare a manifest via
        // E888_SPRINT_MANIFEST, so engineers who have not yet adopted the
        // convention are unaffected. See ENGINEERING-COORDINATION.md.
        DatabaseAssignment::assertAssignedWhenDeclared(
            (string) config('database.connections.' . config('database.default') . '.database'),
            'phpunit'
        );

        return parent::setUpTraits();
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Re-asserted after boot: a test that swaps the connection at runtime
        // must not escape the check that setUpTraits() already applied.
        ProductionDatabaseGuard::assertSafeTestTarget('phpunit');
    }
}
