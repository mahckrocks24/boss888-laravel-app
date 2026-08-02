<?php

namespace App\Core\Engineer888\Coordination;

/**
 * A sprint's declaration of what it owns.
 *
 * WHY THIS EXISTS
 * Repository Intelligence answers "which files belong together technically". On
 * 2026-07-31 that produced a commit containing 33 documents, at least 9 of which
 * were written by the PlatformEvents engineer. The grouping was correct and the
 * commit was still wrong, because coherence and ownership are different
 * questions and only the first was being asked.
 *
 * The manifest answers the second. It is a plain JSON file, versioned with the
 * code, listing what this sprint may touch. It is deliberately not a lock
 * service: it constrains execution, it does not arbitrate between engineers.
 *
 * FAIL CLOSED. A path that matches nothing is UNKNOWN, and UNKNOWN is never
 * staged. Guessing is what produced the coordination debt in the first place.
 */
final class OwnershipManifest
{
    public const OWNED = 'OWNED';
    public const SHARED_DECLARED = 'SHARED_DECLARED';
    public const EXCLUDED = 'EXCLUDED';
    public const UNKNOWN = 'UNKNOWN';

    /** Where manifests live. One file per sprint. */
    public const DIRECTORY = '.engineer888/sprints';

    private function __construct(
        private array $data,
        private string $path,
    ) {}

    /**
     * The manifest governing the current process, or null.
     *
     * Resolution order, most explicit first:
     *   1. E888_SPRINT_MANIFEST — set by a phpunit config or the environment.
     *   2. Exactly one manifest in the directory.
     * Two or more manifests with no explicit selection is AMBIGUOUS and returns
     * null, so callers that require a manifest fail closed rather than picking.
     */
    public static function active(?string $repoPath = null): ?self
    {
        $root = rtrim($repoPath ?? base_path(), '/');

        $explicit = getenv('E888_SPRINT_MANIFEST') ?: ($_SERVER['E888_SPRINT_MANIFEST'] ?? null);
        if (is_string($explicit) && $explicit !== '') {
            $full = str_starts_with($explicit, '/') ? $explicit : $root . '/' . $explicit;

            return self::load($full);
        }

        $found = glob($root . '/' . self::DIRECTORY . '/*.json') ?: [];
        if (count($found) === 1) { return self::load($found[0]); }

        return null;   // none, or ambiguous
    }

    public static function load(string $path): ?self
    {
        if (! is_file($path)) { return null; }
        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data)) { return null; }

        return new self($data, $path);
    }

    public function path(): string { return $this->path; }

    public function engineer(): string { return (string) ($this->data['engineer'] ?? 'unknown'); }

    public function session(): string { return (string) ($this->data['session'] ?? 'unknown'); }

    public function sprint(): string { return (string) ($this->data['sprint'] ?? 'unknown'); }

    public function testDatabase(): ?string
    {
        $value = $this->data['test_database'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function phpunitConfig(): ?string
    {
        $value = $this->data['phpunit_config'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return array<int,array<string,mixed>> */
    public function sharedFiles(): array
    {
        return array_values(array_filter((array) ($this->data['shared_files'] ?? []), 'is_array'));
    }

    /**
     * Ownership of one repository-relative path.
     *
     * Exclusions are checked first: an explicit "not mine" always beats a broad
     * ownership glob, because that is the only way to carve an exception out of
     * a directory this sprint otherwise owns.
     *
     * @return array{status:string, evidence:string}
     */
    public function classify(string $path): array
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');

        foreach ((array) ($this->data['exclusions'] ?? []) as $pattern) {
            if ($this->matches($path, (string) $pattern)) {
                return ['status' => self::EXCLUDED, 'evidence' => 'explicitly excluded by "' . $pattern . '"'];
            }
        }

        foreach ($this->sharedFiles() as $shared) {
            if (($shared['path'] ?? null) === $path) {
                return [
                    'status' => self::SHARED_DECLARED,
                    'evidence' => 'declared shared edit — ' . ($shared['reason'] ?? 'no reason recorded'),
                ];
            }
        }

        foreach ((array) ($this->data['owned_files'] ?? []) as $file) {
            if ((string) $file === $path) {
                return ['status' => self::OWNED, 'evidence' => 'listed in owned_files'];
            }
        }

        foreach ((array) ($this->data['owned_paths'] ?? []) as $pattern) {
            if ($this->matches($path, (string) $pattern)) {
                return ['status' => self::OWNED, 'evidence' => 'matches owned_paths "' . $pattern . '"'];
            }
        }

        return [
            'status' => self::UNKNOWN,
            'evidence' => 'no ownership evidence — not owned, not declared shared, not excluded',
        ];
    }

    /** True only for statuses that may be staged. */
    public static function isCommittable(string $status): bool
    {
        return $status === self::OWNED || $status === self::SHARED_DECLARED;
    }

    /**
     * Glob matching with `**` meaning "any depth".
     *
     * fnmatch() alone treats `*` as not crossing `/`, which makes
     * `app/Core/Engineer888/**` fail to match a nested file — the exact shape
     * every ownership rule here uses.
     */
    private function matches(string $path, string $pattern): bool
    {
        $pattern = ltrim(str_replace('\\', '/', $pattern), '/');

        if ($pattern === $path) { return true; }

        $regex = preg_quote($pattern, '#');
        $regex = str_replace('\*\*/', '(?:.*/)?', $regex);   // `**/` — zero or more directories
        $regex = str_replace('\*\*', '.*', $regex);          // `**`  — anything, including `/`
        $regex = str_replace('\*', '[^/]*', $regex);         // `*`   — within one segment
        $regex = str_replace('\?', '.', $regex);

        return (bool) preg_match('#^' . $regex . '$#', $path);
    }
}
