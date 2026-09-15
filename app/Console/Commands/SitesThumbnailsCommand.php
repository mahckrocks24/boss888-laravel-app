<?php

namespace App\Console\Commands;

use App\Engines\Builder\Support\SiteThumbnail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Websites-page thumbnails: `sites:thumbnails [--site=ID] [--missing] [--stale]`.
 * --stale (scheduled every minute): re-shoot sites whose export changed after their thumbnail — every editing path
 * writes sites/{id}/index.html directly (field edits, colour rules, undo, catalogue sync, inline saves), so the file's
 * mtime is the one truth; a change younger than 20 s is left for the next minute so a burst of edits yields one shot.
 */
class SitesThumbnailsCommand extends Command
{
    protected $signature = 'sites:thumbnails {--site= : one website id} {--missing : only sites without a thumbnail} {--stale : only sites whose export is newer than their thumbnail}';
    protected $description = 'Render the card thumbnail (home page screenshot) for exported sites';

    public function handle(): int
    {
        $q = DB::table('websites')->whereNull('deleted_at')->whereNull('external_url')->orderBy('id');
        if ($this->option('site')) { $q->where('id', (int) $this->option('site')); }
        if ($this->option('missing')) { $q->whereNull('thumbnail_url'); }
        $ok = 0; $skip = 0; $fail = 0; $fresh = 0;
        foreach ($q->pluck('id') as $id) {
            $export = storage_path("app/public/sites/{$id}/index.html");
            if (! is_file($export)) { $skip++; continue; }
            if ($this->option('stale')) {
                $thumb = storage_path('app/public/' . SiteThumbnail::DIR . "/{$id}.jpg");
                $exportAt = (int) filemtime($export);
                if (is_file($thumb) && (int) filemtime($thumb) >= $exportAt) { $fresh++; continue; }
                if (! is_file($thumb) && time() - $exportAt > 86400) { $skip++; continue; }   // never shot and not edited today: the --missing backfill's job, not the minute pass
                if (time() - $exportAt < 20) { $fresh++; continue; }   // still being edited — next minute
                if (\Illuminate\Support\Facades\Cache::has("site-thumb-fail-{$id}")) { $skip++; continue; }   // a broken render waits 30 min, not every minute
                if ($ok + $fail >= 3) { break; }   // a scheduled pass renders at most 3 sites (one Chrome, ~45 s) — the box is shared with the live platform
            }
            $url = SiteThumbnail::generate((int) $id);
            if ($url) { $ok++; $this->line("  {$id}  {$url}"); }
            else { $fail++; $this->warn("  {$id}  failed"); if ($this->option('stale')) { \Illuminate\Support\Facades\Cache::put("site-thumb-fail-{$id}", 1, 1800); } }
        }
        $this->info("done: {$ok} rendered, {$fresh} up to date, {$skip} without export, {$fail} failed");
        return self::SUCCESS;
    }
}
