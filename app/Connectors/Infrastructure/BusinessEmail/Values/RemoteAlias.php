<?php

namespace App\Connectors\Infrastructure\BusinessEmail\Values;

use InvalidArgumentException;

/**
 * INFRA888 · E2 — an alias as the PROVIDER reports it. See RemoteMailbox for why
 * observed and desired shapes are deliberately separate types.
 */
final class RemoteAlias
{
    public function __construct(
        public readonly string $providerRef,
        public readonly string $sourceLocalPart,
        public readonly ?string $targetAddress = null,
    ) {
        if (trim($providerRef) === '') {
            throw new InvalidArgumentException('RemoteAlias: a provider object with no reference cannot be acted on.');
        }

        if (trim($sourceLocalPart) === '') {
            throw new InvalidArgumentException('RemoteAlias: source local part is required to match our records.');
        }
    }

    public function normalizedSourceLocalPart(): string
    {
        return strtolower(trim($this->sourceLocalPart));
    }

    public function toAdminArray(): array
    {
        return [
            'provider_ref'      => $this->providerRef,
            'source_local_part' => $this->sourceLocalPart,
            'target_address'    => $this->targetAddress,
        ];
    }
}
