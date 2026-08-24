<?php

namespace Tests\Feature\Safety;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TD-35 — reserved-word collisions in raw SQL.
 *
 * WHY THIS EXISTS
 * `COUNT(*) rows` shipped in the E1 M5 Costs screen and was a hard syntax error
 * on MySQL 8, because ROWS became reserved for window functions. The screen
 * would have been broken in production. Nothing caught it until the query ran.
 *
 * A one-off sweep fixes today. This guards tomorrow: the reserved-word list is
 * read FROM THE SERVER, so when the database is upgraded and the list grows,
 * this test starts failing on code that was previously fine — which is exactly
 * the moment someone needs to know.
 *
 * SCOPE, stated so the guarantee is not overread:
 *   - covers raw SQL only (selectRaw, whereRaw, DB::raw and friends)
 *   - the query builder quotes identifiers itself, proven below, so builder
 *     calls are not at risk
 *   - it cannot see SQL assembled at runtime from variables
 */
class ReservedWordAuditTest extends TestCase
{
    private const BASE = '/var/www/levelup-staging';
    private const SCAN_DIRS = ['app', 'routes', 'database'];

    /** Words this server reserves right now. */
    private function reserved(): array
    {
        $rows = DB::select("SELECT WORD FROM information_schema.KEYWORDS WHERE RESERVED = 1");

        return array_map(fn ($r) => strtoupper($r->WORD), $rows);
    }

    /** @return array<int,array{file:string,line:int,sql:string}> */
    private function rawSqlFragments(): array
    {
        $out = [];

        foreach (self::SCAN_DIRS as $dir) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(self::BASE . '/' . $dir, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($it as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $src = (string) @file_get_contents($file->getPathname());
                if ($src === '') {
                    continue;
                }

                preg_match_all(
                    '/(selectRaw|whereRaw|havingRaw|orderByRaw|groupByRaw|DB::raw|->raw)\s*\(\s*([\'"])(.*?)\2/s',
                    $src, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER
                );

                foreach ($m as $hit) {
                    $out[] = [
                        'file' => str_replace(self::BASE . '/', '', $file->getPathname()),
                        'line' => substr_count(substr($src, 0, $hit[0][1]), "\n") + 1,
                        'sql' => $hit[3][0],
                    ];
                }
            }
        }

        return $out;
    }

    /** Split a select list on top-level commas. */
    private function topLevelParts(string $sql): array
    {
        $depth = 0; $cur = ''; $parts = [];

        foreach (str_split($sql) as $ch) {
            if ($ch === '(') { $depth++; }
            if ($ch === ')') { $depth--; }

            if ($ch === ',' && $depth === 0) {
                $parts[] = $cur; $cur = '';
                continue;
            }
            $cur .= $ch;
        }
        $parts[] = $cur;

        return $parts;
    }

    /**
     * The regression guard. An alias that is a reserved word is a syntax error,
     * not a style problem.
     */
    public function test_no_raw_sql_alias_is_a_reserved_word(): void
    {
        $reserved = $this->reserved();
        $this->assertNotEmpty($reserved, 'could not read the keyword list from the server');

        $offenders = [];

        foreach ($this->rawSqlFragments() as $frag) {
            foreach ($this->topLevelParts($frag['sql']) as $part) {
                $p = trim($part);
                if ($p === '' || !str_contains($p, ' ')) {
                    continue;
                }

                $alias = null;
                if (preg_match('/\bAS\s+([A-Za-z_][A-Za-z0-9_]*)\s*$/i', $p, $mm)) {
                    $alias = $mm[1];
                } elseif (preg_match('/(\)|\w|`)\s+([A-Za-z_][A-Za-z0-9_]*)\s*$/', $p, $mm)) {
                    $alias = $mm[2];
                }

                if ($alias === null) {
                    continue;
                }
                if (in_array(strtoupper($alias), ['ASC', 'DESC', 'NULL', 'END', 'THEN', 'ELSE', 'BY'], true)) {
                    continue;
                }

                if (in_array(strtoupper($alias), $reserved, true)) {
                    $offenders[] = "{$frag['file']}:{$frag['line']} — alias '{$alias}' in: " . trim($p);
                }
            }
        }

        $this->assertSame([], $offenders,
            "raw SQL uses a reserved word as an alias, which is a syntax error on this server:\n  "
            . implode("\n  ", $offenders)
            . "\nQuote it with backticks or rename it (e.g. `rows` -> row_count).");
    }

    /**
     * Columns in this schema whose names are reserved words must never appear
     * unquoted in raw SQL. GROUP BY / ORDER BY are syntax, not identifiers.
     */
    public function test_no_raw_sql_uses_a_reserved_column_name_unquoted(): void
    {
        $reserved = $this->reserved();

        $cols = array_map(
            // aliased explicitly: information_schema returns COLUMN_NAME upper
            // cased on this server and PHP property access is case-sensitive
            fn ($r) => strtoupper($r->col),
            DB::select('SELECT DISTINCT column_name AS col FROM information_schema.columns WHERE table_schema = ?',
                [DB::getDatabaseName()])
        );
        $reservedCols = array_values(array_intersect($cols, $reserved));

        if ($reservedCols === []) {
            $this->markTestSkipped('no column in this schema is named with a reserved word');
        }

        $syntaxPair = ['GROUP' => 'BY', 'ORDER' => 'BY', 'PARTITION' => 'BY'];
        $offenders = [];

        foreach ($this->rawSqlFragments() as $frag) {
            // already-quoted and string-literal sections are safe
            $stripped = preg_replace('/`[^`]*`/', ' ', $frag['sql']);
            $stripped = preg_replace("/'[^']*'/", ' ', (string) $stripped);

            preg_match_all('/[A-Za-z_][A-Za-z0-9_]*|\S/', (string) $stripped, $mm);
            $toks = $mm[0];

            foreach ($toks as $i => $tok) {
                $u = strtoupper($tok);
                if (!in_array($u, $reservedCols, true)) {
                    continue;
                }
                $next = strtoupper($toks[$i + 1] ?? '');
                if (($syntaxPair[$u] ?? null) === $next) {
                    continue;
                }
                $offenders[] = "{$frag['file']}:{$frag['line']} — '{$tok}' in: " . trim($frag['sql']);
            }
        }

        $this->assertSame([], $offenders,
            "raw SQL references a reserved column name without backticks:\n  " . implode("\n  ", $offenders));
    }

    /**
     * The reason the 15 reserved-named columns are safe today: the query builder
     * quotes identifiers. If that ever stopped being true the audit's scope
     * assumption would be wrong, so it is asserted rather than believed.
     */
    public function test_the_query_builder_quotes_identifiers(): void
    {
        $sql = DB::table('platform_settings')->select('key', 'group')->toSql();

        $this->assertStringContainsString('`key`', $sql);
        $this->assertStringContainsString('`group`', $sql);
        $this->assertStringContainsString('`platform_settings`', $sql);

        // and it actually executes against a reserved-word column
        $this->assertIsInt(DB::table('platform_settings')->whereNotNull('key')->count());
    }

    /** The failure mode itself, proven rather than assumed. */
    public function test_an_unquoted_reserved_alias_really_is_rejected(): void
    {
        $threw = false;
        try {
            DB::select('SELECT COUNT(*) rows FROM platform_settings');
        } catch (\Throwable) {
            $threw = true;
        }

        $this->assertTrue($threw, 'this server accepted a reserved word as a bare alias — the audit premise is wrong');

        // the sanctioned forms work
        $this->assertIsArray(DB::select('SELECT COUNT(*) AS row_count FROM platform_settings'));
        $this->assertIsArray(DB::select('SELECT COUNT(*) `rows` FROM platform_settings'));
    }
}
