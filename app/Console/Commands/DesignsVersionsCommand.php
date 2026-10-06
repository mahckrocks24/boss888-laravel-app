<?php

namespace App\Console\Commands;

use App\Engines\Builder\Support\DesignVersions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * DESIGN-UPDATES-1: record the version every website stands on (only where none is recorded: before this no site knew, so
 * today's version is its baseline) and archive those versions; optionally stamp design_version into every manifest.
 * Archives only the designs websites use (--all for every design): the box is short of disk.
 */
class DesignsVersionsCommand extends Command
{
    protected $signature = 'designs:versions {--backfill : record the baseline on websites that have none} {--manifests : write design_version into every manifest} {--all : archive every design, not only those in use}';
    protected $description = 'DESIGN-UPDATES-1: archive design versions and record what each website stands on';

    public function handle(): int
    {
        $stamped = 0; $archived = [];
        if ($this->option('manifests') || $this->option('all')) {
            foreach (glob(storage_path('templates/*/template.html')) ?: [] as $p) {
                $slug = basename(dirname($p));
                if ($this->option('manifests') && DesignVersions::stampManifest($slug)) $stamped++;
                if ($this->option('all') && DesignVersions::archive($slug) !== '') $archived[$slug] = 1;
            }
        }
        $b = 0;
        foreach (DB::table('websites')->whereNull('deleted_at')->where('type', 'template')->pluck('id') as $id) {
            $d = DesignVersions::ofSite((int) $id);
            if ($d['slug'] === '' || ! is_file(storage_path("templates/{$d['slug']}/template.html"))) continue;
            if ($d['version'] === '') { if ($this->option('backfill') && DesignVersions::stampSite((int) $id, null, 'baseline')) { $b++; $archived[$d['slug']] = 1; } }
            elseif (DesignVersions::current($d['slug']) === $d['version'] && DesignVersions::archive($d['slug']) !== '') $archived[$d['slug']] = 1;
        }
        $this->line(count($archived) . ' design versions archived' . ($this->option('manifests') ? ", {$stamped} manifests stamped" : '') . ($this->option('backfill') ? ", baseline recorded on {$b} websites" : ''));
        return self::SUCCESS;
    }
}
