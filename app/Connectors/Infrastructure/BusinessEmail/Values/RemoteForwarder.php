<?php

namespace App\Connectors\Infrastructure\BusinessEmail\Values;

use InvalidArgumentException;

/**
 * INFRA888 · E2 — a forwarder as the PROVIDER reports it. See RemoteMailbox for
 * why observed and desired shapes are deliberately separate types.
 */
final class RemoteForwarder
{
    public function __construct(
        public readonly string $providerRef,
        public readonly string $sourceLocalPart,
        public readonly ?string $destinationAddress = null,
    ) {
        if (trim($providerRef) === '') {
            throw new InvalidArgumentException(
                'RemoteForwarder: a provider object with no reference cannot be acted on.'
            );
        }

        if (trim($sourceLocalPart) === '') {
            throw new InvalidArgumentException('RemoteForwarder: source local part is required.');
        }
    }

    public function normalizedSourceLocalPart(): string
    {
        return strtolower(trim($this->sourceLocalPart));
    }

    public function normalizedDestination(): ?string
    {
        return $this->destinationAddress === null ? null : strtolower(trim($this->destinationAddress));
    }

    public function toAdminArray(): array
    {
        return [
            'provider_ref'        => $this->providerRef,
            'source_local_part'   => $this->sourceLocalPart,
            'destination_address' => $this->destinationAddress,
        ];
    }
}
