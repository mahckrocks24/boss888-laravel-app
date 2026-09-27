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
    public const TRUTH = 'This capability is not part of the current LevelUpGrowth product.';

    /** F-EM-E1: the precise truth about email — marketing is out, one-to-one platform mail is in. */
    public const TRUTH_EMAIL = 'Email marketing — campaigns, newsletters and drip sequences — is not part of the current LevelUpGrowth product. The emails the platform sends to you (new booking and enquiry alerts, notifications and account emails) are; your customers do not get automatic emails from the platform — you confirm with them yourself.';

    /** F-EM-F1: what actually happens when a customer books or enquires on the website. */
    public const TRUTH_CUSTOMER_EMAIL = 'Your customers do not get an automatic email from the platform when they book or enquire — the request lands in your Calendar › Bookings and CRM, you are alerted, and you confirm with them yourself.';

    /** A sentence that denies email as a whole, without naming marketing/campaigns/newsletters/sequences. */
    private const BLANKET_EMAIL_DENIAL = '/\b(e-?mails?|e-?mail sending|sending e-?mails?)\b[^.!?]{0,40}\b(is(?:n\'t| not)|are(?:n\'t| not)|(?:is|are) no longer)\b[^.!?]{0,30}\b(part of|in|included in|available in|offered)\b[^.!?]{0,40}\b(product|launch|platform|LevelUp)|\bno e-?mail (system|service|sending|capability|feature)\b|\bcan(?:not|\'t) send (any )?e-?mails? on your behalf\b|\bplatform can(?:not|\'t) send (e-?mail|on your behalf)\b/iu';

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
    // DEC-0028 / RISK-0099 (2026-08-29): social posting/publishing and the platforms are IN the
    // product again — "connect your Facebook account" is now a TRUE statement, not banned framing.
    private const REMOVED_SUBJECTS = [
        // WATCH-1 (2026-09-27): listening and monitoring are in the product again (market watch)
        'email marketing', 'email campaign', 'newsletter', 'sequence',
        'drip', 'ads', 'ad campaign', 'comment', 'inbox', 'engagement',   // CAMPAIGNS-1: bare 'campaign' removed — growth campaigns are in the product
        // Bare 'email' last: it only reaches here when no PROTECTED_SUBJECT
        // matched, i.e. the sentence is not about transactional mail.
        'email',
    ];

    private const REMOVED_AGENTS = [
        'jordan', 'tyler', 'zara', 'zoe', 'maya', 'vera', 'kai', 'chris', 'leo', // marcus restored (DEC-0028)
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
    // DEC-0028 / RISK-0099 (2026-08-29): "connect an account / connect your social" is TRUE product
    // guidance now that social publishing is in launch — only email-marketing framing stays conclusive.
    private const SELF_SUFFICIENT_FRAMING = [
        'connect an email service', 'connect your email service', 'connect your email provider',
        'connect a mailing list', 'connect your mailing list', 'connect your newsletter',
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
        // DEC-0030 (2026-09-02): split CAPTURING the whitespace so newlines/paragraphs survive; implode(' ')
        // here flattened every reply into one paragraph. Even indices are sentences, odd are the delimiters.
        $parts = preg_split('/((?<=[.!?])\s+)/u', $out, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$out];
        for ($__i = 0; $__i < count($parts); $__i += 2) {
            $sentence = $parts[$__i];
            $l = mb_strtolower(self::normalise($sentence));
            if (self::mentionsAny($l, self::PROTECTED_SUBJECTS)) continue;
            if (self::mentionsAny($l, self::SELF_SUFFICIENT_FRAMING)) { $parts[$__i] = self::TRUTH; $reasons[] = 'reframed_removed_capability'; continue; }
            if (!self::mentionsAny($l, self::REMOVED_SUBJECTS)) continue;
            if (!self::mentionsAny($l, self::BANNED_FRAMING))   continue;
            $parts[$__i] = self::TRUTH; $reasons[] = 'reframed_removed_capability';
        }
        // ── 1b. F-EM-E1: a sentence that denies EMAIL as a whole is false — transactional mail is in the product.
        for ($__i = 0; $__i < count($parts); $__i += 2) {
            $sentence = $parts[$__i];
            $l = mb_strtolower(self::normalise($sentence));
            if ($sentence === self::TRUTH || $sentence === self::TRUTH_EMAIL) continue;
            if (preg_match('/\b(marketing|campaigns?|newsletters?|sequences?|drips?|bulk|broadcasts?)\b/u', $l)) continue; // already precise
            if (!preg_match(self::BLANKET_EMAIL_DENIAL, $sentence)) continue;
            $parts[$__i] = self::TRUTH_EMAIL; $reasons[] = 'blanket_email_denial_corrected';
        }
        // ── 1c. F-EM-F1: "your customers receive a confirmation email" is false — the platform alerts the OWNER only.
        for ($__i = 0; $__i < count($parts); $__i += 2) {
            $sentence = $parts[$__i];
            if ($sentence === self::TRUTH_CUSTOMER_EMAIL) continue;
            if (!preg_match('/\b(customers?|clients?|guests?|visitors?|they)\b[^.!?]{0,60}\b(receive|get|are sent|will receive|will get|automatically (receive|get))\b[^.!?]{0,40}\b(confirmation|booking|appointment|reservation)\b[^.!?]{0,20}\b(e-?mails?|messages?)\b/iu', $sentence)) continue;
            if (preg_match('/\b(do not|don.t|no|never|not)\b/iu', $sentence)) continue; // already a denial
            $parts[$__i] = self::TRUTH_CUSTOMER_EMAIL; $reasons[] = 'customer_confirmation_claim_corrected';
        }
        $out = implode('', $parts);

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
        // TIDY WITHOUT FLATTENING. This collapsed \s{2,} — every run of two or more whitespace characters,
        // newlines included — into a single space. It runs over EVERY deterministic router reply, so any
        // answer built as a list arrived as a wall of text: the keyword-ranking table at line 902 and the
        // website list both implode on "\n" and both were flattened here. Chef Red, 2026-09-01: "Sarah is
        // terribly slow and I fucking hate talking to her." Unreadable is part of that.
        //
        // Runs of spaces and tabs still collapse; newlines survive, and three or more become a paragraph
        // break rather than an accident.
        $out = preg_replace('/[^\S\n]{2,}/u', ' ', $out) ?? $out;
        $out = preg_replace('/[^\S\n]*\n[^\S\n]*/u', "\n", $out) ?? $out;
        $out = preg_replace('/\n{3,}/u', "\n\n", $out) ?? $out;
        $out = preg_replace('/[^\S\n]+([.,!?;:])/u', '$1', $out) ?? $out;
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
