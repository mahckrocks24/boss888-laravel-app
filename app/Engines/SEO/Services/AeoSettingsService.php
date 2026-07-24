<?php

namespace App\Engines\SEO\Services;

use Illuminate\Support\Facades\DB;

/**
 * Wave 46 — AEO Settings Service.
 *
 * Manages per-workspace AEO configuration:
 *  - AI crawler allow/block toggles (GPTBot, ClaudeBot, PerplexityBot, etc.)
 *  - llms.txt content cache (regenerated daily + on-demand)
 *
 * Settings are read each time PublishedSiteMiddleware serves /robots.txt
 * or /llms.txt for a tenant subdomain.
 */
class AeoSettingsService
{
    private const ALL_CRAWLERS = [
        'allow_gptbot'         => 'GPTBot',          // OpenAI / ChatGPT search
        'allow_claudebot'      => 'ClaudeBot',       // Anthropic / Claude
        'allow_perplexitybot'  => 'PerplexityBot',   // Perplexity AI
        'allow_bingbot'        => 'Bingbot',         // Bing Copilot
        'allow_google_extended'=> 'Google-Extended', // Google AI Overviews / Gemini
        'allow_bytespider'     => 'Bytespider',      // ByteDance / TikTok (off by default)
        'allow_amazonbot'      => 'Amazonbot',       // Alexa, Amazon AI
        'allow_ccbot'          => 'CCBot',           // Common Crawl (training data)
    ];

    /**
     * Get settings row for a workspace, creating defaults if missing.
     */
    public function get(int $wsId): array
    {
        $row = DB::table('aeo_settings')->where('workspace_id', $wsId)->first();
        if (!$row) {
            DB::table('aeo_settings')->insert([
                'workspace_id' => $wsId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $row = DB::table('aeo_settings')->where('workspace_id', $wsId)->first();
        }
        return (array) $row;
    }

    /**
     * Update one or more setting fields. Returns the updated row.
     */
    public function update(int $wsId, array $changes): array
    {
        $this->get($wsId); // ensure row exists

        $allowed = ['aeo_mode_enabled', 'llms_txt_cache'] + array_flip(array_keys(self::ALL_CRAWLERS));
        $allowed = array_keys(array_flip(array_keys(self::ALL_CRAWLERS)) + ['aeo_mode_enabled' => 0, 'llms_txt_cache' => 0]);

        $clean = [];
        foreach ($changes as $k => $v) {
            if (in_array($k, $allowed, true)) {
                $clean[$k] = is_bool($v) ? (int) $v : $v;
            }
        }
        $clean['updated_at'] = now();

        if (!empty($clean)) {
            DB::table('aeo_settings')->where('workspace_id', $wsId)->update($clean);
        }
        return $this->get($wsId);
    }

    /**
     * Render robots.txt content for a workspace.
     * Includes per-crawler User-agent blocks for AI crawlers + the
     * generic catch-all + sitemap URL.
     */
    public function renderRobotsTxt(int $wsId, string $sitemapUrl): string
    {
        $settings = $this->get($wsId);

        $body = "# AI Search Crawlers — see /llms.txt for canonical content index\n\n";

        foreach (self::ALL_CRAWLERS as $key => $userAgent) {
            $allow = !empty($settings[$key]);
            $body .= "User-agent: {$userAgent}\n";
            $body .= ($allow ? "Allow: /" : "Disallow: /") . "\n\n";
        }

        $body .= "# Default for all other crawlers\n";
        $body .= "User-agent: *\nAllow: /\n\n";
        $body .= "Sitemap: {$sitemapUrl}\n";

        return $body;
    }

    /**
     * Regenerate llms.txt content from workspace pages + articles.
     * Persists to aeo_settings.llms_txt_cache so the public route can
     * serve it without re-querying every request.
     */
    public function regenerateLlmsTxt(int $wsId): string
    {
        $this->get($wsId); // ensure row exists

        $ws = DB::table('workspaces')->where('id', $wsId)->first(['name', 'business_name', 'industry', 'location']);
        $businessName = $ws->business_name ?? $ws->name ?? 'Site';
        $description = trim((string)($ws->industry ?? '') . ($ws->location ? ' in ' . $ws->location : ''));

        // Find the workspace's primary published website to derive the URL base.
        $website = DB::table('websites')
            ->where('workspace_id', $wsId)
            ->where('status', 'published')
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->first(['id', 'subdomain', 'domain', 'name']);

        $base = $website
            ? 'https://' . ($website->domain ?: $website->subdomain ?: '')
            : 'https://levelupgrowth.io';

        $out = "# {$businessName}\n\n";
        if ($description) {
            $out .= "> " . $this->oneLine($description) . "\n\n";
        }

        // ── Pages ──
        $pages = collect();
        if ($website && isset($website->id)) {
            try {
                $pages = DB::table('pages')
                    ->where('website_id', $website->id)
                    ->where('status', 'published')
                    ->orderBy('slug')
                    ->limit(50)
                    ->get(['title', 'slug', 'meta_description']);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[AeoSettings] pages query failed', ['err' => $e->getMessage()]);
            }
        }

        if ($pages->isNotEmpty()) {
            $out .= "## Pages\n\n";
            foreach ($pages as $p) {
                $url = rtrim($base, '/') . '/' . ltrim((string) $p->slug, '/');
                $desc = $p->meta_description ? ' — ' . $this->oneLine($p->meta_description) : '';
                $out .= "- [{$p->title}]({$url}){$desc}\n";
            }
            $out .= "\n";
        }

        // ── Articles ──
        $articles = DB::table('articles')
            ->where('workspace_id', $wsId)
            ->where('status', 'published')
            ->whereNull('deleted_at')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get(['title', 'slug', 'seo_json']);

        if ($articles->isNotEmpty()) {
            $out .= "## Articles\n\n";
            foreach ($articles as $a) {
                $url = rtrim($base, '/') . '/blog/' . ltrim((string) $a->slug, '/');
                $seo = $a->seo_json ? json_decode($a->seo_json, true) : null;
                $desc = (is_array($seo) && !empty($seo['description'])) ? ' — ' . $this->oneLine($seo['description']) : '';
                $out .= "- [{$a->title}]({$url}){$desc}\n";
            }
            $out .= "\n";
        }

        // 2026-05-28 — WP-synced fallback. The pages + articles queries above
        // only see Laravel-built websites and Laravel-managed articles. WP
        // workspaces (shukranuae.com etc.) have neither — their canonical URL
        // list lives in seo_content_index, populated by the WP connector
        // sync. Without this block llms.txt is just a header for any WP-only
        // workspace. Filter the same junk URLs the chatbot crawler skips so
        // the LLM-facing index isn't littered with drafts.
        if ($pages->isEmpty() && $articles->isEmpty()) {
            try {
                $indexed = DB::table('seo_content_index')
                    ->where('workspace_id', $wsId)
                    ->whereNotNull('url')
                    ->where('url', '!=', '')
                    ->orderByDesc('word_count')
                    ->limit(200)
                    ->get(['url', 'title', 'meta_description']);

                $kept = [];
                foreach ($indexed as $row) {
                    $u = (string) $row->url;
                    if (preg_match('/\?p=\d+/i', $u))             continue;
                    if (preg_match('#/(wp-admin|wp-content|wp-includes|wp-json|feed)/?#i', $u)) continue;
                    if (preg_match('#\.(jpg|jpeg|png|gif|webp|pdf|zip|svg|ico)(\?|$)#i', $u)) continue;
                    $kept[] = $row;
                    if (count($kept) >= 100) break;
                }

                if (! empty($kept)) {
                    $out .= "## Pages\n\n";
                    foreach ($kept as $row) {
                        $title = trim((string) ($row->title ?: $row->url));
                        $desc  = $row->meta_description ? ' — ' . $this->oneLine($row->meta_description) : '';
                        $out  .= "- [{$title}]({$row->url}){$desc}\n";
                    }
                    $out .= "\n";
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[AeoSettings] seo_content_index fallback failed', ['err' => $e->getMessage()]);
            }
        }

        $out .= "## About\n\n";
        $out .= "This llms.txt file follows the convention proposed at https://llmstxt.org\n";
        $out .= "It provides a canonical, machine-readable index of this site's content for LLM-based search engines.\n";
        $out .= "Last regenerated: " . now()->toIso8601String() . "\n";

        DB::table('aeo_settings')->where('workspace_id', $wsId)->update([
            'llms_txt_cache' => $out,
            'llms_txt_regen_at' => now(),
            'updated_at' => now(),
        ]);

        return $out;
    }

    /**
     * Get llms.txt content, regenerating if cache is missing or stale (> 24h).
     */
    public function getLlmsTxt(int $wsId): string
    {
        $settings = $this->get($wsId);
        $cache = $settings['llms_txt_cache'] ?? null;
        $regenAt = $settings['llms_txt_regen_at'] ?? null;

        $stale = !$regenAt || \Carbon\Carbon::parse($regenAt)->lt(now()->subHours(24));

        if (!$cache || $stale) {
            return $this->regenerateLlmsTxt($wsId);
        }
        return $cache;
    }

    public static function crawlerLabels(): array
    {
        return self::ALL_CRAWLERS;
    }

    private function oneLine(string $s): string
    {
        return trim(preg_replace('/\s+/', ' ', $s));
    }
}
