<?php

namespace App\Core\Distribution;

/**
 * Per-platform rules for the Content Publisher.
 *
 * SCOPE CHANGE 2026-07-22 — this used to answer one question ("can this platform
 * carry an article link?"). It now answers two, because the same platform can be
 * valid for one intent and wrong for another:
 *
 *   INTENT_MANUAL_PUBLISH — the user composed a post and pressed Publish.
 *   INTENT_ARTICLE_SHARE  — the blog system is distributing a published article.
 *
 * Instagram is the case that proves the split: it is a first-class manual
 * publishing target (media + caption) but a poor article-share target, because a
 * URL in an IG caption is not clickable. Under the old single-question model it
 * was hard-refused; that was correct for article share and wrong for publishing.
 *
 * LAUNCH SELECTABILITY IS SEPARATE FROM CORRECTNESS.
 * A platform is `launch_selectable` only when this deployment can actually reach
 * it: OAuth code present AND credentials configured AND a publish implementation
 * exists. As of 2026-07-22 that is Facebook and Instagram only. The others are
 * modelled here so captions and variations work end to end, but they are never
 * offered to a user, because offering a channel we cannot publish to is a lie.
 */
final class PlatformPolicy
{
    public const INTENT_MANUAL_PUBLISH = 'manual_publish';
    public const INTENT_ARTICLE_SHARE  = 'article_share';

    /**
     * media: 'required' | 'optional' | 'none'
     * link_clickable: whether a URL in the body is a real link (drives article share)
     */
    private const SPEC = [
        'facebook' => [
            'label' => 'Facebook', 'launch_selectable' => true,
            'max' => 63206, 'soft' => 1200, 'hashtags' => 3,
            'media' => 'optional', 'link_clickable' => true, 'max_media' => 10,
        ],
        'instagram' => [
            'label' => 'Instagram', 'launch_selectable' => true,
            'max' => 2200, 'soft' => 1800, 'hashtags' => 15,
            'media' => 'required', 'link_clickable' => false, 'max_media' => 10,
        ],
        'linkedin' => [
            'label' => 'LinkedIn', 'launch_selectable' => false,
            'unavailable' => 'LINKEDIN_CREDENTIALS_NOT_CONFIGURED',
            'max' => 3000, 'soft' => 1300, 'hashtags' => 3,
            'media' => 'optional', 'link_clickable' => true, 'max_media' => 9,
        ],
        'x' => [
            'label' => 'X', 'launch_selectable' => false,
            'unavailable' => 'X_CREDENTIALS_NOT_CONFIGURED',
            'max' => 280, 'soft' => 260, 'hashtags' => 2,
            'media' => 'optional', 'link_clickable' => true, 'max_media' => 4,
        ],
        'threads' => [
            'label' => 'Threads', 'launch_selectable' => false,
            'unavailable' => 'THREADS_CONNECTOR_NOT_IMPLEMENTED',
            'max' => 500, 'soft' => 480, 'hashtags' => 1,
            'media' => 'optional', 'link_clickable' => true, 'max_media' => 10,
        ],
        'google_business' => [
            'label' => 'Google Business Profile', 'launch_selectable' => false,
            'unavailable' => 'GBP_CONNECTOR_NOT_IMPLEMENTED',
            'max' => 1500, 'soft' => 1200, 'hashtags' => 0,
            'media' => 'optional', 'link_clickable' => true, 'max_media' => 1,
        ],
    ];

    /** Aliases users and old data actually use. */
    private const ALIASES = ['twitter' => 'x', 'gbp' => 'google_business', 'google' => 'google_business'];

    public static function normalise(string $platform): string
    {
        $p = strtolower(trim($platform));
        return self::ALIASES[$p] ?? $p;
    }

    public static function known(string $platform): bool
    {
        return isset(self::SPEC[self::normalise($platform)]);
    }

    /** Platforms a user may actually select today. */
    public static function selectable(): array
    {
        return array_keys(array_filter(self::SPEC, fn($s) => $s['launch_selectable']));
    }

    /** Every modelled platform, for internal caption-variation work. */
    public static function all(): array
    {
        return array_keys(self::SPEC);
    }

    public static function label(string $platform): string
    {
        return self::spec($platform)['label'] ?? ucfirst(self::normalise($platform));
    }

    private static function spec(string $platform): array
    {
        return self::SPEC[self::normalise($platform)] ?? [];
    }

    /**
     * Can this platform be used for this intent, right now, on this deployment?
     *
     * @return array{ok:bool, reason?:string}
     */
    public static function check(string $platform, string $intent = self::INTENT_MANUAL_PUBLISH): array
    {
        $p = self::normalise($platform);
        $s = self::SPEC[$p] ?? null;

        if ($s === null) {
            return ['ok' => false, 'reason' => 'UNKNOWN_PLATFORM'];
        }
        if (!$s['launch_selectable']) {
            return ['ok' => false, 'reason' => $s['unavailable']];
        }
        // Article distribution is pointless where the link cannot be clicked.
        if ($intent === self::INTENT_ARTICLE_SHARE && !$s['link_clickable']) {
            return ['ok' => false, 'reason' => strtoupper($p) . '_CANNOT_CARRY_ARTICLE_LINK'];
        }
        return ['ok' => true];
    }

    public static function isSupported(string $platform, string $intent = self::INTENT_MANUAL_PUBLISH): bool
    {
        return self::check($platform, $intent)['ok'];
    }

    public static function maxLength(string $p): int   { return self::spec($p)['max'] ?? 1000; }
    public static function softLength(string $p): int  { return self::spec($p)['soft'] ?? 800; }
    public static function maxHashtags(string $p): int { return self::spec($p)['hashtags'] ?? 0; }
    public static function maxMedia(string $p): int    { return self::spec($p)['max_media'] ?? 1; }
    public static function linkIsClickable(string $p): bool { return (bool) (self::spec($p)['link_clickable'] ?? true); }

    /** 'required' | 'optional' | 'none' */
    public static function mediaRequirement(string $p): string
    {
        return self::spec($p)['media'] ?? 'optional';
    }

    public static function requiresMedia(string $p): bool
    {
        return self::mediaRequirement($p) === 'required';
    }

    /**
     * Provider-specific text normalisation.
     *  - LinkedIn/X render no markdown, so leaving ** ** in place looks broken.
     *  - Instagram collapses whitespace aggressively.
     */
    public static function formatBody(string $platform, string $body): string
    {
        $p = self::normalise($platform);
        $body = str_replace(["\r\n", "\r"], "\n", $body);

        if (in_array($p, ['linkedin', 'x', 'instagram', 'threads'], true)) {
            $body = preg_replace('/\*\*(.+?)\*\*/s', '$1', $body) ?? $body;
            $body = preg_replace('/(?<!\*)\*(?!\s)(.+?)(?<!\s)\*(?!\*)/s', '$1', $body) ?? $body;
            $body = preg_replace('/^#{1,6}\s*/m', '', $body) ?? $body;
        }

        $body = preg_replace("/\n{3,}/", "\n\n", $body) ?? $body;
        return trim($body);
    }

    /**
     * Truncate on a word boundary, reserving room for a trailing URL when one is
     * supplied. The URL must survive intact — it is usually the point of the post.
     */
    public static function fitToLimit(string $platform, string $body, string $url = ''): string
    {
        $room = min(self::maxLength($platform), self::softLength($platform))
              - ($url === '' ? 0 : strlen($url) + 2);

        if ($room <= 0) return '';
        if (strlen($body) <= $room) return $body;

        $cut = substr($body, 0, $room);
        $sp  = strrpos($cut, ' ');
        if ($sp !== false && $sp > $room * 0.6) $cut = substr($cut, 0, $sp);
        return rtrim($cut, " \t\n.,;:-") . '…';
    }

    /** Why a platform is unavailable, for honest UI copy. */
    public static function unavailableReason(string $platform): ?string
    {
        return self::spec($platform)['unavailable'] ?? null;
    }
}
