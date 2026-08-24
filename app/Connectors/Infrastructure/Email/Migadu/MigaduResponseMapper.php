<?php

namespace App\Connectors\Infrastructure\Email\Migadu;

use App\Connectors\Infrastructure\BusinessEmail\Values\MailDnsRecord;
use App\Connectors\Infrastructure\BusinessEmail\Values\MailboxUsageSample;
use App\Connectors\Infrastructure\BusinessEmail\Values\RemoteAlias;
use App\Connectors\Infrastructure\BusinessEmail\Values\RemoteCatchAll;
use App\Connectors\Infrastructure\BusinessEmail\Values\RemoteForwarder;
use App\Connectors\Infrastructure\BusinessEmail\Values\RemoteMailbox;
use DateTimeImmutable;

/**
 * INFRA888 · E5 — provider payload to provider-neutral value object.
 *
 * This is the membrane. Everything above it speaks the platform's vocabulary;
 * everything below speaks the vendor's. A provider field that has no neutral
 * equivalent stops here rather than travelling upward as an "extra" — which is
 * how provider lock-in gets into business logic one convenient field at a time.
 *
 * UNKNOWN IS NOT ZERO. Where the provider does not report a figure, these
 * mappers return null. E4's customer surface already renders null as "not
 * measured yet", and a fabricated zero would be a quiet lie about storage.
 */
final class MigaduResponseMapper
{
    /**
     * A provider reference must be stable, opaque and reconstructible. The
     * provider keys mailboxes by local part within a domain, so that pair IS
     * the identity — there is no separate id to store.
     */
    public static function mailboxRef(string $domain, string $localPart): string
    {
        return $domain . '/' . $localPart;
    }

    /** @return array{0:string,1:string} [domain, localPart] */
    public static function splitRef(string $ref): array
    {
        $parts = explode('/', $ref, 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new \InvalidArgumentException('Malformed provider reference.');
        }

        return [$parts[0], $parts[1]];
    }

    public static function toMailbox(string $domain, array $row): RemoteMailbox
    {
        $localPart = (string) ($row['local_part'] ?? '');

        return new RemoteMailbox(
            providerRef: self::mailboxRef($domain, $localPart),
            localPart: $localPart,
            displayName: self::nullableString($row['name'] ?? null),
            // Still null: the live object carries storage USAGE but no per-mailbox
            // storage LIMIT. Usage and quota are different questions, and only one
            // of them has an answer here.
            quotaMb: null,
            suspended: self::isSuspended($row),
            // CORRECTED IN E6. The documented mailbox object had no storage field
            // and E5 therefore reported per-mailbox usage as permanently unknown.
            // The live object returns `storage_usage`, so it is read.
            storageUsedMb: self::storageMb($row['storage_usage'] ?? null),
        );
    }

    /**
     * CORRECTED IN E6.
     *
     * The published mailbox schema had no `is_active` field, so E5 inferred
     * suspension from the five access flags and accepted that restoring was
     * lossy. The live object DOES carry `is_active`, which is a single
     * authoritative boolean — so it is trusted first, and the flag heuristic
     * survives only as a fallback for a response that omits it.
     */
    public static function isSuspended(array $row): ?bool
    {
        // INFRA888 · E7 — is_active FALSE MEANS TWO DIFFERENT THINGS.
        //
        // A suspended mailbox and a freshly invited one both come back with
        // is_active=false. `activated_at` separates them: an invited mailbox
        // has never been activated, so it is null. Without this, every mailbox
        // created through invitation mode would be reported to its customer as
        // SUSPENDED the moment it was created.
        if (self::isAwaitingActivation($row)) {
            return false;
        }

        if (array_key_exists('is_active', $row) && is_bool($row['is_active'])) {
            return ! $row['is_active'];
        }

        $flags = ['may_send', 'may_receive', 'may_access_imap', 'may_access_pop3', 'may_access_managesieve'];
        $present = 0;
        $off = 0;

        foreach ($flags as $flag) {
            if (! array_key_exists($flag, $row)) {
                continue;
            }

            $present++;

            if ($row[$flag] === false) {
                $off++;
            }
        }

        if ($present === 0) {
            return null;
        }

        return $off === $present;
    }

    /**
     * Provisioned, but nobody has claimed it yet.
     *
     * Proven live 2026-08-06: a mailbox created with password_method=invitation
     * returns is_active=false with activated_at=null. Once its owner sets a
     * password the provider activates it.
     */
    public static function isAwaitingActivation(array $row): bool
    {
        if (! array_key_exists('is_active', $row) || $row['is_active'] !== false) {
            return false;
        }

        // Never activated. A suspended mailbox WAS activated once, so it keeps
        // a timestamp; this is the field that tells them apart.
        return array_key_exists('activated_at', $row) && empty($row['activated_at']);
    }

    /**
     * `storage_usage` comes back as a float. Its UNIT is not documented and both
     * live mailboxes read 0, so the unit could not be determined by observation.
     * The same conservative rule as the domain figure is applied, and the
     * limitation is recorded rather than papered over.
     */
    private static function storageMb(mixed $value): ?int
    {
        return is_numeric($value) ? self::megabytes($value) : null;
    }

    /**
     * Forwardings arrive EMBEDDED in the mailbox row — a live fact the
     * documentation did not show, and a significant one: it removes the
     * per-mailbox fan-out the inventory previously needed, and with it the
     * truncation risk that came from bounding that fan-out.
     *
     * @return array<int,RemoteForwarder>
     */
    public static function embeddedForwarders(string $domain, array $mailboxRow): array
    {
        $localPart = (string) ($mailboxRow['local_part'] ?? '');
        $rows = $mailboxRow['forwardings'] ?? null;

        if ($localPart === '' || ! is_array($rows)) {
            return [];
        }

        $forwarders = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                $forwarders[] = self::toForwarder($domain, $localPart, $row);
            }
        }

        return $forwarders;
    }

    /** True when the mailbox row carries its forwardings inline. */
    public static function hasEmbeddedForwardings(array $mailboxRow): bool
    {
        return isset($mailboxRow['forwardings']) && is_array($mailboxRow['forwardings']);
    }

    public static function toAlias(string $domain, array $row): RemoteAlias
    {
        $localPart = (string) ($row['local_part'] ?? '');
        $destinations = self::destinations($row);

        return new RemoteAlias(
            providerRef: self::mailboxRef($domain, $localPart),
            sourceLocalPart: $localPart,
            // The neutral value object carries a single target. A provider alias
            // may fan out to several; the first is reported and the full set is
            // preserved for reconciliation rather than silently collapsed.
            targetAddress: $destinations[0] ?? null,
        );
    }

    public static function toForwarder(string $domain, string $mailboxLocalPart, array $row): RemoteForwarder
    {
        $address = (string) ($row['address'] ?? '');

        return new RemoteForwarder(
            providerRef: self::mailboxRef($domain, $mailboxLocalPart) . '/' . $address,
            sourceLocalPart: $mailboxLocalPart,
            destinationAddress: $address !== '' ? $address : null,
        );
    }

    /**
     * A forwarding the destination has not confirmed is NOT yet delivering mail.
     * Treating it as active would tell a customer their forwarding works when it
     * silently does not.
     */
    public static function forwarderIsConfirmed(array $row): bool
    {
        return ! empty($row['confirmed_at']) && empty($row['blocked_at']);
    }

    public static function toCatchAll(string $domain, array $rewrites): RemoteCatchAll
    {
        foreach ($rewrites as $row) {
            if (($row['local_part_rule'] ?? null) !== '*') {
                continue;
            }

            $destinations = self::destinations($row);

            return new RemoteCatchAll(
                enabled: true,
                targetAddress: $destinations[0] ?? null,
                providerRef: $domain . '/' . (string) ($row['name'] ?? MigaduRequestFactory::CATCHALL_SLUG),
            );
        }

        // An empty rewrite list is positive evidence of no catch-all, which is
        // the PTAA requirement — unknown recipients must be rejected.
        return RemoteCatchAll::disabled();
    }

    /**
     * DNS requirements, mapped from what the API ACTUALLY returns.
     *
     * CORRECTED IN E6 AGAINST THE LIVE API. The documented shape — a list of
     * records — does not exist. The real response is a keyed object whose keys
     * name the purpose, some holding a single record and some holding several:
     *
     *   { "spf":{…}, "dkim":[…3 CNAMEs…], "dmarc":{…},
     *     "dns_verification":{…}, "mx_records":[…2…], "domain_name":"…" }
     *
     * Two further live facts the documentation did not show: `type` comes back
     * LOWERCASE ("txt", "cname", "mx"), and DKIM is published as three CNAMEs
     * rather than a TXT key. The previous mapper found nothing it recognised in
     * this and returned a malformed-response failure — which is exactly what
     * live validation is for.
     *
     * @return array<int,MailDnsRecord>
     */
    public static function toDnsRecords(array $payload): array
    {
        // Section key => the purpose it represents. Anything not listed is
        // ignored rather than guessed at.
        $sections = [
            'mx_records'       => MailDnsRecord::PURPOSE_MX,
            'mx'               => MailDnsRecord::PURPOSE_MX,
            'spf'              => MailDnsRecord::PURPOSE_SPF,
            'dkim'             => MailDnsRecord::PURPOSE_DKIM,
            'dmarc'            => MailDnsRecord::PURPOSE_DMARC,
            'dns_verification' => MailDnsRecord::PURPOSE_VERIFICATION,
            'verification'     => MailDnsRecord::PURPOSE_VERIFICATION,
        ];

        $records = [];

        foreach ($sections as $key => $purpose) {
            if (! isset($payload[$key]) || ! is_array($payload[$key])) {
                continue;
            }

            $section = $payload[$key];

            // A section is either one record or a list of them. `name` being
            // present is what distinguishes a single record from a list.
            $rows = array_key_exists('name', $section) || array_key_exists('value', $section)
                ? [$section]
                : $section;

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $record = self::toDnsRecord($purpose, $row);

                if ($record !== null) {
                    $records[] = $record;
                }
            }
        }

        // The documented list shape has never been observed live, but costs
        // nothing to keep supporting in case the API converges on it later.
        if ($records === []) {
            $legacy = $payload['records'] ?? (array_is_list($payload) ? $payload : []);

            foreach ($legacy as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $purpose = self::purposeFor(
                    strtoupper((string) ($row['type'] ?? '')),
                    (string) ($row['host'] ?? $row['name'] ?? ''),
                    (string) ($row['value'] ?? $row['data'] ?? '')
                );

                if ($purpose === null) {
                    continue;
                }

                $record = self::toDnsRecord($purpose, $row);

                if ($record !== null) {
                    $records[] = $record;
                }
            }
        }

        return $records;
    }

    private static function toDnsRecord(string $purpose, array $row): ?MailDnsRecord
    {
        // Uppercased: the value object validates type against MX/TXT/CNAME and
        // the live API sends lowercase.
        $type = strtoupper((string) ($row['type'] ?? ''));
        $value = trim((string) ($row['value'] ?? $row['data'] ?? ''));

        if ($type === '' || $value === '') {
            return null;
        }

        if (! in_array($type, MailDnsRecord::types(), true)) {
            return null;
        }

        return new MailDnsRecord(
            purpose: $purpose,
            type: $type,
            name: (string) ($row['name'] ?? $row['host'] ?? '@'),
            value: $value,
            priority: isset($row['priority']) ? (int) $row['priority'] : null,
            ttl: isset($row['ttl']) ? (int) $row['ttl'] : null,
            // DMARC is the one record mail flows without. Everything else is
            // required, and marking it so is what lets the customer screen
            // distinguish "must add" from "should add".
            required: $purpose !== MailDnsRecord::PURPOSE_DMARC,
        );
    }

    /**
     * Classify a record by what it is FOR, not by what the provider called it.
     * Retained only for the documented-but-never-observed list shape above.
     */
    private static function purposeFor(string $type, string $name, string $value): ?string
    {
        $lowerName = strtolower($name);
        $lowerValue = strtolower($value);

        return match (true) {
            $type === 'MX'                                           => MailDnsRecord::PURPOSE_MX,
            $type === 'TXT' && str_contains($lowerValue, 'v=spf1')   => MailDnsRecord::PURPOSE_SPF,
            $type === 'TXT' && str_contains($lowerValue, 'v=dmarc1') => MailDnsRecord::PURPOSE_DMARC,
            $type === 'TXT' && str_contains($lowerValue, 'verify')   => MailDnsRecord::PURPOSE_VERIFICATION,
            str_contains($lowerName, '_dmarc')                       => MailDnsRecord::PURPOSE_DMARC,
            str_contains($lowerName, '_domainkey')                   => MailDnsRecord::PURPOSE_DKIM,
            $type === 'CNAME' && str_contains($lowerName, 'key')     => MailDnsRecord::PURPOSE_DKIM,
            default                                                  => null,
        };
    }

    /**
     * Domain-level usage. Per-mailbox storage is not published, so a single
     * domain sample is returned rather than a fabricated per-mailbox breakdown.
     *
     * @return array<int,MailboxUsageSample>
     */
    public static function toUsageSamples(array $payload, array $mailboxLocalParts, DateTimeImmutable $observedAt): array
    {
        $samples = [];

        foreach ($mailboxLocalParts as $localPart) {
            $samples[] = new MailboxUsageSample(
                localPart: (string) $localPart,
                observedAt: $observedAt,
                // Deliberately null: the provider reports storage per domain, and
                // dividing a domain total across mailboxes would be invention.
                storageUsedMb: null,
                storageQuotaMb: null,
                messagesSent: null,
                messagesReceived: null,
                lastLoginAt: null,
            );
        }

        return $samples;
    }

    /** Domain-level totals, for the operator surface and quota signals. */
    public static function toDomainUsage(array $payload): array
    {
        return [
            'storage_used_mb' => self::megabytes($payload['storage_usage'] ?? $payload['usage'] ?? null),
            'storage_limit_mb' => self::megabytes($payload['storage_limit'] ?? $payload['mailbox_default_storage_limit'] ?? null),
            'mailbox_count'   => isset($payload['mailboxes']) && is_numeric($payload['mailboxes'])
                ? (int) $payload['mailboxes']
                : null,
        ];
    }

    /**
     * The provider's units are not documented. Rather than guess between bytes
     * and megabytes and be wrong by six orders of magnitude, a plain integer is
     * treated as bytes only when it is implausibly large for megabytes.
     */
    private static function megabytes(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        if ($number <= 0) {
            return 0;
        }

        // Above a terabyte expressed in MB, the figure is far more likely bytes.
        return $number > 1_048_576 ? (int) round($number / 1_048_576) : (int) round($number);
    }

    /** @return array<int,string> */
    public static function destinations(array $row): array
    {
        $raw = $row['destinations'] ?? null;

        if (is_string($raw)) {
            $raw = array_map('trim', explode(',', $raw));
        }

        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $raw), fn (string $v) => $v !== ''));
    }

    /**
     * Envelope keys, as the live API actually returns them.
     *
     * CORRECTED IN E6: aliases come back under `address_aliases`, not `aliases`.
     * E5 looked for `aliases`, found nothing, and reported an alias count of
     * null — which read as "we could not tell" when the truth was "we looked in
     * the wrong place". Each logical collection now carries its known aliases.
     *
     * @return array<int,array>
     */
    public static function rows(array $payload, string $key): array
    {
        $envelopes = match ($key) {
            'aliases'     => ['address_aliases', 'aliases'],
            'mailboxes'   => ['mailboxes'],
            'rewrites'    => ['rewrites'],
            'forwardings' => ['forwardings'],
            'domains'     => ['domains'],
            'identities'  => ['identities'],
            default       => [$key],
        };

        foreach ($envelopes as $envelope) {
            if (isset($payload[$envelope]) && is_array($payload[$envelope])) {
                return array_values(array_filter($payload[$envelope], 'is_array'));
            }
        }

        // A bare list is still accepted; no endpoint has been observed returning
        // one, but it costs nothing to keep working if that changes.
        return array_is_list($payload)
            ? array_values(array_filter($payload, 'is_array'))
            : [];
    }

    private static function nullableString(mixed $value): ?string
    {
        $string = is_scalar($value) ? trim((string) $value) : '';

        return $string === '' ? null : $string;
    }
}
