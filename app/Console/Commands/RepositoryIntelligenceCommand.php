<?php

namespace App\Console\Commands;

use App\Core\Engineer888\RepositoryIntelligence;
use Illuminate\Console\Command;

/**
 * Engineer888 — Repository Intelligence.
 *
 * The Daily Brief says "174 uncommitted files". This says which of them are
 * finished work, which are accidents, what order they can safely be committed in,
 * and what to do first.
 *
 * Usage:
 *   php artisan engineering:repo                     full report
 *   php artisan engineering:repo --section=commit    one section
 *   php artisan engineering:repo --subsystem=Ads     narrow to one subsystem
 *   php artisan engineering:repo --files             per-file classification table
 *   php artisan engineering:repo --json              machine-readable
 *
 * Exit codes: 0 nothing blocking · 1 warnings · 2 critical risks present.
 */
class RepositoryIntelligenceCommand extends Command
{
    protected $signature = 'engineering:repo
        {--json : output machine-readable JSON}
        {--section=* : limit to sections (summary,classification,commit,risks,cleanup,dependencies,priority)}
        {--subsystem= : analyse only one subsystem}
        {--files : include the full per-file classification table}
        {--limit=40 : maximum rows in the per-file table}';

    protected $description = 'Engineer888 repository intelligence — classify every changed file, propose an ordered commit plan, rank risks';

    public function handle(): int
    {
        $report = (new RepositoryIntelligence(base_path()))->analyse($this->option('subsystem') ?: null);

        if (! ($report['available'] ?? false)) {
            $this->error('Repository Intelligence unavailable: ' . ($report['reason'] ?? 'unknown'));

            return 2;
        }

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $this->exitCode($report);
        }

        $sections = $this->option('section') ?: ['summary', 'classification', 'commit', 'risks', 'cleanup', 'dependencies', 'priority'];

        $this->newLine();
        $this->line('  <options=bold>ENGINEER888 — REPOSITORY INTELLIGENCE</>');
        $this->line('  ' . now()->toDayDateTimeString() . '   ·   deterministic analysis, no model calls');
        $this->line('  ' . str_repeat('═', 76));

        if (in_array('summary', $sections, true))       { $this->renderSummary($report); }
        if (in_array('classification', $sections, true)) { $this->renderClassification($report); }
        if (in_array('commit', $sections, true))         { $this->renderCommitPlan($report); }
        if (in_array('risks', $sections, true))          { $this->renderRisks($report); }
        if (in_array('cleanup', $sections, true))        { $this->renderCleanup($report); }
        if (in_array('dependencies', $sections, true))   { $this->renderDependencies($report); }
        if (in_array('priority', $sections, true))       { $this->renderPriority($report); }
        if ($this->option('files'))                      { $this->renderFiles($report); }

        $this->newLine();
        $this->line('  ' . str_repeat('═', 76));
        $this->line('  <fg=gray>' . $report['summary']['changed_files'] . ' changed files · '
            . $report['graph_files'] . ' repository files parsed in ' . $report['graph_ms'] . 'ms · '
            . 'total ' . $report['duration_ms'] . 'ms</>');
        $this->newLine();

        return $this->exitCode($report);
    }

    private function exitCode(array $report): int
    {
        foreach ($report['risks'] as $risk) {
            if ($risk['severity'] === 'critical') { return 2; }
        }
        foreach ($report['risks'] as $risk) {
            if ($risk['severity'] === 'warn') { return 1; }
        }

        return 0;
    }

    // ── sections ────────────────────────────────────────────────────────

    private function renderSummary(array $report): void
    {
        $s = $report['summary'];
        $this->section('REPOSITORY SUMMARY');
        $this->kv('changed files', (string) $s['changed_files']);
        if ($s['undercount_factor'] !== null && $s['undercount_factor'] > 1.05) {
            $this->kv('git status says', $s['collapsed_entries'] . ' entries — <fg=yellow>understates by '
                . $s['undercount_factor'] . 'x</> because it collapses untracked directories');
        }
        $this->kv('lines', '+' . number_format($s['insertions']) . ' / -' . number_format($s['deletions']));
        $this->kv('repository', number_format($s['repository_php_files']) . ' php files, '
            . number_format($s['classes_declared']) . ' classes, ' . $s['subsystems_known'] . ' subsystems discovered');

        $areas = [];
        foreach (array_slice($s['by_area'], 0, 8, true) as $area => $n) { $areas[] = "{$area}:{$n}"; }
        $this->kv('by area', implode('  ', $areas));
    }

    private function renderClassification(array $report): void
    {
        $this->section('FEATURE CLASSIFICATION');
        $labels = [
            'complete-feature'     => ['ready to commit',            'green'],
            'partial-feature'      => ['unfinished markers present', 'yellow'],
            'abandoned-experiment' => ['needs a decision',           'yellow'],
            'temporary-debugging'  => ['debug code in app/',         'red'],
            'architectural-change' => ['wiring or schema',           'cyan'],
            'documentation'        => ['no runtime effect',          'gray'],
            'generated'            => ['build output',               'gray'],
            'backup-artifact'      => ['delete, do not commit',      'yellow'],
            'temporary-artifact'   => ['delete, do not commit',      'red'],
            'merge-artifact'       => ['blocks everything',          'red'],
        ];

        foreach ($report['by_classification'] as $classification => $count) {
            [$meaning, $colour] = $labels[$classification] ?? ['', 'default'];
            $this->line(sprintf('    <fg=%s>%-22s</> %4d   <fg=gray>%s</>', $colour, $classification, $count, $meaning));
        }

        $this->newLine();
        $this->line('    <options=bold>by subsystem</>');
        foreach (array_slice($report['by_subsystem'], 0, 14, true) as $name => $data) {
            $this->line(sprintf('    %-20s %4d files   <fg=gray>%s</>',
                $name, $data['files'], implode(', ', array_slice($data['layers'], 0, 6))));
        }
    }

    private function renderCommitPlan(array $report): void
    {
        $plan = $report['commit_plan'];
        $this->section('COMMIT PROPOSAL  <fg=gray>(ordered: dependencies and schema first)</>');

        foreach ($plan['groups'] as $group) {
            $marker = $group['blocking'] === [] ? '<fg=green>●</>' : '<fg=yellow>●</>';
            $this->newLine();
            $this->line('  ' . $marker . ' <options=bold>Commit ' . $group['position'] . ' — ' . $group['title'] . '</>');
            $this->line('      <fg=gray>' . wordwrap($group['rationale'], 88, "\n      ") . '</>');
            $this->line('      files: ' . $group['file_count'] . '  ·  layers: ' . implode(', ', $group['layers'])
                . ($group['has_test'] ? '  ·  <fg=green>tested</>' : '  ·  <fg=yellow>no tests</>'));

            foreach ($group['must_follow'] as $dependency) {
                $this->line('      <fg=cyan>must follow "' . $dependency['group'] . '"</> — ' . $dependency['reason']);
            }
            foreach ($group['blocking'] as $blocker) {
                $this->line('      <fg=yellow>BLOCKER</> ' . wordwrap($blocker['reason'], 78, "\n              "));
                foreach (array_slice($blocker['files'], 0, 4) as $offender) {
                    $this->line('              <fg=yellow>→</> ' . $offender);
                }
                if (count($blocker['files']) > 4) {
                    $this->line('              <fg=gray>→ and ' . (count($blocker['files']) - 4) . ' more</>');
                }
            }

            $show = array_slice($group['files'], 0, 6);
            foreach ($show as $path) { $this->line('        <fg=gray>' . $path . '</>'); }
            if ($group['file_count'] > count($show)) {
                $this->line('        <fg=gray>… and ' . ($group['file_count'] - count($show)) . ' more</>');
            }
        }

        foreach ($plan['order_conflicts'] as $conflict) {
            $this->newLine();
            $this->line('  <fg=red;options=bold>ORDERING CONFLICT</> ' . wordwrap($conflict, 84, "\n      "));
        }
    }

    private function renderRisks(array $report): void
    {
        $this->section('ARCHITECTURAL RISKS');
        if ($report['risks'] === []) {
            $this->line('    <fg=green>none detected</>');

            return;
        }

        foreach ($report['risks'] as $risk) {
            $tag = match ($risk['severity']) {
                'critical' => '<fg=red;options=bold>CRITICAL</>',
                'warn'     => '<fg=yellow;options=bold>WARN    </>',
                default    => '<fg=blue>INFO    </>',
            };
            $this->newLine();
            $this->line('  ' . $tag . '  <options=bold>' . $risk['title'] . '</>  <fg=gray>[' . $risk['id'] . ']</>');
            $this->line('            ' . wordwrap($risk['detail'], 84, "\n            "));
            $this->line('            <fg=gray>prevents: ' . $risk['prevents'] . '</>');
            foreach (array_slice($risk['evidence'], 0, 5) as $item) {
                $this->line('              <fg=gray>· ' . mb_substr($item, 0, 110) . '</>');
            }
            if ($risk['count'] > 5) {
                $this->line('              <fg=gray>… and ' . ($risk['count'] - 5) . ' more</>');
            }
        }
    }

    private function renderCleanup(array $report): void
    {
        $excluded = $report['commit_plan']['excluded'];
        $this->section('SUGGESTED CLEANUP  <fg=gray>(excluded from every commit above)</>');

        if ($excluded['do_not_commit'] === [] && $excluded['blocked'] === []) {
            $this->line('    <fg=green>nothing to clean up</>');

            return;
        }

        foreach ($excluded['blocked'] as $item) {
            $this->line('    <fg=red>BLOCKED</>  ' . $item['path'] . '  <fg=gray>— ' . $item['why'] . '</>');
        }
        foreach (array_slice($excluded['do_not_commit'], 0, 25) as $item) {
            $this->line('    <fg=yellow>DELETE </>  ' . $item['path'] . '  <fg=gray>— ' . $item['why'] . '</>');
        }
        $extra = count($excluded['do_not_commit']) - 25;
        if ($extra > 0) { $this->line('    <fg=gray>… and ' . $extra . ' more</>'); }
    }

    private function renderDependencies(array $report): void
    {
        $deps = $report['dependencies'];
        $this->section('DEPENDENCY ANALYSIS');
        $this->line('    <fg=gray>' . $deps['analysed_files'] . ' changed files declare classes · '
            . $deps['cycles_total'] . ' circular dependencies in the repository</>');
        $this->newLine();
        $this->line(sprintf('    %-52s %5s %5s %7s  %s', 'file', 'uses', 'used', 'impact', 'regression'));

        foreach ($deps['most_connected'] as $row) {
            $colour = match ($row['regression_risk']) {
                'high'     => 'red',
                'moderate' => 'yellow',
                'isolated' => 'gray',
                default    => 'green',
            };
            $this->line(sprintf('    %-52s %5d %5d %7d  <fg=%s>%s</>',
                mb_substr($row['path'], -52), $row['depends_on'], $row['used_by'],
                $row['impact_files'], $colour, $row['regression_risk']));
        }

        if ($deps['cycles_touching_changes'] !== []) {
            $this->newLine();
            $this->line('    <fg=yellow;options=bold>circular dependencies involving changed files</>');
            foreach ($deps['cycles_touching_changes'] as $cycle) {
                $this->line('      <fg=gray>' . implode(' ↔ ', array_slice($cycle, 0, 4))
                    . (count($cycle) > 4 ? ' (+' . (count($cycle) - 4) . ')' : '') . '</>');
            }
        }
    }

    private function renderPriority(array $report): void
    {
        $this->section('ENGINEERING PRIORITY  <fg=gray>(do these in this order)</>');
        if ($report['priority'] === []) {
            $this->line('    <fg=green>nothing outstanding</>');

            return;
        }

        $n = 0;
        foreach ($report['priority'] as $item) {
            $n++;
            $this->newLine();
            $this->line('  <options=bold>' . $n . '. ' . $item['action'] . '</>  <fg=gray>(' . $item['scope'] . ')</>');
            $this->line('     ' . wordwrap($item['why'], 86, "\n     "));
        }
    }

    private function renderFiles(array $report): void
    {
        $this->section('PER-FILE CLASSIFICATION');
        $limit = (int) $this->option('limit');
        $files = $report['files'];

        // Most decision-worthy first: anything not plainly complete.
        usort($files, function ($a, $b) {
            $rank = [
                'merge-artifact' => 0, 'temporary-artifact' => 1, 'temporary-debugging' => 2,
                'backup-artifact' => 3, 'partial-feature' => 4, 'abandoned-experiment' => 5,
                'architectural-change' => 6, 'complete-feature' => 7, 'generated' => 8, 'documentation' => 9,
            ];

            return [$rank[$a['analysis']['classification']] ?? 99, $a['path']]
               <=> [$rank[$b['analysis']['classification']] ?? 99, $b['path']];
        });

        foreach (array_slice($files, 0, $limit) as $file) {
            $this->line(sprintf('    %-20s %-12s %s',
                $file['analysis']['classification'],
                $file['subsystem']['subsystem'],
                $file['path']));
            $this->line('        <fg=gray>' . $file['analysis']['reason']
                . ($file['analysis']['flags'] !== [] ? '  [' . implode(' ', $file['analysis']['flags']) . ']' : '') . '</>');
        }
        if (count($files) > $limit) {
            $this->line('    <fg=gray>… ' . (count($files) - $limit) . ' more (raise --limit)</>');
        }
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function section(string $title): void
    {
        $this->newLine();
        $this->line('  <options=bold>' . $title . '</>');
        $this->line('  ' . str_repeat('─', 76));
    }

    private function kv(string $k, string $v): void
    {
        $this->line('    ' . str_pad($k, 18) . $v);
    }
}
