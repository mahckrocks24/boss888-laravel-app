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
            'excerpt' => $article->excerpt ?: mb_substr(strip_tags($article->content ?? ''), 0, 160) . '...',
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
}
