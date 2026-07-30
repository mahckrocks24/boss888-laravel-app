<?php

namespace App\Core\Engineer888;

use App\Core\Engineer888\Repository\ArchitectureMap;
use App\Core\Engineer888\Repository\CommitPlanner;
use App\Core\Engineer888\Repository\DependencyGraph;
use App\Core\Engineer888\Repository\FileClassifier;
use App\Core\Engineer888\Repository\RiskScanner;
use App\Core\Engineer888\Repository\WorkingTree;

/**
 * Repository Intelligence.
 *
 * The Daily Brief answers "what currently exists". This answers "what should the
 * engineer do next", which requires classifying every changed file, understanding
 * what depends on what, and producing an ordered plan rather than a list.
 *
 * Deterministic throughout. No model is called: the conclusions are derived from
 * the tokenizer, git, and the database, so they are reproducible and can be
 * checked by hand. That is a requirement rather than a limitation — a plan for
 * committing production code has to be verifiable.
 */
final class RepositoryIntelligence
{
    public function __construct(private string $repoPath) {}

    /** @return array<string,mixed> */
    public function analyse(?string $onlySubsystem = null): array
    {
        $started = microtime(true);

        $tree = WorkingTree::read($this->repoPath);
        if (! $tree['available']) {
            return ['available' => false, 'reason' => $tree['reason'] ?? 'working tree unreadable'];
        }

        $map = new ArchitectureMap($this->repoPath);
        $graph = (new DependencyGraph($this->repoPath))->build();
        $classifier = new FileClassifier($this->repoPath, $map);

        $analysed = [];
        foreach ($tree['files'] as $file) {
            $subsystem = $map->subsystem($file['path']);
            if ($onlySubsystem !== null && strcasecmp($subsystem['subsystem'], $onlySubsystem) !== 0) {
                continue;
            }
            $file['layer'] = $map->layer($file['path']);
            $file['subsystem'] = $subsystem;
            $file['analysis'] = $classifier->classify($file, $graph);
            $analysed[] = $file;
        }

        $risks = (new RiskScanner($this->repoPath, $map))->scan($analysed, $graph);
        $plan = (new CommitPlanner($map))->plan($analysed, $graph);

        return [
            'available'    => true,
            'summary'      => $this->summary($tree, $analysed, $graph, $map),
            'files'        => $analysed,
            'by_classification' => $this->tally($analysed, fn ($f) => $f['analysis']['classification']),
            'by_subsystem' => $this->subsystemTally($analysed),
            'by_layer'     => $this->tally($analysed, fn ($f) => $f['layer']),
            'risks'        => $risks,
            'commit_plan'  => $plan,
            'dependencies' => $this->dependencyReport($analysed, $graph),
            'priority'     => $this->priority($risks, $plan, $analysed),
            'duration_ms'  => (int) round((microtime(true) - $started) * 1000),
            'graph_ms'     => $graph->buildMs(),
            'graph_files'  => $graph->filesScanned(),
        ];
    }

    private function summary(array $tree, array $analysed, DependencyGraph $graph, ArchitectureMap $map): array
    {
        return [
            'changed_files'       => count($analysed),
            'collapsed_entries'   => $tree['collapsed_entries'],
            'expanded_files'      => $tree['expanded_files'],
            // git status collapses untracked directories. Reporting the collapsed
            // number understates the uncommitted surface — here by 2.2x.
            'undercount_factor'   => $tree['collapsed_entries'] > 0
                ? round($tree['expanded_files'] / $tree['collapsed_entries'], 2)
                : null,
            'by_area'             => $tree['by_area'],
            'repository_php_files' => $graph->filesScanned(),
            'classes_declared'    => count($graph->declaredIn()),
            'subsystems_known'    => count($map->vocabulary()),
            'insertions'          => array_sum(array_map(fn ($f) => (int) ($f['insertions'] ?? 0), $analysed)),
            'deletions'           => array_sum(array_map(fn ($f) => (int) ($f['deletions'] ?? 0), $analysed)),
        ];
    }

    private function tally(array $analysed, callable $key): array
    {
        $out = [];
        foreach ($analysed as $file) { $k = $key($file); $out[$k] = ($out[$k] ?? 0) + 1; }
        arsort($out);

        return $out;
    }

    private function subsystemTally(array $analysed): array
    {
        $out = [];
        foreach ($analysed as $file) {
            $name = $file['subsystem']['subsystem'];
            $out[$name]['files'] = ($out[$name]['files'] ?? 0) + 1;
            $out[$name]['layers'][$file['layer']] = true;
            $out[$name]['basis'] = $file['subsystem']['basis'];
        }
        foreach ($out as $name => $data) {
            $layers = array_keys($data['layers']);
            sort($layers);
            $out[$name]['layers'] = $layers;
        }
        uasort($out, fn ($a, $b) => $b['files'] <=> $a['files']);

        return $out;
    }

    /**
     * Dependency facts for the files where they matter. Restricted to the most
     * connected files — printing an impact line for all 394 would bury the ones
     * that carry risk.
     */
    private function dependencyReport(array $analysed, DependencyGraph $graph, int $top = 12): array
    {
        $rows = [];
        foreach ($analysed as $file) {
            if ($file['extension'] !== 'php') { continue; }
            if ($graph->declaredBy($file['path']) === []) { continue; }
            $usedBy = $graph->usedBy($file['path']);
            $dependsOn = $graph->dependsOn($file['path']);
            $impact = $graph->impact($file['path']);
            $rows[] = [
                'path'           => $file['path'],
                'layer'          => $file['layer'],
                'subsystem'      => $file['subsystem']['subsystem'],
                'depends_on'     => count($dependsOn),
                'depends_on_files' => array_slice($dependsOn, 0, 5),
                'used_by'        => count($usedBy),
                'used_by_files'  => array_slice($usedBy, 0, 5),
                'impact_files'   => $impact['files'],
                'impact_truncated' => $impact['truncated'],
                'regression_risk' => $this->regressionRisk($impact['files'], count($usedBy), $file),
            ];
        }

        usort($rows, fn ($a, $b) => [$b['impact_files'], $b['used_by']] <=> [$a['impact_files'], $a['used_by']]);

        $cycles = $graph->cycles();
        // Only cycles that involve something being changed are actionable now.
        $changedPaths = array_flip(array_column($analysed, 'path'));
        $relevantCycles = array_values(array_filter($cycles,
            fn ($cycle) => array_intersect_key(array_flip($cycle), $changedPaths) !== []));

        return [
            'most_connected'  => array_slice($rows, 0, $top),
            'analysed_files'  => count($rows),
            'cycles_total'    => count($cycles),
            'cycles_touching_changes' => array_slice($relevantCycles, 0, 5),
        ];
    }

    private function regressionRisk(int $impact, int $usedBy, array $file): string
    {
        // A change to something nothing imports cannot regress anything else.
        if ($usedBy === 0) { return 'isolated'; }
        if ($impact >= 40) { return 'high'; }
        if ($impact >= 10) { return 'moderate'; }

        return 'low';
    }

    /**
     * The engineering priority list — the actual answer to "what next".
     *
     * Ordered by what blocks what: things that make other work unsafe come
     * first, then the first commit that can be made cleanly, then cleanup.
     *
     * @return array<int,array<string,string>>
     */
    private function priority(array $risks, array $plan, array $analysed): array
    {
        $out = [];

        foreach ($risks as $risk) {
            if ($risk['severity'] !== 'critical') { continue; }
            $out[] = [
                'action' => 'Resolve ' . $risk['id'],
                'why'    => $risk['detail'] . ' Prevents: ' . $risk['prevents'] . '.',
                'scope'  => $risk['count'] . ' file(s)',
            ];
        }

        // The first group with no blockers is the one that can be committed now.
        $firstClean = null;
        $firstBlocked = null;
        foreach ($plan['groups'] as $group) {
            if ($group['kind'] === 'review') { continue; }
            if ($group['blocking'] === [] && $firstClean === null) { $firstClean = $group; }
            if ($group['blocking'] !== [] && $firstBlocked === null) { $firstBlocked = $group; }
        }

        if ($firstClean !== null) {
            $out[] = [
                'action' => 'Commit: ' . $firstClean['title'],
                'why'    => $firstClean['rationale'],
                'scope'  => $firstClean['file_count'] . ' file(s), position ' . $firstClean['position'] . ' in the plan',
            ];
        }

        if ($firstBlocked !== null) {
            $reasons = [];
            foreach ($firstBlocked['blocking'] as $blocker) {
                // Name the file. A blocker the engineer cannot locate is not actionable.
                $reasons[] = $blocker['reason'] . ' (' . implode(', ', array_slice($blocker['files'], 0, 2))
                    . (count($blocker['files']) > 2 ? ', +' . (count($blocker['files']) - 2) : '') . ')';
            }
            $out[] = [
                'action' => 'Unblock: ' . $firstBlocked['title'],
                'why'    => implode(' ', $reasons),
                'scope'  => $firstBlocked['file_count'] . ' file(s)',
            ];
        }

        $debris = count($plan['excluded']['do_not_commit']);
        if ($debris > 0) {
            $out[] = [
                'action' => 'Delete ' . $debris . ' artifact file(s) rather than committing them',
                'why'    => 'Backups and accidental files. Removing them shrinks the changed set without '
                          . 'any review burden, which makes everything remaining easier to reason about.',
                'scope'  => $debris . ' file(s)',
            ];
        }

        $review = array_values(array_filter($plan['groups'], fn ($g) => $g['kind'] === 'review'));
        if ($review !== []) {
            $count = array_sum(array_map(fn ($g) => $g['file_count'], $review));
            $out[] = [
                'action' => 'Decide the fate of ' . $count . ' unreferenced or experimental file(s)',
                'why'    => 'Each is either unfinished wiring or abandoned work. Leaving them undecided is '
                          . 'what produced a 394-file working tree in the first place.',
                'scope'  => count($review) . ' subsystem(s)',
            ];
        }

        foreach ($risks as $risk) {
            if ($risk['severity'] !== 'warn') { continue; }
            $out[] = [
                'action' => 'Address ' . $risk['id'],
                'why'    => $risk['detail'],
                'scope'  => $risk['count'] . ' item(s)',
            ];
        }

        return $out;
    }
}
