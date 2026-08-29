<?php

namespace App\Core\Integrity;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AgentClaimValidator — deterministic post-generation check that an agent is
 * not claiming work it did not do.
 *
 * WHY (forensic 2026-07-19):
 *   James  (SEO Strategist): "I've already identified the 4 orphan pages and
 *          queued the fix for myself — it's in progress now." No such task
 *          existed. Specialists cannot queue work at all, and inserting
 *          internal links is not even in his skill set.
 *   Priya  (Content Manager): "I've already flagged the stuck lead deletion to
 *          Sarah." She had asked permission 30 seconds earlier, been told yes,
 *          then claimed it was done without doing it.
 *
 * Four successive prompt rules failed to stop this — the same lesson as the
 * numeric fabrication work: prompt text is PREVENTION, it is not ENFORCEMENT.
 * This runs AFTER synthesis and BEFORE storage, so a false claim never reaches
 * the owner regardless of what the model emitted.
 *
 * ROLE MODEL (agents table, verified):
 *   sarah  — is_dmm=1, Digital Marketing Manager. The ONLY agent that
 *            coordinates and delegates. She MAY legitimately say she queued
 *            work, but only when this turn actually created tasks.
 *   all 19 others — specialists (James=SEO Strategist, Priya=Content Manager,
 *            Alex=Technical SEO Engineer, Elena=Lead & CRM Manager, …). They
 *            can NEVER queue, assign or execute. Any claim that they did is
 *            false by construction, not merely unverified.
 */
class AgentClaimValidator
{
    /**
     * A sentence is a false-completion claim if it matches any of these.
     * Sentence-level (not fragment-level) so removing it leaves clean prose and
     * catches compound claims like "…and queued the fix for myself" where the
     * verb is not directly attached to "I've".
     */
    private const CLAIM_PATTERNS = [
        // First-person past-tense action: "I've queued", "I have flagged", "I queued", "I've already... for myself"
        '/\bI(\'?ve|\s+have|\s+already)?\b[^.!?\n]*\b(queued|assigned|scheduled|kicked off|launched|triggered|flagged|escalated|notified|informed|told\s+sarah|passed (it|that|this) (to|on)|raised (it|that|this) with)\b/i',
        // "queued/assigned ... for myself|for you" without needing the I've prefix
        '/\b(queued|assigned|scheduled|added|set up|created)\b[^.!?\n]*\bfor (myself|you|the team|sarah)\b/i',
        // In-progress status claims
        '/\b(it\'?s|that\'?s|this is|they\'?re|they are|the (fix|task|work|job|links?|articles?|images?) (is|are))\b[^.!?\n]*\b(now\s+)?(running|in progress|underway|processing|being (fixed|processed|generated|written|handled|inserted|added))\b/i',
        // "I'm <doing> now" present-progressive execution claims
        '/\bI\'?m\s+(currently\s+)?(inserting|adding|writing|generating|fixing|updating|building|creating|running|processing|queu)[a-z]*\b/i',
        // Forward promises that imply work is already running
        '/\bI\'?ll\s+(confirm|update you|let you know|report back|notify you)\b[^.!?\n]*\b(when|as soon as|once)\b/i',
        '/\bworking on (it|that|them) now\b/i',
        '/\bshould be done in\b[^.!?\n]*\bminutes?\b/i',
        // RISK-0123 (2026-08-29) — impersonal/passive queue claims slipped through every pattern above:
        // "The task to change the hero subtitle … is already queued and awaiting execution." — said twice
        // to the owner while the tasks table was empty. A claim that work is queued is a claim, whoever
        // the grammatical subject is.
        '/\b(is|are|was|were|has been|have been|got|gets)\s+(already\s+|now\s+)?(queued|in the queue|scheduled|awaiting execution|in progress|underway|in flight|being handled|being processed|lined up)\b/i',
        '/\balready (queued|scheduled|in progress|underway|in flight|being handled)\b/i',
    ];

    /**
     * @param  string  $reply     the generated reply
     * @param  int     $wsId      workspace
     * @param  string  $slug      agent slug
     * @param  bool    $didQueue  did THIS turn actually create tasks?
     * @return array{reply:string, stripped:array}
     */
    public function validate(string $reply, int $wsId, string $slug, bool $didQueue = false): array
    {
        if (trim($reply) === '') {
            return ['reply' => $reply, 'stripped' => []];
        }

        $slug     = strtolower(trim($slug));
        $isDmm    = $this->isDelegator($slug);
        $stripped = [];

        // Sarah may legitimately report delegation — but ONLY when this turn
        // really produced tasks. A specialist may never claim it at all.
        if ($isDmm && $didQueue) {
            return ['reply' => $reply, 'stripped' => []];
        }

        // Split into sentences, keep only those that are NOT a claim. Operating
        // whole-sentence avoids the mid-sentence debris a fragment-excision left
        // ("...for myself —.") and catches compound claims.
        $sentences = preg_split('/(?<=[.!?])\s+|\n+/', $reply, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $kept = [];
        foreach ($sentences as $sentence) {
            $isClaim = false;
            foreach (self::CLAIM_PATTERNS as $rx) {
                if (preg_match($rx, $sentence)) { $isClaim = true; break; }
            }
            if ($isClaim) {
                $stripped[] = trim($sentence);
            } else {
                $kept[] = trim($sentence);
            }
        }

        if (empty($stripped)) {
            return ['reply' => $reply, 'stripped' => []];
        }

        // Drop bullets/fragments left dangling, collapse whitespace.
        $kept = array_values(array_filter($kept, fn ($s) => preg_match('/[a-z0-9]/i', $s) && mb_strlen($s) > 2));
        $out = trim(implode(' ', $kept));
        $out = preg_replace('/\s{2,}/', ' ', $out);
        $out = preg_replace('/\s+([.,!?])/', '$1', (string) $out);
        $out = trim((string) $out);

        // Replace the removed claim with what is actually true for this role.
        $honest = $isDmm
            ? "I haven't queued that yet — say the word and I'll set it running."
            : "I can't queue or run that myself — that needs Sarah to assign it. Want me to pass it to her?";

        $out = $out === '' ? $honest : rtrim($out, " \t") . ' ' . $honest;

        Log::warning('[AgentClaim] stripped unverified completion claim', [
            'workspace_id' => $wsId,
            'agent'        => $slug,
            'is_delegator' => $isDmm,
            'did_queue'    => $didQueue,
            'stripped'     => array_slice($stripped, 0, 3),
        ]);

        return ['reply' => $out, 'stripped' => $stripped];
    }

    /** Only the DMM may speak about delegating. Read from the agents table. */
    private function isDelegator(string $slug): bool
    {
        if ($slug === 'sarah' || $slug === 'dmm') {
            return true;
        }
        try {
            return (bool) DB::table('agents')->where('slug', $slug)->value('is_dmm');
        } catch (\Throwable) {
            return false;
        }
    }
}
