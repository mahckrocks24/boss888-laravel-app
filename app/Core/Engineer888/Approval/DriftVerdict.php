<?php

namespace App\Core\Engineer888\Approval;

/**
 * Whether the repository still looks the way the approver was told it did.
 *
 * Separate from EnforcementResult on purpose. That class answers "does this
 * candidate match its approval", which is a question about two records; this one
 * answers "does the repository match that approval", which is a question about
 * the disk. Collapsing them would produce refusals that name a candidate when
 * the thing that moved was a file.
 *
 * Findings are structured rather than prose so a later audit can count them by
 * kind, and repository-relative rather than absolute so a refusal that reaches a
 * customer-facing surface carries no server layout.
 */
final class DriftVerdict
{
    /** The approved pre-image no longer describes the repository. */
    public const DRIFT = 'REPOSITORY_DRIFT_SINCE_APPROVAL';

    /** The candidate predates pre-image binding, or its evidence is incomplete. */
    public const UNBOUND = 'LEGACY_CANDIDATE_PREIMAGE_UNBOUND';

    // Finding codes. Deliberately narrow: each one names a different repair.
    public const TARGET_CHANGED     = 'TARGET_CHANGED';
    public const TARGET_MISSING     = 'TARGET_MISSING';
    public const TARGET_EXISTS      = 'TARGET_UNEXPECTEDLY_EXISTS';
    public const TARGET_UNREADABLE  = 'TARGET_UNREADABLE';
    public const TARGET_NOT_A_FILE  = 'TARGET_NOT_A_FILE';
    public const PATH_ESCAPES       = 'PATH_ESCAPES_REPOSITORY';
    public const PRE_IMAGE_UNBOUND  = 'PRE_IMAGE_UNBOUND';

    private function __construct(
        public readonly bool $permitted,
        public readonly string $summary,
        /** @var array<int,array{path:string,code:string,detail:string}> */
        public readonly array $findings,
        public readonly int $checked,
    ) {}

    public static function permit(int $checked): self
    {
        return new self(true,
            $checked . ' approved target(s) are byte-for-byte as they were when the change was approved',
            [], $checked);
    }

    /** @param array<int,array{path:string,code:string,detail:string}> $findings */
    public static function refuse(string $summary, array $findings, int $checked = 0): self
    {
        return new self(false, $summary, $findings, $checked);
    }

    public function detail(): string
    {
        if ($this->findings === []) { return $this->summary; }

        return implode('; ', array_map(
            fn (array $f) => $f['path'] . ' — ' . $f['detail'],
            $this->findings
        ));
    }

    /** @return array<int,string> */
    public function codes(): array
    {
        return array_values(array_unique(array_column($this->findings, 'code')));
    }

    /** @return array<int,string> */
    public function paths(): array
    {
        return array_values(array_unique(array_column($this->findings, 'path')));
    }

    public function toArray(): array
    {
        return [
            'permitted' => $this->permitted,
            'reason'    => $this->permitted ? null : $this->summary,
            'summary'   => $this->summary,
            'checked'   => $this->checked,
            'codes'     => $this->codes(),
            'findings'  => $this->findings,
        ];
    }
}
