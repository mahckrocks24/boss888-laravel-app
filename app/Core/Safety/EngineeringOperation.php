<?php

namespace App\Core\Safety;

use RuntimeException;

/**
 * RSK-M4-1 — one high-impact engineering operation at a time.
 *
 * The routes lock protects FILES. This protects the OPERATION: a migration, a
 * deploy, a milestone write. Two of those should not run against the same
 * production environment at once even when they touch different files, because
 * the thing they share is the database, the route table and the evidence trail.
 *
 * It is deliberately thin. Ownership, expiry, heartbeat, stale takeover and the
 * audit trail already exist in SourceOwnershipLock and are reused unchanged —
 * this adds a scope and a preflight, not a second locking system.
 *
 * begin() fails closed. It refuses when another operation holds the lock, and
 * refuses when ConcurrencyGuard reports a blocking conflict. The refusal names
 * the other owner and the evidence, so the answer to "why did my deploy stop?"
 * is on screen rather than in a log somewhere.
 *
 * Honest limit, stated once and repeated in the guard: this binds operations
 * that call it. A session that runs `artisan migrate` directly is DETECTED by
 * the preflight, not prevented. That is a real gap and it is documented rather
 * than papered over.
 */
final class EngineeringOperation
{
    /** One environment-wide operation scope. */
    public const SCOPE = 'engineering-operation';

    /** Operations are expected to be minutes, not hours. */
    public const DEFAULT_TTL = 1800;

    public function __construct(
        private SourceOwnershipLock $locks = new SourceOwnershipLock(),
        private ?ConcurrencyGuard $guard = null,
    ) {
        $this->guard ??= new ConcurrencyGuard($this->locks);
    }

    /**
     * Start an operation, or refuse with the reason.
     *
     * @param string $owner  session identifier, e.g. 'e1-m5-incidents'
     * @param string $phase  human phase description for the audit trail
     * @param bool   $force  proceed despite blocking conflicts. Always recorded.
     *
     * @return array{owner:string, phase:string, lock:array, assessment:array, forced:bool}
     *
     * @throws RuntimeException when another operation is live, or a blocking
     *                          conflict is present and $force is false
     */
    public function begin(string $owner, string $phase, bool $force = false, int $ttl = self::DEFAULT_TTL): array
    {
        $assessment = $this->guard->assess($owner);

        if ($assessment['blocking'] !== [] && !$force) {
            $this->locks->audit(self::SCOPE, 'operation_refused', [
                'owner' => $owner,
                'phase' => $phase,
                'verdict' => $assessment['verdict'],
                'blocking' => array_column($assessment['blocking'], 'check'),
                'summary' => array_column($assessment['blocking'], 'summary'),
            ]);

            throw new RuntimeException(
                "REFUSING to start '{$phase}': concurrent engineering work is present.\n"
                . $this->describe($assessment['blocking'])
                . "\nCoordinate, wait, or pass force=true to proceed deliberately — a forced start is recorded."
            );
        }

        // Fails closed if another operation holds the scope; re-entrant for us.
        $lock = $this->locks->acquire(self::SCOPE, $owner, $phase, [], $ttl);

        $this->locks->audit(self::SCOPE, 'operation_begin', [
            'owner' => $owner,
            'phase' => $phase,
            'verdict' => $assessment['verdict'],
            'conflicts' => array_column($assessment['conflicts'], 'check'),
            'forced' => $force,
        ]);

        return [
            'owner' => $owner,
            'phase' => $phase,
            'lock' => $lock,
            'assessment' => $assessment,
            'forced' => $force,
        ];
    }

    /**
     * Finish an operation, recording what the environment looked like on the
     * way out. The closing assessment is evidence in its own right: a conflict
     * that appeared during the operation is worth knowing about afterwards.
     */
    public function end(string $owner): array
    {
        $assessment = $this->guard->assess($owner);

        $this->locks->audit(self::SCOPE, 'operation_end', [
            'owner' => $owner,
            'verdict' => $assessment['verdict'],
            'conflicts' => array_column($assessment['conflicts'], 'check'),
        ]);

        $released = $this->locks->release(self::SCOPE, $owner);

        return ['released' => $released, 'assessment' => $assessment];
    }

    /** Who holds the operation lock right now, if anyone. */
    public function current(): ?array
    {
        $lock = $this->locks->read(self::SCOPE);

        if ($lock === null || ($lock['released_at'] ?? null) !== null) {
            return null;
        }

        return $lock;
    }

    private function describe(array $blocking): string
    {
        $lines = [];
        foreach ($blocking as $b) {
            $lines[] = "  - [{$b['check']}] {$b['summary']}";
            foreach (array_slice($b['evidence'], 0, 5) as $e) {
                $lines[] = '      ' . (is_array($e) ? json_encode($e) : (string) $e);
            }
        }

        return implode("\n", $lines);
    }
}
