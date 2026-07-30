<?php

namespace App\Core\Engineer888\Repository;

/**
 * Decides what each changed file IS.
 *
 * "174 files modified" is a measurement. "this is a complete feature, that is an
 * abandoned experiment, those four are shell redirection accidents" is a
 * decision, and it is the decision the engineer actually needs.
 *
 * Every file receives exactly one PRIMARY classification, chosen by the first
 * matching rule in a fixed precedence order, plus any number of FLAGS. Primary
 * classification answers "which commit does this belong to"; flags answer "what
 * else should I know". A file can be a complete feature AND contain a TODO.
 *
 * PRECEDENCE, and why this order:
 *   merge-artifact      never intentional; blocks everything
 *   temporary-artifact  accidents (redirection debris, cookie jars) — delete, do not commit
 *   backup-artifact     copies of real files; committing them is always wrong
 *   generated           machine output; belongs to a build, not a commit
 *   documentation       no runtime risk, groups separately
 *   debugging           debug output in app/ runs in production
 *   abandoned (by name) "spike", "-old", "draft" state the author's own verdict
 *   partial             an unfinished marker is direct evidence of work in flight
 *   abandoned (orphan)  nothing references it — circumstantial, so it ranks below
 *   architectural       reaches beyond its own subsystem
 *   complete            nothing above applied
 *
 * The two abandoned rules are deliberately split around `partial`. The first
 * version ranked all abandonment above partial, on the reasoning that "evidence
 * it was left behind outweighs evidence it works" — which was wrong, and a test
 * caught it. A brand-new class carrying a TODO is unreferenced precisely BECAUSE
 * it is unfinished; calling it abandoned tells the engineer to delete work that
 * is in progress. A filename that says "spike" is different: that is the author
 * stating the verdict themselves, so it still outranks the marker.
 */
final class FileClassifier
{
    public const COMPLETE      = 'complete-feature';
    public const PARTIAL       = 'partial-feature';
    public const ABANDONED     = 'abandoned-experiment';
    public const DEBUGGING     = 'temporary-debugging';
    public const GENERATED     = 'generated';
    public const BACKUP        = 'backup-artifact';
    public const TEMPORARY     = 'temporary-artifact';
    public const MERGE         = 'merge-artifact';
    public const ARCHITECTURAL = 'architectural-change';
    public const DOCUMENTATION = 'documentation';

    /** Bytes of a file to read for content evidence. Enough for markers. */
    private const READ_LIMIT = 262144;

    public function __construct(
        private string $repoPath,
        private ArchitectureMap $map,
    ) {}

    /**
     * @param  array<string,mixed>  $file  a WorkingTree record
     * @return array{classification:string, confidence:string, reason:string, flags:array<int,string>, evidence:array<int,string>}
     */
    public function classify(array $file, ?DependencyGraph $graph = null): array
    {
        $path = $file['path'];
        $layer = $this->map->layer($path);
        $base = basename($path);
        $flags = [];
        $evidence = [];

        $content = $this->readContent($file);

        // ── merge artifact ───────────────────────────────────────────────
        if ($content !== null && preg_match('/^(<{7}|>{7}) /m', $content)) {
            return $this->result(self::MERGE, 'high',
                'contains unresolved conflict markers', $flags,
                ['conflict marker found in file body']);
        }

        // ── temporary artifact ───────────────────────────────────────────
        // Zero-byte files whose names contain shell metacharacters are the
        // residue of a mistyped pipe or redirect. Four exist in this repository:
        // `cron|-`, `daily|-`, `everyM|-`, `hourly`.
        if ($file['exists'] && $file['size'] === 0 && ! str_contains($path, '/')
            && preg_match('/[|<>&$]/', $base)) {
            return $this->result(self::TEMPORARY, 'high',
                'zero-byte root file with shell metacharacters in its name — created by a mistyped redirect',
                $flags, ['size 0 bytes', 'name contains a shell metacharacter']);
        }

        if ($content !== null && str_starts_with(ltrim($content), '# Netscape HTTP Cookie File')) {
            $flags[] = 'may-contain-session-token';

            return $this->result(self::TEMPORARY, 'high',
                'a curl cookie jar left in the repository — may carry a live session token',
                $flags, ['first line identifies a Netscape cookie file']);
        }

        if ($file['exists'] && $file['size'] === 0 && ! in_array($layer, ['documentation'], true)) {
            return $this->result(self::TEMPORARY, 'medium',
                'empty file — nothing to commit', $flags, ['size 0 bytes']);
        }

        // ── backup artifact ──────────────────────────────────────────────
        if (preg_match('/(\.bak|\.backup|\.orig|\.old|~$|\.candidate\.|\.before-|-backup-|\.save$|\.tmp$)/i', $base)) {
            return $this->result(self::BACKUP, 'high',
                'a copy of another file kept alongside it', $flags,
                ['filename marks it as a copy']);
        }

        // ── generated ────────────────────────────────────────────────────
        if (preg_match('#^(public/build/|public/hot|storage/)#', $path)
            || preg_match('/\.(min\.js|min\.css|map)$/', $base)
            || in_array($base, ['composer.lock', 'package-lock.json', 'yarn.lock'], true)) {
            return $this->result(self::GENERATED, 'high',
                'build or dependency output, not authored source', $flags,
                ['path or name identifies generated output']);
        }

        // ── documentation ────────────────────────────────────────────────
        if ($layer === 'documentation') {
            $isReport = (bool) preg_match('/(REPORT|AUDIT|REGISTER|SPECIFICATION|MASTERPLAN|BRIEF|POLICY|ADR|CATALOG|PLAN)/i', $base);
            if ($isReport) { $flags[] = 'engineering-record'; }

            return $this->result(self::DOCUMENTATION, 'high',
                $isReport ? 'an engineering record — no runtime effect' : 'documentation only',
                $flags, ['markdown file']);
        }

        // ── content-derived flags, shared by the remaining classifications ─
        $unfinished = [];
        $debug = [];
        $commented = false;
        $isSource = in_array($file['extension'], ['php', 'js', 'jsx', 'ts', 'tsx', 'vue', 'css'], true);

        if ($content !== null && $isSource) {
            if ($file['extension'] === 'php') {
                // Tokenized. A regex here produced two false positives on the
                // first live run: RiskScanner.php reported itself as containing
                // dd() because the string "dd()" appears in its own description,
                // and FileClassifier.php reported itself as unfinished because
                // the word TODO appears in the pattern that looks for TODO.
                // Structural facts come from the tokenizer, not from matching
                // text that may be inside a string or a comment.
                $php = $this->scanPhp($content);
                $unfinished = $php['unfinished'];
                $debug = $php['debug'];
                $commented = $php['commented_block'];
            } else {
                $unfinished = $this->findUnfinishedByLine($content);
                $debug = $this->findDebugByLine($content);
                $commented = $this->hasCommentedOutCodeByLine($content);
            }
        }

        if ($unfinished !== []) { $flags[] = 'unfinished-markers'; }
        if ($debug !== []) { $flags[] = 'debug-statements'; }
        if ($commented) { $flags[] = 'commented-out-code'; }
        if ($file['deleted']) { $flags[] = 'deletion'; }

        // ── temporary debugging ──────────────────────────────────────────
        // Debug output left in application code executes in production.
        if ($debug !== [] && str_starts_with($path, 'app/')) {
            return $this->result(self::DEBUGGING, 'high',
                'debug output left in application code — this runs in production', $flags,
                array_slice($debug, 0, 3));
        }

        // ── abandoned experiment ─────────────────────────────────────────
        $abandonedName = (bool) preg_match('/(spike|experiment|scratch|playground|-v[0-9]$|_old$|-old$|-copy|draft)/i',
            pathinfo($path, PATHINFO_FILENAME));
        if ($abandonedName) {
            return $this->result(self::ABANDONED, 'medium',
                'named as an experiment or superseded copy', $flags,
                ['filename signals a spike or superseded version']);
        }

        // ── partial feature ──────────────────────────────────────────────
        // Ranked above the orphan rule below: see the precedence note on the
        // class. An unfinished marker means someone is mid-flight.
        if ($unfinished !== []) {
            return $this->result(self::PARTIAL, 'high',
                'contains explicit unfinished markers', $flags, array_slice($unfinished, 0, 3));
        }

        // An untracked class nothing references, in a layer the framework does
        // not call directly, has never been wired in.
        if ($graph !== null && $file['untracked'] && $file['extension'] === 'php'
            && ! $this->map->isFrameworkEntryPoint($layer)
            && $graph->declaredBy($path) !== [] && $graph->isUnreferenced($path)) {
            return $this->result(self::ABANDONED, 'medium',
                'a new class that nothing in the repository references — never wired in', $flags,
                ['zero inbound references', 'layer ' . $layer . ' is not invoked by the framework']);
        }

        // ── architectural change ─────────────────────────────────────────
        // These alter how the whole application is wired, so they carry blast
        // radius regardless of how small the diff is.
        if (in_array($layer, ['routing', 'config', 'bootstrap', 'provider', 'middleware'], true)) {
            $governed = str_starts_with($path, 'routes/');
            if ($governed) { $flags[] = 'governed-path'; }

            return $this->result(self::ARCHITECTURAL, 'high',
                'changes how the application is wired, not what one feature does', $flags,
                array_values(array_filter([
                    'layer ' . $layer,
                    $governed ? 'under the routes governance lock — must be written through GovernedWriter' : null,
                ])));
        }
        if ($layer === 'migration') {
            $flags[] = 'schema-change';

            return $this->result(self::ARCHITECTURAL, 'high',
                'a schema change — other work may depend on it existing first', $flags,
                ['database migration']);
        }

        // ── complete ─────────────────────────────────────────────────────
        return $this->result(self::COMPLETE, $file['untracked'] ? 'medium' : 'high',
            'no unfinished markers, artifacts or architectural reach', $flags,
            ['layer ' . $layer]);
    }

    /**
     * Tokenized scan of a PHP source file.
     *
     * Unfinished markers are read ONLY from comment tokens, because that is where
     * a TODO lives; a TODO inside a string is either data or, as here, a pattern
     * that looks for TODOs. Debug calls are read ONLY from real call sites: a
     * T_STRING naming a debug function, followed by `(`, not preceded by `->`,
     * `::`, `$` or `function`.
     *
     * @return array{unfinished:array<int,string>, debug:array<int,string>, commented_block:bool}
     */
    private function scanPhp(string $content): array
    {
        $unfinished = [];
        $debug = [];
        $commentedBlock = false;

        try {
            $tokens = @token_get_all($content);
        } catch (\Throwable) {
            return ['unfinished' => [], 'debug' => [], 'commented_block' => false];
        }
        if (! is_array($tokens)) {
            return ['unfinished' => [], 'debug' => [], 'commented_block' => false];
        }

        // Debug output that runs. error_log() is deliberately NOT here: it writes
        // to the error log, which is a legitimate if crude thing to do, and
        // flagging it as production-breaking would be false.
        $debugFunctions = ['dd', 'dump', 'var_dump', 'print_r', 'ray', 'dodump'];

        $commentRun = 0;
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (! is_array($token)) { $commentRun = 0; continue; }
            [$id, $text, $line] = [$token[0], $token[1], $token[2] ?? 0];

            if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
                $marker = $this->unfinishedMarker($text);
                if ($marker !== null) {
                    $unfinished[] = 'line ' . $line . ': ' . $marker . ' marker';
                }
                // Consecutive comment lines that read as statements.
                foreach (preg_split('/\r\n|\n|\r/', $text) ?: [] as $commentLine) {
                    $trimmed = ltrim($commentLine, "/#* \t");
                    if (preg_match('/[;{}]\s*$|\$\w+\s*=|->\w+\(|::\w+\(/', $trimmed)) {
                        $commentRun++;
                        if ($commentRun >= 3) { $commentedBlock = true; }
                    } elseif (trim($trimmed) !== '') {
                        $commentRun = 0;
                    }
                }
                continue;
            }

            if ($id === T_WHITESPACE) { continue; }
            $commentRun = 0;

            if ($id !== T_STRING || ! in_array(strtolower($text), $debugFunctions, true)) { continue; }

            // Must be a call.
            $next = $this->significant($tokens, $i + 1);
            if ($next === null || $tokens[$next] !== '(') { continue; }

            // Must not be a method, a static call, a declaration or a variable.
            $prev = $this->significantBackwards($tokens, $i - 1);
            if ($prev !== null) {
                $p = $tokens[$prev];
                if (is_array($p) && in_array($p[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST], true)) { continue; }
                if (defined('T_NULLSAFE_OBJECT_OPERATOR') && is_array($p) && $p[0] === T_NULLSAFE_OBJECT_OPERATOR) { continue; }
                if (is_array($p) && $p[0] === T_STRING) { continue; }   // part of a qualified name
            }

            $debug[] = 'line ' . $line . ': ' . $text . '()';
            if (count($debug) >= 10) { break; }
        }

        if (count($unfinished) > 20) { $unfinished = array_slice($unfinished, 0, 20); }

        return ['unfinished' => $unfinished, 'debug' => $debug, 'commented_block' => $commentedBlock];
    }

    /**
     * A TODO marker, or null if the word merely appears in prose.
     *
     * The marker convention is positional: it opens the comment line, optionally
     * followed by `:`, `-`, or `(owner)`. `// TODO: wire this up` is a marker.
     * "…reported it as unfinished because the word TODO appears in the pattern
     * that searches for TODO" is a sentence about markers, and counting it typed
     * this engine's own commit as `wip`. Detecting the convention rather than
     * the word is what separates the two.
     */
    private function unfinishedMarker(string $comment): ?string
    {
        foreach (preg_split('/\r\n|\n|\r/', $comment) ?: [] as $line) {
            // Strip the comment punctuation that opens the line.
            $stripped = preg_replace('#^\s*(//+|\#+|/\*+|\*+)\s*#', '', $line) ?? $line;

            // Every form must OPEN the line, including the docblock tag. An
            // earlier version accepted "@todo" anywhere, which matched this very
            // file's comment describing the docblock form.
            if (preg_match('/^@(todo|fixme)\b/i', $stripped, $m)) {
                return strtoupper($m[1]);
            }
            if (preg_match('/^(TODO|FIXME|HACK|XXX)\b\s*[:\-(]?/i', $stripped, $m)) {
                return strtoupper($m[1]);
            }
            if (preg_match('/^NOT[ _]IMPLEMENTED\b/i', $stripped)) {
                return 'NOT IMPLEMENTED';
            }
        }

        return null;
    }

    private function significant(array $tokens, int $i): ?int
    {
        $count = count($tokens);
        for (; $i < $count; $i++) {
            $t = $tokens[$i];
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { continue; }

            return $i;
        }

        return null;
    }

    private function significantBackwards(array $tokens, int $i): ?int
    {
        for (; $i >= 0; $i--) {
            $t = $tokens[$i];
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { continue; }

            return $i;
        }

        return null;
    }

    /** @return array<int,string> line-based fallback for non-PHP source */
    private function findUnfinishedByLine(string $content): array
    {
        $found = [];
        foreach (preg_split('/\r\n|\n|\r/', $content) ?: [] as $n => $line) {
            if (preg_match('/\b(TODO|FIXME|HACK|XXX)\b/i', $line, $m)) {
                $found[] = 'line ' . ($n + 1) . ': ' . strtoupper($m[1]);
            }
            if (count($found) >= 20) { break; }
        }

        return $found;
    }

    /** @return array<int,string> line-based fallback for non-PHP source */
    private function findDebugByLine(string $content): array
    {
        $found = [];
        foreach (preg_split('/\r\n|\n|\r/', $content) ?: [] as $n => $line) {
            $trimmed = ltrim($line);
            if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '#')) { continue; }
            // Remove quoted spans so a debug call named inside a string does not match.
            $stripped = preg_replace('/([\'"`])(?:\\\\.|(?!\1).)*\1/', '""', $line) ?? $line;
            if (preg_match('/(?<![a-zA-Z_.])(debugger|console\.(?:log|debug|trace))\s*[\(;]/', $stripped, $m)) {
                $found[] = 'line ' . ($n + 1) . ': ' . $m[1];
            }
            if (count($found) >= 10) { break; }
        }

        return $found;
    }

    /**
     * Three or more consecutive comment lines that look like statements rather
     * than prose. Two is a note; a block is code someone could not delete.
     */
    private function hasCommentedOutCodeByLine(string $content): bool
    {
        $run = 0;
        foreach (preg_split('/\r\n|\n|\r/', $content) ?: [] as $line) {
            $trimmed = ltrim($line);
            $isComment = str_starts_with($trimmed, '//') || str_starts_with($trimmed, '#');
            $looksLikeCode = $isComment && preg_match('/[;{}]\s*$|\$\w+\s*=|->\w+\(|::\w+\(/', $trimmed);
            if ($looksLikeCode) {
                $run++;
                if ($run >= 3) { return true; }
            } else {
                $run = 0;
            }
        }

        return false;
    }

    private function readContent(array $file): ?string
    {
        if (! $file['exists'] || $file['size'] === 0) {
            return $file['exists'] ? '' : null;
        }
        if ($file['size'] > self::READ_LIMIT * 8) { return null; }   // very large: skip
        $full = $this->repoPath . '/' . $file['path'];
        $raw = @file_get_contents($full, false, null, 0, self::READ_LIMIT);
        if ($raw === false) { return null; }
        // Binary content produces meaningless matches.
        if (str_contains(substr($raw, 0, 4096), "\0")) { return null; }

        return $raw;
    }

    private function result(string $classification, string $confidence, string $reason, array $flags, array $evidence): array
    {
        sort($flags);

        return [
            'classification' => $classification,
            'confidence'     => $confidence,
            'reason'         => $reason,
            'flags'          => array_values(array_unique($flags)),
            'evidence'       => array_values($evidence),
        ];
    }
}
