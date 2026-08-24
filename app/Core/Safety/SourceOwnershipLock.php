<?php

namespace App\Core\Safety;

use RuntimeException;

/**
 * CR-22 — fail-closed source ownership for shared files.
 *
 * WHY THIS EXISTS (INC-2026-003)
 * On 2026-07-27 a STUDIO888 Phase P session rewrote routes/api.php from a
 * backup that predated three completed phases, reverting every route-level fix
 * of P1, P2-A and P2-B — including a customer-facing fabricated-reply defect —
 * while successfully adding its own route. Nothing objected. The file has no
 * owner, so every session assumes it owns it.
 *
 * This is deliberately NOT a distributed lock platform. It is a lock file with
 * an owner, a scope, an expiry and a heartbeat, whose only job is to make a
 * second session STOP rather than overwrite. Operational safety, not
 * orchestration.
 */
final class SourceOwnershipLock
{
    public const LOCK_DIR = '/var/www/levelup-staging/storage/app/source-locks';

    /** A lock older than this without a heartbeat is stale and may be broken. */
    public const STALE_SECONDS = 3600;

    public function __construct(private string $lockDir = self::LOCK_DIR) {}

    private function path(string $scope): string
    {
        return $this->lockDir . '/' . preg_replace('/[^a-z0-9_-]/i', '_', $scope) . '.lock.json';
    }

    /**
     * Acquire ownership of a scope.
     *
     * @param string   $scope  logical name, e.g. 'routes-api'
     * @param string   $owner  session/agent identifier
     * @param string   $phase  task or phase id, so an audit trail exists
     * @param string[] $paths  files this lock covers
     *
     * @throws RuntimeException when another live owner holds it
     */
    public function acquire(string $scope, string $owner, string $phase, array $paths, int $ttlSeconds = 7200): array
    {
        if (!is_dir($this->lockDir)) {
            mkdir($this->lockDir, 0775, true);
        }
        $path = $this->path($scope);

        $existing = $this->read($scope);
        if ($existing !== null && !$this->isStale($existing)) {
            if (($existing['owner'] ?? null) === $owner) {
                return $this->heartbeat($scope, $owner);   // re-entrant for the same owner
            }
            throw new RuntimeException(sprintf(
                "REFUSING: '%s' is owned by %s (phase %s) since %s, expires %s. "
                . "Do not edit %s. Coordinate, or wait for release. INC-2026-003 is why this exists.",
                $scope, $existing['owner'], $existing['phase'],
                $existing['acquired_at'], $existing['expires_at'],
                implode(', ', $existing['paths'] ?? [])
            ));
        }

        if ($existing !== null) {
            // Stale takeover is allowed, but it is always recorded.
            $this->audit($scope, 'stale_takeover', [
                'previous_owner' => $existing['owner'] ?? null,
                'previous_phase' => $existing['phase'] ?? null,
                'new_owner'      => $owner,
            ]);
        }

        $lock = [
            'scope'       => $scope,
            'owner'       => $owner,
            'phase'       => $phase,
            'paths'       => array_values($paths),
            'acquired_at' => gmdate('c'),
            'heartbeat_at'=> gmdate('c'),
            'expires_at'  => gmdate('c', time() + $ttlSeconds),
            'pid'         => getmypid(),
            'released_at' => null,
        ];
        // Hashes at acquisition, so a later release can prove what changed.
        foreach ($paths as $p) {
            $lock['hashes'][$p] = is_file($p) ? hash_file('sha256', $p) : null;
        }

        file_put_contents($path, json_encode($lock, JSON_PRETTY_PRINT), LOCK_EX);
        $this->audit($scope, 'acquired', ['owner' => $owner, 'phase' => $phase]);

        return $lock;
    }

    /**
     * Assert the caller may write this path. Called by tooling BEFORE a write.
     * Fails closed: an unknown owner is refused, not permitted.
     */
    public function assertOwned(string $scope, string $owner): void
    {
        $lock = $this->read($scope);

        if ($lock === null) {
            throw new RuntimeException(
                "REFUSING: '{$scope}' has no ownership lock. Acquire one before editing a shared file. "
                . 'See ROUTE-LOCKING-OPERATIONS.md.'
            );
        }
        if ($this->isStale($lock)) {
            throw new RuntimeException(
                "REFUSING: the lock on '{$scope}' is stale (no heartbeat since {$lock['heartbeat_at']}). "
                . 'Re-acquire it deliberately so the takeover is recorded.'
            );
        }
        if (($lock['owner'] ?? null) !== $owner) {
            throw new RuntimeException(sprintf(
                "REFUSING: '%s' is owned by %s (phase %s), not %s. This is the exact condition that "
                . 'caused INC-2026-003.', $scope, $lock['owner'], $lock['phase'], $owner
            ));
        }
    }

    public function heartbeat(string $scope, string $owner): array
    {
        $lock = $this->read($scope);
        if ($lock === null || ($lock['owner'] ?? null) !== $owner) {
            throw new RuntimeException("cannot heartbeat '{$scope}': not the owner");
        }
        $lock['heartbeat_at'] = gmdate('c');
        file_put_contents($this->path($scope), json_encode($lock, JSON_PRETTY_PRINT), LOCK_EX);

        return $lock;
    }

    /** Release, recording which of the owned paths actually changed. */
    public function release(string $scope, string $owner, bool $force = false): array
    {
        $lock = $this->read($scope);
        if ($lock === null) {
            return ['released' => false, 'reason' => 'no_lock'];
        }
        if (($lock['owner'] ?? null) !== $owner && !$force) {
            throw new RuntimeException(
                "REFUSING: '{$scope}' is owned by {$lock['owner']}. A forced release must be explicit "
                . 'and is written to the audit trail.'
            );
        }

        $changed = [];
        foreach (($lock['paths'] ?? []) as $p) {
            $before = $lock['hashes'][$p] ?? null;
            $after  = is_file($p) ? hash_file('sha256', $p) : null;
            if ($before !== $after) {
                $changed[] = $p;
            }
        }

        $lock['released_at']    = gmdate('c');
        $lock['released_by']    = $owner;
        $lock['forced']         = $force;
        $lock['changed_paths']  = $changed;
        file_put_contents($this->path($scope), json_encode($lock, JSON_PRETTY_PRINT), LOCK_EX);
        @unlink($this->path($scope));

        $this->audit($scope, $force ? 'forced_release' : 'released', [
            'owner' => $owner, 'changed_paths' => $changed,
        ]);

        return ['released' => true, 'changed_paths' => $changed];
    }

    /** CR-22B: the lock file path, so governed tooling can rebase hashes. */
    public function lockFile(string $scope): string
    {
        return $this->path($scope);
    }

    public function read(string $scope): ?array
    {
        $path = $this->path($scope);
        if (!is_file($path)) {
            return null;
        }
        $d = json_decode((string) file_get_contents($path), true);

        return is_array($d) && ($d['released_at'] ?? null) === null ? $d : null;
    }

    public function isStale(array $lock): bool
    {
        $hb = strtotime((string) ($lock['heartbeat_at'] ?? $lock['acquired_at'] ?? '')) ?: 0;
        $exp = strtotime((string) ($lock['expires_at'] ?? '')) ?: 0;

        return (time() - $hb) > self::STALE_SECONDS || ($exp > 0 && time() > $exp);
    }

    /** @return array<int,array> all live locks */
    public function all(): array
    {
        if (!is_dir($this->lockDir)) {
            return [];
        }
        $out = [];
        foreach (glob($this->lockDir . '/*.lock.json') as $f) {
            $d = json_decode((string) file_get_contents($f), true);
            if (is_array($d) && ($d['released_at'] ?? null) === null) {
                $out[] = $d;
            }
        }

        return $out;
    }

    /**
     * CR-22B: opened up so governed write/restore tooling records through the
     * same audit trail. Behaviour unchanged.
     */
    public function audit(string $scope, string $event, array $data): void
    {
        $line = json_encode(array_merge([
            'at' => gmdate('c'), 'scope' => $scope, 'event' => $event, 'pid' => getmypid(),
        ], $data));
        @file_put_contents($this->lockDir . '/audit.log', $line . "\n", FILE_APPEND | LOCK_EX);
    }
}
