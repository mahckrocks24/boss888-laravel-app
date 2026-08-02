<?php

namespace App\Console\Commands;

use App\Core\Engineer888\Install\InstallManifest;
use App\Core\Engineer888\Install\SafeInstaller;
use Illuminate\Console\Command;

/**
 * Engineer888 — installation provenance.
 *
 * Answers the question the old installers could not: for every file this
 * installer placed, is it still what was placed there?
 *
 *   php artisan engineering:install --status
 *   php artisan engineering:install --verify
 *   php artisan engineering:install --adopt=path/to/file.php --reason="..."
 *
 * Exit codes: 0 clean · 1 drift detected · 2 manifest unusable.
 */
class SafeInstallCommand extends Command
{
    protected $signature = 'engineering:install
        {--status : list every recorded installation}
        {--verify : check every recorded file for drift since installation}
        {--adopt= : record an existing file as installed, without writing to it}
        {--reason= : why the file is being adopted}
        {--json : machine-readable output}';

    protected $description = 'Engineer888 installation provenance — prove installed files have not been silently replaced';

    public function handle(): int
    {
        try {
            $manifest = InstallManifest::load(base_path());
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return 2;
        }

        if ($this->option('adopt')) { return $this->adopt((string) $this->option('adopt')); }
        if ($this->option('status')) { return $this->status($manifest); }

        return $this->verify($manifest);
    }

    private function status(InstallManifest $manifest): int
    {
        if ($this->option('json')) {
            $this->line(json_encode($manifest->entries(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        $this->newLine();
        $this->line('  <options=bold>ENGINEER888 — INSTALLATION MANIFEST</>');
        $this->line('  ' . str_repeat('─', 88));
        $this->line('  ' . $manifest->count() . ' recorded file(s)');
        $this->newLine();

        foreach ($manifest->entries() as $destination => $entry) {
            $overrides = count($entry['overrides'] ?? []);
            $this->line(sprintf('    %-58s %s  %s%s',
                mb_substr($destination, -58),
                substr((string) $entry['destination_hash'], 0, 8),
                substr((string) $entry['installed_at'], 0, 16),
                $overrides > 0 ? "  <fg=yellow>{$overrides} override(s)</>" : ''));
        }
        $this->newLine();

        return 0;
    }

    private function verify(InstallManifest $manifest): int
    {
        $drifted = [];
        $missing = [];
        $clean = 0;

        foreach ($manifest->entries() as $destination => $entry) {
            $full = base_path($destination);

            if (! is_file($full)) { $missing[] = $destination; continue; }

            $hash = sha1((string) file_get_contents($full));
            $hash === $entry['destination_hash']
                ? $clean++
                : $drifted[] = ['path' => $destination, 'recorded' => $entry['destination_hash'], 'now' => $hash];
        }

        if ($this->option('json')) {
            $this->line(json_encode(compact('clean', 'drifted', 'missing'), JSON_PRETTY_PRINT));

            return $drifted === [] && $missing === [] ? 0 : 1;
        }

        $this->newLine();
        $this->line('  <options=bold>INSTALLATION VERIFY</>');
        $this->line('  ' . str_repeat('─', 88));
        $this->line("    <fg=green>{$clean}</> unchanged since installation");

        foreach ($missing as $path) {
            $this->line("    <fg=yellow>MISSING </> {$path}");
        }

        foreach ($drifted as $item) {
            $this->line("    <fg=cyan>DRIFTED </> {$item['path']}");
            $this->line('                recorded ' . substr($item['recorded'], 0, 12)
                . '  now ' . substr($item['now'], 0, 12));
        }

        $this->newLine();
        if ($drifted !== []) {
            $this->line('  <fg=gray>Drift is not an error — it usually means the file was fixed after installation.');
            $this->line('  It means the transport source is now STALE, and re-installing it would revert the fix.</>');
        }
        $this->newLine();

        return $drifted === [] && $missing === [] ? 0 : 1;
    }

    private function adopt(string $path): int
    {
        $reason = (string) ($this->option('reason') ?: '');
        if (trim($reason) === '') {
            $this->error('--adopt requires --reason: recording a file as installed is a claim about provenance.');

            return 2;
        }

        $full = base_path($path);
        if (! is_file($full)) {
            $this->error("{$path} does not exist");

            return 2;
        }

        $content = (string) file_get_contents($full);
        $decision = (new SafeInstaller(base_path()))
            ->install($path, $content, 'adopted: ' . $reason, 'engineer888', false, null, true);

        $this->info("adopted {$path} — {$decision->state}");
        $this->line('  hash ' . substr((string) $decision->destinationHash, 0, 12));

        return 0;
    }
}
