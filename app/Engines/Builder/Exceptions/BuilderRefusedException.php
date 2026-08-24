<?php

namespace App\Engines\Builder\Exceptions;

use RuntimeException;

/**
 * BUILDER888 P1-6-a — the domain refused an action for a business reason
 * (plan limit, entitlement) rather than failing.
 *
 * Distinct from a persistence fault so the customer receives the actionable
 * message the platform authored, never "we couldn't save this website".
 */
final class BuilderRefusedException extends RuntimeException
{
    public function __construct(
        string $customerMessage,
        public readonly bool $limitReached = false,
    ) {
        parent::__construct($customerMessage);
    }
}
