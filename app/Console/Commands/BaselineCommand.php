<?php

namespace App\Console\Commands;

use App\Core\Engineer888\Baseline\BaselineSnapshot;
use App\Core\Engineer888\Baseline\DirtyFileClassifier;
use App\Core\Engineer888\Coordination\OwnershipManifest;
use App\Core\Engineer888\RepositoryIntelligence;
use App\Core\Engineer888\Signals\Shell;
use Illuminate\Console\Command;

/**
 * Engineer888 — production baseline.
 *
 *   php artisan engineering:baseline --capture
 *   php artisan engineering:baseline --verify[=snapshot]
 *   php artisan engineering:baseline --classify
 *   php artisan engineering:baseline --queue        (coordination queue)
 *   php artisan engineering:baseline --divergence   (production vs Git)
 *
 * Read-only except --capture, which writes a snapshot file and nothing else.
 */
class BaselineCommand extends Command
{
    protected $signature = 'engineering:baseline
        {--capture : record an immutable snapshot of the working tree and platform}
        {--label=baseline : label for the captured snapshot}
        {--verify= : compare the current tree against a snapshot (default: latest)}
        {--classify : classify every dirty file}
        {--queue : list files needing another engineer\'s confirmation}
        {--divergence : measure how much of production Git does not describe}
        {--json : machine-readable output}';

    protected $description = 'Engineer888 production baseline — snapshot, classify and measure what Git does not yet describe';

    public function handle(): int
    {
        $snapshots = new BaselineSnapshot(base_path());

        if ($this->option('capture'))    { return $this->capture($snapshots); }
        if ($this->option('verify') !== null && $this->option('verify') !== false) { return $this->verify($snapshots); }
        if ($this->option('queue'))      { return $this->classify(true); }
        if ($this->option('divergence')) { return $this->divergence(); }

        return $this->classify(false);
    }

    private function capture(BaselineSnapshot $snapshots): int
    {
        $snapshot = $snapshots->capture((string) $this->option('label'));

        if ($this->option('json')) {
            $this->line(json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        $this->newLine();
        $this->line('  <options=bold>BASELINE CAPTURED</>');
        $this->line('  ' . str_repeat('─', 82));
        $this->kv('snapshot', (string) $snapshot['path']);
        $this->kv('captured at', (string) $snapshot['captured_at']);
        $this->kv('head', substr((string) $snapshot['git']['head'], 0, 12) . '  (' . $snapshot['git']['branch'] . ')');
        $this->kv('upstream', $snapshot['git']['upstream'] ?? 'NONE');
        $this->kv('dirty files', (string) $snapshot['git']['dirty_count']);
        $this->kv('integrity', substr((string) $snapshot['integrity'], 0, 16));
        $this->kv('pending migrations', (string) count($snapshot['migrations']['pending'] ?? []));
        $this->newLine();

        return 0;
    }

    private function verify(BaselineSnapshot $snapshots): int
    {
        $target = (string) $this->option('verify');
        if ($target === '' || $target === '1') { $target = (string) $snapshots->latest(); }
        if ($target === '') { $this->error('no snapshot to verify against'); return 2; }

        $result = $snapshots->verify($target);

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $result['drift_detected'] ? 1 : 0;
        }

        $this->newLine();
        $this->line('  <options=bold>BASELINE VERIFY</>  ' . $target);
        $this->line('  ' . str_repeat('─', 82));
        $this->kv('integrity', $result['integrity_intact']
            ? '<fg=green>intact</>' : '<fg=red>THE SNAPSHOT ITSELF WAS EDITED</>');
        $this->kv('head then/now', substr((string) $result['head_then'], 0, 10) . ' / ' . substr((string) $result['head_now'], 0, 10));
        $this->kv('changed', (string) count($result['changed']));
        $this->kv('removed', (string) count($result['removed']));
        $this->kv('appeared', (string) count($result['appeared']));

        foreach (array_slice($result['changed'], 0, 12) as $item) {
            $this->line('    <fg=yellow>DRIFTED</> ' . $item['path'] . '  ' . $item['was'] . ' -> ' . $item['now']);
        }
        foreach (array_slice($result['appeared'], 0, 12) as $path) {
            $this->line('    <fg=cyan>APPEARED</> ' . $path);
        }

        $this->newLine();
        $this->line($result['drift_detected']
            ? '  <fg=yellow>Drift detected — another engineer has been working. Affected files must leave their commit group.</>'
            : '  <fg=green>No drift since the snapshot.</>');
        $this->newLine();

        return $result['drift_detected'] ? 1 : 0;
    }

    private function classify(bool $queueOnly): int
    {
        $report = (new RepositoryIntelligence(base_path()))->analyse();
        if (! ($report['available'] ?? false)) {
            $this->error('repository unreadable: ' . ($report['reason'] ?? '?'));

            return 2;
        }

        $classifier = new DirtyFileClassifier(
            OwnershipManifest::active(base_path()),
            $this->externalEvidence()
        );

        $byCategory = [];
        $rows = [];
        foreach ($report['files'] as $file) {
            $verdict = $classifier->classify($file);
            $byCategory[$verdict['category']][] = $file['path'];
            $rows[] = ['path' => $file['path']] + $verdict;
        }

        if ($this->option('json')) {
            $this->line(json_encode(['counts' => array_map('count', $byCategory), 'files' => $rows],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        if ($queueOnly) { return $this->renderQueue($rows); }

        $this->newLine();
        $this->line('  <options=bold>DIRTY FILE CLASSIFICATION</>  ' . count($rows) . ' files');
        $this->line('  ' . str_repeat('─', 82));

        $order = [
            DirtyFileClassifier::OWNED_COMPLETE, DirtyFileClassifier::OWNED_PARTIAL,
            DirtyFileClassifier::GOVERNED_SHARED, DirtyFileClassifier::DOCUMENTATION,
            DirtyFileClassifier::EXTERNALLY_OWNED, DirtyFileClassifier::OWNERSHIP_UNKNOWN,
            DirtyFileClassifier::ABANDONED, DirtyFileClassifier::GENERATED,
            DirtyFileClassifier::RUNTIME_DATA, DirtyFileClassifier::BACKUP,
            DirtyFileClassifier::TEMPORARY, DirtyFileClassifier::SENSITIVE,
            DirtyFileClassifier::UNSAFE_TO_COMMIT,
        ];

        foreach ($order as $category) {
            $count = count($byCategory[$category] ?? []);
            if ($count === 0) { continue; }
            $committable = in_array($category, DirtyFileClassifier::COMMITTABLE, true);
            $this->line(sprintf('    <fg=%s>%-20s</> %4d   %s',
                $committable ? 'green' : ($category === DirtyFileClassifier::SENSITIVE ? 'red' : 'gray'),
                $category, $count, $committable ? 'may enter the baseline' : 'excluded'));
        }

        $committableCount = 0;
        foreach (DirtyFileClassifier::COMMITTABLE as $category) {
            $committableCount += count($byCategory[$category] ?? []);
        }

        $this->newLine();
        $this->line('  <options=bold>' . $committableCount . '</> of ' . count($rows) . ' files can be committed by this sprint.');
        $this->line('  <fg=gray>The remainder are not defects — they belong to other engineers, or are not source.</>');
        $this->newLine();

        return 0;
    }

    private function renderQueue(array $rows): int
    {
        $queue = array_values(array_filter($rows, fn ($r) => in_array($r['category'], [
            DirtyFileClassifier::EXTERNALLY_OWNED, DirtyFileClassifier::OWNERSHIP_UNKNOWN,
        ], true)));

        $this->newLine();
        $this->line('  <options=bold>COORDINATION QUEUE</>  ' . count($queue) . ' files need another engineer\'s confirmation');
        $this->line('  ' . str_repeat('─', 82));

        $grouped = [];
        foreach ($queue as $row) {
            $key = $row['category'] . ' :: ' . preg_replace('#/[^/]+$#', '', $row['path']);
            $grouped[$key][] = $row['path'];
        }
        ksort($grouped);

        foreach (array_slice($grouped, 0, 40, true) as $key => $paths) {
            $this->line(sprintf('    %-58s %3d', mb_substr($key, 0, 58), count($paths)));
        }
        if (count($grouped) > 40) {
            $this->line('    <fg=gray>… and ' . (count($grouped) - 40) . ' more groups</>');
        }
        $this->newLine();

        return 0;
    }

    /**
     * Evidence that a path belongs to another engineer: a session test directory
     * or a subsystem named in recent commits. Advisory — it decides who to ask,
     * never whether to commit.
     *
     * @return array<string,string>
     */
    private function externalEvidence(): array
    {
        $evidence = [];

        foreach (glob(base_path('phpunit.*.xml')) ?: [] as $config) {
            $session = preg_replace('/^phpunit\.|\.xml$/', '', basename($config));
            if ($session === 'e888' || $session === '') { continue; }
            $evidence['tests/Feature/' . ucfirst($session)] = "session {$session}";
        }

        $log = Shell::run('git', ['log', '-25', '--format=%s'], base_path());
        foreach (explode("\n", $log['out']) as $subject) {
            if (preg_match('/^\w+\(([a-z0-9]+)\)/i', trim($subject), $m)) {
                $scope = strtolower($m[1]);
                if ($scope === 'engineer888') { continue; }
                $evidence[$scope] = "recent commits scoped {$scope}";
            }
        }

        return $evidence;
    }

    private function divergence(): int
    {
        $report = (new RepositoryIntelligence(base_path()))->analyse();
        $classifier = new DirtyFileClassifier(OwnershipManifest::active(base_path()), $this->externalEvidence());

        $source = 0; $runtime = 0; $mine = 0;
        foreach ($report['files'] as $file) {
            $verdict = $classifier->classify($file);
            if (in_array($verdict['category'], [DirtyFileClassifier::RUNTIME_DATA, DirtyFileClassifier::BACKUP,
                DirtyFileClassifier::TEMPORARY, DirtyFileClassifier::GENERATED, DirtyFileClassifier::SENSITIVE], true)) {
                $runtime++;
                continue;
            }
            $source++;
            if ($verdict['committable']) { $mine++; }
        }

        $this->newLine();
        $this->line('  <options=bold>PRODUCTION-TO-GIT DIVERGENCE</>');
        $this->line('  ' . str_repeat('─', 82));
        $this->kv('dirty files', (string) count($report['files']));
        $this->kv('non-source', $runtime . '  (runtime data, artifacts — never expected in Git)');
        $this->kv('source files Git does not describe', (string) $source);
        $this->kv('of those, committable by me', (string) $mine);
        $this->kv('remaining after this sprint', (string) ($source - $mine));
        $this->newLine();
        $this->line('  <fg=gray>Git describes production only when the third number reaches zero.</>');
        $this->newLine();

        return $source - $mine > 0 ? 1 : 0;
    }

    private function kv(string $k, string $v): void
    {
        $this->line('    ' . str_pad($k, 36) . $v);
    }
}
