<?php

namespace App\Engines\SEO\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Wave 48 — AEO Traffic Logger.
 *
 * Detects AI-crawler User-Agent strings and AI-referrer Referer headers on
 * tenant page requests. Inserts a row to `aeo_traffic` per detected event.
 *
 * Fail-open by design: any logging error is swallowed so we never block
 * a page render. Logged at WARNING for diagnosis.
 */
class AeoTrafficLogger
{
    /**
     * AI crawler User-Agent fingerprints. Match by substring (case-insensitive).
     * Order matters: most specific first to avoid false positives (e.g.,
     * Google-Extended must match before generic Googlebot patterns).
     */
    private const CRAWLERS = [
        'GPTBot'           => 'GPTBot',
        'ChatGPT-User'     => 'ChatGPT-User',
        'OAI-SearchBot'    => 'OAI-SearchBot',
        'ClaudeBot'        => 'ClaudeBot',
        'anthropic-ai'     => 'anthropic-ai',
        'Claude-Web'       => 'Claude-Web',
        'PerplexityBot'    => 'PerplexityBot',
        'Perplexity-User'  => 'Perplexity-User',
        'Google-Extended'  => 'Google-Extended',
        'Bytespider'       => 'Bytespider',
        'Amazonbot'        => 'Amazonbot',
        'CCBot'            => 'CCBot',
        'cohere-ai'        => 'cohere-ai',
        'YouBot'           => 'YouBot',
        'PhindBot'         => 'PhindBot',
        'Diffbot'          => 'Diffbot',
        'FacebookBot'      => 'FacebookBot',
        'Applebot-Extended'=> 'Applebot-Extended',
        // Bingbot is included because it powers Bing Copilot retrieval
        'Bingbot'          => 'Bingbot',
    ];

    /**
     * AI-referrer hostnames. Hostname extracted from Referer; case-insensitive.
     */
    private const AI_REFERRERS = [
        'chatgpt.com'              => 'chatgpt.com',
        'chat.openai.com'          => 'chat.openai.com',
        'perplexity.ai'            => 'perplexity.ai',
        'www.perplexity.ai'        => 'perplexity.ai',  // normalize
        'claude.ai'                => 'claude.ai',
        'copilot.microsoft.com'    => 'copilot.microsoft.com',
        'gemini.google.com'        => 'gemini.google.com',
        'you.com'                  => 'you.com',
        'www.you.com'              => 'you.com',
        'phind.com'                => 'phind.com',
        'www.phind.com'            => 'phind.com',
        'duckduckgo.com'           => 'duckduckgo.com',  // DuckAssist
    ];

    /**
     * Inspect a request and log a row if it matches an AI crawler or referrer.
     * Workspace + website are resolved by the caller (PublishedSiteMiddleware).
     */
    public function log(Request $request, int $workspaceId, ?int $websiteId, string $url): void
    {
        try {
            $ua = (string) $request->header('User-Agent', '');
            $referer = (string) $request->header('Referer', '');
            $ip = (string) ($request->header('CF-Connecting-IP') ?? $request->ip() ?? '');

            $crawler = $this->detectCrawler($ua);
            $referralSource = $this->detectReferrer($referer);

            if (!$crawler && !$referralSource) {
                return; // not an AI signal — skip
            }

            $row = [
                'workspace_id' => $workspaceId,
                'website_id'   => $websiteId,
                'url'          => mb_substr($url, 0, 2048),
                'user_agent'   => mb_substr($ua, 0, 500),
                'referer'      => $referer ? mb_substr($referer, 0, 500) : null,
                'ip'           => $ip ? mb_substr($ip, 0, 45) : null,
                'created_at'   => now(),
            ];

            if ($crawler) {
                DB::table('aeo_traffic')->insert(array_merge($row, [
                    'type'   => 'crawler',
                    'source' => $crawler,
                ]));
            }

            if ($referralSource) {
                DB::table('aeo_traffic')->insert(array_merge($row, [
                    'type'   => 'referral',
                    'source' => $referralSource,
                ]));
            }
        } catch (\Throwable $e) {
            // Fail open — never block the page render on logging issues.
            Log::warning('[AeoTrafficLogger] log failed', [
                'error' => $e->getMessage(),
                'ws'    => $workspaceId,
            ]);
        }
    }

    private function detectCrawler(string $ua): ?string
    {
        if ($ua === '') return null;
        foreach (self::CRAWLERS as $pattern => $label) {
            if (stripos($ua, $pattern) !== false) {
                return $label;
            }
        }
        return null;
    }

    private function detectReferrer(string $referer): ?string
    {
        if ($referer === '') return null;
        $host = strtolower((string) parse_url($referer, PHP_URL_HOST));
        if ($host === '') return null;
        if (str_starts_with($host, 'www.')) $host = substr($host, 4);
        return self::AI_REFERRERS[$host] ?? null;
    }

    /**
     * Build a dashboard summary for a workspace over the last N days.
     * Returns counts grouped by type + source, plus a daily series for trends.
     */
    public function summary(int $workspaceId, int $days = 30): array
    {
        $since = now()->subDays($days);

        $bySource = DB::table('aeo_traffic')
            ->where('workspace_id', $workspaceId)
            ->where('created_at', '>=', $since)
            ->selectRaw('type, source, COUNT(*) as cnt')
            ->groupBy('type', 'source')
            ->orderByDesc('cnt')
            ->get();

        $crawlerTotal = (int) $bySource->where('type', 'crawler')->sum('cnt');
        $referralTotal = (int) $bySource->where('type', 'referral')->sum('cnt');

        // Daily series for trends
        $daily = DB::table('aeo_traffic')
            ->where('workspace_id', $workspaceId)
            ->where('created_at', '>=', $since)
            ->selectRaw('DATE(created_at) as day, type, COUNT(*) as cnt')
            ->groupBy('day', 'type')
            ->orderBy('day')
            ->get();

        $topPages = DB::table('aeo_traffic')
            ->where('workspace_id', $workspaceId)
            ->where('created_at', '>=', $since)
            ->selectRaw('url, COUNT(*) as cnt')
            ->groupBy('url')
            ->orderByDesc('cnt')
            ->limit(10)
            ->get();

        return [
            'window_days' => $days,
            'crawler_total' => $crawlerTotal,
            'referral_total' => $referralTotal,
            'crawler_by_source' => $bySource->where('type', 'crawler')->values()->map(fn($r) => [
                'source' => $r->source, 'count' => (int) $r->cnt,
            ]),
            'referral_by_source' => $bySource->where('type', 'referral')->values()->map(fn($r) => [
                'source' => $r->source, 'count' => (int) $r->cnt,
            ]),
            'daily_series' => $daily->groupBy('day')->map(function ($day) {
                return [
                    'crawlers'  => (int) $day->where('type', 'crawler')->sum('cnt'),
                    'referrals' => (int) $day->where('type', 'referral')->sum('cnt'),
                ];
            })->toArray(),
            'top_pages' => $topPages->map(fn($p) => [
                'url' => $p->url, 'count' => (int) $p->cnt,
            ]),
        ];
    }
}
