<?php

namespace App\Core\Engineer888\Coordination;

use RuntimeException;

/**
 * Positive proof that a destructive database operation is aimed at a database
 * assigned to this session.
 *
 * WHAT THIS ADDS OVER ProductionDatabaseGuard
 * That guard answers "is this production?" and "does the name look like a test
 * database?". Both were true of `levelup_test` on 2026-07-30, and dropping it
 * still destroyed another engineer's in-flight run. Looking like a test database
 * is not the same as being MY test database.
 *
 * This class answers the ownership question: is this specific database the one
 * declared in this session's manifest? Anything else — production, a shared
 * default, another engineer's database, or an unprovable name — is refused.
 *
 * A process check is explicitly not evidence. On 2026-07-30 `ps` showed no test
 * run at the moment it was consulted, and a concurrent session was mid-migration
 * two seconds later. Point-in-time observation cannot prove absence.
 */
final class DatabaseAssignment
{
    /** Never a valid target for a destructive operation, under any manifest. */
    public const PRODUCTION_NAMES = ['levelup_staging', 'levelup', 'levelup_production', 'levelup_prod'];

    /**
     * Test databases that belong to everybody, which means nobody may destroy
     * them. `levelup_test` is the one that has already caused a collision in
     * both directions.
     */
    public const SHARED_NAMES = ['levelup_test', 'levelup_clean_test', 'boss888_test'];

    /**
     * @throws RuntimeException unless $database is this session's assigned database
     */
    public static function assertAssigned(string $database, string $context, ?OwnershipManifest $manifest = null): void
    {
        $where = " [{$context}]";
        $database = trim($database);

        if ($database === '') {
            throw new RuntimeException(
                "REFUSING{$where}: no database resolved. A destructive operation must name its target."
            );
        }

        foreach (self::PRODUCTION_NAMES as $production) {
            if (strcasecmp($database, $production) === 0) {
                throw new RuntimeException(
                    "REFUSING{$where}: '{$database}' is production. This is never a valid destructive target."
                );
            }
        }

        foreach (self::SHARED_NAMES as $shared) {
            if (strcasecmp($database, $shared) === 0) {
                throw new RuntimeException(
                    "REFUSING{$where}: '{$database}' is a SHARED test database. Dropping it destroyed a "
                    . "concurrent engineer's run on 2026-07-30. Use the database assigned to this session "
                    . 'in ' . OwnershipManifest::DIRECTORY . '.'
                );
            }
        }

        $manifest ??= OwnershipManifest::active();

        if ($manifest === null) {
            throw new RuntimeException(
                "REFUSING{$where}: no sprint manifest is active, so no database is assigned to this session. "
                . 'Ambiguous assignment fails closed. Create a manifest in ' . OwnershipManifest::DIRECTORY
                . ' or set E888_SPRINT_MANIFEST.'
            );
        }

        $assigned = $manifest->testDatabase();

        if ($assigned === null) {
            throw new RuntimeException(
                "REFUSING{$where}: the manifest {$manifest->path()} declares no test_database."
            );
        }

        if (strcasecmp($database, $assigned) !== 0) {
            throw new RuntimeException(
                "REFUSING{$where}: '{$database}' is not assigned to this session. The manifest "
                . "({$manifest->sprint()}) assigns '{$assigned}'. Another engineer may be using "
                . "'{$database}' right now, and a process check cannot prove otherwise."
            );
        }
    }

    /**
     * Enforce assignment only where a session has opted in by declaring its
     * manifest through E888_SPRINT_MANIFEST.
     *
     * WHY OPT-IN. Enforcing unconditionally would break every engineer who does
     * not yet have a manifest, on the first run after this lands. Breaking
     * colleagues in order to protect them is an outage, not a control. Sessions
     * adopt the convention by pointing their phpunit config at a manifest;
     * Engineer888 has done so, and until another session does, its behaviour is
     * exactly what it was.
     */
    public static function assertAssignedWhenDeclared(string $database, string $context): void
    {
        $declared = getenv('E888_SPRINT_MANIFEST') ?: ($_SERVER['E888_SPRINT_MANIFEST'] ?? null);

        if (! is_string($declared) || $declared === '') {
            return;   // session has not adopted the convention
        }

        self::assertAssigned($database, $context);
    }

    /** Non-throwing form, for reporting. @return array{assigned:bool, reason:string} */
    public static function check(string $database, ?OwnershipManifest $manifest = null): array
    {
        try {
            self::assertAssigned($database, 'check', $manifest);

            return ['assigned' => true, 'reason' => 'assigned to this session'];
        } catch (RuntimeException $e) {
            return ['assigned' => false, 'reason' => $e->getMessage()];
        }
    }

    public static function isProductionName(string $database): bool
    {
        foreach (self::PRODUCTION_NAMES as $production) {
            if (strcasecmp($database, $production) === 0) { return true; }
        }

        return false;
    }

    public static function isSharedName(string $database): bool
    {
        foreach (self::SHARED_NAMES as $shared) {
            if (strcasecmp($database, $shared) === 0) { return true; }
        }

        return false;
    }
}
