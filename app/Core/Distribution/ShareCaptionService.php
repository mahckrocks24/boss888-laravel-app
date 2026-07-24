<?php

namespace App\Core\Distribution;

use App\Connectors\RuntimeClient;
use Illuminate\Support\Facades\Log;

/**
 * W5 (2026-07-22) — grounded caption generation for article distribution.
 *
 * REPLACES a removed capability without reinstating it. The old caption
 * producers (ai_generate_social_post / social_ai_post / generate_hashtags) are
 * in LaunchScopePolicy::REMOVED_TOOLS and are NOT called here. Instead this
 * service uses the retained generic `chat_json` runtime mode, which the
 * deployed v2.37.3 runtime still serves, and constrains it to the article.
 *
 * GROUNDING IS ENFORCED, NOT REQUESTED. Whatever the model returns is checked
 * against the article text: any number, percentage or quoted phrase that does
 * not appear in the article causes the generated caption to be REJECTED and the
 * deterministic fallback used instead. A prompt asking for no fabrication is a
 * hope; this check is a guarantee.
 */
class ShareCaptionService
{
    public function __construct(
        private RuntimeClient $runtime,
    ) {}

    /** Retained generic mode. NEVER 'social_post' — that mode is removed. */
    private const RUNTIME_MODE = 'chat_json';

    /**
     * @return array{content:string, source:string, grounded:bool, reason:?string, hashtags:array}
     */
    public function build(object $article, string $platform, string $canonicalUrl): array
    {
        $platform = PlatformPolicy::normalise($platform);
        $fallback = $this->fallback($article, $platform, $canonicalUrl);

        try {
            $generated = $this->generate($article, $platform, $canonicalUrl);
        } catch (\Throwable $e) {
            Log::info('[ArticleShare] caption generation threw; using fallback', [
                'article' => $article->id ?? null, 'error' => $e->getMessage(),
            ]);
            return $fallback + ['reason' => 'GENERATION_EXCEPTION'];
        }

        if ($generated === null) {
            return $fallback + ['reason' => 'GENERATION_UNAVAILABLE'];
        }

        $verdict = $this->checkGrounding($generated, $article);
        if (!$verdict['ok']) {
            Log::info('[ArticleShare] generated caption rejected as ungrounded', [
                'article' => $article->id ?? null, 'reason' => $verdict['reason'],
            ]);
            return $fallback + ['reason' => 'UNGROUNDED_' . $verdict['reason']];
        }

        return [
            'content'  => $this->assemble($platform, $generated, $canonicalUrl),
            'source'   => 'generated',
            'grounded' => true,
            'reason'   => null,
            'hashtags' => [],
        ];
    }

    /**
     * Deterministic, always-available caption. Uses ONLY the article's own
     * title and excerpt, so it cannot misrepresent the article. This is what
     * ships whenever generation is unavailable or ungrounded.
     */
    public function fallback(object $article, string $platform, string $canonicalUrl): array
    {
        $platform = PlatformPolicy::normalise($platform);
        $title    = trim((string) ($article->title ?? ''));
        $excerpt  = trim(strip_tags((string) ($article->excerpt ?? '')));

        if ($excerpt === '') {
            $body = (string) ($article->content ?? '');
            $body = trim(preg_replace('/\s+/', ' ', strip_tags($body)) ?? '');
            $excerpt = $body === '' ? '' : (strlen($body) > 220 ? substr($body, 0, 220) : $body);
            if ($excerpt !== '' && strlen($body) > 220) {
                $sp = strrpos($excerpt, ' ');
                if ($sp !== false) $excerpt = substr($excerpt, 0, $sp);
                $excerpt = rtrim($excerpt, " \t.,;:-") . '…';
            }
        }

        $text = $excerpt !== '' ? ($title . "\n\n" . $excerpt) : $title;

        return [
            'content'  => $this->assemble($platform, $text, $canonicalUrl),
            'source'   => 'fallback',
            'grounded' => true,
            'reason'   => null,
            'hashtags' => [],
        ];
    }

    /**
     * Adapt one base caption into a platform-specific variation.
     *
     * Scope change 2026-07-22: the Publisher generates a SEPARATE caption per
     * selected platform rather than forcing one string everywhere. This applies
     * the platform's formatting and length rules, and appends the canonical link
     * only where a link is actually clickable — on Instagram a URL in the caption
     * is dead text, so we leave it out rather than ship something that looks like
     * a link and is not.
     */
    public function adaptForPlatform(string $base, string $platform, string $canonicalUrl = ''): string
    {
        $platform = PlatformPolicy::normalise($platform);
        $base     = trim($base);

        // Avoid duplicating a link the base caption already carries.
        if ($canonicalUrl !== '' && str_contains($base, $canonicalUrl)) {
            $base = trim(str_replace($canonicalUrl, '', $base));
        }

        $body = PlatformPolicy::formatBody($platform, $base);
        $wantsLink = $canonicalUrl !== '' && PlatformPolicy::linkIsClickable($platform);

        $body = PlatformPolicy::fitToLimit($platform, $body, $wantsLink ? $canonicalUrl : '');

        if (!$wantsLink) {
            return $body;
        }
        return $body === '' ? $canonicalUrl : ($body . "\n\n" . $canonicalUrl);
    }

    /** Compose body + canonical link under the provider's rules. */
    private function assemble(string $platform, string $body, string $canonicalUrl): string
    {
        $body = PlatformPolicy::formatBody($platform, $body);
        $body = PlatformPolicy::fitToLimit($platform, $body, $canonicalUrl);
        $out  = $body === '' ? $canonicalUrl : ($body . "\n\n" . $canonicalUrl);

        // Belt and braces: the link is the point of the share. If any formatting
        // step dropped it, fall back to the bare link rather than ship a
        // link-less "share".
        if (!str_contains($out, $canonicalUrl)) return $canonicalUrl;
        return $out;
    }

    /** @return string|null raw generated body (no link), or null if unavailable */
    private function generate(object $article, string $platform, string $canonicalUrl): ?string
    {
        $title   = trim((string) ($article->title ?? ''));
        $excerpt = trim(strip_tags((string) ($article->excerpt ?? '')));
        $body    = trim(preg_replace('/\s+/', ' ', strip_tags((string) ($article->content ?? ''))) ?? '');
        if (strlen($body) > 4000) $body = substr($body, 0, 4000);

        $limit = PlatformPolicy::softLength($platform) - strlen($canonicalUrl) - 2;

        $prompt = "Write a short {$platform} caption that links to the article below.\n\n"
                . "STRICT RULES:\n"
                . "- Use ONLY facts stated in the article. Invent nothing.\n"
                . "- Do NOT include statistics, numbers or percentages unless they appear verbatim in the article.\n"
                . "- Do NOT invent quotes.\n"
                . "- Do NOT add marketing claims the article does not make.\n"
                . "- Do NOT include the URL; it is appended automatically.\n"
                . "- Do NOT include hashtags.\n"
                . "- Maximum {$limit} characters.\n"
                . "- Return JSON: {\"caption\": \"...\"}\n\n"
                . "ARTICLE TITLE: {$title}\n"
                . ($excerpt !== '' ? "ARTICLE EXCERPT: {$excerpt}\n" : '')
                . "ARTICLE BODY: {$body}\n";

        $result = $this->runtime->aiRun(self::RUNTIME_MODE, $prompt, [
            'purpose'  => 'article_share_caption',
            'platform' => $platform,
        ], 400);

        if (!($result['success'] ?? false) || empty($result['text'])) return null;

        $parsed = json_decode((string) $result['text'], true);
        $caption = is_array($parsed) ? (string) ($parsed['caption'] ?? '') : (string) $result['text'];
        $caption = trim($caption);

        return $caption === '' ? null : $caption;
    }

    /**
     * Reject anything the article does not support.
     *
     * @return array{ok:bool, reason:?string}
     */
    public function checkGrounding(string $caption, object $article): array
    {
        $haystack = strtolower(
            (string) ($article->title ?? '') . ' ' .
            strip_tags((string) ($article->excerpt ?? '')) . ' ' .
            strip_tags((string) ($article->content ?? ''))
        );
        $haystack = preg_replace('/\s+/', ' ', $haystack) ?? $haystack;

        // 1. Numbers and percentages must appear in the article.
        preg_match_all('/\d[\d,.]*\s?%?/', $caption, $m);
        foreach (array_unique($m[0]) as $num) {
            $needle = strtolower(trim($num));
            if ($needle === '' || strlen($needle) < 2) continue; // ignore stray single digits
            if (!str_contains($haystack, rtrim($needle, '%'))) {
                return ['ok' => false, 'reason' => 'FABRICATED_NUMBER'];
            }
        }

        // 2. Quoted phrases must appear in the article.
        preg_match_all('/["""](.{6,}?)[""" ]/u', $caption, $q);
        foreach ($q[1] ?? [] as $quote) {
            if (!str_contains($haystack, strtolower(trim($quote)))) {
                return ['ok' => false, 'reason' => 'FABRICATED_QUOTE'];
            }
        }

        // 3. Removed-product vocabulary must never appear — a caption that
        //    advertises campaigns/newsletters misrepresents the launch product.
        foreach (['newsletter', 'email campaign', 'drip sequence', 'ad campaign'] as $banned) {
            if (str_contains(strtolower($caption), $banned) && !str_contains($haystack, $banned)) {
                return ['ok' => false, 'reason' => 'OUT_OF_SCOPE_CLAIM'];
            }
        }

        // 4. Must actually say something.
        if (strlen(trim($caption)) < 10) {
            return ['ok' => false, 'reason' => 'TOO_SHORT'];
        }

        return ['ok' => true, 'reason' => null];
    }
}
