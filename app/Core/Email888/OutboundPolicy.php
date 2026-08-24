<?php

namespace App\Core\Email888;

use Symfony\Component\Mime\Email;

/**
 * EMAIL888 - resolves a declared PURPOSE into sender identity and stream.
 *
 * This is the only place that answers "who is this from and how does it
 * travel?". Call sites declare intent; they do not choose an address.
 *
 * Overrides are addressed by REGISTRY KEY, never by literal address or vendor
 * stream name, so even an overriding caller cannot introduce an unregistered
 * sender.
 */
final class OutboundPolicy
{
    public const HDR_PURPOSE     = 'X-LU-Purpose';
    public const HDR_WORKSPACE   = 'X-LU-Workspace';
    public const HDR_USER        = 'X-LU-User';
    public const HDR_CORRELATION = 'X-LU-Correlation';
    public const HDR_CLAIM       = 'X-LU-Claim';
    public const HDR_SENDER      = 'X-LU-Sender';
    public const HDR_REPLY_TO    = 'X-LU-Reply-To';
    public const HDR_STREAM      = 'X-LU-Stream';

    /** Every X-LU-* header is internal routing metadata and is stripped before send. */
    public const INTERNAL_HEADERS = [
        self::HDR_PURPOSE,
        self::HDR_WORKSPACE,
        self::HDR_USER,
        self::HDR_CORRELATION,
        self::HDR_CLAIM,
        self::HDR_SENDER,
        self::HDR_REPLY_TO,
        self::HDR_STREAM,
    ];

    public const UNCLASSIFIED = 'unclassified';

    public static function isKnownPurpose(string $purpose): bool
    {
        return isset(config('email888.purposes')[$purpose]);
    }

    /**
     * @return array{sender:array{address:string,name:string},reply_to:?array{address:string,name:string},stream_class:string,provider_stream:?string}|null
     */
    public static function resolve(
        string $purpose,
        ?string $senderKeyOverride = null,
        ?string $replyKeyOverride = null,
        ?string $streamClassOverride = null,
    ): ?array {
        $spec = config('email888.purposes')[$purpose] ?? null;
        if (! is_array($spec)) {
            return null;
        }

        $senders   = config('email888.senders', []);
        $senderKey = $senderKeyOverride ?? ($spec['sender'] ?? null);
        $sender    = $senders[$senderKey] ?? null;

        if (! is_array($sender) || ($sender['address'] ?? '') === '') {
            // A purpose pointing at a sender that does not exist is a
            // configuration error. Returning null makes the caller fall back to
            // current behaviour rather than sending from an empty address.
            return null;
        }

        $replyKey = $replyKeyOverride ?? ($spec['reply_to'] ?? null);
        $replyTo  = $replyKey ? ($senders[$replyKey] ?? null) : null;
        $class    = $streamClassOverride ?? (string) ($spec['stream'] ?? 'transactional');

        return [
            'sender'          => ['address' => (string) $sender['address'], 'name' => (string) ($sender['name'] ?? '')],
            'reply_to'        => $replyTo ? ['address' => (string) $replyTo['address'], 'name' => (string) ($replyTo['name'] ?? '')] : null,
            'stream_class'    => $class,
            'provider_stream' => config('email888.streams')[$class] ?? null,
        ];
    }

    /**
     * Read an internal header, then remove it so it never leaves the building.
     */
    public static function takeHeader(Email $message, string $name): ?string
    {
        $headers = $message->getHeaders();
        if (! $headers->has($name)) {
            return null;
        }

        $value = $headers->get($name)?->getBodyAsString();
        $headers->remove($name);

        return $value !== null && $value !== '' ? $value : null;
    }

    /**
     * EM-8 — the ONE place the outbound provider is named.
     *
     * The dispatcher, the ledger and the webhook recorder each used to write the
     * literal 'postmark'. None of them has any business knowing that: they
     * record WHICH provider handled a message, which is a fact about
     * configuration, not a fact about the canonical path.
     *
     * This is not yet multi-provider routing, and does not pretend to be —
     * choosing a provider per message is a policy decision nobody has asked for.
     * What it does buy is that swapping the platform's provider is a
     * configuration change plus an adapter, rather than a search-and-replace
     * through the core.
     */
    public static function provider(): string
    {
        $p = trim((string) config('email888.provider', ''));

        // A vendor default does NOT belong here — naming one in code is the
        // exact leak this method exists to remove; config/email888.php carries
        // it. A blank configuration records 'unconfigured', which is the honest
        // answer and is greppable, rather than a vendor name we merely assumed.
        return $p !== '' ? $p : 'unconfigured';
    }

    public static function stripInternalHeaders(Email $message): void
    {
        foreach (self::INTERNAL_HEADERS as $h) {
            $message->getHeaders()->remove($h);
        }
    }
}
