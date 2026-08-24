<?php

namespace App\Engines\Infrastructure\Observation;

/**
 * INFRA888 · S6 — DRIFT TAXONOMY.
 *
 * Every drift class carries four things, because a finding nobody can act on is
 * noise: how bad it is, what an operator should do, whether the customer should
 * hear about it, and what the recommended action actually is.
 *
 * Nothing here repairs anything. The taxonomy describes; operators decide.
 *
 * Customer visibility is deliberately conservative. A customer needs to know
 * their domain may be at risk; they do not need our registrar's name, our
 * internal status codes, or a diff of nameserver hostnames they never set.
 */
final class DomainDrift
{
    // ── severities ───────────────────────────────────────────────────────────
    public const CRITICAL = 'critical';
    public const WARNING = 'warning';
    public const INFO = 'info';

    // ── drift classes ────────────────────────────────────────────────────────
    public const EXPIRY_EARLIER = 'expiry_earlier_than_believed';
    public const EXPIRY_LATER = 'expiry_later_than_believed';
    public const CUSTODY_LOST = 'custody_lost';
    public const NOT_MANAGED_BY_US = 'not_managed_by_us';
    public const NAMESERVER_DRIFT = 'nameserver_drift';
    public const AUTO_RENEW_DRIFT = 'auto_renew_drift';
    public const LOCK_DRIFT = 'registrar_lock_drift';
    public const STATUS_DRIFT = 'status_drift';
    public const REGISTRAR_UNAVAILABLE = 'registrar_unavailable';
    public const STALE_OBSERVATION = 'stale_observation';
    public const ORPHANED_ESTATE = 'orphaned_estate';
    public const ORPHANED_REGISTRAR = 'orphaned_registrar_asset';
    public const UNKNOWN = 'unknown_drift';

    /**
     * @return array<string,array{severity:string,title:string,operator:string,customer:?string,action:string}>
     */
    public static function catalogue(): array
    {
        return [
            self::EXPIRY_EARLIER => [
                'severity' => self::CRITICAL,
                'title' => 'Registrar expiry is EARLIER than the estate believes',
                'operator' => 'The domain will lapse sooner than any schedule we hold. A renewal we recorded may never have landed, or the term was shortened.',
                'customer' => 'We are re-checking this domain\'s renewal date.',
                'action' => 'Verify against the registrar account immediately and re-plan the renewal. Do not trust the recorded date.',
            ],
            self::EXPIRY_LATER => [
                'severity' => self::WARNING,
                'title' => 'Registrar expiry is LATER than the estate believes',
                'operator' => 'The domain was renewed outside our ledger — registrar auto-renew, a manual renewal, or an unrecorded call.',
                'customer' => null,
                'action' => 'Confirm who renewed it, then update the recorded expiry deliberately. Renewing again would double-charge.',
            ],
            self::CUSTODY_LOST => [
                'severity' => self::CRITICAL,
                'title' => 'Registrar no longer reports this domain as ours',
                'operator' => 'We believe we hold this domain; the registrar disagrees. Possible transfer away, expiry-and-release, or account change.',
                'customer' => 'We are verifying control of this domain.',
                'action' => 'Stop all renewal activity. Establish ownership before spending anything.',
            ],
            self::NOT_MANAGED_BY_US => [
                'severity' => self::WARNING,
                'title' => 'Domain exists at the registrar but is not under our management',
                'operator' => 'The registrar knows the domain but does not place it in our account.',
                'customer' => null,
                'action' => 'Confirm which account holds it. Custody may need correcting in the estate.',
            ],
            self::NAMESERVER_DRIFT => [
                'severity' => self::WARNING,
                'title' => 'Nameservers differ from the recorded set',
                'operator' => 'DNS delegation changed. This can be a legitimate customer change or an unauthorised one — it is how a domain gets hijacked.',
                'customer' => 'The DNS settings for this domain have changed.',
                'action' => 'Confirm the change was authorised. If not, treat as a security incident.',
            ],
            self::AUTO_RENEW_DRIFT => [
                'severity' => self::WARNING,
                'title' => 'Auto-renew flag differs from the estate',
                'operator' => 'Our renewal planning assumes one setting; the registrar has another.',
                'customer' => null,
                'action' => 'Decide the intended setting and align deliberately.',
            ],
            self::LOCK_DRIFT => [
                'severity' => self::WARNING,
                'title' => 'Registrar transfer lock differs from the estate',
                'operator' => 'An unlocked domain can be transferred away. Unexpected unlocking is a precursor to hijack.',
                'customer' => null,
                'action' => 'Confirm the unlock was requested. Re-lock if not.',
            ],
            self::STATUS_DRIFT => [
                'severity' => self::WARNING,
                'title' => 'Registrar domain status differs from the estate',
                'operator' => 'The registrar reports a lifecycle state we do not hold.',
                'customer' => null,
                'action' => 'Reconcile the status; check for expiry, hold or suspension.',
            ],
            self::REGISTRAR_UNAVAILABLE => [
                'severity' => self::CRITICAL,
                'title' => 'Registrar could not be reached',
                'operator' => 'We cannot see the truth at all. Absence of drift findings means nothing while this is true.',
                'customer' => null,
                'action' => 'Check credentials, IP whitelist and provider status. Treat all domain health as UNKNOWN until it clears.',
            ],
            self::STALE_OBSERVATION => [
                'severity' => self::WARNING,
                'title' => 'No recent observation of this domain',
                'operator' => 'The last look is old enough that current state is unknown, not healthy.',
                'customer' => null,
                'action' => 'Run an observation. Do not report confidence until one succeeds.',
            ],
            self::ORPHANED_ESTATE => [
                'severity' => self::CRITICAL,
                'title' => 'Estate holds a domain the registrar does not',
                'operator' => 'We are recording — and may be billing for — a domain that does not exist in our registrar account.',
                'customer' => 'We are verifying this domain.',
                'action' => 'Establish whether it was ever registered, transferred, or released. Do not renew.',
            ],
            self::ORPHANED_REGISTRAR => [
                'severity' => self::INFO,
                'title' => 'Registrar holds a domain the estate does not',
                'operator' => 'We own a domain nobody is tracking — it will silently expire because no renewal is planned for it.',
                'customer' => null,
                'action' => 'Adopt it into the estate or deliberately let it go.',
            ],
            self::UNKNOWN => [
                'severity' => self::WARNING,
                'title' => 'Unclassified difference',
                'operator' => 'A difference was detected that the taxonomy does not describe.',
                'customer' => null,
                'action' => 'Inspect the raw observation and extend the taxonomy.',
            ],
        ];
    }

    /** @return array<string,mixed> */
    public static function describe(string $class): array
    {
        return self::catalogue()[$class] ?? self::catalogue()[self::UNKNOWN];
    }

    public static function severityOf(string $class): string
    {
        return (string) self::describe($class)['severity'];
    }

    /** Highest severity present in a set of drift classes. */
    public static function highest(array $classes): ?string
    {
        $rank = [self::INFO => 1, self::WARNING => 2, self::CRITICAL => 3];
        $best = null;

        foreach ($classes as $c) {
            $s = self::severityOf(is_array($c) ? ($c['class'] ?? self::UNKNOWN) : $c);

            if ($best === null || $rank[$s] > $rank[$best]) {
                $best = $s;
            }
        }

        return $best;
    }

    /** Only drift classes with customer-safe wording are ever surfaced. */
    public static function customerMessage(string $class): ?string
    {
        return self::describe($class)['customer'];
    }
}
