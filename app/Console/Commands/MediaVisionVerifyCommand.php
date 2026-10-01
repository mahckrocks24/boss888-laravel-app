<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * OWNER RULE (2026-09-14): "make sure all newly generated images are getting proper tagging and description".
 * Every image enters the library with a prompt-DERIVED description and tags (MediaCataloguer::catalogueQuietly).
 * This command upgrades them to VERIFIED ones with the vision model, a few at a time, so nothing stays derived for
 * long and no single run costs much. Idempotent: verified rows are skipped.
 *
 * RISK-0215 (RFC-0022 forensic, 2026-10-01): the job had retried the same unreadable rows every ten minutes for weeks
 * (1,440 failed vision calls a day) because videos sat under asset_type=image and a failure never marked the row.
 * Now: a row whose url or mime is not a still image is tagged flag:vision-unavailable at once; any other row is
 * retried at most three times (flag:vision-retry:N), then tagged flag:vision-unavailable; one log line per run.
 *
 *   php artisan media:vision-verify              (up to 20 rows, oldest derived first)
 *   php artisan media:vision-verify --limit=50 --ids=1640,1641
 */
class MediaVisionVerifyCommand extends Command
{
    protected $signature = 'media:vision-verify {--limit=20} {--ids=} {--platform-only}';
    protected $description = 'Upgrade derived media descriptions/tags to vision-verified ones (bounded, idempotent).';

    private const MAX_RETRIES = 3;
    private const STILL_EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $ids = array_values(array_filter(array_map('intval', explode(',', (string) $this->option('ids')))));
        $q = DB::table('media')->where('asset_type', 'image')->whereNotNull('url')->where('url', '!=', '')
            ->where('tags', 'like', '%"desc:derived"%')->where('tags', 'not like', '%"desc:verified"%')
            ->where('tags', 'not like', '%"flag:vision-unavailable"%');
        if ($ids !== []) { $q->whereIn('id', $ids); }
        if ($this->option('platform-only')) { $q->where('is_platform_asset', 1); }
        $rows = $q->orderBy('id')->limit($limit)->get(['id', 'url', 'mime_type', 'tags']);
        $ok = 0; $fail = 0; $skipped = 0; $retired = 0; $lastError = null;
        foreach ($rows as $r) {
            $tags = json_decode((string) $r->tags, true) ?: [];
            if (! $this->isStillImage((string) $r->url, (string) $r->mime_type)) {
                $this->retire((int) $r->id, $tags, 'not a still image');
                $skipped++;
                continue;
            }
            try {
                if (\App\Services\MediaCataloguer::visionVerify((int) $r->id)) { $ok++; continue; }
                $fail++;
            } catch (\Throwable $e) {
                $fail++; $lastError = $e->getMessage();
            }
            $n = $this->retryCount($tags) + 1;
            if ($n >= self::MAX_RETRIES) { $this->retire((int) $r->id, $tags, 'failed ' . $n . ' times'); $retired++; }
            else { $this->setRetry((int) $r->id, $tags, $n); }
        }
        $line = ['verified' => $ok, 'failed' => $fail, 'skipped_non_image' => $skipped, 'retired' => $retired, 'seen' => $rows->count()];
        if ($lastError !== null) $line['last_error'] = mb_substr($lastError, 0, 200);
        $this->info("verified={$ok} failed={$fail} skipped={$skipped} retired={$retired} of " . $rows->count());
        if ($rows->count() > 0 && $ok === 0 && $fail > 0) Log::warning('[media:vision-verify] every row failed', $line);
        else Log::info('[media:vision-verify]', $line);
        return self::SUCCESS;
    }

    private function isStillImage(string $url, string $mime): bool
    {
        if ($mime !== '' && ! str_starts_with(strtolower($mime), 'image/')) return false;
        $path = (string) parse_url($url, PHP_URL_PATH);
        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        return $ext === '' || in_array($ext, self::STILL_EXT, true);
    }

    private function retryCount(array $tags): int
    {
        foreach ($tags as $t) { if (preg_match('/^flag:vision-retry:(\d+)$/', (string) $t, $m)) return (int) $m[1]; }
        return 0;
    }

    private function setRetry(int $id, array $tags, int $n): void
    {
        $tags = array_values(array_filter($tags, fn ($t) => ! str_starts_with((string) $t, 'flag:vision-retry:')));
        $tags[] = 'flag:vision-retry:' . $n;
        DB::table('media')->where('id', $id)->update(['tags' => json_encode(array_values(array_unique($tags)), JSON_UNESCAPED_SLASHES), 'updated_at' => now()]);
    }

    private function retire(int $id, array $tags, string $why): void
    {
        $tags = array_values(array_filter($tags, fn ($t) => ! str_starts_with((string) $t, 'flag:vision-retry:')));
        $tags[] = 'flag:vision-unavailable';
        DB::table('media')->where('id', $id)->update(['tags' => json_encode(array_values(array_unique($tags)), JSON_UNESCAPED_SLASHES), 'updated_at' => now()]);
        Log::info('[media:vision-verify] row retired from verification', ['id' => $id, 'why' => $why]);
    }
}
