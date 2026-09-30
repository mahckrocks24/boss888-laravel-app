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
        // tidy: doubled spaces and a space before punctuation left by a removal
        $out = (string) preg_replace('/[ \t]{2,}/', ' ', $out);
        $out = (string) preg_replace('/\s+([,.;!?])/', '$1', $out);
        return $out;
    }
}
