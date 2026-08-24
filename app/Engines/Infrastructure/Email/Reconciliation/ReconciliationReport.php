<?php

namespace App\Engines\Infrastructure\Email\Reconciliation;

/**
 * INFRA888 · E2 — the outcome of one reconciliation pass.
 *
 * `conclusive` is the field that stops this being dangerous. A pass that could
 * not enumerate the provider completely — rate limit, timeout, a provider that
 * cannot list at all — produces findings about what it COULD see, and refuses
 * to conclude anything about absence. An inconclusive report that looked like a
 * conclusive one would tell an operator that half a customer's mailboxes had
 * vanished.
 */
final class ReconciliationReport
{
    /**
     * @param array<int,ReconciliationFinding> $findings
     */
    public function __construct(
        public readonly string $domain,
        public readonly array $findings,
        public readonly bool $conclusive,
        public readonly string $inconclusiveReason = '',
        public readonly int $desiredObjectCount = 0,
        public readonly int $observedObjectCount = 0,
    ) {
    }

    public static function unreachable(string $domain, string $reason): self
    {
        return new self(
            $domain,
            [new ReconciliationFinding(
                ReconciliationFinding::PROVIDER_UNREACHABLE,
                $domain,
                $reason
            )],
            false,
            $reason
        );
    }

    public function isClean(): bool
    {
        return $this->findings === [];
    }

    public function count(): int
    {
        return count($this->findings);
    }

    /** @return array<int,ReconciliationFinding> */
    public function ofKind(string $kind): array
    {
        return array_values(array_filter($this->findings, fn (ReconciliationFinding $f) => $f->kind === $kind));
    }

    public function hasKind(string $kind): bool
    {
        return $this->ofKind($kind) !== [];
    }

    /** @return array<int,string> */
    public function kinds(): array
    {
        return array_values(array_unique(array_map(fn (ReconciliationFinding $f) => $f->kind, $this->findings)));
    }

    public function worstSeverity(): ?string
    {
        $order = [
            ReconciliationFinding::SEVERITY_CRITICAL,
            ReconciliationFinding::SEVERITY_HIGH,
            ReconciliationFinding::SEVERITY_MEDIUM,
            ReconciliationFinding::SEVERITY_LOW,
            ReconciliationFinding::SEVERITY_INFO,
        ];

        foreach ($order as $severity) {
            foreach ($this->findings as $finding) {
                if ($finding->severity === $severity) {
                    return $severity;
                }
            }
        }

        return null;
    }

    /**
     * The customer summary. Deliberately a SUMMARY: counts and severities, not
     * a list of every internal difference. A customer needs to know whether
     * their mail is working, not to read a diff of our binding table.
     */
    public function toCustomerArray(): array
    {
        return [
            'domain'         => $this->domain,
            'healthy'        => $this->isClean() && $this->conclusive,
            'checked'        => $this->conclusive,
            'issue_count'    => $this->count(),
            'worst_severity' => $this->worstSeverity(),
            'issues'         => array_map(
                fn (ReconciliationFinding $f) => $f->toCustomerArray(),
                $this->findings
            ),
        ];
    }

    /** The operator view: every finding with its structured comparison. */
    public function toAdminArray(): array
    {
        return [
            'domain'               => $this->domain,
            'conclusive'           => $this->conclusive,
            'inconclusive_reason'  => $this->inconclusiveReason,
            'desired_object_count' => $this->desiredObjectCount,
            'observed_object_count' => $this->observedObjectCount,
            'findings'             => array_map(
                fn (ReconciliationFinding $f) => $f->toAdminArray(),
                $this->findings
            ),
        ];
    }
}
