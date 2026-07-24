<?php

namespace App\Jobs;

use App\Engines\Chatbot\Services\ChatbotWebsiteCrawler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * CHATBOT888 — async website crawl for the chatbot knowledge base.
 *
 * Dispatched by the SPA and WP-plugin "Refresh from website" actions.
 * Walks seo_content_index for the workspace, fetches each URL, extracts
 * plaintext, and stores it as source_type='website_crawl' rows in
 * chatbot_knowledge_sources (+ chunks). See ChatbotWebsiteCrawler for the
 * page-level logic.
 *
 * Concurrency: a 10-minute Cache lock prevents overlapping crawls for the
 * same workspace. The UI can read `chatbot_crawl:ws:{wsId}` to see the
 * current status payload.
 */
class CrawlChatbotKnowledgeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Worker timeout — generous enough for ~100 pages at 8s/req worst case. */
    public int $timeout = 900;

    /** Don't auto-retry — a crawl failure is usually fatal (DNS, paywall, etc.). */
    public int $tries = 1;

    public function __construct(
        public int $workspaceId,
        public int $maxPages = ChatbotWebsiteCrawler::DEFAULT_MAX_PAGES,
    ) {}

    public function handle(ChatbotWebsiteCrawler $crawler): void
    {
        $key = self::statusKey($this->workspaceId);

        Cache::put($key, [
            'status'     => 'running',
            'started_at' => now()->toIso8601String(),
            'max_pages'  => $this->maxPages,
        ], 600);

        Log::info('[chatbot-crawler] job start', [
            'workspace_id' => $this->workspaceId,
            'max_pages'    => $this->maxPages,
        ]);

        try {
            $report = $crawler->crawlWorkspace($this->workspaceId, $this->maxPages);

            Cache::put($key, [
                'status'      => 'done',
                'started_at'  => Cache::get($key)['started_at'] ?? now()->toIso8601String(),
                'finished_at' => now()->toIso8601String(),
                'report'      => $report,
            ], 86400);

            Log::info('[chatbot-crawler] job done', [
                'workspace_id' => $this->workspaceId,
                'report'       => $report,
            ]);
        } catch (\Throwable $e) {
            Cache::put($key, [
                'status'      => 'failed',
                'started_at'  => Cache::get($key)['started_at'] ?? now()->toIso8601String(),
                'finished_at' => now()->toIso8601String(),
                'error'       => $e->getMessage(),
            ], 86400);

            Log::error('[chatbot-crawler] job failed', [
                'workspace_id' => $this->workspaceId,
                'error'        => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public static function statusKey(int $workspaceId): string
    {
        return "chatbot_crawl:ws:{$workspaceId}";
    }

    /** Dispatcher-side guard so the SPA/WP endpoints can refuse overlap. */
    public static function isRunning(int $workspaceId): bool
    {
        $s = Cache::get(self::statusKey($workspaceId));
        return is_array($s) && ($s['status'] ?? null) === 'running';
    }
}
