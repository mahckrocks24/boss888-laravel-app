<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BlogController
{
    /**
     * List published marketing blog articles.
     * GET /api/blog/posts?page=1&category=seo&per_page=6
     */
    public function listPosts(Request $request): JsonResponse
    {
        $perPage = min(20, max(1, (int) $request->input('per_page', 6)));
        $page = max(1, (int) $request->input('page', 1));
        $category = $request->input('category');

        $query = DB::table('articles')
            ->where('is_marketing_blog', true)
            ->where('status', 'published')
            ->whereNull('deleted_at');

        if ($category && $category !== 'all') {
            $query->where('blog_category', $category);
        }

        $total = (clone $query)->count();
        $articles = $query->orderByDesc('published_at')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get()
            ->map(fn($a) => $this->formatArticle($a, true))
            ->toArray();

        return response()->json([
            'articles' => $articles,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / $perPage),
            ],
        ]);
    }

    /**
     * Get a single blog article by slug.
     * GET /api/blog/posts/{slug}
     */
    public function getPost(string $slug): JsonResponse
    {
        $article = DB::table('articles')
            ->where('slug', $slug)
            ->where('is_marketing_blog', true)
            ->where('status', 'published')
            ->whereNull('deleted_at')
            ->first();

        if (!$article) {
            return response()->json(['error' => 'Article not found'], 404);
        }

        // Get related articles (same category, excluding this one)
        $related = DB::table('articles')
            ->where('is_marketing_blog', true)
            ->where('status', 'published')
            ->where('id', '!=', $article->id)
            ->whereNull('deleted_at');

        if ($article->blog_category) {
            $related->where('blog_category', $article->blog_category);
        }

        $relatedArticles = $related->orderByDesc('published_at')
            ->limit(3)
            ->get()
            ->map(fn($a) => $this->formatArticle($a, true))
            ->toArray();

        $formatted = $this->formatArticle($article, false);
        $formatted['related'] = $relatedArticles;

        return response()->json($formatted);
    }

    /**
     * List blog categories with article counts.
     * GET /api/blog/categories
     */
    public function categories(): JsonResponse
    {
        $categories = DB::table('articles')
            ->where('is_marketing_blog', true)
            ->where('status', 'published')
            ->whereNull('deleted_at')
            ->whereNotNull('blog_category')
            ->selectRaw('blog_category, COUNT(*) as count')
            ->groupBy('blog_category')
            ->orderByDesc('count')
            ->get()
            ->toArray();

        $total = DB::table('articles')
            ->where('is_marketing_blog', true)
            ->where('status', 'published')
            ->whereNull('deleted_at')
            ->count();

        array_unshift($categories, (object) ['blog_category' => 'all', 'count' => $total]);

        return response()->json(['categories' => $categories]);
    }

    private function formatArticle(object $article, bool $truncateContent = false): array
    {
        $wordCount = $article->word_count ?: str_word_count(strip_tags($article->content ?? ''));
        $readTime = max(1, (int) ceil($wordCount / 200));

        return [
            'id' => $article->id,
            'title' => $article->title,
            'slug' => $article->slug,
            // 2026-05-22 FIX 6 — smarter excerpt fallback. Byte-cut at 160 was
            // landing mid-word (",000. The Pr..." when the cut split "$2,000");
            // it also duplicated the title and left "TLDR" + &amp; in the output.
            'excerpt' => $article->excerpt ?: $this->buildExcerptFallback($article),
            'content' => $truncateContent ? null : $article->content,
            'featured_image_url' => $article->featured_image_url,
            'category' => $article->blog_category,
            'author' => 'Sarah',
            'author_role' => 'AI Digital Marketing Manager',
            'published_at' => $article->published_at,
            'read_time' => $readTime,
            'word_count' => $wordCount,
        ];
    }

    /**
     * Smart excerpt fallback (2026-05-22 FIX 6).
     *  - Strip leading <h1>...</h1> so excerpt does not duplicate the title.
     *  - Strip <aside class="aeo-tldr"> / <div class="aeo-tldr"> if present.
     *  - html_entity_decode so &amp; becomes &.
     *  - Collapse whitespace, strip stray leading "TLDR".
     *  - Cut at 160 chars but trim back to the last word boundary so we
     *    never end mid-token (which produced things like ",000. The Pr...").
     */
    private function buildExcerptFallback(object $article): string
    {
        $content = (string) ($article->content ?? '');

        // Remove the leading <h1>...</h1> so excerpt does not duplicate the
        // title (which is rendered separately on the card).
        $content = preg_replace('/^\s*<h1\b[^>]*>.*?<\/h1>/is', '', $content, 1);

        // Strip the AEO TLDR <aside>/<div class="aeo-tldr ..."> block if
        // present at the top so we lead with body prose.
        $content = preg_replace(
            '/^\s*<(aside|div)\b[^>]*class="[^"]*aeo-tldr[^"]*"[^>]*>.*?<\/\1>/is',
            '',
            $content,
            1
        );

        $plain = strip_tags($content);
        $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Strip a stray "TLDR" prefix (when content lacked a wrapping aside).
        $plain = preg_replace('/^\s*TLDR\s*/i', '', $plain, 1);
        $plain = preg_replace('/\s+/u', ' ', $plain);
        $plain = trim($plain);

        if ($plain === '') return '';

        $limit = 160;
        if (mb_strlen($plain) <= $limit) return $plain;

        $cut = mb_substr($plain, 0, $limit);
        // Trim back to the last whitespace so we do not end mid-word.
        $cut = preg_replace('/\s+\S*$/u', '', $cut);

        return rtrim($cut, " .,;:") . "\xE2\x80\xA6"; // U+2026 horizontal ellipsis
    }

}
