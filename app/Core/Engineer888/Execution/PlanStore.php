<?php

namespace App\Core\Engineer888\Execution;

use App\Core\Engineer888\RepositoryIntelligence;
use Illuminate\Support\Facades\DB;

/**
 * Persists a Repository Intelligence plan so execution consumes it rather than
 * re-deriving it.
 *
 * WHY THE FINGERPRINTS EXIST. This working tree is edited continuously by more
 * than one session. A plan generated at 13:20 describes files as they were at
 * 13:20. Executing it at 13:40 without checking would stage whatever the file
 * contains now — which may be another session's half-written change, captured
 * into a commit whose message describes something else entirely. The fingerprint
 * turns that from a silent corruption into a refusal.
 */
final class PlanStore
{
    public function __construct(private string $repoPath) {}

    /** Generate, fingerprint and store. @return array{plan_id:int, plan:array} */
    public function create(): array
    {
        $gate = new GitGate($this->repoPath);
        $report = (new RepositoryIntelligence($this->repoPath))->analyse();

        if (! ($report['available'] ?? false)) {
            throw new \RuntimeException('cannot plan: ' . ($report['reason'] ?? 'repository unreadable'));
        }

        $fingerprints = [];
        foreach ($report['files'] as $file) {
            $fingerprints[$file['path']] = $this->fingerprint($file['path']);
        }

        $branch = trim($gate->run('rev-parse', ['--abbrev-ref', 'HEAD'])['out']);
        $head = trim($gate->run('rev-parse', ['HEAD'])['out']);

        $id = DB::table('engineering_commit_plans')->insertGetId([
            'generated_at'    => now(),
            'repository_path' => $this->repoPath,
            'branch'          => $branch ?: 'unknown',
            'head_commit'     => $head ?: 'unknown',
            'plan'            => json_encode($report),
            'fingerprints'    => json_encode($fingerprints),
            'group_count'     => count($report['commit_plan']['groups']),
            'file_count'      => count($report['files']),
            'status'          => 'open',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        return ['plan_id' => $id, 'plan' => $report];
    }

    /** @return array{row:object, plan:array, fingerprints:array}|null */
    public function load(?int $planId = null): ?array
    {
        $query = DB::table('engineering_commit_plans');
        $row = $planId !== null
            ? $query->where('id', $planId)->first()
            : $query->where('status', 'open')->orderByDesc('id')->first();

        if ($row === null) { return null; }

        return [
            'row'          => $row,
            'plan'         => json_decode($row->plan, true) ?: [],
            'fingerprints' => json_decode($row->fingerprints, true) ?: [],
        ];
    }

    /**
     * Content hash of a file as it is right now. Missing files hash to a
     * distinct marker so "deleted since planning" is detectable and is not
     * confused with "empty".
     */
    public function fingerprint(string $relativePath): string
    {
        $full = $this->repoPath . '/' . $relativePath;
        if (! is_file($full)) { return 'absent'; }

        return sha1_file($full) ?: 'unreadable';
    }

    /**
     * Which of these files have changed since the plan was made.
     *
     * @return array<int,array{path:string, was:string, now:string, change:string}>
     */
    public function drift(array $paths, array $fingerprints): array
    {
        $drift = [];
        foreach ($paths as $path) {
            $was = $fingerprints[$path] ?? null;
            $now = $this->fingerprint($path);
            if ($was === null) {
                $drift[] = ['path' => $path, 'was' => 'not in plan', 'now' => $now, 'change' => 'unplanned'];
                continue;
            }
            if ($was !== $now) {
                $change = $now === 'absent' ? 'deleted since planning' : 'modified since planning';
                $drift[] = ['path' => $path, 'was' => substr($was, 0, 12), 'now' => substr($now, 0, 12), 'change' => $change];
            }
        }

        return $drift;
    }

    /** @return array<int,object> executions recorded for a plan */
    public function executions(int $planId): array
    {
        return DB::table('engineering_executions')
            ->where('plan_id', $planId)
            ->orderBy('group_position')
            ->orderBy('id')
            ->get()
            ->all();
    }

    /** Group positions that have completed successfully. @return array<int,int> */
    public function completedPositions(int $planId): array
    {
        return DB::table('engineering_executions')
            ->where('plan_id', $planId)
            ->where('status', 'completed')
            ->pluck('group_position')
            ->map(fn ($p) => (int) $p)
            ->all();
    }
}
