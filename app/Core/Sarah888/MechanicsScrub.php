<?php

namespace App\Core\Sarah888;

/**
 * SARAH-VOICE-1 (REPORT-0066 finding 4, 2026-09-30). The owner's own thread carried Sarah's machinery: "I have no tools
 * switched on this turn", "I don't have a reader for the social queue this turn", "the delegation log is partly cut off in
 * what I can see this turn", "a temporary system issue (logged for the team)". A customer never hears about turns, tools,
 * readers or logs. The prompt now says so (ToolSchemaService); this is the deterministic backstop for what still slips
 * through: phrase-level, meaning-preserving, never a whole-sentence rewrite. Specific clauses first, generic words last.
 */
final class MechanicsScrub
{
    /** @var array<string,string> regex => replacement, applied in order */
    private const PHRASES = [
        // whole clauses about tools, readers and logs
        '/\bI\s+have\s+no\s+tools?\s+(?:switched\s+on|available|enabled|running)(?:\s+(?:on\s+my\s+side|for\s+this|this\s+turn|right\s+now|in\s+this\s+reply))*[^,.;]*/iu' => "I can't do that from here right now",
        '/\b(?:there\s+are\s+)?no\s+tools?\s+(?:are\s+)?(?:switched\s+on|available|enabled)(?:\s+(?:on\s+my\s+side|this\s+turn|right\s+now))*/iu' => "I can't do that from here right now",
        '/\bI\s+(?:don\'t|do\s+not)\s+have\s+a\s+reader\s+for\s+[^,.;]*/iu'     => "I can't open that from here right now",
        '/\bthe\s+delegation\s+log\s+(?:is|was)\s+[^,.;]*/iu'                    => 'my notes on that are incomplete',
        '/\bthe\s+delegation\s+log\b/iu'                                        => 'my notes',
        '/\s*\((?:logged|noted|flagged)\s+for\s+the\s+team\)/iu'                => '',
        // tool-result narration: "(LevelUp AI, asset "Featured image: …":" / "(asset #12)" / "(task 937)"
        '/\s*\((?:LevelUp AI,?\s*)?(?:asset|assets|task|tasks|job|record|row)\b[^)]{0,160}\)?:?/iu' => '',
        '/\bin\s+what\s+I\s+can\s+(?:see|read)\s+(?:this\s+turn|right\s+now|here)\b/iu' => 'from here',
        '/\bmy\s+own\s+record\b/iu'                                             => 'my notes',
        '/\bthe\s+record\s+(?:names|shows|says)\b/iu'                           => 'my notes show',
        '/\bthe\s+platform\'?s?\s+(?:own\s+)?approval\s+gate\b/iu'              => 'your approval',
        '/\bno\s+escalation\s+path\b/iu'                                        => 'no one else to hand it to',
        // generic time-frame words
        '/\b(?:in|on|for|during)\s+this\s+(?:turn|reply|response|message)\b/iu' => 'right now',
        '/\bthis\s+turn\b/iu'                                                    => 'right now',
        '/\bfrom\s+this\s+reply\b/iu'                                            => 'from here',
    ];

    public static function apply(string $reply): string
    {
        if (trim($reply) === '') return $reply;
        $out = $reply;
        foreach (self::PHRASES as $re => $to) {
            $out = (string) preg_replace($re, $to, $out);
        }
        // NO-IDS-1: internal numbers and action codes never reach the owner
        $out = self::noIds($out);
        // tidy: doubled spaces and a space before punctuation left by a removal
        $out = (string) preg_replace('/[ \t]{2,}/', ' ', $out);
        $out = (string) preg_replace('/\s+([,.;!?])/', '$1', $out);
        return $out;
    }

    /**
     * NO-IDS-1 (2026-09-30). "(approval #9093 / #9883, 0 credits)" -> "(0 credits)"; "Blocked (13): #33205, #33197 (internal
     * link insertion)" -> "Blocked (13): (internal link insertion)"; "task #937" -> "a task"; "fix_orphans" -> "fix orphans".
     * Four- and five-digit or seven-plus-digit numbers after # only, so a colour such as #0A0806 or #112233 is left alone.
     */
    public static function noIds(string $s): string
    {
        $num = '#\s?(?:\d{4,5}|\d{7,})\b';
        $list = $num . '(?:\s*(?:\/|,|&|and)\s*' . $num . ')*';
        $s = (string) preg_replace('/\b(?:approvals?|queue|tasks?|jobs?|requests?|proposals?|items?|tickets?|ids?|assets?|records?)\s*' . $list . '/iu', '', $s);
        $s = (string) preg_replace('/(?<![\w&])' . $list . '/u', '', $s);
        $s = (string) preg_replace('/\b(?:task|approval|job|record|asset)\s+(?:id\s+)?\d{3,}\b/iu', 'it', $s);
        // action codes: two to four lowercase words joined by underscores, not inside a URL, path, e-mail or code span
        $s = (string) preg_replace_callback('/(?<![\/\w.@`-])([a-z]{2,}(?:_[a-z]{2,}){1,3})(?![\w\/.@`-])/', fn ($m) => str_replace('_', ' ', $m[1]), $s);
        // tidy what the removals leave
        $s = (string) preg_replace('/\(\s*[,\/;&]\s*/u', '(', $s);
        $s = (string) preg_replace('/\s*[,\/;&]\s*\)/u', ')', $s);
        $s = (string) preg_replace('/\(\s*\)/u', '', $s);
        $s = (string) preg_replace('/:\s*(?:,\s*)+/u', ': ', $s);
        $s = (string) preg_replace('/(?:,\s*){2,}/u', ', ', $s);
        $s = (string) preg_replace('/(^|[.!?]\s+)it\b/u', '$1It', $s);   // a sentence that began with the removed number
        return $s;
    }
}
