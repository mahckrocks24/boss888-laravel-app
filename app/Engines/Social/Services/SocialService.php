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

    public function updatePost(int $id, array $data): array
    {
        $update = array_intersect_key($data, array_flip(['content', 'platform']));
        if (isset($data['media'])) $update['media_json'] = json_encode($data['media']);
        if (isset($data['hashtags'])) $update['hashtags_json'] = json_encode($data['hashtags']);
        $update['updated_at'] = now();
        DB::table('social_posts')->where('id', $id)->update($update);
        return ['updated' => true];
    }

    public function schedulePost(int $postId, string $scheduledAt): void
    {
        DB::table('social_posts')->where('id', $postId)->update([
            'status' => 'scheduled', 'scheduled_at' => $scheduledAt, 'updated_at' => now(),
        ]);
    }

    public function publishPost(int $postId): array
    {
        $post = DB::table('social_posts')->where('id', $postId)->first();
        if (!$post) throw new \RuntimeException("Post not found");

        try {
            $result = $this->connector->publish($post->platform, [
                'content' => $post->content,
                'media' => json_decode($post->media_json ?? '[]', true),
                'account_id' => $post->social_account_id,
            ]);

            DB::table('social_posts')->where('id', $postId)->update([
                'status' => 'published', 'published_at' => now(),
                'external_post_id' => $result['external_id'] ?? null,
                'updated_at' => now(),
            ]);

            $this->engineIntel->recordToolUsage('social', 'social_publish_post', 0.9);
            return ['published' => true, 'external_id' => $result['external_id'] ?? null];
        } catch (\Throwable $e) {
            DB::table('social_posts')->where('id', $postId)->update(['status' => 'failed', 'updated_at' => now()]);
            return ['published' => false, 'error' => $e->getMessage()];
        }
    }

    public function deletePost(int $id): void
    {
        DB::table('social_posts')->where('id', $id)->update(['deleted_at' => now()]);
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
    public function aiGeneratePost(int $wsId, array $params): array
    {
        $platform = $params['platform'] ?? 'instagram';
        $topic    = $params['topic'] ?? '';
        $tone     = $params['tone'] ?? 'professional';

        // ── Creative blueprint (still routes through CreativeService for R5) ─
        $bp    = $this->blueprint($wsId, 'post', [
            'platform' => $platform,
            'goal'     => "Engaging {$platform} post about {$topic}",
        ]);
        $bpCtx = $this->blueprintContext($bp);
        // ───────────────────────────────────────────────────────────────────

        $context = array_filter([
            'platform'      => $platform,
            'tone'          => $tone,
            'brand_voice'   => 'Marcus — social media specialist',
            'brand_context' => $bpCtx ?: null,
            'business'      => !empty($params['context']) ? json_encode($params['context']) : null,
        ], fn($v) => $v !== null && $v !== '');

        $userPrompt = "Generate a {$platform} post about: {$topic}\n"
                    . "Tone: {$tone}";

        $result = $this->runtime->aiRun('social_post', $userPrompt, $context, 600);

        // Runtime returns text — try to parse as JSON if it looks like one
        $parsed = null;
        if ($result['success'] && !empty($result['text'])) {
            $maybe = json_decode($result['text'], true);
            if (is_array($maybe)) $parsed = $maybe;
        }

        if ($result['success'] && $parsed) {
            $post = $this->createPost($wsId, [
                'platform' => $platform,
                'content'  => $parsed['content'] ?? '',
                'hashtags' => $parsed['hashtags'] ?? [],
            ]);
            return array_merge($post, [
                'ai_generated' => true,
                'best_time'    => $parsed['best_time'] ?? null,
                'source'       => 'runtime',
            ]);
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

        $result = $this->runtime->aiRun('social_post', $userPrompt, $context, 300);

        $hashtags = [];
        if ($result['success'] && !empty($result['text'])) {
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

    public function disconnectAccount(int $accountId): void
    {
        DB::table('social_accounts')->where('id', $accountId)->update(['status' => 'disconnected', 'updated_at' => now()]);
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
