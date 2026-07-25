<?php

namespace App\Engines\Mention\Services;

use App\Connectors\RuntimeClient;
use Illuminate\Support\Facades\Log;

/**
 * MentionSentimentService — classify excerpts as positive / neutral /
 * negative / mixed / unknown.
 *
 * Two-stage:
 *   1. Rule-based fast path — cheap lexicon match. Returns a confident
 *      verdict for ~40-60% of common cases (clear praise, clear complaint,
 *      review-rating language). No credits, no LLM cost.
 *   2. LLM fallback via runtime.chatJson — for ambiguous excerpts where
 *      rule-based confidence is below threshold. Structured JSON output.
 *
 * Stateless. Each call is independent. Caller (MentionScanService)
 * decides whether to invoke and persists the result.
 */
class MentionSentimentService
{
    public const SENTIMENTS = ['positive', 'neutral', 'negative', 'mixed', 'unknown'];

    private const POSITIVE_TERMS = [
        // praise verbs / adjectives
        'love', 'loved', 'amazing', 'excellent', 'great', 'awesome', 'fantastic',
        'wonderful', 'perfect', 'best', 'recommend', 'highly recommend', 'impressed',
        'thrilled', 'happy', 'satisfied', 'helpful', 'fast', 'reliable', 'professional',
        // review signals
        '5 star', 'five star', '5/5', '⭐⭐⭐⭐⭐', 'rated 5',
        // recommendation phrasing
        'would recommend', 'worth it', 'go-to', 'top notch', 'top-notch',
    ];

    private const NEGATIVE_TERMS = [
        'terrible', 'awful', 'horrible', 'worst', 'scam', 'fraud', 'rip off',
        'rip-off', 'ripoff', 'avoid', 'do not buy', 'don\'t buy', 'never again',
        'complaint', 'complain', 'disappointed', 'disappointing', 'broken',
        'doesn\'t work', 'does not work', 'waste of money', 'unprofessional',
        'rude', 'slow', 'unreliable', 'sketchy',
        // review signals
        '1 star', 'one star', '1/5', '2/5',
        // legal/risk
        'lawsuit', 'sued', 'class action',
    ];

    private const MIXED_TERMS = [
        'but', 'however', 'although', 'though', 'mixed feelings',
        'pros and cons', 'on the other hand',
    ];

    public function __construct(private readonly RuntimeClient $runtime) {}

    /**
     * Classify a single excerpt.
     *
     * Returns:
     *   ['sentiment' => ..., 'confidence' => 0.0..1.0, 'rationale' => '...',
     *    'method' => 'rule_based' | 'llm' | 'fallback']
     */
    public function classify(string $brandName, string $excerpt, array $opts = []): array
    {
        $text = mb_strtolower(trim($excerpt));
        if ($text === '') {
            return $this->result('unknown', 0.0, 'empty excerpt', 'fallback');
        }

        // ── Stage 1: rule-based ──────────────────────────────────────────
        $posHits = $this->countMatches($text, self::POSITIVE_TERMS);
        $negHits = $this->countMatches($text, self::NEGATIVE_TERMS);
        $mixedHits = $this->countMatches($text, self::MIXED_TERMS);

        // Confident verdicts: one side has ≥2 hits and the other has 0
        if ($posHits >= 2 && $negHits === 0 && $mixedHits === 0) {
            return $this->result('positive', 0.85, "rule-based: {$posHits} positive lexicon hits", 'rule_based');
        }
        if ($negHits >= 2 && $posHits === 0 && $mixedHits === 0) {
            return $this->result('negative', 0.85, "rule-based: {$negHits} negative lexicon hits", 'rule_based');
        }
        // Single strong hit each side → mixed
        if ($posHits >= 1 && $negHits >= 1) {
            return $this->result('mixed', 0.65, "rule-based: {$posHits} pos + {$negHits} neg", 'rule_based');
        }
        // One strong hit + mixed term → mixed
        if (($posHits >= 1 || $negHits >= 1) && $mixedHits >= 1) {
            $verdict = $posHits >= $negHits ? 'positive' : 'negative';
            return $this->result('mixed', 0.55, "rule-based: tonal markers + qualifier", 'rule_based');
        }

        // Ambiguous → either LLM (default) or short-circuit to neutral
        if (!empty($opts['skip_llm'])) {
            return $this->result('neutral', 0.40, 'rule-based: no strong markers (skip_llm)', 'rule_based');
        }

        // ── Stage 2: LLM fallback ────────────────────────────────────────
        return $this->classifyViaRuntime($brandName, $excerpt);
    }

    private function classifyViaRuntime(string $brand, string $excerpt): array
    {
        $system = "You classify sentiment of public web mentions about a brand. "
            . "Output ONLY valid JSON in this shape: "
            . '{"sentiment":"positive|neutral|negative|mixed|unknown","confidence":0.0-1.0,"rationale":"one short sentence"}. '
            . "Be conservative: when the excerpt is generic news/listicle context with no opinion, return 'neutral'. "
            . "When the excerpt is too sparse to judge, return 'unknown'.";

        $brandSafe = mb_substr($brand, 0, 100);
        $excerptSafe = mb_substr($excerpt, 0, 800);

        $user = "Brand: \"{$brandSafe}\"\n\nExcerpt:\n\"{$excerptSafe}\"\n\nClassify the sentiment of this excerpt toward the brand.";

        try {
            $result = $this->runtime->chatJson($system, $user, ['task' => 'mention_sentiment'], 200);
        } catch (\Throwable $e) {
            Log::warning('[MentionSentiment] runtime chatJson threw', ['error' => $e->getMessage()]);
            return $this->result('unknown', 0.0, 'runtime error: ' . $e->getMessage(), 'fallback');
        }

        if (empty($result['success']) || empty($result['text'])) {
            Log::warning('[MentionSentiment] runtime returned empty', [
                'error' => $result['error'] ?? null,
            ]);
            return $this->result('unknown', 0.0, 'runtime returned empty', 'fallback');
        }

        $parsed = $this->extractJson($result['text']);
        if (!is_array($parsed)) {
            Log::warning('[MentionSentiment] parse failed', [
                'snippet' => substr((string) $result['text'], 0, 200),
            ]);
            return $this->result('unknown', 0.0, 'parse failed', 'fallback');
        }

        $sent = (string) ($parsed['sentiment'] ?? 'unknown');
        if (!in_array($sent, self::SENTIMENTS, true)) $sent = 'unknown';

        $conf = $parsed['confidence'] ?? 0.5;
        if (!is_numeric($conf)) $conf = 0.5;
        $conf = max(0.0, min(1.0, (float) $conf));

        $rationale = mb_substr((string) ($parsed['rationale'] ?? ''), 0, 300);

        return $this->result($sent, $conf, $rationale, 'llm');
    }

    private function countMatches(string $haystack, array $needles): int
    {
        $count = 0;
        foreach ($needles as $n) {
            if ($n === '') continue;
            if (str_contains($haystack, $n)) $count++;
        }
        return $count;
    }

    private function extractJson(string $raw): ?array
    {
        $raw = trim($raw);
        if (str_starts_with($raw, '```')) {
            $raw = preg_replace('/^```\w*\s*/', '', $raw, 1);
            $raw = preg_replace('/\s*```\s*$/', '', $raw, 1);
        }
        // Find the first { and matching } to be defensive about LLM
        // wrapping JSON in extra prose.
        $start = strpos($raw, '{');
        $end   = strrpos($raw, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $raw = substr($raw, $start, $end - $start + 1);
        }
        $parsed = json_decode($raw, true);
        return is_array($parsed) ? $parsed : null;
    }

    private function result(string $sentiment, float $confidence, string $rationale, string $method): array
    {
        return [
            'sentiment'  => $sentiment,
            'confidence' => round($confidence, 2),
            'rationale'  => $rationale,
            'method'     => $method,
        ];
    }
}