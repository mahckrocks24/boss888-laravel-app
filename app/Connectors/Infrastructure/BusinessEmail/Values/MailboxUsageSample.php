<?php

namespace App\Connectors\Infrastructure\BusinessEmail\Values;

use DateTimeInterface;
use InvalidArgumentException;

/**
 * INFRA888 · E2 — one usage measurement as the PROVIDER reports it.
 *
 * EVERY MEASURE IS NULLABLE, AND NULL IS NOT ZERO.
 * This is the same rule the `email_usage` table enforces, restated at the seam
 * so a provider adapter cannot quietly substitute a zero on the way in. A
 * provider that does not report send counts produces null; rendering that as
 * "0 messages sent" would be a fabricated number the customer has no way to
 * detect. The engine copies these fields through unchanged.
 */
final class MailboxUsageSample
{
    public function __construct(
        public readonly string $localPart,
        public readonly DateTimeInterface $observedAt,
        public readonly ?int $storageUsedMb = null,
        public readonly ?int $storageQuotaMb = null,
        public readonly ?int $messagesSent = null,
        public readonly ?int $messagesReceived = null,
        public readonly ?DateTimeInterface $lastLoginAt = null,
    ) {
        if (trim($localPart) === '') {
            throw new InvalidArgumentException(
                'MailboxUsageSample: a measurement with no subject cannot be attributed to a mailbox.'
            );
        }

        foreach ([
            'storage_used_mb'   => $storageUsedMb,
            'storage_quota_mb'  => $storageQuotaMb,
            'messages_sent'     => $messagesSent,
            'messages_received' => $messagesReceived,
        ] as $field => $value) {
            if ($value !== null && $value < 0) {
                throw new InvalidArgumentException("MailboxUsageSample: {$field} cannot be negative.");
            }
        }
    }

    public function normalizedLocalPart(): string
    {
        return strtolower(trim($this->localPart));
    }

    /** True when the provider reported nothing measurable at all. */
    public function isEmpty(): bool
    {
        return $this->storageUsedMb === null
            && $this->storageQuotaMb === null
            && $this->messagesSent === null
            && $this->messagesReceived === null;
    }

    public function toArray(): array
    {
        return [
            'local_part'        => $this->localPart,
            'observed_at'       => $this->observedAt->format(DATE_ATOM),
            'storage_used_mb'   => $this->storageUsedMb,
            'storage_quota_mb'  => $this->storageQuotaMb,
            'messages_sent'     => $this->messagesSent,
            'messages_received' => $this->messagesReceived,
            'last_login_at'     => $this->lastLoginAt?->format(DATE_ATOM),
        ];
    }
}
