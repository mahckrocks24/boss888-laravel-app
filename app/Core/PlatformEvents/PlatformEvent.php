<?php

namespace App\Core\PlatformEvents;

use InvalidArgumentException;

/**
 * Base class for every typed platform event.
 *
 * Typed events over a SHARED envelope. Not one unrestricted generic payload:
 * a free-form bag cannot be validated, cannot be versioned per event, and gives
 * a subscriber no contract. Within a month `payload['domain']` and
 * `payload['domain_name']` both exist and no test catches it.
 *
 * Each subclass declares its own required payload fields and its own schema
 * version, and validates on construction — so a malformed payload fails BEFORE
 * the business transaction commits, not after.
 */
abstract class PlatformEvent
{
    public const ACTOR_USER     = 'user';
    public const ACTOR_AGENT    = 'agent';
    public const ACTOR_SYSTEM   = 'system';
    public const ACTOR_CUSTOMER = 'customer';

    public const SENSITIVITY_PUBLIC     = 'public';
    public const SENSITIVITY_INTERNAL   = 'internal';
    public const SENSITIVITY_RESTRICTED = 'restricted';

    /** e.g. 'domain.order.created' */
    abstract public static function type(): string;

    abstract public static function schemaVersion(): int;

    /** Payload keys that MUST be present. */
    abstract public static function requiredPayloadKeys(): array;

    abstract public function subjectType(): string;

    abstract public function subjectId(): string;

    public function __construct(
        public readonly int $workspaceId,
        public readonly array $payload,
        public readonly string $actorType = self::ACTOR_SYSTEM,
        public readonly ?int $actorId = null,
        public readonly ?string $capabilityKey = null,
        public readonly ?string $correlationId = null,
        public readonly ?string $causationId = null,
        public readonly ?\DateTimeInterface $occurredAt = null,
    ) {
        $this->assertValid();
    }

    public function sensitivity(): string
    {
        return self::SENSITIVITY_INTERNAL;
    }

    /**
     * Deterministic identity.
     *
     * The same logical event always produces the same id, so a duplicate
     * producer invocation collides on the unique index instead of recording a
     * second event. Derived from type + schema version + subject — NOT from
     * time or randomness, which would defeat the purpose.
     *
     * Formatted as a UUID so the column type is honest, but it is a hash, not
     * a random v4.
     */
    public function eventId(): string
    {
        $seed = static::type() . '|v' . static::schemaVersion()
            . '|' . $this->subjectType() . '|' . $this->subjectId()
            . '|ws' . $this->workspaceId;

        $h = substr(hash('sha256', $seed), 0, 32);

        return sprintf('%s-%s-%s-%s-%s',
            substr($h, 0, 8), substr($h, 8, 4), substr($h, 12, 4),
            substr($h, 16, 4), substr($h, 20, 12)
        );
    }

    private function assertValid(): void
    {
        if ($this->workspaceId <= 0) {
            throw new InvalidArgumentException(
                static::type() . ': workspace_id is mandatory — an event that cannot name its tenant cannot be delivered safely'
            );
        }

        if (! in_array($this->actorType, [self::ACTOR_USER, self::ACTOR_AGENT, self::ACTOR_SYSTEM, self::ACTOR_CUSTOMER], true)) {
            throw new InvalidArgumentException(static::type() . ": unknown actor_type '{$this->actorType}'");
        }

        $missing = array_values(array_diff(static::requiredPayloadKeys(), array_keys($this->payload)));

        if ($missing !== []) {
            throw new InvalidArgumentException(
                static::type() . ' is missing required payload field(s): ' . implode(', ', $missing)
            );
        }

        if ($this->subjectId() === '') {
            throw new InvalidArgumentException(static::type() . ': subject_id cannot be empty');
        }

        $this->assertPayloadTypes();
    }

    /** Subclasses override to add field-level type checks. */
    protected function assertPayloadTypes(): void
    {
    }
}
