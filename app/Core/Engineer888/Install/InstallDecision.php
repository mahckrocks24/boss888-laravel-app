<?php

namespace App\Core\Engineer888\Install;

/**
 * What the installer decided about one file, and the evidence behind it.
 *
 * Every refusal carries the three hashes that produced it, because "it
 * refused" is not actionable and "the destination hash is X, I installed Y,
 * the source is now Z" is.
 */
final class InstallDecision
{
    /** Destination does not exist — nothing can be lost. */
    public const INSTALL = 'INSTALL';

    /** Destination matches what was installed; the source has moved on. */
    public const UPDATE = 'UPDATE';

    /** Destination matches the record and the source is identical. */
    public const UNCHANGED_NO_OP = 'UNCHANGED_NO_OP';

    /** Destination differs from what was installed — someone edited it. REFUSE. */
    public const DIVERGED = 'DIVERGED';

    /** Destination exists with no install record. REFUSE. */
    public const UNKNOWN_PROVENANCE = 'UNKNOWN_PROVENANCE';

    /** Operator explicitly approved overwriting a divergent destination. */
    public const OVERRIDDEN = 'OVERRIDDEN';

    /** Refused for a reason unrelated to provenance (ownership, unreadable). */
    public const REFUSED = 'REFUSED';

    public function __construct(
        public readonly string $state,
        public readonly string $destination,
        public readonly ?string $sourceHash,
        public readonly ?string $destinationHash,
        public readonly ?string $recordedSourceHash,
        public readonly ?string $recordedDestinationHash,
        public readonly string $reason,
        public readonly array $diff = [],
        public readonly ?string $backup = null,
        public readonly ?string $recommendation = null,
    ) {}

    public function isWrite(): bool
    {
        return in_array($this->state, [self::INSTALL, self::UPDATE, self::OVERRIDDEN], true);
    }

    public function isRefusal(): bool
    {
        return in_array($this->state, [self::DIVERGED, self::UNKNOWN_PROVENANCE, self::REFUSED], true);
    }

    /** True when the destination changed after installation — the incident shape. */
    public function destinationChangedSinceInstall(): bool
    {
        return $this->recordedDestinationHash !== null
            && $this->destinationHash !== null
            && $this->recordedDestinationHash !== $this->destinationHash;
    }

    public function sourceChangedSinceInstall(): bool
    {
        return $this->recordedSourceHash !== null
            && $this->sourceHash !== null
            && $this->recordedSourceHash !== $this->sourceHash;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'state'                      => $this->state,
            'destination'                => $this->destination,
            'source_hash'                => $this->sourceHash,
            'destination_hash'           => $this->destinationHash,
            'recorded_source_hash'       => $this->recordedSourceHash,
            'recorded_destination_hash'  => $this->recordedDestinationHash,
            'destination_changed'        => $this->destinationChangedSinceInstall(),
            'source_changed'             => $this->sourceChangedSinceInstall(),
            'reason'                     => $this->reason,
            'diff'                       => $this->diff,
            'backup'                     => $this->backup,
            'recommendation'             => $this->recommendation,
        ];
    }
}
