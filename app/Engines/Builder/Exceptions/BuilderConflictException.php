<?php

namespace App\Engines\Builder\Exceptions;

use RuntimeException;

/**
 * RISK-0100 — optimistic-lock conflict. A page save carried a base_version that no
 * longer matches the stored content: someone else changed the page since this client
 * loaded it. Surfaced as HTTP 409 so the edit is refused truthfully instead of silently
 * overwriting the other change (no lost update).
 */
final class BuilderConflictException extends RuntimeException
{
    public function __construct(string $customerMessage = 'This page was changed by someone else since you opened it. Reload to get the latest version, then re-apply your change.')
    {
        parent::__construct($customerMessage);
    }
}
