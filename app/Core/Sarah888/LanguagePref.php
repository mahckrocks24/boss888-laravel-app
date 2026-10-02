<?php

namespace App\Core\Sarah888;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SARAH-LANG-1 (REPORT-0066 finding 11, 2026-09-30). The owner asked "Can you speak in normal taglish instead" and the
 * conversation obliged - but every generated update after it (briefs, campaign cards, digests, check-ins) came back in
 * English, because the preference lived only in the chat context. It is now a workspace setting: set from the owner's own
 * words, read by every place that writes in Sarah's voice.
 */
final class LanguagePref
{
    public const KEY = 'sarah_language';

    /** @var array<string,string> pattern => language label */
    private const ASKS = [
        '/\btaglish\b/iu'                                        => 'Taglish (Tagalog mixed with English, the everyday Filipino way)',
        '/\b(in|speak|write|reply|talk|answer|respond|use)\s+(tagalog|filipino)\b/iu' => 'Tagalog',   // SARAH-LANG-2: a request, never a mention ("Filipino-French menus")
        '/\b(tagalog|filipino)\s+(please|na lang|nalang)\b/iu'   => 'Tagalog',
        '/\b(in|speak|write|reply|talk|answer)\s+(plain\s+)?english\b/iu' => 'English',
        '/\benglish\s+(please|na lang|nalang)\b/iu'              => 'English',
        '/\b(in|speak|write|reply|talk|answer)\s+(en\s+)?(spanish|espa[nñ]ol)\b/iu' => 'Spanish',
        '/\b(in|speak|write|reply|talk|answer)\s+(french|fran[cç]ais)\b/iu' => 'French',
        '/\b(in|speak|write|reply|talk|answer)\s+(german|deutsch)\b/iu' => 'German',
        '/\b(in|speak|write|reply|talk|answer)\s+(bisaya|cebuano)\b/iu' => 'Bisaya (Cebuano)',
    ];

    /** A language the owner is asking for in this message, or null. */
    public static function detect(string $text): ?string
    {
        $t = trim($text);
        if ($t === '' || mb_strlen($t) > 400) return null;
        // it has to be a request about how to talk, not a mention ("the Tagalog menu page")
        $asksHow = (bool) preg_match('/\b(speak|talk|write|reply|answer|respond|say it|use|switch|instead|please|na lang|nalang|mas okay|better if|can you|could you|pwede|puwede|gusto ko)\b/iu', $t);
        foreach (self::ASKS as $re => $lang) {
            if (preg_match($re, $t) && ($asksHow || $lang === 'Taglish (Tagalog mixed with English, the everyday Filipino way)')) return $lang;
        }
        return null;
    }

    /** Read the owner's message; persist a language request when there is one. Returns the new label or null. */
    public static function absorb(int $wsId, string $text): ?string
    {
        $lang = self::detect($text);
        if ($lang === null) return null;
        try {
            $raw = DB::table('workspaces')->where('id', $wsId)->value('settings_json');
            $s = is_string($raw) ? (json_decode($raw, true) ?: []) : (is_array($raw) ? $raw : []);
            if (($s[self::KEY] ?? null) === $lang) return $lang;
            $s[self::KEY] = $lang;
            $s[self::KEY . '_set_at'] = now()->toIso8601String();
            DB::table('workspaces')->where('id', $wsId)->update(['settings_json' => json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'updated_at' => now()]);
            Log::info('[SARAH-LANG-1] language preference set', ['ws' => $wsId, 'language' => $lang]);
            try { app(\App\Core\OwnerModel\OwnerModelService::class)->upsert($wsId, null, 'preferences', 'language', 'Wants replies in ' . $lang . '.', 'stated', 0.95, 'language_pref', 'confirmed'); } catch (\Throwable) {}   // RFC-0023 P1
        } catch (\Throwable $e) {
            Log::warning('[SARAH-LANG-1] could not persist', ['ws' => $wsId, 'e' => $e->getMessage()]);
        }
        return $lang;
    }

    public static function get(int $wsId): ?string
    {
        try {
            $raw = DB::table('workspaces')->where('id', $wsId)->value('settings_json');
            $s = is_string($raw) ? (json_decode($raw, true) ?: []) : (is_array($raw) ? $raw : []);
            $v = $s[self::KEY] ?? null;
            return is_string($v) && $v !== '' ? $v : null;
        } catch (\Throwable) { return null; }
    }

    /** One instruction line for any prompt that writes in Sarah's voice; '' when the owner never asked. */
    public static function instruction(int $wsId): string
    {
        $l = self::get($wsId);
        if ($l === null || stripos($l, 'english') === 0) return '';
        return "LANGUAGE: the owner asked you to write in {$l}. Write everything in it - every sentence, every list item - keeping names, product terms and numbers as they are. ";
    }
}
