<?php

namespace App\Core\PlatformEvents;

use InvalidArgumentException;

/**
 * A subscriber's complete, declared contract.
 *
 * Everything a subscriber is permitted to do is stated here, in one place, and
 * validated on construction. A subscriber cannot be activated without declaring
 * its version, what it accepts, when it became active, how it retries, and what
 * sensitivity of data it may see.
 *
 * The activation timestamp is the important one: delivery is PROSPECTIVE. Adding
 * a subscriber must never silently reach back over historical events — that is
 * how a newly-wired notification subscriber emails a customer about an order
 * from three months ago.
 */
final class SubscriberDeclaration
{
    public const TENANCY_WORKSPACE_SCOPED = 'workspace_scoped';

    /** Policy for an event whose schema version this subscriber does not accept. */
    public const UNSUPPORTED_SCHEMA_SKIP = 'skip';
    public const UNSUPPORTED_SCHEMA_FAIL = 'permanently_fail';

    /**
     * @param array<string, int[]> $accepts        event_type => accepted schema versions
     * @param string[]             $sensitivityAllowance
     * @param int[]                $backoff        seconds per attempt
     */
    public function __construct(
        public readonly string $key,
        public readonly int $version,
        public readonly array $accepts,
        public readonly string $activatedAt,
        public readonly int $maxAttempts,
        public readonly array $backoff,
        public readonly array $sensitivityAllowance,
        public readonly string $tenancy = self::TENANCY_WORKSPACE_SCOPED,
        public readonly string $unsupportedSchemaPolicy = self::UNSUPPORTED_SCHEMA_SKIP,
        public readonly bool $emitsCustomerFacingSideEffects = false,
        public readonly string $description = '',

        /*
        | ── GOVERNANCE TIMESTAMPS — ELIGIBILITY, NOT EXECUTION ──
        |
        | These decide whether a delivery OBLIGATION exists. They live in code
        | because they are governance decisions, not operational switches. Feature
        | flags decide whether an existing obligation may currently RUN.
        |
        | disabledForFutureEligibilityAt: events recorded at or after this instant
        |   receive no delivery row. Rows already created are untouched.
        |
        | retiredAt: no new delivery row is ever created, at any event time.
        |   Historical evidence stays immutable. Reactivation requires a NEW
        |   subscriber version or an explicit governance decision — never just
        |   clearing this field, because delivery identity is
        |   (event, key, version) and reusing a retired version would silently
        |   collide with history.
        */
        public readonly ?string $disabledForFutureEligibilityAt = null,
        public readonly ?string $retiredAt = null,
    ) {
        if ($this->key === '' || ! preg_match('/^[a-z]+(\.[a-z_]+)+$/', $this->key)) {
            throw new InvalidArgumentException("subscriber key '{$this->key}' must be a dotted lower-case identifier");
        }

        if ($this->version < 1) {
            throw new InvalidArgumentException("{$this->key}: version must be >= 1");
        }

        if ($this->accepts === []) {
            throw new InvalidArgumentException("{$this->key}: must accept at least one event type");
        }

        foreach ($this->accepts as $type => $versions) {
            if (! is_string($type) || ! is_array($versions) || $versions === []) {
                throw new InvalidArgumentException("{$this->key}: accepts must map event_type => [schema versions]");
            }
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/', $this->activatedAt)) {
            throw new InvalidArgumentException("{$this->key}: activatedAt must be an explicit timestamp");
        }

        foreach (['disabledForFutureEligibilityAt' => $this->disabledForFutureEligibilityAt,
                  'retiredAt' => $this->retiredAt] as $label => $ts) {
            if ($ts !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/', $ts)) {
                throw new InvalidArgumentException("{$this->key}: {$label} must be an explicit timestamp or null");
            }
        }

        if ($this->retiredAt !== null && $this->retiredAt < $this->activatedAt) {
            throw new InvalidArgumentException("{$this->key}: retiredAt cannot precede activatedAt");
        }

        if ($this->maxAttempts < 1) {
            throw new InvalidArgumentException("{$this->key}: maxAttempts must be >= 1");
        }

        if ($this->backoff === []) {
            throw new InvalidArgumentException("{$this->key}: must declare a backoff ladder");
        }

        if ($this->sensitivityAllowance === []) {
            throw new InvalidArgumentException("{$this->key}: must declare which sensitivities it may see");
        }

        foreach ($this->sensitivityAllowance as $s) {
            if (! in_array($s, [PlatformEvent::SENSITIVITY_PUBLIC, PlatformEvent::SENSITIVITY_INTERNAL, PlatformEvent::SENSITIVITY_RESTRICTED], true)) {
                throw new InvalidArgumentException("{$this->key}: unknown sensitivity '{$s}'");
            }
        }

        if (! in_array($this->unsupportedSchemaPolicy, [self::UNSUPPORTED_SCHEMA_SKIP, self::UNSUPPORTED_SCHEMA_FAIL], true)) {
            throw new InvalidArgumentException("{$this->key}: unknown unsupported-schema policy");
        }
    }

    /**
     * Retired subscribers never acquire a new obligation. Distinct from paused:
     * a paused subscriber still accrues pending deliveries to run later.
     */
    public function isRetired(): bool
    {
        return $this->retiredAt !== null;
    }

    /** May this subscriber still acquire NEW delivery obligations at all? */
    public function acquiresNewObligations(): bool
    {
        return ! $this->isRetired();
    }

    /**
     * Does an event recorded at this instant create an obligation for us?
     *
     * Deliberately independent of every feature flag and of worker state: an
     * obligation either exists or it does not, and that must not depend on
     * whether a worker happened to be switched on at fan-out time. That coupling
     * is exactly what lost Phase 1C event #1.
     */
    public function eligibleForEventRecordedAt(string $recordedAt): bool
    {
        if ($this->isRetired()) {
            return false;
        }

        // Prospective boundary: never reach back over history automatically.
        if ($recordedAt < $this->activatedAt) {
            return false;
        }

        if ($this->disabledForFutureEligibilityAt !== null
            && $recordedAt >= $this->disabledForFutureEligibilityAt) {
            return false;
        }

        return true;
    }

    public function acceptsType(string $eventType): bool
    {
        return array_key_exists($eventType, $this->accepts);
    }

    public function acceptsSchema(string $eventType, int $schemaVersion): bool
    {
        return in_array($schemaVersion, $this->accepts[$eventType] ?? [], true);
    }

    public function maySee(string $sensitivity): bool
    {
        return in_array($sensitivity, $this->sensitivityAllowance, true);
    }

    public function backoffFor(int $attempt): int
    {
        $idx = max(0, min($attempt - 1, count($this->backoff) - 1));

        return (int) $this->backoff[$idx];
    }
}
