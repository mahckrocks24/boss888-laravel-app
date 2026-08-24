<?php

namespace App\Connectors\Infrastructure\BusinessEmail\Values;

use App\Engines\Infrastructure\Email\Support\EmailAddress;
use InvalidArgumentException;

/**
 * INFRA888 · E2 — a provider-neutral forwarding request.
 *
 * Loop safety is NOT enforced here, deliberately. This object describes what was
 * asked for; ForwarderLoopSafety decides whether it may be attempted, and the
 * engine refuses before a provider is called. Duplicating the check here would
 * put the same rule in two places, and the two would eventually disagree — with
 * the version nobody remembered to update being the one that ran.
 *
 * What this DOES refuse is a destination that is not an address at all, because
 * that is a property of the value rather than a policy about it.
 */
final class ForwarderSpec
{
    public function __construct(
        public readonly string $sourceLocalPart,
        public readonly string $destinationAddress,
    ) {
        if (! EmailAddress::isValidLocalPart($sourceLocalPart)) {
            throw new InvalidArgumentException(
                "ForwarderSpec: '{$sourceLocalPart}' is not a usable local part."
            );
        }

        if (! EmailAddress::isValid($destinationAddress)) {
            throw new InvalidArgumentException(
                'ForwarderSpec: the destination is not a usable email address. Mail relayed to an unroutable '
                . 'destination generates bounces against the customer\'s own domain reputation.'
            );
        }
    }

    public function toArray(): array
    {
        return [
            'source_local_part'   => $this->sourceLocalPart,
            'destination_address' => $this->destinationAddress,
        ];
    }

    public function toAuditArray(): array
    {
        return $this->toArray();
    }
}
