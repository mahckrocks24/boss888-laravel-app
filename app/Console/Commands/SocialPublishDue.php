<?php

namespace App\Console\Commands;

use App\Core\Distribution\PublisherService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * SOCIAL-888 PUBLISH-HARDENING (2026-09-04) — the canonical social scheduled-publish worker.
 *
 * Publishes approved, due `publisher_posts` through PublisherService::execute — the SAME
 * canonical path as manual publishing (real Graph connectors, honesty guard, idempotency,
 * truthful states). Transport is dry-run (MockTransport) until config('publisher.live_transport')
 * is enabled after Meta App Review, so scheduling this now is safe:
 *   - execute() re-checks explicit approval and the scheduled time,
 *   - execute() is idempotent (terminal-state suppression) so a re-run never double-publishes,
 *   - credit is settled inside the canonical path exactly once.
 * Also reconciles any publishing_unknown variations before they could be blindly retried.
 */
class SocialPublishDue extends Command
{
    protected $signature = 'social:publish-due {--limit=25}';
    protected $description = 'Publish approved, due social publisher_posts via the canonical publisher (dry-run until live_transport)';

    public function handle(PublisherService $pub): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $due   = $pub->dueForPublishing($limit);

        $ok = 0; $fail = 0;
        foreach ($due as $post) {
            try {
                $r = $pub->execute((int) $post->workspace_id, (int) $post->id);
                ($r['ok'] ?? false) ? $ok++ : $fail++;
            } catch (\Throwable $e) {
                $fail++;
                Log::error('social:publish-due execute failed', ['post_id' => $post->id ?? null, 'error' => $e->getMessage()]);
            }
        }

        Cache::put('social:publish-due:last_run', now()->toIso8601String(), 86400);
        if ($ok || $fail) {
            Log::info('social.publish_due', ['due' => count($due), 'ok' => $ok, 'fail' => $fail]);
        }
        $this->line(json_encode(['due' => count($due), 'ok' => $ok, 'fail' => $fail]));
        return self::SUCCESS;
    }
}
