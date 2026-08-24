<?php

namespace App\Connectors\Infrastructure\BusinessEmail\Values;

use App\Engines\Infrastructure\Email\Support\EmailAddress;
use InvalidArgumentException;

/**
 * INFRA888 · E2 — a provider-neutral mailbox request.
 *
 * NOTHING IN THIS OBJECT IS A CREDENTIAL, and there is no field where one could
 * be put. Providers differ on whether a password can be set through their API;
 * the ones that allow it invite exactly the pattern this contract refuses —
 * a plaintext secret travelling through an engine, an operation record and a
 * log. Initial credentials are the provider's business, delivered to the
 * customer through the provider's own flow, and `email.password.reset` requests
 * a reset rather than returning a password.
 *
 * NULL MEANS "DO NOT CHANGE" on update, not "clear". A spec that could not
 * express "leave the display name alone" would force every quota change to
 * resend a display name, and a caller that forgot would silently blank it.
 * Clearing is done by passing an empty string, which is a different value from
 * null and is treated as such.
 */
final class MailboxSpec
{
    /**
     * @param string      $localPart   the part before the @; required on create
     * @param string|null $displayName null = leave unchanged; '' = clear
     * @param int|null    $quotaMb     null = leave unchanged
     */
    /**
     * @param string|null $invitationEmail where the PROVIDER sends its own
     *        invitation so the mailbox owner can set their own password
     */
    public function __construct(
        public readonly string $localPart,
        public readonly ?string $displayName = null,
        public readonly ?int $quotaMb = null,
        public readonly ?string $invitationEmail = null,
    ) {
        if (! EmailAddress::isValidLocalPart($localPart)) {
            throw new InvalidArgumentException(
                "MailboxSpec: '{$localPart}' is not a local part every provider will accept."
            );
        }

        // An invitation goes to a real person. A malformed address means the
        // mailbox is created and nobody is ever told, which looks like a
        // working mailbox and is not one.
        if ($invitationEmail !== null && ! EmailAddress::isValid($invitationEmail)) {
            throw new InvalidArgumentException(
                'MailboxSpec: the invitation address must be a valid email address.'
            );
        }

        if ($quotaMb !== null && $quotaMb <= 0) {
            throw new InvalidArgumentException(
                'MailboxSpec: a quota must be positive. Zero would be a mailbox that cannot receive mail, '
                . 'which is a suspension, not a quota.'
            );
        }
    }

    /**
     * INFRA888 · E7. Still true that nothing here is a credential — an address
     * to invite is not a secret, and the provider mints and delivers the
     * password itself. LevelUp Growth never sees one.
     */
    public function usesInvitation(): bool
    {
        return $this->invitationEmail !== null;
    }

    public function changesDisplayName(): bool
    {
        return $this->displayName !== null;
    }

    public function changesQuota(): bool
    {
        return $this->quotaMb !== null;
    }

    /** True when an update spec asks for nothing. Callers should refuse these. */
    public function isEmptyUpdate(): bool
    {
        return ! $this->changesDisplayName() && ! $this->changesQuota();
    }

    /** Safe for an operation record. Contains no secret because none exists. */
    public function toArray(): array
    {
        return [
            'local_part'       => $this->localPart,
            'display_name'     => $this->displayName,
            'quota_mb'         => $this->quotaMb,
            'invitation_email' => $this->invitationEmail,
        ];
    }

    /**
     * For an operator log. Deliberately omits the display name, which is
     * personal data the customer did not ask to have copied into an audit trail.
     */
    public function toAuditArray(): array
    {
        return [
            'local_part'          => $this->localPart,
            'quota_mb'            => $this->quotaMb,
            'display_name_set'    => $this->changesDisplayName(),
            // Whether an invitation was sent, not to whom. The recipient is
            // personal data the customer did not ask to have copied into an
            // audit trail — the same reason the display name is omitted.
            'invitation_sent'     => $this->usesInvitation(),
        ];
    }
}
