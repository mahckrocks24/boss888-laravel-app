<?php

namespace App\Core\Engineer888\Runtime;

use RuntimeException;

/**
 * A scratch directory Engineer888 is allowed to write.
 *
 * Verification needs to write things — a rewritten phpunit config, a captured
 * log. Sprint 8's safety test wrote them next to the application, which works
 * as root and fails as the application user, and the response of making the
 * repository writable would trade one broken test for a real hazard.
 *
 * So the workspace lives under storage/app, which the application already owns,
 * is already git-ignored, and which every Engineer888 tree walk already
 * excludes. It is unique per execution, cleaned on both success and failure,
 * and old runs are pruned on open so a crashed workflow cannot accumulate.
 */
final class Workspace
{
    public const BASE = 'storage/app/engineer888/run';

    /** Anything older than this from an earlier crash is removed on open. */
    public const MAX_AGE_SECONDS = 86400;

    private function __construct(
        private readonly string $root,
        public readonly string $runId,
    ) {}

    /**
     * @param string $runId caller-supplied identity — a workflow UUID, a PID.
     * @throws RuntimeException when the workspace cannot be created
     */
    public static function open(string $repoPath, string $runId): self
    {
        $runId = self::safeSegment($runId);
        $base = rtrim($repoPath, '/') . '/' . self::BASE;

        if (! is_dir($base) && ! @mkdir($base, 0775, true) && ! is_dir($base)) {
            throw new RuntimeException(
                "cannot create the Engineer888 workspace at {$base}. Verification will not fall back to "
                . 'writing next to the application — that is the assumption this class removes.'
            );
        }

        self::prune($base);

        $root = $base . '/' . $runId;
        if (! is_dir($root) && ! @mkdir($root, 0775, true) && ! is_dir($root)) {
            throw new RuntimeException("cannot create the workspace directory {$root}");
        }

        if (! is_writable($root)) {
            throw new RuntimeException("the workspace {$root} exists but is not writable");
        }

        return new self($root, $runId);
    }

    public function path(string $name): string
    {
        return $this->root . '/' . self::safeSegment($name);
    }

    public function write(string $name, string $content): string
    {
        $path = $this->path($name);

        if (@file_put_contents($path, $content) === false) {
            throw new RuntimeException("could not write {$path}");
        }

        return $path;
    }

    public function root(): string
    {
        return $this->root;
    }

    /** Remove everything this run created. Safe to call twice. */
    public function close(): void
    {
        self::remove($this->root);
    }

    /**
     * One path segment, with no way out of the workspace.
     *
     * Names reach here from workflow identifiers and, in principle, from
     * anything a caller passes. Traversal is refused rather than sanitised
     * quietly, because a silently rewritten path is a path nobody can audit.
     */
    private static function safeSegment(string $name): string
    {
        $name = trim($name);

        if ($name === '' || str_contains($name, '/') || str_contains($name, '\\')
            || str_contains($name, '..') || str_contains($name, "\0")) {
            throw new RuntimeException("unsafe workspace segment: '{$name}'");
        }

        return $name;
    }

    private static function prune(string $base): void
    {
        foreach (@scandir($base) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') { continue; }
            $path = $base . '/' . $entry;
            if (! is_dir($path)) { continue; }
            if (time() - (int) @filemtime($path) < self::MAX_AGE_SECONDS) { continue; }
            self::remove($path);
        }
    }

    private static function remove(string $path): void
    {
        if (! is_dir($path)) { @unlink($path); return; }

        foreach (@scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') { continue; }
            self::remove($path . '/' . $entry);
        }

        @rmdir($path);
    }

    /**
     * A phpunit config copied out of the repository, with its relative paths
     * made absolute.
     *
     * PHPUnit resolves `bootstrap`, test directories and source directories
     * RELATIVE TO THE CONFIG FILE. Copying a config out of the repository
     * without rewriting them produces a config that looks right and silently
     * finds no tests, which would read as a pass.
     *
     * @param array<string,string> $replacements applied after absolutisation
     */
    public function phpunitConfigFrom(string $templatePath, string $repoPath, array $replacements = [], string $name = 'phpunit.xml'): string
    {
        $xml = @file_get_contents($templatePath);
        if ($xml === false) { throw new RuntimeException("cannot read {$templatePath}"); }

        $repo = rtrim($repoPath, '/');

        $xml = preg_replace_callback(
            '/(bootstrap|cacheDirectory)="([^"]+)"/',
            fn ($m) => $m[1] . '="' . self::absolutise($m[2], $repo) . '"',
            $xml
        );

        $xml = preg_replace_callback(
            '/(<(?:directory|file)[^>]*>)([^<]+)(<\/(?:directory|file)>)/',
            fn ($m) => $m[1] . self::absolutise(trim($m[2]), $repo) . $m[3],
            $xml
        );

        foreach ($replacements as $from => $to) {
            $xml = str_replace($from, $to, $xml);
        }

        return $this->write($name, $xml);
    }

    private static function absolutise(string $path, string $repo): string
    {
        if (str_starts_with($path, '/')) { return $path; }

        return $repo . '/' . ltrim($path, './');
    }
}
