<?php

namespace App\Engines\Infrastructure\Email\Admin;

use Illuminate\Http\Request;

/**
 * INFRA888 · E3 — who may do what on the Business Email admin surface.
 *
 * Routed to from `AdminAccess` by the `business_email.` capability prefix, the
 * same way Engineer888 is. It owns no floor of its own: platform-admin status
 * is checked by AdminAccess before this class is consulted, and by the
 * `admin` middleware before any route runs. What this adds is GRANULARITY —
 * the distinction between an operator who may look, one who may act, and one
 * who may destroy or touch credentials.
 *
 * ─── THE SHAPE OF THE PERMISSION SET ────────────────────────────────────────
 *
 *   read           see everything about Business Email
 *   operate        ordinary provisioning: create a mailbox, an alias, a
 *                  forwarder, sync usage, observe health
 *   manual_review  resolve an ambiguous or failed operation
 *   provider.assign      bind a workspace or domain to a provider connection
 *   provider.credentials rotate or replace a provider secret
 *   mailbox.destroy      delete a mailbox — destroys customer mail
 *   domain.destroy       terminate a domain — stops all mail for it
 *
 * ─── WHY THE LAST THREE ARE AN EXPLICIT ALLOWLIST ───────────────────────────
 *
 * The first four are granted to any platform admin, which matches how all 54
 * pre-existing admin pages behave and does not invent a new authority
 * vocabulary. The last three are NOT: they are granted only to user ids named
 * in `config('business_email.elevated_admin_user_ids')`, which defaults to an
 * empty list.
 *
 * An empty list means nobody. Deleting a mailbox and rotating a provider
 * credential are therefore refused for EVERY operator on every install today,
 * until an owner deliberately names someone. That is the fail-closed reading,
 * and it is the one that matters: the platform's own audit found 48 of 64
 * mutating admin routes with no additional control at all, and the cost of
 * that class of gap is measured in customer data.
 *
 * This is deliberately NOT a parallel RBAC system. The platform rejected adding
 * a fifth authority vocabulary; `PermissionRegistry` is its answer, and
 * registering Business Email there is an E4 entry condition. Until then an
 * allowlist that cannot accidentally grant is the honest interim.
 */
final class BusinessEmailAdminAccess
{
    public const PREFIX = 'business_email';

    public const READ = 'business_email.read';
    public const OPERATE = 'business_email.operate';
    public const MANUAL_REVIEW = 'business_email.manual_review';
    public const PROVIDER_ASSIGN = 'business_email.provider.assign';
    public const PROVIDER_CREDENTIALS = 'business_email.provider.credentials';
    public const MAILBOX_DESTROY = 'business_email.mailbox.destroy';
    public const DOMAIN_DESTROY = 'business_email.domain.destroy';

    /** @return array<int,string> */
    public static function all(): array
    {
        return [
            self::READ,
            self::OPERATE,
            self::MANUAL_REVIEW,
            self::PROVIDER_ASSIGN,
            self::PROVIDER_CREDENTIALS,
            self::MAILBOX_DESTROY,
            self::DOMAIN_DESTROY,
        ];
    }

    /**
     * Capabilities any platform admin holds.
     *
     * @return array<int,string>
     */
    public static function standard(): array
    {
        return [self::READ, self::OPERATE, self::MANUAL_REVIEW, self::PROVIDER_ASSIGN];
    }

    /**
     * Capabilities that require an explicitly named operator.
     *
     * @return array<int,string>
     */
    public static function elevated(): array
    {
        return [self::PROVIDER_CREDENTIALS, self::MAILBOX_DESTROY, self::DOMAIN_DESTROY];
    }

    public static function isElevated(string $capability): bool
    {
        return in_array($capability, self::elevated(), true);
    }

    public static function isKnown(string $capability): bool
    {
        return in_array($capability, self::all(), true);
    }

    /**
     * May this request use this capability?
     *
     * Called by AdminAccess, which has already established platform-admin
     * status. An unknown capability is refused — a module naming a policy
     * nobody implements has a bug, and failing open would hide it behind a
     * working screen.
     */
    public function allows(?Request $request, string $capability): bool
    {
        // The whole area is inert while the gate is closed, including its
        // permissions. There is no capability to hold on a surface that is off.
        if (! BusinessEmailAdminGate::isEnabled()) {
            return false;
        }

        if (! self::isKnown($capability)) {
            return false;
        }

        if (! self::isElevated($capability)) {
            return true;
        }

        $userId = (int) ($request?->user()?->id ?? 0);

        if ($userId <= 0) {
            return false;
        }

        return in_array($userId, self::elevatedUserIds(), true);
    }

    /** @return array<int,int> */
    public static function elevatedUserIds(): array
    {
        return array_map('intval', (array) config('business_email.elevated_admin_user_ids', []));
    }

    /**
     * The capability a governed Business Email action requires.
     *
     * Destructive actions map to the narrow capabilities; everything else to
     * `operate`. Stated as a map rather than derived from the capability slug,
     * because a rule computed from a string is a rule that changes silently
     * when someone renames a slug.
     *
     * @return string the admin capability required to run this engine capability
     */
    public static function requiredFor(string $engineCapability): string
    {
        return match ($engineCapability) {
            'email.mailbox.delete' => self::MAILBOX_DESTROY,
            'email.domain.terminate' => self::DOMAIN_DESTROY,
            default => self::OPERATE,
        };
    }
}
