<?php

namespace App\Core\Engineer888\Baseline;

use App\Core\Engineer888\Coordination\OwnershipManifest;
use App\Core\Engineer888\Repository\WorkingTree;
use App\Core\Engineer888\Signals\Shell;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * An immutable, hash-verifiable record of the working tree and platform at a
 * moment in time.
 *
 * WHY. Sprint 5 commits files while other engineers are actively editing the
 * same tree. Without a snapshot there is no way to tell, at commit time,
 * whether a file changed since it was classified — and staging a file whose
 * content moved underneath the analysis is how another engineer's half-finished
 * work gets captured. The snapshot turns that from a hope into a check.
 *
 * The snapshot is a record, not a lock. Other engineers are not stopped; drift
 * is detected and the affected file is dropped from its group.
 */
final class BaselineSnapshot
{
    public const DIRECTORY = '.engineer888/baselines';

    public const VERSION = 1;

    public function __construct(private string $repoPath) {}

    /**
     * Capture the current state. Returns the manifest and writes it.
     *
     * @return array<string,mixed>
     */
    public function capture(string $label = 'baseline'): array
    {
        $tree = WorkingTree::read($this->repoPath);
        if (! ($tree['available'] ?? false)) {
            throw new RuntimeException('cannot capture a baseline: ' . ($tree['reason'] ?? 'working tree unreadable'));
        }

        $files = [];
        foreach ($tree['files'] as $file) {
            $full = $this->repoPath . '/' . $file['path'];
            $files[$file['path']] = [
                'status' => $file['status'],
                'hash'   => is_file($full) ? sha1_file($full) : 'absent',
                'size'   => is_file($full) ? filesize($full) : 0,
                'mtime'  => is_file($full) ? filemtime($full) : null,
            ];
        }
        ksort($files);

        $manifest = OwnershipManifest::active($this->repoPath);

        $snapshot = [
            'snapshot_version' => self::VERSION,
            'label'            => $label,
            'captured_at'      => gmdate('c'),
            'captured_by'      => $manifest?->session(),
            'ownership_manifest' => $manifest !== null
                ? ltrim(str_replace($this->repoPath, '', $manifest->path()), '/') : null,

            'git' => [
                'head'       => trim(Shell::run('git', ['rev-parse', 'HEAD'], $this->repoPath)['out']),
                'branch'     => trim(Shell::run('git', ['rev-parse', '--abbrev-ref', 'HEAD'], $this->repoPath)['out']),
                'dirty_count' => count($files),
                'upstream'   => Shell::run('git', ['rev-parse', '--abbrev-ref', '--symbolic-full-name', '@{u}'], $this->repoPath)['ok']
                    ? trim(Shell::run('git', ['rev-parse', '--abbrev-ref', '--symbolic-full-name', '@{u}'], $this->repoPath)['out'])
                    : null,
            ],

            'platform'   => $this->platform(),
            'migrations' => $this->migrations(),
            'files'      => $files,
        ];

        // The integrity hash covers everything except itself, so a later run can
        // prove the snapshot has not been edited.
        $snapshot['integrity'] = sha1(json_encode($snapshot));

        $path = $this->write($snapshot, $label);
        $snapshot['path'] = ltrim(str_replace($this->repoPath, '', $path), '/');

        return $snapshot;
    }

    /**
     * Compare the current tree against a stored snapshot.
     *
     * @return array<string,mixed>
     */
    public function verify(string $snapshotPath): array
    {
        $full = str_starts_with($snapshotPath, '/') ? $snapshotPath : $this->repoPath . '/' . $snapshotPath;
        $raw = @file_get_contents($full);
        if ($raw === false) { throw new RuntimeException("cannot read snapshot {$full}"); }

        $snapshot = json_decode($raw, true);
        if (! is_array($snapshot) || ! isset($snapshot['files'])) {
            throw new RuntimeException("{$full} is not a usable baseline snapshot");
        }

        // Prove the snapshot itself was not edited.
        $claimed = $snapshot['integrity'] ?? null;
        $copy = $snapshot;
        unset($copy['integrity'], $copy['path']);
        $recomputed = sha1(json_encode($copy));
        $intact = $claimed !== null && hash_equals((string) $claimed, $recomputed);

        $changed = [];
        $removed = [];
        foreach ($snapshot['files'] as $path => $recorded) {
            $file = $this->repoPath . '/' . $path;
            if (! is_file($file)) {
                if ($recorded['hash'] !== 'absent') { $removed[] = $path; }
                continue;
            }
            $now = sha1_file($file);
            if ($now !== $recorded['hash']) {
                $changed[] = ['path' => $path, 'was' => substr((string) $recorded['hash'], 0, 12), 'now' => substr((string) $now, 0, 12)];
            }
        }

        // Files that appeared after the snapshot — another engineer at work.
        $tree = WorkingTree::read($this->repoPath);
        $appeared = [];
        foreach (($tree['files'] ?? []) as $file) {
            if (! isset($snapshot['files'][$file['path']])) { $appeared[] = $file['path']; }
        }

        return [
            'snapshot'          => $snapshotPath,
            'captured_at'       => $snapshot['captured_at'] ?? null,
            'integrity_intact'  => $intact,
            'head_then'         => $snapshot['git']['head'] ?? null,
            'head_now'          => trim(Shell::run('git', ['rev-parse', 'HEAD'], $this->repoPath)['out']),
            'changed'           => $changed,
            'removed'           => $removed,
            'appeared'          => $appeared,
            'drift_detected'    => $changed !== [] || $removed !== [] || $appeared !== [],
        ];
    }

    /** Has this specific file changed since the snapshot? */
    public function hasDrifted(array $snapshot, string $path): bool
    {
        $recorded = $snapshot['files'][$path] ?? null;
        if ($recorded === null) { return true; }   // unknown to the snapshot is drift

        $full = $this->repoPath . '/' . $path;
        if (! is_file($full)) { return $recorded['hash'] !== 'absent'; }

        return sha1_file($full) !== $recorded['hash'];
    }

    /** @return array<int,string> newest first */
    public function list(): array
    {
        $found = glob($this->repoPath . '/' . self::DIRECTORY . '/*.json') ?: [];
        usort($found, fn ($a, $b) => filemtime($b) <=> filemtime($a));

        return array_map(fn ($p) => ltrim(str_replace($this->repoPath, '', $p), '/'), $found);
    }

    public function latest(): ?string
    {
        return $this->list()[0] ?? null;
    }

    // ── internals ───────────────────────────────────────────────────────

    private function platform(): array
    {
        $counts = [];
        foreach (['users', 'workspaces', 'tasks', 'task_events', 'api_usage_logs'] as $table) {
            try { $counts[$table] = DB::table($table)->count(); } catch (\Throwable) { $counts[$table] = null; }
        }

        $workers = Shell::run('bash', ['-c', "ps ax -o pid= -o cmd= | grep '[q]ueue:work' | wc -l"], $this->repoPath, 30);

        return [
            'database'        => DB::connection()->getDatabaseName(),
            'table_counts'    => $counts,
            'queue_workers'   => (int) trim($workers['out']),
            'scheduler_log_age' => is_file('/var/log/laravel-scheduler.log')
                ? time() - (int) filemtime('/var/log/laravel-scheduler.log') : null,
        ];
    }

    private function migrations(): array
    {
        try {
            $applied = DB::table('migrations')->pluck('migration')->all();
        } catch (\Throwable $e) {
            return ['error' => substr($e->getMessage(), 0, 100)];
        }

        $set = array_flip($applied);
        $pending = [];
        foreach (glob($this->repoPath . '/database/migrations/*.php') ?: [] as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);
            if (! isset($set[$name])) { $pending[] = $name; }
        }
        sort($pending);

        return ['applied_count' => count($applied), 'pending' => $pending];
    }

    private function write(array $snapshot, string $label): string
    {
        $directory = $this->repoPath . '/' . self::DIRECTORY;
        if (! is_dir($directory) && ! mkdir($directory, 0775, true)) {
            throw new RuntimeException("cannot create {$directory}");
        }

        $name = gmdate('Ymd-His') . '-' . preg_replace('/[^a-z0-9-]+/i', '-', $label) . '.json';
        $path = $directory . '/' . $name;

        $temp = $path . '.tmp';
        file_put_contents($temp, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        if (! rename($temp, $path)) {
            @unlink($temp);
            throw new RuntimeException("cannot move snapshot into place");
        }

        return $path;
    }
}
