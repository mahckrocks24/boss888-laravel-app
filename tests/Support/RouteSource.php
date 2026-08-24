<?php

namespace Tests\Support;

/**
 * The route source set.
 *
 * CR-22B split routes/api.php into a parent file plus modules under
 * routes/api/authenticated/. Any test that asserts on route SOURCE TEXT must
 * read all of it — a test pinned to one filename would keep passing while
 * guarding nothing, which is worse than no test at all.
 */
final class RouteSource
{
    /** Relative paths of the extracted module files, sorted for stability. */
    public static function relativeModules(): array
    {
        $dir = base_path('routes/api/authenticated');
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (glob($dir . '/*.php') as $f) {
            $out[] = 'routes/api/authenticated/' . basename($f);
        }
        sort($out);
        return $out;
    }

    /** Relative paths of every file that contributes route source. */
    public static function relative(): array
    {
        return array_merge(['routes/api.php'], self::relativeModules());
    }

    /** The concatenated source of every route file. */
    public static function all(): string
    {
        $parts = [];
        foreach (self::relative() as $rel) {
            $p = base_path($rel);
            if (is_file($p)) {
                $parts[] = (string) file_get_contents($p);
            }
        }
        return implode("\n", $parts);
    }
}
