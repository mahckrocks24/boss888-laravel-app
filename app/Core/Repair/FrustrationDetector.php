<?php

namespace App\Core\Repair;

use Illuminate\Support\Facades\DB;

/**
 * RFC-0023 P3: reads one owner turn and says how frustrated the owner sounds, why, and what on record most likely caused it.
 * Three families of cue, each scored, none of them a model call:
 *   voice      - the owner's words (still / again / third time / wrong / not what I asked / useless / caps / !!!)
 *   behaviour  - the same request repeated within 30 minutes, three image asks in an hour, a rejection just given
 *   system     - Sarah's own failures on record (ledger rows failed or QA-rejected in the last 48 h, a promise not kept)
 * A behaviour or system cue alone never opens an episode: the owner must sound it (voice >= 1 cue) or the score must be
 * high (>= 45) with a fresh failure of Sarah's own. That keeps "write it again, this time for Friday" from being a repair.
 */
class FrustrationDetector
{
    /** @var array<string, array{0:string,1:int}> cue id => [pattern, points] */
    private const VOICE = [
        'nth_time'      => ['/\b(second|third|fourth|fifth|sixth|\d+(st|nd|rd|th)) time\b|\bhow many times\b|\bonce again\b|\byet again\b|\bulit\b/u', 35],
        'still'         => ['/\b(still|again)\b.{0,60}\b(wrong|same|not|broken|missing|bare|empty|nothing|plate|only|just)\b|\b(wrong|same|broken|missing|nothing)\b.{0,40}\b(still|again)\b/iu', 25],
        'told_you'      => ['/\bi (already )?(told|said|asked|explained)( you| this| that)?\b.{0,30}(already|before|twice|yesterday|earlier|last time)|\bi already (told|said|asked|explained)\b/iu', 30],
        'not_asked'     => ['/\bnot what i (asked|wanted|said|meant)\b|\bthat\'?s not (it|right|what)\b|\bthis is not\b|\bhindi (ito|yan|yun)\b/iu', 30],
        'wrong'         => ['/\b(wrong|incorrect|broken|useless|unusable|unacceptable|terrible|awful|garbage|rubbish|ridiculous|pointless|waste of (time|credits|money))\b|\bnakakainis\b|\bwalang kwenta\b/iu', 25],
        'emotion'       => ['/\b(frustrat(ed|ing)|annoy(ed|ing)|disappoint(ed|ing)|fed up|sick of|tired of|losing patience|unhappy|upset)\b|\bcome on\b|\bseriously\b|\bfor god\'?s sake\b|\bwtf\b/iu', 30],
        'doesnt_work'   => ['/\b(doesn\'?t|does not|didn\'?t|did not|won\'?t|isn\'?t|is not) (work|working|load|loading|show|showing|save|saving|help|helping)\b|\bnothing (happened|happens|works)\b|\bno (change|difference)\b/iu', 15],
        'give_up'       => ['/\bforget it\b|\bnever mind\b|\bi give up\b|\bdo it myself\b|\bcancel (my|the) (plan|subscription|account)\b|\brefund\b|\bmy credits? (back|wasted)\b|\bwasted (my )?credits\b/iu', 35],
        'fix_it'        => ['/\b(just )?fix (it|this|that)\b|\bmake it right\b|\bsort (it|this) out\b|\bredo (it|this|that)\b|\bstart over\b/iu', 10],
        'why'           => ['/\bwhy (is|are|does|do|did|can\'?t|won\'?t|isn\'?t) (it|this|that|you|the)\b/iu', 10],
    ];

    /** @return array{score:int, level:string, cues:array<int,string>, voice:int, cause:?array} */
    public function read(int $wsId, ?int $bizId, string $content, ?int $userMessageId = null): array
    {
        $text = trim($content);
        $cues = []; $score = 0; $voice = 0;
        if ($text === '' || mb_strlen($text) < 4) return ['score' => 0, 'level' => 'none', 'cues' => [], 'voice' => 0, 'cause' => null];

        // voice
        foreach (self::VOICE as $id => [$re, $pts]) { if (preg_match($re, $text)) { $cues[] = 'voice:' . $id; $score += $pts; $voice++; } }
        $letters = preg_replace('/[^A-Za-z]/', '', $text);
        if (mb_strlen($letters) >= 12 && preg_match_all('/[A-Z]/', $letters) / max(1, strlen($letters)) > 0.6) { $cues[] = 'voice:caps'; $score += 15; $voice++; }
        if (preg_match('/[!?]{2,}|!\s*!|\?\s*\?/', $text)) { $cues[] = 'voice:punctuation'; $score += 10; $voice++; }
        // a thank-you or a clear "better" cancels the voice cues of that line (the owner is pleased, even if the words include "again")
        if (preg_match('/\b(thank(s| you)|perfect|great|love it|much better|that\'?s it|well done|nice one|exactly)\b/iu', $text) && ! preg_match('/\b(not|no|but|still)\b/iu', $text)) { $score = 0; $voice = 0; $cues = ['voice:pleased']; }

        // behaviour
        try {
            $recent = DB::table('agent_messages')->where('workspace_id', $wsId)->where('role', 'user')->where('created_at', '>=', now()->subMinutes(30))
                ->when($userMessageId, fn ($q) => $q->where('id', '<', $userMessageId))->orderByDesc('id')->limit(6)->pluck('content');
            $cur = $this->bag($text);
            foreach ($recent as $r) {
                $prev = $this->bag((string) $r);
                if (count($cur) >= 4 && count($prev) >= 4) {
                    $j = count(array_intersect_key($cur, $prev)) / max(1, count($cur + $prev));
                    if ($j >= 0.5) { $cues[] = 'behaviour:repeat_request'; $score += 25; break; }
                }
            }
            $imgAsks = DB::table('agent_messages')->where('workspace_id', $wsId)->where('role', 'user')->where('created_at', '>=', now()->subMinutes(60))
                ->where('content', 'regexp', '(image|picture|photo|banner|poster|graphic|visual|design)')->count();
            if ($imgAsks >= 3 && preg_match('/\b(image|picture|photo|banner|poster|graphic|visual|design)\b/iu', $text)) { $cues[] = 'behaviour:repeated_image_asks'; $score += 15; }
            $rej = DB::table('owner_model_events')->where('workspace_id', $wsId)->where('event', 'approval_rejected')->where('created_at', '>=', now()->subHours(2))->count();
            if ($rej) { $cues[] = 'behaviour:just_rejected'; $score += 10; }
        } catch (\Throwable) {}

        // system: what Sarah herself broke, on record
        $cause = null;
        try {
            $rows = DB::table('outcome_ledger')->where('workspace_id', $wsId)
                ->when($bizId, fn ($q) => $q->where(fn ($w) => $w->where('business_id', $bizId)->orWhereNull('business_id')))
                ->where('created_at', '>=', now()->subHours(72))
                ->where(fn ($q) => $q->where('status', 'failed')->orWhereIn('verdict', ['rejected', 'declined']))
                ->orderByDesc('id')->limit(8)->get();
            $fresh = $rows->filter(fn ($r) => strtotime((string) $r->created_at) >= time() - 48 * 3600)->count();
            if ($fresh) { $cues[] = 'system:' . $fresh . '_failed_or_rejected_48h'; $score += min(20, 10 * $fresh); }
            $cause = $this->pickCause($rows, $text);
        } catch (\Throwable) {}

        $score = min(100, $score);
        $level = 'none';
        if ($voice >= 1 && $score >= 25) $level = $score >= 70 ? 'severe' : ($score >= 45 ? 'strong' : 'mild');
        elseif ($voice === 0 && $score >= 45 && $cause) $level = 'mild';   // repeated asks after a failure of Sarah's own, no hard words yet
        return ['score' => $score, 'level' => $level, 'cues' => $cues, 'voice' => $voice, 'cause' => $cause];
    }

    /** @return array<string,true> */
    private function bag(string $s): array
    {
        $w = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($s), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $w = array_filter($w, fn ($x) => mb_strlen($x) >= 3 && ! in_array($x, ['the', 'and', 'for', 'with', 'this', 'that', 'you', 'please', 'can', 'make', 'want', 'need', 'one', 'two', 'ang', 'ng', 'mga', 'yung'], true));
        return array_fill_keys($w, true);
    }

    /** The ledger row the owner is most likely talking about: shared words first, else the newest failure. */
    private function pickCause($rows, string $text): ?array
    {
        if (! $rows || $rows->isEmpty()) return null;
        $cur = $this->bag($text); $best = null; $bestN = -1;
        foreach ($rows as $r) {
            $p = json_decode((string) $r->planned_json, true) ?: []; $d = json_decode((string) $r->delivered_json, true) ?: []; $v = json_decode((string) $r->verdict_json, true) ?: [];
            $hay = $this->bag(($p['what'] ?? '') . ' ' . ($d['what'] ?? '') . ' ' . ($r->kind ?? '') . ' ' . ($p['action'] ?? ''));
            $n = count(array_intersect_key($cur, $hay));
            if ($n > $bestN) { $bestN = $n; $best = [$r, $p, $d, $v]; }
        }
        [$r, $p, $d, $v] = $best;
        $what = trim((string) ($p['what'] ?? $d['what'] ?? str_replace('_', ' ', (string) ($p['action'] ?? $r->kind))));
        $why = $r->status === 'failed' ? ('it failed' . (! empty($d['error']) ? ': ' . mb_substr((string) $d['error'], 0, 120) : ''))
            : ($r->verdict === 'declined' ? 'the owner declined it' : ('it was rejected' . (($v['by'] ?? '') === 'sarah_qa' ? ' by your own quality check' : ' by the owner') . (! empty($v['reason']) ? ': ' . mb_substr((string) $v['reason'], 0, 140) : '')));
        return ['ref' => $r->ref, 'ledger_id' => (int) $r->id, 'kind' => $r->kind, 'when' => substr((string) ($r->delivered_at ?: $r->created_at), 0, 10),
            'what' => mb_substr($what, 0, 140), 'why' => $why, 'credits' => (int) ($d['credits'] ?? $p['credits'] ?? 0), 'matched_words' => $bestN,
            'task_id' => str_starts_with((string) $r->ref, 'task:') ? (int) substr((string) $r->ref, 5) : null];
    }
}
