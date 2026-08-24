<?php

namespace App\Core\Engineer888\Audit;

/**
 * One way to walk this repository, with the readability rules stated.
 *
 * Three separate walkers assumed every path was readable. That is true as root
 * — which is every engineer's terminal — and false as the application user,
 * which is what actually executes a workflow. On 2026-08-02 the difference
 * turned an approved, installed change into a halted task with the file left in
 * place, because the safety audit produced no output at all and its `2>/dev/null`
 * hid the reason.
 *
 * So readability is now a first-class outcome rather than an accident:
 *
 *   REQUIRED  unreadable → the scan is INCOMPLETE and callers must fail
 *   EXCLUDED  unreadable → recorded, never a failure; it was not going to be read
 *   OPTIONAL  unreadable → warning, with the path named
 *
 * A scan that could not read a required path may not report "0 unsafe". Silence
 * about what was skipped is the failure mode this class exists to remove.
 */
final class TreeScan
{
    public const REQUIRED = 'REQUIRED';
    public const EXCLUDED = 'EXCLUDED';
    public const OPTIONAL = 'OPTIONAL';

    /**
     * Directories that are never source and are not scanned.
     *
     * Runtime state, dependencies, build output and git internals. An
     * unreadable directory here is a fact about the deployment, not a defect.
     */
    public const EXCLUDED_FRAGMENTS = [
        '/vendor/', '/node_modules/', '/.git/', '/storage/', '/public/build/',
        '/_backup/', '/bootstrap/cache/', '/.engineer888/backups/',
    ];

    /**
     * Where the platform's own code lives. Unreadable here is a hard failure:
     * a safety audit that skipped app/ has audited nothing that matters.
     */
    public const REQUIRED_ROOTS = ['app', 'routes', 'config', 'database', 'tests', 'tools'];

    /** @var array<int,array{path:string,class:string,reason:string}> */
    private array $unreadable = [];

    /** @var array<int,string> */
    private array $excludedDirs = [];

    private int $filesSeen = 0;

    public function __construct(
        private readonly string $root,
        private readonly string $extension = 'php',
    ) {}

    /**
     * Relative paths of every file of the requested type that could be read.
     *
     * @return array<int,string>
     */
    public function files(): array
    {
        $out = [];
        $root = rtrim($this->root, '/');

        $directory = new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS);

        // Pruned at DESCENT. Filtering after the iterator has opened a directory
        // is too late — opening is what throws.
        $filtered = new \RecursiveCallbackFilterIterator($directory, function ($item) use ($root) {
            if (! $item->isDir()) { return true; }

            $path = str_replace('\\', '/', $item->getPathname());
            $relative = ltrim(substr($path, strlen($root)), '/');

            if ($this->isExcluded($path . '/')) {
                $this->excludedDirs[] = $relative;

                return false;
            }

            if (! $item->isReadable()) {
                $this->unreadable[] = [
                    'path'   => $relative,
                    'class'  => $this->classify($relative),
                    'reason' => 'directory is not readable by ' . $this->currentUser(),
                ];

                return false;
            }

            return true;
        });

        $iterator = new \RecursiveIteratorIterator(
            $filtered,
            \RecursiveIteratorIterator::SELF_FIRST,
            // A directory that becomes unreadable between the check and the
            // descent is skipped, not thrown. It is already recorded above when
            // detectable; this is the race.
            \RecursiveIteratorIterator::CATCH_GET_CHILD
        );

        foreach ($iterator as $item) {
            if (! $item->isFile()) { continue; }

            $full = str_replace('\\', '/', $item->getPathname());
            if ($this->extension !== '' && ! str_ends_with($full, '.' . $this->extension)) { continue; }
            if ($this->isExcluded($full)) { continue; }

            $relative = ltrim(substr($full, strlen($root)), '/');
            $this->filesSeen++;

            if (! $item->isReadable()) {
                $this->unreadable[] = [
                    'path'   => $relative,
                    'class'  => $this->classify($relative),
                    'reason' => 'file is not readable by ' . $this->currentUser(),
                ];
                continue;
            }

            $out[] = $relative;
        }

        sort($out);

        return $out;
    }

    /** True only when every REQUIRED path was read. */
    public function isComplete(): bool
    {
        return $this->requiredUnreadable() === [];
    }

    /** @return array<int,array<string,string>> */
    public function requiredUnreadable(): array
    {
        return array_values(array_filter($this->unreadable, fn ($u) => $u['class'] === self::REQUIRED));
    }

    /** @return array<int,array<string,string>> */
    public function optionalUnreadable(): array
    {
        return array_values(array_filter($this->unreadable, fn ($u) => $u['class'] === self::OPTIONAL));
    }

    /** @return array<int,array<string,string>> */
    public function unreadable(): array
    {
        return $this->unreadable;
    }

    /** @return array<int,string> */
    public function excludedDirectories(): array
    {
        return array_values(array_unique($this->excludedDirs));
    }

    /**
     * The evidence a caller must publish alongside any verdict.
     *
     * @return array<string,mixed>
     */
    public function coverage(): array
    {
        return [
            'root'                => $this->root,
            'files_seen'          => $this->filesSeen,
            'complete'            => $this->isComplete(),
            'required_roots'      => self::REQUIRED_ROOTS,
            'excluded_dirs'       => array_slice($this->excludedDirectories(), 0, 40),
            'excluded_dir_count'  => count($this->excludedDirectories()),
            'unreadable'          => $this->unreadable,
            'required_unreadable' => $this->requiredUnreadable(),
            'scanned_by'          => $this->currentUser(),
        ];
    }

    public function classify(string $relative): string
    {
        $first = explode('/', $relative)[0] ?? '';

        if ($this->isExcluded('/' . $relative . '/')) { return self::EXCLUDED; }
        if (in_array($first, self::REQUIRED_ROOTS, true)) { return self::REQUIRED; }

        return self::OPTIONAL;
    }

    private function isExcluded(string $path): bool
    {
        foreach (self::EXCLUDED_FRAGMENTS as $fragment) {
            if (str_contains($path, $fragment)) { return true; }
        }

        return false;
    }

    private function currentUser(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $info = @posix_getpwuid(posix_geteuid());
            if (is_array($info) && isset($info['name'])) { return (string) $info['name']; }
        }

        return get_current_user() ?: 'unknown';
    }
}
