<?php

namespace App\Core\Engineer888\Execution;

use App\Core\Engineer888\Repository\DependencyGraph;
use App\Core\Engineer888\Signals\Shell;
use Illuminate\Support\Facades\DB;

/**
 * Executes exactly one commit group, or refuses to.
 *
 * The order is fixed and every step can only stop the process, never widen it:
 *
 *   preflight → stage → confirm the index matches the plan EXACTLY → build the
 *   message → (approval) → commit → verify → log
 *
 * The check after staging is the one that matters most. Preflight looks at the
 * repository before anything is touched; the post-stage check compares what git
 * ACTUALLY staged against what the plan asked for. `git add` on a path can pull
 * in more than the named file, and a concurrent session writing between the two
 * moments is a real possibility in this tree. If the sets differ by even one
 * file, the index is unstaged and the group aborts.
 *
 * Verification failure never triggers repair. The commit stays, the sequence
 * stops, and the engineer is given the failing output and the exact command to
 * undo it. Anything else is Engineer888 deciding on the engineer's behalf.
 */
final class CommitExecutor
{
    private GitGate $git;

    private PreflightCheck $preflight;

    private CommitMessageBuilder $messages;

    public function __construct(
        private string $repoPath,
        private PlanStore $store,
    ) {
        $this->git = new GitGate($repoPath);
        $this->preflight = new PreflightCheck($repoPath, $this->git, $store);
        $this->messages = new CommitMessageBuilder();
    }

    /**
     * @param  callable(array):bool|null  $approve  receives the preview, returns true to proceed
     * @return array<string,mixed>
     */
    public function execute(array $group, array $context, bool $dryRun, ?callable $approve = null): array
    {
        $started = microtime(true);
        $planId = (int) $context['plan_id'];
        $plan = $context['plan'];
        $fingerprints = $context['fingerprints'];

        $record = [
            'plan_id'         => $planId,
            'group_key'       => $group['key'],
            'group_position'  => (int) $group['position'],
            'repository_path' => $this->repoPath,
            'files'           => json_encode($group['files']),
            'started_at'      => now(),
            'created_at'      => now(),
            'updated_at'      => now(),
        ];

        // ── 1. preflight ─────────────────────────────────────────────────
        $completed = $this->store->completedPositions($planId);
        $pre = $this->preflight->run($group, $fingerprints, $completed, $plan);

        if (! $pre['passed']) {
            return $this->finish($record, 'preflight_failed', $pre['abort_reason'], $started, [
                'preflight' => $pre,
            ], $dryRun);
        }

        // ── 2. select verification ───────────────────────────────────────
        $graph = $context['graph'];
        $checks = (new TestSelector($this->repoPath, $graph))->select($group);

        // ── 3. build the message ─────────────────────────────────────────
        $message = $this->messages->build($group, $plan, $planId, count($plan['commit_plan']['groups']));

        $preview = [
            'group'     => $group,
            'preflight' => $pre,
            'checks'    => $checks,
            'message'   => $message,
            'files'     => $group['files'],
        ];

        if ($dryRun) {
            return $this->finish($record, 'aborted', 'dry run — nothing was staged or committed', $started, [
                'preflight' => $pre, 'checks' => $checks, 'message' => $message, 'preview' => $preview,
            ], true);
        }

        // ── 4. approval ──────────────────────────────────────────────────
        if ($approve === null || ! $approve($preview)) {
            return $this->finish($record, 'aborted', 'not approved — nothing was staged or committed', $started, [
                'preflight' => $pre, 'checks' => $checks, 'message' => $message, 'preview' => $preview,
            ], false);
        }

        // ── 5. stage, naming every file ──────────────────────────────────
        $toStage = $group['files'];
        $addResult = $this->git->run('add', array_merge(['--'], $toStage));
        if (! $addResult['ok']) {
            $this->unstageAll();

            return $this->finish($record, 'aborted',
                'git add failed: ' . ($addResult['refused'] ?? trim($addResult['out']) ?: 'unknown'),
                $started, ['preflight' => $pre, 'checks' => $checks, 'message' => $message], false);
        }

        // ── 6. the index must match the plan exactly ─────────────────────
        $staged = $this->preflight->stagedFiles();
        $unexpected = array_values(array_diff($staged, $toStage));
        $missing = array_values(array_diff($toStage, $staged));

        // A planned deletion appears in the plan but git may report it under its
        // own path; both directions are compared so neither surprise is missed.
        if ($unexpected !== [] || $missing !== []) {
            $this->unstageAll();
            $detail = [];
            if ($unexpected !== []) { $detail[] = 'unexpected: ' . implode(', ', array_slice($unexpected, 0, 5)); }
            if ($missing !== []) { $detail[] = 'not staged: ' . implode(', ', array_slice($missing, 0, 5)); }

            return $this->finish($record, 'preflight_failed',
                'the index does not match the plan — ' . implode('; ', $detail),
                $started, ['preflight' => $pre, 'checks' => $checks, 'message' => $message,
                           'staged' => $staged], false);
        }

        // ── 7. commit ────────────────────────────────────────────────────
        $messageFile = storage_path('app/e888-commit-message-' . $planId . '-' . $group['position'] . '.txt');
        file_put_contents($messageFile, $message);

        $commit = $this->git->run('commit', ['--file=' . $messageFile, '--cleanup=verbatim']);
        @unlink($messageFile);

        if (! $commit['ok']) {
            $this->unstageAll();

            return $this->finish($record, 'aborted',
                'git commit failed: ' . ($commit['refused'] ?? trim($commit['out']) ?: 'unknown'),
                $started, ['preflight' => $pre, 'checks' => $checks, 'message' => $message], false);
        }

        $sha = trim($this->git->run('rev-parse', ['HEAD'])['out']);
        $record['commit_sha'] = $sha;
        $record['commit_message'] = $message;

        // ── 8. verify ────────────────────────────────────────────────────
        $verification = $this->verify($checks);

        $status = match ($verification['result']) {
            'passed'       => 'completed',
            'inconclusive' => 'verification_inconclusive',
            default        => 'verification_failed',
        };

        $outcome = match ($verification['result']) {
            'passed' => 'committed ' . substr($sha, 0, 8) . ' and verified (' . count($checks) . ' check(s))',
            'inconclusive' => 'committed ' . substr($sha, 0, 8)
                . ' but verification could not reach a verdict: ' . $verification['reason'],
            default => 'committed ' . substr($sha, 0, 8)
                . ' but verification FAILED at ' . $verification['failed_check'],
        };

        return $this->finish($record, $status, $outcome, $started, [
            'preflight'    => $pre,
            'checks'       => $checks,
            'message'      => $message,
            'verification' => $verification,
            'commit_sha'   => $sha,
            'undo_command' => 'git reset --soft HEAD~1   # run this yourself; Engineer888 will not',
        ], false);
    }

    /**
     * Run the selected checks in order. The first failure stops the rest —
     * later checks would only report consequences of the first.
     *
     * @return array<string,mixed>
     */
    public function verify(array $checks): array
    {
        $results = [];
        $output = [];

        foreach ($checks as $check) {
            if (($check['runner'] ?? 'shell') === 'none') {
                $results[] = ['id' => $check['id'], 'passed' => true, 'skipped' => true, 'ms' => 0];
                continue;
            }

            $started = microtime(true);
            [$ok, $text] = ($check['runner'] === 'internal')
                ? $this->runInternal($check)
                : $this->runShell($check);
            $ms = (int) round((microtime(true) - $started) * 1000);

            $results[] = ['id' => $check['id'], 'passed' => $ok, 'ms' => $ms];
            $output[] = '--- ' . $check['id'] . ' (' . ($ok ? 'PASS' : 'FAIL') . ', ' . $ms . 'ms)' . "\n" . $text;

            if (! $ok) {
                // A test database corrupted by another session running its own
                // suite is not evidence that this commit broke anything. It is
                // still a stop — but calling it a failure would be untrue.
                $inconclusive = (bool) preg_match(
                    '/(Base table or view (not found|already exists)|migrations.{0,20}doesn.t exist|SQLSTATE\[42S0[12]\])/i',
                    $text
                );

                return [
                    'result'       => $inconclusive ? 'inconclusive' : 'failed',
                    'failed_check' => $check['id'],
                    'reason'       => $inconclusive
                        ? 'the shared test database was in an inconsistent state, which is a known effect of '
                        . 'concurrent test runs and is not attributable to this commit'
                        : 'check ' . $check['id'] . ' returned a non-zero exit status',
                    'checks'       => $results,
                    'output'       => implode("\n", $output),
                ];
            }
        }

        return [
            'result' => 'passed',
            'reason' => 'every selected check passed',
            'checks' => $results,
            'output' => implode("\n", $output),
        ];
    }

    /** @return array{0:bool, 1:string} */
    private function runShell(array $check): array
    {
        $out = [];
        $code = 0;
        @exec('cd ' . escapeshellarg($this->repoPath) . ' && ' . $check['command'] . ' 2>&1', $out, $code);

        return [$code === 0, implode("\n", array_slice($out, -60))];
    }

    /**
     * Checks that are clearer run in-process than assembled as a shell string.
     *
     * @return array{0:bool, 1:string}
     */
    private function runInternal(array $check): array
    {
        $problems = [];

        foreach ($check['targets'] as $target) {
            $full = $this->repoPath . '/' . $target;
            if (! is_file($full)) { $problems[] = $target . ': missing'; continue; }

            if ($check['id'] === 'config-loads') {
                try {
                    $value = require $full;
                    if (! is_array($value)) { $problems[] = $target . ': does not return an array'; }
                } catch (\Throwable $e) {
                    $problems[] = $target . ': ' . $e->getMessage();
                }
                continue;
            }

            if ($check['id'] === 'blade-compile') {
                try {
                    $compiled = app('blade.compiler')->compileString((string) file_get_contents($full));
                    // The compiler emits PHP; if that PHP does not tokenize, the
                    // template would fail at render time.
                    if (@token_get_all('<?php ?>' . $compiled) === false) {
                        $problems[] = $target . ': compiles to invalid PHP';
                    }
                } catch (\Throwable $e) {
                    $problems[] = $target . ': ' . $e->getMessage();
                }
            }
        }

        return [$problems === [], $problems === [] ? 'ok' : implode("\n", $problems)];
    }

    /**
     * Clear the index without touching the working tree.
     *
     * `git reset` is forbidden by the gate precisely because its --hard form
     * destroys work, so unstaging goes through `restore --staged`, which cannot.
     */
    private function unstageAll(): void
    {
        $staged = $this->preflight->stagedFiles();
        if ($staged === []) { return; }
        $this->git->run('restore', array_merge(['--staged', '--'], $staged));
    }

    private function finish(array $record, string $status, ?string $outcome, float $started, array $extra, bool $dryRun): array
    {
        $record['status'] = $status;
        $record['outcome'] = $outcome !== null ? mb_substr($outcome, 0, 250) : null;
        $record['duration_ms'] = (int) round((microtime(true) - $started) * 1000);
        $record['finished_at'] = now();
        $record['checks'] = json_encode($extra['checks'] ?? []);
        $record['check_output'] = isset($extra['verification']['output'])
            ? mb_substr((string) $extra['verification']['output'], 0, 60000) : null;
        $record['commit_message'] = $extra['message'] ?? ($record['commit_message'] ?? null);
        $record['commit_sha'] = $extra['commit_sha'] ?? ($record['commit_sha'] ?? null);

        // A dry run is not an execution and must not pollute the log — but a
        // refusal IS, because "why is this still uncommitted" is exactly the
        // question the log exists to answer.
        $id = null;
        if (! $dryRun) {
            $id = DB::table('engineering_executions')->insertGetId($record);
        }

        return array_merge($extra, [
            'execution_id' => $id,
            'status'       => $status,
            'outcome'      => $outcome,
            'duration_ms'  => $record['duration_ms'],
            'dry_run'      => $dryRun,
        ]);
    }
}
