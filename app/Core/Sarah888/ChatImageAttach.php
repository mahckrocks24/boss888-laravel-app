<?php

namespace App\Core\Sarah888;

/**
 * NANOBANANA (2026-09-03): shared extractor so EVERY Sarah reply path renders an app-hosted
 * generated-image URL as an INLINE, downloadable image instead of a bare link or raw markdown.
 * Handles both markdown images ![alt](url) and bare URLs. Never attaches arbitrary external URLs
 * (must be /storage/ + an image extension).
 */
class ChatImageAttach
{
    /** @return array{text:string, attachments:array<int,array{kind:string,url:string,name:string}>} */
    public static function extract(string $text): array
    {
        $atts = [];
        $seen = [];
        $add = function (string $url) use (&$atts, &$seen): bool {
            $url = rtrim(trim($url), ".,);]>\"'");
            if (strpos($url, '/storage/') === false) { return false; }
            if (! preg_match('#\.(png|jpe?g|webp|gif)$#i', $url)) { return false; }
            if (isset($seen[$url]) || count($atts) >= 4) { return false; }
            $seen[$url] = true;
            $atts[] = ['kind' => 'image', 'url' => $url, 'name' => 'Generated image'];
            return true;
        };

        // 1) markdown images ![alt](url) — drop the WHOLE tag when the URL qualifies.
        $text = preg_replace_callback('#!\[[^\]]*\]\(([^)\s]+)\)#', function ($m) use ($add) {
            return $add($m[1]) ? '' : $m[0];
        }, $text);

        // 2) bare URLs still left in the prose.
        if (preg_match_all('#https?://\S+#i', $text, $mm)) {
            foreach ($mm[0] as $raw) {
                if ($add($raw)) { $text = str_replace($raw, '', $text); }
            }
        }

        if ($atts) {
            // tidy leftover "Here's your image:" / trailing colon-dash artefacts + doubled spaces.
            $text = preg_replace('/[:\-\x{2014}]\s*(?=\n|$)/u', '', $text);
            $text = trim((string) preg_replace('/[ \t]{2,}/', ' ', $text));
            if ($text === '') { $text = "Here's your image:"; }
        }
        return ['text' => $text, 'attachments' => $atts];
    }
}
