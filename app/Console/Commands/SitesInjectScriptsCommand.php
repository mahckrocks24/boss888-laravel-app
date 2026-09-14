<?php

namespace App\Console\Commands;

use App\Engines\Builder\Support\SiteScripts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Put the platform scripts (form poster, tracking) into every exported site page — home and sub-pages — for exports
 * written before SiteScripts existed. Idempotent; safe to re-run.  php artisan sites:inject-scripts [--site=ID]
 */
class SitesInjectScriptsCommand extends Command
{
    protected $signature = 'sites:inject-scripts {--site=} {--dry}';
    protected $description = 'Inject the form poster and tracking snippets into exported site pages (idempotent).';

    public function handle(): int
    {
        $root = storage_path('app/public/sites');
        $ids = $this->option('site') ? [(int) $this->option('site')] : array_map(fn($d) => (int) basename($d), array_filter(glob($root . '/*', GLOB_ONLYDIR) ?: [], fn($d) => ctype_digit(basename($d))));
        $sites = 0; $files = 0; $changed = 0;
        foreach ($ids as $id) {
            $settings = json_decode((string) (DB::table('websites')->where('id', $id)->value('settings_json') ?: '{}'), true) ?: [];
            $list = array_merge(glob("$root/$id/index.html") ?: [], glob("$root/$id/*/index.html") ?: []);
            $list = array_filter($list, fn($f) => ! str_contains($f, '/.history/'));
            if ($list === []) continue;
            $sites++;
            foreach ($list as $f) {
                $files++;
                $html = (string) @file_get_contents($f);
                if ($html === '') continue;
                $new = SiteScripts::inject($html, $id, $settings);
                if ($new !== $html) { $changed++; if (! $this->option('dry')) { file_put_contents($f, $new); @chown($f, 'www-data'); } }
            }
        }
        $this->info("sites={$sites} files={$files} changed={$changed}" . ($this->option('dry') ? ' (dry run)' : ''));
        return self::SUCCESS;
    }
}
