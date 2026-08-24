<?php

namespace App\Core\Engineer888\Approval;

/**
 * May this candidate be written, and if not, exactly why.
 *
 * A refusal carries its reasons. An engineer who is told only "approval
 * invalid" will look for a way around the gate; one who is told "changed
 * content in app/Foo.php" will look at app/Foo.php.
 */
final class EnforcementResult
{
    private function __construct(
        public readonly bool $permitted,
        public readonly string $summary,
        public readonly array $reasons = [],
        public readonly ?object $approval = null,
    ) {}

    public static function permit(object $approval): self
    {
        return new self(true,
            'approved by ' . $approval->approver_name . ' — binding matches',
            [], $approval);
    }

    /** @param array<int,string> $reasons */
    public static function refuse(string $summary, array $reasons = [], ?object $approval = null): self
    {
        return new self(false, $summary, $reasons, $approval);
    }

    public function detail(): string
    {
        return $this->reasons === [] ? $this->summary : implode('; ', $this->reasons);
    }

    public function toArray(): array
    {
        return [
            'permitted' => $this->permitted,
            'summary'   => $this->summary,
            'reasons'   => $this->reasons,
            'approval'  => $this->approval === null ? null : [
                'id'          => $this->approval->id,
                'state'       => $this->approval->state,
                'approver'    => $this->approval->approver_name,
                'approved_at' => $this->approval->approved_at ?? null,
                'fingerprint' => $this->approval->fingerprint,
            ],
        ];
    }
}
