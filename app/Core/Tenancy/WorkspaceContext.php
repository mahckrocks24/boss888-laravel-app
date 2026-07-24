<?php

namespace App\Core\Tenancy;

use RuntimeException;

/**
 * INFRA888 — explicit workspace context.
 *
 * WHY THIS EXISTS
 * ---------------
 * Phase 0 audit §7: the platform resolves tenancy by reading a request attribute
 * (JwtAuthMiddleware.php:82 -> BaseEngineController.php:55). That works for HTTP,
 * but queue jobs and scheduled commands have NO request — so any scope built on
 * request() silently degrades to "no workspace" in exactly the async paths where
 * infrastructure provisioning actually runs.
 *
 * This context is request-independent and FAILS CLOSED: reading it while unset
 * throws. There is no null default, because a null default is how cross-tenant
 * leaks hide.
 *
 * Usage is always scoped:
 *
 *     WorkspaceContext::run($wsId, function () { ... });
 *
 * or explicitly entered/exited by middleware and jobs.
 */
final class WorkspaceContext
{
    private static ?int $workspaceId = null;
    private static ?int $actorUserId = null;

    /** Stack allows safe nesting (e.g. an admin override inside a request). */
    private static array $stack = [];

    public static function enter(int $workspaceId, ?int $actorUserId = null): void
    {
        if ($workspaceId <= 0) {
            throw new RuntimeException('WorkspaceContext: workspace id must be positive.');
        }

        self::$stack[] = [self::$workspaceId, self::$actorUserId];
        self::$workspaceId = $workspaceId;
        self::$actorUserId = $actorUserId;
    }

    public static function exit(): void
    {
        if (self::$stack === []) {
            self::$workspaceId = null;
            self::$actorUserId = null;
            return;
        }

        [self::$workspaceId, self::$actorUserId] = array_pop(self::$stack);
    }

    /**
     * Preferred entry point — guarantees the context is released even on throw.
     *
     * @template T
     * @param  callable():T  $callback
     * @return T
     */
    public static function run(int $workspaceId, callable $callback, ?int $actorUserId = null)
    {
        self::enter($workspaceId, $actorUserId);

        try {
            return $callback();
        } finally {
            self::exit();
        }
    }

    /** Fails closed. Never returns a default. */
    public static function id(): int
    {
        if (self::$workspaceId === null) {
            throw new RuntimeException(
                'WorkspaceContext is not set. INFRA888 queries require an explicit workspace. '
                . 'Wrap the call in WorkspaceContext::run($wsId, fn () => ...).'
            );
        }

        return self::$workspaceId;
    }

    public static function idOrNull(): ?int
    {
        return self::$workspaceId;
    }

    public static function isSet(): bool
    {
        return self::$workspaceId !== null;
    }

    public static function actorUserId(): ?int
    {
        return self::$actorUserId;
    }

    /**
     * Test-only reset. Not used in application code.
     */
    public static function reset(): void
    {
        self::$workspaceId = null;
        self::$actorUserId = null;
        self::$stack = [];
    }
}
