<?php

namespace App\Core\Engineer888\Support;

/**
 * A unified diff, computed here rather than shelled out to `diff`.
 *
 * The reviewer's whole job is to read what will change, so this must not lie by
 * omission. It reports the true line counts even when it truncates the display,
 * and it says so when it truncates.
 *
 * Longest-common-subsequence over lines. Fine for the file sizes a candidate is
 * allowed to contain (60KB, enforced by CandidateValidator) and it avoids a
 * process call, which matters because this runs inside a request.
 */
final class UnifiedDiff
{
    public const MAX_LINES_RENDERED = 400;

    /**
     * @return array{
     *   hunks:array<int,array<string,mixed>>, additions:int, deletions:int,
     *   truncated:bool, total_lines:int, binary:bool
     * }
     */
    public static function between(string $before, string $after, int $context = 3): array
    {
        if (self::looksBinary($before) || self::looksBinary($after)) {
            return ['hunks' => [], 'additions' => 0, 'deletions' => 0,
                    'truncated' => false, 'total_lines' => 0, 'binary' => true];
        }

        $old = self::lines($before);
        $new = self::lines($after);

        $ops = self::operations($old, $new);

        $additions = 0;
        $deletions = 0;
        foreach ($ops as $op) {
            if ($op['type'] === 'add') { $additions++; }
            if ($op['type'] === 'del') { $deletions++; }
        }

        $lines = self::withContext($ops, $context);
        $truncated = count($lines) > self::MAX_LINES_RENDERED;

        return [
            'hunks'       => $truncated ? array_slice($lines, 0, self::MAX_LINES_RENDERED) : $lines,
            'additions'   => $additions,
            'deletions'   => $deletions,
            'truncated'   => $truncated,
            'total_lines' => count($lines),
            'binary'      => false,
        ];
    }

    /**
     * Split into lines the way a diff tool counts them.
     *
     * A file ending in a newline is not a file with a trailing empty line —
     * `explode` disagrees, and the first version of this class reported a
     * two-line new file as three additions. Exactly one trailing terminator is
     * dropped; a file ending in two newlines really does end with a blank line.
     *
     * @return array<int,string>
     */
    private static function lines(string $content): array
    {
        if ($content === '') { return []; }

        $normalised = str_replace("\r\n", "\n", $content);
        if (str_ends_with($normalised, "\n")) { $normalised = substr($normalised, 0, -1); }

        return explode("\n", $normalised);
    }

    /** @return array<int,array{type:string,old:?int,new:?int,text:string}> */
    private static function operations(array $old, array $new): array
    {
        $lcs = self::lcsTable($old, $new);

        $ops = [];
        $i = count($old);
        $j = count($new);

        while ($i > 0 || $j > 0) {
            if ($i > 0 && $j > 0 && $old[$i - 1] === $new[$j - 1]) {
                array_unshift($ops, ['type' => 'same', 'old' => $i, 'new' => $j, 'text' => $old[$i - 1]]);
                $i--; $j--;
            } elseif ($j > 0 && ($i === 0 || $lcs[$i][$j - 1] >= $lcs[$i - 1][$j])) {
                array_unshift($ops, ['type' => 'add', 'old' => null, 'new' => $j, 'text' => $new[$j - 1]]);
                $j--;
            } else {
                array_unshift($ops, ['type' => 'del', 'old' => $i, 'new' => null, 'text' => $old[$i - 1]]);
                $i--;
            }
        }

        return $ops;
    }

    private static function lcsTable(array $old, array $new): array
    {
        $n = count($old);
        $m = count($new);
        $table = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));

        for ($i = 1; $i <= $n; $i++) {
            for ($j = 1; $j <= $m; $j++) {
                $table[$i][$j] = $old[$i - 1] === $new[$j - 1]
                    ? $table[$i - 1][$j - 1] + 1
                    : max($table[$i - 1][$j], $table[$i][$j - 1]);
            }
        }

        return $table;
    }

    /** Keep changed lines plus a few unchanged ones either side. */
    private static function withContext(array $ops, int $context): array
    {
        $keep = [];
        foreach ($ops as $index => $op) {
            if ($op['type'] === 'same') { continue; }
            for ($k = max(0, $index - $context); $k <= min(count($ops) - 1, $index + $context); $k++) {
                $keep[$k] = true;
            }
        }

        $lines = [];
        $lastKept = -1;
        foreach ($ops as $index => $op) {
            if (! isset($keep[$index])) { continue; }
            if ($lastKept !== -1 && $index > $lastKept + 1) {
                $lines[] = ['type' => 'gap', 'old' => null, 'new' => null,
                            'text' => '@@ ' . ($index - $lastKept - 1) . ' unchanged line(s) @@'];
            }
            $lines[] = $op;
            $lastKept = $index;
        }

        return $lines;
    }

    private static function looksBinary(string $content): bool
    {
        return $content !== '' && str_contains(substr($content, 0, 8000), "\0");
    }
}
