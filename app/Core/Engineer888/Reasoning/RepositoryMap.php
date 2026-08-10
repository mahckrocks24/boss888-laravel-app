<?php

namespace App\Core\Engineer888\Reasoning;

use App\Core\Engineer888\Coordination\OwnershipManifest;

/**
 * What the repository ACTUALLY is, established before anything is designed.
 *
 * WHY THIS EXISTS. On 2026-08-10 Engineer888 was asked to build a bug tracker in
 * a bare PSR-4 PHP project and proposed app/Http/Controllers/BugController.php,
 * three .blade.php views and an UPDATE to routes/web.php. None of those paths
 * exist here and no Laravel is installed. Its own recorded unknown was "what
 * specific technology stack should be used?" — it asked the question, guessed
 * Laravel, and designed against the guess.
 *
 * The cause was structural, not a bad day from the model. The provider was given
 * the repository PATH as a string and nothing else. SourceGrounding, which does
 * read real bytes, is a VERIFICATION mechanism: it proves claims about files the
 * model has already named. On a greenfield task there are no prior names, so
 * there was nothing to verify and nothing to see.
 *
 * This class is the missing half — DISCOVERY. It is deterministic, bounded, and
 * evidence-based:
 *
 *   deterministic  same tree in, same bytes out. The request is fingerprinted,
 *                  so a map that varied between calls would make approval drift
 *                  fire at random.
 *   bounded        a repository with 700 dirty files must not become 700 lines
 *                  of prompt. Structure is worth more than enumeration.
 *   evidence-based a framework is named only when a marker proves it. "PHP is
 *                  present" is not evidence of Laravel, and UNKNOWN is a better
 *                  answer than a confident wrong one.
 */
final class RepositoryMap
{
    /** No marker proved any framework. Not an error — often the truth. */
    public const UNKNOWN = 'UNKNOWN';

    /** composer PSR-4 autoloading and no framework marker: a plain PHP project. */
    public const CUSTOM_PHP = 'CUSTOM PHP PROJECT (PSR-4, no framework)';

    /** Never walked: build output, dependencies, and volatile state. */
    private const SKIP = ['.git', 'vendor', 'node_modules', 'storage', 'bootstrap/cache',
                          '.phpunit.cache', '.idea', '.vscode', 'dist', 'build', 'coverage', '.engineer888'];

    private const MAX_TOP_LEVEL   = 40;
    private const MAX_CHILDREN    = 12;
    private const MAX_ENTRY_FILES = 12;

    private function __construct(
        public readonly string $repositoryPath,
        public readonly string $framework,
        /** @var array<int,string> the markers that proved it, or why nothing did */
        public readonly array $frameworkEvidence,
        /** @var array<string,mixed> */
        public readonly array $identity,
        /** @var array<int,array{path:string,type:string,children:array<int,string>}> */
        public readonly array $structure,
        /** @var array<int,string> */
        public readonly array $entryPoints,
        /** @var array<string,mixed> */
        public readonly array $testing,
        /** @var array<string,mixed> */
        public readonly array $dependencies,
        /** @var array<string,mixed> */
        public readonly array $ownership,
    ) {}

    /**
     * Read the repository. Returns null when the path does not resolve, so a
     * caller can tell "no map" from "an empty map".
     */
    public static function discover(string $repositoryPath): ?self
    {
        $root = realpath($repositoryPath);
        if ($root === false || ! is_dir($root)) {
            return null;
        }

        $composer = self::readJson($root . '/composer.json');
        $package  = self::readJson($root . '/package.json');

        [$framework, $evidence] = self::classify($root, $composer, $package);

        return new self(
            $root,
            $framework,
            $evidence,
            self::identity($root),
            self::structure($root),
            self::entryPoints($root, $package),
            self::testing($root, $composer, $package),
            self::dependencies($composer, $package),
            self::ownership($root),
        );
    }

    // ── FRAMEWORK CLASSIFICATION ────────────────────────────────────────────
    //
    // Every branch requires a marker that cannot be present by accident. The
    // rule that matters is the last one: composer + PSR-4 and nothing else is
    // reported as a plain PHP project, not as "probably Laravel".

    /** @return array{0:string,1:array<int,string>} */
    private static function classify(string $root, ?array $composer, ?array $package): array
    {
        $require = array_merge(
            (array) ($composer['require'] ?? []),
            (array) ($composer['require-dev'] ?? []),
        );
        $has = static fn (string $name): bool => array_key_exists($name, $require);
        $file = static fn (string $rel): bool => file_exists($root . '/' . $rel);

        // Laravel needs BOTH a console entry point and the framework itself.
        // artisan alone is a shell script anyone can write; laravel/framework
        // alone can appear in a library that merely integrates with it.
        if ($file('artisan') && ($has('laravel/framework') || $has('illuminate/support'))) {
            $evidence = ['artisan exists'];
            if ($has('laravel/framework')) { $evidence[] = 'composer requires laravel/framework'; }
            if ($has('illuminate/support')) { $evidence[] = 'composer requires illuminate/support'; }
            if ($file('bootstrap/app.php')) { $evidence[] = 'bootstrap/app.php exists'; }
            return ['LARAVEL', $evidence];
        }

        if ($file('bin/console') && $has('symfony/framework-bundle')) {
            return ['SYMFONY', ['bin/console exists', 'composer requires symfony/framework-bundle']];
        }

        if ($has('slim/slim')) {
            return ['SLIM', ['composer requires slim/slim']];
        }

        if ($has('laminas/laminas-mvc') || $has('cakephp/cakephp') || $has('yiisoft/yii2')) {
            $named = array_values(array_filter(['laminas/laminas-mvc', 'cakephp/cakephp', 'yiisoft/yii2'], $has));
            return [strtoupper(explode('/', $named[0])[0]), ['composer requires ' . $named[0]]];
        }

        if ($package !== null && isset($package['dependencies']['next'])) {
            return ['NEXT.JS', ['package.json depends on next']];
        }

        // No framework marker. Say what IS proven rather than nothing.
        if ($composer !== null && isset($composer['autoload']['psr-4'])) {
            return [self::CUSTOM_PHP, [
                'composer.json present',
                'PSR-4 autoload: ' . implode(', ', array_map(
                    static fn ($ns, $dir) => $ns . ' => ' . (is_array($dir) ? implode('|', $dir) : $dir),
                    array_keys($composer['autoload']['psr-4']),
                    array_values($composer['autoload']['psr-4']),
                )),
                'no artisan, no bin/console, no framework dependency',
            ]];
        }

        return [self::UNKNOWN, ['no composer.json, package.json or framework marker was found']];
    }

    // ── STRUCTURE ───────────────────────────────────────────────────────────

    /** @return array<int,array{path:string,type:string,children:array<int,string>}> */
    private static function structure(string $root): array
    {
        $entries = @scandir($root) ?: [];
        sort($entries, SORT_STRING);

        $out = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || in_array($entry, self::SKIP, true)) { continue; }
            if (count($out) >= self::MAX_TOP_LEVEL) { break; }

            $full = $root . '/' . $entry;
            if (is_dir($full)) {
                $out[] = ['path' => $entry . '/', 'type' => 'dir', 'children' => self::children($full)];
            } else {
                $out[] = ['path' => $entry, 'type' => 'file', 'children' => []];
            }
        }

        return $out;
    }

    /** One level down only, sorted, capped. Enough to see shape, not contents. */
    private static function children(string $dir): array
    {
        $entries = @scandir($dir) ?: [];
        sort($entries, SORT_STRING);

        $out = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || in_array($entry, self::SKIP, true)) { continue; }
            if ($entry === '.gitkeep') { continue; }
            if (count($out) >= self::MAX_CHILDREN) { $out[] = '…'; break; }
            $out[] = is_dir($dir . '/' . $entry) ? $entry . '/' : $entry;
        }

        return $out;
    }

    private static function identity(string $root): array
    {
        return [
            'repository_path' => $root,
            'branch'          => self::gitBranch($root),
            'head'            => self::gitHead($root),
        ];
    }

    // ── ENTRY POINTS ────────────────────────────────────────────────────────

    private static function entryPoints(string $root, ?array $package): array
    {
        $out = [];
        foreach (['public/index.php', 'index.php', 'artisan', 'bin/console', 'app.php', 'server.php'] as $rel) {
            if (is_file($root . '/' . $rel)) { $out[] = $rel; }
        }

        foreach (['bin', 'scripts'] as $dir) {
            if (! is_dir($root . '/' . $dir)) { continue; }
            $files = @scandir($root . '/' . $dir) ?: [];
            sort($files, SORT_STRING);
            foreach ($files as $f) {
                if ($f === '.' || $f === '..' || is_dir($root . '/' . $dir . '/' . $f)) { continue; }
                if (count($out) >= self::MAX_ENTRY_FILES) { break 2; }
                $out[] = $dir . '/' . $f;
            }
        }

        foreach (array_keys((array) ($package['scripts'] ?? [])) as $script) {
            if (count($out) >= self::MAX_ENTRY_FILES) { break; }
            $out[] = 'npm run ' . $script;
        }

        return $out;
    }

    // ── TESTING ─────────────────────────────────────────────────────────────

    private static function testing(string $root, ?array $composer, ?array $package): array
    {
        $configs = [];
        foreach (['phpunit.xml', 'phpunit.xml.dist', 'phpunit.acceptance.xml', 'phpunit.sandbox.xml',
                  'jest.config.js', 'vitest.config.js'] as $c) {
            if (is_file($root . '/' . $c)) { $configs[] = $c; }
        }
        foreach ((@glob($root . '/phpunit.*.xml') ?: []) as $c) {
            $base = basename($c);
            if (! in_array($base, $configs, true)) { $configs[] = $base; }
        }
        sort($configs, SORT_STRING);

        $dirs = [];
        foreach (['tests', 'test', 'spec', '__tests__'] as $d) {
            if (is_dir($root . '/' . $d)) { $dirs[] = $d . '/'; }
        }

        $require = array_merge((array) ($composer['require'] ?? []), (array) ($composer['require-dev'] ?? []));
        $framework = self::UNKNOWN;
        if (array_key_exists('phpunit/phpunit', $require)) { $framework = 'PHPUnit'; }
        if (array_key_exists('pestphp/pest', $require))    { $framework = 'Pest'; }
        if (isset($package['devDependencies']['jest']))    { $framework = 'Jest'; }

        $command = self::UNKNOWN;
        if ($configs !== [] && $framework === 'PHPUnit') {
            $command = is_file($root . '/artisan')
                ? 'php artisan test -c ' . $configs[0]
                : 'vendor/bin/phpunit -c ' . $configs[0];
        }

        return ['framework' => $framework, 'configs' => $configs, 'directories' => $dirs, 'command' => $command];
    }

    private static function dependencies(?array $composer, ?array $package): array
    {
        $direct = array_keys((array) ($composer['require'] ?? []));
        $dev    = array_keys((array) ($composer['require-dev'] ?? []));
        $node   = array_keys((array) ($package['dependencies'] ?? []));
        sort($direct, SORT_STRING); sort($dev, SORT_STRING); sort($node, SORT_STRING);

        return [
            'composer_require'     => array_slice($direct, 0, 25),
            'composer_require_dev' => array_slice($dev, 0, 25),
            'node_dependencies'    => array_slice($node, 0, 25),
        ];
    }

    /**
     * The manifest's own declaration, read from the file it resolved to.
     *
     * OwnershipManifest exposes classify() rather than the raw declaration —
     * correct for enforcement, but the model needs the list itself to know where
     * it is allowed to put things before it proposes anything.
     */
    private static function ownership(string $root): array
    {
        $manifest = OwnershipManifest::active($root);
        if ($manifest === null) {
            return ['manifest' => null, 'owned_paths' => [], 'owned_files' => []];
        }

        $data = self::readJson($manifest->path()) ?? [];

        return [
            'manifest'    => $manifest->path(),
            'owned_paths' => array_values((array) ($data['owned_paths'] ?? [])),
            'owned_files' => array_values((array) ($data['owned_files'] ?? [])),
        ];
    }

    // ── RENDERING ───────────────────────────────────────────────────────────

    /**
     * The prompt block. Deterministic: no timestamps, no absolute ordering that
     * depends on the filesystem, nothing that changes between two reads of an
     * unchanged tree.
     */
    public function render(): string
    {
        $out = [];
        $out[] = '# REPOSITORY — ESTABLISHED BY DISCOVERY, NOT BY ASSUMPTION';
        $out[] = 'Everything in this section was read from the repository just now. Treat it as';
        $out[] = 'the complete truth about what exists. Anything not listed here does not exist.';
        $out[] = '';
        $out[] = 'framework: ' . $this->framework;
        foreach ($this->frameworkEvidence as $e) { $out[] = '  evidence: ' . $e; }
        $out[] = 'repository: ' . $this->identity['repository_path'];
        if (($this->identity['branch'] ?? null) !== null) { $out[] = 'branch: ' . $this->identity['branch']; }

        $out[] = '';
        $out[] = '## STRUCTURE (top level, one level deep)';
        if ($this->structure === []) { $out[] = '(the repository is empty)'; }
        foreach ($this->structure as $node) {
            $out[] = '- ' . $node['path'];
            foreach ($node['children'] as $child) { $out[] = '    ' . $child; }
        }

        $out[] = '';
        $out[] = '## ENTRY POINTS';
        $out[] = $this->entryPoints === [] ? '(none found)' : '- ' . implode("\n- ", $this->entryPoints);

        $out[] = '';
        $out[] = '## TESTING';
        $out[] = 'framework: ' . $this->testing['framework'];
        $out[] = 'configs: ' . ($this->testing['configs'] === [] ? '(none)' : implode(', ', $this->testing['configs']));
        $out[] = 'directories: ' . ($this->testing['directories'] === [] ? '(none)' : implode(', ', $this->testing['directories']));
        $out[] = 'command: ' . $this->testing['command'];

        $out[] = '';
        $out[] = '## DEPENDENCIES';
        $out[] = 'composer require: ' . ($this->dependencies['composer_require'] === []
            ? '(none)' : implode(', ', $this->dependencies['composer_require']));
        $out[] = 'composer require-dev: ' . ($this->dependencies['composer_require_dev'] === []
            ? '(none)' : implode(', ', $this->dependencies['composer_require_dev']));
        if ($this->dependencies['node_dependencies'] !== []) {
            $out[] = 'node dependencies: ' . implode(', ', $this->dependencies['node_dependencies']);
        }

        $out[] = '';
        $out[] = '## WRITEABLE AREAS (ownership)';
        $out[] = 'owned paths: ' . ($this->ownership['owned_paths'] === []
            ? '(no manifest — nothing is writeable)' : implode(', ', $this->ownership['owned_paths']));
        if ($this->ownership['owned_files'] !== []) {
            // WHETHER EACH ONE EXISTS, SAID OUT LOUD.
            //
            // Acceptance run 2026-08-11, candidate e29c33a3: the manifest lists
            // README.md under owned_files, this section printed the bare name,
            // and the provider proposed action `update` for a README that has
            // never existed. Reading "owned files: README.md" as "README.md is
            // here" is a fair reading of what this line used to say.
            //
            // Ownership answers "may I write this". It has never answered "is it
            // there", and the two must not look alike.
            $annotated = [];
            foreach ($this->ownership['owned_files'] as $file) {
                $annotated[] = $file . ($this->has($file)
                    ? ' (exists — use action update)'
                    : ' (DOES NOT EXIST YET — use action create)');
            }
            $out[] = 'owned files: ' . implode(', ', $annotated);
        }

        $out[] = '';
        $out[] = '## NO FICTIONAL INFRASTRUCTURE';
        $out[] = 'You may not build against anything the section above does not prove exists.';
        $out[] = 'If the framework is ' . self::CUSTOM_PHP . ' or ' . self::UNKNOWN . ', then there is no';
        $out[] = 'router, no ORM, no template engine, no controller base class and no dependency';
        $out[] = 'container unless you create one in this candidate.';
        $out[] = '';
        $out[] = 'DO NOT propose a path under a directory that is not listed above unless your own';
        $out[] = 'file_changes create it.';
        $out[] = 'DO NOT use action "update" for a file that is not listed above — update means the';
        $out[] = 'file already exists, and a wrong claim there is refused.';
        $out[] = 'DO NOT extend, import or call a class the repository does not contain.';
        $out[] = 'If the task genuinely needs a framework or a dependency that is not installed,';
        $out[] = 'say so in unknowns and propose the smallest architecture that works WITHOUT it,';
        $out[] = 'or state plainly that introducing the dependency is the decision to be taken.';

        return implode("\n", $out);
    }

    public function toArray(): array
    {
        return [
            'repository_path'    => $this->repositoryPath,
            'framework'          => $this->framework,
            'framework_evidence' => $this->frameworkEvidence,
            'identity'           => $this->identity,
            'structure'          => $this->structure,
            'entry_points'       => $this->entryPoints,
            'testing'            => $this->testing,
            'dependencies'       => $this->dependencies,
            'ownership'          => $this->ownership,
        ];
    }

    /** Does discovery prove this relative path exists? */
    public function has(string $relative): bool
    {
        return file_exists($this->repositoryPath . '/' . ltrim($relative, '/'));
    }

    /** @return array<int,string> top-level directory names, without the slash */
    public function topLevelDirectories(): array
    {
        $out = [];
        foreach ($this->structure as $node) {
            if ($node['type'] === 'dir') { $out[] = rtrim($node['path'], '/'); }
        }

        return $out;
    }

    // ── git, read without a shell ───────────────────────────────────────────

    private static function gitBranch(string $root): ?string
    {
        $head = self::gitHeadFile($root);
        if ($head === null) { return null; }

        return preg_match('#^ref: refs/heads/(.+)$#', trim($head), $m) === 1 ? $m[1] : null;
    }

    private static function gitHead(string $root): ?string
    {
        $head = self::gitHeadFile($root);
        if ($head === null) { return null; }
        $head = trim($head);

        if (preg_match('/^[0-9a-f]{40}$/', $head) === 1) { return substr($head, 0, 12); }

        return null;   // a symbolic ref; the branch name is the useful half
    }

    private static function gitHeadFile(string $root): ?string
    {
        $git = $root . '/.git';
        if (is_file($git)) {                       // a worktree: .git is a pointer
            $line = (string) @file_get_contents($git);
            if (preg_match('/^gitdir:\s*(.+)$/m', $line, $m) === 1) { $git = trim($m[1]); }
        }
        $head = $git . '/HEAD';

        return is_file($head) ? (string) @file_get_contents($head) : null;
    }

    private static function readJson(string $path): ?array
    {
        if (! is_file($path)) { return null; }
        $data = json_decode((string) @file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }
}
