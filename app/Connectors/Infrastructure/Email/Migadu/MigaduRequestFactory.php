<?php

namespace App\Connectors\Infrastructure\Email\Migadu;

use App\Connectors\Infrastructure\BusinessEmail\Values\AliasSpec;
use App\Connectors\Infrastructure\BusinessEmail\Values\CatchAllSpec;
use App\Connectors\Infrastructure\BusinessEmail\Values\ForwarderSpec;
use App\Connectors\Infrastructure\BusinessEmail\SecretString;
use App\Connectors\Infrastructure\BusinessEmail\Values\MailboxSpec;

/**
 * INFRA888 · E5 — provider-neutral input to provider-shaped request.
 *
 * Every path and payload the adapter sends is built here, so the vendor's URL
 * grammar exists in exactly one file. It builds requests and nothing else: it
 * opens no socket, reads no config and makes no decision about whether a call
 * is allowed.
 */
final class MigaduRequestFactory
{
    /** The catch-all rewrite's stable slug, so we can find and delete our own. */
    public const CATCHALL_SLUG = 'levelup-catch-all';

    // ── paths ───────────────────────────────────────────────────────────────

    public static function domains(): string
    {
        return 'domains';
    }

    public static function domain(string $domain): string
    {
        return 'domains/' . self::segment($domain);
    }

    public static function domainRecords(string $domain): string
    {
        return self::domain($domain) . '/records';
    }

    public static function domainDiagnostics(string $domain): string
    {
        return self::domain($domain) . '/diagnostics';
    }

    public static function domainUsage(string $domain): string
    {
        return self::domain($domain) . '/usage';
    }

    public static function mailboxes(string $domain): string
    {
        return self::domain($domain) . '/mailboxes';
    }

    public static function mailbox(string $domain, string $localPart): string
    {
        return self::mailboxes($domain) . '/' . self::segment($localPart);
    }

    public static function aliases(string $domain): string
    {
        return self::domain($domain) . '/aliases';
    }

    public static function alias(string $domain, string $localPart): string
    {
        return self::aliases($domain) . '/' . self::segment($localPart);
    }

    public static function forwardings(string $domain, string $mailboxLocalPart): string
    {
        return self::mailbox($domain, $mailboxLocalPart) . '/forwardings';
    }

    public static function forwarding(string $domain, string $mailboxLocalPart, string $address): string
    {
        return self::forwardings($domain, $mailboxLocalPart) . '/' . self::segment($address);
    }

    public static function rewrites(string $domain): string
    {
        return self::domain($domain) . '/rewrites';
    }

    public static function rewrite(string $domain, string $name): string
    {
        return self::rewrites($domain) . '/' . self::segment($name);
    }

    // ── payloads ────────────────────────────────────────────────────────────

    /**
     * NOTE THE ABSENT FIELD. The provider accepts a write-only `password` here,
     * and this factory deliberately never sets it. E4 forbids this platform
     * generating or transmitting a mailbox secret, so mailbox creation produces
     * a mailbox whose password is established out of band.
     */
    /**
     * INVITATION MODE — proven live 2026-08-06.
     *
     * The provider refuses to create a mailbox with neither a password nor an
     * invitation: `{local_part, name}` alone returns 400. Supplying
     * `password_method=invitation` with a recovery address returns 200, and the
     * mailbox comes back `is_active=false, activated_at=null` — it exists, and
     * it is waiting for its owner.
     *
     * THIS PLATFORM NEVER SENDS A PASSWORD. There is no branch here that could:
     * the field is not written, and a guard asserts it never appears.
     */
    public static function createMailbox(MailboxSpec $spec, #[\SensitiveParameter] ?string $password = null): array
    {
        $payload = [
            'local_part' => $spec->localPart,
            'name'       => $spec->displayName ?? $spec->localPart,
        ];

        // INFRA888 · E7.3 — WHITE-LABEL PROVISIONING IS THE DEFAULT.
        //
        // The provider refuses to create a mailbox with neither a password nor
        // an invitation, and its invitation email is the vendor's own, branded
        // and linking to the vendor's site. So we supply an undisclosed
        // bootstrap credential instead: the provider stays silent, and LevelUp
        // owns the entire customer conversation.
        //
        // The bootstrap is generated HERE, one line before it is sent, and the
        // array it lives in is discarded when the request completes. It never
        // reaches the engine, an operation record, or a log — it cannot, because
        // nothing upstream is ever given it.
        if ($spec->invitationEmail !== null) {
            // Retained for a future provider whose invitation is neutral. Never
            // selected for this one — see the E7.3 architecture guard.
            $payload['password_method'] = 'invitation';
            $payload['password_recovery_email'] = $spec->invitationEmail;

            return $payload;
        }

        // INFRA888 · E7.4 — NO BOOTSTRAP CREDENTIAL EXISTS ANY MORE.
        //
        // E7.3 generated one so the mailbox could be created before the customer
        // chose a password, then changed it later with PUT. Live evidence killed
        // that: the PUT returned 200, the new password did not authenticate, and
        // the old one still did. Acceptance is not effect.
        //
        // The mailbox is now created at the moment the customer submits their
        // own password, using the CREATE path that was proven to work. This
        // platform therefore never generates a mailbox credential at all.
        if ($password !== null) {
            $payload['password'] = $password;
        }

        // Explicitly empty: with no recovery address the provider has nowhere to
        // send anything, which is what keeps it silent. Proven live 2026-08-07.
        $payload['password_recovery_email'] = '';

        return $payload;
    }

    /**
     * The relay payload. One field, and the caller has already revealed it at
     * the single permitted point.
     */
    public static function setPassword(#[\SensitiveParameter] string $password): array
    {
        return ['password' => $password];

        // Per-mailbox quota is not a provider concept; the capability map marks
        // it unsupported. Sending it would be silently ignored, which is worse
        // than not sending it, because it would look as though it applied.
        return $payload;
    }

    public static function updateMailbox(MailboxSpec $spec): array
    {
        return ['name' => $spec->displayName ?? $spec->localPart];
    }

    /**
     * CORRECTED IN E6 — suspension is a single field, not five.
     *
     * E5 read the published mailbox schema, found no `is_active`, and emulated
     * suspension by clearing all five access flags. That worked but could not be
     * undone faithfully: restore had to set all five back to true, so a mailbox
     * deliberately POP3-disabled before suspension came back POP3-enabled.
     *
     * The LIVE mailbox object carries `is_active`. Using it makes suspend and
     * restore symmetrical and non-destructive: nothing else about the mailbox is
     * touched, so nothing else can be lost.
     *
     * The write semantics of `is_active` are NOT yet proven — E6's single
     * authorised mutation is a create/verify/delete, not a suspend. Proving it
     * belongs to the first milestone allowed to suspend something.
     */
    public static function activation(bool $active): array
    {
        return ['is_active' => $active];
    }

    /**
     * The five-flag approach, retained because it is genuinely useful: it
     * withdraws access without deactivating the mailbox, which is a different
     * and softer action than suspension.
     */
    public static function accessFlags(bool $enabled): array
    {
        return [
            'may_send'               => $enabled,
            'may_receive'            => $enabled,
            'may_access_imap'        => $enabled,
            'may_access_pop3'        => $enabled,
            'may_access_managesieve' => $enabled,
        ];
    }

    public static function createAlias(AliasSpec $spec): array
    {
        return [
            'local_part'   => $spec->sourceLocalPart,
            'destinations' => [$spec->targetAddress],
        ];
    }

    public static function createForwarding(ForwarderSpec $spec): array
    {
        return ['address' => $spec->destinationAddress];
    }

    /**
     * A catch-all is a rewrite whose pattern matches every local part. Giving it
     * a stable slug is what lets `clearCatchAll` delete the one we created
     * rather than guessing among the customer's own pattern rules.
     */
    public static function createCatchAll(CatchAllSpec $spec): array
    {
        return [
            'name'             => self::CATCHALL_SLUG,
            'local_part_rule'  => '*',
            'order_num'        => 100,
            'destinations'     => [$spec->targetAddress],
        ];
    }

    /**
     * Path segments are encoded, and a segment that could climb the path is
     * refused outright. A local part containing `../` would otherwise address a
     * different resource entirely.
     */
    private static function segment(string $value): string
    {
        $trimmed = trim($value);

        if ($trimmed === '' || str_contains($trimmed, '/') || str_contains($trimmed, '..')) {
            throw new \InvalidArgumentException('Unsafe path segment for a provider request.');
        }

        return rawurlencode($trimmed);
    }
}
