<?php

namespace App\Engines\Infrastructure\Email\Customer;

use App\Engines\Infrastructure\Email\Registry\BusinessEmailCapabilityRegistry as Registry;

/**
 * INFRA888 · E4 — what a workspace is entitled to, in LevelUp's terms.
 *
 * ─── THE DISTINCTION THIS CLASS EXISTS TO KEEP ──────────────────────────────
 *
 * A customer buys a LevelUp allocation. They do not buy a provider plan, and
 * the two must never be conflated — not in the API, not in the UI, and not in
 * the arithmetic. If the underlying provider allows 500 mailboxes and the
 * LevelUp product sells 10, the customer's limit is 10; if the provider later
 * allows 50, the customer's limit is still 10 until LevelUp says otherwise.
 * Reading a limit from provider capacity would make a customer's allowance
 * change when we switched vendor, which is precisely the coupling the whole
 * white-label design exists to prevent.
 *
 * ─── WHY THE DEFAULTS ARE CONFIGURABLE AND UNNAMED ──────────────────────────
 *
 * Commercial Business Email plans are not approved yet (masterplan §14 Q6 is
 * still open). Inventing public plan names here would put unapproved commercial
 * language into the product, and hard-coding one tenant's capacity into shared
 * code would make a customer-specific decision permanent. So the limits are
 * configuration with conservative defaults, they carry no plan name, and they
 * expose no price.
 */
final class CustomerEntitlements
{
    /** Keys read from the workspace's resolved plan features. */
    public const ACCESS = Registry::ENTITLEMENT_ACCESS;
    public const MAILBOX_LIMIT = Registry::ENTITLEMENT_MAILBOX_LIMIT;
    public const DOMAIN_LIMIT = Registry::ENTITLEMENT_DOMAIN_LIMIT;
    public const ALIAS_LIMIT = 'business_email_alias_limit';
    public const FORWARDER_LIMIT = 'business_email_forwarder_limit';
    public const STORAGE_MB = 'business_email_storage_mb';
    public const CATCHALL_ALLOWED = 'business_email_catchall_allowed';

    /**
     * Resolve the entitlement map for a workspace.
     *
     * E4 reads defaults from configuration. When commercial plans exist, this
     * is the ONE method that changes — every caller already goes through it, so
     * nothing else has to know where a limit came from.
     *
     * @return array<string,mixed>
     */
    public static function forWorkspace(int $workspaceId): array
    {
        $defaults = (array) config('business_email.customer_entitlements', []);

        return [
            self::ACCESS           => (bool) ($defaults['access'] ?? true),
            self::DOMAIN_LIMIT     => self::limitValue($defaults['domains'] ?? 1),
            self::MAILBOX_LIMIT    => self::limitValue($defaults['mailboxes'] ?? 10),
            self::ALIAS_LIMIT      => self::limitValue($defaults['aliases'] ?? 25),
            self::FORWARDER_LIMIT  => self::limitValue($defaults['forwarders'] ?? 25),
            self::STORAGE_MB       => self::limitValue($defaults['storage_mb'] ?? 51200),
            self::CATCHALL_ALLOWED => (bool) ($defaults['catchall'] ?? false),
        ];
    }

    /**
     * `true` means granted with no ceiling; an integer is a ceiling.
     *
     * The E1 context reads null as NOT GRANTED and `true` as granted-unlimited,
     * and getting that backwards refused every mailbox create in E3. The
     * conversion lives here so no caller has to remember it.
     */
    private static function limitValue(mixed $configured): int|bool
    {
        if ($configured === null || $configured === '' || $configured === 'unlimited') {
            return true;
        }

        return max(0, (int) $configured);
    }

    /**
     * The customer-facing view of an allowance: used, limit, remaining.
     *
     * `limit: null` means no ceiling. Never a provider figure, never a price,
     * never a plan name.
     *
     * @return array{used:int,limit:?int,remaining:?int,at_limit:bool,percent:?float}
     */
    public static function allowance(int $used, int|bool|null $limit): array
    {
        if ($limit === true || $limit === null) {
            return ['used' => $used, 'limit' => null, 'remaining' => null, 'at_limit' => false, 'percent' => null];
        }

        $limit = (int) $limit;
        $remaining = max(0, $limit - $used);

        return [
            'used'      => $used,
            'limit'     => $limit,
            'remaining' => $remaining,
            'at_limit'  => $used >= $limit,
            'percent'   => $limit > 0 ? round(($used / $limit) * 100, 1) : null,
        ];
    }

    /** The threshold at which a customer is warned they are running out. */
    public const WARN_AT_PERCENT = 80.0;

    public static function isApproaching(array $allowance): bool
    {
        return $allowance['percent'] !== null
            && $allowance['percent'] >= self::WARN_AT_PERCENT
            && ! $allowance['at_limit'];
    }
}
