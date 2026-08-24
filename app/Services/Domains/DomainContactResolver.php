<?php

namespace App\Services\Domains;

/**
 * Supplies and validates registrant contact data for domain registrations.
 *
 * Two responsibilities, deliberately kept out of the registrar connector so the
 * adapter stays a pure protocol translator:
 *
 *   1. Provide the SANDBOX-ONLY test identity, and refuse to provide it
 *      anywhere else.
 *   2. Validate any contact -- sandbox or real customer -- BEFORE a billable
 *      API call is made, so a malformed field costs nothing.
 *
 * PRODUCTION ISOLATION
 * sandboxTestContact() has two independent locks: the configured environment
 * must be 'sandbox', AND the caller must explicitly opt in. It returns null
 * rather than throwing so a caller cannot accidentally swallow the refusal and
 * proceed with a partially-populated array. There is no code path by which
 * production registration can reach this data.
 */
class DomainContactResolver
{
    /** Fields Namecheap requires for every contact role. */
    public const REQUIRED_FIELDS = [
        'first_name', 'last_name', 'address1', 'city',
        'postal_code', 'country', 'phone', 'email',
    ];

    /**
     * The sandbox test registrant.
     *
     * @param bool $explicitlyRequested caller must pass true; a default-valued
     *                                  call site can never obtain this data
     * @return array<string,string>|null null when not permitted
     */
    public static function sandboxTestContact(bool $explicitlyRequested = false): ?array
    {
        if (! $explicitlyRequested) {
            return null;
        }

        // Lock 1: the adapter's configured environment.
        if (config('namecheap.environment') !== 'sandbox') {
            return null;
        }

        // Lock 2: the endpoint actually in use must be the sandbox endpoint.
        // Guards against an environment string that says sandbox while the
        // endpoint map has been pointed somewhere real.
        $endpoint = (string) config('namecheap.endpoints.sandbox');

        if (! str_contains($endpoint, 'sandbox.namecheap.com')) {
            return null;
        }

        $contact = (array) config('namecheap.sandbox_test_contact', []);

        // Never hand back a half-populated identity.
        return self::problems($contact) === [] ? $contact : null;
    }

    /** True when the sandbox test identity may be used right now. */
    public static function sandboxContactPermitted(): bool
    {
        return self::sandboxTestContact(true) !== null;
    }

    /**
     * Every problem with a contact, as human-readable strings.
     * An empty array means the contact is safe to submit.
     */
    public static function problems(array $c): array
    {
        $problems = [];

        $missing = self::missingFields($c);

        if ($missing !== []) {
            $problems[] = 'missing ' . implode(', ', $missing);
        }

        // Only format-check fields that are actually present; a missing field is
        // already reported above and should not be reported twice.
        $val = static fn (string $k): string => trim((string) ($c[$k] ?? ''));

        if ($val('email') !== '' && ! filter_var($val('email'), FILTER_VALIDATE_EMAIL)) {
            $problems[] = 'email is not a valid address';
        }

        // Namecheap requires +CC.NNNNNNNNNN -- a country code, a literal dot,
        // then digits. A plain national number is rejected by the API, so it is
        // rejected here first, where it costs nothing.
        if ($val('phone') !== '' && ! preg_match('/^\+[0-9]{1,3}\.[0-9]{4,14}$/', $val('phone'))) {
            $problems[] = 'phone must be in +CC.NNNNNNNNNN format (e.g. +1.2125550100)';
        }

        // ISO 3166-1 alpha-2.
        if ($val('country') !== '' && ! preg_match('/^[A-Za-z]{2}$/', $val('country'))) {
            $problems[] = 'country must be a two-letter ISO 3166-1 code (e.g. US)';
        }

        if ($val('postal_code') !== '' && ! preg_match('/^[A-Za-z0-9][A-Za-z0-9 \-]{1,11}$/', $val('postal_code'))) {
            $problems[] = 'postal_code is not a plausible postal code';
        }

        foreach (['first_name', 'last_name'] as $f) {
            if ($val($f) !== '' && preg_match('/[0-9]/', $val($f))) {
                $problems[] = "{$f} must not contain digits";
            }
        }

        if ($val('address1') !== '' && mb_strlen($val('address1')) < 3) {
            $problems[] = 'address1 is too short to be a real address';
        }

        if ($val('city') !== '' && preg_match('/[0-9]/', $val('city'))) {
            $problems[] = 'city must not contain digits';
        }

        return $problems;
    }

    /** @return string[] names of required fields that are absent or blank */
    public static function missingFields(array $c): array
    {
        return array_values(array_filter(
            self::REQUIRED_FIELDS,
            static fn (string $f): bool => trim((string) ($c[$f] ?? '')) === ''
        ));
    }

    public static function isValid(array $c): bool
    {
        return self::problems($c) === [];
    }

    /**
     * A contact reduced to something safe to write to a log or audit record.
     * Personal data is a liability in a log file: names, street addresses,
     * phone numbers and email addresses never appear. Only the SHAPE is kept.
     */
    public static function redact(array $c): array
    {
        $present = [];

        foreach (self::REQUIRED_FIELDS as $f) {
            $present[$f] = trim((string) ($c[$f] ?? '')) !== '';
        }

        return [
            'fields_present' => $present,
            'complete'       => self::missingFields($c) === [],
            'valid'          => self::isValid($c),
            // Country is the only value retained: it drives registry rules and
            // carries no personal identification on its own.
            'country'        => strtoupper(trim((string) ($c['country'] ?? ''))) ?: null,
        ];
    }
}
