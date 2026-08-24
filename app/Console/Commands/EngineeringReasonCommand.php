<?php

namespace App\Console\Commands;

use App\Core\Engineer888\Audit\WriteBoundary;
use App\Core\Engineer888\Reasoning\CandidateStore;
use App\Core\Engineer888\Reasoning\ProviderRegistry;
use App\Core\Engineer888\Reasoning\ReasoningEngine;
use App\Core\Engineer888\Reasoning\ReasoningOutcome;
use App\Core\Engineer888\Workflow\WorkflowEngine;
use Illuminate\Console\Command;

/**
 * The reasoning engine, inspectable from the terminal.
 *
 * Everything here is read-only or, at most, writes a candidate row. Reasoning
 * about a task changes nothing in the repository — that is the whole point —
 * so this command can be run against a live task without consequence.
 *
 * NOTE ON METHOD NAMES. Nothing here is called run(). Illuminate's Command
 * declares a public run(), a private override is a fatal inheritance error, and
 * `php -l` cannot see it: on 2026-08-02 that mistake took down every artisan
 * invocation on this platform including the scheduler.
 */
final class EngineeringReasonCommand extends Command
{
    protected $signature = 'engineering:reason
        {--providers        : list every registered reasoning provider and whether it can run}
        {--boundary         : prove that nothing in the reasoning namespace can write}
        {--context=         : task uuid — show the context that WOULD be sent, without calling a provider}
        {--task=            : task uuid — reason about it and store the candidate}
        {--candidate=       : task uuid — show the candidates already stored for it}
        {--show=            : with --candidate, print the proposed content of this file path}
        {--provider=        : override the configured provider for this invocation}';

    protected $description = 'Engineering Reasoning Engine: context, providers, candidates';

    public function handle(WorkflowEngine $workflow): int
    {
        if ($this->option('providers'))       { return $this->showProviders(); }
        if ($this->option('boundary'))        { return $this->showBoundary(); }
        if ($this->option('context'))         { return $this->showContext($workflow, (string) $this->option('context')); }
        if ($this->option('task'))            { return $this->reasonAbout($workflow, (string) $this->option('task')); }
        if ($this->option('candidate'))       { return $this->showCandidates($workflow, (string) $this->option('candidate')); }

        $this->line('');
        $this->line('  ENGINEERING REASONING ENGINE');
        $this->line('  The model proposes. Engineer888 verifies.');
        $this->line('');
        $this->line('    --providers            who can reason right now');
        $this->line('    --boundary             prove reasoning cannot write');
        $this->line('    --context=<uuid>       what would be sent, and what was left out');
        $this->line('    --task=<uuid>          reason, validate, store');
        $this->line('    --candidate=<uuid>     what was proposed, and whether it was accepted');
        $this->line('');

        return self::SUCCESS;
    }

    private function showProviders(): int
    {
        $registry = ProviderRegistry::fromConfig();

        $this->line('');
        $this->line('  REASONING PROVIDERS' . ($registry->enabled() ? '' : '   [REASONING DISABLED]'));
        $this->line('  Swapping one for another is a config change. No stage names a provider.');
        $this->line('  ' . str_repeat('─', 86));

        foreach ($registry->inventory() as $row) {
            $mark = $row['available'] ? '✔' : '·';
            $this->line(sprintf('    %s %-10s %-26s %-30s %s',
                $mark,
                $row['name'] . ($row['default'] ? '*' : ''),
                substr((string) $row['model'], 0, 26),
                substr((string) $row['endpoint'], 0, 30),
                $row['deterministic'] ? 'deterministic' : ''
            ));
            $this->line(sprintf('      %s', $row['notes']));
            if (! $row['available']) {
                $this->line(sprintf('      unavailable: %s', $row['reason']));
            }
        }

        $this->line('');
        $this->line('    * = configured default (E888_REASONING_PROVIDER)');
        $this->line('');

        return self::SUCCESS;
    }

    private function showBoundary(): int
    {
        $this->line('');
        $this->line('  WRITE BOUNDARY — the reasoning engine must never modify a file');
        $this->line('  ' . str_repeat('─', 86));

        $failed = 0;
        foreach (WriteBoundary::selfTest() as $case) {
            $this->line(sprintf('    %s %-52s %s',
                $case['passed'] ? '✔' : '✘', $case['case'], $case['detail']));
            if (! $case['passed']) { $failed++; }
        }

        if ($failed > 0) {
            $this->line('');
            $this->error("  the detector itself failed {$failed} self-test(s); its findings mean nothing");

            return self::FAILURE;
        }

        $directory = base_path('app/Core/Engineer888/Reasoning');
        $boundary = new WriteBoundary($directory);
        $violations = $boundary->violations();

        $this->line('');
        $this->line('    scanned ' . count($boundary->phpFiles()) . ' file(s) under app/Core/Engineer888/Reasoning');

        foreach ($violations as $violation) {
            $this->line(sprintf('    ✘ %s:%d  %s (%s)',
                str_replace(base_path() . '/', '', $violation['file']),
                $violation['line'], $violation['symbol'], $violation['kind']));
        }

        $this->line($violations === []
            ? '    ✔ no filesystem write, process call or mutating collaborator anywhere in the namespace'
            : '    ✘ ' . count($violations) . ' violation(s)');
        $this->line('');

        return $violations === [] ? self::SUCCESS : self::FAILURE;
    }

    private function showContext(WorkflowEngine $workflow, string $uuid): int
    {
        [$task, $project] = $this->resolve($workflow, $uuid);
        if ($task === null) { return self::FAILURE; }

        $request = ReasoningEngine::make()->buildRequest($project, $task);

        $this->line('');
        $this->line('  CONTEXT FOR: ' . $task->title);
        $this->line('  ' . $request->sizeBytes() . ' bytes · fingerprint ' . substr($request->fingerprint(), 0, 12));
        $this->line('  ' . str_repeat('─', 86));

        foreach ($request->contextManifest() as $source => $stats) {
            $this->line(sprintf('    %-26s %3d item(s)  %6d bytes', $source, $stats['items'], $stats['bytes']));
        }

        $this->line('');
        $this->line('  EXCLUDED — ' . count($request->excluded) . ' item(s) deliberately not sent');

        $reasons = [];
        foreach ($request->excluded as $excluded) { $reasons[$excluded['reason']][] = $excluded['item']; }
        foreach ($reasons as $reason => $items) {
            $this->line(sprintf('    %-58s %d', $reason, count($items)));
            foreach (array_slice($items, 0, 3) as $item) {
                $this->line('      · ' . substr($item, 0, 72));
            }
        }

        $this->line('');
        $this->line('  No provider was called. Nothing was written.');
        $this->line('');

        return self::SUCCESS;
    }

    private function reasonAbout(WorkflowEngine $workflow, string $uuid): int
    {
        [$task, $project] = $this->resolve($workflow, $uuid);
        if ($task === null) { return self::FAILURE; }

        $provider = $this->option('provider') ? (string) $this->option('provider') : null;

        $this->line('');
        $this->line('  REASONING: ' . $task->title);
        $this->line('  project ' . $project->key . ' · provider ' . ($provider ?? 'configured default'));
        $this->line('  ' . str_repeat('─', 86));

        $outcome = ReasoningEngine::make()->propose($project, $task, $provider);

        $this->line('    status: ' . $outcome->status);
        $this->line('    ' . wordwrap($outcome->reason, 84, "\n    "));

        if ($outcome->violations !== []) {
            $this->line('');
            $this->line('  ENGINEER888 REFUSED THE ANSWER');
            foreach ($outcome->violations as $violation) {
                $this->line('    ✘ [' . $violation['rule'] . '] ' . $violation['detail']);
            }
        }

        if ($outcome->isValidated()) {
            $candidate = $outcome->candidate;
            $this->line('');
            $this->line('    confidence: ' . $candidate->confidence());
            $this->line('    files:');
            foreach ($candidate->fileChanges() as $change) {
                $this->line(sprintf('      %-8s %-52s %6d bytes',
                    $change['action'] ?? '?', $change['path'] ?? '?', strlen((string) ($change['content'] ?? ''))));
            }
            if ($candidate->unknowns() !== []) {
                $this->line('    unknowns:');
                foreach ($candidate->unknowns() as $unknown) {
                    $this->line('      ? ' . substr((string) ($unknown['question'] ?? ''), 0, 78));
                }
            }
            $this->line('');
            $this->line('  Nothing was installed. Run the workflow to take this through approval.');
        }

        $this->line('');

        return $outcome->status === ReasoningOutcome::VALIDATED ? self::SUCCESS : self::FAILURE;
    }

    private function showCandidates(WorkflowEngine $workflow, string $uuid): int
    {
        [$task] = $this->resolve($workflow, $uuid);
        if ($task === null) { return self::FAILURE; }

        $rows = (new CandidateStore())->forTask((int) $task->id);

        $this->line('');
        $this->line('  CANDIDATES FOR: ' . $task->title);
        $this->line('  ' . str_repeat('─', 86));

        if ($rows === []) {
            $this->line('    none — this task has never been reasoned about');
            $this->line('');

            return self::SUCCESS;
        }

        foreach ($rows as $row) {
            $this->line(sprintf('    #%-4d %-9s %-10s %-22s %d file(s)  %s  %dms',
                $row->id, $row->status, $row->provider, substr((string) $row->model, 0, 22),
                $row->file_count, (string) $row->confidence, $row->latency_ms));

            $violations = json_decode((string) $row->violations, true) ?: [];
            foreach ($violations as $violation) {
                $this->line('        ✘ ' . $violation['detail']);
            }
        }

        $show = $this->option('show');
        if ($show) {
            $candidate = (new CandidateStore())->latestValidated((int) $task->id);
            foreach ($candidate?->fileChanges() ?? [] as $change) {
                if (($change['path'] ?? '') !== $show) { continue; }
                $this->line('');
                $this->line('  PROPOSED CONTENT — ' . $show);
                $this->line('  ' . str_repeat('─', 86));
                $this->line((string) ($change['content'] ?? ''));
            }
        }

        $this->line('');

        return self::SUCCESS;
    }

    /** @return array{0:?object,1:?object} */
    private function resolve(WorkflowEngine $workflow, string $uuid): array
    {
        $task = $workflow->task($uuid);
        if ($task === null) {
            $this->error("unknown task: {$uuid}");

            return [null, null];
        }

        $project = \Illuminate\Support\Facades\DB::table('engineering_projects')->find($task->project_id);

        return [$task, $project];
    }
}
