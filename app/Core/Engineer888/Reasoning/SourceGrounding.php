<?php

namespace App\Core\Engineer888\Reasoning;

use App\Core\Engineer888\Coordination\OwnershipManifest;

/**
 * The exact bytes of the files a proposal intends to rewrite.
 *
 * WHY THIS EXISTS. CandidateImplementation's contract asks for "the complete
 * file content, not a diff", and until now the model was never shown any file
 * content. It received class surfaces — signatures and constants — and was then
 * asked to reproduce a 13KB file it had never seen. On 2026-08-07 two real runs
 * ended the same way: gpt-4o gave up on the impossible half of the instruction
 * and put a sentence describing the change into the `content` field, which
 * CandidateValidator correctly rejected as not_php. The validator was right.
 * The request was impossible.
 *
 * So this class answers one question: which of the files this proposal names
 * may safely be read, and what are their exact bytes right now.
 *
 * IT VERIFIES BEFORE IT READS. A path arrives from a language model, which
 * means it is a suggestion, not a fact. Every path must resolve inside the
 * project's own repository, must exist, must not escape by traversal or
 * symlink, must not be secret-bearing, and must not be excluded by the active
 * ownership manifest. A path that fails any of those is reported as refused,
 * with the reason, and no context is built for it. Inventing context for a
 * fictional file would teach the model that its invention was real.
 *
 * IT NEVER TRUNCATES A TARGET. Half a file plus an instruction to return the
 * whole file is the same impossible contract in a smaller costume. When the
 * sources do not fit, this reports SOURCE_CONTEXT_BUDGET_EXCEEDED and the
 * workflow blocks truthfully instead.
 */
final class SourceGrounding
{
    public const BUDGET_EXCEEDED = 'SOURCE_CONTEXT_BUDGET_EXCEEDED';

    /** The context section grounded sources are filed under. Structural. */
    public const SECTION = 'grounded_source';

    /**
     * Room kept for everything that is not target source: the task, the output
     * contract, ownership boundaries and the other structural sections. Source
     * gets the rest, and gets it before any summary does.
     */
    private const RESERVE_BYTES = 14000;

    /** Files whose contents must never reach a model, matched on the path. */
    private const FORBIDDEN = [
        '/^\.env/', '/(^|\/)\.env/', '/\.pem$/', '/\.key$/', '/\.p12$/', '/\.pfx$/',
        '/(^|\/)keys?\//', '/(^|\/)secrets?\//', '/(^|\/)credentials?/i',
        '/id_rsa/', '/id_ed25519/', '/\.htpasswd$/', '/auth\.json$/',
    ];

    public function __construct(
        private readonly string $repoPath,
        private readonly int $maxBytes = 60000,
    ) {}

    /**
     * Build context items for the files a proposal says it will change.
     *
     * Only create|update entries are grounded: `read` and `none` describe files
     * the proposal consults, not files it must reproduce byte for byte.
     *
     * @param  array $filesAffected the proposal's own files_affected list
     * @return array{items:array,grounded:array,refused:array,budget_exceeded:bool,bytes:int}
     */
    public function forFilesAffected(array $filesAffected): array
    {
        $wanted = [];

        foreach ($filesAffected as $entry) {
            if (! is_array($entry)) { continue; }

            $action = strtolower(trim((string) ($entry['action'] ?? '')));
            if (! in_array($action, ['create', 'update'], true)) { continue; }

            $path = trim((string) ($entry['path'] ?? ''));
            if ($path === '') { continue; }

            $wanted[$path] = $action;
        }

        return $this->forPaths($wanted);
    }

    /**
     * @param  array<string,string> $paths path => action
     * @return array{items:array,grounded:array,refused:array,budget_exceeded:bool,bytes:int}
     */
    public function forPaths(array $paths): array
    {
        $items = [];
        $grounded = [];
        $refused = [];
        $bytes = 0;
        $budget = max(0, $this->maxBytes - self::RESERVE_BYTES);
        $exceeded = false;

        foreach ($paths as $path => $action) {
            $verdict = $this->verify($path, $action);

            if ($verdict['ok'] === false) {
                $refused[] = ['path' => $path, 'reason' => $verdict['reason']];
                continue;
            }

            // A file the proposal will CREATE has no current bytes. Saying so
            // is grounding too: it tells the model it is writing something new
            // rather than reconstructing something it cannot see.
            if ($verdict['exists'] === false) {
                $items[] = [
                    'section' => self::SECTION,
                    'label'   => 'FILE ' . $path . ' — does not exist yet (action: create)',
                    'body'    => 'COMPLETE: true (empty). Propose the entire contents of this new file.',
                ];
                $grounded[] = ['path' => $path, 'sha256' => null, 'bytes' => 0, 'exists' => false];
                continue;
            }

            $source = (string) file_get_contents($verdict['real']);
            $length = strlen($source);

            if ($bytes + $length > $budget) {
                // Reported, never trimmed. The contract asks for a complete
                // file; a partially supplied file cannot honestly produce one.
                $exceeded = true;
                $refused[] = ['path' => $path, 'reason' => self::BUDGET_EXCEEDED
                    . ': this source is ' . $length . ' bytes and only '
                    . max(0, $budget - $bytes) . ' bytes of source budget remain'];
                continue;
            }

            $bytes += $length;

            $items[] = [
                'section' => self::SECTION,
                'label'   => 'FILE ' . $path . ' — sha256 ' . hash('sha256', $source)
                           . ' — ' . $length . ' bytes — COMPLETE: true',
                // Hashed and rendered exactly as read. No normalisation: the
                // approval contract fingerprints bytes, so anything this layer
                // "tidied" would be a different file from the one on disk.
                'body'    => "\n<<<SOURCE\n" . $source . "\nSOURCE\n",
            ];

            $grounded[] = ['path' => $path, 'sha256' => hash('sha256', $source),
                           'bytes' => $length, 'exists' => true];
        }

        return ['items' => $items, 'grounded' => $grounded, 'refused' => $refused,
                'budget_exceeded' => $exceeded, 'bytes' => $bytes];
    }

    /**
     * May this path be read, and does it exist?
     *
     * @return array{ok:bool,reason:?string,real:?string,exists:bool}
     */
    private function verify(string $path, string $action): array
    {
        $refuse = fn (string $why) => ['ok' => false, 'reason' => $why, 'real' => null, 'exists' => false];

        if ($path !== ltrim($path, '/')) {
            return $refuse('absolute paths are not accepted; paths are relative to the repository root');
        }

        if (str_contains($path, "\0")) {
            return $refuse('path contains a null byte');
        }

        // Rejected on the declared path, before any resolution: a traversal
        // that happens to land inside the repo is still a proposal that tried.
        if (preg_match('#(^|/)\.\.(/|$)#', $path)) {
            return $refuse('path traversal is not accepted');
        }

        foreach (self::FORBIDDEN as $pattern) {
            if (preg_match($pattern, $path)) {
                return $refuse('secret-bearing or prohibited path; its contents are never sent to a model');
            }
        }

        $root = realpath($this->repoPath);
        if ($root === false) {
            return $refuse('the project repository path does not resolve');
        }

        $full = $root . '/' . $path;
        $real = realpath($full);

        if ($real === false) {
            // Nonexistent is a fact, and for `create` it is the expected one.
            if ($action === 'create') {
                $parent = realpath(dirname($full));
                if ($parent === false || ! str_starts_with($parent . '/', $root . '/')) {
                    return $refuse('the directory for this new file is outside the project');
                }

                return ['ok' => true, 'reason' => null, 'real' => null, 'exists' => false];
            }

            return $refuse('this file does not exist in the repository');
        }

        // Resolved, so symlinks are already followed: an escape shows up here.
        if (! str_starts_with($real . '/', $root . '/')) {
            return $refuse('resolves outside the project repository');
        }

        if (! is_file($real)) {
            return $refuse('not a regular file');
        }

        $relative = substr($real, strlen($root) + 1);

        foreach (self::FORBIDDEN as $pattern) {
            if (preg_match($pattern, $relative)) {
                return $refuse('resolves to a secret-bearing or prohibited path');
            }
        }

        // The manifest constrains what this sprint may touch. A file it
        // explicitly excludes is not context for a proposal to rewrite it.
        $manifest = OwnershipManifest::active($this->repoPath);

        if ($manifest !== null && method_exists($manifest, 'classify')
            && $manifest->classify($relative) === OwnershipManifest::EXCLUDED) {
            return $refuse('the active ownership manifest excludes this path');
        }

        return ['ok' => true, 'reason' => null, 'real' => $real, 'exists' => true];
    }
    /** The context section the verified test inventory is filed under. */
    public const TESTS_SECTION = 'verified_tests';

    /**
     * The tests that genuinely exist and genuinely relate to these targets.
     *
     * WHY THIS EXISTS. Grounding the source stopped the model inventing file
     * contents; it kept inventing coverage. On 2026-08-07 a structurally
     * perfect candidate was rejected because it claimed
     * tests/Feature/Engineer888/ValidationTest.php already covered the change.
     * No such file exists. CandidateValidator's invented_coverage rule caught
     * it, correctly — the rule was never the problem, the evidence was.
     *
     * So the model is handed the real list and told it may cite nothing else.
     * Selection is deterministic: a test is relevant when it mentions the
     * target's class name. No scoring, no guessing, and never the whole suite.
     *
     * @param  array<int,string> $targetPaths verified implementation targets
     * @return array{items:array,tests:array}
     */
    public function testInventoryFor(array $targetPaths, int $limit = 12): array
    {
        $root = realpath($this->repoPath);
        if ($root === false || ! is_dir($root . '/tests')) {
            return ['items' => [], 'tests' => []];
        }

        $needles = [];
        foreach ($targetPaths as $p) {
            $base = pathinfo((string) $p, PATHINFO_FILENAME);
            if ($base !== '') { $needles[] = $base; }
        }
        if ($needles === []) { return ['items' => [], 'tests' => []]; }

        $tests = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/tests', \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $file) {
            if (count($tests) >= $limit) { break; }
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) { continue; }

            $real = realpath($file->getPathname());
            if ($real === false || ! str_starts_with($real, $root . '/tests/')) { continue; }

            $body = @file_get_contents($real);
            if ($body === false) { continue; }

            foreach ($needles as $needle) {
                if (str_contains($body, $needle)) {
                    $tests[] = substr($real, strlen($root) + 1);
                    break;
                }
            }
        }

        sort($tests);

        if ($tests === []) { return ['items' => [], 'tests' => []]; }

        return [
            'tests' => $tests,
            'items' => [[
                'section' => self::TESTS_SECTION,
                'label'   => 'VERIFIED EXISTING TESTS (' . count($tests) . ')',
                'body'    => "\n- " . implode("\n- ", $tests),
            ]],
        ];
    }

    /**
     * Does this path name a test file that really exists here?
     *
     * The same containment rules as source: inside the repository, inside
     * tests/, a real file, no traversal, no symlink escape.
     */
    public function isVerifiedTestPath(string $path): bool
    {
        if ($path === '' || $path !== ltrim($path, '/')) { return false; }
        if (preg_match('#(^|/)\.\.(/|$)#', $path)) { return false; }
        if (! str_starts_with($path, 'tests/')) { return false; }

        $root = realpath($this->repoPath);
        if ($root === false) { return false; }

        $real = realpath($root . '/' . $path);

        return $real !== false
            && str_starts_with($real, $root . '/tests/')
            && is_file($real);
    }
}
