<?php

namespace App\Engines\Infrastructure\Email\Support;

/**
 * INFRA888 · E1 — address and local-part normalisation.
 *
 * One place decides what a local part and an address are, because the answer is
 * used by a unique constraint, by loop detection and by the customer surface.
 * Three implementations of "is this valid" would disagree, and the disagreement
 * would surface as a mailbox that can be created but never addressed.
 *
 * DELIBERATELY CONSERVATIVE. RFC 5321 permits quoted local parts containing
 * spaces and almost any character. Providers overwhelmingly do not, and a local
 * part we accept but the provider rejects becomes a provisioning failure the
 * customer cannot understand. The accepted set here is the intersection that
 * works everywhere: letters, digits, dot, hyphen, underscore and plus, not
 * starting or ending with a dot, no consecutive dots.
 *
 * LENGTHS come from RFC 5321 section 4.5.3.1 and are enforced in the schema:
 * local part 64 octets, domain 253, full address 320.
 */
final class EmailAddress
{
    public const MAX_LOCAL_PART = 64;
    public const MAX_DOMAIN = 253;
    public const MAX_ADDRESS = 320;

    /**
     * Local parts that must never be handed to a customer as an ordinary
     * mailbox because mail infrastructure and certificate authorities treat
     * them as privileged. RFC 2142 plus the CA/Browser Forum validation set.
     *
     * Not a security boundary on its own — it is a default the engine applies
     * so that `admin@` and `hostmaster@` are a deliberate administrative
     * decision rather than something a self-service form hands out.
     */
    public const RESERVED_LOCAL_PARTS = [
        'abuse', 'admin', 'administrator', 'hostmaster', 'postmaster',
        'webmaster', 'security', 'ssladmin', 'ssladministrator', 'sslwebmaster',
        'root', 'mailer-daemon', 'noc',
    ];

    public static function isValidLocalPart(string $localPart): bool
    {
        $local = trim($localPart);

        if ($local === '' || strlen($local) > self::MAX_LOCAL_PART) {
            return false;
        }

        if (str_starts_with($local, '.') || str_ends_with($local, '.') || str_contains($local, '..')) {
            return false;
        }

        return (bool) preg_match('/^[A-Za-z0-9._+-]+$/', $local);
    }

    public static function isReservedLocalPart(string $localPart): bool
    {
        return in_array(strtolower(trim($localPart)), self::RESERVED_LOCAL_PARTS, true);
    }

    public static function isValidDomain(string $domain): bool
    {
        $host = strtolower(trim($domain));

        if ($host === '' || strlen($host) > self::MAX_DOMAIN) {
            return false;
        }

        // At least one dot: a single-label host cannot receive internet mail.
        if (! str_contains($host, '.')) {
            return false;
        }

        foreach (explode('.', $host) as $label) {
            if ($label === '' || strlen($label) > 63) {
                return false;
            }

            if (! preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $label)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Canonical form used for storage comparison and loop detection: trimmed and
     * lower-cased.
     *
     * NOTE ON CASE. RFC 5321 says the local part is case-SENSITIVE and only the
     * domain is not. In practice no provider we would integrate treats
     * Sales@ and sales@ as different mailboxes, and treating them as different
     * would let one customer create both and receive neither reliably. We
     * therefore normalise the whole address, and the uniqueness constraint in
     * the schema is on the normalised value.
     *
     * @return string|null null when the input is not a usable address
     */
    public static function normalize(string $address): ?string
    {
        $value = strtolower(trim($address));

        if ($value === '' || strlen($value) > self::MAX_ADDRESS) {
            return null;
        }

        $at = strrpos($value, '@');

        if ($at === false || $at === 0 || $at === strlen($value) - 1) {
            return null;
        }

        $local = substr($value, 0, $at);
        $domain = substr($value, $at + 1);

        if (! self::isValidLocalPart($local) || ! self::isValidDomain($domain)) {
            return null;
        }

        return $local . '@' . $domain;
    }

    public static function isValid(string $address): bool
    {
        return self::normalize($address) !== null;
    }

    public static function compose(string $localPart, string $domain): ?string
    {
        return self::normalize($localPart . '@' . $domain);
    }

    public static function domainOf(string $address): ?string
    {
        $normalized = self::normalize($address);

        if ($normalized === null) {
            return null;
        }

        return substr($normalized, strrpos($normalized, '@') + 1);
    }

    public static function localPartOf(string $address): ?string
    {
        $normalized = self::normalize($address);

        if ($normalized === null) {
            return null;
        }

        return substr($normalized, 0, strrpos($normalized, '@'));
    }
}
