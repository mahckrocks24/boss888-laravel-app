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

    public function createPost(int $wsId, array $data): array
    {
        $id = DB::table('social_posts')->insertGetId([
            'workspace_id' => $wsId,
            // SOCIAL-888 provenance: capture the website when the caller states one; else
            // NULL = workspace-level (no blind guessing). article/studio callers may pass it.
            'website_id' => $data['website_id'] ?? null,
            'social_account_id' => $data['account_id'] ?? null,
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
        return ['post_id' => $id, 'status' => 'draft'];
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
        $n = DB::table('social_posts')->where('id', $id)->when($wsId !== null, fn($q) => $q->where('workspace_id', $wsId))->update($update);
        if ($wsId !== null && $n === 0) throw new \RuntimeException('Post not found');
        return ['updated' => true];
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
        $account = $post->social_account_id
            ? DB::table('social_accounts')->where('id', $post->social_account_id)->where('workspace_id', $post->workspace_id)->first()
            : DB::table('social_accounts')->where('workspace_id', $post->workspace_id)->where('platform', $post->platform)->orderByDesc('id')->first();
        if (!$account) {
            $fail('failed', 'permanent:NOT_CONNECTED',
                "The post wasn't published to {$post->platform} — no {$post->platform} account is connected for this workspace yet. Connect it in Settings to publish for real.");
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
                \App\Core\Publisher\ConnectionHealth::REVOKED, $r['error_code'] ?? null);
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
        return DB::table('social_accounts')->insertGetId([
            'workspace_id' => $wsId,
            'platform' => $data['platform'] ?? 'instagram',
            'account_name' => $data['account_name'] ?? '',
            'account_id' => $data['account_id'] ?? null,
            'credentials_json' => json_encode($data['credentials'] ?? []),
            'status' => 'connected',
            'stats_json' => json_encode(['followers' => 0, 'posts' => 0, 'engagement_rate' => 0]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function listAccounts(int $wsId): array
    {
        return DB::table('social_accounts')->where('workspace_id', $wsId)->get()->toArray();
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
