<?php

namespace App\Core\Engineer888\Migration;

/**
 * How reversible is this migration, really?
 *
 * Everything Engineer888 writes is now recoverable except schema, and the
 * honest position is that some schema changes are not recoverable at all. This
 * classifier says which — from the migration's own tokens, not from its name —
 * and refuses to guess.
 *
 * The point is not to block migrations. It is that a task should say, BEFORE a
 * human approves it, whether the thing they are approving can be undone.
 */
final class MigrationClassifier
{
    public const REVERSIBLE_AUTOMATIC = 'REVERSIBLE_AUTOMATIC';
    public const REVERSIBLE_WITH_DATA_RISK = 'REVERSIBLE_WITH_DATA_RISK';
    public const IRREVERSIBLE = 'IRREVERSIBLE';
    public const UNKNOWN = 'UNKNOWN';

    /** Operations whose reversal cannot return the data that was in them. */
    public const DESTRUCTIVE = [
        'dropColumn', 'dropTable', 'drop', 'dropIfExists', 'truncate', 'delete',
        'dropForeign', 'dropPrimary', 'dropSoftDeletes',
    ];

    /** Operations that reverse cleanly. */
    public const REVERSIBLE = ['create', 'addColumn', 'index', 'unique', 'foreign', 'string', 'integer'];

    /** Anything that reaches outside the database cannot be rolled back at all. */
    public const EXTERNAL_SIDE_EFFECTS = ['Http::', 'Storage::', 'Mail::', 'Queue::', 'dispatch(', 'exec('];

    /**
     * @return array<string,mixed>
     */
    public function classify(string $source, string $path): array
    {
        $up = $this->methodBody($source, 'up');
        $down = $this->methodBody($source, 'down');

        $evidence = [];
        $tables = $this->tables($up);

        if ($up === null) {
            return $this->result(self::UNKNOWN, $path, $tables,
                ['no up() method could be located, so nothing about this migration can be proved'], []);
        }

        if ($down === null || trim($down) === '' || $this->isEmptyBody($down)) {
            return $this->result(self::IRREVERSIBLE, $path, $tables,
                ['down() is absent or empty, so this migration cannot be reversed by the framework'],
                $this->externalEffects($up));
        }

        $external = $this->externalEffects($up);
        if ($external !== []) {
            return $this->result(self::IRREVERSIBLE, $path, $tables,
                ['up() has effects outside the database (' . implode(', ', $external)
                 . '), which a schema rollback cannot undo'], $external);
        }

        $destructiveUp = $this->destructiveCalls($up);

        if ($destructiveUp !== []) {
            // A down() that recreates the structure does not recreate the rows.
            $evidence[] = 'up() performs ' . implode(', ', $destructiveUp)
                        . '; down() can restore the structure but not the data that was in it';

            return $this->result(self::REVERSIBLE_WITH_DATA_RISK, $path, $tables, $evidence, []);
        }

        if ($this->hasDataTransformation($up)) {
            return $this->result(self::REVERSIBLE_WITH_DATA_RISK, $path, $tables,
                ['up() transforms existing rows, and a schema down() does not restore their prior values'], []);
        }

        $evidence[] = 'up() is additive and down() reverses it structurally';

        return $this->result(self::REVERSIBLE_AUTOMATIC, $path, $tables, $evidence, []);
    }

    /** @return array<int,string> */
    private function destructiveCalls(string $body): array
    {
        $found = [];
        foreach (self::DESTRUCTIVE as $call) {
            if (preg_match('/->\s*' . preg_quote($call, '/') . '\s*\(/', $body)
                || preg_match('/Schema::\s*' . preg_quote($call, '/') . '\s*\(/', $body)) {
                $found[] = $call;
            }
        }

        return array_values(array_unique($found));
    }

    /** @return array<int,string> */
    private function externalEffects(string $body): array
    {
        $found = [];
        foreach (self::EXTERNAL_SIDE_EFFECTS as $effect) {
            if (str_contains($body, $effect)) { $found[] = rtrim($effect, '(:'); }
        }

        return array_values(array_unique($found));
    }

    private function hasDataTransformation(string $body): bool
    {
        return (bool) preg_match('/DB::table\([^)]*\)->(update|insert|delete)\s*\(/', $body);
    }

    /** @return array<int,string> */
    private function tables(?string $body): array
    {
        if ($body === null) { return []; }

        preg_match_all('/Schema::\w+\(\s*[\'"]([a-zA-Z0-9_]+)[\'"]/', $body, $schema);
        preg_match_all('/DB::table\(\s*[\'"]([a-zA-Z0-9_]+)[\'"]/', $body, $db);

        return array_values(array_unique(array_merge($schema[1] ?? [], $db[1] ?? [])));
    }

    /**
     * The body of a named method, by brace counting over tokens.
     *
     * Regex over `function up() { ... }` stops at the first closing brace, which
     * is almost never the right one — a Schema::create closure alone contains
     * two.
     */
    private function methodBody(string $source, string $name): ?string
    {
        $tokens = @token_get_all($source);
        if (! is_array($tokens)) { return null; }

        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) { continue; }

            $found = null;
            for ($j = $i + 1; $j < $count && $j < $i + 5; $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) { $found = $tokens[$j][1]; break; }
            }
            if ($found !== $name) { continue; }

            $depth = 0;
            $body = '';
            $started = false;

            for ($j = $i; $j < $count; $j++) {
                $piece = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];

                if ($piece === '{') { $depth++; $started = true; }
                if ($started) { $body .= $piece; }
                if ($piece === '}') {
                    $depth--;
                    if ($depth === 0) { return $body; }
                }
            }

            return $body;
        }

        return null;
    }

    private function isEmptyBody(string $body): bool
    {
        $stripped = trim(preg_replace('/[\s{}]|\/\/.*|\/\*.*?\*\//s', '', $body) ?? '');

        return $stripped === '' || $stripped === ';';
    }

    /** @return array<string,mixed> */
    private function result(string $classification, string $path, array $tables, array $evidence, array $external): array
    {
        return [
            'path'              => $path,
            'classification'    => $classification,
            'tables'            => $tables,
            'evidence'          => $evidence,
            'external_effects'  => $external,
            'automatic_rollback' => $classification === self::REVERSIBLE_AUTOMATIC,
            'requires_backup'   => in_array($classification,
                [self::REVERSIBLE_WITH_DATA_RISK, self::IRREVERSIBLE, self::UNKNOWN], true),
        ];
    }
}
