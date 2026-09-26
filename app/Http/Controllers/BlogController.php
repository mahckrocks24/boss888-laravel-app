<?php

namespace App\Http\Controllers;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The company blog API — levelupgrowth.io/blog.
 *
 * The blog runs on the same articles table, Write engine and QA gate as every
 * customer's blog. A post is public when and only when it belongs to the HOUSE
 * workspace, is flagged for the site blog, is published, is not deleted, and
 * its producing task was not rejected by Sarah's QA gate (masterplan §08,
 * rebuild U5, 2026-09-07). The old marketing pages read title/slug/excerpt/
 * featured_image_url/category/read_time/published_at; those keys are kept.
 */
class BlogController
{
    /** The company's own workspace. On 2026-09-07 the blog was found listing QA-tenant and other customers' articles. */
    public const HOUSE_WORKSPACE_ID = 1;

    public const AUTHOR = 'Sarah';
    public const AUTHOR_ROLE = 'AI Digital Marketing Manager';
    public const SITE_URL = 'https://levelupgrowth.io';

    /** Category slugs → labels (masterplan §08 taxonomy). Unknown values fall back to a slugified label. */
    public const CATEGORIES = [
        'product' => 'Product', 'playbooks' => 'Playbooks', 'seo' => 'SEO and AI search', 'websites' => 'Websites and templates',
        'customers' => 'Customers and CRM', 'social' => 'Social and content', 'industry' => 'Industry guides', 'company' => 'Company',
        'ai-strategy' => 'AI strategy',
    ];

    /** GET /api/blog/posts?page=1&category=seo&per_page=6 */
    public function listPosts(Request $request): JsonResponse
    {
        $perPage = min(50, max(1, (int) $request->input('per_page', 6)));
        $page = max(1, (int) $request->input('page', 1));
        $category = (string) $request->input('category', '');

        $query = $this->publicQuery();
        if ($category !== '' && $category !== 'all') {
            $query->where('a.blog_category', $category);
        }

        $total = (clone $query)->count();
        $articles = $query->orderByDesc('a.published_at')->offset(($page - 1) * $perPage)->limit($perPage)
            ->get()->map(fn ($a) => $this->formatArticle($a, true))->values()->all();

        return response()->json([
            'articles' => $articles,
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => (int) ceil($total / $perPage)],
        ]);
    }

    /** GET /api/blog/posts/{slug} */
    public function getPost(string $slug): JsonResponse
    {
        $article = $this->publicQuery()->where('a.slug', $slug)->first();
        if (! $article) {
            return response()->json(['error' => 'Article not found'], 404);
        }

        $related = $this->publicQuery()->where('a.id', '!=', $article->id);
        if ($article->blog_category) { $related->where('a.blog_category', $article->blog_category); }
        $relatedArticles = $related->orderByDesc('a.published_at')->limit(3)->get()->map(fn ($a) => $this->formatArticle($a, true))->values()->all();

        $formatted = $this->formatArticle($article, false);
        $formatted['related'] = $relatedArticles;

        return response()->json($formatted);
    }

    /** GET /api/blog/categories */
    public function categories(): JsonResponse
    {
        $rows = $this->publicBase()->whereNotNull('a.blog_category')->where('a.blog_category', '!=', '')
            ->selectRaw('a.blog_category, COUNT(*) as count')->groupBy('a.blog_category')->orderByDesc('count')->get();

        $categories = $rows->map(fn ($r) => ['slug' => $r->blog_category, 'blog_category' => $r->blog_category, 'label' => self::label($r->blog_category), 'count' => (int) $r->count])->values()->all();
        $total = $this->publicBase()->count();
        array_unshift($categories, ['slug' => 'all', 'blog_category' => 'all', 'label' => 'All posts', 'count' => $total]);

        return response()->json(['categories' => $categories]);
    }

    /** The one public predicate, without a select list (categories() groups on it). */
    private function publicBase(): Builder
    {
        return DB::table('articles as a')
            ->leftJoin('tasks as t', 't.id', '=', 'a.task_id')
            ->where('a.workspace_id', self::HOUSE_WORKSPACE_ID)
            ->where('a.is_marketing_blog', true)
            ->where('a.status', 'published')
            ->whereNull('a.deleted_at')
            // Sarah's QA gate: work she rejected is a draft, never a post.
            ->where(function ($q) { $q->whereNull('t.qa_status')->orWhere('t.qa_status', '!=', 'rejected'); });
    }

    /** The public predicate with the article columns. */
    private function publicQuery(): Builder
    {
        return $this->publicBase()->select('a.*');
    }

    public static function label(?string $slug): string
    {
        $slug = (string) $slug;
        return self::CATEGORIES[$slug] ?? Str::headline(str_replace('-', ' ', $slug));
    }

    private function formatArticle(object $article, bool $truncateContent = false): array
    {
        $wordCount = (int) ($article->word_count ?: str_word_count(strip_tags((string) ($article->content ?? ''))));
        $readTime = max(1, (int) ceil($wordCount / 200));
        $tags = json_decode((string) ($article->tags_json ?? ''), true);
        $tags = is_array($tags) ? array_values(array_filter(array_map('strval', $tags))) : [];
        $excerpt = $article->excerpt ?: $this->buildExcerptFallback($article);
        $url = self::SITE_URL . '/blog/' . $article->slug . '/';

        $jsonld = json_decode((string) ($article->jsonld_json ?? ''), true);
        if (! is_array($jsonld) || empty($jsonld['@type'])) {
            $jsonld = [
                '@context' => 'https://schema.org', '@type' => 'BlogPosting',
                'headline' => $article->title, 'description' => $excerpt, 'url' => $url,
                'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $url],
                'datePublished' => $article->published_at, 'dateModified' => $article->updated_at ?: $article->published_at,
                'author' => ['@type' => 'Organization', 'name' => 'LevelUpGrowth', 'url' => self::SITE_URL, 'description' => self::AUTHOR . ', ' . self::AUTHOR_ROLE],
                'publisher' => ['@type' => 'Organization', 'name' => 'LevelUpGrowth', 'logo' => ['@type' => 'ImageObject', 'url' => self::SITE_URL . '/img/logo-icon-40.png']],
                'articleSection' => self::label($article->blog_category), 'keywords' => implode(', ', $tags),
                'wordCount' => $wordCount, 'timeRequired' => 'PT' . $readTime . 'M',
            ];
            if ($article->featured_image_url) {
                $jsonld['image'] = ['@type' => 'ImageObject', 'url' => $article->featured_image_url, 'caption' => $article->featured_image_alt ?: $article->title];
            }
        }

        return [
            'id' => $article->id,
            'title' => $article->title,
            'slug' => $article->slug,
            'url' => $url,
            'excerpt' => $excerpt,
            'content' => $truncateContent ? null : $article->content,
            'featured_image_url' => $article->featured_image_url,
            'featured_image_alt' => $article->featured_image_alt,
            'category' => $article->blog_category,
            'category_label' => self::label($article->blog_category),
            'tags' => $tags,
            'type' => $article->type ?: 'article',
            'author' => self::AUTHOR,
            'author_role' => self::AUTHOR_ROLE,
            'meta_title' => $article->meta_title,
            'meta_description' => $article->meta_description,
            'published_at' => $article->published_at,
            'updated_at' => $article->updated_at,
            'read_time' => $readTime,
            'word_count' => $wordCount,
            'jsonld' => $truncateContent ? null : $jsonld,
        ];
    }

    /** Smart excerpt fallback (2026-05-22 FIX 6): no title echo, no TLDR block, decoded entities, word-boundary cut. */
    private function buildExcerptFallback(object $article): string
    {
        $content = (string) ($article->content ?? '');
        $content = preg_replace('/^\s*<h1\b[^>]*>.*?<\/h1>/is', '', $content, 1);
        $content = preg_replace('/^\s*<(aside|div)\b[^>]*class="[^"]*aeo-tldr[^"]*"[^>]*>.*?<\/\1>/is', '', $content, 1);
        $plain = strip_tags($content);
        $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = preg_replace('/^\s*TLDR\s*/i', '', $plain, 1);
        $plain = trim((string) preg_replace('/\s+/u', ' ', $plain));
        if ($plain === '') { return ''; }
        $limit = 160;
        if (mb_strlen($plain) <= $limit) { return $plain; }
        $cut = mb_substr($plain, 0, $limit);
        $cut = preg_replace('/\s+\S*$/u', '', $cut);

        return rtrim((string) $cut, " .,;:") . "\u{2026}";
    }
}
