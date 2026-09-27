<?php

namespace App\Engines\Social\Services;

use App\Connectors\SocialConnector;
use App\Connectors\DeepSeekConnector;
use App\Core\Intelligence\EngineIntelligenceService;
use App\Engines\Creative\Services\CreativeService;
use Illuminate\Support\Facades\DB;

class SocialService
{
    private const PLATFORMS = ['instagram', 'facebook', 'linkedin', 'tiktok', 'twitter', 'snapchat'];

    public function __construct(
        private SocialConnector           $connector,
        private DeepSeekConnector         $llm,
        private EngineIntelligenceService  $engineIntel,
        private CreativeService            $creative,
        private \App\Connectors\RuntimeClient $runtime,
    ) {}

    // ── Creative blueprint helper ────────────────────────────────────────────
    private function blueprint(int $wsId, string $type, array $context = []): array
    {
        try {
            $result = $this->creative->generateThroughBlueprint('social', $type, $wsId, $context);
            return $result['output'] ?? [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function blueprintContext(array $bp): string
    {
        // FIX 2026-04-13 (Phase 0.17b downstream): the chat_json blueprint refactor
        // means BlueprintService can now return richer JSON shapes — fields like
        // `avoid`, `hook`, `tone_instructions` may come back as arrays. Coerce to
        // comma-joined strings so string interpolation doesn't throw.
        $stringify = static function ($v): ?string {
            if ($v === null || $v === '') return null;
            if (is_string($v)) return $v;
            if (is_array($v)) {
                $flat = array_filter(array_map(
                    fn($x) => is_scalar($x) ? (string) $x : null,
                    $v
                ), fn($x) => $x !== null && $x !== '');
                return empty($flat) ? null : implode(', ', $flat);
            }
            return is_scalar($v) ? (string) $v : null;
        };

        $brand   = $stringify($bp['brand_context'] ?? null);
        $tone    = $stringify($bp['tone_instructions'] ?? null);
        $hook    = $stringify($bp['hook'] ?? null);
        $engage  = $stringify($bp['engagement_prompt'] ?? null);
        $avoid   = $stringify($bp['avoid'] ?? null);

        $parts = array_filter([
            $brand,
            $tone   !== null ? "Tone: {$tone}"            : null,
            $hook   !== null ? "Hook strategy: {$hook}"   : null,
            $engage !== null ? "End with: {$engage}"      : null,
            $avoid  !== null ? "Avoid: {$avoid}"          : null,
        ]);
        return empty($parts) ? '' : implode(' | ', $parts);
    }

    // ═══════════════════════════════════════════════════════
    // POSTS
    // ═══════════════════════════════════════════════════════

    /** F-SOC-F6: the platform the owner named, normalised to PLATFORMS ('x' → twitter); null when none is determinable. */
    public static function resolvePlatform(array $data): ?string
    {
        $explicit = strtolower(trim((string) ($data['platform'] ?? $data['channel'] ?? $data['network'] ?? '')));
        $map = ['x' => 'twitter', 'x.com' => 'twitter', 'fb' => 'facebook', 'ig' => 'instagram', 'insta' => 'instagram', 'li' => 'linkedin'];
        $explicit = $map[$explicit] ?? $explicit;
        if ($explicit !== '' && in_array($explicit, self::PLATFORMS, true)) return $explicit;
        $text = strtolower(implode(' ', array_filter([(string) ($data['title'] ?? ''), (string) ($data['user_request'] ?? ''), (string) ($data['topic'] ?? ''), (string) ($data['description'] ?? ''), (string) ($data['request'] ?? '')])));
        foreach (['facebook' => '/\bfacebook\b|\bfb\b/', 'instagram' => '/\binstagram\b|\binsta\b|\big\b/', 'linkedin' => '/\blinkedin\b/', 'tiktok' => '/\btiktok\b/', 'twitter' => '/\btwitter\b|\bx post\b|\bon x\b|\btweet/', 'snapchat' => '/\bsnapchat\b/'] as $p => $rx) {
            if (preg_match($rx, $text)) return $p;
        }
        return null;
    }

    /** POST-MEDIA-1: 'video' for a video file link, else 'image'. */
    public static function mediaKind(string $url): string
    {
        return preg_match('#\.(mp4|mov|m4v|webm)(\?|$)#i', $url) ? 'video' : 'image';
    }

    /**
     * POST-MEDIA-1: the newest image or video Sarah attached in this workspace's chat within the last hour, if it is a
     * file this platform hosts. Returns ['type','url'] or null. Only Sarah's own replies count, never an upload.
     */
    public static function recentChatMedia(int $wsId, int $minutes = 60): ?array
    {
        try {
            // POST-MEDIA-1b (2026-09-26): an image is carried ONCE — only if Sarah made it after the last post drafted in this
            // workspace. The "better one" draft (post 298) otherwise re-used the earlier nutrition banner.
            $since = now()->subMinutes($minutes);
            $lastPost = DB::table('social_posts')->where('workspace_id', $wsId)->max('created_at');
            if ($lastPost && $lastPost > $since) { $since = \Illuminate\Support\Carbon::parse($lastPost); }
            $rows = DB::table('agent_messages')->where('workspace_id', $wsId)->where('agent_slug', 'sarah')->where('role', 'agent')
                ->where('created_at', '>', $since)->where('metadata_json', 'like', '%attachments%')
                ->orderByDesc('id')->limit(10)->pluck('metadata_json');
            $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'staging.levelupgrowth.io';
            foreach ($rows as $j) {
                $meta = json_decode((string) $j, true) ?: [];
                foreach ((array) ($meta['attachments'] ?? []) as $a) {
                    $url = (string) ($a['url'] ?? ''); $kind = (string) ($a['kind'] ?? '');
                    if (! in_array($kind, ['image', 'video'], true) || ! preg_match('#^https://#', $url) || ! str_contains($url, '/storage/')) continue;
                    $h = parse_url($url, PHP_URL_HOST);
                    if ($h !== $host && ! str_ends_with((string) $h, 'levelupgrowth.io')) continue;
                    return ['type' => $kind === 'video' ? 'video' : self::mediaKind($url), 'url' => $url];
                }
            }
        } catch (\Throwable $e) { /* no media carried; the post still saves */ }
        return null;
    }

    public function createPost(int $wsId, array $data): array
    {
        // F-SOC-F6: content aliases; platform inferred from the request; copy composed when only the request is present.
        $content = trim((string) ($data['content'] ?? $data['text'] ?? $data['caption'] ?? $data['message'] ?? $data['body'] ?? $data['copy'] ?? ''));
        $subject = trim((string) ($data['topic'] ?? $data['user_request'] ?? $data['title'] ?? $data['description'] ?? ''));
        $platform = self::resolvePlatform($data);
        if ($content === '' && $subject === '') {
            return ['success' => false, 'code' => 'INVALID_INPUT', 'no_charge' => true, 'error' => 'I need the post copy, or at least what the post should say, before I can draft it.'];
        }
        if ($platform === null) {
            return ['success' => false, 'code' => 'INVALID_INPUT', 'no_charge' => true, 'error' => 'Which platform should this post be for — Facebook, Instagram, LinkedIn, TikTok or X?'];
        }
        if ($content === '') {
            $gen = $this->aiGeneratePost($wsId, ['platform' => $platform, 'topic' => $subject, 'persist' => false] + (isset($data['tone']) ? ['tone' => $data['tone']] : []) + (! empty($data['business_id']) ? ['business_id' => (int) $data['business_id']] : [])); // BRAND-B0
            $content = trim((string) ($gen['content'] ?? ''));
            if ($content === '') {
                return ['success' => false, 'code' => 'GENERATION_FAILED', 'no_charge' => true, 'error' => 'I could not compose that post just now — nothing was saved. Try again in a moment or give me the copy.'];
            }
            if (empty($data['hashtags']) && !empty($gen['hashtags'])) $data['hashtags'] = $gen['hashtags'];
            $data['ai_generated'] = true;
        }
        $data['content'] = $content; $data['platform'] = $platform;
        // POST-MEDIA-1: a single image/video link is media too (Studio already speaks this shape) ...
        if (empty($data['media'])) {
            if (! empty($data['video_url']))      $data['media'] = [['type' => 'video', 'url' => (string) $data['video_url']]];
            elseif (! empty($data['image_url']))  $data['media'] = [['type' => 'image', 'url' => (string) $data['image_url']]];
            elseif (! empty($data['media_url']))  $data['media'] = [['type' => self::mediaKind((string) $data['media_url']), 'url' => (string) $data['media_url']]];
        }
        // ... and a post Sarah drafts in the chat, with no media and no article, carries the image or video she just
        // generated in that conversation. Her post tool has no media field and her history drops attachments, so
        // without this the banner she made and promised to "tag" was never on the post. The preview card shows it.
        if (empty($data['media']) && empty($data['article_id']) && ($data['created_via'] ?? '') === 'sarah_chat') {
            $carried = self::recentChatMedia($wsId);
            if ($carried) { $data['media'] = [$carried]; }
        }
        // POST-IMAGE-1 (Owner 2026-09-26: "how come there is no image banner on your latest draft?"): when Sarah drafts a post
        // whose brief describes an image/banner and none exists, the image is made WITH the post — through the engine
        // executor, so plan gating, credits and the image intelligence (one-line text rule) apply exactly as for any image.
        if (empty($data['media']) && empty($data['article_id']) && in_array(($data['created_via'] ?? ''), ['sarah_chat', 'campaign'], true)) {   // CAMPAIGNS-1: campaign posts too
            $brief = trim(implode(' ', array_filter([(string) ($data['title'] ?? ''), (string) ($data['description'] ?? ''), (string) ($data['user_request'] ?? '')])));
            if ($brief !== '' && preg_match('/\b(banner|image|graphic|visual|photo|picture|artwork|poster)\b/i', $brief)
                && ! preg_match('/\b(no|without)\s+(an?\s+)?(image|photo|picture|visual|graphic|banner)\b/i', $brief)) {
                $ar = preg_match('/\b(1:1|4:3|3:4|16:9|9:16|4:5|3:2|2:3)\b/', $brief, $am) ? $am[1] : '1:1';
                try {
                    $res = app(\App\Core\EngineKernel\EngineExecutionService::class)->execute($wsId, 'creative', 'generate_image',
                        ['prompt' => mb_substr($brief, 0, 1800), 'aspect_ratio' => $ar, 'platform' => $platform, 'asset_type' => 'social_post', 'source' => 'social'] + (! empty($data['business_id']) ? ['business_id' => (int) $data['business_id']] : []), // BRAND-B0
                        ['source' => 'agent', 'agent_id' => 'sarah']);
                    $url = $res['data']['url'] ?? $res['url'] ?? ($res['data']['data']['url'] ?? null);
                    if (is_string($url) && preg_match('#^https://#', $url)) {
                        $data['media'] = [['type' => 'image', 'url' => $url]];
                    } else {
                        \Illuminate\Support\Facades\Log::info('[POST-IMAGE-1] no image for the draft', ['ws' => $wsId, 'res' => array_intersect_key((array) $res, array_flip(['success', 'code', 'error', 'message']))]);
                    }
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('[POST-IMAGE-1] image generation failed', ['ws' => $wsId, 'error' => $e->getMessage()]);
                }
            }
        }
        // PREVIEW-1 (2026-09-25): a share of an article carries the article's link, site and business — the preview shows
        // them and Facebook builds its card from the link. Sarah's drafts today carried none of the three.
        $__canonical = isset($data['canonical_url']) && is_string($data['canonical_url']) ? trim($data['canonical_url']) : '';
        if (! empty($data['article_id']) && (int) $data['article_id'] > 0) {
            try {
                $__a = DB::table('articles as a')->leftJoin('websites as w', 'w.id', '=', 'a.website_id')->where('a.id', (int) $data['article_id'])->where('a.workspace_id', $wsId)
                    ->first(['a.slug', 'a.website_id', 'w.custom_domain', 'w.subdomain', 'w.business_id']);
                if ($__a) {
                    if (empty($data['website_id']) && $__a->website_id) $data['website_id'] = (int) $__a->website_id;
                    if (empty($data['business_id']) && ! empty($__a->business_id)) $data['business_id'] = (int) $__a->business_id;
                    $__host = $__a->custom_domain ?: $__a->subdomain;
                    if ($__canonical === '' && $__host && $__a->slug) $__canonical = 'https://' . preg_replace('#^https?://#', '', rtrim((string) $__host, '/')) . '/blog/' . ltrim((string) $__a->slug, '/');
                }
            } catch (\Throwable $e) { /* the post still saves; the preview just has no link */ }
        }
        $id = DB::table('social_posts')->insertGetId([
            'canonical_url' => $__canonical !== '' ? $__canonical : null,   // PREVIEW-1
            'workspace_id' => $wsId,
            // SOCIAL-888 provenance: capture the website when the caller states one; else
            // NULL = workspace-level (no blind guessing). article/studio callers may pass it.
            'website_id' => $data['website_id'] ?? null,
            // SOCIAL-PROFILE-1: the business the post is about (Sarah's context), and the article it shares.
            'business_id' => isset($data['business_id']) && (int) $data['business_id'] > 0 ? (int) $data['business_id'] : null,
            'article_id' => isset($data['article_id']) && (int) $data['article_id'] > 0 ? (int) $data['article_id'] : null,
            'social_account_id' => $data['account_id'] ?? $data['social_account_id'] ?? null,
            'platform' => $data['platform'] ?? 'instagram',
            'content' => $data['content'] ?? '',
            'media_json' => json_encode($data['media'] ?? []),
            'hashtags_json' => json_encode($data['hashtags'] ?? []),
            'status' => 'draft',
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'task_id' => $data['task_id'] ?? null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->engineIntel->recordToolUsage('social', 'social_create_post');
        return ['post_id' => $id, 'status' => 'draft', 'platform' => $platform, 'content' => $content, 'ai_generated' => !empty($data['ai_generated'])];
    }

    public function getPost(int $wsId, int $id): ?object
    {
        return DB::table('social_posts')->where('workspace_id', $wsId)->where('id', $id)->first();
    }

    public function listPosts(int $wsId, array $filters = []): array
    {
        $q = DB::table('social_posts')->where('workspace_id', $wsId)->whereNull('deleted_at');
        if (!empty($filters['platform'])) $q->where('platform', $filters['platform']);
        if (!empty($filters['status'])) $q->where('status', $filters['status']);
        $total = $q->count();
        return ['posts' => $q->orderByDesc('created_at')->limit($filters['limit'] ?? 50)->get(), 'total' => $total];
    }

    public function updatePost(int $id, array $data, ?int $wsId = null): array
    {
        $update = array_intersect_key($data, array_flip(['content', 'platform']));
        if (isset($data['media'])) $update['media_json'] = json_encode($data['media']);
        if (isset($data['hashtags'])) $update['hashtags_json'] = json_encode($data['hashtags']);
        $update['updated_at'] = now();
        // F-SOC-C4: existence first — affected rows are 0 for a no-op update and must not read as "not found"
        if (!DB::table('social_posts')->where('id', $id)->when($wsId !== null, fn($q) => $q->where('workspace_id', $wsId))->exists()) throw new \RuntimeException('Post not found');
        $n = DB::table('social_posts')->where('id', $id)->when($wsId !== null, fn($q) => $q->where('workspace_id', $wsId))->update($update);
        return ['updated' => true, 'changed' => $n > 0];
    }

    public function schedulePost(int $postId, string $scheduledAt, ?int $wsId = null): void
    {
        // CERT SOC-P1-3 (2026-09-04): normalise any standard datetime (ISO-8601 with T/Z,
        // RFC, etc. — a JS frontend sends toISOString()) to MySQL format. Previously the raw
        // string was written straight to the column, so "2026-09-06T09:00:00Z" produced a raw
        // SQLSTATE[22007] error leaked to the client. Parse, require future, store UTC.
        try {
            $when = \Carbon\Carbon::parse($scheduledAt);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Invalid schedule time — provide a valid date and time.');
        }
        if ($when->isPast()) {
            throw new \RuntimeException('Schedule time must be in the future.');
        }
        $normalized = $when->utc()->format('Y-m-d H:i:s');
        $n = DB::table('social_posts')->where('id', $postId)->when($wsId !== null, fn($q) => $q->where('workspace_id', $wsId))->update([
            'status' => 'scheduled', 'scheduled_at' => $normalized, 'updated_at' => now(),
        ]);
        if ($wsId !== null && $n === 0) throw new \RuntimeException('Post not found');
    }

    public function publishPost(int $postId, ?int $wsId = null): array
    {
        $post = DB::table('social_posts')->where('id', $postId)->when($wsId !== null, fn($q) => $q->where('workspace_id', $wsId))->first();
        if (!$post) throw new \RuntimeException("Post not found");

        // SOCIAL-888 PUBLISH-HARDENING (2026-09-04): the Social-nav publish now runs through the
        // CANONICAL Graph publishers (App\Core\Publisher\*) using the stored Page token — the same
        // real connectors + honesty guard + truthful states as PublisherService::callProvider —
        // instead of the retired external SocialConnector. Transport is dry-run (MockTransport)
        // until config('publisher.live_transport') is enabled after Meta App Review, so nothing
        // reaches a platform yet and no row is ever marked 'published' on a non-live transport.

        // ── Idempotency: a row that already went out is never published twice.
        if (($post->execution_status ?? null) === 'published' || !empty($post->external_post_id)) {
            return ['published' => true, 'duplicate' => true, 'external_id' => $post->external_post_id];
        }

        // ── Execution lock (compare-and-swap): claim the row so concurrent/duplicate
        //    publish requests cannot both transmit.
        $claimed = DB::table('social_posts')->where('id', $postId)
            ->whereNotIn('status', ['publishing', 'published'])
            ->update([
                'status' => 'publishing', 'execution_status' => 'executing',
                'attempt_count' => DB::raw('COALESCE(attempt_count,0) + 1'), 'updated_at' => now(),
            ]);
        if ($claimed === 0) {
            return ['published' => false, 'in_progress' => true,
                    'message' => "A publish is already in progress for this post."];
        }

        $fail = function (string $execStatus, string $failClass, string $userMsg) use ($postId) {
            DB::table('social_posts')->where('id', $postId)->update([
                'status' => 'draft', 'execution_status' => $execStatus,
                'failure_class' => $failClass, 'updated_at' => now(),
            ]);
            throw new \RuntimeException($userMsg);
        };

        // ── Resolve the connection for this workspace + platform.
        // SOCIAL-PROFILE-1: the business the post is about picks the Page. Several Pages and no way to tell
        // → refuse and say so (Sarah asks), never "the newest one".
        $resolved = (new SocialAccountResolver())->resolve(
            (int) $post->workspace_id, (string) $post->platform,
            (int) ($post->business_id ?? 0) ?: null, (int) ($post->website_id ?? 0) ?: null, (int) ($post->article_id ?? 0) ?: null,
            (int) ($post->social_account_id ?? 0) ?: null
        );
        $account = $resolved['account'];
        if (!$account) {
            $fail('failed', 'permanent:' . ($resolved['reason'] ?: 'NOT_CONNECTED'),
                "The post wasn't published to {$post->platform} — " . ($resolved['message'] ?: "no {$post->platform} account is connected for this workspace yet. Connect it in Settings to publish for real."));
        }
        if (empty($post->social_account_id)) {
            DB::table('social_posts')->where('id', $postId)->update(['social_account_id' => $account->id, 'updated_at' => now()]);
        }

        $health = \App\Core\Publisher\ConnectionHealth::assess($account);
        if (!($health['ok'] ?? false)) {
            $fail('failed', 'permanent:' . ($health['state'] ?? 'CONNECTION_UNUSABLE'),
                $health['message'] ?? "The {$post->platform} connection needs attention before publishing.");
        }

        $creds = \App\Core\Publisher\ConnectionHealth::readCredentials($account);
        $conn  = [
            'page_id'      => $account->linked_page_id ?: $account->account_id,
            'ig_user_id'   => $account->account_id,
            'access_token' => $creds['page_access_token'] ?? $creds['access_token'] ?? '',
        ];
        $payload = [
            'content'         => $post->content,
            'media'           => json_decode($post->media_json ?? '[]', true) ?: [],
            'canonical_url'   => $post->canonical_url ?? '',
            'idempotency_key' => $post->idempotency_key,
            'correlation_id'  => $post->correlation_id ?? ('sp_' . $post->id),
        ];

        $transport = config('publisher.live_transport', false)
            ? new \App\Core\Publisher\HttpTransport()
            : new \App\Core\Publisher\MockTransport();
        $live = $transport->isLive();

        $connector = match (\App\Core\Distribution\PlatformPolicy::normalise((string) $post->platform)) {
            'facebook'  => new \App\Core\Publisher\FacebookPublisherConnector($transport),
            'instagram' => new \App\Core\Publisher\InstagramPublisherConnector($transport),
            default     => null,
        };
        if ($connector === null) {
            $fail('failed', 'permanent:NO_CONNECTOR', "Publishing to {$post->platform} isn't supported yet.");
        }

        $r = $connector->publish($payload, $conn);

        // ── SUCCESS. HONESTY GUARD: a success over a non-live transport reached only a mock —
        //    it is NEVER 'published' and NEVER stores a provider id.
        if ($r['success'] ?? false) {
            DB::table('social_posts')->where('id', $postId)->update([
                'execution_status'      => $live ? 'published' : 'dry_run_ok',
                'status'                => $live ? 'published' : 'draft',
                'external_post_id'      => $live ? ($r['provider_post_id'] ?? null) : null,
                'provider_status_class' => $r['status_class'] ?? null,
                'provider_error_code'   => null,
                'failure_class'         => null,
                'published_at'          => $live ? now() : null,
                'updated_at'            => now(),
            ]);
            if (!$live) {
                throw new \RuntimeException(
                    "Your {$post->platform} post passed every check, but real publishing isn't enabled yet "
                    . "(the platform connection is pending review). Nothing was posted.");
            }
            $this->engineIntel->recordToolUsage('social', 'social_publish_post', 0.9);
            return ['published' => true, 'external_id' => $r['provider_post_id']];
        }

        // ── UNCERTAIN: the provider may have created the post. Never blind-retry; reconcile.
        if (($r['status_class'] ?? '') === \App\Core\Publisher\MetaErrorMap::UNCERTAIN) {
            DB::table('social_posts')->where('id', $postId)->update([
                'execution_status'      => 'publishing_unknown',
                'provider_status_class' => \App\Core\Publisher\MetaErrorMap::UNCERTAIN,
                'provider_error_code'   => $r['error_code'] ?? null,
                'failure_class'         => 'uncertain:' . ($r['error_code'] ?? 'UNKNOWN'),
                'updated_at'            => now(),
            ]);
            throw new \RuntimeException(
                "We couldn't confirm whether your {$post->platform} post went out. We'll verify before any retry so nothing is posted twice.");
        }

        // ── FAILED / RETRYABLE.
        if (!empty($r['needs_reconnect'])) {
            \App\Core\Publisher\ConnectionHealth::mark((int) $account->id,
                \App\Core\Publisher\MetaErrorMap::isPermissionCode($r['error_code'] ?? null)
                    ? \App\Core\Publisher\ConnectionHealth::INSUFFICIENT      // SOCIAL-LIVE-1b: re-grant, not re-login
                    : \App\Core\Publisher\ConnectionHealth::REVOKED,
                $r['error_code'] ?? null);
        }
        DB::table('social_posts')->where('id', $postId)->update([
            'status'                => 'draft',
            'execution_status'      => ($r['retryable'] ?? false) ? 'retry_pending' : 'failed',
            'provider_status_class' => $r['status_class'] ?? null,
            'provider_error_code'   => $r['error_code'] ?? null,
            'failure_class'         => (($r['retryable'] ?? false) ? 'transient:' : 'permanent:') . ($r['error_code'] ?? 'UNKNOWN'),
            'updated_at'            => now(),
        ]);
        throw new \RuntimeException($r['message'] ?? "Publishing to {$post->platform} failed.");
    }

    public function deletePost(int $id, ?int $wsId = null): void
    {
        $n = DB::table('social_posts')->where('id', $id)->when($wsId !== null, fn($q) => $q->where('workspace_id', $wsId))->update(['deleted_at' => now()]);
        if ($wsId !== null && $n === 0) throw new \RuntimeException('Post not found');
    }

    // ═══════════════════════════════════════════════════════
    // AI GENERATION
    // ═══════════════════════════════════════════════════════

    /**
     * REFACTORED 2026-04-12 (Phase 2L SOC1 / doc 14): now routes through
     * RuntimeClient::aiRun('social_post', ...) instead of direct DeepSeekConnector.
     * The runtime has a built-in `social_post` task type with platform-aware
     * prompt building. Brand context is passed via the context dict.
     *
     * Hands vs brain pattern: runtime generates, Laravel persists.
     */
    /** The social copywriter framing (mirrors the runtime v2.37.10 'social_post' prompt). */
    public const SOCIAL_SYSTEM_PROMPT = 'You are a senior social media copywriter. Write ONE on-brand post for the given platform. '
        . 'Ground every choice in the supplied brand context (brand_name, brand_voice, colours, industry, audience) and any learned_patterns. '
        . 'Honour platform norms: instagram 138-150 characters sweet spot with emojis welcome, facebook 40-80 characters organic, '
        . 'linkedin 1300-3000 characters professional, twitter/x 280 characters hard limit, tiktok 100-300 characters. '
        . 'Never mention LevelUp, AI, or that this was generated. Return ONLY JSON: {"content": "...", "hashtags": ["#tag", ...], "best_time": "e.g. Tue 6pm"}.';

    public function aiGeneratePost(int $wsId, array $params): array
    {
        // /* phase1-brand-aware */ — resolve workspace brand kit; params override
        $kit = app(\App\Core\Brand\WorkspaceBrandKitResolver::class)
            ->resolveWithOverrides($wsId, $params);

        $platform = $params['platform'] ?? 'instagram';
        $topic    = $params['topic'] ?? '';
        // tone resolved from workspace brand; param can still override
        $tone     = $params['tone'] ?? $kit['tone'];

        // ── Creative blueprint (kept for industry context, but brand voice/colors
        // now come from WorkspaceBrandKitResolver — white-label enforced) ──
        $bp    = $this->blueprint($wsId, 'post', [
            'platform' => $platform,
            'goal'     => "Engaging {$platform} post about {$topic}",
        ]);
        $bpCtx = $this->blueprintContext($bp);
        // ───────────────────────────────────────────────────────────────────

        $context = array_filter([
            'platform'      => $platform,
            'tone'          => $tone,
            // Workspace-grounded brand voice (NEVER "Marcus — social media specialist")
            'brand_name'    => $kit['brand_name'],
            'brand_voice'   => $kit['voice'],
            'visual_style'  => $kit['visual_style'] ?? null, // BRAND-B0
            'brand_primary' => $kit['primary_color'],
            'brand_secondary' => $kit['secondary_color'],
            'industry'      => $kit['industry'],
            'audience'      => $kit['target_audience'],
            'brand_context' => $bpCtx ?: null,
            // Unlocks RuntimeClient::aiRun auto-enrichment: business_name, tracked
            // SEO keywords, prior article titles (avoid repeating Write's topics),
            // and the cross-agent workspace_knowledge block (shared brain).
            'workspace_id'  => $wsId,
            'business'      => !empty($params['context']) ? json_encode($params['context']) : null,
        ], fn($v) => $v !== null && $v !== '');

        // Experience888 retrieval — "gaining experience for better output in the
        // future": consult confidence-graded, workspace-scoped learnings so past
        // results guide new content. Degrades to a no-op when nothing is proven.
        try {
            $patterns = app(\App\Core\Experience888\ExperienceRetriever::class)
                ->relevantPatterns($wsId, trim("{$platform} {$topic} " . ($kit['industry'] ?? '') . " social post"), 4);
            $learned = [];
            foreach ($patterns as $p) {
                $learned[] = trim(rtrim((string) $p->statement, '.'))
                    . " (confidence {$p->confidence_level}, {$p->positive_count}/{$p->sample_size})";
            }
            if ($learned) { $context['learned_patterns'] = $learned; }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::debug('SocialService: experience retrieval skipped', ['error' => $e->getMessage()]);
        }

        $userPrompt = "Generate a {$platform} post about: {$topic}\n"
                    . "Tone: {$tone}";
        if (! empty($kit['brand_rules'])) {   // BRAND-B1: the owner's brand rules are hard rules for the copy too
            $userPrompt .= "\nBrand rules (always follow):\n- " . implode("\n- ", array_slice((array) $kit['brand_rules'], 0, 12));
        }
        if (!empty($context['learned_patterns'])) {
            // Fold learnings into the prompt directly so they reach the model even
            // if the runtime task ignores unknown context keys.
            $userPrompt .= "\n\nWhat has worked before for this brand (apply where relevant):\n- "
                        . implode("\n- ", $context['learned_patterns']);
        }

        // RISK-0099 (2026-08-29): the live runtime (v2.37.9) still refuses task 'social_post'
        // (out_of_launch_scope — W3 removal never propagated after DEC-0028). Its generic
        // chat_json task IS in scope, so generation runs through it with a social system prompt.
        // This works against the deployed runtime today; the runtime package v2.37.10 restores
        // 'social_post' for the agent/tool path as well.
        $result = $this->runtime->chatJson(self::SOCIAL_SYSTEM_PROMPT, $userPrompt, $context, 600);

        // Runtime returns parsed JSON (chatJson) or text — accept both
        $parsed = null;
        if ($result['success'] && !empty($result['parsed']) && is_array($result['parsed'])) {
            $parsed = $result['parsed'];
        } elseif ($result['success'] && !empty($result['text'])) {
            $maybe = json_decode($result['text'], true);
            if (is_array($maybe)) $parsed = $maybe;
        }

        if ($result['success'] && $parsed) {
            $copy = [
                'content'      => (string) ($parsed['content'] ?? ''),
                'hashtags'     => array_values(array_filter((array) ($parsed['hashtags'] ?? []), 'is_string')),
                'best_time'    => $parsed['best_time'] ?? null,
                'platform'     => $platform,
                'ai_generated' => true,
                'source'       => 'runtime',
            ];
            // RISK-0099 (2026-08-29): the composer drafts INTO the form (persist=false) — the
            // customer reviews before anything is saved. Sarah/agents keep the persisting default.
            if (array_key_exists('persist', $params) && filter_var($params['persist'], FILTER_VALIDATE_BOOL) === false) {
                return $copy + ['post_id' => null, 'status' => 'unsaved'];
            }
            $post = $this->createPost($wsId, ['platform' => $platform, 'content' => $copy['content'], 'hashtags' => $copy['hashtags']]);
            return array_merge($post, $copy);
        }

        // Persist whatever we got even if JSON parsing failed — better than evaporating
        if ($result['success'] && !empty($result['text'])) {
            $post = $this->createPost($wsId, [
                'platform' => $platform,
                'content'  => $result['text'],
                'hashtags' => [],
            ]);
            return array_merge($post, [
                'ai_generated' => true,
                'source'       => 'runtime',
                'note'         => 'JSON parse failed — stored raw text as content',
            ]);
        }

        return [
            'error' => $result['error'] ?? 'AI generation failed',
            'ai_generated' => false,
            'source' => 'runtime',
        ];
    }

    /**
     * REFACTORED 2026-04-12 (Phase 2L SOC2 / doc 14): now routes through
     * RuntimeClient::aiRun('social_post', ...) with a hashtag-only prompt.
     * The runtime's social_post task is the closest match — it discards the
     * "post body" generation in favor of pure hashtag output. When chat_json
     * task type ships (Phase 0.17), this can be migrated to that.
     *
     * Also: now optionally PERSISTS hashtags to social_posts.hashtags_json
     * if a post_id is supplied (closes the no-persistence half of HIGH severity).
     */
    public function generateHashtags(int $wsId, array $params): array
    {
        $content  = $params['content'] ?? $params['topic'] ?? '';
        $platform = $params['platform'] ?? 'instagram';
        $postId   = $params['post_id'] ?? null;

        $context = [
            'task'     => 'hashtag_generation_only',
            'platform' => $platform,
            'count'    => 20,
            'mix'      => 'popular (1M+), medium (100K-1M), niche (<100K)',
        ];

        $userPrompt = "Generate 20 relevant hashtags for a {$platform} post about: {$content}\n"
                    . "Mix: popular (1M+ posts), medium (100K-1M), and niche (<100K).\n"
                    . "Output ONLY a JSON array of hashtag strings, nothing else. Example: [\"#example1\", \"#example2\"]";

        $result = $this->runtime->chatJson(
            'You are a social media strategist. Return ONLY a JSON object of the shape {"hashtags": ["#tag", ...]} — no prose.',
            $userPrompt, $context, 300
        );

        $hashtags = [];
        if ($result['success'] && !empty($result['parsed']) && is_array($result['parsed'])) {
            $list = $result['parsed']['hashtags'] ?? (array_is_list($result['parsed']) ? $result['parsed'] : []);
            $hashtags = array_values(array_filter((array) $list, 'is_string'));
        } elseif ($result['success'] && !empty($result['text'])) {
            // Try parsing as JSON array
            $maybe = json_decode($result['text'], true);
            if (is_array($maybe)) {
                $hashtags = array_filter($maybe, 'is_string');
            } else {
                // Fallback: extract any #word patterns from the text
                preg_match_all('/#\w+/', $result['text'], $m);
                $hashtags = $m[0] ?? [];
            }
        }

        // PERSISTENCE: if post_id supplied, fold into the post's hashtags_json
        if ($postId && !empty($hashtags)) {
            DB::table('social_posts')->where('id', $postId)->update([
                'hashtags_json' => json_encode($hashtags),
                'updated_at'    => now(),
            ]);
        }

        return [
            'hashtags'  => array_values($hashtags),
            'generated' => $result['success'] ?? false,
            'post_id'   => $postId,
            'persisted' => (bool) ($postId && $hashtags),
            'source'    => 'runtime',
        ];
    }

    // ═══════════════════════════════════════════════════════
    // ACCOUNTS
    // ═══════════════════════════════════════════════════════

    public function addAccount(int $wsId, array $data): int
    {
        // F-SOC-E3 (2026-09-06): a manually supplied credential is NOT a connection. Only the OAuth callbacks (verified at the
        // provider) may mark an account connected. Secrets go into the encrypted envelope, never the plaintext column.
        $id = DB::table('social_accounts')->insertGetId([
            'workspace_id' => $wsId,
            'platform' => $data['platform'] ?? 'instagram',
            'account_name' => $data['account_name'] ?? '',
            'account_id' => $data['account_id'] ?? null,
            'credentials_json' => json_encode(['_' => 'encrypted']),
            'status' => 'pending_verification',
            'health_state' => 'unverified',
            'health_detail' => 'Credentials were supplied manually and have not been verified. Connect the account through the provider login to publish.',
            'health_checked_at' => now(),
            'stats_json' => json_encode(['followers' => 0, 'posts' => 0, 'engagement_rate' => 0]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if (!empty($data['credentials']) && is_array($data['credentials'])) {
            try { \App\Core\Publisher\ConnectionHealth::storeCredentials($id, $data['credentials']); } catch (\Throwable $e) { \Illuminate\Support\Facades\Log::warning('[Social] addAccount: could not encrypt credentials', ['account' => $id, 'err' => $e->getMessage()]); }
        }
        return $id;
    }

    public function listAccounts(int $wsId): array
    {
        // SOCIAL-PAGEPICK-1: customer-safe columns only. The raw row carried credentials_json — with the
        // access token in it — to the browser. No credential column is ever selected here.
        // SOCIAL-DISCONNECT-1: a disconnected row stays (a reconnect revives it) but is not an account the
        // customer has; it never appears in the list.
        $rows = DB::table('social_accounts')->where('workspace_id', $wsId)
            ->where('status', '!=', 'disconnected')
            ->select(['id', 'workspace_id', 'platform', 'account_name', 'account_id', 'status', 'health_state',
                      'token_expires_at', 'health_checked_at', 'health_detail', 'provider_account_name',
                      'linked_page_id', 'stats_json', 'business_id', 'created_at', 'updated_at'])
            ->get()->toArray();
        // SOCIAL-PROFILE-1: name the business each account belongs to (read through the resolver).
        $names = [];
        foreach ((new \App\Core\Business\BusinessProfileResolver())->forWorkspace($wsId) as $b) { $names[(int) $b->id] = (string) $b->name; }
        foreach ($rows as $r) { $r->business_name = ! empty($r->business_id) ? ($names[(int) $r->business_id] ?? null) : null; }

        return $rows;
    }

    /** SOCIAL-PROFILE-1: which business a connected account belongs to (null = unassigned). */
    public function setAccountBusiness(int $wsId, int $accountId, ?int $businessId): array
    {
        $acc = DB::table('social_accounts')->where('id', $accountId)->where('workspace_id', $wsId)->first();
        if (! $acc) {
            return ['success' => false, 'code' => 'NOT_FOUND', 'error' => 'Account not found.'];
        }
        $resolver = new SocialAccountResolver();
        if ($businessId && ! $resolver->businessInWorkspace($wsId, $businessId)) {
            return ['success' => false, 'code' => 'NOT_FOUND', 'error' => 'Business not found.'];
        }
        DB::table('social_accounts')->where('id', $accountId)->update(['business_id' => $businessId ?: null, 'updated_at' => now()]);
        // An Instagram account linked to this Page follows it.
        if (strtolower((string) $acc->platform) === 'facebook' && ! empty($acc->account_id)) {
            DB::table('social_accounts')->where('workspace_id', $wsId)->where('platform', 'instagram')
                ->where('linked_page_id', (string) $acc->account_id)->update(['business_id' => $businessId ?: null, 'updated_at' => now()]);
        }

        return ['success' => true, 'account_id' => $accountId, 'business_id' => $businessId ?: null,
                'business_name' => $businessId ? $resolver->businessName($wsId, $businessId) : null];
    }

    public function disconnectAccount(int $wsId, int $accountId): bool
    {
        // Tenancy + correctness fix: the route calls disconnectAccount($wsId, $id),
        // but the old signature took only ($accountId) so PHP bound $accountId=$wsId
        // and discarded the real id -> wrong row, NO workspace scoping (cross-tenant
        // IDOR), and a void return that made the route always report disconnected:false.
        // Scope by BOTH workspace_id and id; return whether a row was actually updated.
        $n = DB::table('social_accounts')
            ->where('id', $accountId)
            ->where('workspace_id', $wsId)
            ->update(['status' => 'disconnected', 'updated_at' => now()]);
        try { app(\App\Core\Awareness\ConnectionFactsService::class)->recompute($wsId, true); } catch (\Throwable $e) {}   // SARAH-AWARE-1
        return $n > 0;
    }

    // ═══════════════════════════════════════════════════════
    // CALENDAR & ANALYTICS
    // ═══════════════════════════════════════════════════════

    public function getCalendarPosts(int $wsId, ?string $from, ?string $to): array
    {
        $from = $from ?: now()->startOfMonth()->toDateString();
        $to = $to ?: now()->endOfMonth()->toDateString();
        return DB::table('social_posts')->where('workspace_id', $wsId)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($from, $to) {
                $q->whereBetween('scheduled_at', [$from, $to])
                  ->orWhereBetween('published_at', [$from, $to]);
            })
            ->orderBy('scheduled_at')->get()->toArray();
    }

    /**
     * Queue a post for publishing. Called by Studio's publishToSocial bridge
     * after a design has been exported. Creates a social_posts row + ties it
     * to the design id; downstream publishPost() handles actual platform send.
     *
     * Phase 1 implementation: creates draft post row. Phase 2+ will add
     * platform routing + scheduled queue.
     */
    public function queuePost(int $wsId, array $data): array
    {
        // Studio -> Social bridge. Normalise the Studio payload so nothing is lost:
        // video-or-image media, schedule alias, and one post per chosen platform
        // (social_posts.platform is single-valued). Studio provenance -> source_type.
        $platforms = array_values(array_filter(
            (array) ($data['platforms'] ?? [$data['platform'] ?? 'instagram']),
            fn($p) => $p !== null && $p !== ''
        ));
        if (! $platforms) { $platforms = ['instagram']; }

        // Media: explicit array wins; else VIDEO takes precedence over image so a
        // Studio video is carried through (Owner: studio supplies images AND videos).
        $media = [];
        if (! empty($data['media']) && is_array($data['media'])) {
            $media = $data['media'];
        } elseif (! empty($data['video_url'])) {
            $media = [['type' => 'video', 'url' => (string) $data['video_url']]];
        } elseif (! empty($data['image_url'])) {
            $media = [['type' => 'image', 'url' => (string) $data['image_url']]];
        }

        $scheduledAt = $data['scheduled_at'] ?? $data['schedule_at'] ?? null;
        $caption     = $data['caption'] ?? $data['content'] ?? '';

        $postIds = [];
        foreach ($platforms as $platform) {
            $post = $this->createPost($wsId, [
                'platform'     => $platform,
                'content'      => $caption,
                'media'        => $media,
                'hashtags'     => $data['hashtags'] ?? [],
                'scheduled_at' => $scheduledAt,
                'account_id'   => $data['account_id'] ?? null,
            ]);
            $pid = $post['post_id'] ?? null;
            if ($pid) {
                DB::table('social_posts')->where('id', $pid)->where('workspace_id', $wsId)->update([
                    'source_type' => 'studio',
                    'status'      => $scheduledAt ? 'scheduled' : 'draft',
                    'updated_at'  => now(),
                ]);
                $postIds[] = $pid;
            }
        }

        return [
            'success'   => true,
            'post_ids'  => $postIds,
            'post_id'   => $postIds[0] ?? null,   // back-compat with single-id callers
            'platforms' => $platforms,
            'media'     => $media,
            'status'    => $scheduledAt ? 'scheduled' : 'queued',
            'message'   => count($postIds) . ' post(s) queued from Studio — visible in the calendar for review/scheduling.',
        ];
    }

    public function getDashboard(int $wsId): array
    {
        $posts = DB::table('social_posts')->where('workspace_id', $wsId)->whereNull('deleted_at');
        $accounts = DB::table('social_accounts')->where('workspace_id', $wsId);

        // Per-platform breakdown
        $platformStats = [];
        foreach (self::PLATFORMS as $p) {
            $count = (clone $posts)->where('platform', $p)->count();
            if ($count > 0) $platformStats[$p] = $count;
        }

        return [
            'total_posts' => (clone $posts)->count(),
            'published' => (clone $posts)->where('status', 'published')->count(),
            'scheduled' => (clone $posts)->where('status', 'scheduled')->count(),
            'drafts' => (clone $posts)->where('status', 'draft')->count(),
            'accounts_connected' => (clone $accounts)->where('status', 'connected')->count(),
            'platform_breakdown' => $platformStats,
            'recent' => (clone $posts)->orderByDesc('created_at')->limit(5)->get(),
            'upcoming' => (clone $posts)->where('status', 'scheduled')->where('scheduled_at', '>=', now())->orderBy('scheduled_at')->limit(5)->get(),
        ];
    }
}
