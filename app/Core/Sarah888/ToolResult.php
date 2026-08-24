<?php

namespace App\Core\Sarah888;

/**
 * SARAH888 — TYPED TOOL RESULT (Laravel → Runtime).
 *
 * Runtime reasons from EVIDENCE, never from prose about evidence.
 *
 * The status set is closed and every value is falsifiable against the record.
 * SUCCEEDED specifically means Laravel ran the real connector and holds the
 * output — it is the one status the runtime may describe in the past tense.
 * Measured 2026-08-13: Runtime's own serp_analysis returned success:true for a
 * model-written essay, which is exactly the claim this type exists to make
 * impossible.
 */
final class ToolResult
{
    public const SUCCEEDED         = 'TOOL_SUCCEEDED';
    public const FAILED            = 'TOOL_FAILED';
    public const REQUIRES_APPROVAL = 'TOOL_REQUIRES_APPROVAL';
    public const UNAVAILABLE       = 'TOOL_UNAVAILABLE';
    public const MISCONFIGURED     = 'TOOL_MISCONFIGURED';
    public const REFUSED           = 'TOOL_REFUSED';

    /**
     * Shadow simulation. A mutating capability is NEVER executed on the shadow
     * path; instead Laravel computes what it WOULD decide and says so. These are
     * deliberately distinct constants rather than a boolean flag on the real ones,
     * so a simulated outcome can never be mistaken for a decision that was acted on.
     */
    public const WOULD_BE_PERMITTED    = 'WOULD_BE_PERMITTED';
    public const WOULD_REQUIRE_APPROVAL = 'WOULD_REQUIRE_APPROVAL';
    public const WOULD_BE_REFUSED      = 'WOULD_BE_REFUSED';
    public const WOULD_BE_UNAVAILABLE  = 'WOULD_BE_UNAVAILABLE';
    public const WOULD_BE_MISCONFIGURED = 'WOULD_BE_MISCONFIGURED';

    public const SIMULATED = [
        self::WOULD_BE_PERMITTED, self::WOULD_REQUIRE_APPROVAL, self::WOULD_BE_REFUSED,
        self::WOULD_BE_UNAVAILABLE, self::WOULD_BE_MISCONFIGURED,
    ];

    /** Statuses after which NOTHING was executed. Sarah may not speak in the past tense. */
    public const NOT_EXECUTED = [
        self::REQUIRES_APPROVAL, self::UNAVAILABLE, self::MISCONFIGURED,
        self::REFUSED, self::FAILED,
        self::WOULD_BE_PERMITTED, self::WOULD_REQUIRE_APPROVAL, self::WOULD_BE_REFUSED,
        self::WOULD_BE_UNAVAILABLE, self::WOULD_BE_MISCONFIGURED,
    ];

    /** Map a real governance decision to what shadow reports instead. */
    public static function toSimulated(string $realStatus): string
    {
        return match ($realStatus) {
            self::REQUIRES_APPROVAL => self::WOULD_REQUIRE_APPROVAL,
            self::REFUSED           => self::WOULD_BE_REFUSED,
            self::UNAVAILABLE       => self::WOULD_BE_UNAVAILABLE,
            self::MISCONFIGURED     => self::WOULD_BE_MISCONFIGURED,
            default                 => self::WOULD_BE_PERMITTED,
        };
    }

    public function simulated(): bool
    {
        return in_array($this->status, self::SIMULATED, true);
    }

    public function __construct(
        public readonly string $status,
        public readonly string $capabilityId,
        public readonly ?array $data = null,
        public readonly ?string $provenance = null,
        public readonly int $creditCost = 0,
        public readonly int $latencyMs = 0,
        public readonly ?string $errorClass = null,
        public readonly ?string $message = null,
        public readonly ?int $executionId = null,
        public readonly ?int $proposalId = null,
        public readonly array $missingConfiguration = [],
    ) {}

    public function executed(): bool
    {
        return $this->status === self::SUCCEEDED;
    }

    /**
     * What the runtime is allowed to assert, stated explicitly so the reasoning
     * layer does not have to infer it from a status string.
     */
    public function toRuntime(): array
    {
        return [
            'status'          => $this->status,
            'capability_id'   => $this->capabilityId,
            'executed'        => $this->executed(),
            'may_claim_done'  => $this->executed(),
            'simulated'       => $this->simulated(),
            'data'            => $this->data,
            'provenance'      => $this->provenance,
            'credit_cost'     => $this->creditCost,
            'latency_ms'      => $this->latencyMs,
            'error_class'     => $this->errorClass,
            'message'         => $this->message,
            'execution_id'    => $this->executionId,
            'proposal_id'     => $this->proposalId,
            'missing_configuration' => $this->missingConfiguration,
        ];
    }

    public static function succeeded(string $cap, array $data, string $provenance,
                                     int $ms, int $cost = 0, ?int $execId = null): self
    {
        return new self(self::SUCCEEDED, $cap, $data, $provenance, $cost, $ms,
            null, null, $execId);
    }

    public static function unavailable(string $cap, string $why, array $missing = []): self
    {
        return new self(self::UNAVAILABLE, $cap, null, null, 0, 0,
            'not_available', $why, null, null, $missing);
    }

    public static function misconfigured(string $cap, string $why, array $missing): self
    {
        return new self(self::MISCONFIGURED, $cap, null, null, 0, 0,
            'misconfigured', $why, null, null, $missing);
    }

    public static function requiresApproval(string $cap, string $why, ?int $proposalId = null,
                                            int $cost = 0): self
    {
        return new self(self::REQUIRES_APPROVAL, $cap, null, null, $cost, 0,
            null, $why, null, $proposalId);
    }

    public static function refused(string $cap, string $why): self
    {
        return new self(self::REFUSED, $cap, null, null, 0, 0, 'refused', $why);
    }

    public static function failed(string $cap, string $errorClass, string $why, int $ms = 0): self
    {
        return new self(self::FAILED, $cap, null, null, 0, $ms, $errorClass, $why);
    }
}
