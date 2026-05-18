<?php

namespace App\Services\SeoOptimization;

use Illuminate\Support\Facades\DB;

/**
 * FocusKeywordDetector
 *
 * Deterministic, AI-free, 0-credit detection of a page's primary focus
 * keyword. Runs on every row open for ALL tiers (paid + free), since the
 * detected keyword informs both AI optimization (Growth+) and manual
 * editing (free).
 *
 * Precedence:
 *   1. seo_keywords table — highest-volume keyword whose target_url
 *      matches the page URL exactly. Most authoritative signal because
 *      the user explicitly told us this keyword matters.
 *   2. H1 phrase extraction — 2-3 word phrase, stop-words stripped.
 *   3. Title phrase extraction — same algorithm, on meta_title or title.
 *   4. URL slug phrase extraction — last-resort.
 *   5. If nothing extractable → needs_user_input = true.
 *
 * Returns:
 *   primary_keyword:   string
 *   source:            'tracked_keywords' | 'h1_extract' | 'title_extract' | 'slug_extract' | 'none'
 *   alternatives:      string[]  (2-3 candidates the user can switch to)
 *   confidence:        0.0..1.0  (tracked=1.0, h1=0.7, title=0.5, slug=0.3, none=0)
 *   needs_user_input:  bool
 */
class FocusKeywordDetector
{
    private const STOP_WORDS = [
        'a', 'an', 'and', 'are', 'as', 'at', 'be', 'but', 'by', 'for',
        'from', 'has', 'have', 'in', 'is', 'it', 'its', 'of', 'on', 'or',
        'the', 'to', 'was', 'were', 'will', 'with', 'this', 'that', 'these',
        'those', 'we', 'our', 'us', 'you', 'your', 'i', 'me', 'my',
        'how', 'what', 'why', 'when', 'where', 'who', 'which',
        'best', 'top', 'guide', 'tips', 'about', 'page', 'blog', 'home',
        'all', 'any', 'some', 'more', 'less', 'most', 'least',
    ];

    public function detect(int $wsId, int $pageId): array
    {
        $page = DB::table('seo_content_index')
            ->where('workspace_id', $wsId)
            ->where('id', $pageId)
            ->first(['id', 'url', 'title', 'meta_title', 'h1']);

        if (! $page) {
            return $this->emptyResult('page_not_found');
        }

        // 1. Tracked keywords (highest priority)
        $tracked = DB::table('seo_keywords')
            ->where('workspace_id', $wsId)
            ->where('target_url', $page->url)
            ->orderByDesc('volume')
            ->limit(5)
            ->get(['keyword', 'volume']);

        if ($tracked->isNotEmpty()) {
            $primary = (string) $tracked->first()->keyword;
            $alternatives = $tracked->slice(1)->pluck('keyword')->values()->toArray();
            return [
                'primary_keyword'  => $primary,
                'source'           => 'tracked_keywords',
                'alternatives'     => $alternatives,
                'confidence'       => 1.0,
                'needs_user_input' => false,
            ];
        }

        // 2. H1 phrase extraction
        $h1Phrase = $this->extractPhrase((string) $page->h1);
        if ($h1Phrase !== '') {
            return [
                'primary_keyword'  => $h1Phrase,
                'source'           => 'h1_extract',
                'alternatives'     => array_values(array_filter([
                    $this->extractPhrase((string) ($page->meta_title ?? $page->title)),
                    $this->extractSlug((string) $page->url),
                ], fn ($p) => $p !== '' && $p !== $h1Phrase)),
                'confidence'       => 0.7,
                'needs_user_input' => false,
            ];
        }

        // 3. Title phrase extraction
        $titlePhrase = $this->extractPhrase((string) ($page->meta_title ?? $page->title));
        if ($titlePhrase !== '') {
            return [
                'primary_keyword'  => $titlePhrase,
                'source'           => 'title_extract',
                'alternatives'     => array_values(array_filter([
                    $this->extractSlug((string) $page->url),
                ], fn ($p) => $p !== '' && $p !== $titlePhrase)),
                'confidence'       => 0.5,
                'needs_user_input' => false,
            ];
        }

        // 4. URL slug phrase extraction
        $slugPhrase = $this->extractSlug((string) $page->url);
        if ($slugPhrase !== '') {
            return [
                'primary_keyword'  => $slugPhrase,
                'source'           => 'slug_extract',
                'alternatives'     => [],
                'confidence'       => 0.3,
                'needs_user_input' => false,
            ];
        }

        // 5. Nothing detectable
        return $this->emptyResult('no_signal');
    }

    /**
     * Extract a 2-3 word phrase from text, stop-words stripped, lower-cased.
     * Returns '' if nothing usable.
     */
    private function extractPhrase(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        // Tokenize on whitespace + punctuation
        $tokens = preg_split('/[\s\p{P}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        if (! is_array($tokens)) {
            return '';
        }

        // Strip stop words + 1-char tokens + pure digits
        $kept = array_values(array_filter($tokens, function ($t) {
            return mb_strlen($t) > 2
                && ! in_array($t, self::STOP_WORDS, true)
                && ! preg_match('/^\d+$/', $t);
        }));

        if (empty($kept)) {
            return '';
        }

        // Take up to 3 leading tokens — heuristic: most pages name their
        // primary subject in the first few non-stopword tokens.
        $phrase = implode(' ', array_slice($kept, 0, 3));
        return $phrase;
    }

    /**
     * Extract phrase from URL slug. Splits on hyphens / underscores / slashes.
     */
    private function extractSlug(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $slug = trim($path, '/');
        if ($slug === '') {
            return '';
        }
        // Take the LAST path segment (usually the most specific)
        $segments = explode('/', $slug);
        $last     = end($segments);
        // Convert hyphens/underscores to spaces
        $words = preg_replace('/[-_]+/', ' ', (string) $last);
        return $this->extractPhrase((string) $words);
    }

    private function emptyResult(string $reason): array
    {
        return [
            'primary_keyword'  => '',
            'source'           => 'none',
            'alternatives'     => [],
            'confidence'       => 0.0,
            'needs_user_input' => true,
            'reason'           => $reason,
        ];
    }
}
