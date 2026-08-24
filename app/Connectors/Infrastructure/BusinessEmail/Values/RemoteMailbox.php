<?php

namespace App\Connectors\Infrastructure\BusinessEmail\Values;

use InvalidArgumentException;

/**
 * INFRA888 · E2 — a mailbox as the PROVIDER reports it.
 *
 * Distinct from EmailMailbox on purpose. That model is what we intend; this is
 * what is actually there. Reconciliation compares the two, and merging them into
 * one shape would make the comparison impossible to express — you cannot diff a
 * thing against itself.
 *
 * `providerRef` is OPAQUE. Nothing may parse it, infer meaning from its shape,
 * or build logic on its format.
 *
 * `quotaMb` and `suspended` are nullable because providers differ in what they
 * report, and a provider that does not expose quota must produce null rather
 * than a guess. Reconciliation treats null as "cannot compare" and never as
 * "mismatch" — reporting drift because a provider is quiet would fill the
 * operator's screen with findings that mean nothing.
 */
final class RemoteMailbox
{
    public function __construct(
        public readonly string $providerRef,
        public readonly string $localPart,
        public readonly ?string $displayName = null,
        public readonly ?int $quotaMb = null,
        public readonly ?bool $suspended = null,
        public readonly ?int $storageUsedMb = null,
    ) {
        if (trim($providerRef) === '') {
            throw new InvalidArgumentException(
                'RemoteMailbox: a provider object with no reference cannot be bound to or acted on later.'
            );
        }

        if (trim($localPart) === '') {
            throw new InvalidArgumentException('RemoteMailbox: local part is required to match against our records.');
        }
    }

    public function normalizedLocalPart(): string
    {
        return strtolower(trim($this->localPart));
    }

    /** Operator surface only. A customer never sees a provider reference. */
    public function toAdminArray(): array
    {
        return [
            'provider_ref'    => $this->providerRef,
            'local_part'      => $this->localPart,
            'display_name'    => $this->displayName,
            'quota_mb'        => $this->quotaMb,
            'suspended'       => $this->suspended,
            'storage_used_mb' => $this->storageUsedMb,
        ];
    }
}
