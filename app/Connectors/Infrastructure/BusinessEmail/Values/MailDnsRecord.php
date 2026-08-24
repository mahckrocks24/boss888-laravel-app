<?php

namespace App\Connectors\Infrastructure\BusinessEmail\Values;

use InvalidArgumentException;

/**
 * INFRA888 · E2 — one DNS record a customer must publish, as an abstract object.
 *
 * WHY THIS IS A TYPED OBJECT AND NOT A STRING
 * The masterplan is explicit: DNS requirements come back as abstract record
 * objects, not provider strings. The reason is the one hard limit of the whole
 * white-label design. MX, SPF and DKIM values physically contain the provider's
 * hostnames — that is unavoidable and was accepted deliberately (Option A). What
 * is NOT unavoidable is provider PROSE reaching the customer: a provider that
 * returns "Add these records in your DNS panel — see help.example-vendor.com"
 * would put a vendor name on a LevelUp screen if the payload were a string blob.
 *
 * Splitting the record into typed fields means the engine renders the
 * instruction in LevelUp's own words and only the record VALUE — the part that
 * genuinely must be published verbatim — passes through.
 *
 * `purpose` exists so the engine can group and explain records without parsing
 * their contents. Deciding "this TXT is the SPF one" by string-matching its
 * value would be exactly the provider-format coupling this object prevents.
 */
final class MailDnsRecord
{
    // ── purpose: what this record is FOR, in our vocabulary ──────────────────
    public const PURPOSE_MX = 'mx';
    public const PURPOSE_SPF = 'spf';
    public const PURPOSE_DKIM = 'dkim';
    public const PURPOSE_DMARC = 'dmarc';
    /** Provider ownership proof. Not part of mail flow. */
    public const PURPOSE_VERIFICATION = 'verification';

    /** @return array<int,string> */
    public static function purposes(): array
    {
        return [
            self::PURPOSE_MX,
            self::PURPOSE_SPF,
            self::PURPOSE_DKIM,
            self::PURPOSE_DMARC,
            self::PURPOSE_VERIFICATION,
        ];
    }

    /** Standard DNS types only. No vendor record types exist in this contract. */
    public static function types(): array
    {
        return ['MX', 'TXT', 'CNAME'];
    }

    /**
     * @param string   $purpose  one of self::purposes()
     * @param string   $type     MX | TXT | CNAME
     * @param string   $name     host, or '@' for the zone apex
     * @param string   $value    published verbatim; may contain provider hostnames
     * @param int|null $priority MX only
     * @param int|null $ttl      provider suggestion; null means "customer's default"
     * @param bool     $required false for advisory records such as DMARC
     */
    public function __construct(
        public readonly string $purpose,
        public readonly string $type,
        public readonly string $name,
        public readonly string $value,
        public readonly ?int $priority = null,
        public readonly ?int $ttl = null,
        public readonly bool $required = true,
    ) {
        if (! in_array($purpose, self::purposes(), true)) {
            throw new InvalidArgumentException("MailDnsRecord: unknown purpose '{$purpose}'.");
        }

        if (! in_array($type, self::types(), true)) {
            throw new InvalidArgumentException(
                "MailDnsRecord: '{$type}' is not a standard record type this contract accepts."
            );
        }

        if (trim($name) === '' || trim($value) === '') {
            throw new InvalidArgumentException('MailDnsRecord: name and value are both required.');
        }

        if ($type === 'MX' && $priority === null) {
            throw new InvalidArgumentException('MailDnsRecord: an MX record without a priority is unusable.');
        }
    }

    /**
     * The customer-facing shape.
     *
     * Contains no provider identifier, no provider URL and no provider prose —
     * only the record the customer must publish, plus our own vocabulary for
     * what it is. The value itself is passed through because it has to be.
     */
    public function toCustomerArray(): array
    {
        return [
            'purpose'  => $this->purpose,
            'type'     => $this->type,
            'name'     => $this->name,
            'value'    => $this->value,
            'priority' => $this->priority,
            'ttl'      => $this->ttl,
            'required' => $this->required,
        ];
    }

    /** Identity for comparing an expected record against an observed one. */
    public function fingerprint(): string
    {
        return strtolower(implode('|', [
            $this->type,
            trim($this->name, '.'),
            $this->value,
            (string) $this->priority,
        ]));
    }

    public function matches(self $other): bool
    {
        return $this->fingerprint() === $other->fingerprint();
    }

    /** @param array<int,self> $records */
    public static function toCustomerArrayList(array $records): array
    {
        return array_values(array_map(fn (self $r) => $r->toCustomerArray(), $records));
    }
}
