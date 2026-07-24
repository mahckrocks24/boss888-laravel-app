<?php

namespace App\Core\Distribution;

use App\Connectors\DryRunSocialConnector;
use App\Core\Billing\CreditService;
use App\Core\LaunchScope\ArticleShareToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * W5 (2026-07-22) — THE OWNER of the retained article-distribution workflow.
 *
 * Before W5 this workflow had no owner at all. PostPublishCoordinator queued a
 * task with assigned_agents=[] (W3 correctly stripped marcus/zoe) and a payload
 * carrying article_id + platform but NO content and NO url; the task dispatched
 * to SocialService::createPost(), which wrote `content = $data['content'] ?? ''`
 * — an empty draft with no link. Nothing read mode='article_share'; it was only
 * ever a permission marker. This class is the retained service that marker was
 * always supposed to name.
 *
 * It is NOT an agent. It is not Marcus, Zoe, Elena or any social specialist —
 * those are removed. It is infrastructure owned by the blog/article system.
 *
 * THE CONTRACT
 * ------------
 * Every share is bound to immutable distribution context: workspace, published
 * article, canonical URL, connected account, platform, idempotency key. That
 * context is signed into an ArticleShareToken which LaunchScopePolicy verifies
 * at BOTH dispatch and execution. The caption may be edited; the context may
 * not. A caption that no longer carries the canonical URL is rejected. Together
 * those rules mean article_share cannot be bent into arbitrary social posting.
 *
 * TERMINATION
 * -----------
 * The final step is DryRunSocialConnector: it builds and validates the exact
 * provider request and returns it without transmitting. Real transmission does
 * not exist in this product (see that class). Nothing in this service opens a
 * socket to a provider.
 */
class ArticleDistributionService
{
    public const ENGINE = 'social';
    public const ACTION = 'social_create_post';

    /** Credit model — see reportCreditModel(). Generation is the only cost. */
    public const CREDIT_CAPTION_GENERATED = 1;
    public const CREDIT_CAPTION_FALLBACK  = 0;
    public const CREDIT_PUBLISH           = 0;

    public function __construct(
        private CanonicalUrlResolver $urls,
        private ShareCaptionService $captions,
        private DryRunSocialConnector $dryRun,
        private CreditService $credits,
    ) {}

    // ═════════════════════════════════════════════════════════════════════
    //  1. REQUEST — validate, resolve, caption, persist. Idempotent.
    // ═════════════════════════════════════════════════════════════════════

    /**
     * @param array $input article_id, platform, social_account_id,
     *                     [scheduled_at], [timezone], [initiated_by], [reshare_nonce]
     * @return array{ok:bool, ...}
     */
    public function requestShare(int $wsId, array $input): array
    {
        $corr = (string) Str::uuid();

        $article = $this->validateArticle($wsId, (int) ($input['article_id'] ?? 0));
        if (!$article['ok']) return $this->fail($article['reason'], $corr);

        $platform = PlatformPolicy::normalise((string) ($input['platform'] ?? ''));
        $support  = PlatformPolicy::check($platform, PlatformPolicy::INTENT_ARTICLE_SHARE);
        if (!$support['ok']) return $this->fail($support['reason'], $corr);

        $account = $this->validateAccount($wsId, (int) ($input['social_account_id'] ?? 0), $platform);
        if (!$account['ok']) return $this->fail($account['reason'], $corr);

        $url = $this->urls->resolve($wsId, $article['article']);
        if (!$url['ok']) return $this->fail($url['reason'], $corr);

        $schedule = $this->normaliseSchedule($input);
        if (!$schedule['ok']) return $this->fail($schedule['reason'], $corr);

        $idem = $this->idempotencyKey(
            $wsId, (int) $article['article']->id, (int) $account['account']->id,
            $platform, $schedule['slot'], (string) ($input['reshare_nonce'] ?? '')
        );

        // ── Duplicate suppression. A replay returns the ORIGINAL record and
        //    charges nothing. Sharing the same article again on purpose needs a
        //    distinct user-authorised reshare_nonce, which changes the key.
        $existing = DB::table('social_posts')->where('idempotency_key', $idem)->first();
        if ($existing) {
            Log::info('[ArticleShare] idempotent replay suppressed', [
                'ws' => $wsId, 'article' => $article['article']->id,
                'share' => $existing->id, 'correlation_id' => $corr,
            ]);
            return [
                'ok' => true, 'duplicate' => true, 'share_id' => (int) $existing->id,
                'status' => $existing->status, 'execution_status' => $existing->execution_status,
                'credits_charged' => 0, 'correlation_id' => $corr,
            ];
        }

        // ── Caption. Charged only when the model is actually invoked.
        $caption = $this->captions->build($article['article'], $platform, $url['url']);
        $cost    = $caption['source'] === 'generated'
            ? self::CREDIT_CAPTION_GENERATED
            : self::CREDIT_CAPTION_FALLBACK;

        $reservation = null;
        if ($cost > 0) {
            if (!$this->credits->hasBalance($wsId, $cost)) {
                return $this->fail('INSUFFICIENT_CREDITS', $corr);
            }
            $reservation = $this->credits->reserve($wsId, $cost, 'article_share_caption');
        }

        try {
            $shareId = DB::table('social_posts')->insertGetId([
                'workspace_id'       => $wsId,
                'source_type'        => 'article',
                'article_id'         => (int) $article['article']->id,
                'canonical_url'      => $url['url'],
                'idempotency_key'    => $idem,
                'social_account_id'  => (int) $account['account']->id,
                'platform'           => $platform,
                'content'            => $caption['content'],
                'media_json'         => json_encode([]),
                'hashtags_json'      => json_encode($caption['hashtags'] ?? []),
                'status'             => 'draft',
                'approval_state'     => 'pending',
                'execution_status'   => $schedule['mode'] === 'scheduled' ? 'awaiting_approval' : 'awaiting_approval',
                'scheduled_at'       => $schedule['at'],
                'correlation_id'     => $corr,
                'created_at'         => now(), 'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            if ($reservation) $this->credits->release($wsId, $reservation);
            // A unique-key collision here means a concurrent request won the
            // race — that is a suppressed duplicate, not an error.
            $raced = DB::table('social_posts')->where('idempotency_key', $idem)->first();
            if ($raced) {
                return ['ok' => true, 'duplicate' => true, 'share_id' => (int) $raced->id,
                        'credits_charged' => 0, 'correlation_id' => $corr];
            }
            Log::error('[ArticleShare] share insert failed', ['error' => $e->getMessage(), 'correlation_id' => $corr]);
            return $this->fail('SHARE_PERSIST_FAILED', $corr);
        }

        if ($reservation) $this->credits->commit($wsId, $reservation, $cost);

        $this->createApproval($wsId, $shareId, $article['article'], $platform, $input);
        $this->notify($wsId, $input['initiated_by'] ?? null, 'article_share.awaiting_approval',
            'Share ready for review',
            'A caption for "' . ($article['article']->title ?? 'your article') . '" is ready to review.',
            'info', ['share_id' => $shareId, 'correlation_id' => $corr]);

        $this->audit('requested', $wsId, $shareId, [
            'article_id' => $article['article']->id, 'platform' => $platform,
            'caption_source' => $caption['source'], 'caption_reason' => $caption['reason'],
            'canonical_url' => $url['url'], 'url_source' => $url['source'],
            'credits_charged' => $cost, 'correlation_id' => $corr,
        ]);

        return [
            'ok' => true, 'duplicate' => false, 'share_id' => (int) $shareId,
            'canonical_url' => $url['url'], 'url_source' => $url['source'],
            'platform' => $platform, 'content' => $caption['content'],
            'caption_source' => $caption['source'], 'caption_reason' => $caption['reason'],
            'approval_state' => 'pending', 'execution_status' => 'awaiting_approval',
            'scheduled_at' => $schedule['at'], 'mode' => $schedule['mode'],
            'idempotency_key' => $idem, 'credits_charged' => $cost, 'correlation_id' => $corr,
        ];
    }

    // ═════════════════════════════════════════════════════════════════════
    //  2. REVIEW — edit / approve / cancel
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Caption edits are allowed. Detaching the caption from its article is not:
     * the canonical URL must survive, and article_id/canonical_url are never
     * writable here. This is the rule that stops "edit" becoming "compose".
     */
    public function editCaption(int $wsId, int $shareId, string $caption, ?int $userId = null): array
    {
        $share = $this->loadShare($wsId, $shareId);
        if (!$share) return $this->fail('SHARE_NOT_FOUND', null);
        if (in_array($share->execution_status, ['dry_run_ok', 'published', 'cancelled'], true)) {
            return $this->fail('SHARE_ALREADY_FINALISED', $share->correlation_id);
        }

        $caption = trim($caption);
        if ($caption === '') return $this->fail('CAPTION_EMPTY', $share->correlation_id);

        if (!str_contains($caption, (string) $share->canonical_url)) {
            return $this->fail('CAPTION_MUST_RETAIN_CANONICAL_URL', $share->correlation_id);
        }
        $max = PlatformPolicy::maxLength($share->platform);
        if (strlen($caption) > $max) {
            return $this->fail('CAPTION_EXCEEDS_PLATFORM_LIMIT', $share->correlation_id);
        }

        DB::table('social_posts')->where('id', $shareId)->where('workspace_id', $wsId)->update([
            'content' => $caption, 'approval_state' => 'pending', 'updated_at' => now(),
        ]);

        $this->audit('caption_edited', $wsId, $shareId, [
            'by' => $userId, 'length' => strlen($caption), 'correlation_id' => $share->correlation_id,
        ]);
        return ['ok' => true, 'share_id' => $shareId, 'content' => $caption];
    }

    /** Double-approval is suppressed: an already-approved share is a no-op. */
    public function approve(int $wsId, int $shareId, ?int $userId = null): array
    {
        $share = $this->loadShare($wsId, $shareId);
        if (!$share) return $this->fail('SHARE_NOT_FOUND', null);

        if ($share->approval_state === 'approved') {
            $this->audit('approve_duplicate_suppressed', $wsId, $shareId, ['correlation_id' => $share->correlation_id]);
            return ['ok' => true, 'duplicate' => true, 'share_id' => $shareId, 'approval_state' => 'approved'];
        }

        $next = $share->scheduled_at ? 'scheduled' : 'approved';
        DB::table('social_posts')->where('id', $shareId)->where('workspace_id', $wsId)->update([
            'approval_state' => 'approved',
            'execution_status' => $next,
            'status' => $share->scheduled_at ? 'scheduled' : 'draft',
            'updated_at' => now(),
        ]);
        try {
            DB::table('approvals')->where('workspace_id', $wsId)
                ->where('engine', 'distribution')->where('batch_id', 'share:' . $shareId)
                ->update(['status' => 'approved', 'decision_by' => $userId,
                          'decided_at' => now(), 'updated_at' => now()]);
        } catch (\Throwable $e) {
            Log::warning('[ArticleShare] approval update failed', ['error' => $e->getMessage(), 'share' => $shareId]);
        }

        if ($share->scheduled_at) $this->upsertCalendarEvent($wsId, $share);

        $this->audit('approved', $wsId, $shareId, ['by' => $userId, 'correlation_id' => $share->correlation_id]);
        return ['ok' => true, 'duplicate' => false, 'share_id' => $shareId, 'approval_state' => 'approved',
                'execution_status' => $next];
    }

    public function cancel(int $wsId, int $shareId, ?int $userId = null): array
    {
        $share = $this->loadShare($wsId, $shareId);
        if (!$share) return $this->fail('SHARE_NOT_FOUND', null);
        DB::table('social_posts')->where('id', $shareId)->where('workspace_id', $wsId)
            ->update(['execution_status' => 'cancelled', 'status' => 'draft', 'updated_at' => now()]);
        DB::table('calendar_events')->where('workspace_id', $wsId)
            ->where('reference_type', 'article_share')->where('reference_id', $shareId)->delete();
        $this->audit('cancelled', $wsId, $shareId, ['by' => $userId, 'correlation_id' => $share->correlation_id]);
        return ['ok' => true, 'share_id' => $shareId, 'execution_status' => 'cancelled'];
    }

    // ═════════════════════════════════════════════════════════════════════
    //  3. EXECUTE — re-validate, mint token, build payload. No transmission.
    // ═════════════════════════════════════════════════════════════════════

    public function execute(int $wsId, int $shareId): array
    {
        $share = $this->loadShare($wsId, $shareId);
        if (!$share) return $this->fail('SHARE_NOT_FOUND', null);
        $corr = (string) $share->correlation_id;

        // Idempotency across worker retries / duplicate scheduler firing.
        if (in_array($share->execution_status, ['dry_run_ok', 'published'], true)) {
            $this->audit('execute_duplicate_suppressed', $wsId, $shareId, ['correlation_id' => $corr]);
            return ['ok' => true, 'duplicate' => true, 'share_id' => $shareId,
                    'execution_status' => $share->execution_status, 'credits_charged' => 0];
        }
        if ($share->execution_status === 'cancelled') {
            return $this->fail('SHARE_CANCELLED', $corr);
        }
        if ($share->approval_state !== 'approved') {
            return $this->fail('SHARE_NOT_APPROVED', $corr);
        }

        // ── Re-validate at execution time. State drifts between approval and
        //    execution: articles get unpublished, accounts get revoked.
        $article = $this->validateArticle($wsId, (int) $share->article_id);
        if (!$article['ok']) return $this->permanentFailure($wsId, $share, $article['reason']);

        $account = $this->validateAccount($wsId, (int) $share->social_account_id, $share->platform);
        if (!$account['ok']) return $this->permanentFailure($wsId, $share, $account['reason']);

        $urlCheck = $this->urls->validatePublicUrl((string) $share->canonical_url);
        if (!$urlCheck['ok']) return $this->permanentFailure($wsId, $share, $urlCheck['reason']);

        if (!str_contains((string) $share->content, (string) $share->canonical_url)) {
            return $this->permanentFailure($wsId, $share, 'CAPTION_LOST_CANONICAL_URL');
        }

        // ── Mint the credential. This is the ONLY place it is produced, and it
        //    is produced only after every check above has passed.
        $token = ArticleShareToken::mint([
            'workspace_id'      => $wsId,
            'article_id'        => $share->article_id,
            'platform'          => $share->platform,
            'social_account_id' => $share->social_account_id,
            'canonical_url'     => $share->canonical_url,
            'idempotency_key'   => $share->idempotency_key,
        ]);

        // ── Kernel boundary. The same policy the route guard reads.
        $params = [
            'workspace_id' => $wsId, 'article_id' => $share->article_id,
            'platform' => $share->platform, 'social_account_id' => $share->social_account_id,
            'canonical_url' => $share->canonical_url, 'idempotency_key' => $share->idempotency_key,
            'distribution_token' => $token, 'mode' => 'article_share',
        ];
        $denied = \App\Core\LaunchScope\LaunchScopePolicy::deniedReason(self::ENGINE, self::ACTION, $params, []);
        if ($denied !== null) {
            return $this->permanentFailure($wsId, $share, $denied);
        }

        DB::table('social_posts')->where('id', $shareId)->update([
            'execution_status' => 'executing',
            'attempt_count' => DB::raw('attempt_count + 1'),
            'updated_at' => now(),
        ]);

        // ── Terminal step. Builds the real request; sends nothing.
        $result = $this->dryRun->buildPayload([
            'platform' => $share->platform, 'content' => $share->content,
            'canonical_url' => $share->canonical_url, 'article_id' => $share->article_id,
            'idempotency_key' => $share->idempotency_key,
            'social_account_id' => $share->social_account_id,
        ], $account['account']);

        if (!$result['ok']) {
            return $this->permanentFailure($wsId, $share, $result['reason'] ?? 'PAYLOAD_INVALID', $result['violations']);
        }

        DB::table('social_posts')->where('id', $shareId)->update([
            'execution_status'      => 'dry_run_ok',
            'status'                => 'draft',   // NOT 'published' — nothing was sent.
            'failure_class'         => null,
            'provider_payload_json' => json_encode([
                'endpoint' => $result['endpoint'], 'method' => $result['method'],
                'payload'  => $result['payload'], 'transmitted' => false,
                'built_at' => now()->toIso8601String(),
            ]),
            'updated_at' => now(),
        ]);

        $this->audit('dry_run_ok', $wsId, $shareId, [
            'endpoint' => $result['endpoint'], 'bytes' => strlen(json_encode($result['payload'])),
            'transmitted' => false, 'credits_charged' => self::CREDIT_PUBLISH, 'correlation_id' => $corr,
        ]);
        $this->notify($wsId, null, 'article_share.validated', 'Share validated',
            'The share for this article was built and validated. Provider publishing is not enabled yet, so nothing was posted.',
            'success', ['share_id' => $shareId, 'correlation_id' => $corr]);

        return [
            'ok' => true, 'duplicate' => false, 'share_id' => $shareId,
            'execution_status' => 'dry_run_ok', 'transmitted' => false,
            'endpoint' => $result['endpoint'], 'payload' => $result['payload'],
            'credits_charged' => self::CREDIT_PUBLISH, 'correlation_id' => $corr,
        ];
    }

    // ═════════════════════════════════════════════════════════════════════
    //  4. VALIDATION
    // ═════════════════════════════════════════════════════════════════════

    /** @return array{ok:bool, article?:object, reason?:string} */
    public function validateArticle(int $wsId, int $articleId): array
    {
        if ($articleId <= 0) return ['ok' => false, 'reason' => 'MISSING_ARTICLE_ID'];

        $a = DB::table('articles')->where('id', $articleId)->first();
        if (!$a)                          return ['ok' => false, 'reason' => 'ARTICLE_NOT_FOUND'];
        if ((int) $a->workspace_id !== $wsId) return ['ok' => false, 'reason' => 'ARTICLE_CROSS_WORKSPACE'];
        if (!empty($a->deleted_at))       return ['ok' => false, 'reason' => 'ARTICLE_DELETED'];
        if (strtolower((string) $a->status) !== 'published') return ['ok' => false, 'reason' => 'ARTICLE_NOT_PUBLISHED'];

        $hasBody = trim(strip_tags((string) ($a->content ?? ''))) !== ''
                || trim(strip_tags((string) ($a->excerpt ?? ''))) !== '';
        if (!$hasBody)                    return ['ok' => false, 'reason' => 'ARTICLE_HAS_NO_CONTENT'];
        if (trim((string) ($a->title ?? '')) === '') return ['ok' => false, 'reason' => 'ARTICLE_HAS_NO_TITLE'];

        return ['ok' => true, 'article' => $a];
    }

    /** @return array{ok:bool, account?:object, reason?:string} */
    public function validateAccount(int $wsId, int $accountId, string $platform): array
    {
        if ($accountId <= 0) return ['ok' => false, 'reason' => 'MISSING_ACCOUNT_ID'];

        $acc = DB::table('social_accounts')->where('id', $accountId)->first();
        if (!$acc)                              return ['ok' => false, 'reason' => 'ACCOUNT_NOT_FOUND'];
        if ((int) $acc->workspace_id !== $wsId) return ['ok' => false, 'reason' => 'ACCOUNT_CROSS_WORKSPACE'];
        if (strtolower((string) $acc->status) !== 'connected') return ['ok' => false, 'reason' => 'ACCOUNT_NOT_CONNECTED'];
        if (PlatformPolicy::normalise((string) $acc->platform) !== PlatformPolicy::normalise($platform)) {
            return ['ok' => false, 'reason' => 'ACCOUNT_PLATFORM_MISMATCH'];
        }
        $creds = json_decode((string) ($acc->credentials_json ?? '{}'), true);
        if (!is_array($creds) || empty($creds)) return ['ok' => false, 'reason' => 'ACCOUNT_CREDENTIALS_MISSING'];

        return ['ok' => true, 'account' => $acc];
    }

    // ═════════════════════════════════════════════════════════════════════
    //  5. SUPPORT
    // ═════════════════════════════════════════════════════════════════════

    private function normaliseSchedule(array $input): array
    {
        $at = $input['scheduled_at'] ?? null;
        if (empty($at)) return ['ok' => true, 'mode' => 'immediate', 'at' => null, 'slot' => 'immediate'];

        $tz = (string) ($input['timezone'] ?? 'UTC');
        try {
            $dt = new \DateTimeImmutable((string) $at, new \DateTimeZone($tz));
        } catch (\Throwable) {
            return ['ok' => false, 'reason' => 'INVALID_SCHEDULE_OR_TIMEZONE'];
        }
        $utc = $dt->setTimezone(new \DateTimeZone('UTC'));
        if ($utc->getTimestamp() <= time()) return ['ok' => false, 'reason' => 'SCHEDULE_IN_THE_PAST'];

        return [
            'ok' => true, 'mode' => 'scheduled',
            'at'   => $utc->format('Y-m-d H:i:s'),
            'slot' => $utc->format('Y-m-d\TH:i'),   // minute precision = the idempotency slot
        ];
    }

    /**
     * Stable across replays, distinct per intentional re-share.
     * Documented: sharing the same article to the same account again later
     * REQUIRES a user-authorised reshare_nonce; without one the key collides and
     * the replay is suppressed with zero charge.
     */
    public function idempotencyKey(int $wsId, int $articleId, int $accountId, string $platform, string $slot, string $nonce = ''): string
    {
        return 'as_' . hash('sha256', implode('|', [
            $wsId, $articleId, $accountId, PlatformPolicy::normalise($platform), $slot, $nonce,
        ]));
    }

    private function loadShare(int $wsId, int $shareId): ?object
    {
        return DB::table('social_posts')
            ->where('id', $shareId)->where('workspace_id', $wsId)
            ->where('source_type', 'article')->whereNull('deleted_at')
            ->first();
    }

    /**
     * Transient vs permanent. Only transient failures are worth a retry; a
     * revoked account or an unpublished article will never succeed, so we stop
     * and tell the user instead of burning the queue.
     */
    public static function classifyFailure(string $reason): string
    {
        $transient = ['PROVIDER_TIMEOUT', 'PROVIDER_5XX', 'RATE_LIMITED', 'NETWORK_ERROR', 'SHARE_PERSIST_FAILED'];
        return in_array($reason, $transient, true) ? 'transient' : 'permanent';
    }

    private function permanentFailure(int $wsId, object $share, string $reason, array $violations = []): array
    {
        $class = self::classifyFailure($reason);
        DB::table('social_posts')->where('id', $share->id)->update([
            'execution_status' => $class === 'transient' ? 'retry_pending' : 'failed',
            'failure_class'    => $class . ':' . $reason,
            'updated_at'       => now(),
        ]);

        $this->audit('execution_failed', $wsId, (int) $share->id, [
            'reason' => $reason, 'class' => $class, 'violations' => $violations,
            'credits_charged' => 0, 'correlation_id' => $share->correlation_id,
        ]);

        if ($class === 'permanent') {
            $needsReconnect = str_contains($reason, 'ACCOUNT_') || str_contains($reason, 'CREDENTIALS');
            $this->notify($wsId, null, 'article_share.failed', 'Article share could not be completed',
                $needsReconnect
                    ? 'The connected account needs to be reconnected before this article can be shared.'
                    : 'This article share was stopped: ' . $reason . '. Nothing was posted.',
                'error', ['share_id' => $share->id, 'reason' => $reason,
                          'correlation_id' => $share->correlation_id]);
        }

        return ['ok' => false, 'error' => $reason, 'failure_class' => $class,
                'retryable' => $class === 'transient', 'share_id' => (int) $share->id,
                'credits_charged' => 0, 'correlation_id' => $share->correlation_id];
    }

    private function createApproval(int $wsId, int $shareId, object $article, string $platform, array $input): void
    {
        try {
            DB::table('approvals')->insert([
                'workspace_id' => $wsId,
                'requested_by' => $input['initiated_by'] ?? null,
                'requester_actor_type' => 'system',
                'engine' => 'distribution',
                'action' => 'article_share',
                'capability_key' => 'distribution.article_share',
                'data_json' => json_encode([
                    'share_id' => $shareId, 'article_id' => $article->id,
                    'article_title' => $article->title, 'platform' => $platform,
                ]),
                // NOT task_id: approvals.task_id is FK-constrained to `tasks`,
                // and a share is not a task. batch_id is free-form and carries
                // the link instead.
                'batch_id' => 'share:' . $shareId,
                'status' => 'pending',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[ArticleShare] approval row failed', ['error' => $e->getMessage(), 'share' => $shareId]);
        }
    }

    private function upsertCalendarEvent(int $wsId, object $share): void
    {
        try {
            DB::table('calendar_events')->updateOrInsert(
                ['workspace_id' => $wsId, 'reference_type' => 'article_share', 'reference_id' => $share->id],
                [
                    'title' => 'Article share · ' . $share->platform,
                    'description' => (string) $share->canonical_url,
                    'category' => 'content', 'engine' => 'distribution',
                    'starts_at' => $share->scheduled_at, 'all_day' => 0,
                    'created_at' => now(), 'updated_at' => now(),
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('[ArticleShare] calendar upsert failed', ['error' => $e->getMessage(), 'share' => $share->id]);
        }
    }

    private function notify(int $wsId, ?int $userId, string $type, string $title, string $body, string $severity, array $data): void
    {
        try {
            DB::table('notifications')->insert([
                'workspace_id' => $wsId, 'user_id' => $userId, 'channel' => 'app',
                'type' => $type, 'category' => 'distribution', 'title' => $title,
                'body' => $body, 'severity' => $severity,
                'data_json' => json_encode($data),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[ArticleShare] notification failed', ['error' => $e->getMessage()]);
        }
    }

    /** Structured audit. Never contains tokens, credentials or raw provider secrets. */
    private function audit(string $event, int $wsId, int $shareId, array $meta): void
    {
        Log::info('[ArticleShare] ' . $event, ['workspace_id' => $wsId, 'share_id' => $shareId] + $meta);
    }

    private function fail(string $reason, ?string $corr): array
    {
        return ['ok' => false, 'error' => $reason,
                'failure_class' => self::classifyFailure($reason),
                'credits_charged' => 0, 'correlation_id' => $corr];
    }

    /** Reports the credit model rather than inventing one. See W5 report. */
    public static function reportCreditModel(): array
    {
        return [
            'caption_generated' => self::CREDIT_CAPTION_GENERATED,
            'caption_fallback'  => self::CREDIT_CAPTION_FALLBACK,
            'publish'           => self::CREDIT_PUBLISH,
            'blocked_request'   => 0,
            'invalid_context'   => 0,
            'duplicate_replay'  => 0,
            'retry'             => 0,
            'provider_failure'  => 0,
            'basis' => 'PostPublishCoordinator already declared credit_cost=1 for the '
                     . 'caption-only article share; ToolCostCalculatorService lists social=0 for '
                     . 'publishing. No new price invented.',
        ];
    }
}
