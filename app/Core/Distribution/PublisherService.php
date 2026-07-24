<?php

namespace App\Core\Distribution;

use App\Connectors\DryRunSocialConnector;
use App\Core\Billing\CreditService;
use App\Core\LaunchScope\ArticleShareToken;
use App\Core\LaunchScope\LaunchScopePolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * CONTENT PUBLISHER — the owner of manual publishing.
 *
 * Evolved from ArticleDistributionService (W5) rather than replacing it: the
 * validation, canonical-URL resolution, grounded captions, idempotency, audit and
 * credit protection are the same machinery, widened from "share a published
 * article" to "publish content the user composed".
 *
 * WHAT THIS IS NOT
 * ----------------
 * It is not social media management. There is no listening, no sentiment, no
 * competitor monitoring, no engagement, no inbox, no analytics dashboard, no
 * campaign generation, and no autonomous or recurring posting. Those stay removed.
 *
 * THE APPROVAL BOUNDARY
 * ---------------------
 * Nothing reaches a provider without an explicit user act. Structurally:
 * execute() cannot run without a manual-publish ArticleShareToken, and that token
 * cannot be minted without an approval_method from
 * ArticleShareToken::EXPLICIT_APPROVALS plus a real approver id. Uploading media
 * and generating captions are deliberately incapable of producing one. Autonomous
 * publishing is therefore unrepresentable, not merely disallowed.
 *
 * MODEL
 * -----
 * publisher_posts = the user's unit of work (media + base caption + platforms +
 * ONE approval). social_posts = one row per platform: the caption variation and
 * the publication record. Platforms succeed and fail independently.
 */
class PublisherService
{
    public const ENGINE = 'social';
    public const ACTION = 'social_publish_post';

    /** Credit model — reportCreditModel(). Generation is the only cost. */
    public const CREDIT_CAPTION_GENERATED = 1;
    public const CREDIT_CAPTION_FALLBACK  = 0;
    public const CREDIT_PUBLISH           = 0;

    public const STATUSES = [
        'draft', 'awaiting_approval', 'approved', 'scheduled', 'publishing',
        // 'validated' = every platform payload was built and accepted, but the
        // transport was not live, so nothing was sent. Distinct from 'published'
        // on purpose — see the honesty guard in callProvider().
        'validated', 'published', 'partially_published', 'failed', 'cancelled',
    ];

    public function __construct(
        private CanonicalUrlResolver $urls,
        private ShareCaptionService $captions,
        private DryRunSocialConnector $dryRun,
        private CreditService $credits,
    ) {}

    /** Overridden in tests to script provider responses. */
    private ?\App\Core\Publisher\Transport $transportOverride = null;

    public function useTransport(\App\Core\Publisher\Transport $t): void
    {
        $this->transportOverride = $t;
    }

    /**
     * The seam to the outside world.
     *
     * Live sending is OFF and stays off until the Meta app holds
     * pages_manage_posts / instagram_content_publish AND designated non-customer
     * test assets exist. Until then every provider call runs through
     * MockTransport, which has no network access, so the connector logic is
     * fully exercised while contacting Meta remains impossible by construction.
     */
    private function transport(): \App\Core\Publisher\Transport
    {
        if ($this->transportOverride !== null) return $this->transportOverride;
        return config('publisher.live_transport', false)
            ? new \App\Core\Publisher\HttpTransport()
            : new \App\Core\Publisher\MockTransport();
    }

    /** Canonical hashes the approval binds to. */
    public static function captionHash(string $caption): string
    {
        return hash('sha256', trim($caption));
    }

    public static function mediaHash(array $media): string
    {
        $norm = array_map(fn($m) => [
            'source' => $m['source'] ?? null, 'id' => $m['id'] ?? null, 'url' => $m['url'] ?? null,
        ], array_values($media));
        return hash('sha256', json_encode($norm, JSON_UNESCAPED_SLASHES));
    }

    public static function scheduleKey(?string $scheduledAt): string
    {
        return $scheduledAt === null || $scheduledAt === ''
            ? 'immediate'
            : substr((string) $scheduledAt, 0, 16);
    }

    // ═════════════════════════════════════════════════════════════════════
    //  1. DRAFT — media in, per-platform variations out. Never publishes.
    // ═════════════════════════════════════════════════════════════════════

    /**
     * @param array $input source_type, [article_id], [media], [caption_base],
     *                     platforms[], [created_by]
     */
    public function createDraft(int $wsId, array $input): array
    {
        $corr = (string) Str::uuid();

        $source = strtolower((string) ($input['source_type'] ?? 'upload'));
        if (!in_array($source, ['upload', 'media_library', 'studio', 'article'], true)) {
            return $this->fail('INVALID_SOURCE_TYPE', $corr);
        }

        // ── Platforms: must be known AND selectable on this deployment.
        $platforms = array_values(array_unique(array_map(
            fn($p) => PlatformPolicy::normalise((string) $p),
            (array) ($input['platforms'] ?? [])
        )));
        if ($platforms === []) return $this->fail('NO_PLATFORM_SELECTED', $corr);

        foreach ($platforms as $p) {
            $c = PlatformPolicy::check($p, PlatformPolicy::INTENT_MANUAL_PUBLISH);
            if (!$c['ok']) return $this->fail($c['reason'], $corr);
        }

        // ── Media.
        $media = $this->normaliseMedia($wsId, (array) ($input['media'] ?? []));
        if (!$media['ok']) return $this->fail($media['reason'], $corr);

        foreach ($platforms as $p) {
            if (PlatformPolicy::requiresMedia($p) && $media['items'] === []) {
                return $this->fail(strtoupper($p) . '_REQUIRES_MEDIA', $corr);
            }
            if (count($media['items']) > PlatformPolicy::maxMedia($p)) {
                return $this->fail(strtoupper($p) . '_TOO_MANY_MEDIA', $corr);
            }
        }

        // ── Article-sourced posts keep the W5 guarantees.
        $article = null; $canonical = null;
        if ($source === 'article') {
            $v = $this->validateArticle($wsId, (int) ($input['article_id'] ?? 0));
            if (!$v['ok']) return $this->fail($v['reason'], $corr);
            $article = $v['article'];
            $u = $this->urls->resolve($wsId, $article);
            if (!$u['ok']) return $this->fail($u['reason'], $corr);
            $canonical = $u['url'];
        }

        $idem = 'pub_' . hash('sha256', implode('|', [
            $wsId, $source, $input['article_id'] ?? 0,
            json_encode($media['items']), implode(',', $platforms),
            (string) ($input['caption_base'] ?? ''),
            (string) ($input['idempotency_nonce'] ?? $corr),
        ]));

        $existing = DB::table('publisher_posts')->where('idempotency_key', $idem)->first();
        if ($existing) {
            return ['ok' => true, 'duplicate' => true, 'post_id' => (int) $existing->id,
                    'status' => $existing->status, 'credits_charged' => 0, 'correlation_id' => $corr];
        }

        // ── Base caption.
        $base = trim((string) ($input['caption_base'] ?? ''));
        $cost = 0;
        if ($base === '' && $article !== null) {
            $gen  = $this->captions->build($article, $platforms[0], (string) $canonical);
            $base = $gen['content'];
            $cost = $gen['source'] === 'generated' ? self::CREDIT_CAPTION_GENERATED : self::CREDIT_CAPTION_FALLBACK;
        }

        $reservation = null;
        if ($cost > 0) {
            if (!$this->credits->hasBalance($wsId, $cost)) return $this->fail('INSUFFICIENT_CREDITS', $corr);
            $reservation = $this->credits->reserve($wsId, $cost, 'publisher_caption');
        }

        try {
            $postId = DB::table('publisher_posts')->insertGetId([
                'workspace_id'     => $wsId,
                'source_type'      => $source,
                'article_id'       => $article?->id,
                'canonical_url'    => $canonical,
                'media_json'       => json_encode($media['items']),
                'caption_base'     => $base,
                'target_platforms' => json_encode($platforms),
                'status'           => 'draft',
                'idempotency_key'  => $idem,
                'correlation_id'   => $corr,
                'created_by'       => $input['created_by'] ?? null,
                'created_at'       => now(), 'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            if ($reservation) $this->credits->release($wsId, $reservation);
            $raced = DB::table('publisher_posts')->where('idempotency_key', $idem)->first();
            if ($raced) return ['ok' => true, 'duplicate' => true, 'post_id' => (int) $raced->id, 'credits_charged' => 0];
            Log::error('[Publisher] draft insert failed', ['error' => $e->getMessage(), 'correlation_id' => $corr]);
            return $this->fail('DRAFT_PERSIST_FAILED', $corr);
        }

        if ($reservation) $this->credits->commit($wsId, $reservation, $cost);

        // ── One variation row per platform. Independently editable.
        $variations = [];
        foreach ($platforms as $p) {
            $text = $this->captions->adaptForPlatform($base, $p, (string) $canonical);
            $vid  = DB::table('social_posts')->insertGetId([
                'workspace_id'      => $wsId,
                'source_type'       => $source === 'article' ? 'article' : 'publisher',
                'publisher_post_id' => $postId,
                'article_id'        => $article?->id,
                'canonical_url'     => $canonical,
                'idempotency_key'   => $idem . ':' . $p,
                'platform'          => $p,
                'content'           => $text,
                'media_json'        => json_encode($media['items']),
                'hashtags_json'     => json_encode([]),
                'status'            => 'draft',
                'approval_state'    => 'none',
                'execution_status'  => 'draft',
                'correlation_id'    => $corr,
                'created_at'        => now(), 'updated_at' => now(),
            ]);
            $variations[$p] = ['id' => $vid, 'content' => $text, 'length' => strlen($text),
                               'limit' => PlatformPolicy::maxLength($p)];
        }

        $this->syncCalendar($wsId, $postId);
        $this->audit('draft_created', $wsId, $postId, [
            'source' => $source, 'platforms' => $platforms, 'media' => count($media['items']),
            'credits_charged' => $cost, 'correlation_id' => $corr,
        ]);

        return [
            'ok' => true, 'duplicate' => false, 'post_id' => (int) $postId, 'status' => 'draft',
            'platforms' => $platforms, 'variations' => $variations, 'media' => $media['items'],
            'canonical_url' => $canonical, 'credits_charged' => $cost, 'correlation_id' => $corr,
            // Said plainly so no caller can mistake a draft for a publication.
            'approval_required' => true,
            'note' => 'Draft only. Nothing is published until the user explicitly approves.',
        ];
    }

    /** Edit one platform's caption. Never detaches article context. */
    public function updateVariation(int $wsId, int $postId, string $platform, string $caption, ?int $userId = null): array
    {
        $post = $this->loadPost($wsId, $postId);
        if (!$post) return $this->fail('POST_NOT_FOUND', null);
        if (in_array($post->status, ['published', 'publishing', 'cancelled'], true)) {
            return $this->fail('POST_ALREADY_FINALISED', $post->correlation_id);
        }

        $p = PlatformPolicy::normalise($platform);
        $var = DB::table('social_posts')->where('publisher_post_id', $postId)
               ->where('workspace_id', $wsId)->where('platform', $p)->first();
        if (!$var) return $this->fail('VARIATION_NOT_FOUND', $post->correlation_id);

        $caption = trim($caption);
        if ($caption === '') return $this->fail('CAPTION_EMPTY', $post->correlation_id);
        if (strlen($caption) > PlatformPolicy::maxLength($p)) {
            return $this->fail('CAPTION_EXCEEDS_' . strtoupper($p) . '_LIMIT', $post->correlation_id);
        }
        // Article shares must keep their link; free posts have no such constraint.
        if ($post->source_type === 'article' && $post->canonical_url
            && PlatformPolicy::linkIsClickable($p)
            && !str_contains($caption, (string) $post->canonical_url)) {
            return $this->fail('CAPTION_MUST_RETAIN_CANONICAL_URL', $post->correlation_id);
        }

        // Editing invalidates any prior approval — re-approval is required.
        DB::table('social_posts')->where('id', $var->id)->update([
            'content' => $caption, 'approval_state' => 'none', 'updated_at' => now(),
        ]);
        DB::table('publisher_posts')->where('id', $postId)->update([
            'status' => 'draft', 'approval_method' => null, 'approved_by' => null,
            'approved_at' => null, 'updated_at' => now(),
        ]);
        $this->syncCalendar($wsId, $postId);

        $this->audit('variation_edited', $wsId, $postId, [
            'platform' => $p, 'by' => $userId, 'length' => strlen($caption),
            'reapproval_required' => true, 'correlation_id' => $post->correlation_id,
        ]);
        return ['ok' => true, 'post_id' => $postId, 'platform' => $p, 'content' => $caption,
                'approval_required' => true];
    }

    // ═════════════════════════════════════════════════════════════════════
    //  2. APPROVAL — the only door to publishing.
    // ═════════════════════════════════════════════════════════════════════

    /**
     * @param string $approvalMethod MUST be in ArticleShareToken::EXPLICIT_APPROVALS
     * @param array  $opts           [scheduled_at], [timezone], [social_account_ids => [platform=>id]]
     */
    public function approve(int $wsId, int $postId, string $approvalMethod, ?int $userId, array $opts = []): array
    {
        $post = $this->loadPost($wsId, $postId);
        if (!$post) return $this->fail('POST_NOT_FOUND', null);
        $corr = (string) $post->correlation_id;

        // ── THE BOUNDARY. Uploading or captioning can never land here.
        if (!ArticleShareToken::isExplicitApproval($approvalMethod)) {
            $this->audit('approval_refused', $wsId, $postId, [
                'method' => $approvalMethod, 'reason' => 'NOT_AN_EXPLICIT_USER_APPROVAL', 'correlation_id' => $corr,
            ]);
            return $this->fail('APPROVAL_NOT_EXPLICIT', $corr);
        }
        if (empty($userId)) return $this->fail('APPROVAL_REQUIRES_USER', $corr);

        if (in_array($post->status, ['published', 'publishing'], true)) {
            return ['ok' => true, 'duplicate' => true, 'post_id' => $postId, 'status' => $post->status];
        }
        if ($post->status === 'cancelled') return $this->fail('POST_CANCELLED', $corr);

        // Re-validate the whole post at approval time.
        $check = $this->revalidate($wsId, $post);
        if (!$check['ok']) return $this->fail($check['reason'], $corr);

        $schedule = $this->normaliseSchedule($opts);
        if (!$schedule['ok']) return $this->fail($schedule['reason'], $corr);

        // Bind an account per platform now, so approval covers a concrete target.
        $accounts = $this->resolveAccounts($wsId, $post, $opts['social_account_ids'] ?? []);
        if (!$accounts['ok']) return $this->fail($accounts['reason'], $corr);

        DB::table('publisher_posts')->where('id', $postId)->update([
            'status'          => $schedule['mode'] === 'scheduled' ? 'scheduled' : 'approved',
            'approval_method' => $approvalMethod,
            'approved_by'     => $userId,
            'approved_at'     => now(),
            'scheduled_at'    => $schedule['at'],
            'timezone'        => $schedule['tz'],
            'approved_schedule_key' => self::scheduleKey($schedule['at']),
            'updated_at'      => now(),
        ]);
        foreach ($accounts['map'] as $platform => $acc) {
            $row = DB::table('social_posts')->where('publisher_post_id', $postId)
                   ->where('platform', $platform)->first();
            if (!$row) continue;

            // Freeze exactly what was approved. execute() recomputes these and
            // refuses if anything drifted.
            DB::table('social_posts')->where('id', $row->id)->update([
                'social_account_id' => $acc->id,
                'approval_state'    => 'approved',
                'execution_status'  => $schedule['mode'] === 'scheduled' ? 'scheduled' : 'approved',
                'status'            => $schedule['mode'] === 'scheduled' ? 'scheduled' : 'draft',
                'scheduled_at'      => $schedule['at'],
                'approved_caption_hash' => self::captionHash((string) $row->content),
                'approved_media_hash'   => self::mediaHash(json_decode((string) $row->media_json, true) ?: []),
                'updated_at'        => now(),
            ]);
        }

        $this->syncCalendar($wsId, $postId);
        $this->notify($wsId, $userId, 'publisher.approved',
            $schedule['mode'] === 'scheduled' ? 'Post scheduled' : 'Post approved',
            $schedule['mode'] === 'scheduled'
                ? 'Your post is scheduled for ' . $schedule['at'] . ' UTC.'
                : 'Your post is approved and ready to publish.',
            'success', ['post_id' => $postId, 'correlation_id' => $corr]);

        $this->audit('approved', $wsId, $postId, [
            'method' => $approvalMethod, 'by' => $userId, 'mode' => $schedule['mode'],
            'scheduled_at' => $schedule['at'], 'correlation_id' => $corr,
        ]);

        return ['ok' => true, 'duplicate' => false, 'post_id' => $postId,
                'status' => $schedule['mode'] === 'scheduled' ? 'scheduled' : 'approved',
                'approval_method' => $approvalMethod, 'scheduled_at' => $schedule['at'],
                'mode' => $schedule['mode'], 'correlation_id' => $corr];
    }

    public function cancel(int $wsId, int $postId, ?int $userId = null): array
    {
        $post = $this->loadPost($wsId, $postId);
        if (!$post) return $this->fail('POST_NOT_FOUND', null);
        if ($post->status === 'published') return $this->fail('CANNOT_CANCEL_PUBLISHED', $post->correlation_id);

        DB::table('publisher_posts')->where('id', $postId)->update(['status' => 'cancelled', 'updated_at' => now()]);
        DB::table('social_posts')->where('publisher_post_id', $postId)
            ->update(['execution_status' => 'cancelled', 'updated_at' => now()]);
        $this->syncCalendar($wsId, $postId);
        $this->audit('cancelled', $wsId, $postId, ['by' => $userId, 'correlation_id' => $post->correlation_id]);
        return ['ok' => true, 'post_id' => $postId, 'status' => 'cancelled'];
    }

    // ═════════════════════════════════════════════════════════════════════
    //  3. EXECUTE — per platform. Requires an approved post. No transmission.
    // ═════════════════════════════════════════════════════════════════════

    public function execute(int $wsId, int $postId): array
    {
        $post = $this->loadPost($wsId, $postId);
        if (!$post) return $this->fail('POST_NOT_FOUND', null);
        $corr = (string) $post->correlation_id;

        $terminal = ['published', 'partially_published'];
        // 'validated' is terminal only while sending is still dry — see the
        // per-variation rule below.
        if (!$this->transport()->isLive()) $terminal[] = 'validated';
        if (in_array($post->status, $terminal, true)) {
            $this->audit('execute_duplicate_suppressed', $wsId, $postId, ['correlation_id' => $corr]);
            return ['ok' => true, 'duplicate' => true, 'post_id' => $postId, 'status' => $post->status,
                    'credits_charged' => 0];
        }
        if ($post->status === 'cancelled') return $this->fail('POST_CANCELLED', $corr);

        // Approval is re-checked here, not trusted from earlier.
        if (!ArticleShareToken::isExplicitApproval($post->approval_method) || empty($post->approved_by)) {
            $this->audit('execute_refused_unapproved', $wsId, $postId, [
                'method' => $post->approval_method, 'correlation_id' => $corr,
            ]);
            return $this->fail('POST_NOT_EXPLICITLY_APPROVED', $corr);
        }
        if ($post->scheduled_at && strtotime((string) $post->scheduled_at) > time()) {
            return $this->fail('SCHEDULED_TIME_NOT_REACHED', $corr);
        }

        $check = $this->revalidate($wsId, $post);
        if (!$check['ok']) return $this->failPost($wsId, $post, $check['reason']);

        DB::table('publisher_posts')->where('id', $postId)->update(['status' => 'publishing', 'updated_at' => now()]);

        $results = []; $okCount = 0; $failCount = 0;
        $variations = DB::table('social_posts')->where('publisher_post_id', $postId)
                      ->where('workspace_id', $wsId)->get();

        $live = $this->transport()->isLive();
        foreach ($variations as $v) {
            // 'published' is terminal: it really went out, never send it twice.
            // 'dry_run_ok' is terminal ONLY while the transport is still dry —
            // otherwise enabling live sending would silently skip every post
            // that had previously been validated, and nothing would publish.
            $alreadyDone = $v->execution_status === 'published'
                || ($v->execution_status === 'dry_run_ok' && !$live);
            if ($alreadyDone) {
                $results[$v->platform] = ['ok' => true, 'duplicate' => true,
                                          'transmitted' => $v->execution_status === 'published'];
                $okCount++;
                continue;
            }

            $r = $this->executeOne($wsId, $post, $v);
            $results[$v->platform] = $r;
            $r['ok'] ? $okCount++ : $failCount++;
        }

        // Same honesty rule at post level: a run that transmitted nothing is not
        // a publication. 'validated' says exactly what happened.
        $anyLive = false;
        foreach ($results as $res) { if (($res['transmitted'] ?? false) === true) { $anyLive = true; break; } }
        unset($res);
        $status = $failCount === 0
            ? ($anyLive ? 'published' : 'validated')
            : ($okCount > 0 ? 'partially_published' : 'failed');
        DB::table('publisher_posts')->where('id', $postId)->update([
            'status' => $status,
            'published_at' => ($okCount > 0 && $anyLive) ? now() : null,
            'updated_at' => now(),
        ]);
        $this->syncCalendar($wsId, $postId);

        $this->notify($wsId, (int) $post->approved_by, 'publisher.' . $status,
            match ($status) {
                'published' => 'Post published',
                'validated' => 'Post validated (not yet sent)',
                'partially_published' => 'Post partially published',
                default => 'Post failed',
            },
            $failCount === 0
                ? ($anyLive
                    ? 'Your post is live on the selected channels.'
                    : 'All platform payloads were built and validated. Provider publishing is not enabled yet, so nothing was posted.')
                : "{$okCount} succeeded, {$failCount} failed.",
            $failCount === 0 ? 'success' : ($okCount > 0 ? 'warning' : 'error'),
            ['post_id' => $postId, 'correlation_id' => $corr]);

        $this->audit('executed', $wsId, $postId, [
            'status' => $status, 'ok' => $okCount, 'failed' => $failCount,
            'transmitted' => false, 'credits_charged' => self::CREDIT_PUBLISH, 'correlation_id' => $corr,
        ]);

        return ['ok' => $failCount === 0, 'duplicate' => false, 'post_id' => $postId,
                'status' => $status, 'transmitted' => false, 'results' => $results,
                'credits_charged' => self::CREDIT_PUBLISH, 'correlation_id' => $corr];
    }

    /**
     * One platform. Verifies the approval still describes reality, checks the
     * connection is usable, mints the credential, passes the kernel, then calls
     * the provider connector.
     */
    private function executeOne(int $wsId, object $post, object $v): array
    {
        $account = $this->validateAccount($wsId, (int) $v->social_account_id, $v->platform);
        if (!$account['ok']) return $this->failVariation($wsId, $v, $account['reason']);

        // ── Connection health BEFORE anything else. Saying "not connected" when
        //    a token was revoked sends the user to the wrong screen.
        $health = \App\Core\Publisher\ConnectionHealth::assess($account['account']);
        if (!$health['ok']) {
            return $this->failVariation($wsId, $v, $health['reason'] ?? 'CONNECTION_UNUSABLE', [], $health['message']);
        }

        // ── The approval must still describe the artefact. Any drift voids it.
        $media = json_decode((string) $v->media_json, true) ?: [];
        if ($v->approved_caption_hash !== null
            && !hash_equals((string) $v->approved_caption_hash, self::captionHash((string) $v->content))) {
            return $this->failVariation($wsId, $v, 'APPROVAL_VOID_CAPTION_CHANGED');
        }
        if ($v->approved_media_hash !== null
            && !hash_equals((string) $v->approved_media_hash, self::mediaHash($media))) {
            return $this->failVariation($wsId, $v, 'APPROVAL_VOID_MEDIA_CHANGED');
        }
        if (($post->approved_schedule_key ?? null) !== null
            && !hash_equals((string) $post->approved_schedule_key, self::scheduleKey($post->scheduled_at))) {
            return $this->failVariation($wsId, $v, 'APPROVAL_VOID_SCHEDULE_CHANGED');
        }

        if (PlatformPolicy::requiresMedia($v->platform) && $media === []) {
            return $this->failVariation($wsId, $v, strtoupper($v->platform) . '_REQUIRES_MEDIA');
        }

        $token = ArticleShareToken::mint([
            'workspace_id'      => $wsId,
            'publisher_post_id' => $post->id,
            'platform'          => $v->platform,
            'social_account_id' => $v->social_account_id,
            'idempotency_key'   => $v->idempotency_key,
            'approval_method'   => $post->approval_method,
            'approved_by'       => $post->approved_by,
            'caption_hash'      => self::captionHash((string) $v->content),
            'media_hash'        => self::mediaHash($media),
            'schedule_key'      => self::scheduleKey($post->scheduled_at),
        ], ArticleShareToken::INTENT_MANUAL_PUBLISH);

        $params = [
            'workspace_id' => $wsId, 'publisher_post_id' => $post->id,
            'platform' => $v->platform, 'social_account_id' => $v->social_account_id,
            'idempotency_key' => $v->idempotency_key, 'approval_method' => $post->approval_method,
            'approved_by' => $post->approved_by,
            'caption_hash' => self::captionHash((string) $v->content),
            'media_hash' => self::mediaHash($media),
            'schedule_key' => self::scheduleKey($post->scheduled_at),
            'distribution_token' => $token,
        ];
        $denied = LaunchScopePolicy::deniedReason(self::ENGINE, self::ACTION, $params, []);
        if ($denied !== null) return $this->failVariation($wsId, $v, $denied);

        DB::table('social_posts')->where('id', $v->id)->update([
            'execution_status' => 'executing',
            'attempt_count' => DB::raw('attempt_count + 1'),
            'updated_at' => now(),
        ]);

        return $this->callProvider($wsId, $post, $v, $account['account'], $media);
    }

    /** Dispatch to the platform connector and record a normalised outcome. */
    private function callProvider(int $wsId, object $post, object $v, object $account, array $media): array
    {
        $creds = \App\Core\Publisher\ConnectionHealth::readCredentials($account);
        $conn  = [
            'page_id'      => $account->linked_page_id ?: $account->account_id,
            'ig_user_id'   => $account->account_id,
            'access_token' => $creds['page_access_token'] ?? $creds['access_token'] ?? '',
        ];
        $payload = [
            'content' => $v->content, 'media' => $media,
            'canonical_url' => $v->canonical_url ?? '',
            'idempotency_key' => $v->idempotency_key,
            'correlation_id' => $v->correlation_id,
        ];

        $transport = $this->transport();
        $connector = match (PlatformPolicy::normalise($v->platform)) {
            'facebook'  => new \App\Core\Publisher\FacebookPublisherConnector($transport),
            'instagram' => new \App\Core\Publisher\InstagramPublisherConnector($transport),
            default     => null,
        };
        if ($connector === null) {
            return $this->failVariation($wsId, $v, 'NO_CONNECTOR_FOR_PLATFORM');
        }

        $r = $connector->publish($payload, $conn);

        // ── SUCCESS ─────────────────────────────────────────────────────
        // HONESTY GUARD. A connector "success" over a non-live transport means
        // the request was built and accepted by a mock — nothing reached Meta.
        // Marking such a row 'published', or storing a simulated id in
        // external_post_id, would tell the user their content went out when it
        // did not. Only a live transmission earns 'published'.
        if ($r['success']) {
            $live = $transport->isLive();

            DB::table('social_posts')->where('id', $v->id)->update([
                'execution_status'      => $live ? 'published' : 'dry_run_ok',
                'status'                => $live ? 'published' : 'draft',
                // Never persist a mock id as though it were a real provider post.
                'external_post_id'      => $live ? $r['provider_post_id'] : null,
                'provider_request_id'   => $live ? ($r['provider_request_id'] ?? null) : null,
                'provider_status_class' => $r['status_class'],
                'provider_error_code'   => null,
                'failure_class'         => null,
                'published_at'          => $live ? now() : null,
                'provider_payload_json' => json_encode([
                    'provider'    => $r['provider'],
                    'transmitted' => $live,
                    'endpoint'    => $r['endpoint'] ?? null,
                    'provider_post_id'  => $live ? $r['provider_post_id'] : null,
                    'simulated_id'      => $live ? null : $r['provider_post_id'],
                    'at' => now()->toIso8601String(),
                ]),
                'updated_at' => now(),
            ]);

            $this->audit($live ? 'published' : 'dry_run_ok', $wsId, (int) $post->id, [
                'platform' => $v->platform,
                'provider_post_id' => $live ? $r['provider_post_id'] : null,
                'transmitted' => $live, 'correlation_id' => $v->correlation_id,
            ]);

            return ['ok' => true, 'transmitted' => $live, 'platform' => $v->platform,
                    'execution_status' => $live ? 'published' : 'dry_run_ok',
                    'endpoint' => $r['endpoint'] ?? null,
                    'provider_post_id' => $live ? $r['provider_post_id'] : null,
                    'simulated_id' => $live ? null : $r['provider_post_id']];
        }

        // ── UNCERTAIN — the provider may have created the post. NEVER retry
        //    blindly; reconcile against the provider instead.
        if (($r['status_class'] ?? '') === \App\Core\Publisher\MetaErrorMap::UNCERTAIN) {
            DB::table('social_posts')->where('id', $v->id)->update([
                'execution_status'      => 'publishing_unknown',
                'provider_status_class' => \App\Core\Publisher\MetaErrorMap::UNCERTAIN,
                'provider_error_code'   => $r['error_code'],
                'provider_request_id'   => $r['provider_request_id'] ?? null,
                'failure_class'         => 'uncertain:' . ($r['error_code'] ?? 'UNKNOWN'),
                'updated_at'            => now(),
            ]);
            $this->audit('publishing_unknown', $wsId, (int) $post->id, [
                'platform' => $v->platform, 'error_code' => $r['error_code'],
                'provider_request_id' => $r['provider_request_id'] ?? null,
                'note' => 'Outcome unknown; reconciliation required before any retry.',
                'correlation_id' => $v->correlation_id,
            ]);
            $this->notify($wsId, $post->approved_by ? (int) $post->approved_by : null,
                'publisher.uncertain', 'Checking whether your post went out',
                'The provider did not confirm the result. We are verifying before trying again, '
                . 'so nothing gets posted twice.', 'warning',
                ['post_id' => $post->id, 'platform' => $v->platform]);

            return ['ok' => false, 'uncertain' => true, 'platform' => $v->platform,
                    'error' => $r['error_code'], 'retryable' => false];
        }

        // ── FAILED / RETRYABLE ──────────────────────────────────────────
        if (!empty($r['needs_reconnect'])) {
            \App\Core\Publisher\ConnectionHealth::mark((int) $account->id,
                \App\Core\Publisher\ConnectionHealth::REVOKED, $r['error_code']);
        }
        DB::table('social_posts')->where('id', $v->id)->update([
            'execution_status'      => $r['retryable'] ? 'retry_pending' : 'failed',
            'provider_status_class' => $r['status_class'],
            'provider_error_code'   => $r['error_code'],
            'provider_request_id'   => $r['provider_request_id'] ?? null,
            'failure_class'         => ($r['retryable'] ? 'transient:' : 'permanent:') . ($r['error_code'] ?? 'UNKNOWN'),
            'updated_at'            => now(),
        ]);
        $this->audit('provider_failed', $wsId, (int) $post->id, [
            'platform' => $v->platform, 'error_code' => $r['error_code'],
            'retryable' => $r['retryable'], 'message' => $r['message'],
            'correlation_id' => $v->correlation_id,
        ]);
        return ['ok' => false, 'platform' => $v->platform, 'error' => $r['error_code'],
                'message' => $r['message'], 'retryable' => $r['retryable']];
    }

    /**
     * Resolve a publishing_unknown row by asking the provider what exists.
     * Only after this says "not found" may the post be retried.
     */
    public function reconcile(int $wsId, int $variationId): array
    {
        $v = DB::table('social_posts')->where('id', $variationId)->where('workspace_id', $wsId)->first();
        if (!$v) return ['ok' => false, 'error' => 'VARIATION_NOT_FOUND'];
        if ($v->execution_status !== 'publishing_unknown') {
            return ['ok' => true, 'nothing_to_do' => true, 'execution_status' => $v->execution_status];
        }

        $account = DB::table('social_accounts')->where('id', $v->social_account_id)
                   ->where('workspace_id', $wsId)->first();
        if (!$account) return ['ok' => false, 'error' => 'ACCOUNT_NOT_FOUND'];

        $creds = \App\Core\Publisher\ConnectionHealth::readCredentials($account);
        $conn  = ['page_id' => $account->linked_page_id ?: $account->account_id,
                  'ig_user_id' => $account->account_id,
                  'access_token' => $creds['page_access_token'] ?? $creds['access_token'] ?? ''];

        $transport = $this->transport();
        $connector = match (PlatformPolicy::normalise($v->platform)) {
            'facebook'  => new \App\Core\Publisher\FacebookPublisherConnector($transport),
            'instagram' => new \App\Core\Publisher\InstagramPublisherConnector($transport),
            default     => null,
        };
        if ($connector === null) return ['ok' => false, 'error' => 'NO_CONNECTOR_FOR_PLATFORM'];

        DB::table('social_posts')->where('id', $v->id)
          ->update(['reconcile_attempts' => DB::raw('reconcile_attempts + 1'), 'updated_at' => now()]);

        $res = $connector->reconcile(['content' => $v->content, 'correlation_id' => $v->correlation_id], $conn);

        if (!($res['resolved'] ?? false)) {
            // Still unknown. Stay in the safe state rather than risk a duplicate.
            $this->audit('reconcile_inconclusive', $wsId, (int) $v->publisher_post_id, [
                'platform' => $v->platform, 'reason' => $res['reason'] ?? null,
            ]);
            return ['ok' => false, 'resolved' => false, 'execution_status' => 'publishing_unknown'];
        }

        if ($res['found'] ?? false) {
            DB::table('social_posts')->where('id', $v->id)->update([
                'execution_status' => 'published', 'status' => 'published',
                'external_post_id' => $res['provider_id'] ?? null,
                'failure_class' => null, 'published_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit('reconciled_published', $wsId, (int) $v->publisher_post_id, [
                'platform' => $v->platform, 'provider_post_id' => $res['provider_id'] ?? null,
            ]);
            return ['ok' => true, 'resolved' => true, 'found' => true, 'execution_status' => 'published'];
        }

        // Confirmed absent — now, and only now, a retry is safe.
        DB::table('social_posts')->where('id', $v->id)->update([
            'execution_status' => 'retry_pending',
            'failure_class' => 'transient:RECONCILED_NOT_FOUND', 'updated_at' => now(),
        ]);
        $this->audit('reconciled_absent', $wsId, (int) $v->publisher_post_id, ['platform' => $v->platform]);
        return ['ok' => true, 'resolved' => true, 'found' => false, 'execution_status' => 'retry_pending'];
    }

    // ═════════════════════════════════════════════════════════════════════
    //  4. STATUS / HISTORY
    // ═════════════════════════════════════════════════════════════════════

    public function status(int $wsId, int $postId): array
    {
        $post = $this->loadPost($wsId, $postId);
        if (!$post) return $this->fail('POST_NOT_FOUND', null);

        $vars = DB::table('social_posts')->where('publisher_post_id', $postId)->where('workspace_id', $wsId)
                ->get(['platform', 'content', 'status', 'approval_state', 'execution_status',
                       'failure_class', 'attempt_count', 'external_post_id', 'scheduled_at']);

        return [
            'ok' => true, 'post_id' => $postId, 'status' => $post->status,
            'source_type' => $post->source_type, 'approval_method' => $post->approval_method,
            'approved_by' => $post->approved_by, 'approved_at' => $post->approved_at,
            'scheduled_at' => $post->scheduled_at, 'timezone' => $post->timezone,
            'published_at' => $post->published_at, 'correlation_id' => $post->correlation_id,
            'platforms' => $vars,
        ];
    }

    public function history(int $wsId, array $filters = []): array
    {
        $q = DB::table('publisher_posts')->where('workspace_id', $wsId)->whereNull('deleted_at');
        if (!empty($filters['status'])) $q->where('status', $filters['status']);
        if (!empty($filters['from']))   $q->where('created_at', '>=', $filters['from']);
        $total = $q->count();
        return ['ok' => true, 'total' => $total,
                'posts' => $q->orderByDesc('created_at')->limit($filters['limit'] ?? 50)->get()];
    }

    /** Posts whose scheduled time has arrived and which are explicitly approved. */
    public function dueForPublishing(int $limit = 25)
    {
        return DB::table('publisher_posts')
            ->where('status', 'scheduled')
            ->whereNotNull('approval_method')
            ->whereNotNull('approved_by')
            ->where('scheduled_at', '<=', now())
            ->whereNull('deleted_at')
            ->limit($limit)->get();
    }

    // ═════════════════════════════════════════════════════════════════════
    //  5. VALIDATION / SUPPORT
    // ═════════════════════════════════════════════════════════════════════

    private function revalidate(int $wsId, object $post): array
    {
        if ($post->source_type === 'article') {
            $a = $this->validateArticle($wsId, (int) $post->article_id);
            if (!$a['ok']) return ['ok' => false, 'reason' => $a['reason']];
            $u = $this->urls->validatePublicUrl((string) $post->canonical_url);
            if (!$u['ok']) return ['ok' => false, 'reason' => $u['reason']];
        }
        $platforms = json_decode((string) $post->target_platforms, true) ?: [];
        foreach ($platforms as $p) {
            $c = PlatformPolicy::check($p, PlatformPolicy::INTENT_MANUAL_PUBLISH);
            if (!$c['ok']) return ['ok' => false, 'reason' => $c['reason']];
        }
        return ['ok' => true];
    }

    /** Media must belong to the workspace. No cross-tenant asset use. */
    private function normaliseMedia(int $wsId, array $media): array
    {
        $out = [];
        foreach ($media as $m) {
            if (!is_array($m)) return ['ok' => false, 'reason' => 'MALFORMED_MEDIA'];
            $src = strtolower((string) ($m['source'] ?? 'upload'));
            if (!in_array($src, ['upload', 'media_library', 'studio'], true)) {
                return ['ok' => false, 'reason' => 'INVALID_MEDIA_SOURCE'];
            }

            if ($src === 'studio' && !empty($m['id'])) {
                $owned = DB::table('studio_designs')->where('id', $m['id'])
                         ->where('workspace_id', $wsId)->exists();
                if (!$owned) return ['ok' => false, 'reason' => 'MEDIA_CROSS_WORKSPACE'];
            }
            if ($src === 'media_library' && !empty($m['id'])) {
                $owned = DB::table('media')->where('id', $m['id'])
                         ->where('workspace_id', $wsId)->exists();
                if (!$owned) return ['ok' => false, 'reason' => 'MEDIA_CROSS_WORKSPACE'];
            }

            $url = (string) ($m['url'] ?? '');
            if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
                return ['ok' => false, 'reason' => 'MALFORMED_MEDIA_URL'];
            }

            $out[] = ['source' => $src, 'id' => $m['id'] ?? null, 'url' => $url,
                      'mime' => $m['mime'] ?? null];
        }
        return ['ok' => true, 'items' => $out];
    }

    private function resolveAccounts(int $wsId, object $post, array $explicit): array
    {
        $platforms = json_decode((string) $post->target_platforms, true) ?: [];
        $map = [];
        foreach ($platforms as $p) {
            $accId = $explicit[$p] ?? null;
            if ($accId === null) {
                $acc = DB::table('social_accounts')->where('workspace_id', $wsId)
                       ->where('platform', $p)->where('status', 'connected')->first();
                if (!$acc) return ['ok' => false, 'reason' => strtoupper($p) . '_NOT_CONNECTED'];
                $accId = $acc->id;
            }
            $v = $this->validateAccount($wsId, (int) $accId, $p);
            if (!$v['ok']) return ['ok' => false, 'reason' => $v['reason']];
            $map[$p] = $v['account'];
        }
        return ['ok' => true, 'map' => $map];
    }

    public function validateArticle(int $wsId, int $articleId): array
    {
        if ($articleId <= 0) return ['ok' => false, 'reason' => 'MISSING_ARTICLE_ID'];
        $a = DB::table('articles')->where('id', $articleId)->first();
        if (!$a)                                return ['ok' => false, 'reason' => 'ARTICLE_NOT_FOUND'];
        if ((int) $a->workspace_id !== $wsId)   return ['ok' => false, 'reason' => 'ARTICLE_CROSS_WORKSPACE'];
        if (!empty($a->deleted_at))             return ['ok' => false, 'reason' => 'ARTICLE_DELETED'];
        if (strtolower((string) $a->status) !== 'published') return ['ok' => false, 'reason' => 'ARTICLE_NOT_PUBLISHED'];
        $hasBody = trim(strip_tags((string) ($a->content ?? ''))) !== ''
                || trim(strip_tags((string) ($a->excerpt ?? ''))) !== '';
        if (!$hasBody)                          return ['ok' => false, 'reason' => 'ARTICLE_HAS_NO_CONTENT'];
        if (trim((string) ($a->title ?? '')) === '') return ['ok' => false, 'reason' => 'ARTICLE_HAS_NO_TITLE'];
        return ['ok' => true, 'article' => $a];
    }

    public function validateAccount(int $wsId, int $accountId, string $platform): array
    {
        if ($accountId <= 0) return ['ok' => false, 'reason' => 'MISSING_ACCOUNT_ID'];
        $acc = DB::table('social_accounts')->where('id', $accountId)->first();
        if (!$acc)                                return ['ok' => false, 'reason' => 'ACCOUNT_NOT_FOUND'];
        if ((int) $acc->workspace_id !== $wsId)   return ['ok' => false, 'reason' => 'ACCOUNT_CROSS_WORKSPACE'];
        if (strtolower((string) $acc->status) !== 'connected') return ['ok' => false, 'reason' => 'ACCOUNT_NOT_CONNECTED'];
        if (PlatformPolicy::normalise((string) $acc->platform) !== PlatformPolicy::normalise($platform)) {
            return ['ok' => false, 'reason' => 'ACCOUNT_PLATFORM_MISMATCH'];
        }
        $creds = json_decode((string) ($acc->credentials_json ?? '{}'), true);
        if (!is_array($creds) || empty($creds)) return ['ok' => false, 'reason' => 'ACCOUNT_CREDENTIALS_MISSING'];
        return ['ok' => true, 'account' => $acc];
    }

    private function normaliseSchedule(array $opts): array
    {
        $at = $opts['scheduled_at'] ?? null;
        if (empty($at)) return ['ok' => true, 'mode' => 'immediate', 'at' => null, 'tz' => null];

        $tz = (string) ($opts['timezone'] ?? 'UTC');
        try {
            $dt = new \DateTimeImmutable((string) $at, new \DateTimeZone($tz));
        } catch (\Throwable) {
            return ['ok' => false, 'reason' => 'INVALID_SCHEDULE_OR_TIMEZONE'];
        }
        $utc = $dt->setTimezone(new \DateTimeZone('UTC'));
        if ($utc->getTimestamp() <= time()) return ['ok' => false, 'reason' => 'SCHEDULE_IN_THE_PAST'];

        return ['ok' => true, 'mode' => 'scheduled', 'at' => $utc->format('Y-m-d H:i:s'), 'tz' => $tz];
    }

    private function loadPost(int $wsId, int $postId): ?object
    {
        return DB::table('publisher_posts')->where('id', $postId)
               ->where('workspace_id', $wsId)->whereNull('deleted_at')->first();
    }

    public static function classifyFailure(string $reason): string
    {
        $transient = ['PROVIDER_TIMEOUT', 'PROVIDER_5XX', 'RATE_LIMITED', 'NETWORK_ERROR', 'DRAFT_PERSIST_FAILED'];
        return in_array($reason, $transient, true) ? 'transient' : 'permanent';
    }

    private function failVariation(int $wsId, object $v, string $reason, array $violations = [], ?string $userMessage = null): array
    {
        $class = self::classifyFailure($reason);
        DB::table('social_posts')->where('id', $v->id)->update([
            'execution_status' => $class === 'transient' ? 'retry_pending' : 'failed',
            'failure_class'    => $class . ':' . $reason,
            'updated_at'       => now(),
        ]);
        $this->audit('variation_failed', $wsId, (int) $v->publisher_post_id, [
            'platform' => $v->platform, 'reason' => $reason, 'class' => $class,
            'violations' => $violations, 'correlation_id' => $v->correlation_id,
        ]);
        return ['ok' => false, 'error' => $reason, 'failure_class' => $class,
                'message' => $userMessage, 'retryable' => $class === 'transient'];
    }

    private function failPost(int $wsId, object $post, string $reason): array
    {
        DB::table('publisher_posts')->where('id', $post->id)->update(['status' => 'failed', 'updated_at' => now()]);
        $this->syncCalendar($wsId, (int) $post->id);
        $this->audit('post_failed', $wsId, (int) $post->id, [
            'reason' => $reason, 'correlation_id' => $post->correlation_id,
        ]);
        $this->notify($wsId, $post->approved_by ? (int) $post->approved_by : null, 'publisher.failed',
            'Post could not be published',
            str_contains($reason, 'ACCOUNT_') || str_contains($reason, 'CREDENTIALS')
                ? 'The connected account needs to be reconnected before this can publish.'
                : 'This post was stopped: ' . $reason . '. Nothing was posted.',
            'error', ['post_id' => $post->id, 'reason' => $reason]);
        return ['ok' => false, 'error' => $reason, 'post_id' => (int) $post->id, 'credits_charged' => 0];
    }

    /** One calendar event per post, carrying the publishing lifecycle state. */
    private function syncCalendar(int $wsId, int $postId): void
    {
        try {
            $post = DB::table('publisher_posts')->find($postId);
            if (!$post) return;

            if ($post->status === 'cancelled') {
                DB::table('calendar_events')->where('workspace_id', $wsId)
                  ->where('reference_type', 'publisher_post')->where('reference_id', $postId)->delete();
                return;
            }

            $platforms = json_decode((string) $post->target_platforms, true) ?: [];
            DB::table('calendar_events')->updateOrInsert(
                ['workspace_id' => $wsId, 'reference_type' => 'publisher_post', 'reference_id' => $postId],
                [
                    'title'       => 'Publish · ' . implode(', ', array_map([PlatformPolicy::class, 'label'], $platforms)),
                    'description' => 'Status: ' . $post->status,
                    'category'    => 'content', 'engine' => 'publisher',
                    'starts_at'   => $post->scheduled_at ?: ($post->published_at ?: now()),
                    'all_day'     => 0,
                    'created_at'  => now(), 'updated_at' => now(),
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('[Publisher] calendar sync failed', ['error' => $e->getMessage(), 'post' => $postId]);
        }
    }

    private function notify(int $wsId, ?int $userId, string $type, string $title, string $body, string $severity, array $data): void
    {
        try {
            DB::table('notifications')->insert([
                'workspace_id' => $wsId, 'user_id' => $userId, 'channel' => 'app',
                'type' => $type, 'category' => 'publisher', 'title' => $title,
                'body' => $body, 'severity' => $severity, 'data_json' => json_encode($data),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[Publisher] notification failed', ['error' => $e->getMessage()]);
        }
    }

    /** Structured audit. Never contains tokens or credentials. */
    private function audit(string $event, int $wsId, int $postId, array $meta): void
    {
        Log::info('[Publisher] ' . $event, ['workspace_id' => $wsId, 'post_id' => $postId] + $meta);
    }

    private function fail(string $reason, ?string $corr): array
    {
        return ['ok' => false, 'error' => $reason, 'failure_class' => self::classifyFailure($reason),
                'credits_charged' => 0, 'correlation_id' => $corr];
    }

    public static function reportCreditModel(): array
    {
        return [
            'caption_generated' => self::CREDIT_CAPTION_GENERATED,
            'caption_fallback'  => self::CREDIT_CAPTION_FALLBACK,
            'publish'           => self::CREDIT_PUBLISH,
            'blocked_request'   => 0, 'invalid_context' => 0, 'duplicate_replay' => 0,
            'retry'             => 0, 'provider_failure' => 0,
            'basis' => 'Unchanged from W5: PostPublishCoordinator already declared credit_cost=1 for '
                     . 'caption generation; ToolCostCalculatorService lists social=0 for publishing. '
                     . 'Per-platform variations do NOT multiply the caption charge.',
        ];
    }
}
