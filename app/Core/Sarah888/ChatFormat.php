<?php

namespace App\Core\Sarah888;

/**
 * ChatFormat — DEC-0030 (2026-09-02): make Sarah's replies READ like a person, not a wall.
 *
 * Measured (scratch ws 999993, guards already newline-preserving): DeepSeek emits the whole answer on ONE line —
 * "Three priorities this week, in order: 1. Follow up... 2. Publish... 3. Decide..." with zero newlines. The
 * guard fix preserves newlines the model never wrote, so the answer still arrived as one dense paragraph. Owner:
 * "it comes in a giant paragraph and it hurts my eyes... very AI, unlike when people chat."
 *
 * This is a PRESENTATION transform only: it never changes a word, it only inserts line and paragraph breaks that
 * a person would use — numbered/bulleted items onto their own lines, a blank line between a lead-in and its list,
 * and long prose paragraphs split into short two-sentence ones. Deterministic, idempotent, code fences left
 * untouched. It runs at the end of the reply pipeline, after every truthfulness guard.
 */
final class ChatFormat
{
    public static function humanize(string $reply): string
    {
        $t = str_replace("\r\n", "\n", trim($reply));
        if ($t === '') return $reply;

        // Protect fenced code blocks from any reformatting.
        $fences = [];
        $t = preg_replace_callback('/```[\s\S]*?```/', function ($m) use (&$fences) {
            $k = "\x00F" . count($fences) . "\x00"; $fences[] = $m[0]; return $k;
        }, $t) ?? $t;

        // 0. SARAH-LEAK-1 (2026-09-23): internal action names never reach the owner - snake_case tokens of plain words
        //    become words (fix_orphans -> Fix Orphans); nothing inside URLs, paths, addresses, code or key=value survives the lookbehind.
        $t = preg_replace_callback('/`[^`\n]*`/', function ($m) use (&$fences) { $k = "\x00F" . count($fences) . "\x00"; $fences[] = $m[0]; return $k; }, $t) ?? $t;   // inline code is code too
        $t = preg_replace_callback('/(?<![\/=.@\w-])([a-z]{3,}(?:_[a-z0-9]{2,}){1,3})(?![\/.@=\w-])/', function ($m) { return ucwords(str_replace('_', ' ', $m[1])); }, $t) ?? $t;
        // 1. Inline numbered items → their own line. "… 1. Foo … 2) Bar" (1-2 digits, . or ), then a capital or
        //    quote/paren). Whitespace before the number guards against decimals ($1.50, 3.9k are never matched).
        $t = preg_replace('/(?<!\d)[ \t]+(\d{1,2})([.)])[ \t]+(?=[A-Z"\'(\[])/u', "\n$1$2 ", $t) ?? $t;
        // 2. Inline bullets → their own line.
        $t = preg_replace('/[ \t]+•[ \t]+(?=\S)/u', "\n- ", $t) ?? $t;
        $t = preg_replace('/[ \t]+-[ \t]+(?=[A-Z"\'(\[])/u', "\n- ", $t) ?? $t;
        // 3. Blank line before a list that follows a lead-in ending in : . ! or ?
        $t = preg_replace('/([:.!?])\n(?=(?:\d{1,2}[.)]|-)[ \t])/u', "$1\n\n", $t) ?? $t;
        // 4. Blank line after the last list item before trailing prose (a capital-led non-list line).
        $t = preg_replace('/((?:\n|^)(?:\d{1,2}[.)]|-)[ \t][^\n]*)\n(?=[A-Z][^\n]*)/u', "$1\n\n", $t) ?? $t;
        // 5. Break a long, list-free prose paragraph into short two-sentence paragraphs.
        $t = self::breakLongParagraphs($t);
        // 6. Never more than one blank line.
        $t = preg_replace('/\n{3,}/', "\n\n", $t) ?? $t;

        // Restore fences.
        $t = preg_replace_callback('/\x00F(\d+)\x00/', fn ($m) => $fences[(int) $m[1]] ?? '', $t) ?? $t;
        return trim($t);
    }

    /** A paragraph with 4+ sentences and over ~300 chars is regrouped into 2-sentence paragraphs. */
    private static function breakLongParagraphs(string $t): string
    {
        $lines = explode("\n", $t);
        $out = [];
        foreach ($lines as $ln) {
            if (preg_match('/^(?:\d{1,2}[.)]|-)[ \t]/u', $ln) || mb_strlen($ln) <= 300) { $out[] = $ln; continue; }
            $sent = preg_split('/(?<=[.!?])\s+/u', $ln) ?: [$ln];
            if (count($sent) < 4) { $out[] = $ln; continue; }
            $paras = []; $buf = [];
            foreach ($sent as $s) {
                $buf[] = $s;
                if (count($buf) >= 2) { $paras[] = implode(' ', $buf); $buf = []; }
            }
            if ($buf) $paras[] = implode(' ', $buf);
            $out[] = implode("\n\n", $paras);
        }
        return implode("\n", $out);
    }
}
