<?php

namespace App\Jobs\SeoOptimization;

use App\Engines\SEO\Services\SeoService;
use App\Services\SeoOptimization\OptimizationOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Wave 10 (2026-05-18). Post-publish optimization hook.
 *
 * Dispatched after an article is successfully published to WordPress.
 *
 * Flow:
 *   1. Tier-2 (browser) extract on the published URL → upserts
 *      seo_images rows for every <img> WordPress now hosts on that post.
 *   2. For each seo_images row matching this page_url that isn't already
 *      optimized/queued, hand it to OptimizationOrchestrator->dispatch().
 *   3. Orchestrator handles plan gating, C2/C1 classification, and the
 *      actual OptimizeWpAttachmentJob dispatch.
 *
 * Idempotent — repeated runs no-op for already-optimized images, and the
 * orchestrator's own "already_in_state" check prevents double-queueing.
 * Failures in Tier-2 are non-fatal — we still try to dispatch on whatever
 * rows already exist for the page.
 */
class PostPublishOptimizeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $backoff = 60;
    public int $timeout = 300; // Tier-2 puppeteer can be slow

    public function __construct(
        public int $wsId,
        public ?int $userId,
        public string $publicUrl,
        public int $articleId
    ) {}

    public function handle(SeoService $seo, OptimizationOrchestrator $orchestrator): void
    {
        $upserted = 0;
        try {
            $upserted = $seo->tier2ExtractRendered($this->wsId, $this->publicUrl);
        } catch (\Throwable $e) {
            Log::warning('[Wave10] tier2 extract failed: ' . $e->getMessage(), [
                'ws_id' => $this->wsId, 'url' => $this->publicUrl,
            ]);
        }

        $rows = DB::table('seo_images')
            ->where('workspace_id', $this->wsId)
            ->where('page_url', $this->publicUrl)
            ->where(function ($q) {
                $q->whereNull('optimization_status')
                  ->orWhereNotIn('optimization_status', ['optimized', 'optimized_external', 'queued', 'running']);
            })
            ->get(['id', 'image_url', 'optimization_status']);

        $dispatched = 0;
        $blocked = 0;
        $reasons = [];
        foreach ($rows as $row) {
            try {
                $result = $orchestrator->dispatch($this->wsId, $this->userId, $row->image_url);
                if (! empty($result['accepted'])) {
                    $dispatched++;
                } else {
                    $blocked++;
                    $reasons[$result['reason'] ?? 'unknown'] = ($reasons[$result['reason'] ?? 'unknown'] ?? 0) + 1;
                }
            } catch (\Throwable $e) {
                $blocked++;
                $reasons['exception'] = ($reasons['exception'] ?? 0) + 1;
                Log::warning('[Wave10] orchestrator dispatch threw: ' . $e->getMessage(), [
                    'ws_id' => $this->wsId, 'image_url' => $row->image_url,
                ]);
            }
        }

        Log::info('[Wave10] post-publish optimization summary', [
            'ws_id'        => $this->wsId,
            'article_id'   => $this->articleId,
            'url'          => $this->publicUrl,
            'tier2_upserted' => $upserted,
            'rows_seen'    => count($rows),
            'dispatched'   => $dispatched,
            'blocked'      => $blocked,
            'reasons'      => $reasons,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('[Wave10] PostPublishOptimizeJob permanently failed', [
            'ws_id'     => $this->wsId,
            'url'       => $this->publicUrl,
            'article_id'=> $this->articleId,
            'error'     => $exception->getMessage(),
        ]);
    }
}
