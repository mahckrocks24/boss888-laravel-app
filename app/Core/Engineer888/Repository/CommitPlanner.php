<?php

namespace App\Core\Engineer888\Repository;

/**
 * Turns a changed set into an ordered sequence of proposed commits.
 *
 * The requirement is explicit: never simply list files, explain WHY they belong
 * together. So a group's rationale is not a template — it is generated from the
 * group's actual composition. A group containing a migration, a model, a service
 * and a test is described as a vertical slice; a group of ten console commands is
 * described as a command surface; a group of one file says so plainly.
 *
 * ORDERING is derived, not asserted. Two constraints decide it:
 *   1. Schema before the code that expects it. A commit containing migrations
 *      must precede commits whose files depend on that subsystem, because the
 *      reverse order produces a commit that cannot run.
 *   2. Dependency direction. If any file in group A imports a file in group B,
 *      B is committed first, so that every commit in the sequence is one where
 *      the tree still resolves.
 * Cycles between groups are REPORTED rather than silently broken — a cycle means
 * the split is wrong, and hiding it would produce a plan that looks valid and
 * is not.
 */
final class CommitPlanner
{
    /** Cleanup and documentation are separated out of feature commits. */
    private const CLEANUP = [
        FileClassifier::BACKUP,
        FileClassifier::TEMPORARY,
        FileClassifier::GENERATED,
    ];

    public function __construct(private ArchitectureMap $map) {}

    /**
     * @param  array<int,array<string,mixed>>  $analysed
     * @return array{groups:array<int,array<string,mixed>>, order_conflicts:array<int,string>, excluded:array<string,mixed>}
     */
    public function plan(array $analysed, DependencyGraph $graph): array
    {
        $groups = [];
        $excluded = ['do_not_commit' => [], 'blocked' => []];
        $units = $this->cohesiveUnits($analysed);

        foreach ($analysed as $file) {
            $classification = $file['analysis']['classification'];

            // Debris never becomes a commit; it becomes a deletion.
            if (in_array($classification, self::CLEANUP, true)) {
                $excluded['do_not_commit'][] = ['path' => $file['path'], 'why' => $file['analysis']['reason']];
                continue;
            }
            // Conflict markers block everything; they are not committable at all.
            if ($classification === FileClassifier::MERGE) {
                $excluded['blocked'][] = ['path' => $file['path'], 'why' => $file['analysis']['reason']];
                continue;
            }

            $key = $this->groupKey($file, $units);
            $groups[$key]['files'][] = $file;
        }

        // Build each group's description from what is actually in it.
        $built = [];
        foreach ($groups as $key => $group) {
            $built[$key] = $this->describe($key, $group['files']);
        }

        // Order by dependency direction and schema-first.
        [$ordered, $conflicts] = $this->order($built, $graph);

        return ['groups' => $ordered, 'order_conflicts' => $conflicts, 'excluded' => $excluded];
    }

    /**
     * Group identity. Documentation is grouped separately from code even within
     * the same subsystem, because a docs commit carries no deployment risk and
     * mixing them means the risky commit cannot be reverted on its own.
     */
    private function groupKey(array $file, array $units): string
    {
        $classification = $file['analysis']['classification'];
        $subsystem = $file['subsystem']['subsystem'];

        if ($classification === FileClassifier::DOCUMENTATION) {
            return 'docs';
        }
        if ($classification === FileClassifier::ABANDONED) {
            return 'review:' . $subsystem;
        }
        // Co-creation beats name matching. See cohesiveUnits().
        if (isset($units[$file['path']])) {
            return 'unit:' . $units[$file['path']];
        }

        // A single "unattributed" group is a grab-bag, and a grab-bag creates
        // false circularity. tests/TestCase.php and RuntimeClient.php landed in
        // the same group; almost every test imports the former and several
        // subsystems are imported by the latter, so that one group was
        // simultaneously upstream and downstream of everything, and the ordering
        // pass reported all 25 groups as one cycle. Splitting by layer keeps
        // shared infrastructure separable from the things that use it.
        if ($subsystem === 'unattributed') {
            return 'shared:' . $this->map->layer($file['path']);
        }

        return 'feature:' . $subsystem;
    }

    /**
     * Directories whose changed files were plainly created as one piece of work.
     *
     * WHY THIS EXISTS. Subsystem attribution is right per file and wrong per
     * commit. The admin multi-page conversion added 25 files to
     * resources/views/admin/pages/ — memory.blade.php, queue.blade.php,
     * orchestration.blade.php and so on. Each name-matches a different subsystem,
     * so the first plan proposed twenty single-file commits for one change.
     *
     * The evidence that overrides the name is co-creation:
     *   · at least 3 changed files in the same directory
     *   · ALL of them untracked, i.e. none existed before
     *   · modification times within 48 hours of each other
     *   · their individual names imply MORE THAN ONE subsystem
     *
     * The last two conditions are what stop this from over-merging. public/app/js/
     * also holds files from several subsystems, but it is a mix of long-standing
     * tracked files and a few new ones, so it fails the "all untracked" test and
     * its files stay attributed to their own features — which is correct, because
     * they are separate work that happens to share a flat directory.
     *
     * @return array<string,string> file path => directory that owns it
     */
    private function cohesiveUnits(array $analysed): array
    {
        $byDirectory = [];
        foreach ($analysed as $file) {
            // Artifacts and docs never form a unit.
            if (in_array($file['analysis']['classification'],
                array_merge(self::CLEANUP, [FileClassifier::DOCUMENTATION, FileClassifier::MERGE]), true)) {
                continue;
            }
            $dir = dirname($file['path']);
            if ($dir === '.' || $dir === '') { continue; }   // repository root is not a unit
            $byDirectory[$dir][] = $file;
        }

        $units = [];
        foreach ($byDirectory as $dir => $files) {
            if (count($files) < 3) { continue; }

            $subsystems = [];
            $mtimes = [];
            foreach ($files as $file) {
                if (! $file['untracked']) { continue 2; }         // pre-existing file: not one new unit
                $subsystems[$file['subsystem']['subsystem']] = true;
                if ($file['mtime'] !== null) { $mtimes[] = $file['mtime']; }
            }

            if (count($subsystems) < 2) { continue; }             // already one subsystem; leave it there
            if ($mtimes === []) { continue; }
            if (max($mtimes) - min($mtimes) > 48 * 3600) { continue; }

            foreach ($files as $file) { $units[$file['path']] = $dir; }
        }

        return $units;
    }

    /** @return array<string,mixed> */
    private function describe(string $key, array $files): array
    {
        usort($files, fn ($a, $b) => $a['path'] <=> $b['path']);

        $layers = [];
        $classifications = [];
        $flags = [];
        foreach ($files as $file) {
            $layers[$this->map->layer($file['path'])] = true;
            $classifications[$file['analysis']['classification']] = true;
            foreach ($file['analysis']['flags'] as $flag) { $flags[$flag] = true; }
        }
        $layers = array_keys($layers);
        sort($layers);
        $classifications = array_keys($classifications);
        sort($classifications);
        $flags = array_keys($flags);
        sort($flags);

        [$kind, $subsystem] = array_pad(explode(':', $key, 2), 2, '');

        // A unit is identified by its directory; name it after the subsystems its
        // files belong to, so the label says what the work is.
        $unitDirectory = null;
        if ($kind === 'unit') {
            $unitDirectory = $subsystem;
            $members = [];
            foreach ($files as $file) { $members[$file['subsystem']['subsystem']] = true; }
            unset($members['unattributed']);
            $members = array_keys($members);
            sort($members);
            $subsystem = $unitDirectory;
        }

        return [
            'key'             => $key,
            'kind'            => $kind,
            'subsystem'       => $subsystem ?: 'documentation',
            'title'           => $this->title($kind, $subsystem, $layers, $files),
            'rationale'       => $this->rationale($kind, $subsystem, $layers, $files),
            'files'           => array_map(fn ($f) => $f['path'], $files),
            'file_count'      => count($files),
            'layers'          => $layers,
            'classifications' => $classifications,
            'flags'           => $flags,
            'has_migration'   => in_array('migration', $layers, true),
            'has_test'        => in_array('test', $layers, true),
            'blocking'        => $this->blocking($files, $flags, $layers),
            'insertions'      => array_sum(array_map(fn ($f) => (int) ($f['insertions'] ?? 0), $files)),
        ];
    }

    private function title(string $kind, string $subsystem, array $layers, array $files): string
    {
        if ($kind === 'docs') {
            return 'Engineering records and documentation (' . count($files) . ' files)';
        }
        if ($kind === 'review') {
            return $subsystem . ' — needs a decision before commit';
        }
        if ($kind === 'unit') {
            return $subsystem . '/ — ' . count($files) . ' new files added together';
        }
        if ($kind === 'shared') {
            return 'Shared ' . $subsystem . ' — not attributable to one feature';
        }
        if ($subsystem === 'unattributed') {
            return 'Unattributed changes — ' . implode(', ', array_slice($layers, 0, 3));
        }

        $shape = $this->shape($layers);

        return $subsystem . ' — ' . $shape;
    }

    /**
     * What this group of layers IS. Named from the composition rather than
     * described as "changes to N files".
     */
    private function shape(array $layers): string
    {
        $has = fn (string ...$names) => array_intersect($names, $layers) !== [];

        $verticalParts = 0;
        foreach ([['migration'], ['model'], ['core', 'service', 'engine'], ['controller'], ['test']] as $tier) {
            if ($has(...$tier)) { $verticalParts++; }
        }
        if ($verticalParts >= 4) { return 'complete vertical slice'; }
        if ($verticalParts === 3) { return 'feature implementation'; }

        if ($has('command') && count($layers) <= 2) { return 'console command surface'; }
        if ($has('migration') && count($layers) === 1) { return 'schema only'; }
        if ($has('test') && count($layers) === 1) { return 'tests only'; }
        if ($has('frontend', 'view', 'public-asset') && ! $has('core', 'service', 'engine', 'controller')) {
            return 'frontend only';
        }
        if ($has('config', 'routing', 'bootstrap', 'provider')) { return 'wiring and configuration'; }

        return implode(' + ', array_slice($layers, 0, 4));
    }

    /** The WHY. Built from evidence in the group, never a fixed sentence. */
    private function rationale(string $kind, string $subsystem, array $layers, array $files): string
    {
        if ($kind === 'docs') {
            return 'Markdown only — no runtime effect, so this can be committed at any point without '
                 . 'deployment risk. Kept separate so a code commit can be reverted without losing the record.';
        }
        if ($kind === 'review') {
            return 'These are new classes that nothing references, or files named as experiments. They '
                 . 'cannot be described as complete work, and committing them as such would record a '
                 . 'feature that does not run. Decide per file: wire it in, or delete it.';
        }
        if ($kind === 'unit') {
            $names = [];
            foreach ($files as $file) { $names[$file['subsystem']['subsystem']] = true; }
            unset($names['unattributed']);
            $names = array_keys($names);
            sort($names);

            return 'All ' . count($files) . ' files are new, live in ' . $subsystem . '/, and were created '
                 . 'within the same 48 hours. Their individual names suggest '
                 . count($names) . ' different subsystems (' . implode(', ', array_slice($names, 0, 6))
                 . (count($names) > 6 ? ', …' : '') . '), but they did not arrive separately — they are one '
                 . 'change to this directory and splitting them by name would produce '
                 . count($names) . ' commits for one piece of work.';
        }

        if ($kind === 'shared') {
            return 'These ' . $subsystem . ' files could not be attributed to a single feature — they are '
                 . 'either shared infrastructure or the file names carry no subsystem evidence. Grouped by '
                 . 'layer rather than lumped together, because shared code is usually a DEPENDENCY of the '
                 . 'feature commits and needs to go in before them, while a stray file does not.';
        }

        $counts = [];
        foreach ($files as $file) { $counts[$this->map->layer($file['path'])] = ($counts[$this->map->layer($file['path'])] ?? 0) + 1; }
        $parts = [];
        foreach ($counts as $layer => $n) { $parts[] = $n . ' ' . $layer . ($n > 1 ? 's' : ''); }

        $why = 'All of these belong to the ' . ($subsystem === 'unattributed' ? 'same unattributed set' : $subsystem . ' subsystem')
             . ' — ' . implode(', ', $parts) . '. ';

        if (in_array('migration', $layers, true)) {
            $why .= 'It contains a schema change, so the code that reads the new tables must not be '
                  . 'committed ahead of it. ';
        }
        if (! in_array('test', $layers, true) && array_intersect(['core', 'service', 'engine', 'controller', 'job'], $layers) !== []) {
            $why .= 'There is no test in this group, so nothing here is proven by the suite. ';
        } elseif (in_array('test', $layers, true)) {
            $why .= 'Tests are included, so this commit is self-verifying. ';
        }
        if (array_intersect(['routing', 'config', 'bootstrap', 'provider'], $layers) !== []) {
            $why .= 'It also changes application wiring, which affects surfaces outside this subsystem. ';
        }

        return trim($why);
    }

    /**
     * Reasons this group must not be committed as-is, each naming the files
     * responsible.
     *
     * The files are not decoration. The first version returned reason strings
     * only, and the renderer printed them above the group's file list — so when a
     * 61-file Chat group reported "contains debug output", the first file listed
     * looked like the cause and was not. A blocker that does not say which file
     * to open is an observation, not an instruction.
     *
     * @return array<int,array{reason:string, files:array<int,string>}>
     */
    private function blocking(array $files, array $flags, array $layers): array
    {
        $withFlag = function (string $flag) use ($files): array {
            $out = [];
            foreach ($files as $file) {
                if (in_array($flag, $file['analysis']['flags'], true)) { $out[] = $file['path']; }
            }
            sort($out);

            return $out;
        };

        $out = [];

        $debug = $withFlag('debug-statements');
        if ($debug !== []) {
            $out[] = [
                'reason' => 'Debug output would run in production — remove it before committing.',
                'files'  => $debug,
            ];
        }

        $unfinished = $withFlag('unfinished-markers');
        if ($unfinished !== []) {
            $out[] = [
                'reason' => 'Unfinished markers present — commit as work in progress, not as a completed feature.',
                'files'  => $unfinished,
            ];
        }

        $governed = $withFlag('governed-path');
        if ($governed !== []) {
            $out[] = [
                'reason' => 'Under the routes governance lock — write through GovernedWriter and update the route baseline.',
                'files'  => $governed,
            ];
        }

        $deletions = $withFlag('deletion');
        if ($deletions !== []) {
            $out[] = [
                'reason' => 'Deletion — confirm nothing still references it before committing.',
                'files'  => $deletions,
            ];
        }

        return $out;
    }

    /**
     * @return array{0:array<int,array<string,mixed>>,1:array<int,string>}
     */
    private function order(array $groups, DependencyGraph $graph): array
    {
        $keys = array_keys($groups);

        // Which group owns each file.
        $owner = [];
        foreach ($groups as $key => $group) {
            foreach ($group['files'] as $path) { $owner[$path] = $key; }
        }

        // Edges: dependency => dependent (the dependency is committed first).
        $edges = [];
        $reasons = [];
        foreach ($groups as $key => $group) {
            foreach ($group['files'] as $path) {
                foreach ($graph->dependsOn($path) as $target) {
                    $targetGroup = $owner[$target] ?? null;
                    if ($targetGroup === null || $targetGroup === $key) { continue; }
                    $edges[$targetGroup][$key] = true;
                    $reasons[$targetGroup . '=>' . $key] = $path . ' imports ' . $target;
                }
            }
        }

        // Schema before code: a migration-bearing group precedes every other
        // group in the same subsystem.
        foreach ($groups as $key => $group) {
            if (! $group['has_migration']) { continue; }
            foreach ($groups as $otherKey => $other) {
                if ($otherKey === $key || $other['subsystem'] !== $group['subsystem']) { continue; }
                $edges[$key][$otherKey] = true;
                $reasons[$key . '=>' . $otherKey] = 'schema must exist before code that reads it';
            }
        }

        // Kahn, with a deterministic tie-break so the plan is stable run to run.
        //
        // A codebase with 759 classes and shared infrastructure WILL contain
        // cycles between any grouping of it. Reporting "no order exists" is
        // technically true and useless — the engineer still has to commit
        // something. So when the queue stalls, the group with the least inbound
        // dependency weight is emitted anyway and the constraints that this
        // violates are named individually. A usable order plus an explicit list
        // of what it breaks beats a correct refusal.
        $indegree = array_fill_keys($keys, 0);
        foreach ($edges as $tos) {
            foreach (array_keys($tos) as $to) { $indegree[$to] = ($indegree[$to] ?? 0) + 1; }
        }

        $remaining = $keys;
        sort($remaining);
        $ordered = [];
        $conflicts = [];

        while ($remaining !== []) {
            $ready = array_values(array_filter($remaining, fn ($k) => ($indegree[$k] ?? 0) === 0));

            if ($ready === []) {
                // Stalled: every remaining group waits on another. Pick the one
                // with the fewest unmet dependencies and record what it breaks.
                usort($remaining, fn ($a, $b) => [$indegree[$a] ?? 0, $a] <=> [$indegree[$b] ?? 0, $b]);
                $forced = $remaining[0];

                $broken = [];
                foreach ($edges as $from => $tos) {
                    if (! isset($tos[$forced])) { continue; }
                    if (in_array($from, $ordered, true)) { continue; }   // already satisfied
                    $broken[] = $reasons[$from . '=>' . $forced] ?? ('depends on ' . $from);
                }

                $conflicts[] = 'Committing "' . $forced . '" at position ' . (count($ordered) + 1)
                    . ' breaks ' . count($broken) . ' dependency constraint(s), because those groups '
                    . 'have not been committed yet: ' . implode('; ', array_slice($broken, 0, 3))
                    . (count($broken) > 3 ? ' (and ' . (count($broken) - 3) . ' more)' : '')
                    . '. The tree will not fully resolve until the whole sequence is committed — commit '
                    . 'these together, or decouple them first.';

                $ready = [$forced];
                $indegree[$forced] = 0;
            }

            // Among groups that are ready, commit the one the most other groups
            // are waiting on. Alphabetical order would put shared infrastructure
            // late and force violations that a better choice avoids. Name breaks
            // ties so the plan is identical on every run.
            usort($ready, fn ($a, $b) => [count($edges[$b] ?? []), $a] <=> [count($edges[$a] ?? []), $b]);
            $key = $ready[0];
            $ordered[] = $key;
            $remaining = array_values(array_diff($remaining, [$key]));

            foreach (array_keys($edges[$key] ?? []) as $next) {
                if (isset($indegree[$next]) && $indegree[$next] > 0) { $indegree[$next]--; }
            }
        }

        $out = [];
        $position = 0;
        foreach ($ordered as $key) {
            $group = $groups[$key];
            $group['position'] = ++$position;
            $group['must_follow'] = [];
            foreach ($edges as $from => $tos) {
                if (isset($tos[$key])) {
                    $group['must_follow'][] = [
                        'group'  => $from,
                        'reason' => $reasons[$from . '=>' . $key] ?? 'dependency',
                    ];
                }
            }
            $out[] = $group;
        }

        return [$out, $conflicts];
    }
}
