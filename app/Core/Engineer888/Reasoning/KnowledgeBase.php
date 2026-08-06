<?php

namespace App\Core\Engineer888\Reasoning;

use App\Core\Engineer888\Coordination\GovernedFiles;
use App\Core\Engineer888\Coordination\OwnershipManifest;
use Illuminate\Support\Facades\DB;

/**
 * What the company knows, per project.
 *
 * Every source here is something Engineer888 already records as a by-product of
 * doing the work: assets promoted with evidence, stages that actually failed,
 * tasks that actually completed, the incident register, the ownership manifest.
 * Nothing is authored for the model's benefit, which is the point — a knowledge
 * base written to impress a reasoning provider would drift from reality within
 * a sprint.
 *
 * PROVEN ONLY. An asset with no evidence_task_id was never demonstrated by an
 * implementation, so it is withheld and the exclusion is recorded. Suggesting an
 * unproven reusable component is how a fabricated library ends up in a plan.
 */
final class KnowledgeBase
{
    /** Asset kinds, and the context section each one feeds. */
    public const ASSET_SECTIONS = [
        'recipe'            => 'recipes',
        'scaffold'          => 'scaffolds',
        'coding_standard'   => 'coding_standards',
        'regression-test'   => 'regression_patterns',
        'regression_test'   => 'regression_patterns',
        'deployment_pattern' => 'deployment_patterns',
        'security_pattern'  => 'security_patterns',
        'playbook'          => 'operational_playbooks',
        'decision'          => 'decisions',
        'failure_pattern'   => 'failure_patterns',
        'incident-pattern'  => 'failure_patterns',
        'package_candidate' => 'reusable_packages',
        'check'             => 'checks',
    ];

    public function __construct(private readonly string $repoPath) {}

    /**
     * Promoted assets visible to this project.
     *
     * Visibility is project_id === $projectId (this project's own) or NULL
     * (company-wide). An asset promoted by another project is not offered here:
     * project isolation is the difference between "the company learned this" and
     * "some other codebase did this once".
     *
     * @return array{items:array<int,array<string,mixed>>,excluded:array<int,array<string,string>>}
     */
    public function assets(int $projectId): array
    {
        $items = [];
        $excluded = [];

        $rows = DB::table('engineering_assets')->orderBy('id')->get();

        foreach ($rows as $row) {
            $ownProject = $row->project_id ?? null;

            if ($ownProject !== null && (int) $ownProject !== $projectId) {
                $excluded[] = ['source' => 'assets', 'item' => $row->kind . '/' . $row->name,
                               'reason' => 'belongs to another project'];
                continue;
            }

            if ($row->evidence_task_id === null && ($row->evidence === null || trim((string) $row->evidence) === '')) {
                $excluded[] = ['source' => 'assets', 'item' => $row->kind . '/' . $row->name,
                               'reason' => 'no implementation evidence; only proven assets may be suggested'];
                continue;
            }

            $items[] = [
                'section' => self::ASSET_SECTIONS[$row->kind] ?? 'assets',
                'label'   => $row->name,
                'body'    => trim((string) $row->summary . ' — evidence: ' . (string) $row->evidence),
                'kind'    => $row->kind,
                'path'    => $row->artifact_path,
            ];
        }

        return ['items' => $items, 'excluded' => $excluded];
    }

    /**
     * The failure register: stages that actually failed, on this project.
     *
     * A real failure history beats a curated one. These rows were written by
     * runs that halted, not by anybody deciding afterwards what was worth
     * remembering.
     *
     * @return array<int,array<string,mixed>>
     */
    public function failureHistory(int $projectId, int $limit = 40): array
    {
        $rows = DB::table('engineering_task_stages as s')
            ->join('engineering_tasks as t', 't.id', '=', 's.task_id')
            ->where('t.project_id', $projectId)
            ->whereIn('s.status', ['failed', 'blocked'])
            ->orderByDesc('s.id')
            ->limit($limit)
            ->get(['s.stage', 's.summary', 's.failure', 't.title', 't.uuid']);

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'section' => 'failure_history',
                'label'   => $row->stage . ' halted on "' . $row->title . '"',
                'body'    => trim((string) $row->summary . ' — ' . (string) $row->failure),
            ];
        }

        return $items;
    }

    /**
     * Tasks that completed on this project, as precedent.
     *
     * @return array<int,array<string,mixed>>
     */
    public function taskHistory(int $projectId, int $limit = 30): array
    {
        $rows = DB::table('engineering_tasks')
            ->where('project_id', $projectId)
            ->where('status', 'completed')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['title', 'description', 'kind', 'change_set']);

        $items = [];
        foreach ($rows as $row) {
            $files = array_keys(json_decode((string) $row->change_set, true) ?: []);
            $items[] = [
                'section' => 'task_history',
                'label'   => $row->title,
                'body'    => substr((string) $row->description, 0, 300)
                           . ($files !== [] ? ' [files: ' . implode(', ', array_slice($files, 0, 5)) . ']' : ''),
            ];
        }

        return $items;
    }

    /**
     * The incident register, parsed leniently.
     *
     * This is the real artifact the platform keeps — append-only, written at the
     * time, by whoever was holding the incident. Parsing it rather than
     * duplicating it means the reasoning engine reads the same history a human
     * engineer would.
     *
     * @return array<int,array<string,mixed>>
     */
    public function incidents(): array
    {
        $path = $this->repoPath . '/INCIDENT-REGISTER.md';
        if (! is_file($path)) { return []; }

        $text = (string) file_get_contents($path);
        $blocks = preg_split('/^##+\s+/m', $text) ?: [];

        $items = [];
        foreach ($blocks as $block) {
            $block = trim($block);
            if ($block === '') { continue; }

            $lines = explode("\n", $block);
            $heading = trim(array_shift($lines) ?? '');
            if ($heading === '' || ! preg_match('/INC-|incident/i', $heading)) { continue; }

            $items[] = [
                'section' => 'incidents',
                'label'   => $heading,
                'body'    => substr(trim(implode(' ', array_slice($lines, 0, 8))), 0, 400),
            ];
        }

        return $items;
    }

    /**
     * Ownership boundaries — what this engineer may and may not touch.
     *
     * Sent to the provider deliberately. A proposal that names a file belonging
     * to another engineer will be refused at IDENTIFY_FILES anyway, but a model
     * told the boundary in advance produces a proposal that can actually be
     * executed rather than one that halts.
     *
     * @return array<int,array<string,mixed>>
     */
    public function ownershipBoundaries(): array
    {
        $manifest = OwnershipManifest::active($this->repoPath);
        if ($manifest === null) {
            return [[
                'section' => 'ownership_boundaries',
                'label'   => 'no active sprint manifest',
                'body'    => 'nothing can be proved owned, so no file may be written by this task',
            ]];
        }

        $items = [[
            'section' => 'ownership_boundaries',
            'label'   => 'sprint ' . $manifest->sprint(),
            'body'    => 'engineer ' . $manifest->engineer() . ', session ' . $manifest->session()
                       . '. Only paths this manifest owns may appear in file_changes.',
        ]];

        foreach ($manifest->sharedFiles() as $shared) {
            $items[] = [
                'section' => 'ownership_boundaries',
                'label'   => 'SHARED: ' . ($shared['path'] ?? ''),
                'body'    => 'policy ' . ($shared['policy'] ?? 'ANNOUNCE BEFORE EDIT')
                           . ' — ' . substr((string) ($shared['reason'] ?? ''), 0, 200),
            ];
        }

        return $items;
    }

    /**
     * The public API of classes the task actually names.
     *
     * ADDED AFTER OBSERVING THE FAILURE IT PREVENTS. Two different providers,
     * given the same context, both produced structurally valid proposals that
     * invented a method signature — and both declared that invention as their
     * first unknown ("what is the exact signature of X?"). They were told what
     * the company knows and never told what the code says.
     *
     * Signatures only, never bodies. The question a proposal needs answered is
     * "what may I call", and sending implementations would spend the whole
     * context budget answering a question nobody asked.
     *
     * Parsed with the tokeniser rather than reflection: nothing is loaded, so
     * building context cannot execute code as a side effect.
     *
     * @return array<int,array<string,mixed>>
     */
    public function codeSurface(string $taskText, int $maxClasses = 8): array
    {
        preg_match_all('/\b([A-Z][A-Za-z0-9]{3,})\b/', $taskText, $matches);
        $names = array_values(array_unique($matches[1] ?? []));
        if ($names === []) { return []; }

        $index = $this->classIndex();
        $items = [];
        $seen = [];
        $queue = $names;

        // ONE HOP, AND ONLY ONE. A task that names ProviderRegistry needs the
        // interface ProviderRegistry hands back, because that is where the
        // methods it will actually call live — observed directly: a proposal
        // used ProviderRegistry::fromConfig() correctly and then invented three
        // method names on the object it returned.
        //
        // Stopping at one hop is not laziness. Transitive closure over a Laravel
        // application reaches the framework within three steps and would spend
        // the entire context budget describing Illuminate.
        while ($queue !== []) {
            $name = array_shift($queue);
            if (isset($seen[$name])) { continue; }
            $seen[$name] = true;

            if (count($items) >= $maxClasses) { break; }
            if (! isset($index[$name])) { continue; }

            $surface = $this->surfaceOf($index[$name]);
            if ($surface === null) { continue; }

            $items[] = [
                'section' => 'code_surface',
                'label'   => $surface['fqcn'],
                'body'    => $surface['signatures'],
            ];

            // Only a class the TASK named expands. Anything reached by a hop is
            // a leaf, which is what makes this one hop rather than a crawl.
            if (! in_array($name, $names, true)) { continue; }

            // Types named in this class's own signatures, queued once.
            preg_match_all('/\b([A-Z][A-Za-z0-9]{3,})\b/', $surface['signatures'], $referenced);
            foreach (array_unique($referenced[1] ?? []) as $related) {
                if (! isset($seen[$related]) && isset($index[$related])) { $queue[] = $related; }
            }
        }

        return $items;
    }

    /**
     * Basename => absolute path, for PHP classes under app/.
     *
     * @return array<string,string>
     */
    private function classIndex(): array
    {
        $root = $this->repoPath . '/app';
        if (! is_dir($root)) { return []; }

        $index = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $entry) {
            if (! $entry->isFile() || $entry->getExtension() !== 'php') { continue; }
            // First one wins. A duplicated basename across namespaces is rare and
            // guessing between them would be worse than offering one.
            $index[$entry->getBasename('.php')] ??= $entry->getPathname();
        }

        return $index;
    }

    /** @return array{fqcn:string,signatures:string}|null */
    private function surfaceOf(string $path): ?array
    {
        $source = @file_get_contents($path);
        if ($source === false) { return null; }

        $tokens = token_get_all($source);
        $namespace = '';
        $class = '';
        $signatures = [];
        $constants = [];

        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            $token = $tokens[$i];
            if (! is_array($token)) { continue; }

            if ($token[0] === T_NAMESPACE) {
                for ($j = $i + 1; $j < $n; $j++) {
                    if ($tokens[$j] === ';' || $tokens[$j] === '{') { break; }
                    if (is_array($tokens[$j]) && $tokens[$j][0] !== T_WHITESPACE) {
                        $namespace .= $tokens[$j][1];
                    }
                }
            }

            if (in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true) && $class === '') {
                for ($j = $i + 1; $j < $n; $j++) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) { $class = $tokens[$j][1]; break; }
                }
            }

            // public const NAME = ...
            if ($token[0] === T_PUBLIC || $token[0] === T_CONST) {
                $isPublicConst = false;
                for ($j = $i + 1; $j < $n && $j < $i + 6; $j++) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) { continue; }
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_CONST) { $isPublicConst = true; }
                    break;
                }
                if ($token[0] === T_CONST || $isPublicConst) {
                    for ($j = $i; $j < $n && $j < $i + 8; $j++) {
                        if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                            $constants[] = 'const ' . $tokens[$j][1];
                            break;
                        }
                    }
                }
            }

            if ($token[0] !== T_FUNCTION) { continue; }

            // Visibility is whatever modifier precedes it. Anything not public
            // is none of a proposal's business.
            $visibility = 'public';
            for ($j = $i - 1; $j >= 0 && $j > $i - 8; $j--) {
                if (! is_array($tokens[$j])) { break; }
                if ($tokens[$j][0] === T_WHITESPACE) { continue; }
                if (in_array($tokens[$j][0], [T_PRIVATE, T_PROTECTED], true)) { $visibility = 'hidden'; break; }
                if (in_array($tokens[$j][0], [T_PUBLIC, T_STATIC, T_ABSTRACT, T_FINAL], true)) { continue; }
                break;
            }
            if ($visibility !== 'public') { continue; }

            $signature = '';
            $depth = 0;
            for ($j = $i; $j < $n; $j++) {
                $piece = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
                if ($piece === '(') { $depth++; }
                if ($piece === ')') { $depth--; }
                if (($piece === '{' || $piece === ';') && $depth <= 0) { break; }
                $signature .= is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE ? ' ' : $piece;
            }

            $signature = trim(preg_replace('/\s+/', ' ', $signature));
            if ($signature !== '') { $signatures[] = $signature; }
        }

        if ($class === '') { return null; }

        $lines = array_merge(array_slice(array_unique($constants), 0, 8), array_slice($signatures, 0, 20));

        return [
            'fqcn'       => ($namespace !== '' ? $namespace . '\\' : '') . $class,
            'signatures' => implode('; ', $lines),
        ];
    }

    /**
     * Test and deployment constraints that a proposal must respect.
     *
     * @return array<int,array<string,mixed>>
     */
    public function executionConstraints(object $project): array
    {
        $items = [];

        $items[] = [
            'section' => 'test_constraints',
            'label'   => 'test configuration',
            'body'    => 'suite runs with ' . ($project->phpunit_config ?: 'the project default config')
                       . ' against ' . ($project->test_database ?: 'an unassigned database')
                       . '. A test run must never resolve to a production database (INC-2026-006).',
        ];

        $items[] = [
            'section' => 'deployment_constraints',
            'label'   => 'deployment model',
            'body'    => 'the repository working tree IS the running environment; there is no separate '
                       . 'build or release step, so an installed file is live immediately after VERIFY.',
        ];

        foreach (GovernedFiles::all() as $path => $policy) {
            $items[] = [
                'section' => 'governed_files',
                'label'   => $path,
                'body'    => (string) ($policy['policy'] ?? 'governed')
                           . ' — affects ' . (string) ($policy['affects'] ?? 'other engineers'),
            ];
        }

        return $items;
    }
}
