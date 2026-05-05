<?php

namespace App\Engines\Write\Services;

use App\Connectors\DeepSeekConnector;
use App\Connectors\RuntimeClient;
use App\Core\Intelligence\EngineIntelligenceService;
use App\Engines\Creative\Services\CreativeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * WriteService — Write engine.
 *
 * SCHEMA FIX 2026-04-12 (Phase 0.16 / doc 01):
 *   The original code wrote to columns that did not exist on the actual `articles`
 *   table: body (→ content), category, tags_json, seo_title, seo_description,
 *   target_keyword, author_agent (→ assigned_agent). And on `article_versions`:
 *   body (→ content), note (→ change_summary), word_count (does not exist on
 *   article_versions). Result: every INSERT failed silently and `articles` had
 *   0 rows since deploy.
 *
 *   This refactor:
 *     - Renames internal column writes to match the actual schema
 *       (content / assigned_agent / change_summary)
 *     - Folds seo_title / seo_description / target_keyword into the existing
 *       `seo_json` JSON column
 *     - Folds category / tags / audience / tone / brief metadata into the existing
 *       `brief_json` JSON column
 *     - Drops `word_count` from createVersion (not on article_versions schema)
 *     - Maintains BACKWARD COMPATIBILITY with the API contract by accepting both
 *       legacy keys ('body', 'seo_title', etc.) AND new keys ('content', etc.)
 *       on createArticle/updateArticle, and by exposing legacy aliases on
 *       getArticle responses (article->body, article->seo_title, etc.)
 *     - getArticle() now hydrates the JSON blobs into flat fields so existing
 *       calculateSeoScore() logic works unchanged.
 */
class WriteService
{
    public function __construct(
        private DeepSeekConnector         $llm,
        private EngineIntelligenceService  $engineIntel,
        private CreativeService            $creative,
        private RuntimeClient              $runtime,
    ) {}

    // ── Creative blueprint helper ────────────────────────────────────────────
    private function blueprint(int $wsId, string $type, array $context = []): array
    {
        try {
            $result = $this->creative->generateThroughBlueprint('write', $type, $wsId, $context);
            return $result['output'] ?? [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function blueprintContext(array $bp): string
    {
        // FIX 2026-04-13 (Phase 0.17b downstream): the chat_json blueprint refactor
        // means BlueprintService can now return richer JSON shapes — fields like
        // `avoid`, `tone_instructions`, `angle` may come back as arrays of strings
        // instead of single strings. Coerce them to comma-joined strings here so
        // the existing string interpolation doesn't throw "Array to string conversion".
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

        $brand = $stringify($bp['brand_context'] ?? null);
        $tone  = $stringify($bp['tone_instructions'] ?? null);
        $angle = $stringify($bp['angle'] ?? null);
        $avoid = $stringify($bp['avoid'] ?? null);
        $mem   = $stringify($bp['memory_context'] ?? null);

        $parts = array_filter([
            $brand,
            $tone   !== null ? "Tone: {$tone}"   : null,
            $angle  !== null ? "Angle: {$angle}" : null,
            $avoid  !== null ? "Avoid: {$avoid}" : null,
            $mem,
        ]);
        return empty($parts) ? '' : implode(' | ', $parts);
    }

    // ═══════════════════════════════════════════════════════
    // ARTICLES CRUD
    // ═══════════════════════════════════════════════════════

    public function createArticle(int $wsId, array $data): array
    {
        // Accept both legacy ('body') and new ('content') key names
        $content = $data['content'] ?? $data['body'] ?? '';

        // Build seo_json from flat legacy keys, OR accept a pre-built blob
        $seoJson = $data['seo_json'] ?? null;
        if ($seoJson === null) {
            $seoJson = array_filter([
                'title'       => $data['seo_title']       ?? null,
                'description' => $data['seo_description'] ?? null,
                'keyword'     => $data['target_keyword']  ?? null,
            ], fn($v) => $v !== null && $v !== '');
        }

        // Build brief_json (writing brief metadata: category, tags, audience, tone)
        $briefJson = $data['brief_json'] ?? null;
        if ($briefJson === null) {
            $briefJson = array_filter([
                'category' => $data['category'] ?? null,
                'tags'     => $data['tags']     ?? null,
                'audience' => $data['audience'] ?? null,
                'tone'     => $data['tone']     ?? null,
            ], fn($v) => $v !== null && $v !== '' && $v !== []);
        }

        // assigned_agent — accept both legacy ('author_agent') and new key
        $assignedAgent = $data['assigned_agent']
            ?? $data['author_agent']
            ?? 'priya';

        $userId = $data['user_id'] ?? null;

        $id = DB::table('articles')->insertGetId([
            'workspace_id'      => $wsId,
            'title'             => $data['title'] ?? 'Untitled',
            'slug'              => Str::slug($data['title'] ?? 'untitled') . '-' . Str::random(4),
            'content'           => $content,
            'excerpt'           => $data['excerpt'] ?? null,
            'type'              => $data['type'] ?? 'blog_post',
            'blog_category'     => $data['blog_category'] ?? null,
            'is_marketing_blog' => !empty($data['is_marketing_blog']),
            'featured_image_url'=> $data['featured_image_url'] ?? null,
            'status'            => 'draft',
            'seo_json'          => !empty($seoJson)   ? json_encode($seoJson)   : null,
            'brief_json'        => !empty($briefJson) ? json_encode($briefJson) : null,
            'word_count'        => str_word_count(strip_tags($content)),
            'readability_score' => $this->calculateReadability($content),
            'seo_score'         => null,
            'assigned_agent'    => $assignedAgent,
            'created_by'        => $userId,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        // Create initial version (changed_by accepts string — store user_id or agent slug)
        $this->createVersion($id, $content, 'Initial draft', $userId !== null ? (string) $userId : null);

        $this->engineIntel->recordToolUsage('write', 'create_article');

        return ['article_id' => $id, 'status' => 'draft'];
    }

    public function updateArticle(int $articleId, array $data): array
    {
        $article = DB::table('articles')->where('id', $articleId)->first();
        if (!$article) throw new \RuntimeException("Article not found: {$articleId}");

        $update = [];

        // Direct field updates (only schema-real columns)
        if (array_key_exists('title',   $data)) $update['title']   = $data['title'];
        if (array_key_exists('excerpt', $data)) $update['excerpt'] = $data['excerpt'];
        if (array_key_exists('type',    $data)) $update['type']    = $data['type'];
        if (array_key_exists('status',  $data)) $update['status']  = $data['status'];

        // Content alias — accept both 'content' and legacy 'body'
        $newContent = $data['content'] ?? $data['body'] ?? null;
        if ($newContent !== null) {
            $update['content']           = $newContent;
            $update['word_count']        = str_word_count(strip_tags($newContent));
            $update['readability_score'] = $this->calculateReadability($newContent);
            // Save version
            $changedBy = isset($data['user_id']) ? (string) $data['user_id'] : null;
            $this->createVersion(
                $articleId,
                $newContent,
                $data['version_note'] ?? 'Updated',
                $changedBy
            );
        }

        // SEO JSON merge — preserve existing keys when partial update
        $seoUpdate = [];
        if (array_key_exists('seo_title',       $data)) $seoUpdate['title']       = $data['seo_title'];
        if (array_key_exists('seo_description', $data)) $seoUpdate['description'] = $data['seo_description'];
        if (array_key_exists('target_keyword',  $data)) $seoUpdate['keyword']     = $data['target_keyword'];
        if (!empty($seoUpdate)) {
            $existingSeo = json_decode($article->seo_json ?? '{}', true) ?: [];
            $merged      = array_merge($existingSeo, $seoUpdate);
            // Strip null/empty keys after merge
            $merged      = array_filter($merged, fn($v) => $v !== null && $v !== '');
            $update['seo_json'] = !empty($merged) ? json_encode($merged) : null;
        }

        // Brief JSON merge — preserve existing keys when partial update
        $briefUpdate = [];
        if (array_key_exists('category', $data)) $briefUpdate['category'] = $data['category'];
        if (array_key_exists('tags',     $data)) $briefUpdate['tags']     = $data['tags'];
        if (array_key_exists('audience', $data)) $briefUpdate['audience'] = $data['audience'];
        if (array_key_exists('tone',     $data)) $briefUpdate['tone']     = $data['tone'];
        if (!empty($briefUpdate)) {
            $existingBrief = json_decode($article->brief_json ?? '{}', true) ?: [];
            $merged        = array_merge($existingBrief, $briefUpdate);
            $merged        = array_filter($merged, fn($v) => $v !== null && $v !== '' && $v !== []);
            $update['brief_json'] = !empty($merged) ? json_encode($merged) : null;
        }

        // assigned_agent — accept both names
        if (array_key_exists('assigned_agent', $data)) $update['assigned_agent'] = $data['assigned_agent'];
        elseif (array_key_exists('author_agent', $data)) $update['assigned_agent'] = $data['author_agent'];

        // Blog-specific fields
        if (array_key_exists('blog_category', $data))      $update['blog_category']      = $data['blog_category'];
        if (array_key_exists('is_marketing_blog', $data))  $update['is_marketing_blog']  = !empty($data['is_marketing_blog']);
        if (array_key_exists('featured_image_url', $data)) $update['featured_image_url'] = $data['featured_image_url'];
        if (array_key_exists('meta_title', $data))        $update['meta_title']        = $data['meta_title'];
        if (array_key_exists('meta_description', $data)) $update['meta_description'] = $data['meta_description'];
        if (array_key_exists('focus_keyword', $data))    $update['focus_keyword']    = $data['focus_keyword'];
        if (array_key_exists('read_time', $data))        $update['read_time']        = (int) $data['read_time'];
        if (array_key_exists('published_at', $data))     $update['published_at']     = $data['published_at'];

        // First-time publish — set published_at
        if (isset($update['status']) && $update['status'] === 'published' && $article->status !== 'published') {
            $update['published_at'] = now();
        }

        $update['updated_at'] = now();
        DB::table('articles')->where('id', $articleId)->update($update);

        return ['article_id' => $articleId, 'updated' => true];
    }

    public function getArticle(int $wsId, int $id): ?object
    {
        $article = DB::table('articles')->where('workspace_id', $wsId)->where('id', $id)->first();
        if (!$article) return null;

        // Hydrate JSON blobs into flat legacy aliases for backward compat
        $seo   = json_decode($article->seo_json   ?? '{}', true) ?: [];
        $brief = json_decode($article->brief_json ?? '{}', true) ?: [];

        $article->body            = $article->content;                  // legacy alias
        $article->seo_title       = $seo['title']       ?? null;
        $article->seo_description = $seo['description'] ?? null;
        $article->target_keyword  = $seo['keyword']     ?? null;
        $article->category        = $brief['category']  ?? null;
        $article->tags            = $brief['tags']      ?? [];
        $article->audience        = $brief['audience']  ?? null;
        $article->tone            = $brief['tone']      ?? null;
        $article->author_agent    = $article->assigned_agent;           // legacy alias

        $article->seo_score = $this->calculateSeoScore($article);
        return $article;
    }

    public function listArticles(int $wsId, array $filters = []): array
    {
        $q = DB::table('articles')->where('workspace_id', $wsId);
        if (!empty($filters['status'])) $q->where('status', $filters['status']);
        if (!empty($filters['type']))   $q->where('type',   $filters['type']);
        if (!empty($filters['search'])) $q->where('title', 'like', '%' . $filters['search'] . '%');
        // NOTE: 'category' filter is now stored inside brief_json (not a top-level column).
        // Use JSON_EXTRACT for category filtering. MySQL 5.7+ / 8.0 syntax.
        if (!empty($filters['category'])) {
            $q->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(brief_json, '$.category')) = ?", [$filters['category']]);
        }
        $total = $q->count();
        $articles = $q->orderByDesc('updated_at')->limit($filters['limit'] ?? 50)->get();
        return ['articles' => $articles, 'total' => $total];
    }

    public function deleteArticle(int $articleId): void
    {
        DB::table('articles')->where('id', $articleId)->delete();
    }

    // ═══════════════════════════════════════════════════════
    // VERSION HISTORY
    // ═══════════════════════════════════════════════════════

    public function getVersions(int $articleId): array
    {
        return DB::table('article_versions')->where('article_id', $articleId)
            ->orderByDesc('version_number')->get()->toArray();
    }

    public function restoreVersion(int $articleId, int $versionId): array
    {
        $version = DB::table('article_versions')->where('id', $versionId)->first();
        if (!$version) throw new \RuntimeException("Version not found");
        // Schema has $version->content (not body)
        return $this->updateArticle($articleId, [
            'content'      => $version->content,
            'version_note' => "Restored from v{$version->version_number}",
        ]);
    }

    /**
     * Insert a new version row.
     *
     * SCHEMA NOTE: article_versions has columns id, article_id, version_number,
     * content, change_summary, changed_by, created_at, updated_at.
     * `body`, `note`, `word_count` were the WRONG column names in the original
     * code — see Phase 0.16 schema fix.
     */
    private function createVersion(int $articleId, string $content, string $changeSummary, ?string $changedBy = null): void
    {
        $lastVersion = DB::table('article_versions')->where('article_id', $articleId)->max('version_number') ?? 0;
        DB::table('article_versions')->insert([
            'article_id'     => $articleId,
            'version_number' => $lastVersion + 1,
            'content'        => $content,
            'change_summary' => $changeSummary,
            'changed_by'     => $changedBy,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
    }

    // ═══════════════════════════════════════════════════════
    // AI GENERATION (through LLM)
    // ═══════════════════════════════════════════════════════

    /**
     * Generate a new article via LLM.
     *
     * REFACTORED 2026-04-12 (Phase 2C-W1 / doc 14): now routes through
     * RuntimeClient::writeDraft() → POST /internal/write/draft instead of
     * direct DeepSeekConnector. The runtime side has its own type-aware prompt
     * builder (buildWritePrompt) that's smarter than the inline prompt this
     * method used to build. Brand context (Priya voice, blueprint instructions)
     * is passed via the `context` dict and gets appended to the runtime's
     * system prompt.
     *
     * Hands vs brain pattern enforced: runtime generates, Laravel persists.
     * W1 LLM bypass site eliminated.
     */
    public function writeArticle(int $wsId, array $params): array
    {
        $topic   = $params['topic'] ?? $params['title'] ?? '';
        $type    = $params['type'] ?? 'blog_post';
        $tone    = $params['tone'] ?? 'professional';
        $length  = $params['length'] ?? 1500;
        $keyword = $params['target_keyword'] ?? $params['keyword'] ?? '';

        // ── Creative blueprint (still routes through CreativeService for R5) ─
        $bp  = $this->blueprint($wsId, 'article', [
            'topic'        => $topic,
            'goal'         => "Write a {$type} about {$topic}",
            'funnel_stage' => $params['funnel_stage'] ?? 'awareness',
            'audience'     => $params['audience'] ?? null,
        ]);
        $bpCtx = $this->blueprintContext($bp);
        // ───────────────────────────────────────────────────────────────────

        // Map our internal length (word count int) to the runtime's enum
        $lengthEnum = $length >= 700 ? 'long' : ($length >= 400 ? 'medium' : 'short');

        // Build the context dict — runtime appends these as "key: value" lines
        $context = array_filter([
            'topic'         => $topic,
            'audience'      => $params['audience'] ?? null,
            'funnel_stage'  => $params['funnel_stage'] ?? null,
            'brand_voice'   => 'Priya — a professional content writer',
            'brand_context' => $bpCtx ?: null,
        ], fn($v) => $v !== null && $v !== '');

        $result = $this->runtime->writeDraft([
            'title'        => $params['title'] ?? ucfirst($topic),
            'brief'        => $params['brief'] ?? "Write a {$type} about: {$topic}",
            'keywords'     => $keyword ? [$keyword] : [],
            'tone'         => $tone,
            'length'       => $lengthEnum,
            'content_type' => $type === 'blog_post' ? 'blog_article' : $type,
            'context'      => $context,
        ]);

        $content = $result['success']
            ? $result['content']
            : "<p>Article generation pending. Topic: {$topic}</p>";

        // Create the article (now persists correctly thanks to Phase 0.16 schema fix)
        $article = $this->createArticle($wsId, [
            'title'          => $params['title'] ?? ucfirst($topic),
            'content'        => $content,
            'type'           => $type,
            'target_keyword' => $keyword,
            'audience'       => $params['audience'] ?? null,
            'tone'           => $tone,
            'assigned_agent' => 'priya',
            'user_id'        => $params['user_id'] ?? null,
        ]);

        $this->engineIntel->recordToolUsage('write', 'write_article', $result['success'] ? 0.8 : 0.3);

        return array_merge($article, [
            'generated'   => $result['success'],
            'tokens_used' => $result['meta']['tokens_used'] ?? 0,
            'source'      => 'runtime',
        ]);
    }

    /**
     * Improve an existing draft via LLM.
     *
     * REFACTORED 2026-04-12 (Phase 2C-W2 / doc 14): now routes through
     * RuntimeClient::writeImprove() → POST /internal/write/improve instead of
     * direct DeepSeekConnector. W2 LLM bypass site eliminated.
     *
     * Per planner Q4 hands-vs-brain pattern: runtime improves, Laravel persists.
     */
    public function improveDraft(int $wsId, array $params): array
    {
        $articleId = $params['article_id'] ?? null;
        $content   = $params['content'] ?? $params['body'] ?? '';

        if ($articleId) {
            $article = DB::table('articles')->where('id', $articleId)->first();
            // Schema is `content`, not `body`
            $content = $article->content ?? $content;
        }

        if (empty($content)) throw new \InvalidArgumentException('No content to improve');

        // ── Creative blueprint (still routes through CreativeService for R5) ─
        $bp    = $this->blueprint($wsId, 'article', ['goal' => 'improve existing draft']);
        $bpCtx = $this->blueprintContext($bp);
        // ───────────────────────────────────────────────────────────────────

        $context = array_filter([
            'brand_context' => $bpCtx ?: null,
            'editor_voice'  => 'Priya — content editor focused on readability, engagement, SEO, and grammar',
        ], fn($v) => $v !== null && $v !== '');

        $result = $this->runtime->writeImprove($content, [
            'instruction' => $params['instructions']
                ?? 'Improve clarity, readability, engagement, SEO, and grammar. Keep the same structure.',
            'tone'        => $params['tone'] ?? '',
            'context'     => $context,
        ]);

        if ($result['success'] && $articleId) {
            $this->updateArticle($articleId, [
                'content'      => $result['content'],
                'version_note' => 'AI-improved draft',
                'user_id'      => $params['user_id'] ?? null,
            ]);
        }

        $this->engineIntel->recordToolUsage('write', 'improve_draft', $result['success'] ? 0.8 : 0.3);

        return [
            'improved'    => $result['success'],
            'content'     => $result['content'] ?? $content,
            'article_id'  => $articleId,
            'tokens_used' => $result['meta']['tokens_used'] ?? 0,
            'source'      => 'runtime',
        ];
    }

    /**
     * REFACTORED 2026-04-12 (Phase 2C-W3 / doc 14): now routes through
     * RuntimeClient::aiRun('seo_content_generation', ...).
     */
    public function generateOutline(int $wsId, array $params): array
    {
        $topic  = $params['topic'] ?? '';
        $type   = $params['type'] ?? 'blog_post';
        $length = $params['length'] ?? 1500;
        $bp     = $this->blueprint($wsId, 'article', ['topic' => $topic]);
        $bpCtx  = $this->blueprintContext($bp);

        $context = array_filter([
            'task'          => 'article_outline',
            'topic'         => $topic,
            'content_type'  => $type,
            'target_length' => "{$length} words",
            'brand_context' => $bpCtx ?: null,
        ], fn($v) => $v !== null && $v !== '');

        $userPrompt = "Generate a detailed article outline with H2/H3 headings and key points.\n"
                    . "Topic: {$topic}\n"
                    . "Type: {$type}\n"
                    . "Target length: {$length} words\n"
                    . "Format as a structured list.";

        $result = $this->runtime->aiRun('seo_content_generation', $userPrompt, $context, 800);

        return [
            'outline'   => $result['text'] ?? "1. Introduction\n2. Main Points\n3. Conclusion",
            'generated' => $result['success'] ?? false,
            'source'    => 'runtime',
        ];
    }

    /**
     * REFACTORED 2026-04-12 (Phase 2C-W4 / doc 14): now routes through
     * RuntimeClient::aiRun('seo_content_generation', ...).
     */
    public function generateHeadlines(int $wsId, array $params): array
    {
        $topic    = $params['topic'] ?? '';
        $audience = $params['audience'] ?? 'general';
        $bp       = $this->blueprint($wsId, 'article', ['topic' => $topic]);
        $bpCtx    = $this->blueprintContext($bp);

        $context = array_filter([
            'task'          => 'headline_generation',
            'topic'         => $topic,
            'audience'      => $audience,
            'count'         => 10,
            'mix'           => 'curiosity-driven, number-based, how-to, emotional',
            'brand_context' => $bpCtx ?: null,
        ], fn($v) => $v !== null && $v !== '');

        $userPrompt = "Generate 10 compelling headline options for: {$topic}\n"
                    . "Audience: {$audience}\n"
                    . "Mix curiosity-driven, number-based, how-to, and emotional headlines.\n"
                    . "Output one headline per line.";

        $result = $this->runtime->aiRun('seo_content_generation', $userPrompt, $context, 400);

        $text = $result['text'] ?? '';
        $headlines = $result['success'] && $text
            ? array_filter(array_map('trim', explode("\n", $text)))
            : ["How to {$topic}", "The Complete Guide to {$topic}"];

        return [
            'headlines' => array_values($headlines),
            'source'    => 'runtime',
        ];
    }

    /**
     * REFACTORED 2026-04-12 (Phase 2C-W5 / doc 14): now routes through
     * RuntimeClient::aiRun('seo_content_generation', ...). Persistence target
     * (`articles.seo_json`) is available — caller can pass `article_id` to
     * persist the meta directly.
     */
    public function generateMeta(int $wsId, array $params): array
    {
        $title     = $params['title'] ?? '';
        $content   = $params['content'] ?? $params['body'] ?? '';
        $keyword   = $params['keyword'] ?? $params['target_keyword'] ?? '';
        $articleId = $params['article_id'] ?? null;

        $context = array_filter([
            'task'         => 'meta_generation',
            'article_title'=> $title,
            'keyword'      => $keyword,
            'first_200'    => substr(strip_tags($content), 0, 200),
        ], fn($v) => $v !== null && $v !== '');

        $userPrompt = "Generate SEO meta title (50-60 chars) and meta description (150-160 chars). "
                    . "Include the keyword naturally. "
                    . "Output as JSON: {\"title\":\"...\",\"description\":\"...\"}";

        $result = $this->runtime->aiRun('seo_content_generation', $userPrompt, $context, 250);

        $meta = ['title' => $title, 'description' => substr(strip_tags($content), 0, 155)];
        if ($result['success'] && !empty($result['text'])) {
            $parsed = json_decode($result['text'], true);
            if (is_array($parsed) && isset($parsed['title'], $parsed['description'])) {
                $meta = $parsed;
            }
        }

        // PHASE 2C-W5 PERSISTENCE: if article_id is supplied, fold into seo_json
        if ($articleId) {
            $this->updateArticle($articleId, [
                'seo_title'       => $meta['title'],
                'seo_description' => $meta['description'],
                'target_keyword'  => $keyword,
                'user_id'         => $params['user_id'] ?? null,
            ]);
        }

        return [
            'meta'       => $meta,
            'article_id' => $articleId,
            'persisted'  => (bool) $articleId,
            'source'     => 'runtime',
        ];
    }

    // ═══════════════════════════════════════════════════════
    // DASHBOARD & ANALYTICS
    // ═══════════════════════════════════════════════════════

    public function getDashboard(int $wsId): array
    {
        $articles = DB::table('articles')->where('workspace_id', $wsId);
        return [
            'total_articles' => (clone $articles)->count(),
            'published' => (clone $articles)->where('status', 'published')->count(),
            'drafts' => (clone $articles)->where('status', 'draft')->count(),
            'avg_word_count' => (int) ((clone $articles)->avg('word_count') ?? 0),
            'avg_readability' => round((clone $articles)->avg('readability_score') ?? 0, 1),
            'total_words' => (int) ((clone $articles)->sum('word_count') ?? 0),
            'recent' => (clone $articles)->orderByDesc('updated_at')->limit(5)->get(),
        ];
    }

    // ═══════════════════════════════════════════════════════
    // PRIVATE HELPERS
    // ═══════════════════════════════════════════════════════

    private function calculateReadability(string $html): float
    {
        $text = strip_tags($html);
        $words = str_word_count($text);
        $sentences = max(1, preg_match_all('/[.!?]+/', $text));
        $syllables = max(1, (int) ($words * 1.5)); // Approximation
        // Flesch Reading Ease approximation
        $score = 206.835 - (1.015 * ($words / $sentences)) - (84.6 * ($syllables / $words));
        return round(max(0, min(100, $score)), 1);
    }

    /**
     * Calculate an SEO score (0-100). Reads from the hydrated article object,
     * which has the legacy flat aliases (body, seo_title, seo_description,
     * target_keyword) populated by getArticle().
     */
    private function calculateSeoScore(object $article): int
    {
        $score = 0;
        if (!empty($article->seo_title))       $score += 15;
        if (!empty($article->seo_description)) $score += 15;
        if (!empty($article->target_keyword))  $score += 10;
        if ($article->word_count >= 300)       $score += 10;
        if ($article->word_count >= 1000)      $score += 10;
        if ($article->word_count >= 1500)      $score += 5;
        if ($article->readability_score >= 50) $score += 10;
        if ($article->readability_score >= 70) $score += 5;
        if (!empty($article->excerpt))         $score += 5;

        // Body content checks — use 'content' (real column) with 'body' fallback for hydrated objects
        $bodyText = $article->content ?? $article->body ?? '';
        if (stripos($bodyText, '<h2') !== false) $score += 10;
        if ($article->target_keyword && stripos($bodyText, $article->target_keyword) !== false) $score += 5;

        return min(100, $score);
    }
}
