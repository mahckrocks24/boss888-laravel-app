<?php

namespace App\Engines\SEO\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Wave 52 — Index Laravel builder pages into seo_content_index.
 *
 * Until now seo_content_index was only populated by:
 *   - WP plugin sync (external WP installs)
 *   - WriteService when an article was created
 *   - Manual /connector/indexed-content imports
 *
 * Pure-Laravel tenant sites (pages built with the Builder) had ZERO entries.
 * The SEO Engine couldn't see them. This service walks every published
 * builder page for a workspace and upserts a content-index row per page.
 */
class BuilderPageIndexer
{
    public function __construct(private SeoService $seo) {}

    /**
     * Index all published builder pages for a workspace.
     * Returns count of pages indexed.
     */
    public function indexWorkspace(int $workspaceId): int
    {
        $websites = DB::table('websites')
            ->where('workspace_id', $workspaceId)
            ->where('status', 'published')
            ->whereNull('deleted_at')
            ->get(['id', 'subdomain', 'domain', 'name']);

        $count = 0;
        foreach ($websites as $site) {
            $count += $this->indexWebsite($workspaceId, $site);
        }
        return $count;
    }

    /**
     * Index every published page on a single website.
     */
    public function indexWebsite(int $workspaceId, object $website): int
    {
        $pages = DB::table('pages')
            ->where('website_id', $website->id)
            ->where('status', 'published')
            ->get(['id', 'slug', 'title', 'meta_title', 'meta_description', 'sections_json', 'seo_json', 'is_homepage', 'updated_at']);

        $count = 0;
        foreach ($pages as $page) {
            try {
                $this->indexPage($workspaceId, $website, $page);
                $count++;
            } catch (\Throwable $e) {
                Log::warning('[BuilderPageIndexer] failed to index page', [
                    'page_id' => $page->id ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        return $count;
    }

    /**
     * Index one page row. Builds the canonical URL from subdomain/domain +
     * slug, extracts text content from sections_json, derives word_count
     * and h1 best-effort, and upserts to seo_content_index.
     *
     * Empty pages still get a row (with word_count=0) so the SEO Engine
     * knows they exist and audits/links can target them.
     */
    public function indexPage(int $workspaceId, object $website, object $page): void
    {
        $host = $website->domain ?: ($website->subdomain ?: '');
        if (!$host) return; // can't build a URL without a host

        // Home page slug normalization — both "home" and is_homepage=1 render at /
        $slug = (string) ($page->slug ?? '');
        $isHome = !empty($page->is_homepage) || $slug === 'home';
        $path = $isHome ? '/' : '/' . ltrim($slug, '/');
        $url = 'https://' . $host . $path;

        $sections = is_string($page->sections_json) ? json_decode($page->sections_json, true) : ($page->sections_json ?: []);
        if (!is_array($sections)) $sections = [];
        $text = $this->extractText($sections);
        $wordCount = $text === '' ? 0 : str_word_count(strip_tags($text));

        // Extract h1 + h2 counts from text + section types
        $h1 = $page->title ?? '';
        $h2Count = 0;
        $imageCount = 0;
        foreach ($sections as $sec) {
            if (!is_array($sec)) continue;
            $type = $sec['type'] ?? '';
            if (in_array($type, ['features', 'cta', 'blog_list', 'contact_form'], true)) {
                $h2Count++;
            }
            if (in_array($type, ['hero', 'image', 'gallery'], true)) {
                $imageCount++;
            }
        }

        $seoJson = is_string($page->seo_json) ? json_decode($page->seo_json, true) : ($page->seo_json ?: []);
        $metaTitle = $page->meta_title ?? ($seoJson['title'] ?? null) ?? $page->title;
        $metaDesc = $page->meta_description ?? ($seoJson['description'] ?? null);

        $payload = [
            'workspace_id' => $workspaceId,
            'title' => $page->title ?? '',
            'meta_title' => $metaTitle,
            'meta_description' => $metaDesc,
            'has_featured_image' => $imageCount > 0 ? 1 : 0,
            'h1' => $h1,
            'h2_count' => $h2Count,
            'word_count' => $wordCount,
            'image_count' => $imageCount,
            'internal_link_count' => 0,
            'external_link_count' => 0,
            'inbound_links' => 0,
            'inbound_weight' => 0,
            'authority_score' => 0,
        ];

        // Use SeoService's protected upsertContentIndex via reflection
        // (matches the pattern used elsewhere — single source of truth).
        $ref = new \ReflectionMethod($this->seo, 'upsertContentIndex');
        $ref->setAccessible(true);
        $ref->invoke($this->seo, $url, $payload);
    }

    /**
     * Best-effort text extraction from a Builder sections array.
     * Walks every "text", "body", "html", "content", "subtitle", "heading"
     * field at any depth and concatenates them.
     */
    private function extractText(array $sections): string
    {
        $parts = [];
        $walker = function ($node) use (&$walker, &$parts) {
            if (is_array($node)) {
                foreach ($node as $key => $val) {
                    if (in_array($key, ['text', 'body', 'html', 'content', 'subtitle', 'heading', 'title', 'description'], true) && is_string($val)) {
                        $parts[] = strip_tags($val);
                    } elseif (is_array($val)) {
                        $walker($val);
                    }
                }
            }
        };
        $walker($sections);
        return implode(' ', array_filter($parts));
    }
}
