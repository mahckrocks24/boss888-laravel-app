<?php

namespace Tests\Feature\Chat\Safety;

use App\Core\Safety\ProductionDatabaseGuard;
use RuntimeException;
use Tests\TestCase;

/**
 * P2-C — regression tests for INC-2026-002.
 *
 * `php artisan migrate --env=testing` resolved to production because no
 * `.env.testing` existed, so Laravel fell back to `.env`. These tests prove the
 * condition can no longer pass silently.
 *
 * They manipulate CONFIG only and never open a connection to anything.
 */
class EnvironmentSafetyTest extends TestCase
{
    private array $original = [];

    protected function setUp(): void
    {
        parent::setUp();
        $c = config('database.default');
        $this->original = [
            'default'  => $c,
            'database' => config("database.connections.{$c}.database"),
            'host'     => config("database.connections.{$c}.host"),
            'env'      => config('app.env'),
        ];
    }

    protected function tearDown(): void
    {
        $c = $this->original['default'];
        config([
            'database.default'                    => $c,
            "database.connections.{$c}.database"  => $this->original['database'],
            "database.connections.{$c}.host"      => $this->original['host'],
            'app.env'                             => $this->original['env'],
        ]);
        parent::tearDown();
    }

    private function target(string $database, ?string $host = null): void
    {
        $c = config('database.default');
        config(["database.connections.{$c}.database" => $database]);
        if ($host !== null) {
            config(["database.connections.{$c}.host" => $host]);
        }
    }

    /** @test The production database name is refused. */
    public function the_production_database_is_rejected(): void
    {
        $this->target('levelup_staging');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/production/i');
        ProductionDatabaseGuard::assertSafeTestTarget('regression');
    }

    /** @test Other production-shaped names are refused too. */
    public function other_production_names_are_rejected(): void
    {
        foreach (['levelup', 'levelup_production', 'levelup_prod'] as $db) {
            $this->target($db);
            try {
                ProductionDatabaseGuard::assertSafeTestTarget('regression');
                $this->fail("{$db} should have been rejected");
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * @test A NEW production database — not on any denylist — is still refused.
     *
     * This is the important one. A denylist alone would let tomorrow's
     * production database through; the guard requires a positive match instead.
     */
    public function an_unknown_non_test_database_is_rejected(): void
    {
        $this->target('levelup_customer_eu');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not recognisable as a test database/i');
        ProductionDatabaseGuard::assertSafeTestTarget('regression');
    }

    /** @test A production host is refused even with a test-shaped database name. */
    public function a_production_host_is_rejected(): void
    {
        $this->target('levelup_test', '134.209.93.41');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/production host/i');
        ProductionDatabaseGuard::assertSafeTestTarget('regression');
    }

    /** @test An empty database configuration fails closed rather than guessing. */
    public function a_missing_database_configuration_fails_closed(): void
    {
        $this->target('');

        $this->expectException(RuntimeException::class);
        ProductionDatabaseGuard::assertSafeTestTarget('regression');
    }

    /** @test A test-only operation outside APP_ENV=testing is refused. */
    public function app_env_and_target_must_agree(): void
    {
        config(['app.env' => 'production']);
        $this->target('levelup_chat_test');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/APP_ENV/');
        ProductionDatabaseGuard::assertSafeTestTarget('regression');
    }

    /** @test The isolated chat database is accepted — ordinary tests still run. */
    public function the_isolated_chat_database_is_accepted(): void
    {
        $this->target('levelup_chat_test', '127.0.0.1');

        ProductionDatabaseGuard::assertSafeTestTarget('regression');
        $this->assertTrue(true, 'levelup_chat_test must remain a valid target');
    }

    /** @test The shared test database is also accepted. */
    public function the_shared_test_database_is_accepted(): void
    {
        $this->target('levelup_test', '127.0.0.1');

        ProductionDatabaseGuard::assertSafeTestTarget('regression');
        $this->assertTrue(true);
    }

    /** @test This very suite is running against a safe target. */
    public function the_current_suite_is_running_against_a_safe_target(): void
    {
        $this->assertTrue(ProductionDatabaseGuard::isSafeTestTarget(),
            'the running suite is not on a safe target: ' . ProductionDatabaseGuard::describeTarget());
        $this->assertStringContainsString('test', ProductionDatabaseGuard::describeTarget());
    }

    /** @test `.env.testing` now exists, so `--env=testing` cannot fall back to `.env`. */
    public function an_env_testing_file_exists_so_the_fallback_cannot_recur(): void
    {
        $path = base_path('.env.testing');

        $this->assertFileExists($path,
            'without .env.testing, `artisan --env=testing` falls back to .env and resolves to production — INC-2026-002');

        $contents = (string) file_get_contents($path);
        $this->assertMatchesRegularExpression('/^DB_DATABASE=levelup_\w*test\w*$/m', $contents,
            '.env.testing must target an isolated test database');
        $this->assertDoesNotMatchRegularExpression('/^DB_DATABASE=levelup_staging$/m', $contents,
            '.env.testing must never target production');
    }

    /** @test The guard never reveals credentials. */
    public function the_guard_never_exposes_credentials(): void
    {
        $described = ProductionDatabaseGuard::describeTarget();

        foreach (['password', 'DB_PASSWORD', 'secret'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $described);
        }
    }
}
