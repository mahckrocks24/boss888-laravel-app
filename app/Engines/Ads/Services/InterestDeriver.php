<?php

namespace App\Engines\Ads\Services;

use App\Engines\Ads\Support\AdInterestTaxonomy;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * InterestDeriver — derives CONTEXTUAL interests for a website.
 *
 * ══════════════════════════════════════════════════════════════════════════
 * EVERY SOURCE BELOW IS A PROPERTY OF THE PUBLISHED SITE.
 * None of them observe a visitor. This is a hard boundary, not a style
 * preference — see the docblock on AdInterestTaxonomy and clause 9 of the
 * Free-plan advertising terms. Adding a visitor-derived signal here would
 * falsify a published legal term and require consent management across every
 * tenant site.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * SOURCES, cheapest first
 *   1. Industry defaults        the floor every site gets from its industry
 *   2. seo_keywords             topics the site targets; `cpc` doubles as a
 *                               commercial-intent signal worth surfacing
 *   3. workspace_memory         owner-stated services / target audience /
 *                               differentiators
 *   4. websites.template_variables  what the site's own copy actually says
 *   5. article titles           topic drift over time (a chef site that blogs
 *                               about athlete nutrition is also nutrition
 *                               inventory)
 *
 * RESILIENCE
 * Each source is independently guarded. A missing table or malformed JSON
 * degrades that one signal and never aborts profiling — a site with no SEO
 * keywords should still get its industry defaults, not an exception.
 */
final class InterestDeriver
{
    /**
     * Distinct keyword hits required before a TEXT signal may promote an interest.
     *
     * Set to 2 deliberately. At 1, a single incidental word promoted a whole
     * interest — the chef site at website_id=3 picked up `seo_search` and
     * `recruitment_hr` because its copy happened to contain one matching term
     * each. Noise in this field is not cosmetic: it is what an advertiser buys,
     * so a spurious interest sells them an audience that is not there.
     *
     * Industry defaults are exempt — they are a deliberate statement about what
     * the site is for, not an incidental mention.
     */
    private const MIN_HITS_FOR_DERIVED_INTEREST = 2;

    /** Cap stored interests so a profile stays legible and targetable. */
    private const MAX_INTERESTS = 12;

    /** Cap stored topical phrases. Descriptive only — never targetable. */
    private const MAX_CONTENT_TOPICS = 30;

    /**
     * @return array{
     *   interests: array<int,string>,
     *   content_topics: array<int,string>,
     *   evidence: array<string,mixed>
     * }
     */
    public function derive(int $websiteId, int $workspaceId, ?string $industrySlug): array
    {
        $evidence  = [];
        $scores    = [];
        $topics    = [];

        // ── 1. Industry defaults — the floor ────────────────────────────
        $defaults = AdInterestTaxonomy::defaultsForIndustry($industrySlug);
        foreach ($defaults as $code) {
            // Weighted above incidental text mentions: the industry is the
            // strongest single statement about what a site is for.
            $scores[$code] = ($scores[$code] ?? 0) + 5;
        }
        $evidence['industry_defaults'] = $defaults;

        // ── 2. SEO keywords ─────────────────────────────────────────────
        $keywordText = '';
        try {
            $rows = DB::table('seo_keywords')
                ->where('workspace_id', $workspaceId)
                ->orderByDesc('volume')
                ->limit(200)
                ->get(['keyword', 'volume', 'cpc']);

            $commercial = [];
            foreach ($rows as $row) {
                $keyword = trim((string) ($row->keyword ?? ''));
                if ($keyword === '') {
                    continue;
                }
                $keywordText .= ' ' . $keyword;
                $topics[] = $keyword;

                if ((float) ($row->cpc ?? 0) > 0) {
                    $commercial[$keyword] = (float) $row->cpc;
                }
            }

            arsort($commercial);
            $evidence['seo_keywords_count'] = count($rows);
            // `cpc` is what advertisers already pay for these topics elsewhere —
            // the single best available proxy for what this inventory is worth.
            $evidence['top_commercial_keywords'] = array_slice($commercial, 0, 5, true);
        } catch (Throwable $e) {
            $evidence['seo_keywords_error'] = $e->getMessage();
        }

        // ── 3. workspace_memory ─────────────────────────────────────────
        $memoryText = '';
        try {
            $memory = DB::table('workspace_memory')
                ->where('workspace_id', $workspaceId)
                ->whereIn('key', ['services', 'target_audience', 'differentiators', 'goal', 'tone'])
                ->pluck('value_json', 'key')
                ->toArray();

            foreach ($memory as $key => $value) {
                $memoryText .= ' ' . $this->flattenJsonish($value);
            }

            $evidence['workspace_memory_keys'] = array_keys($memory);
        } catch (Throwable $e) {
            $evidence['workspace_memory_error'] = $e->getMessage();
        }

        // ── 4. The site's own copy ──────────────────────────────────────
        $copyText = '';
        try {
            $row = DB::table('websites')->where('id', $websiteId)->first(['template_variables']);
            $vars = $this->decodeJson($row->template_variables ?? null);

            if (is_array($vars)) {
                $interestingKeys = 0;
                foreach ($vars as $key => $value) {
                    if (! is_string($key) || ! is_scalar($value)) {
                        continue;
                    }
                    // Copy fields that describe the business, not layout or colour.
                    if (preg_match('/^(services?|hero|meta_description|about|business_tagline|specialt|programs?|menu|amenities|experiences?)/i', $key)) {
                        $copyText .= ' ' . (string) $value;
                        $interestingKeys++;
                    }
                }
                $evidence['template_variable_fields_read'] = $interestingKeys;
            }
        } catch (Throwable $e) {
            $evidence['template_variables_error'] = $e->getMessage();
        }

        // ── 5. Article titles — topic drift ─────────────────────────────
        $articleText = '';
        try {
            $titles = DB::table('articles')
                ->where('workspace_id', $workspaceId)
                ->orderByDesc('id')
                ->limit(50)
                ->pluck('title');

            foreach ($titles as $title) {
                $title = trim((string) $title);
                if ($title === '') {
                    continue;
                }
                $articleText .= ' ' . $title;
                $topics[] = $title;
            }

            $evidence['article_titles_read'] = count($titles);
        } catch (Throwable $e) {
            $evidence['articles_error'] = $e->getMessage();
        }

        // ── Score all text signals against the controlled vocabulary ────
        foreach ([
            'seo_keywords'       => $keywordText,
            'workspace_memory'   => $memoryText,
            'site_copy'          => $copyText,
            'article_titles'     => $articleText,
        ] as $label => $text) {
            if (trim($text) === '') {
                continue;
            }

            $hits = AdInterestTaxonomy::scoreText($text);
            foreach ($hits as $code => $count) {
                if ($count >= self::MIN_HITS_FOR_DERIVED_INTEREST) {
                    $scores[$code] = ($scores[$code] ?? 0) + $count;
                }
            }

            if ($hits !== []) {
                $evidence['signals'][$label] = array_slice($hits, 0, 5, true);
            }
        }

        arsort($scores);

        $interests = array_slice(array_keys($scores), 0, self::MAX_INTERESTS);

        // Defensive: only ever emit codes that exist in the vocabulary. A code
        // that is not in the taxonomy is not targetable and must not be stored.
        $interests = array_values(array_filter(
            $interests,
            static fn (string $code) => AdInterestTaxonomy::isValid($code)
        ));

        return [
            'interests'      => $interests,
            'content_topics' => $this->cleanTopics($topics),
            'evidence'       => $evidence,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────

    /**
     * workspace_memory stores JSON-encoded scalars and arrays alike. Values in
     * the wild include `"private chef"` (a quoted string) and `["a","b"]`.
     * Flatten either into plain searchable text.
     */
    private function flattenJsonish(mixed $value): string
    {
        if (! is_string($value)) {
            return is_scalar($value) ? (string) $value : '';
        }

        $decoded = json_decode($value, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return trim($value, "\" \t\n\r\0\x0B");
        }

        if (is_scalar($decoded)) {
            return (string) $decoded;
        }

        if (is_array($decoded)) {
            $out = '';
            array_walk_recursive($decoded, static function ($leaf) use (&$out) {
                if (is_scalar($leaf)) {
                    $out .= ' ' . $leaf;
                }
            });

            return $out;
        }

        return '';
    }

    private function decodeJson(mixed $value): mixed
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    /** @param array<int,string> $topics @return array<int,string> */
    private function cleanTopics(array $topics): array
    {
        $clean = [];

        foreach ($topics as $topic) {
            $topic = trim(preg_replace('/\s+/', ' ', strip_tags((string) $topic)) ?? '');

            if ($topic === '' || mb_strlen($topic) > 120) {
                continue;
            }

            $clean[mb_strtolower($topic)] = $topic;
        }

        return array_slice(array_values($clean), 0, self::MAX_CONTENT_TOPICS);
    }
}
