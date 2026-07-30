<?php

namespace App\Core\Engineer888\Execution;

use App\Core\Engineer888\Repository\FileClassifier;

/**
 * Writes the commit message from the plan.
 *
 * The convention is NOT invented here — it is the one this repository already
 * uses, read off its own history: `type(scope): subject`, a lowercase scope, a
 * wrapped prose body explaining what and why, and a `Ref:` trailer. Matching the
 * existing convention matters more than matching a standard, and this repository
 * happens to use Conventional Commits already, so both requirements are met by
 * doing the same thing.
 *
 * Every field is derived from evidence the plan already holds. Nothing is
 * guessed, and where something cannot be known — whether a schema change is
 * breaking, for instance — the message says that rather than asserting "none".
 */
final class CommitMessageBuilder
{
    private const WIDTH = 76;

    /** @param  array<string,mixed>  $group */
    public function build(array $group, array $plan, int $planId, int $total): string
    {
        $type = $this->type($group);
        $scope = $this->scope($group);
        $subject = $this->subject($group, $type);

        $header = $type . ($scope !== null ? '(' . $scope . ')' : '') . ': ' . $subject;
        if (mb_strlen($header) > 72) {
            $header = mb_substr($header, 0, 69) . '...';
        }

        $lines = [$header, ''];

        // ── reason ───────────────────────────────────────────────────────
        $lines[] = wordwrap($this->reason($group), self::WIDTH, "\n", true);
        $lines[] = '';

        // ── composition ──────────────────────────────────────────────────
        $counts = [];
        foreach ($group['layers'] as $layer) { $counts[] = $layer; }
        $lines[] = wordwrap('Files: ' . $group['file_count'] . ' (' . implode(', ', $counts) . ').',
            self::WIDTH, "\n", true);

        // ── risk ─────────────────────────────────────────────────────────
        $lines[] = wordwrap('Risk: ' . $this->risk($group), self::WIDTH, "\n", true);

        // ── breaking changes ─────────────────────────────────────────────
        $lines[] = wordwrap('Breaking changes: ' . $this->breaking($group), self::WIDTH, "\n", true);

        // ── follow-up ────────────────────────────────────────────────────
        $followUp = $this->followUp($group);
        if ($followUp !== null) {
            $lines[] = '';
            $lines[] = wordwrap('Follow-up: ' . $followUp, self::WIDTH, "\n", true);
        }

        $lines[] = '';
        $lines[] = 'Ref: Engineer888 commit plan #' . $planId . ', group '
                 . $group['position'] . '/' . $total . ' (' . $group['key'] . ').';

        return implode("\n", $lines) . "\n";
    }

    /** Conventional Commits type, derived from what the group actually contains. */
    public function type(array $group): string
    {
        $layers = $group['layers'];
        $classifications = $group['classifications'];

        if ($layers === ['documentation']) { return 'docs'; }
        if ($layers === ['test']) { return 'test'; }
        if (in_array(FileClassifier::PARTIAL, $classifications, true)) { return 'wip'; }
        if (in_array('migration', $layers, true)) { return 'feat'; }

        $wiringOnly = array_diff($layers, ['config', 'routing', 'bootstrap', 'provider', 'test-config']) === [];
        if ($wiringOnly) { return 'chore'; }

        // New files are a feature; edits to existing files are not necessarily.
        return $group['kind'] === 'unit' ? 'feat' : 'feat';
    }

    /** Lowercase scope, matching this repository's existing style. */
    public function scope(array $group): ?string
    {
        if ($group['kind'] === 'docs') { return null; }

        $subsystem = $group['subsystem'];
        if ($subsystem === '' || $subsystem === 'documentation') { return null; }

        // A unit is named by its directory; use its last meaningful segment.
        if ($group['kind'] === 'unit') {
            $parts = array_values(array_filter(explode('/', $subsystem)));
            $subsystem = end($parts) ?: $subsystem;
        }

        return strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $subsystem) ?? $subsystem);
    }

    private function subject(array $group, string $type): string
    {
        if ($group['kind'] === 'docs') {
            return 'record ' . $group['file_count'] . ' engineering documents';
        }
        if ($group['kind'] === 'unit') {
            return 'add ' . $group['file_count'] . ' files under ' . $group['subsystem'];
        }
        if ($group['kind'] === 'review') {
            return 'unreferenced or experimental files pending a decision';
        }

        $shape = trim(preg_replace('/^.*? — /u', '', $group['title']) ?? $group['title']);

        return $shape !== '' ? $shape : 'changes to ' . $group['subsystem'];
    }

    private function reason(array $group): string
    {
        // The plan already generated a rationale from the group's composition.
        // Restating it here rather than writing a second, differently-worded
        // explanation keeps the commit and the plan describing the same thing.
        $reason = $group['rationale'];

        // These files are already deployed — the platform runs from this working
        // tree. Saying so prevents the commit being read as a behaviour change.
        $reason .= ' These files are already what this environment runs; committing them records '
                 . 'existing behaviour rather than changing it.';

        return $reason;
    }

    private function risk(array $group): string
    {
        $parts = [];

        if ($group['has_migration']) {
            $parts[] = 'contains a schema change';
        }
        if ($group['has_test']) {
            $parts[] = 'tests are included in the commit';
        } else {
            $parts[] = 'no tests in this commit';
        }
        if (array_intersect(['routing', 'config', 'bootstrap', 'provider'], $group['layers']) !== []) {
            $parts[] = 'changes application wiring, so the blast radius is wider than the subsystem';
        }
        foreach ($group['blocking'] as $blocker) {
            $parts[] = strtolower(rtrim($blocker['reason'], '.'));
        }

        $level = $group['has_migration'] || array_intersect(['routing', 'bootstrap', 'provider'], $group['layers']) !== []
            ? 'elevated' : ($group['has_test'] ? 'low' : 'moderate');

        return $level . ' — ' . implode('; ', $parts) . '.';
    }

    private function breaking(array $group): string
    {
        if ($group['has_migration']) {
            // Whether a migration is breaking depends on what it does to existing
            // columns, which this analysis does not read. Claiming "none" would
            // be an assertion the evidence does not support.
            return 'not determined automatically — this commit contains a migration, so review it '
                 . 'against existing data before relying on this line.';
        }
        if (array_intersect(['routing', 'provider', 'bootstrap'], $group['layers']) !== []) {
            return 'none expected, but application wiring changed — verify no route or binding was removed.';
        }

        return 'none identified.';
    }

    private function followUp(array $group): ?string
    {
        $items = [];

        foreach ($group['blocking'] as $blocker) {
            $items[] = $blocker['reason'] . ' (' . implode(', ', array_slice($blocker['files'], 0, 3)) . ')';
        }
        foreach (($group['must_follow'] ?? []) as $dependency) {
            $items[] = 'depends on group "' . $dependency['group'] . '" — ' . $dependency['reason'];
        }
        if (! $group['has_test'] && array_intersect(['core', 'service', 'engine', 'controller', 'job'], $group['layers']) !== []) {
            $items[] = 'no test covers this code in this commit.';
        }

        return $items === [] ? null : implode(' ', array_slice($items, 0, 4));
    }
}
