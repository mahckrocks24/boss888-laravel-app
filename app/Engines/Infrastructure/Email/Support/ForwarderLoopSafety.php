<?php

namespace App\Engines\Infrastructure\Email\Support;

/**
 * INFRA888 · E1 — FORWARDING LOOP SAFETY.
 *
 * A forwarding loop is the one email misconfiguration that damages parties who
 * never agreed to anything: it amplifies a single message into an unbounded
 * stream, and the receiving side blames the sending domain's reputation. It
 * cannot be left to "the provider will probably reject it" — providers differ,
 * and the cheapest place to catch it is before the record is ever created.
 *
 * WHAT THIS DOES AND DOES NOT PROVE
 * It is a pure evaluation over records WE hold. It proves the two loop shapes
 * we can actually see:
 *
 *   SELF_REFERENCE — the destination is the source address itself, or resolves
 *   back into the same mail domain. Provably a loop, decidable from one record.
 *
 *   CHAIN_DETECTED — following our own forwarders from the destination returns
 *   to the source. Provably a loop across our own records.
 *
 * It CANNOT prove the absence of a loop, because the far side may forward back
 * to us and we have no way to see that. `SAFE` therefore means "no loop is
 * visible in records we hold", and the constant is named accordingly rather
 * than `NO_LOOP`. Claiming more than that would be exactly the kind of
 * confident wrongness the observation stack exists to avoid.
 *
 * No network calls. No DNS. Pure over supplied data, so it is testable and
 * cannot become a hidden provider dependency.
 */
final class ForwarderLoopSafety
{
    /** Never evaluated. The honest default for a new record. */
    public const UNCHECKED = 'unchecked';

    /** No loop visible in records we hold. NOT a proof that none exists. */
    public const SAFE = 'safe';

    /** Destination is the source, or lands back in the same mail domain. */
    public const SELF_REFERENCE = 'self_reference';

    /** Following our own forwarders from the destination returns to the source. */
    public const CHAIN_DETECTED = 'chain_detected';

    /** An address could not be parsed, so nothing can be concluded either way. */
    public const UNDETERMINED = 'undetermined';

    /** How many hops to follow before declaring a chain unresolvable. */
    public const MAX_HOPS = 10;

    public static function states(): array
    {
        return [
            self::UNCHECKED,
            self::SAFE,
            self::SELF_REFERENCE,
            self::CHAIN_DETECTED,
            self::UNDETERMINED,
        ];
    }

    /** States in which a forwarder must not be provisioned. */
    public static function blocking(): array
    {
        return [self::SELF_REFERENCE, self::CHAIN_DETECTED, self::UNDETERMINED, self::UNCHECKED];
    }

    public static function isBlocking(string $state): bool
    {
        return in_array($state, self::blocking(), true);
    }

    /**
     * Evaluate one proposed forwarder.
     *
     * @param string               $sourceAddress      full source address, e.g. sales@example.test
     * @param string               $destinationAddress full destination address
     * @param array<int,string>    $ownedDomains       mail domains this workspace operates
     * @param array<string,string> $existingForwarders source address => destination address,
     *                                                 lower-cased, for chain following
     *
     * @return array{state:string,reason:string,hops:int}
     */
    public static function evaluate(
        string $sourceAddress,
        string $destinationAddress,
        array $ownedDomains = [],
        array $existingForwarders = []
    ): array {
        $source = EmailAddress::normalize($sourceAddress);
        $destination = EmailAddress::normalize($destinationAddress);

        if ($source === null || $destination === null) {
            return self::result(
                self::UNDETERMINED,
                'source or destination is not a parseable address, so no conclusion is possible',
                0
            );
        }

        if ($source === $destination) {
            return self::result(self::SELF_REFERENCE, 'the destination is the source address', 0);
        }

        $owned = array_map('strtolower', $ownedDomains);
        $destinationDomain = EmailAddress::domainOf($destination);

        // Delivering back into a domain we operate means the message re-enters
        // our own routing and can be forwarded again. Even where it terminates
        // in a mailbox, this is a configuration the customer almost never
        // intends and must be reviewed rather than silently created.
        if ($destinationDomain !== null && in_array($destinationDomain, $owned, true)) {
            return self::result(
                self::SELF_REFERENCE,
                'the destination is inside a mail domain this workspace operates',
                0
            );
        }

        // Follow our own forwarders. Any return to the source is a loop; any
        // revisit of an already-seen address is a loop that does not include
        // the source and would still amplify.
        $map = [];

        foreach ($existingForwarders as $from => $to) {
            $f = EmailAddress::normalize((string) $from);
            $t = EmailAddress::normalize((string) $to);

            if ($f !== null && $t !== null) {
                $map[$f] = $t;
            }
        }

        $seen = [$source => true];
        $cursor = $destination;

        for ($hop = 1; $hop <= self::MAX_HOPS; $hop++) {
            if (isset($seen[$cursor])) {
                return self::result(
                    self::CHAIN_DETECTED,
                    "following existing forwarders returns to an address already in the chain after {$hop} hop(s)",
                    $hop
                );
            }

            $seen[$cursor] = true;

            if (! isset($map[$cursor])) {
                return self::result(
                    self::SAFE,
                    "no loop visible in records we hold; the chain terminates after {$hop} hop(s)",
                    $hop
                );
            }

            $cursor = $map[$cursor];
        }

        // Longer than we are willing to follow. Refusing to conclude is the
        // honest answer; asserting SAFE here would be a guess.
        return self::result(
            self::UNDETERMINED,
            'the forwarding chain exceeds ' . self::MAX_HOPS . ' hops and was not resolved',
            self::MAX_HOPS
        );
    }

    /** @return array{state:string,reason:string,hops:int} */
    private static function result(string $state, string $reason, int $hops): array
    {
        return ['state' => $state, 'reason' => $reason, 'hops' => $hops];
    }
}
