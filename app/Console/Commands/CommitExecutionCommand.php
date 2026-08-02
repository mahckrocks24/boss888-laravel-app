<?php

namespace App\Console\Commands;

use App\Core\Engineer888\Execution\CommitExecutor;
use App\Core\Engineer888\Execution\GitGate;
use App\Core\Engineer888\Execution\PlanStore;
use App\Core\Engineer888\Repository\DependencyGraph;
use Illuminate\Console\Command;

/**
 * Engineer888 — commit execution.
 *
 * Sprint 1 observed the platform. Sprint 2 explained the working tree and
 * proposed an ordered plan. This executes that plan, one group at a time, with
 * the engineer approving every commit.
 *
 * Usage:
 *   php artisan engineering:commit --plan              generate and store a plan
 *   php artisan engineering:commit --list              plan status and history
 *   php artisan engineering:commit --group=5 --dry-run rehearse one group
 *   php artisan engineering:commit --group=5           execute it (asks first)
 *   php artisan engineering:commit --next              the next uncommitted group
 *   php artisan engineering:commit --policy            what this engine may never do
 *
 * Exit codes: 0 success or clean refusal · 1 preflight refused · 2 verification
 * failed after a commit was made.
 */
class CommitExecutionCommand extends Command
{
    protected $signature = 'engineering:commit
        {--plan : generate and store a new commit plan}
        {--list : show the current plan and its execution history}
        {--plan-id= : operate on a specific plan}
        {--group= : execute the group at this position}
        {--next : execute the next group that has not completed}
        {--dry-run : run every check and show the preview, but never stage or commit}
        {--yes : approve without an interactive prompt (must be passed deliberately)}
        {--policy : print the git safety policy and exit}';

    protected $description = 'Engineer888 commit execution — safely execute the Repository Intelligence plan one commit at a time';

    public function handle(): int
    {
        $repo = base_path();
        $store = new PlanStore($repo);

        if ($this->option('policy')) {
            return $this->showPolicy($repo);
        }

        if ($this->option('plan')) {
            return $this->createPlan($store);
        }

        $loaded = $store->load($this->option('plan-id') ? (int) $this->option('plan-id') : null);
        if ($loaded === null) {
            $this->error('No stored plan. Run: php artisan engineering:commit --plan');

            return 1;
        }

        if ($this->option('list') || (! $this->option('group') && ! $this->option('next'))) {
            return $this->showPlan($store, $loaded);
        }

        return $this->executeGroup($store, $loaded, $repo);
    }

    // ── modes ───────────────────────────────────────────────────────────

    private function showPolicy(string $repo): int
    {
        $this->newLine();
        $this->line('  <options=bold>ENGINEER888 — GIT SAFETY POLICY</>');
        $this->line('  ' . str_repeat('─', 76));
        foreach ((new GitGate($repo))->policy() as $rule) {
            $this->line('    · ' . $rule);
        }
        $this->newLine();
        $this->line('  <fg=gray>Enforced in code by GitGate, which every git invocation passes through.</>');
        $this->line('  <fg=gray>A rule that lives only in a comment survives until someone adds a</>');
        $this->line('  <fg=gray>convenient line of code.</>');
        $this->newLine();

        return 0;
    }

    private function createPlan(PlanStore $store): int
    {
        $this->line('  Analysing the repository...');
        $result = $store->create();
        $plan = $result['plan'];

        $this->newLine();
        $this->line('  <options=bold>PLAN #' . $result['plan_id'] . ' STORED</>');
        $this->line('  ' . count($plan['files']) . ' changed files · '
            . count($plan['commit_plan']['groups']) . ' commit groups · '
            . count($plan['commit_plan']['excluded']['do_not_commit']) . ' files excluded as artifacts');
        $this->newLine();
        $this->line('  <fg=gray>Execution will consume this stored plan and will not re-analyse.</>');
        $this->line('  <fg=gray>Every planned file was fingerprinted; if one changes before it is</>');
        $this->line('  <fg=gray>committed, that group refuses rather than staging different content.</>');
        $this->newLine();
        $this->line('  Next: php artisan engineering:commit --list');
        $this->newLine();

        return 0;
    }

    private function showPlan(PlanStore $store, array $loaded): int
    {
        $row = $loaded['row'];
        $plan = $loaded['plan'];
        $executions = $store->executions((int) $row->id);
        $completed = $store->completedPositions((int) $row->id);

        $byPosition = [];
        foreach ($executions as $execution) { $byPosition[(int) $execution->group_position][] = $execution; }

        $this->newLine();
        $this->line('  <options=bold>ENGINEER888 — COMMIT PLAN #' . $row->id . '</>');
        $this->line('  generated ' . $row->generated_at . ' · branch ' . $row->branch
            . ' · head ' . substr($row->head_commit, 0, 8));
        $this->line('  ' . str_repeat('─', 76));
        $this->line('  ' . count($completed) . ' of ' . $row->group_count . ' groups completed');
        $this->newLine();

        foreach ($plan['commit_plan']['groups'] as $group) {
            $position = (int) $group['position'];
            $attempts = $byPosition[$position] ?? [];
            $last = $attempts === [] ? null : end($attempts);

            [$mark, $colour] = match ($last->status ?? 'pending') {
                'completed'                 => ['✔', 'green'],
                'verification_failed'       => ['✖', 'red'],
                'verification_inconclusive' => ['?', 'yellow'],
                'preflight_failed'          => ['⊘', 'yellow'],
                'aborted'                   => ['·', 'gray'],
                default                     => [' ', 'default'],
            };

            $blocked = $group['blocking'] !== [] ? ' <fg=yellow>[' . count($group['blocking']) . ' blocker(s)]</>' : '';

            $this->line(sprintf('  <fg=%s>%s</> %3d  %-48s %4d files%s',
                $colour, $mark, $position, mb_substr($group['title'], 0, 48), $group['file_count'], $blocked));

            if ($last !== null && $last->status !== 'completed') {
                $this->line('        <fg=gray>' . mb_substr((string) $last->outcome, 0, 90) . '</>');
            }
        }

        $this->newLine();
        $this->line('  <fg=gray>--group=N --dry-run   rehearse   ·   --group=N   execute   ·   --next   continue</>');
        $this->newLine();

        return 0;
    }

    private function executeGroup(PlanStore $store, array $loaded, string $repo): int
    {
        $plan = $loaded['plan'];
        $planId = (int) $loaded['row']->id;
        $groups = $plan['commit_plan']['groups'];
        $completed = $store->completedPositions($planId);

        if ($this->option('next')) {
            $group = null;
            foreach ($groups as $candidate) {
                if (! in_array((int) $candidate['position'], $completed, true)) { $group = $candidate; break; }
            }
            if ($group === null) {
                $this->info('Every group in plan #' . $planId . ' has completed.');

                return 0;
            }
        } else {
            $position = (int) $this->option('group');
            $group = null;
            foreach ($groups as $candidate) {
                if ((int) $candidate['position'] === $position) { $group = $candidate; break; }
            }
            if ($group === null) {
                $this->error('No group at position ' . $position . ' in plan #' . $planId . '.');

                return 1;
            }
        }

        $dryRun = (bool) $this->option('dry-run');

        $this->newLine();
        $this->line('  <options=bold>' . ($dryRun ? 'REHEARSING' : 'EXECUTING') . ' — plan #' . $planId
            . ', group ' . $group['position'] . '/' . count($groups) . '</>');
        $this->line('  ' . $group['title']);
        $this->line('  ' . str_repeat('═', 76));

        // The dependency graph is rebuilt once here and handed to the executor,
        // because test selection needs it. The PLAN is never regenerated.
        $graph = (new DependencyGraph($repo))->build();

        $executor = new CommitExecutor($repo, $store);
        $result = $executor->execute($group, [
            'plan_id'      => $planId,
            'plan'         => $plan,
            'fingerprints' => $loaded['fingerprints'],
            'graph'        => $graph,
        ], $dryRun, $dryRun ? null : fn (array $preview) => $this->approve($preview));

        return $this->report($result, $group);
    }

    // ── output ──────────────────────────────────────────────────────────

    private function approve(array $preview): bool
    {
        $this->renderPreview($preview);

        if ($this->option('yes')) {
            $this->line('  <fg=yellow>--yes supplied: proceeding without an interactive prompt.</>');

            return true;
        }

        $this->newLine();

        return $this->confirm('  Stage these ' . count($preview['files']) . ' file(s) and commit?', false);
    }

    private function renderPreview(array $preview): void
    {
        $this->section('PREFLIGHT');
        foreach ($preview['preflight']['gates'] as $gate) {
            $mark = $gate['passed'] ? '<fg=green>PASS</>' : '<fg=red>FAIL</>';
            $this->line('    ' . $mark . '  ' . str_pad($gate['id'], 24) . ' <fg=gray>' . $gate['detail'] . '</>');
        }

        $this->section('FILES TO BE STAGED (' . count($preview['files']) . ')');
        foreach (array_slice($preview['files'], 0, 20) as $path) {
            $this->line('    ' . $path);
        }
        if (count($preview['files']) > 20) {
            $this->line('    <fg=gray>… and ' . (count($preview['files']) - 20) . ' more</>');
        }

        $this->section('VERIFICATION SELECTED');
        foreach ($preview['checks'] as $check) {
            $this->line('    <options=bold>' . $check['id'] . '</>  <fg=gray>(' . $check['kind'] . ')</>  ' . $check['description']);
            $this->line('        <fg=gray>' . wordwrap($check['rationale'], 82, "\n        ") . '</>');
        }

        $this->section('COMMIT MESSAGE');
        foreach (explode("\n", rtrim($preview['message'])) as $line) {
            $this->line('    <fg=cyan>' . $line . '</>');
        }
    }

    private function report(array $result, array $group): int
    {
        if (isset($result['preview']) && $result['dry_run']) {
            $this->renderPreview($result['preview']);
        }

        $this->newLine();
        $this->line('  ' . str_repeat('═', 76));

        if (! empty($result['log_error'])) {
            $this->line('  <fg=red;options=bold>EXECUTION LOG WRITE FAILED</> — the outcome below happened but was NOT recorded.');
            $this->line('  <fg=red>' . $result['log_error'] . '</>');
            $this->newLine();
        }

        switch ($result['status']) {
            case 'completed':
                $this->line('  <fg=green;options=bold>COMMITTED AND VERIFIED</>');
                $this->line('  ' . $result['outcome']);
                $this->renderChecks($result);
                $this->newLine();
                $this->line('  <fg=gray>Next: php artisan engineering:commit --next</>');
                $this->newLine();

                return 0;

            case 'verification_failed':
            case 'verification_inconclusive':
                $failed = $result['status'] === 'verification_failed';
                $this->line($failed
                    ? '  <fg=red;options=bold>VERIFICATION FAILED — EXECUTION STOPPED</>'
                    : '  <fg=yellow;options=bold>VERIFICATION INCONCLUSIVE — EXECUTION STOPPED</>');
                $this->line('  ' . $result['outcome']);
                $this->newLine();
                $this->line('  <options=bold>reason</>');
                $this->line('    ' . wordwrap($result['verification']['reason'], 80, "\n    "));
                $this->renderChecks($result);

                $this->newLine();
                $this->line('  <options=bold>failing output</>');
                foreach (array_slice(explode("\n", (string) $result['verification']['output']), -25) as $line) {
                    $this->line('    <fg=gray>' . mb_substr($line, 0, 110) . '</>');
                }

                $this->newLine();
                $this->line('  <options=bold>files in this commit</>');
                foreach (array_slice($group['files'], 0, 12) as $path) { $this->line('    ' . $path); }

                $this->newLine();
                $this->line('  <options=bold>suggested next step</>');
                $this->line('    ' . ($failed
                    ? 'Fix the failure above, then re-plan. The commit was NOT undone — Engineer888'
                    : 'Re-run the check when the shared test database is quiet. The commit stands.'));
                $this->line('    ' . ($failed ? 'does not repair or revert on your behalf.' : ''));
                $this->line('    To undo it yourself: <fg=cyan>' . ($result['undo_command'] ?? 'git reset --soft HEAD~1') . '</>');
                $this->newLine();
                $this->line('  <fg=red>No further groups will be executed until this is resolved.</>');
                $this->newLine();

                return 2;

            case 'preflight_failed':
                $this->line('  <fg=yellow;options=bold>REFUSED — NOTHING WAS STAGED OR COMMITTED</>');
                $this->line('  ' . wordwrap((string) $result['outcome'], 80, "\n  "));
                $this->newLine();

                return 1;

            default:
                $this->line('  <fg=gray>' . strtoupper(str_replace('_', ' ', $result['status'])) . '</>');
                $this->line('  ' . (string) $result['outcome']);
                $this->newLine();

                return 0;
        }
    }

    private function renderChecks(array $result): void
    {
        if (! isset($result['verification']['checks'])) { return; }
        $this->newLine();
        $this->line('  <options=bold>checks run</>');
        foreach ($result['verification']['checks'] as $check) {
            $mark = ! empty($check['skipped']) ? '<fg=gray>SKIP</>' : ($check['passed'] ? '<fg=green>PASS</>' : '<fg=red>FAIL</>');
            $this->line('    ' . $mark . '  ' . str_pad($check['id'], 22) . ' <fg=gray>' . $check['ms'] . 'ms</>');
        }
    }

    private function section(string $title): void
    {
        $this->newLine();
        $this->line('  <options=bold>' . $title . '</>');
        $this->line('  ' . str_repeat('─', 76));
    }
}
