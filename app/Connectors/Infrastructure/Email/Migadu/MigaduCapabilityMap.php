<?php

namespace App\Connectors\Infrastructure\Email\Migadu;

use App\Connectors\Infrastructure\BusinessEmail\EmailProviderCapability as Cap;
use App\Connectors\Infrastructure\BusinessEmail\EmailProviderCapabilitySet;

/**
 * INFRA888 · E5 — what this provider can actually do, measured rather than assumed.
 *
 * The masterplan's multi-provider table said "Catch-all: yes, Rename: varies" and
 * flagged itself as an unverified placeholder. This class replaces it with the
 * official API documentation, endpoint by endpoint, and records the evidence
 * beside each verdict so a future reader can re-check it rather than trust it.
 *
 * FIVE OF TWENTY CAPABILITIES ARE UNSUPPORTED. That is not a defect to engineer
 * around. `supports()` exists precisely so the product can hide an action this
 * provider cannot perform, instead of offering one that fails — and so that
 * swapping providers changes what customers can do, not whether the code works.
 *
 * VERIFIED 2026-08-05 against https://www.migadu.com/api/ and the published
 * pricing page. The API documents itself as "still in early beta and not all
 * functionalities are exposed", so this map is a snapshot with a date on it, not
 * a permanent truth. Re-verify before E6 provisions anything.
 */
final class MigaduCapabilityMap
{
    /** The date the official documentation was read. Reported to operators. */
    public const VERIFIED_ON = '2026-08-05';

    /**
     * The date this matrix was checked against the LIVE API, which is a much
     * stronger claim than reading the documentation — and which corrected five
     * of these verdicts. Where the two disagree, the live API wins.
     */
    public const LIVE_VERIFIED_ON = '2026-08-06';

    /**
     * Response envelope keys as the live API returns them, recorded because two
     * of them are not what the documentation implies.
     */
    public const LIVE_ENVELOPES = [
        'domains'     => 'domains',
        'mailboxes'   => 'mailboxes',
        'aliases'     => 'address_aliases',
        'rewrites'    => 'rewrites',
        'identities'  => 'identities',
        'forwardings' => 'forwardings',
    ];

    public const API_BASE_URL = 'https://api.migadu.com/v1/';

    /**
     * Capability => [supported, api, limitation, evidence].
     *
     * `api` is the documented endpoint that implements it, or the reason none does.
     */
    private const MATRIX = [
        // ── domain ──────────────────────────────────────────────────────────
        Cap::DOMAIN_ONBOARD => [
            true, 'POST /domains', '',
            'Documented under Domains. Domain DELETE is deliberately not exposed by the provider, '
            . 'so offboarding is a manual operator action — see NOT_REVERSIBLE below.',
        ],
        Cap::DOMAIN_VERIFY => [
            true, 'GET /domains/{domain}/diagnostics', 'Activation is a separate call.',
            'Diagnostics reports DNS check state; GET /domains/{domain}/activate performs activation. '
            . 'A 422 response is documented as "DNS checks or validation failed", which is the '
            . 'awaiting-DNS case rather than an error.',
        ],
        Cap::DOMAIN_DKIM_ROTATE => [
            false, '', 'No documented endpoint.',
            'The Domains section documents list/get/create/update/records/diagnostics/activate/usage. '
            . 'No key-rotation operation is published.',
        ],

        // ── mailbox ─────────────────────────────────────────────────────────
        Cap::MAILBOX_CREATE => [
            true, 'POST /domains/{domain}/mailboxes', 'REQUIRES AN INITIAL PASSWORD — BLOCKING.',
            'The provider supports creation — proven live 2026-08-06, HTTP 200. What it will not do is '
            . 'create a mailbox without a password: every payload of local_part and name alone returns '
            . '{"error":"bad request"}, and the identical payload plus a password returns 200. The '
            . 'contract\'s MailboxSpec carries no password, and E4 forbids this platform generating or '
            . 'transmitting a mailbox secret. So the capability exists at the provider and cannot '
            . 'currently be exercised through our contract. This is a PRODUCT DECISION, not a defect to '
            . 'code around, and it is escalated rather than silently resolved.',
        ],
        Cap::MAILBOX_UPDATE => [
            true, 'PUT /domains/{domain}/mailboxes/{local_part}', 'Display name and access flags only.',
            'Mailbox object documents name, may_send, may_receive, may_access_imap, may_access_pop3, '
            . 'may_access_managesieve, spam and footer fields.',
        ],
        Cap::MAILBOX_RENAME => [
            false, '', 'local_part is the resource identifier; no rename operation exists.',
            'Mailbox endpoints are keyed by {local_part}. No documented rename or move operation. '
            . 'Renaming would mean create-plus-delete, which loses stored mail — the adapter will '
            . 'not do that silently.',
        ],
        Cap::MAILBOX_SUSPEND => [
            true, 'PUT /domains/{domain}/mailboxes/{local_part}', 'Write semantics unproven.',
            'CORRECTED E6 AGAINST THE LIVE API. The published schema showed no is_active field and '
            . 'E5 therefore emulated suspension by clearing five access flags. The live mailbox object '
            . 'DOES return is_active, so suspension is native. Reading it is proven; WRITING it is not '
            . "— E6's single authorised mutation was a create/verify/delete, not a suspend.",
        ],
        Cap::MAILBOX_RESTORE => [
            true, 'PUT /domains/{domain}/mailboxes/{local_part}', 'No longer lossy. Write semantics unproven.',
            'Follows from the correction above: because suspension is one field rather than five, '
            . 'restore cannot disturb anything else. The E5 warning that a POP3-disabled mailbox would '
            . 'come back POP3-enabled no longer applies.',
        ],
        Cap::MAILBOX_DELETE => [
            true, 'DELETE /domains/{domain}/mailboxes/{local_part}', 'Destructive and irreversible.',
            'Documented. The engine already requires explicit confirmation and, for customers, '
            . 'separation of duties.',
        ],
        Cap::MAILBOX_QUOTA => [
            false, '', 'No per-mailbox storage LIMIT. Per-mailbox message limits do exist.',
            'Still unsupported, but for a narrower reason than E5 recorded. The live mailbox object '
            . 'has no storage limit field, so a storage quota cannot be set per mailbox. It DOES carry '
            . 'daily/weekly/monthly incoming and outgoing message limits, which are a different control '
            . 'and are not what this capability means. Storage USAGE is separately available — see usage.sync.',
        ],
        Cap::MAILBOX_PASSWORD_SET => [
            true, 'PUT /domains/{domain}/mailboxes/{local_part}', 'Admin authority; no old password needed.',
            'Proven live 2026-08-07: PUT with a password returns 200, requires no current password, and '
            . 'sends no notification — the provider holds no recovery address for a password-created '
            . 'mailbox and exposes no notification setting. This is the capability that makes white-label '
            . 'onboarding possible.',
        ],
        Cap::MAILBOX_PASSWORD_RESET => [
            false, '', 'No reset operation; only direct password assignment.',
            'There is no documented endpoint that sends a reset to the mailbox owner. The mailbox '
            . 'object accepts a write-only password field, which would require this platform to '
            . 'generate and transmit a secret — expressly forbidden by E4. Marked unsupported rather '
            . 'than implemented as a secret-handling flow.',
        ],

        // ── routing ─────────────────────────────────────────────────────────
        Cap::ALIAS_CREATE => [
            true, 'POST /domains/{domain}/aliases', 'SAME-DOMAIN DESTINATIONS ONLY.',
            'Official note: "aliases can redirect only on the same domain". An alias to an external '
            . 'address is refused by the adapter rather than silently converted into a forwarder.',
        ],
        Cap::ALIAS_DELETE => [
            true, 'DELETE /domains/{domain}/aliases/{local_part}', '', 'Documented.',
        ],
        Cap::FORWARDER_CREATE => [
            true, 'POST /domains/{domain}/mailboxes/{mailbox}/forwardings',
            'REQUIRES AN EXISTING PARENT MAILBOX, and the destination must confirm.',
            'Forwardings are nested under a mailbox, not under a domain. The contract is '
            . 'domain-scoped, so the adapter maps sourceLocalPart to a parent mailbox and refuses '
            . 'when none exists — it will not create a mailbox the caller did not ask for. The '
            . 'response carries confirmation_sent_at / confirmed_at, so a new forwarder is ACCEPTED, '
            . 'never verified, until the destination confirms.',
        ],
        Cap::FORWARDER_DELETE => [
            true, 'DELETE /domains/{domain}/mailboxes/{mailbox}/forwardings/{address}', '',
            'Documented.',
        ],
        Cap::CATCHALL_CONFIGURE => [
            true, 'POST /domains/{domain}/rewrites', 'Implemented as a pattern rewrite.',
            'Rewrites are documented as "aliases that listen on predefined patterns", with '
            . 'local_part_rule such as "demo-*". A catch-all is the rule "*".',
        ],
        Cap::CATCHALL_CLEAR => [
            true, 'DELETE /domains/{domain}/rewrites/{name}', '',
            'Deleting the catch-all rewrite restores the default of rejecting unknown recipients.',
        ],

        // ── observation ─────────────────────────────────────────────────────
        Cap::USAGE_SYNC => [
            true, 'GET /domains/{domain}/usage + /mailboxes', 'PER-MAILBOX STORAGE AVAILABLE. Unit unverified.',
            'CORRECTED E6. E5 reported per-mailbox storage as permanently unavailable because the '
            . 'published mailbox schema had no storage field. The live object returns storage_usage '
            . 'per mailbox, so it is now read. Its UNIT is undocumented and both observed mailboxes '
            . 'read 0.0, so bytes-versus-megabytes could not be settled by observation and the '
            . 'conservative conversion is applied. Re-check against a mailbox holding real mail.',
        ],
        Cap::LAST_LOGIN_READ => [
            false, '', 'Not exposed.',
            'No last-login or session field on the mailbox object.',
        ],
        Cap::INVENTORY_LIST => [
            true, 'GET /domains/{domain}/mailboxes, /aliases, /rewrites', 'Forwardings arrive embedded.',
            'CORRECTED E6. E5 expected forwardings only as a nested per-mailbox endpoint and paid an '
            . 'N+1 fan-out for them, bounded at 50 with a partial-inventory fallback. The live mailbox '
            . 'row EMBEDS its forwardings, so a full inventory is three calls regardless of mailbox '
            . 'count and the truncation risk is gone. Envelope keys are mailboxes, address_aliases '
            . '(not "aliases") and rewrites.',
        ],
    ];

    /**
     * Operations this provider deliberately does not expose, recorded so nobody
     * builds a product promise on top of them.
     */
    public const NOT_REVERSIBLE = [
        'domain.delete' => 'The provider does not expose domain deletion via API because the action '
            . 'is irreversible. Offboarding a domain is a manual operator action in the provider '
            . 'console and must be planned into any customer offboarding flow.',
    ];

    public static function capabilitySet(): EmailProviderCapabilitySet
    {
        return new EmailProviderCapabilitySet(self::supported());
    }

    /** @return array<int,string> */
    public static function supported(): array
    {
        return array_keys(array_filter(self::MATRIX, fn (array $row) => $row[0] === true));
    }

    /** @return array<int,string> */
    public static function unsupported(): array
    {
        return array_keys(array_filter(self::MATRIX, fn (array $row) => $row[0] === false));
    }

    public static function supports(string $capability): bool
    {
        Cap::assertKnown($capability);

        return (self::MATRIX[$capability][0] ?? false) === true;
    }

    public static function apiFor(string $capability): string
    {
        return self::MATRIX[$capability][1] ?? '';
    }

    public static function limitationFor(string $capability): string
    {
        return self::MATRIX[$capability][2] ?? '';
    }

    public static function evidenceFor(string $capability): string
    {
        return self::MATRIX[$capability][3] ?? '';
    }

    /**
     * The full matrix, for operator diagnostics and the E5 report.
     *
     * ADMIN-ONLY. It names provider endpoints and limits, so it must never reach
     * a customer surface.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function toAdminArray(): array
    {
        $rows = [];

        foreach (self::MATRIX as $capability => [$supported, $api, $limitation, $evidence]) {
            $rows[] = [
                'capability'  => $capability,
                'supported'   => $supported,
                'required'    => in_array($capability, Cap::required(), true),
                'api'         => $api,
                'limitation'  => $limitation,
                'evidence'         => $evidence,
                'verified_on'      => self::VERIFIED_ON,
                'live_verified_on' => self::LIVE_VERIFIED_ON,
            ];
        }

        return $rows;
    }
}
