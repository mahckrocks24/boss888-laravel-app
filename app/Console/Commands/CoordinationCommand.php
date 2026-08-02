<?php

namespace App\Console\Commands;

use App\Core\Engineer888\Coordination\DatabaseAssignment;
use App\Core\Engineer888\Coordination\GovernedFiles;
use App\Core\Engineer888\Coordination\OwnershipManifest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Engineer888 — coordination status.
 *
 * Answers, in one place, the questions an engineer must be able to answer before
 * doing anything consequential in a shared repository: who am I, which database
 * am I allowed to destroy, what do I own, what am I forbidden to touch quietly,
 * and is anybody else working right now.
 *
 *   php artisan engineering:coordination
 *   php artisan engineering:coordination --owns=path/to/file.php
 *   php artisan engineering:coordination --json
 */
class CoordinationCommand extends Command
{
    protected $signature = 'engineering:coordination
        {--owns= : classify a single path and exit}
        {--json : machine-readable output}';

    protected $description = 'Engineer888 coordination status — session identity, assigned database, ownership and governed files';

    public function handle(): int
    {
        $manifest = OwnershipManifest::active(base_path());

        if ($this->option('owns')) {
            return $this->classifyOne($manifest, (string) $this->option('owns'));
        }

        $resolved = (string) config('database.connections.' . config('database.default') . '.database');
        $assignment = DatabaseAssignment::check($resolved, $manifest);

        if ($this->option('json')) {
            $this->line(json_encode([
                'manifest'          => $manifest?->path(),
                'sprint'            => $manifest?->sprint(),
                'assigned_database' => $manifest?->testDatabase(),
                'resolved_database' => $resolved,
                'assignment_ok'     => $assignment['assigned'],
                'governed_files'    => array_keys(GovernedFiles::all()),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $manifest === null ? 1 : 0;
        }

        $this->newLine();
        $this->line('  <options=bold>ENGINEER888 — COORDINATION STATUS</>');
        $this->line('  ' . str_repeat('═', 78));

        // ── identity ─────────────────────────────────────────────────────
        $this->section('SESSION');
        if ($manifest === null) {
            $this->line('    <fg=red>NO ACTIVE MANIFEST</> — every destructive operation fails closed.');
            $this->line('    <fg=gray>Create one in ' . OwnershipManifest::DIRECTORY . ', or set E888_SPRINT_MANIFEST.</>');
        } else {
            $this->kv('engineer', $manifest->engineer());
            $this->kv('sprint', $manifest->sprint());
            $this->kv('manifest', str_replace(base_path() . '/', '', $manifest->path()));
        }

        // ── database ─────────────────────────────────────────────────────
        $this->section('DATABASE');
        $this->kv('assigned', $manifest?->testDatabase() ?? '<fg=red>none</>');
        $this->kv('resolved now', $resolved
            . (DatabaseAssignment::isProductionName($resolved) ? '  <fg=yellow>(production — normal outside tests)</>' : ''));
        $this->kv('destructive ops', $assignment['assigned']
            ? '<fg=green>PERMITTED against the assigned database</>'
            : '<fg=red>REFUSED</> — ' . mb_substr($assignment['reason'], 0, 90));

        // ── other engineers ──────────────────────────────────────────────
        $this->section('OTHER ENGINEERS  <fg=gray>(supplemental evidence only — never proof of isolation)</>');
        foreach ($this->otherSessions($manifest) as $line) { $this->line('    ' . $line); }

        // ── ownership ────────────────────────────────────────────────────
        $this->section('OWNERSHIP');
        if ($manifest !== null) {
            foreach (['owned_paths' => 'owned paths', 'owned_files' => 'owned files'] as $key => $label) {
                $count = count((array) (json_decode((string) file_get_contents($manifest->path()), true)[$key] ?? []));
                $this->kv($label, (string) $count);
            }
            $this->kv('shared declared', (string) count($manifest->sharedFiles()));
            foreach ($manifest->sharedFiles() as $shared) {
                $this->line('      <fg=yellow>· ' . $shared['path'] . '</> <fg=gray>(' . ($shared['policy'] ?? '?') . ')</>');
            }
        }

        // ── governed files ───────────────────────────────────────────────
        $this->section('GOVERNED SHARED FILES');
        foreach (GovernedFiles::all() as $path => $policy) {
            $declared = $this->isDeclared($manifest, $path);
            $this->line(sprintf('    %-26s %-28s %s', $path, $policy['classification'],
                $declared ? '<fg=green>declared by this sprint</>' : '<fg=gray>not declared — may not be staged</>'));
        }

        $this->newLine();
        $this->line('  ' . str_repeat('═', 78));
        $this->newLine();

        return $manifest === null ? 1 : 0;
    }

    private function classifyOne(?OwnershipManifest $manifest, string $path): int
    {
        if ($manifest === null) {
            $this->error('No active manifest — ownership cannot be established, so nothing may be staged.');

            return 1;
        }

        $result = $manifest->classify($path);
        $committable = OwnershipManifest::isCommittable($result['status']);

        $this->newLine();
        $this->line('  ' . $path);
        $this->line('  status:   ' . ($committable ? '<fg=green>' : '<fg=red>') . $result['status'] . '</>');
        $this->line('  evidence: ' . $result['evidence']);
        $this->line('  staging:  ' . ($committable ? '<fg=green>permitted</>' : '<fg=red>REFUSED</>'));
        if (GovernedFiles::isGoverned($path)) {
            $policy = GovernedFiles::policyFor($path);
            $this->line('  governed: <fg=yellow>' . $policy['classification'] . '</> — ' . $policy['affects']);
        }
        $this->newLine();

        return $committable ? 0 : 1;
    }

    /**
     * Who else appears to be working. Reported, never trusted: on 2026-07-30 a
     * process check showed nothing and a concurrent migration was underway two
     * seconds later.
     *
     * @return array<int,string>
     */
    private function otherSessions(?OwnershipManifest $manifest): array
    {
        $lines = [];
        $mine = $manifest?->testDatabase();

        try {
            $rows = DB::select(
                'select db, count(*) as conns from information_schema.processlist where db is not null group by db'
            );
            foreach ($rows as $row) {
                $database = (string) $row->db;
                if ($database === $mine) { continue; }
                if (DatabaseAssignment::isProductionName($database)) { continue; }
                $lines[] = '<fg=yellow>' . $database . '</> — ' . $row->conns . ' connection(s), another session may be mid-run';
            }
        } catch (\Throwable $e) {
            $lines[] = '<fg=gray>could not read the process list: ' . mb_substr($e->getMessage(), 0, 60) . '</>';
        }

        $out = [];
        @exec("ps ax | grep -E '[p]hpunit|[a]rtisan' | grep -vE 'queue:work|schedule:run|engineering:coordination' | head -5", $out);
        foreach ($out as $process) {
            $lines[] = '<fg=yellow>process</> ' . mb_substr(trim(preg_replace('/\s+/', ' ', $process) ?? ''), 0, 96);
        }

        return $lines === [] ? ['<fg=green>nothing observed</> <fg=gray>— absence of evidence, not evidence of absence</>'] : $lines;
    }

    private function isDeclared(?OwnershipManifest $manifest, string $path): bool
    {
        if ($manifest === null) { return false; }

        return $manifest->classify($path)['status'] === OwnershipManifest::SHARED_DECLARED;
    }

    private function section(string $title): void
    {
        $this->newLine();
        $this->line('  <options=bold>' . $title . '</>');
        $this->line('  ' . str_repeat('─', 78));
    }

    private function kv(string $k, string $v): void
    {
        $this->line('    ' . str_pad($k, 18) . $v);
    }
}
