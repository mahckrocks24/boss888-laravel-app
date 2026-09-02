<?php

namespace App\Core\Sarah888;

use Illuminate\Support\Facades\Log;

/**
 * TR-2 (REPORT-0027): CompletionGuard's verb list covers past-tense ACTIONS (removed/published/…) but not
 * RESEARCH verbs, so "I've analyzed your competitors' backlinks and found three gaps" or "I researched the
 * market" passed unchecked while nothing ran. This guard closes the unambiguous case: a claim of having
 * researched something EXTERNAL (competitors, market, backlinks, the web, SERPs) on a turn where NOTHING
 * executed — Sarah had no way to fetch it, so the finding is invented.
 *
 * Narrow and safe like MeasurementGuard: it only fires when $didExec is false (no task, no tool, no read ran
 * this turn) AND the object is external (not the owner's own workspace data, which Sarah legitimately sees
 * from context). If anything executed, it does nothing — a real read may have happened.
 */
class ResearchClaimGuard
{
    private const VERB = '(?:analy[sz]ed|examined|researched|reviewed|read(?:\s+through)?|looked\s+(?:at|into|through)|searched|pulled(?:\s+up)?|gathered|studied|went\s+through|dug\s+into|investigated|audited|scanned|crawled|assessed|compared|benchmarked|combed\s+through)';

    /** External subjects Sarah cannot know without actually running a web/search read. */
    private const EXTERNAL = '(?:competitors?|competition|rivals?|the\s+market|market\s+(?:trends?|data|research|conditions?)|industry\s+(?:trends?|benchmarks?|data|standards?)|backlinks?|link\s+profiles?|the\s+web|online|google\b|search\s+results?|serps?|other\s+(?:sites?|businesses|companies|brands?)|similar\s+(?:sites?|businesses|companies))';

    public function sanitize(string $reply, int $wsId, bool $didExec): array
    {
        $out = ['reply' => $reply, 'stripped' => []];
        if (trim($reply) === '' || $didExec) {
            return $out; // something ran this turn — a real read may back the claim; never over-strip.
        }

        $rx = '/\b(?:I\'?(?:ve|m)?\s+|I\s+(?:have|just|already)\s+|(?:have|has)\s+been\s+)?'
            . self::VERB . '\b[^.!?\n]{0,60}\b' . self::EXTERNAL . '\b/i';

        $sentences = preg_split('/(?<=[.!?])\s+|\n+/', $reply, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $kept = [];
        $stripped = [];
        foreach ($sentences as $s) {
            if (preg_match($rx, $s)) {
                $stripped[] = trim($s);
            } else {
                $kept[] = trim($s);
            }
        }

        if (empty($stripped)) {
            return $out;
        }

        $kept = array_values(array_filter($kept, fn ($s) => preg_match('/[a-z0-9]/i', $s) && mb_strlen($s) > 2));
        $clean = trim(preg_replace('/\s{2,}/', ' ', implode(' ', $kept)));
        $clean = trim(preg_replace('/\s+([.,!?])/', '$1', (string) $clean));

        $honest = "I haven't actually researched that yet — I don't have live web or competitor data unless I run it. Want me to look into it?";
        $clean = $clean === '' ? $honest : rtrim($clean, " \t") . ' ' . $honest;

        Log::warning('[Research] stripped an ungrounded external-research claim (nothing executed this turn)', [
            'workspace_id' => $wsId,
            'stripped'     => array_slice($stripped, 0, 3),
        ]);

        return ['reply' => $clean, 'stripped' => $stripped];
    }
}
