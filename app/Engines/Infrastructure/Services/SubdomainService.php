<?php

namespace App\Engines\Infrastructure\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * SubdomainService — the SINGLE canonical authority for subdomain labels.
 *
 * Created 2026-07-23 (INFRA888 hosting productization, step 1 of the locked
 * implementation order). Builder currently owns subdomain assignment through two
 * route closures in routes/api.php (POST /builder/websites/{id}/set-subdomain and
 * the availability check). Those closures are to be pointed at THIS service last,
 * once the hosting vertical slice is proven — see the handoff. Until then this is
 * a faithful superset of their rules, so both paths agree by construction.
 *
 * CANONICAL RULES (do not fork these):
 *   normalize : lowercase -> strip non [a-z0-9-] -> collapse hyphens -> trim hyphens
 *   regex     : ^[a-z0-9][a-z0-9-]{1,48}[a-z0-9]$
 *   length    : 3-50
 *   hostname  : {label}.levelupgrowth.io
 *
 * NOTE on the pre-existing divergence this service resolves: the set-subdomain
 * closure enforced the 3-50 regex while the availability closure enforced a
 * 2-40 length window, so a 41-50 char label passed one check and failed the
 * other. The regex above (3-50) is canonical per the architecture decision.
 */
class SubdomainService
{
    public const ROOT_DOMAIN = 'levelupgrowth.io';

    public const PATTERN = '/^[a-z0-9][a-z0-9-]{1,48}[a-z0-9]$/';

    public const MIN_LENGTH = 3;
    public const MAX_LENGTH = 50;

    /**
     * Reserved labels. Verbatim from the two Builder closures so behaviour is
     * identical when they are migrated onto this service.
     */
    public const RESERVED = [
        'www', 'app', 'api', 'admin', 'staging', 'mail', 'ftp', 'smtp', 'pop',
        'levelup', 'levelupgrowth', 'support', 'help', 'blog', 'test', 'demo',
        'dashboard', 'panel', 'login', 'signup', 'register', 'auth', 'oauth',
        'billing', 'payment', 'stripe', 'webhook', 'internal', 'system', 'root',
        'cdn', 'assets', 'static', 'media', 'img', 'images', 'css', 'js',
    ];

    /**
     * Normalise a raw user string into a candidate label.
     * Never throws — normalisation of garbage yields '' and validation rejects it.
     */
    public function normalize(string $raw): string
    {
        $slug = strtolower(trim($raw));

        // A full hostname is accepted and reduced to its first label, so pasting
        // "shop.levelupgrowth.io" behaves the same as typing "shop".
        if (str_contains($slug, '.')) {
            $slug = explode('.', $slug)[0];
        }

        $slug = preg_replace('/[^a-z0-9-]+/', '', $slug) ?? '';
        $slug = preg_replace('/-+/', '-', $slug) ?? '';

        return trim($slug, '-');
    }

    public function isReserved(string $label): bool
    {
        return in_array($label, self::RESERVED, true);
    }

    /**
     * Validate an ALREADY-normalised label against format + reserved rules.
     *
     * @return array{valid:bool, error:?string}
     */
    public function check(string $label): array
    {
        if ($label === '') {
            return ['valid' => false, 'error' => 'Choose a web address.'];
        }

        $len = strlen($label);

        if ($len < self::MIN_LENGTH || $len > self::MAX_LENGTH) {
            return [
                'valid' => false,
                'error' => sprintf(
                    'Use between %d and %d characters.',
                    self::MIN_LENGTH,
                    self::MAX_LENGTH
                ),
            ];
        }

        if (!preg_match(self::PATTERN, $label)) {
            return [
                'valid' => false,
                'error' => 'Use lowercase letters, numbers and hyphens only. It cannot start or end with a hyphen.',
            ];
        }

        if ($this->isReserved($label)) {
            return ['valid' => false, 'error' => 'That web address is reserved. Try another.'];
        }

        return ['valid' => true, 'error' => null];
    }

    /**
     * Canonical hostname for a label. Assumes the label is already validated.
     */
    public function hostname(string $label): string
    {
        return $label . '.' . self::ROOT_DOMAIN;
    }

    /**
     * Is the hostname free in the websites table?
     * Mirrors the Builder closures: compares against the FULL hostname, which is
     * what websites.subdomain actually stores, and ignores soft-deleted rows.
     */
    public function isAvailable(string $label, ?int $excludeWebsiteId = null): bool
    {
        $q = DB::table('websites')
            ->where('subdomain', $this->hostname($label))
            ->whereNull('deleted_at');

        if ($excludeWebsiteId !== null && $excludeWebsiteId > 0) {
            $q->where('id', '!=', $excludeWebsiteId);
        }

        return !$q->exists();
    }

    /**
     * Suggest an unused alternative when the preferred label is taken/reserved.
     * Bounded: gives up after a fixed number of attempts rather than looping.
     */
    public function suggest(string $label, ?int $excludeWebsiteId = null): ?string
    {
        $base = substr($label, 0, self::MAX_LENGTH - 6);

        foreach (['-site', '-online', '-hq'] as $suffix) {
            $candidate = $this->normalize($base . $suffix);
            if ($this->check($candidate)['valid'] && $this->isAvailable($candidate, $excludeWebsiteId)) {
                return $candidate;
            }
        }

        for ($i = 2; $i <= 20; $i++) {
            $candidate = $this->normalize($base . '-' . $i);
            if ($this->check($candidate)['valid'] && $this->isAvailable($candidate, $excludeWebsiteId)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Full pipeline: normalise, validate, check availability.
     * Throws InvalidArgumentException with a CUSTOMER-SAFE message on failure.
     */
    public function requireAvailable(string $raw, ?int $excludeWebsiteId = null): string
    {
        $label = $this->normalize($raw);
        $check = $this->check($label);

        if (!$check['valid']) {
            throw new InvalidArgumentException($check['error']);
        }

        if (!$this->isAvailable($label, $excludeWebsiteId)) {
            $suggestion = $this->suggest($label, $excludeWebsiteId);

            throw new InvalidArgumentException(
                'That web address is already taken.'
                . ($suggestion ? ' Try "' . $suggestion . '" instead.' : ' Try another.')
            );
        }

        return $label;
    }
}
