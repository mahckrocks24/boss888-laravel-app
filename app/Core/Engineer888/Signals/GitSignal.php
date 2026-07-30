<?php

namespace App\Core\Engineer888\Signals;

use App\Core\Engineer888\Repository\WorkingTree;

/**
 * Repository state and hygiene.
 *
 * WHY THIS IS THE FIRST SIGNAL
 * The 2026-07-29 audits established that this repository cannot currently
 * deliver reviewed code to production: the branch has no upstream, has never
 * fetched, and last merged 82 days ago, while the working tree carries 150+
 * uncommitted files. Every one of those facts is invisible day to day and
 * silently worsens. Measuring them daily is the cheapest way to stop the drift
 * being discovered by an audit six months from now.
 *
 * 2026-07-30 — the porcelain parsing moved to WorkingTree so Repository
 * Intelligence and this signal cannot disagree about what changed. That move
 * also corrected a real undercount: `git status --porcelain` collapses an
 * untracked DIRECTORY to one entry, so nine new files under
 * app/Core/Engineer888/ counted as one. The counts below are now the expanded
 * ones (394 rather than 176 on this repository), and both numbers are reported
 * so the difference is visible rather than a silent change in meaning.
 */
final class GitSignal implements Signal
{
    public function __construct(private string $repoPath) {}

    public function key(): string { return 'git'; }
    public function label(): string { return 'Repository'; }

    public function collect(): array
    {
        if (! is_dir($this->repoPath . '/.git')) {
            return ['available' => false, 'reason' => 'not a git checkout'];
        }

        $branch = Shell::run('git', ['rev-parse', '--abbrev-ref', 'HEAD'], $this->repoPath);
        $commit = Shell::run('git', ['rev-parse', '--short', 'HEAD'], $this->repoPath);
        $upstream = Shell::run('git', ['rev-parse', '--abbrev-ref', '--symbolic-full-name', '@{u}'], $this->repoPath);
        $lastCommit = Shell::run('git', ['log', '-1', '--format=%cI'], $this->repoPath);

        $tree = WorkingTree::read($this->repoPath);

        $byArea = $tree['by_area'] ?? [];

        $bak = Shell::run('bash', ['-c', 'find . -name "*.bak*" -not -path "./vendor/*" -type f | wc -l'], $this->repoPath);

        return [
            'available'            => true,
            'branch'               => trim($branch['out']) ?: 'unknown',
            'commit'               => trim($commit['out']) ?: 'unknown',
            'has_upstream'         => $upstream['ok'],
            'upstream'             => $upstream['ok'] ? trim($upstream['out']) : null,
            // Expanded count — every untracked file, not every untracked entry.
            'uncommitted_total'    => $tree['expanded_files'] ?? 0,
            // What `git status` alone would have reported. Kept so the gap is
            // legible instead of looking like a sudden jump in dirtiness.
            'uncommitted_entries'  => $tree['collapsed_entries'] ?? 0,
            'uncommitted_app'      => $byArea['app'] ?? 0,
            'uncommitted_by_area'  => array_slice($byArea, 0, 6, true),
            'last_commit_at'       => trim($lastCommit['out']) ?: null,
            'backup_files'         => (int) trim($bak['out']),
        ];
    }
}
