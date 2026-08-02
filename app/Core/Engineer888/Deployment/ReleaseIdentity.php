<?php

namespace App\Core\Engineer888\Deployment;

use App\Core\Engineer888\Signals\Shell;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * What production can actually prove about the code it is running.
 *
 * THE CENTRAL PROBLEM ON THIS PLATFORM. Production is this git working tree.
 * The tree carries hundreds of uncommitted files which the application executes
 * directly. HEAD is therefore a description of what was last committed, not of
 * what is running — and the difference is the entire reason a deployment can be
 * healthy and its identity still unknown.
 *
 * Every field is returned as Evidence, carrying its source and confidence. The
 * runtime's /health version is deliberately never PROVEN: it is a hardcoded
 * literal in index.js and has read 2.37.3 across separate deployments.
 */
final class ReleaseIdentity
{
    /**
     * Files whose content is hashed as a weak identity fingerprint.
     *
     * Chosen because they change on almost any meaningful deployment and are
     * small enough to hash cheaply. This is a fingerprint, not a manifest: it
     * proves "the same or not the same", never "this exact release".
     */
    private const CRITICAL_FILES = [
        'composer.json',
        'bootstrap/app.php',
        'routes/api.php',
        'routes/web.php',
        'app/Connectors/RuntimeClient.php',
        'public/app/js/core.js',
    ];

    public function __construct(private string $repoPath) {}

    /** @return array<string,Evidence> */
    public function collect(): array
    {
        return array_merge(
            $this->fromGit(),
            $this->fromFilesystem(),
            $this->fromRuntime(),
            $this->fromDatabase(),
            $this->fromProcesses(),
        );
    }

    /** @return array<string,Evidence> */
    private function fromGit(): array
    {
        if (! is_dir($this->repoPath . '/.git')) {
            return ['git' => Evidence::unknown('git', 'production is not a git checkout')];
        }

        $head = trim(Shell::run('git', ['rev-parse', 'HEAD'], $this->repoPath)['out']);
        $branch = trim(Shell::run('git', ['rev-parse', '--abbrev-ref', 'HEAD'], $this->repoPath)['out']);
        $committed = trim(Shell::run('git', ['log', '-1', '--format=%cI'], $this->repoPath)['out']);
        $status = Shell::run('git', ['status', '--porcelain', '-uall'], $this->repoPath);
        $dirty = count(array_filter(explode("\n", trim($status['out']))));

        // The pivotal judgement in this whole capability.
        $headDescribesProduction = $dirty === 0;

        return [
            'git_head' => $head !== ''
                ? new Evidence($head, 'git', $headDescribesProduction ? Evidence::PROVEN : Evidence::OBSERVED,
                    $headDescribesProduction
                        ? null
                        : "the working tree has {$dirty} uncommitted files, so this commit does NOT describe "
                        . 'the code production is executing')
                : Evidence::unknown('git', 'rev-parse returned nothing'),

            'git_branch' => $branch !== ''
                ? Evidence::observed($branch, 'git')
                : Evidence::unknown('git', 'no branch resolved'),

            'git_tree_clean' => new Evidence($dirty === 0, 'git', Evidence::PROVEN,
                $dirty === 0 ? null : "{$dirty} uncommitted files"),

            'git_uncommitted_files' => Evidence::proven($dirty, 'git'),

            'git_last_commit_at' => $committed !== ''
                ? Evidence::proven($committed, 'git')
                : Evidence::unknown('git', 'no commit date'),

            'git_upstream' => Shell::run('git', ['rev-parse', '--abbrev-ref', '--symbolic-full-name', '@{u}'], $this->repoPath)['ok']
                ? Evidence::observed(trim(Shell::run('git', ['rev-parse', '--abbrev-ref', '--symbolic-full-name', '@{u}'], $this->repoPath)['out']), 'git')
                : Evidence::unknown('git', 'branch has no upstream — nothing can be compared against a remote'),
        ];
    }

    /** @return array<string,Evidence> */
    private function fromFilesystem(): array
    {
        $hashes = [];
        $missing = [];

        foreach (self::CRITICAL_FILES as $relative) {
            $full = $this->repoPath . '/' . $relative;
            if (! is_file($full)) { $missing[] = $relative; continue; }
            $hashes[$relative] = substr((string) sha1_file($full), 0, 16);
        }

        // Newest mtime under the application directories: the closest thing to a
        // deployment timestamp when deployments are direct file writes.
        $newest = 0;
        $newestFile = null;
        foreach (['app', 'routes', 'config', 'public/app/js'] as $directory) {
            $result = Shell::run('bash', ['-c',
                'find ' . escapeshellarg($directory) . ' -type f -name "*.php" -o -type f -name "*.js" 2>/dev/null '
                . '| grep -v "\.bak" | xargs -r stat -c "%Y %n" 2>/dev/null | sort -rn | head -1',
            ], $this->repoPath, 60);
            $line = trim($result['out']);
            if ($line === '') { continue; }
            [$timestamp, $file] = array_pad(explode(' ', $line, 2), 2, null);
            if ((int) $timestamp > $newest) { $newest = (int) $timestamp; $newestFile = $file; }
        }

        return [
            'critical_file_hashes' => $hashes !== []
                ? Evidence::proven($hashes, 'filesystem',
                    'a fingerprint, not a release manifest: it proves same-or-different, never which release')
                : Evidence::unknown('filesystem', 'no critical files readable'),

            'critical_files_missing' => $missing === []
                ? Evidence::proven([], 'filesystem')
                : Evidence::proven($missing, 'filesystem', 'expected files absent from production'),

            'newest_application_write' => $newest > 0
                ? Evidence::inferred(
                    ['at' => date('c', $newest), 'file' => $newestFile],
                    'filesystem',
                    'the most recent write under app/, routes/, config/ or public/app/js. On a platform '
                    . 'where deployment is a file copy this approximates deploy time; it is also moved by '
                    . 'any edit, so it is inference, not a deployment record')
                : Evidence::unknown('filesystem', 'could not stat application files'),
        ];
    }

    /** @return array<string,Evidence> */
    private function fromRuntime(): array
    {
        $url = rtrim((string) env('RUNTIME_URL', ''), '/');
        if ($url === '') {
            return ['runtime_version' => Evidence::unknown('runtime', 'RUNTIME_URL is not configured')];
        }

        try {
            $response = Http::timeout(15)->get($url . '/health');
        } catch (\Throwable $e) {
            return ['runtime_version' => Evidence::unknown('runtime', 'unreachable: ' . substr($e->getMessage(), 0, 80))];
        }

        if (! $response->successful()) {
            return ['runtime_version' => Evidence::unknown('runtime', 'health returned HTTP ' . $response->status())];
        }

        $body = $response->json() ?? [];

        return [
            // Never PROVEN. See the class comment.
            'runtime_version' => Evidence::observed($body['version'] ?? null, 'runtime /health',
                'a hardcoded literal in index.js (line 492). It has read the same value across separate '
                . 'deployments and is not build identity'),

            'runtime_status' => Evidence::observed($body['status'] ?? null, 'runtime /health'),
        ];
    }

    /** @return array<string,Evidence> */
    private function fromDatabase(): array
    {
        try {
            $latest = DB::table('migrations')->orderByDesc('id')->first();
            $count = DB::table('migrations')->count();
            $batch = DB::table('migrations')->max('batch');
        } catch (\Throwable $e) {
            return ['migration_state' => Evidence::unknown('database', 'unreadable: ' . substr($e->getMessage(), 0, 80))];
        }

        return [
            'migration_state' => Evidence::proven([
                'applied'      => $count,
                'latest_batch' => $batch,
                'latest'       => $latest->migration ?? null,
            ], 'database'),
        ];
    }

    /** @return array<string,Evidence> */
    private function fromProcesses(): array
    {
        // Worker start times are the best available evidence that a restart
        // followed a code change — app/ edits are only picked up on restart.
        // `ps -C php` matches the process NAME, and these run as php8.3 — the
        // first version of this reported "no queue workers observed" in the same
        // report where the health check counted four. Match the command line.
        $result = Shell::run('bash', ['-c',
            "ps ax -o pid=,etimes=,cmd= 2>/dev/null | grep '[q]ueue:work' | head -5",
        ], $this->repoPath, 30);

        $workers = [];
        foreach (array_filter(explode("\n", trim($result['out']))) as $line) {
            if (preg_match('/^\s*(\d+)\s+(\d+)\s+(.*)$/', $line, $m)) {
                $workers[] = [
                    'pid'            => (int) $m[1],
                    'uptime_seconds' => (int) $m[2],
                    'started_at'     => date('c', time() - (int) $m[2]),
                ];
            }
        }

        $supervisor = Shell::run('bash', ['-c', 'sudo -n supervisorctl status 2>/dev/null'], $this->repoPath, 30);

        return [
            'worker_processes' => $workers !== []
                ? Evidence::proven($workers, 'process table',
                    'workers cache app/ code; a worker older than the newest application write is running stale code')
                : Evidence::unknown('process table', 'no queue workers observed'),

            'supervisor_state' => trim($supervisor['out']) !== ''
                ? Evidence::observed(array_values(array_filter(explode("\n", trim($supervisor['out'])))), 'supervisor')
                : Evidence::unknown('supervisor', 'supervisorctl produced no output'),
        ];
    }

    /**
     * Does the observed state match a recorded intent?
     *
     * @return array<string,mixed>
     */
    public function compareWithIntent(array $identity, ?object $intent): array
    {
        if ($intent === null) {
            return [
                'intent_present'  => false,
                'identity_proven' => false,
                'reason'          => 'no deployment intent was recorded, so there is no expected release to compare '
                                   . 'against. Health can still be assessed; identity cannot.',
                'mismatches'      => [],
            ];
        }

        $mismatches = [];

        if ($intent->expected_commit !== null) {
            $observed = $identity['git_head']->value ?? null;
            if ($observed === null) {
                $mismatches[] = 'expected commit ' . substr($intent->expected_commit, 0, 8)
                    . ' but production HEAD could not be read';
            } elseif (! hash_equals((string) $intent->expected_commit, (string) $observed)) {
                $mismatches[] = 'expected commit ' . substr((string) $intent->expected_commit, 0, 8)
                    . ' but production HEAD is ' . substr((string) $observed, 0, 8);
            }
        }

        if ($intent->expected_branch !== null) {
            $observed = $identity['git_branch']->value ?? null;
            if ($observed !== $intent->expected_branch) {
                $mismatches[] = "expected branch {$intent->expected_branch} but production is on "
                    . ($observed ?? 'an unreadable branch');
            }
        }

        $expectedArtifacts = $intent->expected_artifacts ? json_decode($intent->expected_artifacts, true) : null;
        if (is_array($expectedArtifacts)) {
            $observedHashes = $identity['critical_file_hashes']->value ?? [];
            foreach ($expectedArtifacts as $file => $expectedHash) {
                $actual = $observedHashes[$file] ?? null;
                if ($actual === null) {
                    $mismatches[] = "expected artifact {$file} is not present in production";
                } elseif (! hash_equals((string) $expectedHash, (string) $actual)) {
                    $mismatches[] = "artifact {$file} differs from the expected build";
                }
            }
        }

        // Identity is only proven when something authoritative was checked AND
        // the working tree does not silently differ from it.
        $checkedSomething = $intent->expected_commit !== null || is_array($expectedArtifacts);
        $treeClean = (bool) ($identity['git_tree_clean']->value ?? false);

        return [
            'intent_present'  => true,
            'identity_proven' => $checkedSomething && $mismatches === [] && $treeClean,
            'reason'          => $this->identityReason($checkedSomething, $mismatches, $treeClean),
            'mismatches'      => $mismatches,
        ];
    }

    private function identityReason(bool $checkedSomething, array $mismatches, bool $treeClean): string
    {
        if (! $checkedSomething) {
            return 'the intent records no expected commit and no expected artifacts, so there is nothing '
                 . 'to verify identity against.';
        }
        if ($mismatches !== []) {
            return 'the observed release does not match the recorded intent.';
        }
        if (! $treeClean) {
            return 'the expected commit matches HEAD, but the working tree is dirty — production is running '
                 . 'files that no commit describes, so matching HEAD does not prove what is deployed.';
        }

        return 'the observed release matches the recorded intent and the working tree is clean.';
    }
}
