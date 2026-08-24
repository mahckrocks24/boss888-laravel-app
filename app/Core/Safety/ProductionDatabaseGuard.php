<?php

namespace App\Core\Safety;

use RuntimeException;

/**
 * P2-C — fail-closed guard against a test or rehearsal resolving to production.
 *
 * THE INCIDENT THIS EXISTS TO PREVENT (INC-2026-002)
 * On 2026-07-27 a migration rehearsal was issued as:
 *     php artisan migrate --env=testing
 * There was no `.env.testing`, so Laravel fell back to `.env` and the command
 * ran against `levelup_staging` — production. The migration was additive and
 * harmless, but the same command with a destructive migration would not have
 * been. The failure mode is silent: nothing in the output says which database
 * was touched.
 *
 * DESIGN: DENY, THEN ALLOW.
 * A denylist alone is not enough — a new production database would not be on
 * it. So the guard requires the target to be positively recognisable as a test
 * database AND absent from the denylist. Anything it cannot prove is safe is
 * refused.
 *
 * It runs BEFORE a connection is opened. It reads configuration only; it never
 * connects, and it never logs credentials.
 */
final class ProductionDatabaseGuard
{
    /** Databases that must never be the target of a test or rehearsal. */
    public const DENY_DATABASES = ['levelup_staging', 'levelup', 'levelup_production', 'levelup_prod'];

    /** A test target must match one of these. */
    public const ALLOW_PATTERNS = ['/_test$/', '/^levelup_chat_test$/', '/^levelup_test$/', '/_testing$/'];

    /** Hosts that are never acceptable for a test run. */
    public const DENY_HOST_PATTERNS = ['/^134\.209\./', '/\.levelupgrowth\.io$/', '/^levelupgrowth\.io$/'];

    /**
     * Assert the current connection target is a safe non-production database.
     *
     * @throws RuntimeException before any connection is opened
     */
    public static function assertSafeTestTarget(?string $context = null): void
    {
        $connection = config('database.default');
        $database   = (string) config("database.connections.{$connection}.database");
        $host       = (string) config("database.connections.{$connection}.host");
        $where      = $context ? " [{$context}]" : '';

        if ($database === '') {
            throw new RuntimeException(
                "REFUSING TO RUN{$where}: no database is configured. A test run must have an explicit "
                . 'non-production target. See TEST-ENVIRONMENT-SAFETY-SPECIFICATION.md.'
            );
        }

        // 1. Denylist — the known production names.
        foreach (self::DENY_DATABASES as $denied) {
            if (strcasecmp($database, $denied) === 0) {
                throw new RuntimeException(
                    "REFUSING TO RUN{$where}: the target database is '{$database}', which is production. "
                    . "This is the exact condition that let `--env=testing` resolve to production on 2026-07-27. "
                    . 'Use: php artisan test -c phpunit.chat.xml   (target: levelup_chat_test).'
                );
            }
        }

        // 2. Allowlist — must be positively recognisable as a test database.
        $recognised = false;
        foreach (self::ALLOW_PATTERNS as $pattern) {
            if (preg_match($pattern, $database)) {
                $recognised = true;
                break;
            }
        }
        if (!$recognised) {
            throw new RuntimeException(
                "REFUSING TO RUN{$where}: the target database '{$database}' is not recognisable as a test "
                . 'database. A denylist alone would not catch a NEW production database, so the guard '
                . 'requires a positive match. Allowed patterns: ' . implode(', ', self::ALLOW_PATTERNS) . '.'
            );
        }

        // 3. Host denylist — a test must never reach a production host.
        foreach (self::DENY_HOST_PATTERNS as $pattern) {
            if (preg_match($pattern, $host)) {
                throw new RuntimeException(
                    "REFUSING TO RUN{$where}: the database host resolves to a production host. "
                    . 'Tests must target a local, isolated database.'
                );
            }
        }

        // 4. APP_ENV and the target must agree (fail-closed on disagreement).
        $env = (string) config('app.env');
        if ($env !== 'testing' && $context !== null) {
            throw new RuntimeException(
                "REFUSING TO RUN{$where}: APP_ENV is '{$env}', not 'testing', but a test-only operation "
                . 'was requested. APP_ENV and the database target must agree.'
            );
        }
    }

    /**
     * True when it is safe to run a destructive test operation.
     * Never throws — for callers that want to branch rather than abort.
     */
    public static function isSafeTestTarget(): bool
    {
        try {
            self::assertSafeTestTarget();

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    /** Redacted description of the target, safe to print. Never includes credentials. */
    public static function describeTarget(): string
    {
        $connection = config('database.default');

        return sprintf(
            'connection=%s database=%s env=%s',
            $connection,
            (string) config("database.connections.{$connection}.database"),
            (string) config('app.env')
        );
    }
}
