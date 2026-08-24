<?php

namespace App\Connectors\Infrastructure\BusinessEmail\Values;

use App\Engines\Infrastructure\Email\Support\EmailAddress;
use InvalidArgumentException;

/**
 * INFRA888 · E2 — a provider-neutral alias request.
 *
 * THE TARGET IS ALWAYS A RESOLVED ADDRESS.
 * The engine's own model permits an alias to point at a mailbox record OR at a
 * literal address. That distinction is a LevelUp concept: providers only
 * understand addresses. Resolving `target_mailbox_id` to an address before the
 * seam is what stops a LevelUp primary key from being handed to a provider —
 * which would be meaningless there and would couple our ids to their state.
 *
 * `targetMailboxRef` is carried separately and is OPAQUE. Providers that model
 * an alias as a child of a mailbox need the mailbox's own provider reference;
 * providers that model it as a domain-level route ignore it. Neither behaviour
 * belongs in the engine, so both are available and the adapter chooses.
 */
final class AliasSpec
{
    /**
     * @param string      $sourceLocalPart  the alias's own local part
     * @param string      $targetAddress    fully resolved destination
     * @param string|null $targetMailboxRef opaque provider reference, when the
     *                                      target is a mailbox we operate
     */
    public function __construct(
        public readonly string $sourceLocalPart,
        public readonly string $targetAddress,
        public readonly ?string $targetMailboxRef = null,
    ) {
        if (! EmailAddress::isValidLocalPart($sourceLocalPart)) {
            throw new InvalidArgumentException(
                "AliasSpec: '{$sourceLocalPart}' is not a usable local part."
            );
        }

        if (! EmailAddress::isValid($targetAddress)) {
            throw new InvalidArgumentException(
                'AliasSpec: the target is not a usable email address. An alias with an unroutable target '
                . 'silently discards mail.'
            );
        }
    }

    public function targetsAMailboxWeOperate(): bool
    {
        return $this->targetMailboxRef !== null;
    }

    public function toArray(): array
    {
        return [
            'source_local_part' => $this->sourceLocalPart,
            'target_address'    => $this->targetAddress,
            // Deliberately excluded from any customer projection; see the
            // white-label guards.
            'target_mailbox_ref' => $this->targetMailboxRef,
        ];
    }

    /** For an operation record. The provider reference is an operator concern. */
    public function toAuditArray(): array
    {
        return [
            'source_local_part'      => $this->sourceLocalPart,
            'target_address'         => $this->targetAddress,
            'targets_managed_mailbox' => $this->targetsAMailboxWeOperate(),
        ];
    }
}
