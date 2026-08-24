<?php

namespace App\Connectors\Infrastructure\BusinessEmail;

use InvalidArgumentException;

/**
 * INFRA888 · E2 — PROVIDER CAPABILITY FLAGS.
 *
 * WHY THIS EXISTS
 * The masterplan's multi-provider section states the contract must be the
 * intersection of what all providers can do, with flags for the rest. Catch-all
 * is the concrete example: some providers support it fully, some partially, some
 * not at all. Without flags there are only two bad options — restrict the whole
 * product to the weakest provider, or offer an action that fails at the provider
 * and looks like a platform defect to the customer.
 *
 * THE RULE THAT MAKES FLAGS WORTH HAVING
 * An unsupported capability is NOT a provider failure. It is a fact about the
 * configuration, known before any call is made. The engine therefore refuses it
 * BEFORE opening a governed operation, and classifies it as `permanent` rather
 * than retryable — retrying a capability the adapter does not implement can
 * never succeed.
 *
 * NAMES ARE PROVIDER-NEUTRAL AND MUST STAY SO. These describe what Business
 * Email can do, not what any vendor calls it. A flag named for a vendor feature
 * would put vendor vocabulary into the engine, the API and eventually the UI —
 * which is the leak the white-label guards exist to prevent.
 *
 * This class holds constants and validation only. It makes no call and knows
 * about no provider.
 */
final class EmailProviderCapability
{
    // ── domain ───────────────────────────────────────────────────────────────
    public const DOMAIN_ONBOARD = 'domain.onboard';
    public const DOMAIN_VERIFY = 'domain.verify';
    /** Rotating a signing key. Genuinely absent at some providers. */
    public const DOMAIN_DKIM_ROTATE = 'domain.dkim.rotate';

    // ── mailbox ──────────────────────────────────────────────────────────────
    public const MAILBOX_CREATE = 'mailbox.create';
    public const MAILBOX_UPDATE = 'mailbox.update';
    /** Changing the local part in place. The masterplan records this as varying. */
    public const MAILBOX_RENAME = 'mailbox.rename';
    public const MAILBOX_SUSPEND = 'mailbox.suspend';
    public const MAILBOX_RESTORE = 'mailbox.restore';
    public const MAILBOX_DELETE = 'mailbox.delete';
    public const MAILBOX_QUOTA = 'mailbox.quota';
    public const MAILBOX_PASSWORD_RESET = 'mailbox.password_reset';

    /**
     * INFRA888 · E7.3. The provider allows an administrator to SET a mailbox
     * password directly, without the provider contacting the mailbox owner.
     *
     * This is what makes white-label onboarding possible: LevelUp can relay a
     * password the customer chose on a LevelUp page. A provider without it
     * cannot be onboarded white-label, and the flag makes that visible before a
     * migration rather than during one.
     */
    public const MAILBOX_PASSWORD_SET = 'mailbox.password_set';

    // ── routing ──────────────────────────────────────────────────────────────
    public const ALIAS_CREATE = 'alias.create';
    public const ALIAS_DELETE = 'alias.delete';
    public const FORWARDER_CREATE = 'forwarder.create';
    public const FORWARDER_DELETE = 'forwarder.delete';
    public const CATCHALL_CONFIGURE = 'catchall.configure';
    public const CATCHALL_CLEAR = 'catchall.clear';

    // ── observation ──────────────────────────────────────────────────────────
    public const USAGE_SYNC = 'usage.sync';
    /** Not universally exposed, and useful enough to ask for explicitly. */
    public const LAST_LOGIN_READ = 'last_login.read';
    /** Enumerating what exists provider-side. Without it, reconciliation is impossible. */
    public const INVENTORY_LIST = 'inventory.list';

    /** @return array<int,string> */
    public static function all(): array
    {
        return [
            self::DOMAIN_ONBOARD,
            self::DOMAIN_VERIFY,
            self::DOMAIN_DKIM_ROTATE,
            self::MAILBOX_CREATE,
            self::MAILBOX_UPDATE,
            self::MAILBOX_RENAME,
            self::MAILBOX_SUSPEND,
            self::MAILBOX_RESTORE,
            self::MAILBOX_DELETE,
            self::MAILBOX_QUOTA,
            self::MAILBOX_PASSWORD_RESET,
            self::MAILBOX_PASSWORD_SET,
            self::ALIAS_CREATE,
            self::ALIAS_DELETE,
            self::FORWARDER_CREATE,
            self::FORWARDER_DELETE,
            self::CATCHALL_CONFIGURE,
            self::CATCHALL_CLEAR,
            self::USAGE_SYNC,
            self::LAST_LOGIN_READ,
            self::INVENTORY_LIST,
        ];
    }

    /**
     * The set a provider must implement to be usable as a Business Email
     * provider at all. Anything less cannot deliver the product: a provider that
     * cannot create or delete a mailbox is not a mail provider, and one that
     * cannot enumerate what exists cannot be reconciled — which means drift
     * would be undetectable.
     *
     * @return array<int,string>
     */
    public static function required(): array
    {
        return [
            self::DOMAIN_ONBOARD,
            self::DOMAIN_VERIFY,
            self::MAILBOX_CREATE,
            self::MAILBOX_DELETE,
            self::INVENTORY_LIST,
        ];
    }

    /**
     * Capabilities the product can sell without, hiding the action where absent.
     *
     * @return array<int,string>
     */
    public static function optional(): array
    {
        return array_values(array_diff(self::all(), self::required()));
    }

    public static function isKnown(string $capability): bool
    {
        return in_array($capability, self::all(), true);
    }

    /** @throws InvalidArgumentException on an undeclared capability name */
    public static function assertKnown(string $capability): void
    {
        if (! self::isKnown($capability)) {
            throw new InvalidArgumentException(
                "Unknown Business Email provider capability '{$capability}'. Capability names are a closed set; "
                . 'an unrecognised one is a typo or a vendor concept leaking into the contract.'
            );
        }
    }
}
