<?php

namespace App\Core\Engineer888\Repository;

/**
 * Maps a path to its architectural layer and its feature subsystem.
 *
 * TWO DIFFERENT QUESTIONS, DELIBERATELY SEPARATED
 * "What layer is this?" (controller, migration, view) is answered by position in
 * the Laravel tree. "What feature is this part of?" is not — AdsSmokeCommand.php
 * and 2026_07_27_190000_create_ad_taxonomy_table.php and
 * public/ads-preview/index.html sit in three unrelated directories and are one
 * piece of work. Commit grouping needs the second answer, so this class
 * computes it explicitly instead of grouping by directory and calling it done.
 *
 * The subsystem vocabulary is DISCOVERED from the repository rather than
 * hardcoded, so a subsystem added tomorrow is understood without an edit here.
 */
final class ArchitectureMap
{
    /** Directories whose immediate children name a subsystem. */
    private const SUBSYSTEM_ROOTS = [
        'app/Core', 'app/Engines', 'app/Services', 'app/Connectors',
        'tests/Feature', 'routes/api', 'resources/js/pages',
    ];

    /**
     * Directory names that describe a LAYER or a SURFACE rather than a feature.
     *
     * This list is why `routes/api/authenticated/agents-01.php` is attributed to
     * Agent and not to a subsystem called "authenticated". Without it, thirteen
     * route files belonging to Chat, Ads and Agents were grouped together under
     * the name of the middleware group they happen to sit behind.
     */
    private const LAYER_DIRECTORIES = [
        'Api', 'Admin', 'admin', 'Widget', 'Services', 'Support', 'Null',
        'Contracts', 'Types', 'Subscribers', 'authenticated', 'public',
        'internal', 'webhooks', 'Http', 'Console',
    ];

    /** @var array<int,string> discovered subsystem names, longest first */
    private array $vocabulary = [];

    /** @var array<string,string> lowercase name => canonical display spelling */
    private array $canonicalNames = [];

    public function __construct(private string $repoPath)
    {
        $this->vocabulary = $this->discoverVocabulary();
        // Seed canonical spellings from the tree so directory names win over
        // anything later derived from a filename keyword.
        foreach ($this->vocabulary as $name) {
            $this->canonicalNames[strtolower($name)] ??= $name;
        }
    }

    /** @return array<int,string> */
    public function vocabulary(): array
    {
        return $this->vocabulary;
    }

    /**
     * Architectural layer. Ordered most specific first — app/Http/Controllers
     * must be tested before app/Http.
     */
    public function layer(string $path): string
    {
        $rules = [
            'app/Http/Controllers/'  => 'controller',
            'app/Http/Middleware/'   => 'middleware',
            'app/Http/Requests/'     => 'request',
            'app/Http/'              => 'http',
            'app/Console/Commands/'  => 'command',
            'app/Console/'           => 'console',
            'app/Jobs/'              => 'job',
            'app/Models/'            => 'model',
            'app/Policies/'          => 'policy',
            'app/Providers/'         => 'provider',
            'app/Events/'            => 'event',
            'app/Listeners/'         => 'listener',
            'app/Connectors/'        => 'connector',
            'app/Core/'              => 'core',
            'app/Engines/'           => 'engine',
            'app/Services/'          => 'service',
            'app/Support/'           => 'support',
            'app/Exceptions/'        => 'exception',
            'database/migrations/'   => 'migration',
            'database/seeders/'      => 'seeder',
            'database/factories/'    => 'factory',
            'tests/'                 => 'test',
            'routes/'                => 'routing',
            'config/'                => 'config',
            'resources/views/'       => 'view',
            'resources/js/'          => 'frontend',
            'resources/css/'         => 'frontend',
            'public/'                => 'public-asset',
            'bootstrap/'             => 'bootstrap',
            'storage/'               => 'storage',
            'tools/'                 => 'tooling',
            'contracts/'             => 'contract',
        ];

        foreach ($rules as $prefix => $layer) {
            if (str_starts_with($path, $prefix)) {
                // A JS file under public/ is frontend code, not a static asset.
                if ($layer === 'public-asset' && str_ends_with($path, '.js')) { return 'frontend'; }

                return $layer;
            }
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === 'md') { return 'documentation'; }
        if ($ext === 'xml' && str_contains($path, 'phpunit')) { return 'test-config'; }
        if (! str_contains($path, '/')) {
            return $ext === 'php' ? 'root-script' : 'root-file';
        }

        return 'other';
    }

    /**
     * Layers whose classes are invoked by the framework, not by other classes.
     * Zero inbound references is normal for these and must not read as orphaned.
     */
    public function isFrameworkEntryPoint(string $layer): bool
    {
        return in_array($layer, [
            'command', 'controller', 'middleware', 'migration', 'test', 'job',
            'policy', 'provider', 'event', 'listener', 'seeder', 'factory',
            'request', 'console',
        ], true);
    }

    /**
     * Which feature this file belongs to. Cascade, most reliable evidence first;
     * returns 'unattributed' rather than guessing when nothing matches, because a
     * wrong grouping is worse than an admitted gap.
     *
     * @return array{subsystem:string, basis:string}
     */
    public function subsystem(string $path): array
    {
        $result = $this->attribute($path);

        // Canonicalise casing. `admin` discovered from a directory and `Admin`
        // derived from a filename keyword are one subsystem, and leaving both
        // would split a single feature across two commits.
        $result['subsystem'] = $this->canonical($result['subsystem']);

        return $result;
    }

    /**
     * One display spelling per subsystem, case-insensitively. Vocabulary
     * discovered from directories wins, because that is what the tree calls it.
     */
    private function canonical(string $name): string
    {
        $key = strtolower($name);
        if (isset($this->canonicalNames[$key])) { return $this->canonicalNames[$key]; }
        $this->canonicalNames[$key] = $name;

        return $name;
    }

    /** @return array{subsystem:string, basis:string} */
    private function attribute(string $path): array
    {
        // 1. Position under a subsystem root is definitive.
        foreach (self::SUBSYSTEM_ROOTS as $root) {
            if (str_starts_with($path, $root . '/')) {
                $rest = substr($path, strlen($root) + 1);
                $first = explode('/', $rest)[0];
                // Only a directory names a subsystem; a bare file does not, and a
                // layer or surface directory names neither.
                if ($first !== '' && $first !== $rest && ! in_array($first, self::LAYER_DIRECTORIES, true)) {
                    return ['subsystem' => $first, 'basis' => 'directory under ' . $root];
                }
            }
        }

        $base = pathinfo($path, PATHINFO_FILENAME);

        // 2. Class-name prefix against the discovered vocabulary.
        foreach ($this->vocabulary as $name) {
            if (stripos($base, $name) === 0) {
                return ['subsystem' => $name, 'basis' => 'class name begins with ' . $name];
            }
        }

        // 3. Migration filenames name their tables; tables name their feature.
        if (str_starts_with($path, 'database/migrations/')) {
            $sub = $this->fromTableName($base);
            if ($sub !== null) {
                return ['subsystem' => $sub, 'basis' => 'migration table name'];
            }
        }

        // 4. Any vocabulary term appearing in the path, singular or plural.
        $lower = strtolower($path);
        foreach ($this->vocabulary as $name) {
            $n = strtolower($name);
            foreach ([$n, rtrim($n, 's'), $n . 's'] as $variant) {
                if (strlen($variant) >= 4 && str_contains($lower, $variant)) {
                    return ['subsystem' => $name, 'basis' => 'path contains ' . $variant];
                }
            }
        }

        // 5. Documentation and config name their subject in the filename.
        $sub = $this->fromTableName(strtolower(str_replace(['-', '.'], '_', $base)));
        if ($sub !== null) {
            return ['subsystem' => $sub, 'basis' => 'filename keyword'];
        }

        // 6. Last resort — a keyword anywhere in the path. This is what catches
        // resources/views/admin/pages/*.blade.php and public/marketing/*, which
        // sit under surface directories rather than named feature directories.
        $sub = $this->fromTableName(strtolower(str_replace(['-', '.', '/'], '_', $path)));
        if ($sub !== null) {
            return ['subsystem' => $sub, 'basis' => 'path keyword'];
        }

        return ['subsystem' => 'unattributed', 'basis' => 'no evidence'];
    }

    /**
     * Table/keyword to subsystem. Only mappings that are actually true of this
     * repository — a generic dictionary would invent attributions.
     */
    private function fromTableName(string $base): ?string
    {
        $map = [
            'engineering_snapshot' => 'Engineer888',
            'platform_event'       => 'PlatformEvents',
            'domain_'              => 'Domains',
            'customer_domain'      => 'Domains',
            'registrar'            => 'Domains',
            'namecheap'            => 'Namecheap',
            'ad_'                  => 'Ads',
            'ads_'                 => 'Ads',
            'advertis'             => 'Ads',
            'interstitial'         => 'Ads',
            'chat_'                => 'Chat',
            'chatbot'              => 'Chat',
            'message_charge'       => 'Chat',
            'creative_'            => 'Creative',
            'studio'               => 'Studio',
            'governance'           => 'Governance',
            'mfa'                  => 'Governance',
            'api_usage_log'        => 'AiProvenance',
            'ai_provenance'        => 'AiProvenance',
            'provenance'           => 'AiProvenance',
            'admin_page'           => 'Admin',
            'infra'                => 'Infrastructure',
            'hosting'              => 'Infrastructure',
            // Surface keywords. Reached only after everything more specific has
            // failed, so a file that names a real subsystem is never captured here.
            'marketing'            => 'Marketing',
            'admin'                => 'Admin',
        ];

        foreach ($map as $needle => $subsystem) {
            if (str_contains($base, $needle)) { return $subsystem; }
        }

        return null;
    }

    /**
     * Learn the subsystem names present in the tree. Longest first so "Domains"
     * is preferred over "Domain" when both would match.
     *
     * @return array<int,string>
     */
    private function discoverVocabulary(): array
    {
        $names = [];
        foreach (self::SUBSYSTEM_ROOTS as $root) {
            $dir = $this->repoPath . '/' . $root;
            if (! is_dir($dir)) { continue; }
            foreach ((array) @scandir($dir) as $entry) {
                if ($entry === '.' || $entry === '..') { continue; }
                if (! is_dir($dir . '/' . $entry)) { continue; }
                if (in_array($entry, self::LAYER_DIRECTORIES, true)) { continue; }
                $names[$entry] = true;
            }
        }

        $names = array_keys($names);
        usort($names, fn ($a, $b) => strlen($b) <=> strlen($a) ?: strcmp($a, $b));

        return $names;
    }
}
