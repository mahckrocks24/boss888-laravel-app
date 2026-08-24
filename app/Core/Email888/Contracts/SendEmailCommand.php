<?php

namespace App\Core\Email888\Contracts;

use App\Core\Email888\OutboundPolicy;
use InvalidArgumentException;

/**
 * EMAIL888 - the canonical outbound email command. FROZEN CONTRACT.
 *
 * Every producer on the platform - Builder, CRM, Bookings, Billing,
 * Notifications, Marketing, Business Email, Hosting, Domains, Sarah,
 * Engineer888 - will eventually construct one of these instead of reaching for
 * Mail:: or an HTTP client. Nothing provider-specific may appear on it: there is
 * no "message stream" field, no server token, no Postmark anything. A caller
 * declares INTENT (purpose) and the policy layer decides identity and routing.
 *
 * sender_identity and stream_class exist as OVERRIDES, not as the normal path.
 * They are named by registry key, never by literal address or vendor stream, so
 * even an override cannot smuggle a raw sender into the platform.
 *
 * IMMUTABLE. A command that has been validated cannot be edited afterwards, so
 * what the ledger records is what was actually asked for.
 */
final class SendEmailCommand
{
    /**
     * @param string                      $purpose        registered purpose key; decides sender + stream
     * @param string[]                    $recipients     at least one valid address
     * @param string[]                    $cc
     * @param string[]                    $bcc
     * @param string|null                 $senderIdentity registry key override, NOT an address
     * @param string|null                 $streamClass    'transactional'|'broadcast' override
     * @param string|null                 $replyTo        registry key override, NOT an address
     * @param string|null                 $template       blade view name; mutually exclusive with html/text
     * @param array<string,mixed>         $templateData
     * @param list<array<string,mixed>>   $attachments    ['path'|'data', 'name', 'mime']
     * @param array<string,scalar|null>   $metadata       ledger-safe only; never secrets
     */
    public function __construct(
        public readonly string $purpose,
        public readonly array $recipients,
        public readonly ?string $subject = null,
        public readonly ?string $template = null,
        public readonly array $templateData = [],
        public readonly ?string $html = null,
        public readonly ?string $text = null,
        public readonly array $cc = [],
        public readonly array $bcc = [],
        public readonly ?string $senderIdentity = null,
        public readonly ?string $streamClass = null,
        public readonly ?string $replyTo = null,
        public readonly array $attachments = [],
        public readonly ?string $correlationId = null,
        public readonly ?int $workspaceId = null,
        public readonly ?int $actorId = null,
        public readonly array $metadata = [],
        public readonly ?string $idempotencyKey = null,
    ) {
        $this->assertValid();
    }

    private function assertValid(): void
    {
        if (! OutboundPolicy::isKnownPurpose($this->purpose)) {
            throw new InvalidArgumentException(
                "SendEmailCommand: unknown purpose '{$this->purpose}'. "
                . 'Register it in config/email888.php rather than sending unclassified mail.'
            );
        }

        if ($this->recipients === []) {
            throw new InvalidArgumentException('SendEmailCommand: at least one recipient is required.');
        }

        foreach (['recipients' => $this->recipients, 'cc' => $this->cc, 'bcc' => $this->bcc] as $field => $list) {
            foreach ($list as $address) {
                if (! is_string($address) || ! filter_var($address, FILTER_VALIDATE_EMAIL)) {
                    throw new InvalidArgumentException(
                        "SendEmailCommand: {$field} contains an invalid address."
                    );
                }
            }
        }

        $hasBody = $this->template !== null || $this->html !== null || $this->text !== null;
        if (! $hasBody) {
            throw new InvalidArgumentException('SendEmailCommand: one of template, html or text is required.');
        }

        // template + text IS legal: the template renders the HTML part and
        // $text becomes the plain-text alternative. Forbidding that combination
        // is what left every templated message HTML-only, which filters penalise
        // and a text-mode client cannot read at all.
        if ($this->template !== null && $this->html !== null) {
            throw new InvalidArgumentException(
                'SendEmailCommand: template and html are mutually exclusive - two sources for one part.'
            );
        }

        if ($this->streamClass !== null && ! in_array($this->streamClass, ['transactional', 'broadcast'], true)) {
            throw new InvalidArgumentException(
                "SendEmailCommand: stream_class must be 'transactional' or 'broadcast'."
            );
        }

        // An override names a REGISTRY KEY. Rejecting anything that looks like an
        // address is what stops a caller passing a raw sender and bypassing the
        // registry the whole design depends on.
        foreach (['sender_identity' => $this->senderIdentity, 'reply_to' => $this->replyTo] as $field => $key) {
            if ($key === null) {
                continue;
            }
            if (str_contains($key, '@')) {
                throw new InvalidArgumentException(
                    "SendEmailCommand: {$field} must be a sender registry key, not an address."
                );
            }
            if (! isset(config('email888.senders')[$key])) {
                throw new InvalidArgumentException(
                    "SendEmailCommand: {$field} '{$key}' is not in the sender registry."
                );
            }
        }

        if ($this->correlationId !== null && ! \Illuminate\Support\Str::isUuid($this->correlationId)) {
            throw new InvalidArgumentException('SendEmailCommand: correlation_id must be a UUID.');
        }

        foreach ($this->metadata as $k => $v) {
            if (! is_scalar($v) && $v !== null) {
                throw new InvalidArgumentException(
                    "SendEmailCommand: metadata['{$k}'] must be scalar - the ledger is not a document store."
                );
            }
        }
    }

    /** Every recipient this message will reach, for ledger accounting. */
    public function allRecipients(): array
    {
        return array_values(array_unique(array_merge($this->recipients, $this->cc, $this->bcc)));
    }

    public function primaryRecipient(): string
    {
        return $this->recipients[0];
    }
}
