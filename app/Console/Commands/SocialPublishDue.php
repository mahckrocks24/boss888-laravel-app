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

    public function handle(PublisherService $pub, \App\Engines\Social\Services\SocialService $social): int
    {
        $limit = max(1, (int) $this->option('limit'));

        // ── Lane 1: canonical publisher_posts (approved, due) — PublisherService::execute.
        $due = $pub->dueForPublishing($limit);
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

        // ── Lane 2 (SOC-P1-5): standalone Social-nav scheduled posts (no publisher_post_id).
        // They converge onto the SAME canonical publisher via SocialService::publishPost — no
        // second publisher, no duplicated logic. publishPost applies idempotency + execution lock
        // + honesty guard. While the transport is dry-run (Meta App Review pending) a due post
        // validates to 'dry_run_ok' and is LEFT scheduled so it goes out on the live cutover;
        // a real failure drops to draft with a failure_class; a live success becomes 'published'.
        // While the transport is dry-run (Meta App Review pending) a due post can only validate to
        // 'dry_run_ok'; exclude those from re-processing so attempt_count does not churn every minute.
        // On the live cutover they become eligible again and are really published on the first live run.
        $excludeExec = ['published', 'executing'];
        if (!config('publisher.live_transport', false)) { $excludeExec[] = 'dry_run_ok'; }
        $standalone = \Illuminate\Support\Facades\DB::table('social_posts')
            ->where('status', 'scheduled')
            ->whereNull('publisher_post_id')
            ->whereNull('deleted_at')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->whereNotIn(\Illuminate\Support\Facades\DB::raw("COALESCE(execution_status,'')"), $excludeExec)
            ->limit($limit)->get();

        $sOk = 0; $sDry = 0; $sUnknown = 0; $sFail = 0;
        foreach ($standalone as $p) {
            try { $social->publishPost((int) $p->id, (int) $p->workspace_id); }
            catch (\Throwable $e) { /* dry-run + failure both throw; classify by the row state it wrote */ }
            $row = \Illuminate\Support\Facades\DB::table('social_posts')->where('id', $p->id)->first();
            switch ($row->execution_status ?? '') {
                case 'published':          $sOk++; break;
                case 'dry_run_ok':
                    // Validated but nothing was sent — keep it scheduled for the live cutover.
                    \Illuminate\Support\Facades\DB::table('social_posts')->where('id', $p->id)
                        ->update(['status' => 'scheduled', 'updated_at' => now()]);
                    $sDry++; break;
                case 'publishing_unknown': $sUnknown++; break;
                default:                   $sFail++;
            }
        }

        Cache::put('social:publish-due:last_run', now()->toIso8601String(), 86400);
        $summary = ['publisher_due' => count($due), 'pub_ok' => $ok, 'pub_fail' => $fail,
            'standalone_due' => count($standalone), 'sa_published' => $sOk, 'sa_dry_run' => $sDry,
            'sa_unknown' => $sUnknown, 'sa_fail' => $sFail];
        if ($ok || $fail || count($standalone)) Log::info('social.publish_due', $summary);
        $this->line(json_encode($summary));
        return self::SUCCESS;
    }
}
