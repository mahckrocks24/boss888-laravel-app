<?php

namespace App\Console\Commands;

use App\Engines\Creative\Services\CreativeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Backfill featured images for blog articles that were persisted text-only
 * (the imageless-WP-draft bug, fixed forward 2026-06-13). These articles were
 * already charged the 2cr "fully-optimized article" bundle (which includes the
 * image) but never received one, so regeneration here is CREDITLESS — it simply
 * delivers what was paid for. CREATIVE888 is only CALLED (generateImage sets
 * featured_image_url in place); no Creative/* logic is touched.
 *
 * With --push-wp, articles that were already pushed to WordPress (wp_post_id
 * set) also get the new image sideloaded onto the existing post via the
 * connector's lgsc/v1/update-post endpoint, so live drafts are fixed too.
 *
 *   php artisan seo:backfill-featured-images --workspace=7 --limit=20 --dry-run
 *   php artisan seo:backfill-featured-images --workspace=7 --push-wp
 */
class BackfillFeaturedImagesCommand extends Command
{
    protected $signature = 'seo:backfill-featured-images
        {--workspace= : Limit to one workspace id}
        {--limit=25 : Max articles to process this run}
        {--push-wp : Also sideload the image onto the existing WordPress post (update-post)}
        {--dry-run : List what would be done without generating anything}';

    protected $description = 'Generate featured images for blog articles that were saved text-only (creditless — bundle already paid).';

    public function handle(): int
    {
        $ws      = $this->option('workspace') !== null ? (int) $this->option('workspace') : null;
        $limit   = max(1, (int) $this->option('limit'));
        $pushWp  = (bool) $this->option('push-wp');
        $dryRun  = (bool) $this->option('dry-run');

        $q = DB::table('articles')
            ->where(function ($w) {
                $w->whereNull('featured_image_url')->orWhere('featured_image_url', '');
            })
            ->whereIn('type', ['blog_post', 'blog_article']);
        if ($ws !== null) {
            $q->where('workspace_id', $ws);
        }
        $articles = $q->orderByDesc('id')->limit($limit)
            ->get(['id', 'workspace_id', 'title', 'wp_post_id']);

        if ($articles->isEmpty()) {
            $this->info('No imageless blog articles found for the given scope.');
            return self::SUCCESS;
        }

        $this->info(sprintf('%s %d imageless article(s)%s%s',
            $dryRun ? 'Would process' : 'Processing',
            $articles->count(),
            $ws !== null ? " in workspace {$ws}" : '',
            $pushWp ? ' (+ WP push)' : ''
        ));

        $done = 0; $failed = 0; $pushed = 0;
        foreach ($articles as $a) {
            $label = "#{$a->id} ws{$a->workspace_id} \"" . mb_substr((string) $a->title, 0, 48) . '"';
            if ($dryRun) {
                $this->line("  [dry-run] {$label}" . ($a->wp_post_id ? " (wp_post {$a->wp_post_id})" : ''));
                continue;
            }

            try {
                app(CreativeService::class)->generateImage((int) $a->workspace_id, [
                    'article_id' => (int) $a->id,
                    'quality'    => 'mini',
                ]);
            } catch (\Throwable $e) {
                $failed++;
                $this->error("  ✗ {$label} — image gen failed: " . $e->getMessage());
                continue;
            }

            $imageUrl = DB::table('articles')->where('id', $a->id)->value('featured_image_url');
            if (empty($imageUrl)) {
                $failed++;
                $this->error("  ✗ {$label} — generator returned no image");
                continue;
            }
            $done++;
            $this->line("  ✓ {$label} — image set");

            if ($pushWp && $a->wp_post_id) {
                if ($this->pushImageToWp((int) $a->workspace_id, (int) $a->wp_post_id, (string) $imageUrl)) {
                    $pushed++;
                    $this->line("      ↳ pushed to WP post {$a->wp_post_id}");
                } else {
                    $this->warn("      ↳ WP push skipped/failed for post {$a->wp_post_id}");
                }
            }
        }

        $this->info("Done. images set: {$done}, failed: {$failed}" . ($pushWp ? ", wp pushed: {$pushed}" : '') . '. (No credits charged.)');
        return self::SUCCESS;
    }

    /**
     * Sideload the freshly-generated image onto the EXISTING WordPress post via
     * the connector's lgsc/v1/update-post (which calls media_sideload_image +
     * set_post_thumbnail). Mirrors the create-post push auth.
     */
    private function pushImageToWp(int $wsId, int $wpPostId, string $imageUrl): bool
    {
        try {
            $siteUrl = DB::table('seo_settings')->where('workspace_id', $wsId)
                ->where('key', 'site_url')->value('value');
            $secret = DB::table('seo_settings')->where('workspace_id', $wsId)
                ->where('key', 'webhook_secret')->value('value');
            if (! $siteUrl || ! $secret) {
                return false;
            }
            $resp = Http::withHeaders(['X-LGSC-Secret' => $secret, 'Content-Type' => 'application/json'])
                ->timeout(45)
                ->post(rtrim((string) $siteUrl, '/') . '/wp-json/lgsc/v1/update-post', [
                    'post_id'            => $wpPostId,
                    'featured_image_url' => $imageUrl,
                    'secret'             => $secret,
                ]);
            return $resp->successful();
        } catch (\Throwable $e) {
            Log::warning('[backfill-featured-images] WP push failed: ' . $e->getMessage());
            return false;
        }
    }
}
