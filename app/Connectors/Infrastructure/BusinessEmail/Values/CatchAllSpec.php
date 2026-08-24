<?php

namespace App\Connectors\Infrastructure\BusinessEmail\Values;

use App\Engines\Infrastructure\Email\Support\EmailAddress;
use InvalidArgumentException;

/**
 * INFRA888 · E2 — a provider-neutral catch-all request.
 *
 * There is no "disabled" variant of this object, and that is the point: clearing
 * a catch-all is a separate contract method (`clearCatchAll`) with its own
 * capability flag, not a configure call carrying a null target. A spec that
 * could mean either "deliver everything here" or "deliver nothing anywhere"
 * depending on one nullable field is one typo away from silently routing a
 * domain's unassigned mail to nobody.
 */
final class CatchAllSpec
{
    public function __construct(
        public readonly string $targetAddress,
    ) {
        if (! EmailAddress::isValid($targetAddress)) {
            throw new InvalidArgumentException(
                'CatchAllSpec: the target is not a usable email address.'
            );
        }
    }

    public function toArray(): array
    {
        return ['target_address' => $this->targetAddress];
    }

    public function toAuditArray(): array
    {
        return $this->toArray();
    }
}
