<?php

namespace App\Engines\Infrastructure\Observation;

use Illuminate\Support\Facades\DB;

/**
 * INFRA888 · S8 — CUSTODY MODEL.
 *
 * Custody answers "what are we even allowed to know about this thing?" — and
 * that governs which observers can run and what confidence their answers carry.
 *
 * The estate's whole credibility rests on not conflating these. An expiry date
 * read from our own registrar account is VERIFIED. The same date scraped from
 * public WHOIS for a domain a customer holds elsewhere is INFERRED — the format
 * is unstandardised, the data is often redacted, and nobody guarantees it. Both
 * are useful; treating them as equally true is how a monitoring system becomes
 * confidently wrong.
 */
final class Custody
{
    /** We hold the registrar account. Full API truth available. */
    public const MANAGED_BY_US = 'managed_by_us';

    /** The customer holds it at their own registrar; we serve the site. Public signals only. */
    public const CUSTOMER_HELD = 'customer_held';

    /** Someone else entirely holds and operates it. Public signals only, lower expectations. */
    public const THIRD_PARTY_HELD = 'third_party_held';

    /** We genuinely do not know. Never assume the flattering answer. */
    public const UNKNOWN = 'unknown';

    // ── confidence a custody permits ─────────────────────────────────────────
    public const VERIFIED = 'verified';
    public const INFERRED = 'inferred';
    public const UNCERTAIN = 'unknown';

    /**
     * Which dimensions are observable under each custody, and at what confidence.
     *
     * Note DNS, certificate and HTTP are VERIFIED regardless of custody: they are
     * measured directly from the public internet, so who owns the registrar does
     * not weaken them. Only registrar and WHOIS depend on account access.
     *
     * @return array<string,array<string,string>>
     */
    public static function matrix(): array
    {
        return [
            self::MANAGED_BY_US => [
                'registrar' => self::VERIFIED,
                'dns' => self::VERIFIED,
                'certificate' => self::VERIFIED,
                'http' => self::VERIFIED,
                // WHOIS adds nothing we cannot read authoritatively from the API.
                'whois' => self::INFERRED,
            ],
            self::CUSTOMER_HELD => [
                'registrar' => self::UNCERTAIN,   // no account access — cannot observe
                'dns' => self::VERIFIED,
                'certificate' => self::VERIFIED,
                'http' => self::VERIFIED,
                'whois' => self::INFERRED,        // the only expiry signal available
            ],
            self::THIRD_PARTY_HELD => [
                'registrar' => self::UNCERTAIN,
                'dns' => self::VERIFIED,
                'certificate' => self::VERIFIED,
                'http' => self::VERIFIED,
                'whois' => self::INFERRED,
            ],
            self::UNKNOWN => [
                'registrar' => self::UNCERTAIN,
                'dns' => self::VERIFIED,
                'certificate' => self::VERIFIED,
                'http' => self::VERIFIED,
                'whois' => self::INFERRED,
            ],
        ];
    }

    /** Dimensions worth running for this custody. */
    public static function observableDimensions(string $custody): array
    {
        $row = self::matrix()[$custody] ?? self::matrix()[self::UNKNOWN];

        // A dimension we cannot observe at all is excluded rather than recorded
        // as a failure — "no registrar access" is a property of custody, not an
        // incident.
        return array_keys(array_filter($row, fn ($c) => $c !== self::UNCERTAIN));
    }

    public static function confidenceFor(string $custody, string $dimension): string
    {
        return self::matrix()[$custody][$dimension] ?? self::UNCERTAIN;
    }

    /**
     * Resolve custody from what the platform actually records.
     *
     * @return array{custody:string,reason:string,customer_domain_id:?int,website_id:?int,workspace_id:?int}
     */
    public static function resolve(string $hostname): array
    {
        $host = strtolower(trim($hostname));

        // 1. Registered through us -> we hold the registrar account.
        $cd = DB::table('customer_domains')->whereRaw('LOWER(domain) = ?', [$host])->first();

        if ($cd !== null) {
            return [
                'custody' => self::MANAGED_BY_US,
                'reason' => 'registered through our registrar account (customer_domains)',
                'customer_domain_id' => (int) $cd->id,
                'website_id' => null,
                'workspace_id' => (int) $cd->workspace_id,
            ];
        }

        // 2. A Builder site serves it, but we never registered it -> customer holds it.
        $site = DB::table('websites')
            ->whereNull('deleted_at')
            ->whereRaw('LOWER(custom_domain) = ?', [$host])
            ->first();

        if ($site !== null) {
            return [
                'custody' => self::CUSTOMER_HELD,
                'reason' => 'served by our platform but registered elsewhere (websites.custom_domain)',
                'customer_domain_id' => null,
                'website_id' => (int) $site->id,
                'workspace_id' => (int) $site->workspace_id,
            ];
        }

        // 3. Recorded as a custom domain we adopted but do not register.
        $custom = DB::table('custom_domains')->whereRaw('LOWER(hostname) = ?', [$host])->first();

        if ($custom !== null) {
            return [
                'custody' => self::CUSTOMER_HELD,
                'reason' => 'adopted custom domain, registrar not held by us (custom_domains)',
                'customer_domain_id' => null,
                'website_id' => (int) ($custom->website_id ?? 0) ?: null,
                'workspace_id' => (int) $custom->workspace_id,
            ];
        }

        return [
            'custody' => self::UNKNOWN,
            'reason' => 'no platform record associates this hostname with a workspace',
            'customer_domain_id' => null,
            'website_id' => null,
            'workspace_id' => null,
        ];
    }

    /**
     * Every hostname the estate should be watching, with custody resolved.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function estateSubjects(?int $workspaceId = null): array
    {
        $hosts = [];

        foreach (DB::table('customer_domains')->get() as $r) {
            $hosts[strtolower($r->domain)] = true;
        }

        foreach (DB::table('custom_domains')->get() as $r) {
            $hosts[strtolower($r->hostname)] = true;
        }

        foreach (DB::table('websites')->whereNull('deleted_at')
            ->whereNotNull('custom_domain')->where('custom_domain', '<>', '')->get() as $r) {
            $hosts[strtolower($r->custom_domain)] = true;
        }

        $out = [];

        foreach (array_keys($hosts) as $h) {
            $c = Custody::resolve($h);

            if ($workspaceId !== null && (int) $c['workspace_id'] !== $workspaceId) {
                continue;
            }

            $out[] = ['subject' => $h] + $c;
        }

        usort($out, fn ($a, $b) => strcmp($a['subject'], $b['subject']));

        return $out;
    }
}
