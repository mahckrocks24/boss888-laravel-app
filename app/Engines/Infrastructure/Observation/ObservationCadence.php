<?php

namespace App\Engines\Infrastructure\Observation;

/**
 * INFRA888 · S7 — OBSERVATION CADENCE.
 *
 * Cadence is a risk decision, not a preference. Each interval below is chosen
 * from how fast the underlying fact can change AND how bad it is to learn late.
 *
 * These are DEFINITIONS ONLY. No schedule is registered — activation requires
 * explicit authorisation (S7 operational rules). `scheduleProposal()` exists so
 * the intended registration is reviewable before anyone turns it on.
 */
final class ObservationCadence
{
    public const REGISTRAR = 'registrar';
    public const EXPIRY = 'expiry';
    public const CUSTODY = 'custody';
    public const DNS = 'dns';
    public const NAMESERVER = 'nameserver';
    public const CERTIFICATE = 'certificate';

    /**
     * interval_minutes : how often to look
     * stale_after_minutes : when a successful observation stops being evidence
     *
     * stale is deliberately ~3x the interval: one missed run is noise, three
     * consecutive misses is a real gap in knowledge.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function all(): array
    {
        return [
            self::REGISTRAR => [
                'interval_minutes' => 360,   // 6h
                'stale_after_minutes' => 1080, // 18h
                'severity_when_stale' => 'medium',
                'why' => 'The registrar API is authoritative for ownership, lock and auto-renew, '
                    . 'but it is rate-limited and IP-whitelisted. Six hours bounds how long a custody '
                    . 'change or silent renewal can hide while staying well inside sane API budgets.',
            ],
            self::EXPIRY => [
                'interval_minutes' => 360,   // 6h, rides the registrar probe
                'stale_after_minutes' => 1440, // 24h
                'severity_when_stale' => 'medium',
                'why' => 'Expiry moves rarely — usually once a year — so frequent polling buys nothing. '
                    . 'What matters is never being more than a few hours wrong in the final 30 days, '
                    . 'and it costs nothing extra because it rides the same registrar call.',
            ],
            self::CUSTODY => [
                'interval_minutes' => 360,   // 6h, same call
                'stale_after_minutes' => 720,  // 12h — tighter: losing custody is catastrophic
                'severity_when_stale' => 'high',
                'why' => 'Custody loss is the most expensive fact to learn late: a transferred-away or '
                    . 'released domain cannot be recovered by spending money. Same call as registrar, '
                    . 'but a shorter staleness tolerance because unknown custody is itself dangerous.',
            ],
            self::NAMESERVER => [
                'interval_minutes' => 60,    // 1h
                'stale_after_minutes' => 240,  // 4h
                'severity_when_stale' => 'medium',
                'why' => 'Delegation change is the hijack signature, and it is the one fact an attacker '
                    . 'controls directly. Hourly is the tightest loop that stays cheap, and the blast '
                    . 'radius of learning late is the whole site plus its email.',
            ],
            self::DNS => [
                'interval_minutes' => 60,    // 1h
                'stale_after_minutes' => 240,  // 4h
                'severity_when_stale' => 'medium',
                'why' => 'Resolution is what customers actually experience, and it can contradict what '
                    . 'the registrar reports. It is a cheap public lookup with no API budget, so the '
                    . 'only limit is noise.',
            ],
            self::CERTIFICATE => [
                'interval_minutes' => 720,   // 12h
                'stale_after_minutes' => 2880, // 48h
                'severity_when_stale' => 'low',
                'why' => 'Certificates change on a 60-90 day renewal cycle, so 12h is far tighter than '
                    . 'the fact moves. It exists to catch a FAILED renewal with weeks of runway left, '
                    . 'not to watch a certificate that is fine.',
            ],
        ];
    }

    /** @return array<string,mixed> */
    public static function for(string $kind): array
    {
        return self::all()[$kind] ?? self::all()[self::REGISTRAR];
    }

    public static function staleAfterMinutes(string $kind): int
    {
        return (int) self::for($kind)['stale_after_minutes'];
    }

    /**
     * The registration we would add IF activation were authorised. Deliberately
     * inert — reviewable, not runnable.
     *
     * @return array<int,array<string,string>>
     */
    public static function scheduleProposal(): array
    {
        return [
            ['command' => 'infra:estate-observe run --kind=registrar', 'cron' => '0 */6 * * *',
                'note' => 'registrar + expiry + custody ride one API call'],
            ['command' => 'infra:estate-observe run --kind=dns', 'cron' => '0 * * * *',
                'note' => 'nameserver + resolution; public lookups only'],
            ['command' => 'infra:estate-observe run --kind=certificate', 'cron' => '0 */12 * * *',
                'note' => 'TLS handshake observation only'],
            ['command' => 'infra:estate-alerts escalate', 'cron' => '*/15 * * * *',
                'note' => 'advances escalation clocks; raises nothing new'],
        ];
    }
}
