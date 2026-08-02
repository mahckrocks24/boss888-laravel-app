<?php

namespace App\Core\Engineer888\Execution;

use App\Core\Engineer888\Coordination\GovernedFiles;
use App\Core\Engineer888\Coordination\OwnershipManifest;
use App\Core\Engineer888\Repository\FileClassifier;

/**
 * Everything that must be true before a single file is staged.
 *
 * Each gate is a named check with a pass/fail and a reason. Any failure aborts
 * the group — there is no "warn and continue", because the whole point of this
 * layer is that the engineer does not have to audit what Engineer888 did after
 * the fact.
 *
 * The gates are ordered cheapest-first, and the first failure stops the rest, so
 * a refusal names the earliest reason rather than a pile of consequences.
 */
final class PreflightCheck
{
    public function __construct(
        private string $repoPath,
        private GitGate $git,
        private PlanStore $store,
    ) {}

    /**
     * @param  array<string,mixed>  $group
     * @param  array<string,string>  $fingerprints
     * @return array{passed:bool, gates:array<int,array{id:string,passed:bool,detail:string}>, abort_reason:?string}
     */
    public function run(array $group, array $fingerprints, array $completedPositions, array $plan): array
    {
        $gates = [];
        $abort = null;

        $add = function (string $id, bool $passed, string $detail) use (&$gates, &$abort) {
            $gates[] = ['id' => $id, 'passed' => $passed, 'detail' => $detail];
            if (! $passed && $abort === null) { $abort = $id . ': ' . $detail; }

            return $passed;
        };

        // ── the repository must be in an ordinary state ──────────────────
        $inProgress = [];
        foreach (['MERGE_HEAD' => 'a merge', 'rebase-merge' => 'a rebase', 'rebase-apply' => 'a rebase',
                  'CHERRY_PICK_HEAD' => 'a cherry-pick', 'REVERT_HEAD' => 'a revert'] as $marker => $what) {
            if (file_exists($this->repoPath . '/.git/' . $marker)) { $inProgress[] = $what; }
        }
        if (! $add('repository-state', $inProgress === [],
            $inProgress === [] ? 'no merge, rebase or cherry-pick in progress'
                               : 'the repository is mid-' . implode('/', array_unique($inProgress)))) {
            return $this->result($gates, $abort);
        }

        $head = $this->git->run('symbolic-ref', ['-q', 'HEAD']);
        if (! $add('attached-head', trim($head['out']) !== '',
            trim($head['out']) !== '' ? 'on branch ' . basename(trim($head['out'])) : 'HEAD is detached')) {
            return $this->result($gates, $abort);
        }

        // ── the index must be ours alone ─────────────────────────────────
        $staged = $this->stagedFiles();
        if (! $add('empty-index', $staged === [],
            $staged === [] ? 'nothing was already staged'
                           : count($staged) . ' file(s) already staged by someone else: ' . implode(', ', array_slice($staged, 0, 3)))) {
            return $this->result($gates, $abort);
        }

        // ── the plan must still describe reality ─────────────────────────
        $drift = $this->store->drift($group['files'], $fingerprints);
        if (! $add('plan-current', $drift === [],
            $drift === []
                ? count($group['files']) . ' file(s) unchanged since the plan was generated'
                : count($drift) . ' file(s) changed since planning: '
                  . implode('; ', array_map(fn ($d) => $d['path'] . ' (' . $d['change'] . ')', array_slice($drift, 0, 3))))) {
            return $this->result($gates, $abort);
        }

        // ── every planned file must exist and be readable ────────────────
        $missing = [];
        foreach ($group['files'] as $path) {
            $full = $this->repoPath . '/' . $path;
            // A planned deletion is legitimately absent.
            if (! file_exists($full) && ! $this->isPlannedDeletion($path, $plan)) { $missing[] = $path; }
        }
        if (! $add('files-present', $missing === [],
            $missing === [] ? 'every planned file is on disk or is a planned deletion'
                            : count($missing) . ' planned file(s) missing: ' . implode(', ', array_slice($missing, 0, 3)))) {
            return $this->result($gates, $abort);
        }

        // ── nothing in this group may be an artifact ─────────────────────
        $forbidden = [];
        foreach ($plan['files'] as $file) {
            if (! in_array($file['path'], $group['files'], true)) { continue; }
            if (in_array($file['analysis']['classification'], [
                FileClassifier::BACKUP, FileClassifier::TEMPORARY,
                FileClassifier::GENERATED, FileClassifier::MERGE,
            ], true)) {
                $forbidden[] = $file['path'] . ' (' . $file['analysis']['classification'] . ')';
            }
            if (in_array('may-contain-session-token', $file['analysis']['flags'], true)) {
                $forbidden[] = $file['path'] . ' (may carry authentication material)';
            }
        }
        if (! $add('no-artifacts', $forbidden === [],
            $forbidden === [] ? 'no backups, debris, generated output or secret-bearing files'
                              : 'refusing to stage: ' . implode('; ', $forbidden))) {
            return $this->result($gates, $abort);
        }

        // ── no conflict markers, checked from the file itself ────────────
        $conflicted = [];
        foreach ($group['files'] as $path) {
            $full = $this->repoPath . '/' . $path;
            if (! is_file($full) || filesize($full) > 4 * 1024 * 1024) { continue; }
            $head = (string) @file_get_contents($full, false, null, 0, 262144);
            if (preg_match('/^(<{7}|>{7}) /m', $head)) { $conflicted[] = $path; }
        }
        if (! $add('no-conflict-markers', $conflicted === [],
            $conflicted === [] ? 'no unresolved conflict markers'
                               : 'conflict markers in ' . implode(', ', $conflicted))) {
            return $this->result($gates, $abort);
        }

        // ── files git is configured to ignore must not be forced in ──────
        $ignored = [];
        foreach ($group['files'] as $path) {
            $check = $this->git->run('check-ignore', [$path]);
            if (trim($check['out']) !== '') { $ignored[] = $path; }
        }
        if (! $add('not-ignored', $ignored === [],
            $ignored === [] ? 'no planned file is covered by .gitignore'
                            : 'gitignored file(s) in the group: ' . implode(', ', $ignored)
                              . ' — staging these would require a force flag, which is never permitted')) {
            return $this->result($gates, $abort);
        }

        // ── ownership, re-proved at staging time ─────────────────────────
        // The planner already excluded unowned files. This gate exists because
        // the plan is stored and executed later, and because a control with one
        // implementation is a control with one place to fail.
        $manifest = OwnershipManifest::active($this->repoPath);
        if (! $add('sprint-manifest', $manifest !== null,
            $manifest !== null
                ? 'sprint ' . $manifest->sprint() . ' (' . $manifest->engineer() . ')'
                : 'no active sprint manifest — ownership cannot be proven for any file')) {
            return $this->result($gates, $abort);
        }

        $unowned = [];
        $undeclared = [];
        foreach ($group['files'] as $path) {
            $ownership = $manifest->classify($path);
            if (! OwnershipManifest::isCommittable($ownership['status'])) {
                $unowned[] = $path . ' (' . $ownership['status'] . ')';
                continue;
            }
            if (GovernedFiles::isGoverned($path) && $ownership['status'] !== OwnershipManifest::SHARED_DECLARED) {
                $undeclared[] = $path;
            }
        }

        if (! $add('ownership-proven', $unowned === [],
            $unowned === []
                ? count($group['files']) . ' file(s) owned by this sprint or declared shared'
                : count($unowned) . ' file(s) with no ownership evidence: ' . implode(', ', array_slice($unowned, 0, 3))
                  . ' — technical coherence is not ownership')) {
            return $this->result($gates, $abort);
        }

        if (! $add('governed-declared', $undeclared === [],
            $undeclared === []
                ? 'no undeclared governed shared files'
                : 'governed file(s) not declared in the manifest: ' . implode(', ', $undeclared))) {
            return $this->result($gates, $abort);
        }

        // ── ordering: groups this one depends on must already be done ────
        $unmet = [];
        foreach (($group['must_follow'] ?? []) as $dependency) {
            $position = $this->positionOf($dependency['group'], $plan);
            if ($position !== null && ! in_array($position, $completedPositions, true)) {
                $unmet[] = $dependency['group'] . ' (' . $dependency['reason'] . ')';
            }
        }
        // Reported, not fatal: the plan itself records where the order had to be
        // broken, and refusing here would make those groups permanently
        // unexecutable. The engineer is told, and decides.
        $add('dependencies-committed', true, $unmet === []
            ? 'every group this one depends on is already committed'
            : 'ADVISORY — ' . count($unmet) . ' dependency group(s) not yet committed: '
              . implode('; ', array_slice($unmet, 0, 2)));

        return $this->result($gates, $abort);
    }

    /** @return array<int,string> */
    public function stagedFiles(): array
    {
        $res = $this->git->run('diff', ['--cached', '--name-only']);

        return array_values(array_filter(preg_split('/\r\n|\n|\r/', $res['out']) ?: [], fn ($l) => trim($l) !== ''));
    }

    private function isPlannedDeletion(string $path, array $plan): bool
    {
        foreach ($plan['files'] as $file) {
            if ($file['path'] === $path) { return (bool) $file['deleted']; }
        }

        return false;
    }

    private function positionOf(string $groupKey, array $plan): ?int
    {
        foreach ($plan['commit_plan']['groups'] as $group) {
            if ($group['key'] === $groupKey) { return (int) $group['position']; }
        }

        return null;
    }

    private function result(array $gates, ?string $abort): array
    {
        return ['passed' => $abort === null, 'gates' => $gates, 'abort_reason' => $abort];
    }
}
