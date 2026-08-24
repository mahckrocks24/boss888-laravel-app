<?php

namespace App\Core\Email888\Providers;

use App\Core\Email888\Contracts\ProviderEvent;
use App\Core\Email888\Contracts\WebhookNormalizer;
use App\Core\Email888\States\DeliveryState;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * EMAIL888 EM-8 — the Postmark adapter. THE ONLY place Postmark's vocabulary lives.
 *
 * Everything here was previously inline in the webhook controller and in the
 * shared FailureClassifier. It worked, and it would have kept working — right up
 * until a second provider arrived, at which point the mapping would have been
 * copied rather than reused, and the copy would have drifted.
 */
final class PostmarkNormalizer implements WebhookNormalizer
{
    public function provider(): string
    {
        return 'postmark';
    }

    public function normalize(array $payload): array
    {
        $recordType = (string) ($payload['RecordType'] ?? '');

        if ($recordType === '') {
            return [];   // not a Postmark event at all
        }

        [$state, $occurredAt] = $this->map($recordType, $payload);

        $messageId = $payload['MessageID'] ?? null;

        return [new ProviderEvent(
            provider:          $this->provider(),
            providerMessageId: is_string($messageId) && $messageId !== '' ? $messageId : null,
            state:             $state,
            rawEventType:      $recordType . (($payload['Type'] ?? '') !== '' ? ':' . $payload['Type'] : ''),
            occurredAt:        $occurredAt,
            recipient:         is_string($payload['Recipient'] ?? null) ? $payload['Recipient'] : null,
            evidence:          $this->evidence($payload),
        )];
    }

    /** @return array{0:?DeliveryState,1:?Carbon} */
    private function map(string $recordType, array $event): array
    {
        return match ($recordType) {
            'Delivery' => [DeliveryState::DELIVERED, $this->time($event['DeliveredAt'] ?? null)],

            'Bounce' => [
                $this->bounceState((string) ($event['Type'] ?? '')),
                $this->time($event['BouncedAt'] ?? null),
            ],

            // A complaint is a permanent instruction to stop, not a transient
            // failure - suppression, so retry logic never re-sends.
            'SpamComplaint' => [DeliveryState::SUPPRESSED, $this->time($event['BouncedAt'] ?? null)],

            'SubscriptionChange' => ($event['SuppressSending'] ?? false)
                ? [DeliveryState::SUPPRESSED, $this->time($event['ChangedAt'] ?? null)]
                // Understood, but nothing changes. NOT the same as unparseable.
                : [null, null],

            default => [null, null],
        };
    }

    private function bounceState(string $type): DeliveryState
    {
        $transient = ['SoftBounce', 'Transient', 'DnsError', 'SMTPApiError', 'Blocked'];

        return in_array($type, $transient, true) ? DeliveryState::DEFERRED : DeliveryState::BOUNCED;
    }

    private function evidence(array $event): array
    {
        $keep = ['RecordType', 'Type', 'TypeCode', 'Recipient', 'Email', 'Tag',
                 'Description', 'Details', 'Inactive', 'CanActivate', 'MessageStream',
                 'ServerID', 'SuppressSending', 'SuppressionReason'];

        $out = [];
        foreach ($keep as $k) {
            if (array_key_exists($k, $event) && (is_scalar($event[$k]) || $event[$k] === null)) {
                $out[$k] = $event[$k];
            }
        }

        return $out;
    }

    private function time(?string $raw): ?Carbon
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Postmark's API error codes. These used to live in the SHARED classifier,
     * where a second provider's codes would have had to be interleaved with
     * them and the first collision would have misclassified real mail.
     */
    public function classifyFailure(Throwable $e): ?array
    {
        $msg = strtolower($e->getMessage());

        // 406: the recipient is on a suppression list. Sending again produces
        // the identical refusal.
        if (str_contains($msg, 'error code: 406')) {
            return ['state' => DeliveryState::SUPPRESSED, 'category' => 'recipient_suppressed', 'retryable' => false];
        }

        // 300: the address is not a valid address at all.
        if (str_contains($msg, 'error code: 300')) {
            return ['state' => DeliveryState::FAILED, 'category' => 'invalid_recipient', 'retryable' => false];
        }

        // 10: our own token is wrong. Retrying cannot fix a bad credential, and
        // hammering it looks like an attack.
        if (str_contains($msg, 'error code: 10')) {
            return ['state' => DeliveryState::FAILED, 'category' => 'provider_auth', 'retryable' => false];
        }

        // 405: this sender is not permitted on this server.
        if (str_contains($msg, 'error code: 405')) {
            return ['state' => DeliveryState::FAILED, 'category' => 'sender_not_permitted', 'retryable' => false];
        }

        return null;   // nothing Postmark-specific recognised; shared rules apply
    }
}
