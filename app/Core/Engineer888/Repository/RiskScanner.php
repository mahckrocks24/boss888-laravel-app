<?php

namespace App\Core\Engineer888\Repository;

use Illuminate\Support\Facades\DB;

/**
 * Repository-wide risks, ranked.
 *
 * Operates on the whole changed set rather than one file at a time, because the
 * findings that matter most are relationships: a deleted file something still
 * references, two classes with the same name, a migration on disk that has never
 * run. None of those are visible from a single file.
 *
 * Every finding names the failure it prevents. A finding that cannot say what
 * goes wrong is an observation, and observations are what this sprint exists to
 * stop producing.
 */
final class RiskScanner
{
    public function __construct(
        private string $repoPath,
        private ArchitectureMap $map,
    ) {}

    /**
     * @param  array<int,array<string,mixed>>  $analysed  files with 'analysis' attached
     * @return array<int,array<string,mixed>>
     */
    public function scan(array $analysed, DependencyGraph $graph): array
    {
        $risks = [];

        $byClassification = [];
        foreach ($analysed as $file) {
            $byClassification[$file['analysis']['classification']][] = $file['path'];
        }

        // ── unresolved conflicts ─────────────────────────────────────────
        if (! empty($byClassification[FileClassifier::MERGE])) {
            $risks[] = $this->risk('MERGE-MARKERS', 'critical',
                'Unresolved merge conflict markers in tracked files',
                'These files will not parse. Nothing else should be attempted until they are resolved.',
                $byClassification[FileClassifier::MERGE],
                'deploying a file that cannot be parsed');
        }

        // ── debug output in application code ─────────────────────────────
        $debugFiles = [];
        foreach ($analysed as $file) {
            if (in_array('debug-statements', $file['analysis']['flags'], true) && str_starts_with($file['path'], 'app/')) {
                $debugFiles[] = $file['path'] . ' (' . implode('; ', array_slice($file['analysis']['evidence'], 0, 2)) . ')';
            }
        }
        if ($debugFiles !== []) {
            $risks[] = $this->risk('DEBUG-IN-APP', 'critical',
                'Debug output left in application code',
                'These execute in production. dd() halts the request; dump() and print_r() leak internal state into responses.',
                $debugFiles,
                'internal state being written into a customer-facing response');
        }

        // ── a deleted file that others still reference ───────────────────
        $brokenDeletes = [];
        foreach ($analysed as $file) {
            if (! $file['deleted']) { continue; }
            $users = $graph->usedBy($file['path']);
            // A deleted file's own classes are gone; anything still importing
            // them will fail at autoload time, not at deploy time.
            if ($users !== []) {
                $brokenDeletes[] = $file['path'] . ' — still referenced by ' . count($users)
                    . ' file(s): ' . implode(', ', array_slice($users, 0, 3));
            }
        }
        if ($brokenDeletes !== []) {
            $risks[] = $this->risk('DELETED-BUT-REFERENCED', 'critical',
                'A deleted file is still referenced',
                'The reference will fail when the class is autoloaded, which may be long after deployment.',
                $brokenDeletes,
                'a class-not-found error appearing in production hours after the deploy');
        }

        // ── secrets or session material ──────────────────────────────────
        $sensitive = [];
        foreach ($analysed as $file) {
            if (in_array('may-contain-session-token', $file['analysis']['flags'], true)) {
                $sensitive[] = $file['path'];
            }
        }
        if ($sensitive !== []) {
            $risks[] = $this->risk('SENSITIVE-ARTIFACT', 'critical',
                'A file that can carry authentication material is sitting in the repository',
                'Committing it would publish a session token. Delete it; do not add it to .gitignore and leave it on disk.',
                $sensitive,
                'a live session token entering version control');
        }

        // ── duplicate class names ────────────────────────────────────────
        $shortNames = [];
        foreach ($analysed as $file) {
            foreach ($graph->declaredBy($file['path']) as $fqcn) {
                $parts = explode('\\', $fqcn);
                $shortNames[end($parts)][] = $fqcn;
            }
        }
        $duplicates = [];
        foreach ($shortNames as $short => $fqcns) {
            $fqcns = array_unique($fqcns);
            if (count($fqcns) > 1) {
                $duplicates[] = $short . ': ' . implode(' | ', $fqcns);
            }
        }
        if ($duplicates !== []) {
            $risks[] = $this->risk('DUPLICATE-CLASS-NAME', 'warn',
                'The same class name is declared in more than one namespace',
                'Two implementations of the same concept usually means one is dead. Determine which is authoritative before committing.',
                $duplicates,
                'edits being made to the copy that is not the one running');
        }

        // ── unreferenced new classes ─────────────────────────────────────
        $orphans = [];
        foreach ($analysed as $file) {
            if ($file['analysis']['classification'] !== FileClassifier::ABANDONED) { continue; }
            if (! str_contains($file['analysis']['reason'], 'nothing in the repository references')) { continue; }
            $orphans[] = $file['path'];
        }
        if ($orphans !== []) {
            $risks[] = $this->risk('ORPHAN-CLASS', 'warn',
                'New classes that nothing calls',
                'Either the wiring was never finished or the work was abandoned. Both need a decision before the code is committed as done.',
                $orphans,
                'shipping code that cannot run, and believing the feature exists');
        }

        // ── migrations on disk that have never run ───────────────────────
        $pending = $this->pendingMigrations($analysed);
        if ($pending !== []) {
            $risks[] = $this->risk('MIGRATION-NEVER-RUN', 'warn',
                'Migration files present but not applied to this database',
                'Code committed alongside these will reference tables or columns that do not exist here.',
                $pending,
                'a deploy whose code expects a schema the database does not have');
        }

        // ── architectural changes bundled with feature work ──────────────
        $architectural = $byClassification[FileClassifier::ARCHITECTURAL] ?? [];
        $governed = array_values(array_filter($architectural, fn ($p) => str_starts_with($p, 'routes/')));
        if ($governed !== []) {
            $risks[] = $this->risk('GOVERNED-PATH-DIRTY', 'warn',
                'Uncommitted changes under the routes governance lock',
                'routes/ is a protected path. These edits must go through GovernedWriter and the route baseline, not a plain write.',
                $governed,
                'a route change bypassing the governance baseline (INC-2026-005)');
        }

        // ── accumulated debris ───────────────────────────────────────────
        $debris = array_merge(
            $byClassification[FileClassifier::BACKUP] ?? [],
            $byClassification[FileClassifier::TEMPORARY] ?? [],
        );
        if ($debris !== []) {
            $risks[] = $this->risk('REPOSITORY-DEBRIS', 'warn',
                'Files in the working tree that should never be committed',
                'Backups and accidental artifacts. They inflate every count and every search, and one bad `git add -A` commits them permanently.',
                $debris,
                'debris entering git history where it cannot be removed cheaply');
        }

        // ── whole-repository backup accumulation ─────────────────────────
        $bakCount = $this->countBackupFiles();
        if ($bakCount > 100) {
            $risks[] = $this->risk('BACKUP-ACCUMULATION', 'info',
                'Large number of backup files across the repository',
                'These are excluded from this analysis, but they still poison greps and any static rule that forgets to skip them. Archive them outside the tree.',
                [$bakCount . ' files matching *.bak* outside vendor/'],
                'static analysis and audits producing results from files that are not the code');
        }

        // ── commented-out code ───────────────────────────────────────────
        $commented = [];
        foreach ($analysed as $file) {
            if (in_array('commented-out-code', $file['analysis']['flags'], true)) { $commented[] = $file['path']; }
        }
        if ($commented !== []) {
            $risks[] = $this->risk('COMMENTED-OUT-CODE', 'info',
                'Blocks of commented-out code in changed files',
                'Version control already remembers the old version. Commented code is ambiguous about whether it is a note or a pending change.',
                $commented,
                'a future reader restoring code that was deliberately disabled');
        }

        $rank = ['critical' => 0, 'warn' => 1, 'info' => 2];
        usort($risks, fn ($a, $b) => [$rank[$a['severity']], $a['id']] <=> [$rank[$b['severity']], $b['id']]);

        return $risks;
    }

    /** @return array<int,string> */
    private function pendingMigrations(array $analysed): array
    {
        $changed = [];
        foreach ($analysed as $file) {
            if ($this->map->layer($file['path']) === 'migration') {
                $changed[pathinfo($file['path'], PATHINFO_FILENAME)] = $file['path'];
            }
        }
        if ($changed === []) { return []; }

        try {
            $ran = DB::table('migrations')->pluck('migration')->all();
        } catch (\Throwable) {
            return [];   // cannot tell; say nothing rather than guess
        }

        $ranSet = array_flip($ran);
        $out = [];
        foreach ($changed as $name => $path) {
            if (! isset($ranSet[$name])) { $out[] = $path; }
        }
        sort($out);

        return $out;
    }

    private function countBackupFiles(): int
    {
        $res = \App\Core\Engineer888\Signals\Shell::run(
            'bash', ['-c', 'find . -name "*.bak*" -not -path "./vendor/*" -not -path "./node_modules/*" -type f | wc -l'],
            $this->repoPath, 60
        );

        return (int) trim($res['out']);
    }

    private function risk(string $id, string $severity, string $title, string $detail, array $evidence, string $prevents): array
    {
        return [
            'id'        => $id,
            'severity'  => $severity,
            'title'     => $title,
            'detail'    => $detail,
            'evidence'  => array_values($evidence),
            'count'     => count($evidence),
            'prevents'  => $prevents,
        ];
    }
}
