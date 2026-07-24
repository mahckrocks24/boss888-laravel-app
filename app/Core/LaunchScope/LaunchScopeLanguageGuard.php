<?php

namespace App\Core\LaunchScope;

use Illuminate\Support\Facades\Log;

/**
 * Deterministic truthfulness guard for agent replies about removed capability.
 *
 * WHY A GUARD AND NOT JUST A BETTER PROMPT
 * ----------------------------------------
 * The launch-scope rule already lived in Sarah's system prompt, and she still
 * told a user "social media isn't connected for this workspace — Marcus can't
 * publish there." Two failures in one sentence: it framed a REMOVED product as
 * a DISCONNECTED integration (so the user goes looking for a Connect button
 * that will never exist), and it named a removed specialist as though he were
 * on the bench. A prompt makes that unlikely; it cannot make it impossible.
 *
 * This guard is narrow on purpose. It only rewrites a sentence when that
 * sentence is BOTH about a removed capability AND uses forbidden framing.
 * Legitimate "not connected" messages — Search Console, Google Analytics, the
 * WordPress plugin — are retained integrations and must keep saying exactly
 * that, so they are explicitly protected below.
 */
final class LaunchScopeLanguageGuard
{
    /** The one truthful line. */
    public const TRUTH = 'This capability is not part of the current LevelUp Growth product.';

    /**
     * Subjects that genuinely CAN be connected or configured — never rewrite.
     *
     * Two groups: retained integrations (Search Console, Analytics, WordPress,
     * Stripe, domains) and TRANSACTIONAL email. Transactional email is retained
     * — password resets, verification, receipts, notifications — so "the mailer
     * is not configured" is a true statement an operator needs to see. Only
     * email MARKETING is removed.
     */
    private const PROTECTED_SUBJECTS = [
        'search console', 'google analytics', 'analytics', 'gsc', 'ga4',
        'wordpress', 'wp plugin', 'stripe', 'domain', 'dns', 'google business profile',
        'password reset', 'verification', 'verify your', 'receipt', 'invoice',
        'transactional', 'mailer', 'smtp', 'mail driver', 'notification email',
    ];

    /** Subjects that are removed from the product. */
    private const REMOVED_SUBJECTS = [
        'social media', 'social account', 'social channel', 'social posting',
        'social publishing', 'social', 'instagram', 'facebook', 'tiktok', 'twitter',
        'linkedin', 'email marketing', 'email campaign', 'newsletter', 'sequence',
        'drip', 'campaign', 'ads', 'ad campaign', 'comment', 'inbox', 'engagement',
        // Bare 'email' last: it only reaches here when no PROTECTED_SUBJECT
        // matched, i.e. the sentence is not about transactional mail.
        'email',
    ];

    private const REMOVED_AGENTS = [
        'marcus', 'jordan', 'tyler', 'zara', 'zoe', 'maya', 'vera', 'kai', 'chris', 'leo',
    ];

    /** Framing that must never describe a removed capability. */
    private const BANNED_FRAMING = [
        "isn't connected", 'is not connected', 'not connected', 'no accounts are linked',
        'accounts are linked', "isn't configured", 'is not configured', 'not configured',
        'no email service', 'connect an account', 'connect your', 'connect it in',
        'upgrade to unlock', 'upgrade your plan', 'not available on your plan',
        'coming soon', 'not yet available', 'once connected', 'after you connect',
    ];

    /**
     * W6 - framings that are conclusive on their own.
     *
     * The two-key test (removed SUBJECT + banned FRAMING) missed sentences like
     * "Connect an account first to start posting." because the subject list held
     * 'social posting' but not bare 'posting'. Loosening the subject list would
     * catch retained blog/article copy, so instead these few phrases are treated
     * as sufficient by themselves. None of them describes a retained flow -
     * every retained integration (Search Console, WordPress, Stripe, domain) is
     * caught by PROTECTED_SUBJECTS, which is still evaluated first.
     */
    private const SELF_SUFFICIENT_FRAMING = [
        'connect an account', 'connect a social', 'connect your social',
        'connect your facebook', 'connect your instagram', 'connect your page',
        'start posting', 'begin posting', 'upgrade to unlock',
    ];

    /**
     * @return array{text:string, changed:bool, reasons:array}
     */
    public static function sanitize(string $text): array
    {
        if (trim($text) === '') return ['text' => $text, 'changed' => false, 'reasons' => []];

        $reasons = [];
        $out = $text;

        // Match against a punctuation-normalised view so smart quotes cannot
        // smuggle a banned phrase past the lists above.
        $probe = self::normalise($text);

        // ── 1. Sentences that frame a removed capability as connectable.
        $sentences = preg_split('/(?<=[.!?])\s+/u', $out) ?: [$out];
        foreach ($sentences as $i => $sentence) {
            $l = mb_strtolower(self::normalise($sentence));

            if (self::mentionsAny($l, self::PROTECTED_SUBJECTS)) continue;   // retained integration

            // Conclusive on its own - no retained flow speaks this way.
            if (self::mentionsAny($l, self::SELF_SUFFICIENT_FRAMING)) {
                $sentences[$i] = self::TRUTH;
                $reasons[] = 'reframed_removed_capability';
                continue;
            }

            if (!self::mentionsAny($l, self::REMOVED_SUBJECTS))  continue;   // not about removed work
            if (!self::mentionsAny($l, self::BANNED_FRAMING))    continue;   // framing is fine

            $sentences[$i] = self::TRUTH;
            $reasons[] = 'reframed_removed_capability';
        }
        $out = implode(' ', $sentences);

        // ── 2. Any clause presenting a removed specialist as available.
        foreach (self::REMOVED_AGENTS as $agent) {
            $rx = '/\b' . preg_quote($agent, '/')
                . "\\b[^.!?]*\\b(can|could|will|would|is|isn't|can't|cannot|handles?|does|manages?)\\b[^.!?]*[.!?]/iu";
            $replaced = preg_replace($rx, self::TRUTH . ' ', $out);
            if ($replaced !== null && $replaced !== $out) {
                $out = $replaced;
                $reasons[] = 'removed_agent_presented_as_available:' . $agent;
            }
        }

        // ── 3. Bare mentions of a removed specialist by name.
        foreach (self::REMOVED_AGENTS as $agent) {
            $rx = '/\b' . preg_quote($agent, '/') . '\b/iu';
            if (preg_match($rx, $out)) {
                $out = preg_replace($rx, 'that specialist role', $out) ?? $out;
                $reasons[] = 'removed_agent_named:' . $agent;
            }
        }

        // Tidy up the seams left by replacement.
        // A clause swapped mid-sentence leaves artefacts like "Chef, This
        // capability is not part of..." — correct, but visibly machine-edited.
        $out = preg_replace_callback(
            '/([,;:]\s+)' . preg_quote(self::TRUTH, '/') . '/u',
            fn($m) => $m[1] . lcfirst(self::TRUTH),
            $out
        ) ?? $out;
        // A leading vocative followed only by the replacement reads oddly too.
        $out = preg_replace('/^([A-Z][a-z]+,\s+)this capability/u', '$1this capability', $out) ?? $out;
        $out = preg_replace('/\s{2,}/u', ' ', $out) ?? $out;
        $out = preg_replace('/\s+([.,!?;:])/u', '$1', $out) ?? $out;
        $out = preg_replace('/(' . preg_quote(self::TRUTH, '/') . ')(\s*\1)+/u', '$1', $out) ?? $out;
        $out = trim($out);

        $changed = $out !== $text;
        if ($changed) {
            // Log the fact, never the customer's content.
            Log::info('[LaunchScope] agent reply language corrected', [
                'reasons' => array_values(array_unique($reasons)),
            ]);
        }

        return ['text' => $out, 'changed' => $changed, 'reasons' => array_values(array_unique($reasons))];
    }

    /** Convenience for call sites that just want the corrected string. */
    public static function apply(string $text): string
    {
        return self::sanitize($text)['text'];
    }

    /** Detector for tests and monitoring — does this text violate the rules? */
    public static function violates(string $text): array
    {
        return self::sanitize($text)['reasons'];
    }

    private static function mentionsAny(string $haystackLower, array $needles): bool
    {
        $h = self::normalise($haystackLower);
        foreach ($needles as $n) {
            if (str_contains($h, self::normalise($n))) return true;
        }
        return false;
    }

    /**
     * Fold typographic punctuation to ASCII before matching.
     *
     * This is not cosmetic. Models overwhelmingly emit U+2019 (') rather than
     * the ASCII apostrophe, so a banned phrase written as "isn't connected"
     * would never match the sentence the model actually produced — the precise
     * sentence this guard exists to catch. Dashes and smart quotes fold too.
     */
    private static function normalise(string $text): string
    {
        return strtr($text, [
            "\u{2019}" => "'", "\u{2018}" => "'", "\u{02BC}" => "'",
            "\u{201C}" => '"', "\u{201D}" => '"',
            "\u{2014}" => '-', "\u{2013}" => '-', "\u{2212}" => '-',
            "\u{00A0}" => ' ',
        ]);
    }
}
