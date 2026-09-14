<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * OWNER RULE (2026-09-14): "make sure all newly generated images are getting proper tagging and description".
 * Every image enters the library with a prompt-DERIVED description and tags (MediaCataloguer::catalogueQuietly).
 * This command upgrades them to VERIFIED ones with the vision model, a few at a time, so nothing stays derived for
 * long and no single run costs much. Idempotent: verified rows are skipped, failures are retried next run.
 *
 *   php artisan media:vision-verify              (up to 20 rows, oldest derived first)
 *   php artisan media:vision-verify --limit=50 --ids=1640,1641
 */
class MediaVisionVerifyCommand extends Command
{
    protected $signature = 'media:vision-verify {--limit=20} {--ids=} {--platform-only}';
    protected $description = 'Upgrade derived media descriptions/tags to vision-verified ones (bounded, idempotent).';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $ids = array_values(array_filter(array_map('intval', explode(',', (string) $this->option('ids')))));
        $q = DB::table('media')->where('asset_type', 'image')->whereNotNull('url')->where('url', '!=', '')
            ->where('tags', 'like', '%"desc:derived"%')->where('tags', 'not like', '%"desc:verified"%')
            ->where('tags', 'not like', '%"flag:vision-unavailable"%');
        if ($ids !== []) { $q->whereIn('id', $ids); }
        if ($this->option('platform-only')) { $q->where('is_platform_asset', 1); }
        $rows = $q->orderBy('id')->limit($limit)->get(['id']);
        $ok = 0; $fail = 0;
        foreach ($rows as $r) {
            try {
                if (\App\Services\MediaCataloguer::visionVerify((int) $r->id)) { $ok++; } else { $fail++; }
            } catch (\Throwable $e) {
                $fail++;
                Log::warning('[media:vision-verify] failed', ['id' => $r->id, 'error' => $e->getMessage()]);
            }
        }
        $this->info("verified={$ok} failed={$fail} of " . $rows->count());
        Log::info('[media:vision-verify]', ['verified' => $ok, 'failed' => $fail, 'seen' => $rows->count()]);
        return self::SUCCESS;
    }
}
