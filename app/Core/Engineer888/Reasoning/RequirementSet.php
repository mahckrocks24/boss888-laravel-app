<?php

namespace App\Core\Engineer888\Reasoning;

/**
 * The requirements the task actually listed, made impossible to skip quietly.
 *
 * WHY THIS EXISTS. Acceptance run of 2026-08-11, candidate 7e717943: asked for a
 * bug tracker with "validation", "responsive UI", "tests" and "README", the
 * provider returned three good files — a BugTracker class, a web entry point and
 * a test — and simply did not mention the README or write a single line of
 * validation. The candidate passed every structural rule, because every rule it
 * passed was about the SHAPE of the answer rather than its COVERAGE of the
 * question.
 *
 * RECEIVE had recorded the reason in plain sight: "no acceptance criteria —
 * completion will be judged by verification only". The requirements were sitting
 * in the task description as a bulleted list and nothing ever turned them into
 * something the provider was accountable for.
 *
 * This class does exactly that and nothing more. It is deterministic — the same
 * description yields the same list in the same order — because the request is
 * fingerprinted, and it does not interpret, rank or rewrite what it finds. A
 * requirement the author wrote badly is still the requirement.
 */
final class RequirementSet
{
    /** Enough for a real brief; a list longer than this is a programme, not a task. */
    private const MAX = 30;

    /**
     * Lines that are instructions to the workflow rather than requirements of
     * the software. They are removed because asking a provider to "satisfy"
     * them produces noise in unknowns, not better code.
     */
    private const NOT_A_REQUIREMENT = [
        'do not execute', 'do not implement', 'produce only a reviewable candidate',
        'do not approve', 'wait for approval', 'for my review', 'keep the architecture',
    ];

    private function __construct(
        /** @var array<int,string> */
        public readonly array $items,
    ) {}

    public static function fromDescription(?string $description): self
    {
        $text = trim((string) $description);
        if ($text === '') { return new self([]); }

        $items = [];

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            // A requirement is a bullet. Prose around the list is context, and
            // treating a sentence as a requirement is how this becomes noise.
            if (preg_match('/^\s*(?:[-*•]|\d+[.)])\s+(.{2,120})$/u', $line, $m) !== 1) { continue; }

            $item = trim(rtrim(trim($m[1]), ':;,.'));
            if ($item === '') { continue; }

            $lower = strtolower($item);
            foreach (self::NOT_A_REQUIREMENT as $phrase) {
                if (str_contains($lower, $phrase)) { continue 2; }
            }

            // Sub-bullets under a field list ("- fields:" then "  - title") are
            // requirements in their own right and are kept, but the duplicate
            // that arises from a nested restatement is not.
            if (! in_array($item, $items, true)) { $items[] = $item; }
            if (count($items) >= self::MAX) { break; }
        }

        return new self($items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function count(): int
    {
        return count($this->items);
    }

    /**
     * The prompt block.
     *
     * The wording is deliberately about ACCOUNTING rather than about doing: a
     * requirement that genuinely cannot be met is a legitimate unknown, and
     * forcing a provider to pretend otherwise buys a worse candidate than
     * letting it say so.
     */
    public function render(): string
    {
        if ($this->isEmpty()) { return ''; }

        $out = [];
        $out[] = '# REQUIREMENTS — EVERY ONE MUST BE ACCOUNTED FOR';
        $out[] = 'The task lists these explicitly. They are the definition of done for this';
        $out[] = 'candidate; nothing else is.';
        $out[] = '';

        foreach ($this->items as $i => $item) {
            $out[] = ($i + 1) . '. ' . $item;
        }

        $out[] = '';
        $out[] = 'For EACH numbered requirement you must do exactly one of:';
        $out[] = '  a) propose the file_changes that satisfy it, or';
        $out[] = '  b) record it in unknowns, naming the requirement and why it cannot be met.';
        $out[] = '';
        $out[] = 'A requirement that is neither implemented nor declared unknown is the single';
        $out[] = 'most common way this workflow produces a candidate that looks complete and is';
        $out[] = 'not. Documentation and validation are requirements like any other: if the list';
        $out[] = 'says README, a README is a file you must write; if it says validation, the';
        $out[] = 'input must actually be rejected when it is wrong.';

        return implode("\n", $out);
    }

    /** @return array<int,string> */
    public function toArray(): array
    {
        return $this->items;
    }
}
