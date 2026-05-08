<?php

namespace App\Console\Commands;

use App\Services\ThumbnailService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BackfillThumbnails extends Command
{
    protected $signature = 'lu:thumbnails:backfill
                            {--force : regenerate even if thumbnail_url is set}
                            {--limit= : process at most N rows}';

    protected $description = 'Generate 400x300 cover-crop thumbnails for media rows that lack one';

    public function handle(): int
    {
        $publicRoot = storage_path('app/public');
        $force      = (bool) $this->option('force');
        $limit      = (int) $this->option('limit');

        $q = DB::table('media')->where('asset_type', 'image');
        if (! $force) $q->whereNull('thumbnail_url');
        if ($limit > 0) $q->limit($limit);

        $rows = $q->orderBy('id')->get(['id', 'path', 'file_url', 'url', 'filename', 'thumbnail_url']);
        $total = $rows->count();
        $this->info("Found {$total} candidate(s)" . ($force ? ' (force regen)' : ''));

        $ok = 0; $skip = 0; $fail = 0;
        foreach ($rows as $row) {
            // Resolve relative path of source on disk.
            $relPath = ltrim((string) ($row->path ?? ''), '/');
            if (! $relPath) {
                $relPath = ltrim(str_replace('/storage/', '', (string) ($row->file_url ?? '')), '/');
            }
            if (! $relPath) {
                $relPath = ltrim(str_replace('/storage/', '', (string) ($row->url ?? '')), '/');
            }

            if (! $relPath) {
                $this->warn("  id={$row->id} {$row->filename}: SKIP (no path resolvable)");
                $skip++;
                continue;
            }

            $srcAbs = $publicRoot . '/' . $relPath;
            if (! is_file($srcAbs)) {
                $this->warn("  id={$row->id} {$row->filename}: SKIP ({$relPath} not on disk)");
                $skip++;
                continue;
            }

            $thumbAbs = $publicRoot . '/thumbnails/' . $relPath;
            if (! ThumbnailService::generate($srcAbs, $thumbAbs)) {
                $this->error("  id={$row->id} {$row->filename}: FAIL (generate returned false)");
                $fail++;
                continue;
            }

            DB::table('media')->where('id', $row->id)->update([
                'thumbnail_url' => '/storage/thumbnails/' . $relPath,
                'updated_at'    => now(),
            ]);

            $ok++;
            if ($ok % 10 === 0) {
                $this->info("  ... {$ok}/{$total} done");
            }
        }

        $this->info("");
        $this->info("Result: ok={$ok}, skip={$skip}, fail={$fail}, total={$total}");
        Log::info("[lu:thumbnails:backfill] ok={$ok} skip={$skip} fail={$fail} total={$total}");
        return self::SUCCESS;
    }
}
