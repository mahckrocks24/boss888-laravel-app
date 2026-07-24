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
            // 2026-06-10 — honour a caller-supplied scheduled_at (single OR batch
            // article path). The Pipeline/Calendar tab reads articles.scheduled_at;
            // before this, batch fan-out queued everything with no calendar date.
            'scheduled_at'      => $data['scheduled_at'] ?? null,
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

        // 2026-05-12 Phase 0 — sync to SEO index (non-fatal on error)
        try {
            $article = DB::table('articles')->find($id);
            if ($article) {
                app(\App\Engines\SEO\Services\SeoService::class)
                    ->syncFromArticle($wsId, $article);
            }
        } catch (\Throwable $e) {
            \Log::warning('[SEO] Write createArticle sync failed: ' . $e->getMessage());
        }

        return ['article_id' => $id, 'status' => 'draft'];
    }

    public function updateArticle(int $articleId, array $data, ?int $wsId = null): array
    {
        $article = DB::table('articles')->where('id', $articleId)
            ->when($wsId !== null, fn($q) => $q->where('workspace_id', $wsId))
            ->first();
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

        // 2026-05-12 Phase 0 — sync to SEO index (non-fatal on error)
        try {
            $fresh = DB::table('articles')->find($articleId);
            if ($fresh) {
                app(\App\Engines\SEO\Services\SeoService::class)
                    ->syncFromArticle((int) $fresh->workspace_id, $fresh);
            }
        } catch (\Throwable $e) {
            \Log::warning('[SEO] Write updateArticle sync failed: ' . $e->getMessage());
        }

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

    public function deleteArticle(int $articleId, ?int $wsId = null): void
    {
        $deleted = DB::table('articles')->where('id', $articleId)
            ->when($wsId !== null, fn($q) => $q->where('workspace_id', $wsId))
            ->delete();
        if ($wsId !== null && $deleted === 0) throw new \RuntimeException("Article not found: {$articleId}");
    }

    // ═══════════════════════════════════════════════════════
    // VERSION HISTORY
    // ═══════════════════════════════════════════════════════

    public function getVersions(int $articleId, ?int $wsId = null): array
    {
        if ($wsId !== null && !DB::table('articles')->where('id', $articleId)->where('workspace_id', $wsId)->exists()) {
            return [];
        }
        return DB::table('article_versions')->where('article_id', $articleId)
            ->orderByDesc('version_number')->get()->toArray();
    }

    public function restoreVersion(int $articleId, int $versionId, ?int $wsId = null): array
    {
        if ($wsId !== null && !DB::table('articles')->where('id', $articleId)->where('workspace_id', $wsId)->exists()) {
            throw new \RuntimeException("Article not found: {$articleId}");
        }
        $version = DB::table('article_versions')->where('id', $versionId)->where('article_id', $articleId)->first();
        if (!$version) throw new \RuntimeException("Version not found");
        // Schema has $version->content (not body)
        return $this->updateArticle($articleId, [
            'content'      => $version->content,
            'version_note' => "Restored from v{$version->version_number}",
        ], $wsId);
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
        $length  = $params['length'] ?? 1100;  // Wave 35b: house standard 1000-1200 words
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
            // /* phase1-brand-aware */ — workspace voice (not Priya persona)
            'brand_voice'   => (app(\App\Core\Brand\WorkspaceBrandKitResolver::class)->resolve($wsId))['voice'],
            'brand_context' => $bpCtx ?: null,
        ], fn($v) => $v !== null && $v !== '');

        // Wave 65 — enforce target word count via explicit min/max/target
        // params + post-validate. Runtime's length enum alone gives no
        // guarantee (articles drift 800-1500 with 'long').
        $minWords = max(300, (int) ($params['min_words'] ?? ($length - 100)));
        $maxWords = max($minWords + 50, (int) ($params['max_words'] ?? ($length + 100)));
        $targetWords = $length;

        // 2026-05-23 FIX 43 — content rules (Sarah intelligence v1).
        // These are appended to the brief for every article path that
        // goes through WriteService::writeArticle (Sarah chain, SEO
        // Assistant batch, WP plugin direct, batch tasks). Two rules:
        //   1) Never name competitors. Generic — the LLM should write
        //      about products/services/categories without naming
        //      specific competing brands. Prevents accidental promotion
        //      and avoids competitor SEO juice.
        //   2) Year-currency. Earlier articles ran while runtime had
        //      stale year context and produced "tips for 2025" titles
        //      when today is 2026. Inject the current year and tell
        //      the LLM to never include prior years in evergreen
        //      content unless the user explicitly asked for a year
        //      retrospective.
        $currentYear = (int) date('Y');
        $contentRules = "\n\n══ Content rules (strict — do not break) ══\n"
            . "1. NEVER mention, name, or promote specific competitor companies, brands, agencies, "
            . "or service providers by name. Write about categories, approaches, and the workspace's "
            . "own brand only. If discussing 'options' or 'alternatives', describe them generically "
            . "(e.g., 'a national catering chain', 'a meal-delivery service') — never a real brand.\n"
            . "2. The current year is {$currentYear}. NEVER include a year prior to {$currentYear} in "
            . "the title, H1, H2, or meta unless the article is explicitly a year-in-review or "
            . "historical retrospective. 'Tips for " . ($currentYear - 1) . "' style headings are forbidden — "
            . "use 'Tips for {$currentYear}' or no year at all. Same rule applies to pricing data, "
            . "trend predictions, and statistics — anchor them to {$currentYear} or 'this year', "
            . "never an outdated year.";

        // 2026-06-11 — DUPLICATE-CONTENT GUARD (feeds the runtime's uniqueness rule).
        // Root cause of the chef-red doorway problem: 33 county pages generated as
        // generic 'blog_article' with only the county name varying → ~90% identical.
        // Fix: (a) route location/service-area pages to the runtime's differentiation-
        // structured 'location_page' template; (b) pass the titles + opening lines of
        // SIBLING articles so the model is forced to diverge from them.
        $titleForType = (string) ($params['title'] ?? $topic);
        $isLocationPage = (bool) preg_match('/\bcounty\b|\bservice area\b|\bnear me\b/i', $titleForType)
            || (bool) preg_match('/\bin\s+[A-Z][a-zA-Z]+(?:\s+[A-Z][a-zA-Z]+)*,\s*[A-Z]{2}\b/', $titleForType);
        $siblingPages = [];
        try {
            $kwSeed = $keyword ?: implode(' ', array_slice(array_filter(explode(' ',
                trim(preg_replace('/\b(in|near|around|the|a|an|services?|service|of|for|your|our)\b/i', ' ', strtolower($titleForType))))), 0, 3));
            if ($kwSeed !== '') {
                $sibs = DB::table('articles')->where('workspace_id', $wsId)
                    ->where('title', 'like', '%' . $kwSeed . '%')
                    ->orderByDesc('id')->limit(6)->get(['title', 'content']);
                foreach ($sibs as $s) {
                    if (($s->title ?? '') === ($params['title'] ?? '')) continue;
                    $open = trim(mb_substr(preg_replace('/\s+/', ' ', strip_tags((string) ($s->content ?? ''))), 0, 180));
                    $siblingPages[] = '• ' . $s->title . ($open !== '' ? ' — opens: "' . $open . '…"' : '');
                }
            }
        } catch (\Throwable $e) { /* non-fatal — differentiation context is best-effort */ }

        $draftParams = [
            'title'        => $params['title'] ?? ucfirst($topic),
            'brief'        => ($params['brief'] ?? "Write a {$type} about: {$topic}")
                            . ". Target length: {$targetWords} words (strict minimum {$minWords}, maximum {$maxWords}). "
                            . 'Use H2 subheadings to structure the article, write substantive paragraphs of 80-150 words each, and include a brief introduction and conclusion. Do not pad with filler.'
                            . $contentRules,
            'keywords'     => $keyword ? [$keyword] : [],
            'tone'         => $tone,
            'length'       => $lengthEnum,
            'min_words'    => $minWords,
            'max_words'    => $maxWords,
            'target_words' => $targetWords,
            'content_type' => $isLocationPage ? 'location_page' : ($type === 'blog_post' ? 'blog_article' : $type),
            'context'      => array_merge($context, array_filter([
                'current_year'   => (string) $currentYear,
                'content_rules'  => 'no_competitor_names,no_prior_year',
                'existing_pages' => !empty($siblingPages)
                    ? ('This workspace ALREADY has these similar pages — your output MUST be materially different from them (different opening, examples, section order, and local specifics), NOT a templated clone: ' . implode('  ', $siblingPages))
                    : null,
                'differentiate'  => $isLocationPage
                    ? 'This is a location/service-area page. Use genuinely local specifics for THIS exact area (real neighbourhoods, towns, landmarks, local context). Do NOT reuse boilerplate from other area pages with the place name swapped.'
                    : null,
            ])),
        ];
        $result = $this->runtime->writeDraft($draftParams);

        // 2026-05-22 FIX 5 — runtime failure was being silently swallowed.
        // The old code produced "<p>Article generation pending. Topic: ...</p>"
        // and persisted that as a real article (e.g. chef-site #52), then
        // returned success: true to the orchestrator. Downstream tasks
        // (generate_meta, link_suggestions, insert_link) ran against the
        // placeholder and the user saw a stub article they couldn't tell
        // had failed. Throwing here causes the task to be marked failed
        // and the article to never persist.
        if (empty($result['success']) || empty($result['content'])) {
            $errMsg = (string) ($result['error'] ?? $result['message'] ?? 'runtime returned no content');
            \Illuminate\Support\Facades\Log::warning('[writeArticle] runtime failed — throwing instead of writing placeholder', [
                'workspace_id' => $wsId,
                'topic'        => $topic,
                'error'        => $errMsg,
                'meta'         => $result['meta'] ?? null,
            ]);
            throw new \RuntimeException('write_article runtime failure: ' . $errMsg);
        }
        $content = $result['content'];

        // Wave 65 — if the draft came in short, retry ONCE with an explicit
        // expand brief. Hard cap at 1 retry to keep cost predictable.
        if ($result['success'] && !empty($content)) {
            $actualWords = str_word_count(strip_tags($content));
            if ($actualWords < $minWords) {
                \Illuminate\Support\Facades\Log::info('[writeArticle] short draft, retrying with expand brief', [
                    'workspace_id' => $wsId, 'topic' => $topic,
                    'actual_words' => $actualWords, 'min_required' => $minWords,
                ]);
                $expandParams = $draftParams;
                $expandParams['brief'] = "Expand the following article to between {$minWords} and {$maxWords} words "
                    . "(currently {$actualWords}). Add substantive depth — examples, mini-case studies, specific tactics, "
                    . "and a deeper FAQ-style section. Keep the same tone and structure. Existing draft:\n\n" . strip_tags($content);
                $retry = $this->runtime->writeDraft($expandParams);
                if (!empty($retry['success']) && !empty($retry['content'])) {
                    $retryWords = str_word_count(strip_tags($retry['content']));
                    if ($retryWords > $actualWords) {
                        $content = $retry['content'];
                        $result = $retry;
                    }
                }
            }
        }

        if ($content && !str_contains($content, '<h') && !str_contains($content, '<p>')) {
            $converter = new \League\CommonMark\CommonMarkConverter(['html_input' => 'strip']);
            $content = $converter->convert($content)->getContent();
        }

        // Create the article (now persists correctly thanks to Phase 0.16 schema fix)
        // Wave 62 — default is_marketing_blog=1 for blog_post type so the
        // public blog listing (BuilderRenderer.renderBlogList) actually
        // shows AI-generated posts.
        $article = $this->createArticle($wsId, [
            'title'             => $params['title'] ?? ucfirst($topic),
            'content'           => $content,
            'type'              => $type,
            'target_keyword'    => $keyword,
            'audience'          => $params['audience'] ?? null,
            'tone'              => $tone,
            'assigned_agent'    => 'priya',
            'is_marketing_blog' => $params['is_marketing_blog'] ?? ($type === 'blog_post' || $type === 'blog_article'),
            'user_id'           => $params['user_id'] ?? null,
            'scheduled_at'      => $params['scheduled_at'] ?? null, // 2026-06-10 — batch calendar spread
        ]);

        $this->engineIntel->recordToolUsage('write', 'write_article', $result['success'] ? 0.8 : 0.3);

        // 2026-05-22 FIX 19b — auto-index the new draft into seo_content_index
        // so link_suggestions can find it as a source/target BEFORE publish.
        // Without this, the link chain returns 0 candidates (the Wave 66
        // indexer only runs at publish time).
        if (!empty($article['article_id']) || !empty($article['id'])) {
            $this->indexArticleForLinkGraph($wsId, $article['article_id'] ?? $article['id']);
        }

        // 2026-06-13 — FEATURED IMAGE *before* the WordPress push. The
        // "fully-optimized article" bundle (2cr) includes a featured image, but
        // this method only persisted text; the auto-push below then sent a
        // draft with NO image to WordPress (and batch/task articles never got
        // an image at all). Root cause of imageless WP drafts. Generate it HERE
        // — after the row exists, BEFORE the push — when the caller opted into
        // the bundle image (auto_featured_image) and the row has none yet.
        // CREATIVE888 is only CALLED: generateImage() sets featured_image_url +
        // alt on the article row in place; no Creative/* logic is touched.
        // Best-effort + creditless: the 2cr bundle the caller already reserved
        // covers it, and a failure leaves a text-only draft exactly as before
        // (no regression, no double-charge, no double-generation — the single
        // /connector/generate-article path stays idempotent because it checks
        // for an existing featured_image_url before its own image step).
        $newArticleId = $article['article_id'] ?? $article['id'] ?? null;
        if ($newArticleId && ! empty($params['auto_featured_image'])) {
            $alreadyHasImage = DB::table('articles')->where('id', $newArticleId)->value('featured_image_url');
            if (empty($alreadyHasImage)) {
                try {
                    app(\App\Engines\Creative\Services\CreativeService::class)->generateImage($wsId, [
                        'article_id' => (int) $newArticleId,
                        'quality'    => 'mini',
                        'user_id'    => $params['user_id'] ?? null,
                    ]);
                } catch (\Throwable $e) {
                    \Log::warning('[Write] auto featured-image generation failed (text-only draft kept): ' . $e->getMessage());
                }
            }
        }

        // 2026-05-23 FIX 28 (Part A) — auto-push to WordPress as a draft for
        // any workspace with WP credentials. Previously this only fired in
        // SeoAssistantService::execGenerateArticle (FIX 21), so the single-
        // article chat path worked but batch tasks created via Sarah's
        // chain (or the new SEO Assistant batch path) never reached WP.
        // Idempotent via wp_post_id check inside the helper. Non-fatal on
        // failure — the article still lives in Laravel. (Featured image is now
        // generated just above, so the pushed draft carries it.)
        if (!empty($article['article_id']) || !empty($article['id'])) {
            $this->pushDraftToWordPressIfConnected($wsId, $article['article_id'] ?? $article['id']);
        }

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
            // IDOR FIX (2026-07-18) — this lookup was UNSCOPED
            // (`where('id',$articleId)` only), the exact cross-tenant pattern
            // swept on 2026-07-15. A caller passing another tenant's article_id
            // would have had its content read and overwritten.
            $article = DB::table('articles')
                ->where('id', $articleId)
                ->where('workspace_id', $wsId)
                ->whereNull('deleted_at')
                ->first();
            // Schema is `content`, not `body`
            $content = $article->content ?? $content;

            // Not ours (or gone) — do not silently fall through to a self-resolved
            // target, which would improve a DIFFERENT article than was asked for.
            if (! $article) {
                throw new \InvalidArgumentException("Article {$articleId} not found in this workspace");
            }
        }

        // BULK-PROPOSAL FIX (2026-07-18) — Sarah's daily proposals spawn
        // improve_draft tasks carrying only {title, description, proposal_id}:
        // no article_id, no content. Orchestrator.php:701 documents article_id
        // as REQUIRED, so every proposal-generated run threw "No content to
        // improve" — 9 of 10 improve_draft tasks failed, every day, across
        // ws2/ws7/ws26 (tasks 1900/1918/1948/1961/1987/1995).
        //
        // Self-resolve a target the way fix_orphans already does
        // (Orchestrator.php:688 — "no params (optional limit)"): take this
        // workspace's SHORTEST non-empty draft, which is what "expand thin
        // pages" means in practice. Deterministic SQL, no scoring or ranking
        // heuristic, so it stays on the Laravel side of hands-vs-brain.
        if (empty($content) && ! $articleId) {
            $article = DB::table('articles')
                ->where('workspace_id', $wsId)
                ->where('status', 'draft')
                ->whereNull('deleted_at')
                ->whereNotNull('content')
                ->where('content', '!=', '')
                ->orderByRaw('CHAR_LENGTH(content) ASC')
                ->first();
            if ($article) {
                $articleId = (int) $article->id;
                $content   = (string) $article->content;
                \Illuminate\Support\Facades\Log::info('[WriteService] improve_draft self-resolved a target', [
                    'workspace_id' => $wsId,
                    'article_id'   => $articleId,
                    'reason'       => 'no article_id/content in params (bulk proposal)',
                ]);
            }
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

    /**
     * Wave 45 — AEO Enrichment.
     *
     * Reads an article's content and generates the AEO payload via runtime:
     *   - TLDR: 50-100 word answer-style summary
     *   - 3-5 FAQ Q&A pairs (extracted from article content, no fabrication)
     *   - JSON-LD: Article + FAQPage + Organization (as author) + dateModified
     *   - Heading rewrites: H2s rewritten as natural questions where applicable
     *
     * Persists:
     *   - articles.content (rewritten with TLDR injected after H1 + FAQ section appended)
     *   - articles.jsonld_json (the full JSON-LD blob, emitted on rendered pages)
     *   - articles.aeo_enriched_at (timestamp)
     *
     * Cost: 1 credit standalone, 0 when bundled in a Sarah chain.
     * No-op (returns existing state) if article was enriched in the last 5 minutes.
     */
    /**
     * 2026-07-07 — BULK resolver: generate featured images for EVERY article in
     * the workspace that is missing one. The BACKEND finds the real articles (by
     * workspace_id + empty featured_image_url) and fans out one image task per
     * real article_id. This is the fix for "add images to all the missing ones":
     * Sarah emits ONE intent and never has to guess article ids (the root of the
     * cross-workspace id-fabrication bug). Bounded by `limit` (default 10, max 25).
     */
    public function fillMissingImages(int $wsId, array $params): array
    {
        $limit = min(max((int) ($params['limit'] ?? 10), 1), 25);

        $articles = \Illuminate\Support\Facades\DB::table('articles')
            ->where('workspace_id', $wsId)
            ->whereIn('status', ['published', 'draft'])
            ->whereNull('deleted_at')
            ->where(function ($w) {
                $w->whereNull('featured_image_url')->orWhere('featured_image_url', '');
            })
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get(['id', 'title']);

        if ($articles->isEmpty()) {
            return ['success' => true, 'created' => 0, 'changed' => false,
                    'message' => 'Every article already has a featured image — nothing to do.'];
        }

        $taskSvc = app(\App\Core\TaskSystem\TaskService::class);
        $taskIds = [];
        foreach ($articles as $a) {
            $t = $taskSvc->create($wsId, [
                'engine' => 'creative', 'action' => 'generate_image_mini', 'source' => 'agent',
                'assigned_agents' => ['priya'], 'auto_approve' => true, 'requires_approval' => false,
                'credit_cost' => 2,
                'payload' => ['article_id' => (int) $a->id, 'title' => 'Featured image for ' . $a->title, 'created_via' => 'fill_missing_images'],
            ]);
            $taskIds[] = (int) $t->id;
        }

        return [
            'success'     => true,
            'created'     => count($taskIds),
            'article_ids' => $articles->pluck('id')->all(),
            'task_ids'    => $taskIds,
            'message'     => count($taskIds) . ' featured image' . (count($taskIds) === 1 ? '' : 's')
                             . ' are being generated for the articles that were missing them.',
        ];
    }

    public function aeoEnrich(int $wsId, array $params): array
    {
        $articleId = (int) ($params['article_id'] ?? 0);
        if (!$articleId) {
            throw new \InvalidArgumentException('article_id required');
        }

        $article = \Illuminate\Support\Facades\DB::table('articles')
            ->where('id', $articleId)
            ->where('workspace_id', $wsId)
            ->first(['id', 'title', 'content', 'focus_keyword', 'aeo_enriched_at', 'created_at', 'updated_at']);

        if (!$article) {
            throw new \RuntimeException("Article #{$articleId} not found in workspace");
        }

        // Idempotency: skip if enriched within the last 5 minutes (covers
        // accidental double-clicks and chain retries).
        if ($article->aeo_enriched_at && \Carbon\Carbon::parse($article->aeo_enriched_at)->gt(now()->subMinutes(5))) {
            return [
                'article_id' => $articleId,
                'enriched' => false,
                'reason' => 'recently_enriched',
                'aeo_enriched_at' => $article->aeo_enriched_at,
            ];
        }

        // Workspace context for the Organization JSON-LD (author/publisher).
        $ws = \Illuminate\Support\Facades\DB::table('workspaces')->where('id', $wsId)->first(['name', 'business_name']);
        $businessName = $ws->business_name ?? $ws->name ?? 'LevelUp Growth';
        $siteUrl = \Illuminate\Support\Facades\DB::table('seo_settings')
            ->where('workspace_id', $wsId)->where('key', 'site_url')->value('value') ?: 'https://levelupgrowth.io';

        $articleTitle = $article->title ?: ($article->focus_keyword ?: 'Article');
        $articleContent = (string) $article->content;
        $plainText = trim(preg_replace('/\s+/', ' ', strip_tags($articleContent)));
        $firstNWords = implode(' ', array_slice(explode(' ', $plainText), 0, 1200));

        // Folded prompt — request structured JSON output via the existing
        // seo_content_generation task type (no runtime PR needed).
        $userPrompt = "Given the article below, produce a JSON object that helps LLM-based "
            . "search engines (ChatGPT, Perplexity, Claude, Google AI Overviews, Bing Copilot) "
            . "cite this content accurately.\n\n"
            . "ARTICLE TITLE: {$articleTitle}\n"
            . "FOCUS KEYWORD: " . ($article->focus_keyword ?: '(none)') . "\n"
            . "ARTICLE BODY (first ~1200 words):\n{$firstNWords}\n\n"
            . "Output ONLY a JSON object with EXACTLY these three top-level keys (no other keys, "
            . "no markdown, no prose):\n"
            . "{\n"
            . "  \"tldr\": \"50 to 100 word answer-style summary an LLM could quote verbatim\",\n"
            . "  \"faqs\": [ { \"q\": \"question\", \"a\": \"answer\" }, ...3 to 5 pairs grounded in article content ],\n"
            . "  \"heading_rewrites\": [ { \"from\": \"existing H2 text\", \"to\": \"H2 rewritten as a natural question\" }, ... ]\n"
            . "}\n\n"
            . "Rules:\n"
            . "- Use the EXACT keys: tldr, faqs, heading_rewrites (lowercase)\n"
            . "- Each FAQ uses keys q and a (single letters, lowercase)\n"
            . "- Each heading_rewrite uses keys from and to (lowercase)\n"
            . "- Do not invent facts; ground every FAQ answer in the article body";

        // Wave 45 — empty context. Runtime appends context k=v lines into the
        // prompt and the LLM would echo article_id/workspace_name into the
        // output JSON. All routing info is already in the user prompt.
        $context = [];

        $systemPrompt = 'You are an Answer Engine Optimization (AEO) specialist. You produce '
            . 'structured JSON output that helps LLM-based search engines cite content accurately. '
            . 'You never fabricate facts. You ground every FAQ answer in the article text provided. '
            . 'You output valid JSON only — no prose, no markdown fences.';

        // chatJson lets us send a custom system prompt and get parsed JSON
        // back. aiRun('seo_content_generation', ...) has a hardcoded meta-
        // generation system prompt that overrides our AEO request.
        $result = $this->runtime->chatJson($systemPrompt, $userPrompt, $context, 2000);
        if (!($result['success'] ?? false)) {
            \Illuminate\Support\Facades\Log::warning('[AeoEnrich] runtime failed', [
                'article_id' => $articleId,
                'error' => $result['error'] ?? 'unknown',
            ]);
            return [
                'article_id' => $articleId,
                'enriched' => false,
                'reason' => 'runtime_failed',
                'error' => $result['error'] ?? null,
            ];
        }

        // chatJson already parses the JSON for us in $result['parsed'].
        $parsed = $result['parsed'] ?? null;
        if (!is_array($parsed) || !isset($parsed['tldr'])) {
            // Fallback: try parsing $result['text'] in case parsed didn't populate.
            $rawText = (string) ($result['text'] ?? '');
            $rawText = preg_replace('/^```(?:json)?\s*/i', '', trim($rawText));
            $rawText = preg_replace('/\s*```$/', '', $rawText);
            $parsed = json_decode($rawText, true);
        }

        // Wave 45 — normalize LLM response variants. DeepSeek frequently
        // omits tldr or uses {summary, abstract, overview} variants. Faqs
        // can use {q,a}, {question,answer}, etc. Be tolerant: only fail
        // when there's no usable payload at all.
        if (!is_array($parsed)) {
            \Illuminate\Support\Facades\Log::warning('[AeoEnrich] runtime returned non-JSON', [
                'article_id' => $articleId,
                'raw_head' => mb_substr($rawText ?? '', 0, 200),
            ]);
            return [
                'article_id' => $articleId,
                'enriched' => false,
                'reason' => 'parse_failed',
                'raw_head' => mb_substr($rawText ?? '', 0, 200),
            ];
        }

        // Find tldr via any common synonym.
        $tldr = null;
        foreach (['tldr', 'summary', 'abstract', 'overview', 'description', 'meta_description'] as $k) {
            if (isset($parsed[$k]) && is_string($parsed[$k]) && trim($parsed[$k]) !== '') {
                $tldr = trim($parsed[$k]);
                break;
            }
        }

        // Fallback: synthesize tldr from the article's first ~80 words.
        if (!$tldr) {
            $words = preg_split('/\s+/', trim($plainText));
            $tldr = implode(' ', array_slice($words, 0, 80));
            if (count($words) > 80) $tldr .= '.';
            \Illuminate\Support\Facades\Log::info('[AeoEnrich] LLM omitted tldr; synthesized from article body', ['article_id' => $articleId]);
        }

        // FAQ normalization — accept {q,a}, {question,answer}, {name,text}, etc.
        $faqs = [];
        $rawFaqs = is_array($parsed['faqs'] ?? null) ? $parsed['faqs']
                : (is_array($parsed['faq'] ?? null) ? $parsed['faq']
                : (is_array($parsed['questions'] ?? null) ? $parsed['questions'] : []));
        foreach ($rawFaqs as $f) {
            if (!is_array($f)) continue;
            $q = $f['q'] ?? $f['question'] ?? $f['name'] ?? null;
            $a = $f['a'] ?? $f['answer']   ?? $f['text'] ?? null;
            if ($q && $a) {
                $faqs[] = ['q' => trim((string) $q), 'a' => trim((string) $a)];
            }
        }

        // Heading rewrites — accept {from,to}, {original,rewritten}, {old,new}
        $headingRewrites = [];
        $rawRewrites = is_array($parsed['heading_rewrites'] ?? null) ? $parsed['heading_rewrites']
                    : (is_array($parsed['rewrites'] ?? null) ? $parsed['rewrites'] : []);
        foreach ($rawRewrites as $rw) {
            if (!is_array($rw)) continue;
            $from = $rw['from'] ?? $rw['original'] ?? $rw['old'] ?? null;
            $to   = $rw['to']   ?? $rw['rewritten'] ?? $rw['new'] ?? null;
            if ($from && $to) {
                $headingRewrites[] = ['from' => (string) $from, 'to' => (string) $to];
            }
        }

        // ── 1. Mutate the article content ──────────────────────────────
        $newContent = $articleContent;

        // 1a. Inject TLDR right after the first <h1>...</h1> as an <aside>
        if ($tldr && stripos($newContent, 'aeo-tldr') === false) {
            $tldrBlock = '<aside class="aeo-tldr" style="background:#f0f7ff;border-left:4px solid #3B82F6;padding:14px 18px;margin:16px 0;border-radius:6px;font-size:15px;line-height:1.6;color:#1e3a5f"><strong style="display:block;margin-bottom:6px;font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#3B82F6">TLDR</strong>' . e($tldr) . '</aside>';
            // Insert after the first </h1>; fall back to prepending if no H1.
            $h1Pos = stripos($newContent, '</h1>');
            if ($h1Pos !== false) {
                $newContent = substr($newContent, 0, $h1Pos + 5) . "\n" . $tldrBlock . "\n" . substr($newContent, $h1Pos + 5);
            } else {
                $newContent = $tldrBlock . "\n" . $newContent;
            }
        }

        // 1b. Apply heading rewrites — replace H2 inner text where matched.
        foreach ($headingRewrites as $rw) {
            $from = (string) ($rw['from'] ?? '');
            $to   = (string) ($rw['to'] ?? '');
            if ($from === '' || $to === '' || $from === $to) continue;
            // Match <h2>...$from...</h2> exactly once.
            $pattern = '#(<h2[^>]*>)\s*' . preg_quote($from, '#') . '\s*(</h2>)#i';
            $newContent = preg_replace($pattern, '$1' . e($to) . '$2', $newContent, 1);
        }

        // 1c. Append FAQ section if we got Q&A pairs and there's no existing
        // FAQ in ANY form. Wave 76 — class-based markup, no inline styles;
        // also detects inline <h2>FAQ</h2> / <h2>Frequently Asked Questions</h2>
        // so we don't duplicate.
        $hasExistingFaq = stripos($newContent, 'aeo-faq') !== false
            || stripos($newContent, 'lu-faq') !== false
            || preg_match('#<h2[^>]*>\s*(?:FAQ|Frequently Asked Questions?)\s*</h2>#i', $newContent);
        if (!empty($faqs) && !$hasExistingFaq) {
            $faqHtml = '<section class="lu-faq">'
                . '<h2 class="lu-faq-title">Frequently Asked Questions</h2>';
            foreach ($faqs as $f) {
                $faqHtml .= '<div class="lu-faq-item">'
                    . '<h3 class="lu-faq-q">' . e($f['q']) . '</h3>'
                    . '<p class="lu-faq-a">' . e($f['a']) . '</p>'
                    . '</div>';
            }
            $faqHtml .= '</section>';
            $newContent .= "\n" . $faqHtml;
        }

        // ── 2. Build JSON-LD payload ──────────────────────────────────
        $now = now()->toIso8601String();
        $created = $article->created_at ? \Carbon\Carbon::parse($article->created_at)->toIso8601String() : $now;
        $jsonld = [
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'Article',
                    'headline' => $articleTitle,
                    'description' => $tldr,
                    'datePublished' => $created,
                    'dateModified' => $now,
                    'author' => [
                        '@type' => 'Organization',
                        'name' => $businessName,
                        'url' => $siteUrl,
                    ],
                    'publisher' => [
                        '@type' => 'Organization',
                        'name' => $businessName,
                        'url' => $siteUrl,
                    ],
                ],
            ],
        ];
        if (!empty($faqs)) {
            $jsonld['@graph'][] = [
                '@type' => 'FAQPage',
                'mainEntity' => array_map(function ($f) {
                    return [
                        '@type' => 'Question',
                        'name' => $f['q'],
                        'acceptedAnswer' => [
                            '@type' => 'Answer',
                            'text' => $f['a'],
                        ],
                    ];
                }, $faqs),
            ];
        }

        // ── 3. Persist everything atomically ──────────────────────────
        \Illuminate\Support\Facades\DB::table('articles')->where('id', $articleId)->update([
            'content' => $newContent,
            'jsonld_json' => json_encode($jsonld, JSON_UNESCAPED_SLASHES),
            'aeo_enriched_at' => now(),
            'updated_at' => now(),
        ]);

        $this->engineIntel->recordToolUsage('write', 'aeo_enrich', 0.9);

        return [
            'article_id' => $articleId,
            'enriched' => true,
            'tldr' => $tldr,
            'faq_count' => count($faqs),
            'heading_rewrites' => count($headingRewrites),
            'jsonld_persisted' => true,
            'aeo_enriched_at' => $now,
            'source' => 'runtime',
        ];
    }

    public function generateMeta(int $wsId, array $params): array
    {
        $title     = $params['title'] ?? '';
        $content   = $params['content'] ?? $params['body'] ?? '';
        $keyword   = $params['keyword'] ?? $params['target_keyword'] ?? '';
        $articleId = $params['article_id'] ?? null;

        // Wave 41 — when called from the chain we only get article_id, no
        // title or content. Load from articles table so the LLM has real
        // context (without this the prompt receives empty article_title +
        // empty body and the model returns a generic SEO-tutorial reply).
        if ($articleId && (!$title || !$content)) {
            $art = \Illuminate\Support\Facades\DB::table('articles')
                ->where('id', $articleId)
                ->where('workspace_id', $wsId)
                ->first(['title', 'content', 'focus_keyword']);
            if ($art) {
                if (!$title)   $title   = $art->title ?? '';
                if (!$content) $content = $art->content ?? '';
                if (!$keyword) $keyword = $art->focus_keyword ?? '';
            }
        }

        // 2026-05-23 FIX 38 — when called from the chain, the task payload
        // title is something like "Article 1: generate meta", NOT the
        // actual article title. If that string sneaks into $title we end
        // up writing it as the article's meta_title (FIX 36 incident).
        // Heuristic: if $title looks like a task-payload string and we
        // have an articleId, prefer the article's real title from DB.
        if ($articleId && $title !== '' && preg_match('/^(Article\s+\d+|Meta\s+title|generate\s+meta)/i', $title)) {
            $dbTitle = \Illuminate\Support\Facades\DB::table('articles')
                ->where('id', $articleId)->where('workspace_id', $wsId)
                ->value('title');
            if ($dbTitle) $title = $dbTitle;
        }

        $bodyText = trim(preg_replace('/\s+/', ' ', strip_tags((string) $content)));
        $excerpt = mb_substr($bodyText, 0, 900);

        $context = array_filter([
            'workspace_id'  => $wsId,
            'article_id'    => $articleId,
            'task'          => 'meta_generation',
            'article_title' => $title,
            'keyword'       => $keyword,
        ], fn($v) => $v !== null && $v !== '');

        // 2026-05-23 FIX 38 — strict prompt + JSON-only output. Same shape
        // FIX 37 used for the backfill (worked 39/39 with zero failures).
        // 2026-05-23 FIX 43 — content rules: no competitor names, no
        // outdated years. Applied here so meta_title/meta_description
        // never include "Pricing Guide 2025" when today is 2026.
        $currentYear = (int) date('Y');
        $userPrompt = "You are writing SEO meta for a published blog post.\n\n"
                    . "Article title: " . $title . "\n"
                    . ($keyword !== '' ? "Focus keyword: $keyword\n" : "")
                    . "Article opening: $excerpt\n\n"
                    . "Write a meta title (50-60 characters, includes the focus keyword if provided, click-worthy, sentence case)\n"
                    . "and meta description (150-160 characters, includes the keyword, summarises the article, ends with a clear value or CTA).\n\n"
                    . "STRICT RULES:\n"
                    . "1. Never name competitor companies, brands, or service providers. Stay generic.\n"
                    . "2. Current year is {$currentYear}. NEVER include a year prior to {$currentYear} in meta_title or meta_description. If a year reference is useful, use {$currentYear} or omit the year.\n\n"
                    . "Return ONLY this JSON, no preamble, no code fences, no commentary:\n"
                    . "{\"meta_title\":\"...\",\"meta_description\":\"...\"}";

        $meta = null;
        $lastError = null;

        // 2026-05-23 FIX 38 — two attempts before giving up. Pre-FIX 38
        // a single transient runtime hiccup wrote garbage permanently.
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                // 2026-05-24 FIX 56 — max_tokens 60→200. Meta title (60 chars)
                // + description (160 chars) + JSON wrapping is ~250 chars ≈
                // 60 tokens *exactly*. The runtime was truncating mid-JSON
                // and parse failed on every retry. 200 gives ~4× headroom
                // without burning extra credits — the LLM stops on the
                // closing brace.
                $result = $this->runtime->aiRun('seo_content_generation', $userPrompt, $context, 200);
            } catch (\Throwable $e) {
                $lastError = 'runtime exception: ' . $e->getMessage();
                continue;
            }
            if (empty($result['success']) || empty($result['text'])) {
                $lastError = 'runtime returned empty';
                continue;
            }
            $candidate = $this->parseGeneratedMetaJson((string) $result['text']);
            if ($candidate && $this->metaLengthOk($candidate)) {
                $meta = $candidate;
                break;
            }
            $lastError = 'invalid JSON or length out of range';
        }

        // 2026-05-23 FIX 38 — NO GARBAGE FALLBACK. Old code wrote
        // $params['title'] (the task payload string) + first 155 body
        // chars when the LLM failed. That corrupted 17 articles in the
        // FIX 36 incident. Now: on failure, return success=false and
        // DO NOT touch the article. Chain orchestrator can retry later.
        if (!$meta) {
            \Illuminate\Support\Facades\Log::warning('[WriteService::generateMeta] giving up — leaving article meta untouched', [
                'workspace_id' => $wsId,
                'article_id'   => $articleId,
                'reason'       => $lastError,
            ]);
            return [
                'success'    => false,
                'error'      => $lastError ?? 'unknown',
                'article_id' => $articleId,
                'persisted'  => false,
                'source'     => 'runtime',
            ];
        }

        // 2026-05-23 FIX 38 — persist directly to articles.meta_title +
        // articles.meta_description. The previous code went through
        // updateArticle() with seo_title/seo_description keys, relying
        // on column-name translation that may be misbehaving (22
        // articles came back with empty fields in FIX 36 — suggests
        // the translation silently dropped values).
        if ($articleId) {
            \Illuminate\Support\Facades\DB::table('articles')
                ->where('id', $articleId)
                ->where('workspace_id', $wsId)
                ->update([
                    'meta_title'       => $meta['title'],
                    'meta_description' => $meta['description'],
                    'updated_at'       => now(),
                ]);

            // 2026-06-22 — re-sync the SEO content index so the Pages view
            // reflects the new meta immediately. Without this, generate_meta
            // updated the article but left seo_content_index.meta_description
            // stale, so the page was falsely flagged "missing meta".
            // syncFromArticle carries meta_title/meta_description and does NOT
            // touch inbound_links (verified) — so it won't re-orphan the page.
            try {
                $fresh = \Illuminate\Support\Facades\DB::table('articles')->find($articleId);
                if ($fresh) {
                    app(\App\Engines\SEO\Services\SeoService::class)
                        ->syncFromArticle((int) $fresh->workspace_id, $fresh);
                }
            } catch (\Throwable $e) {
                \Log::warning('[Write] generateMeta SEO index sync failed: ' . $e->getMessage());
            }
        }

        return [
            'success'    => true,
            'meta'       => $meta,
            'article_id' => $articleId,
            'persisted'  => (bool) $articleId,
            'source'     => 'runtime',
        ];
    }

    /**
     * 2026-05-23 FIX 38 — defensive JSON parser for LLM meta output.
     * Handles three patterns the model emits:
     *   1. Clean JSON: {"meta_title": "...", "meta_description": "..."}
     *   2. Code-fenced JSON: ```json\n{...}\n```
     *   3. Prose around JSON: "Here is your meta: {...} Hope this helps."
     * Returns ['title' => ..., 'description' => ...] or null.
     */
    private function parseGeneratedMetaJson(string $text): ?array
    {
        // Strip markdown code fences.
        $text = preg_replace('/^\s*```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```\s*$/', '', $text);
        $text = trim($text);

        // 2026-05-24 FIX 56 — normalize typographic quotes that LLMs
        // occasionally emit. " " → ", ' ' → '. json_decode rejects
        // these silently.
        $text = strtr($text, [
            "\xE2\x80\x9C" => '"', "\xE2\x80\x9D" => '"',
            "\xE2\x80\x98" => "'", "\xE2\x80\x99" => "'",
        ]);

        // 2026-05-24 FIX 56 — strip trailing commas before closing
        // braces/brackets. LLMs sometimes emit {"a": 1,} which is
        // invalid JSON but easy to recover.
        $text = preg_replace('/,(\s*[}\]])/', '$1', $text);

        $parsed = json_decode($text, true);
        if (!is_array($parsed)) {
            if (preg_match('/\{[^{}]*\}/s', $text, $m)) {
                $parsed = json_decode($m[0], true);
            }
        }
        if (!is_array($parsed)) return null;

        $title = trim((string) ($parsed['meta_title'] ?? $parsed['title'] ?? ''));
        $desc  = trim((string) ($parsed['meta_description'] ?? $parsed['description'] ?? ''));
        $title = trim($title, "\"'\t\n ");
        $desc  = trim($desc, "\"'\t\n ");
        if ($title === '' || $desc === '') return null;
        return ['title' => $title, 'description' => $desc];
    }

    /**
     * 2026-05-23 FIX 38 — length validation. Google snippets cap meta
     * title around 60 chars and meta description around 160. Range is
     * generous on either side because some niches benefit from longer
     * titles (e.g. long-tail keywords) and some descriptions read
     * better at 130. Outside these bounds we ask the model again
     * rather than write a result that will get truncated in SERPs.
     */
    private function metaLengthOk(array $m): bool
    {
        $tl = mb_strlen($m['title']);
        $dl = mb_strlen($m['description']);
        return $tl >= 25 && $tl <= 75 && $dl >= 90 && $dl <= 200;
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
        $words = max(1, str_word_count($text));
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

    /**
     * 2026-05-22 FIX 19b — index a draft article into seo_content_index so
     * the link-suggester finds it as both source and target. Mirrors what
     * Wave 66 does at publish time but moved to write time. Failure is
     * logged and swallowed (non-blocking).
     */
    private function indexArticleForLinkGraph(int $wsId, int $articleId): void
    {
        try {
            $a = \Illuminate\Support\Facades\DB::table('articles')
                ->where('id', $articleId)->where('workspace_id', $wsId)
                ->first();
            if (!$a) return;

            // Pick the workspace's published website to build a URL. If none,
            // skip — no URL to anchor the seo_content_index row to.
            $site = \Illuminate\Support\Facades\DB::table('websites')
                ->where('workspace_id', $wsId)
                ->where('status', 'published')
                ->whereNull('deleted_at')
                ->orderByDesc('id')
                ->first(['subdomain', 'domain', 'custom_domain']);
            if (!$site) return;

            $host = $site->custom_domain ?: $site->domain ?: $site->subdomain ?: '';
            $host = strtolower(trim((string) $host, ' /'));
            if ($host === '') return;
            if (empty($a->slug)) return;

            $url = 'https://' . $host . '/blog/' . ltrim((string) $a->slug, '/');

            $bodyText = trim(preg_replace('/\s+/', ' ', strip_tags((string) $a->content)));
            $imgCount = preg_match_all('#<img\b#i', (string) $a->content);
            $intLinks = preg_match_all('#<a\b[^>]*href="/[^"]+"#i', (string) $a->content);
            $extLinks = preg_match_all('#<a\b[^>]*href="https?://[^"]+"#i', (string) $a->content);
            $h2Count  = preg_match_all('#<h2\b#i', (string) $a->content);

            $payload = [
                'workspace_id'        => $wsId,
                'title'               => $a->title,
                'meta_title'          => $a->meta_title ?: $a->title,
                'meta_description'    => $a->meta_description ?: mb_substr($bodyText, 0, 160),
                'featured_image_url'  => $a->featured_image_url,
                'has_featured_image'  => $a->featured_image_url ? 1 : 0,
                'h1'                  => $a->title,
                'h2_count'            => (int) $h2Count,
                'word_count'          => (int) ($a->word_count ?? str_word_count($bodyText)),
                'image_count'         => (int) $imgCount,
                'internal_link_count' => (int) $intLinks,
                'external_link_count' => (int) $extLinks,
                'inbound_links'       => 0,
                'inbound_weight'      => 0,
                'authority_score'     => 0.5, // mid-tier so the link gate passes
                'readability_score'   => $a->readability_score,
            ];

            $seoSvc = app(\App\Engines\SEO\Services\SeoService::class);
            $ref = new \ReflectionMethod($seoSvc, 'upsertContentIndex');
            $ref->setAccessible(true);
            $ref->invoke($seoSvc, $url, $payload);

            \Illuminate\Support\Facades\Log::info('[WriteService] draft indexed for link graph', [
                'workspace_id' => $wsId, 'article_id' => $articleId, 'url' => $url,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[WriteService] draft indexing failed (non-fatal)', [
                'workspace_id' => $wsId, 'article_id' => $articleId, 'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 2026-05-23 FIX 28 (Part A) — push a newly-written draft to WordPress
     * as a wp_draft post for workspaces with seo_settings.site_url +
     * webhook_secret configured. Lifted from SeoAssistantService's FIX 21
     * helper so EVERY write path benefits (single SEO Assistant chat, batch
     * task chain, Sarah's chain via /agents/sarah/messages, and direct WP
     * plugin calls to /connector/generate-article).
     *
     * Idempotent: bails out if wp_post_id is already set on the article.
     * Non-fatal on any failure (HTTP error, plugin down, secret rotated,
     * site not configured) — logs a warning and returns null. The article
     * stays in Laravel and can be re-pushed later via the publish flow.
     */
    private function pushDraftToWordPressIfConnected(int $wsId, int $articleId): ?int
    {
        try {
            $a = \Illuminate\Support\Facades\DB::table('articles')
                ->where('id', $articleId)
                ->where('workspace_id', $wsId)
                ->first(['id', 'title', 'content', 'meta_title', 'meta_description', 'featured_image_url', 'wp_post_id']);
            if (!$a) return null;

            // Idempotency — already pushed once, do not duplicate.
            if (!empty($a->wp_post_id)) {
                return (int) $a->wp_post_id;
            }

            $siteUrl = \Illuminate\Support\Facades\DB::table('seo_settings')
                ->where('workspace_id', $wsId)
                ->where('key', 'site_url')
                ->value('value');
            $webhookSecret = \Illuminate\Support\Facades\DB::table('seo_settings')
                ->where('workspace_id', $wsId)
                ->where('key', 'webhook_secret')
                ->value('value');

            // No WP connection configured — Laravel-platform site or unconnected
            // workspace. Skip silently (no log noise).
            if (!$siteUrl || !$webhookSecret) {
                return null;
            }

            $payload = [
                'title'              => $a->title,
                'content'            => $a->content,
                'status'             => 'draft',
                'meta_title'         => $a->meta_title ?: $a->title,
                'meta_description'   => $a->meta_description ?: '',
                'featured_image_url' => $a->featured_image_url ?: null,
                'levelup_article_id' => $articleId,
                'secret'             => $webhookSecret,
            ];
            $wpUrl = rtrim((string) $siteUrl, '/') . '/wp-json/lgsc/v1/create-post';

            $r = \Illuminate\Support\Facades\Http::withHeaders([
                    'Content-Type'  => 'application/json',
                    'X-LGSC-Secret' => $webhookSecret,
                ])
                ->timeout(30)
                ->post($wpUrl, $payload);

            if (!$r->successful()) {
                \Illuminate\Support\Facades\Log::warning('[WriteService] WP draft push HTTP error', [
                    'workspace_id' => $wsId,
                    'article_id'   => $articleId,
                    'http'         => $r->status(),
                    'body'         => mb_substr((string) $r->body(), 0, 400),
                ]);
                return null;
            }

            $body = $r->json() ?: [];
            $wpPostId = isset($body['post_id']) && is_numeric($body['post_id'])
                ? (int) $body['post_id'] : null;
            if (!$wpPostId) return null;

            \Illuminate\Support\Facades\DB::table('articles')->where('id', $articleId)->update([
                'wp_post_id' => $wpPostId,
                'updated_at' => now(),
            ]);
            \Illuminate\Support\Facades\Log::info('[WriteService] WP draft pushed', [
                'workspace_id' => $wsId, 'article_id' => $articleId, 'wp_post_id' => $wpPostId,
            ]);
            return $wpPostId;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[WriteService] WP draft push failed (non-fatal)', [
                'workspace_id' => $wsId, 'article_id' => $articleId, 'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

}
