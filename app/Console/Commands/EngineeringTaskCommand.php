<?php

namespace App\Console\Commands;

use App\Core\Engineer888\Workflow\StageResult;
use App\Core\Engineer888\Workflow\WorkflowEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Engineer888 — the engineering workflow.
 *
 *   php artisan engineering:task --register-project
 *   php artisan engineering:task --create --title="..." --description="..." --change-set=path.json
 *   php artisan engineering:task --run=<uuid> [--dry-run]
 *   php artisan engineering:task --approve=<uuid> --by="Mark" --note="..."
 *   php artisan engineering:task --status=<uuid>
 *   php artisan engineering:task --list
 *   php artisan engineering:task --lifecycle     (the process itself)
 *   php artisan engineering:task --assets        (promoted reusable assets)
 *
 * Exit codes: 0 completed · 1 blocked · 2 failed.
 */
class EngineeringTaskCommand extends Command
{
    protected $signature = 'engineering:task
        {--register-project : register this repository as a project}
        {--project=levelup-growth-platform : project key}
        {--create : create a task}
        {--title= : task title}
        {--description= : task description}
        {--kind=feature : bug|feature|refactor|security|performance|docs|test|infra}
        {--priority=normal}
        {--requested-by=}
        {--change-set= : JSON file mapping destination => source path}
        {--criteria=* : acceptance criteria}
        {--unknown=* : declared unknowns}
        {--run= : run the lifecycle for a task uuid}
        {--dry-run : run without writing, deploying or promoting}
        {--approve= : approve a task uuid}
        {--by= : approver}
        {--note= : approval note}
        {--status= : show a task}
        {--list : list tasks}
        {--lifecycle : print the canonical process}
        {--assets : list promoted reusable assets}
        {--json}';

    protected $description = 'Engineer888 engineering workflow — the canonical lifecycle every task flows through';

    public function handle(): int
    {
        $engine = new WorkflowEngine();

        if ($this->option('lifecycle'))       { return $this->lifecycle(); }
        if ($this->option('assets'))          { return $this->assets(); }
        if ($this->option('register-project')) { return $this->registerProject($engine); }
        if ($this->option('create'))          { return $this->create($engine); }
        if ($this->option('approve'))         { return $this->approve($engine); }
        if ($this->option('status'))          { return $this->status($engine); }
        if ($this->option('run'))             { return $this->runTask($engine); }

        return $this->list();
    }

    // ── process ─────────────────────────────────────────────────────────

    private function lifecycle(): int
    {
        $this->newLine();
        $this->line('  <options=bold>ENGINEER888 — THE ENGINEERING LIFECYCLE</>');
        $this->line('  <fg=gray>Every task flows through this. No feature bypasses it.</>');
        $this->line('  ' . str_repeat('═', 86));

        foreach (WorkflowEngine::stages() as $i => $stage) {
            $this->newLine();
            $this->line(sprintf('  <options=bold>%2d. %s</>', $i + 1, $stage->name()));
            $this->line('      ' . wordwrap($stage->purpose(), 80, "\n      "));
            $this->line('      <fg=gray>inputs:   ' . implode(', ', $stage->inputs()) . '</>');
            $this->line('      <fg=gray>outputs:  ' . implode(', ', $stage->outputs()) . '</>');
            foreach ($stage->failureModes() as $mode) {
                $this->line('      <fg=yellow>fails if:</> ' . $mode);
            }
            $this->line('      <fg=gray>verify:   ' . $stage->verification() . '</>');
            $this->line('      <fg=gray>recover:  ' . wordwrap($stage->recovery(), 72, "\n                ") . '</>');
        }
        $this->newLine();

        return 0;
    }

    private function assets(): int
    {
        $assets = DB::table('engineering_assets')->orderBy('kind')->orderBy('name')->get();

        $this->newLine();
        $this->line('  <options=bold>REUSABLE ENGINEERING ASSETS</>  ' . $assets->count());
        $this->line('  <fg=gray>Promoted only with implementation evidence. Future projects start from these.</>');
        $this->line('  ' . str_repeat('─', 86));

        if ($assets->isEmpty()) {
            $this->line('    none yet — nothing has been proved enough to promote');
            $this->newLine();

            return 0;
        }

        foreach ($assets as $asset) {
            $this->line(sprintf('    <fg=cyan>%-18s</> %s', $asset->kind, $asset->name));
            $this->line('      <fg=gray>' . $asset->summary . '</>');
            $this->line('      <fg=gray>evidence: ' . mb_substr((string) $asset->evidence, 0, 90) . '</>');
        }
        $this->newLine();

        return 0;
    }

    // ── project and task management ─────────────────────────────────────

    private function registerProject(WorkflowEngine $engine): int
    {
        $project = $engine->registerProject([
            'company'            => 'LevelUp Growth',
            'key'                => 'levelup-growth-platform',
            'name'               => 'LevelUp Growth Platform',
            'repository_path'    => base_path(),
            'ownership_manifest' => '.engineer888/sprints',
            'test_database'      => 'levelup_e888_test',
            'phpunit_config'     => 'phpunit.e888.xml',
            'metadata'           => ['project_number' => 1, 'note' => 'Project #001 — the first, not the only'],
        ]);

        $this->info("project {$project->key} registered (#{$project->id}) for {$project->company}");

        return 0;
    }

    private function create(WorkflowEngine $engine): int
    {
        $changeSet = [];
        if ($this->option('change-set')) {
            $path = (string) $this->option('change-set');
            if (! is_file($path)) { $this->error("change set not found: {$path}"); return 2; }
            $changeSet = json_decode((string) file_get_contents($path), true) ?: [];
        }

        $task = $engine->createTask((string) $this->option('project'), [
            'title'               => (string) $this->option('title'),
            'description'         => (string) $this->option('description'),
            'kind'                => (string) $this->option('kind'),
            'priority'            => (string) $this->option('priority'),
            'requested_by'        => $this->option('requested-by') ?: null,
            'acceptance_criteria' => $this->option('criteria') ?: [],
            'unknowns'            => $this->option('unknown') ?: [],
            'change_set'          => $changeSet,
        ]);

        $this->newLine();
        $this->line('  <fg=green;options=bold>TASK CREATED</>  ' . $task->uuid);
        $this->line('  ' . $task->title);
        $this->line('  <fg=gray>' . count($changeSet) . ' file(s) in the change set</>');
        $this->newLine();
        $this->line('  Next: php artisan engineering:task --run=' . $task->uuid . ' --dry-run');
        $this->newLine();

        return 0;
    }

    private function approve(WorkflowEngine $engine): int
    {
        $by = (string) ($this->option('by') ?: '');
        if (trim($by) === '') {
            $this->error('--approve requires --by: approval must name a person.');

            return 2;
        }

        $task = $engine->approve((string) $this->option('approve'), $by, $this->option('note'));
        $this->info("approved by {$task->approved_by} at {$task->approved_at}");

        return 0;
    }

    private function status(WorkflowEngine $engine): int
    {
        $task = $engine->task((string) $this->option('status'));
        if ($task === null) { $this->error('unknown task'); return 2; }

        $stages = DB::table('engineering_task_stages')->where('task_id', $task->id)->orderBy('position')->get();

        $this->newLine();
        $this->line('  <options=bold>' . $task->title . '</>');
        $this->line('  ' . $task->uuid . '  ·  ' . $task->kind . '  ·  ' . $task->status);
        $this->line('  ' . str_repeat('─', 86));

        foreach ($stages as $stage) {
            [$colour, $mark] = match ($stage->status) {
                StageResult::OK      => ['green', '✔'],
                StageResult::BLOCKED => ['yellow', '⊘'],
                StageResult::FAILED  => ['red', '✖'],
                default              => ['gray', '·'],
            };
            $this->line(sprintf('    <fg=%s>%s</> %-18s %-52s %5dms',
                $colour, $mark, $stage->stage, mb_substr((string) $stage->summary, 0, 52), $stage->duration_ms));
            if ($stage->failure !== null) {
                $this->line('        <fg=red>' . wordwrap(mb_substr($stage->failure, 0, 200), 74, "\n        ") . '</>');
            }
        }
        $this->newLine();

        return 0;
    }

    private function list(): int
    {
        $tasks = DB::table('engineering_tasks')->orderByDesc('id')->limit(20)->get();

        $this->newLine();
        $this->line('  <options=bold>ENGINEERING TASKS</>');
        $this->line('  ' . str_repeat('─', 86));

        if ($tasks->isEmpty()) { $this->line('    none'); $this->newLine(); return 0; }

        foreach ($tasks as $task) {
            $colour = match ($task->status) {
                'completed' => 'green', 'blocked' => 'yellow', 'failed' => 'red', default => 'gray',
            };
            $this->line(sprintf('    <fg=%s>%-10s</> %-8s %-46s %s',
                $colour, $task->status, substr($task->uuid, 0, 8), mb_substr($task->title, 0, 46),
                (string) $task->current_stage));
        }
        $this->newLine();

        return 0;
    }

    // ── execution ───────────────────────────────────────────────────────

    /**
     * Named runTask, NOT run.
     *
     * Illuminate\Console\Command::run() is public, so a private run() here is a
     * fatal inheritance error. php -l passed it — lint proves a file PARSES, not
     * that its class LOADS. Every artisan invocation failed until this was fixed,
     * including the per-minute scheduler.
     */
    private function runTask(WorkflowEngine $engine): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $result = $engine->run((string) $this->option('run'), $dryRun);

        if ($this->option('json')) {
            $this->line(json_encode([
                'status' => $result['status'], 'halted_at' => $result['halted_at'],
                'stages' => array_map(fn ($s) => [
                    'stage' => $s['stage'], 'status' => $s['result']->status, 'summary' => $s['result']->summary,
                ], $result['stages']),
            ], JSON_PRETTY_PRINT));

            return $this->exitCode($result['status']);
        }

        $this->newLine();
        $this->line('  <options=bold>ENGINEERING WORKFLOW</>' . ($dryRun ? '  <fg=yellow>(dry run)</>' : ''));
        $this->line('  ' . $result['task']->title);
        $this->line('  ' . $result['project']->company . ' / ' . $result['project']->name);
        $this->line('  ' . str_repeat('═', 86));

        foreach ($result['stages'] as $entry) {
            $stageResult = $entry['result'];
            [$colour, $mark] = match ($stageResult->status) {
                StageResult::OK      => ['green', '✔'],
                StageResult::BLOCKED => ['yellow', '⊘'],
                StageResult::FAILED  => ['red', '✖'],
                default              => ['gray', '·'],
            };

            $this->line(sprintf('    <fg=%s>%s %-18s</> %s',
                $colour, $mark, $entry['stage'], mb_substr($stageResult->summary, 0, 58)));

            if ($stageResult->failure !== null) {
                $this->line('        <fg=' . $colour . '>' . wordwrap($stageResult->failure, 74, "\n        ") . '</>');
            }
        }

        $this->newLine();
        $this->line('  ' . str_repeat('═', 86));
        $colour = match ($result['status']) {
            'completed' => 'green', 'blocked' => 'yellow', default => 'red',
        };
        $this->line('  <fg=' . $colour . ';options=bold>' . strtoupper($result['status']) . '</>'
            . ($result['halted_at'] !== null ? '  halted at ' . $result['halted_at'] : ''));
        $this->newLine();

        return $this->exitCode($result['status']);
    }

    private function exitCode(string $status): int
    {
        return match ($status) { 'completed' => 0, 'blocked' => 1, default => 2 };
    }
}
