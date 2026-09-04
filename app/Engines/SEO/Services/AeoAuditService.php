<?php

namespace App\Engines\SEO\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Wave 44 — AEO (Answer Engine Optimization) audit service.
 *
 * Reads public pages and scores them on signals that LLM-based search
 * engines (ChatGPT search, Perplexity, Claude web search, Google AI
 * Overviews, Bing Copilot) use to decide what to cite.
 *
 * Read-only — no LLM calls, no credits charged. Designed to surface
 * gaps that AEO Mode (Wave 45 enrichment) would fill.
 *
 * Scoring is weighted to 100 across 12 checks. Each check returns a
 * pass/fail plus a one-line fix hint that the UI can render verbatim.
 */
class AeoAuditService
{
    // Wave 84 — labels only. Weight matrix moved to runtime (proprietary).
    private const CHECKS = [
        'article_jsonld'        => ['label' => 'Article JSON-LD schema'],
        'faqpage_jsonld'        => ['label' => 'FAQPage JSON-LD schema'],
        'tldr_at_top'           => ['label' => 'TLDR / answer in first 300 chars'],
        'ai_crawlers_allowed'   => ['label' => 'robots.txt allows AI crawlers'],
        'llms_txt_present'      => ['label' => 'llms.txt available at site root'],
        'date_modified'         => ['label' => 'dateModified in JSON-LD'],
        'question_h2s'          => ['label' => 'At least 2 question-style H2s'],
        'lists_tables'          => ['label' => 'At least 2 lists or tables'],
        'external_citation'     => ['label' => 'At least 1 external citation link'],
        'images_with_alt'       => ['label' => 'All images have alt text'],
        'meta_description_len' => ['label' => 'Meta description 120-160 chars'],
        'title_length'          => ['label' => 'Title 30-60 chars'],
    ];

    /**
     * 2026-06-22 — Build the COMPLETE AEO JSON-LD blob (Article + FAQPage) in
     * Laravel (the brain). The WP connector only echoes this — it never
     * assembles schema. Returns a schema.org @graph array ready to json_encode
     * and store on seo_content_index.aeo_jsonld_json / push to the connector.
     *
     * @param array $article The LLM-produced Article JSON-LD object.
     * @param array $faq     Array of {question, answer} pairs.
     */
    public static function buildJsonLd(array $article, array $faq): array
    {
        // Article node — drop any nested @context; we set it once at the top.
        unset($article['@context']);
        $graph = [$article];

        $entities = [];
        foreach ($faq as $f) {
            if (! is_array($f)) {
                continue;
            }
            $q = isset($f['question']) ? trim((string) $f['question']) : '';
            $a = isset($f['answer']) ? trim((string) $f['answer']) : '';
            if ($q === '' || $a === '') {
                continue;
            }
            $entities[] = [
                '@type'          => 'Question',
                'name'           => $q,
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $a],
            ];
        }
        if (! empty($entities)) {
            $graph[] = ['@type' => 'FAQPage', 'mainEntity' => $entities];
        }

        return ['@context' => 'https://schema.org', '@graph' => $graph];
    }

    /**
     * Audit a single URL and persist the result. Returns the audit row
     * as an array (score, checks, errors).
     */
    public function auditPage(int $wsId, string $url): array
    {
        $started = microtime(true);

        // SEO-P2-4 (2026-09-04): SSRF guard at the AEO crawl source — refuse private/internal targets.
        if (! \App\Engines\SEO\Support\UrlGuard::isPublicHttp($url)) {
            return $this->persist($wsId, $url, [
                'score' => 0, 'checks' => [], 'http_status' => null, 'html_bytes' => 0,
                'error_text' => 'Refused: the URL points to a private or internal address.',
            ]);
        }

        try {
            $resp = Http::timeout(15)
                ->withUserAgent('LevelUpGrowth-AEO-Audit/1.0 (+https://levelupgrowth.io)')
                ->get($url);
            $html = (string) $resp->body();
            $status = $resp->status();
        } catch (\Throwable $e) {
            return $this->persist($wsId, $url, [
                'score' => 0,
                'checks' => [],
                'http_status' => null,
                'html_bytes' => 0,
                'error_text' => 'Fetch failed: ' . $e->getMessage(),
            ]);
        }

        if ($status < 200 || $status >= 400 || strlen($html) < 200) {
            return $this->persist($wsId, $url, [
                'score' => 0,
                'checks' => [],
                'http_status' => $status,
                'html_bytes' => strlen($html),
                'error_text' => "HTTP {$status} or response too small",
            ]);
        }

        // robots.txt + llms.txt checks are site-level — derive origin from URL.
        $origin = $this->originOf($url);
        $robotsBody  = $this->fetchOptional($origin . '/robots.txt');
        $llmsTxtBody = $this->fetchOptional($origin . '/llms.txt');

        $checks = [
            'article_jsonld'        => $this->checkJsonLdType($html, 'Article'),
            'faqpage_jsonld'        => $this->checkJsonLdType($html, 'FAQPage'),
            'tldr_at_top'           => $this->checkTldr($html),
            'ai_crawlers_allowed'   => $this->checkRobotsAllowsAi($robotsBody),
            'llms_txt_present'     => $this->checkLlmsTxt($llmsTxtBody),
            'date_modified'         => $this->checkJsonLdHasField($html, 'dateModified'),
            'question_h2s'          => $this->checkQuestionH2s($html),
            'lists_tables'          => $this->checkListsTables($html),
            'external_citation'     => $this->checkExternalCitation($html, $origin),
            'images_with_alt'       => $this->checkImagesAlt($html),
            'meta_description_len'  => $this->checkMetaDescriptionLength($html),
            'title_length'          => $this->checkTitleLength($html),
        ];

        $score = $this->computeScore($checks);

        return $this->persist($wsId, $url, [
            'score' => $score,
            'checks' => $checks,
            'http_status' => $status,
            'html_bytes' => strlen($html),
            'error_text' => null,
        ]);
    }

    /**
     * Wave 81 — public router for AEO score computation. The 12-check
     * weight matrix is proprietary IP and must route through runtime
     * when INTELLIGENCE_VIA_RUNTIME is enabled.
     */
    private function computeScore(array $checks): int
    {
        // Wave 84 — runtime canonical. Safe default: 0 if runtime unreachable.
        $result = app(\App\Connectors\RuntimeClient::class)->aeoComputeScore($checks);
        return $result ?? 0;
    }

    /**
     * Audit every URL in seo_content_index for the workspace.
     * Returns the count of pages audited. Intended to be called from
     * a queued job for large workspaces.
     */
    public function auditAllIndexed(int $wsId): int
    {
        $rows = DB::table('seo_content_index')
            ->where('workspace_id', $wsId)
            ->whereNotNull('url')
            ->orderByDesc('authority_score')
            ->limit(100)
            ->pluck('url');

        $count = 0;
        foreach ($rows as $url) {
            try {
                $this->auditPage($wsId, $url);
                $count++;
            } catch (\Throwable $e) {
                Log::warning('[AeoAudit] auditPage failed', ['url' => $url, 'error' => $e->getMessage()]);
            }
        }
        return $count;
    }

    // ─── Persistence ──────────────────────────────────────────────────

    private function persist(int $wsId, string $url, array $result): array
    {
        $existing = DB::table('aeo_audits')
            ->where('workspace_id', $wsId)
            ->where('url', $url)
            ->first(['id']);

        $row = [
            'workspace_id'    => $wsId,
            'url'             => $url,
            'score'           => $result['score'] ?? 0,
            'checks_json'     => json_encode($result['checks'] ?? []),
            'http_status'     => $result['http_status'] ?? null,
            'html_bytes'      => $result['html_bytes'] ?? null,
            'error_text'      => $result['error_text'] ?? null,
            'last_audited_at' => now(),
            'updated_at'      => now(),
        ];

        if ($existing) {
            DB::table('aeo_audits')->where('id', $existing->id)->update($row);
            $auditId = $existing->id;
        } else {
            $row['created_at'] = now();
            $auditId = DB::table('aeo_audits')->insertGetId($row);
        }

        return [
            'id' => $auditId,
            'url' => $url,
            'score' => $row['score'],
            'checks' => $result['checks'] ?? [],
            'http_status' => $row['http_status'],
            'error_text' => $row['error_text'],
            'last_audited_at' => $row['last_audited_at']->toIso8601String(),
        ];
    }

    // ─── Checks ───────────────────────────────────────────────────────

    private function checkJsonLdType(string $html, string $type): array
    {
        preg_match_all('#<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $m);
        foreach ($m[1] ?? [] as $blob) {
            $parsed = json_decode(trim($blob), true);
            if (!is_array($parsed)) continue;
            // Could be a single object or a @graph array
            $items = isset($parsed['@graph']) && is_array($parsed['@graph']) ? $parsed['@graph'] : [$parsed];
            foreach ($items as $item) {
                $t = $item['@type'] ?? null;
                if (is_array($t) ? in_array($type, $t, true) : $t === $type) {
                    return ['pass' => true, 'fix' => null];
                }
            }
        }
        $fix = $type === 'Article'
            ? 'Add Article JSON-LD with headline, author, datePublished, dateModified. Wave 45 (AEO Mode) does this automatically.'
            : 'Add a FAQPage JSON-LD with 3-5 Q&A pairs at the bottom of the article.';
        return ['pass' => false, 'fix' => $fix];
    }

    private function checkJsonLdHasField(string $html, string $field): array
    {
        preg_match_all('#<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $m);
        foreach ($m[1] ?? [] as $blob) {
            if (stripos($blob, "\"{$field}\"") !== false) {
                return ['pass' => true, 'fix' => null];
            }
        }
        return ['pass' => false, 'fix' => "Add {$field} to your Article JSON-LD so LLMs know the content's freshness."];
    }

    private function checkTldr(string $html): array
    {
        // Strip everything before the first H1, then take the next 600 chars
        // and look for a clean paragraph that reads like an answer (not an intro).
        $body = preg_replace('#.*?<h1[^>]*>.*?</h1>#is', '', $html, 1);
        if ($body === $html) {
            // No H1 found — fall back to taking the body after </header>
            $body = preg_replace('#.*?</header>#is', '', $html, 1);
        }
        $body = preg_replace('#<(script|style)[^>]*>.*?</\\1>#is', ' ', $body ?? '');
        $body = strip_tags($body);
        $body = trim(preg_replace('/\s+/', ' ', $body));
        $first300 = mb_substr($body, 0, 300);
        $wordCount = str_word_count($first300);
        // Pass if we found 30-100 words of clean text right after the H1
        // (typical TLDR length is 50-100 words).
        $pass = $wordCount >= 30 && $wordCount <= 120;
        return [
            'pass' => $pass,
            'fix' => $pass ? null : 'Add a 50-100 word TLDR answer immediately after your H1. LLMs cite the first 300 chars most often.',
        ];
    }

    private function checkRobotsAllowsAi(?string $robots): array
    {
        if ($robots === null) {
            return ['pass' => false, 'fix' => 'No robots.txt found. Add one with explicit Allow rules for GPTBot, ClaudeBot, PerplexityBot.'];
        }
        // Permissive default: if there's no explicit User-agent: * Disallow: /,
        // and no explicit Disallow for the AI crawlers, we count it as allowed.
        $aiBots = ['GPTBot', 'ClaudeBot', 'anthropic-ai', 'PerplexityBot', 'Bytespider', 'Google-Extended'];
        $blockedBots = [];
        foreach ($aiBots as $bot) {
            if (preg_match('#User-agent:\s*' . preg_quote($bot, '#') . '\s*\n\s*Disallow:\s*/#i', $robots)) {
                $blockedBots[] = $bot;
            }
        }
        if (preg_match('#User-agent:\s*\*\s*\n\s*Disallow:\s*/#i', $robots)) {
            return ['pass' => false, 'fix' => 'robots.txt blocks all crawlers with Disallow: /. Allow at least the major AI crawlers.'];
        }
        if (!empty($blockedBots)) {
            return ['pass' => false, 'fix' => 'robots.txt blocks: ' . implode(', ', $blockedBots) . '. Remove the Disallow lines or migrate to per-tenant control.'];
        }
        return ['pass' => true, 'fix' => null];
    }

    private function checkLlmsTxt(?string $body): array
    {
        $pass = $body !== null && strlen($body) > 50;
        return [
            'pass' => $pass,
            'fix' => $pass ? null : 'Add an llms.txt file at the site root listing your canonical pages. This is the emerging spec adopted by Anthropic, Stripe, Mintlify.',
        ];
    }

    private function checkQuestionH2s(string $html): array
    {
        preg_match_all('#<h2[^>]*>(.*?)</h2>#is', $html, $m);
        $questions = 0;
        foreach ($m[1] ?? [] as $h2) {
            $t = trim(strip_tags($h2));
            // Question heuristic: contains '?' OR starts with a question word
            if (str_contains($t, '?') || preg_match('/^(how|what|why|when|where|who|which|is|are|can|do|does|should|will)\b/i', $t)) {
                $questions++;
            }
        }
        return [
            'pass' => $questions >= 2,
            'fix' => $questions >= 2 ? null : "Rewrite at least 2 H2 headings as natural questions (How does X work? Why is Y important?). Currently found: {$questions}.",
        ];
    }

    private function checkListsTables(string $html): array
    {
        $lists = preg_match_all('#<(ul|ol|table)\b#i', $html);
        return [
            'pass' => $lists >= 2,
            'fix' => $lists >= 2 ? null : "Add at least 2 lists or comparison tables. LLMs extract these verbatim. Currently found: {$lists}.",
        ];
    }

    private function checkExternalCitation(string $html, string $origin): array
    {
        $originHost = strtolower(parse_url($origin, PHP_URL_HOST) ?: '');
        preg_match_all('#href=["\'](https?://[^"\']+)["\']#i', $html, $m);
        foreach ($m[1] ?? [] as $href) {
            $host = strtolower(parse_url($href, PHP_URL_HOST) ?: '');
            if ($host && $host !== $originHost && !str_ends_with($host, '.' . $originHost)) {
                return ['pass' => true, 'fix' => null];
            }
        }
        return ['pass' => false, 'fix' => 'Add at least one citation to an authoritative external source. LLMs trust pages that cite their sources.'];
    }

    private function checkImagesAlt(string $html): array
    {
        preg_match_all('#<img\b[^>]*>#i', $html, $m);
        $total = count($m[0] ?? []);
        if ($total === 0) return ['pass' => true, 'fix' => null];
        $missing = 0;
        foreach ($m[0] as $imgTag) {
            if (!preg_match('/\balt\s*=\s*["\'][^"\']+["\']/', $imgTag)) {
                $missing++;
            }
        }
        return [
            'pass' => $missing === 0,
            'fix' => $missing === 0 ? null : "{$missing} of {$total} images missing alt text. Required for AI image-text retrieval.",
        ];
    }

    private function checkMetaDescriptionLength(string $html): array
    {
        if (!preg_match('#<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']*)["\']#i', $html, $m)) {
            return ['pass' => false, 'fix' => 'Missing meta description. Add one between 120-160 chars summarizing the article.'];
        }
        $len = mb_strlen($m[1]);
        $pass = $len >= 120 && $len <= 160;
        return [
            'pass' => $pass,
            'fix' => $pass ? null : "Meta description is {$len} chars; should be 120-160 for optimal display in search snippets.",
        ];
    }

    private function checkTitleLength(string $html): array
    {
        if (!preg_match('#<title>(.*?)</title>#is', $html, $m)) {
            return ['pass' => false, 'fix' => 'Missing <title> tag.'];
        }
        $len = mb_strlen(trim(strip_tags($m[1])));
        $pass = $len >= 30 && $len <= 60;
        return [
            'pass' => $pass,
            'fix' => $pass ? null : "Title is {$len} chars; should be 30-60 for optimal search display.",
        ];
    }

    // ─── Helpers ──────────────────────────────────────────────────────


    private function originOf(string $url): string
    {
        $p = parse_url($url);
        if (!$p || empty($p['host'])) return $url;
        return ($p['scheme'] ?? 'https') . '://' . $p['host'];
    }

    private function fetchOptional(string $url): ?string
    {
        try {
            $r = Http::timeout(8)
                ->withUserAgent('LevelUpGrowth-AEO-Audit/1.0')
                ->get($url);
            if ($r->status() >= 200 && $r->status() < 400) {
                return (string) $r->body();
            }
        } catch (\Throwable $e) {
            // ignore — treat as absent
        }
        return null;
    }
}
