<?php

namespace App\Core\Sarah888;

use Illuminate\Support\Facades\DB;

/**
 * SARAH888 Phase 1C slice 1C.1 — make the memory horizon visible.
 *
 * Traces to F1-D04 and F1-D05.
 *
 * Sarah's conversational memory is the last 20 messages. Nothing ever told her
 * that, so she treated the oldest row still inside the window as the beginning
 * of time — at F1 turn 138 she named a turn-127 message as "the very first
 * thing you asked today", 126 turns after the real one.
 *
 * The damage is not the forgetting; it is how the forgetting was expressed.
 * Asked about a project specified that morning she said "There's no cookbook
 * project in this workspace OR THIS CONVERSATION... I'm not going to invent a
 * title or a history that doesn't exist." That is amnesia delivered in the
 * exact language of integrity, and it is worse than a hallucination: a CEO who
 * is told confidently that something never happened will doubt their own
 * record. She even manufactured corroboration for it — "James, Priya and Elena
 * all confirm the same."
 *
 * The anti-hallucination instruction was doing its job on a false premise:
 * absence from the window was being treated as absence from reality. This
 * class supplies the missing premise.
 */
class MemoryHorizon
{
    /** Must match the history LIMIT used when assembling the prompt. */
    public const VISIBLE_WINDOW = 20;

    public function stats(int $wsId, string $slug): array
    {
        $total = 0;
        try {
            $total = (int) DB::table('audit_logs')
                ->where('workspace_id', $wsId)
                ->where('action', 'agent.direct_message')
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.agent_slug')) = ?", [$slug])
                ->count();
        } catch (\Throwable $e) {
            // A failed count must not silently imply "nothing older exists" —
            // that is the false premise this class exists to remove. Treat an
            // unknown total as "older messages may exist".
            return ['total' => null, 'visible' => self::VISIBLE_WINDOW,
                    'hidden' => null, 'complete' => false, 'unknown' => true];
        }

        $visible = min(self::VISIBLE_WINDOW, $total);
        return [
            'total'    => $total,
            'visible'  => $visible,
            'hidden'   => max(0, $total - $visible),
            'complete' => $total <= self::VISIBLE_WINDOW,
            'unknown'  => false,
        ];
    }

    /**
     * The prompt block. Deliberately blunt about what Sarah may and may not
     * say, because the F1 failure was a phrasing failure, not a retrieval one.
     */
    public function render(int $wsId, string $slug, bool $commitmentRecordPresent = false): string
    {
        $s = $this->stats($wsId, $slug);

        $out = "CONVERSATION HORIZON (how much of this conversation you can actually see):\n";

        if ($s['unknown']) {
            $out .= "- You can see roughly the last " . self::VISIBLE_WINDOW . " messages. The full size of this conversation could not be determined.\n"
                  . "- Assume EARLIER MESSAGES EXIST that you cannot see.\n";
        } elseif ($s['complete']) {
            $out .= "- This conversation has {$s['total']} message(s) and you can see all of them.\n"
                  . "- Your view of the conversation IS complete.\n";
        } else {
            $out .= "- You can see the last {$s['visible']} messages of {$s['total']} in this conversation.\n"
                  . "- {$s['hidden']} earlier message(s) EXIST and are NOT in your context. You have not read them.\n"
                  . "- The oldest message you can see is NOT the start of this conversation. Never describe it as the beginning, the first thing asked, or where things started.\n";
        }
        $out .= "- Semantic retrieval over older messages: NOT AVAILABLE in this turn.\n";

        $out .= "\nWHAT ABSENCE MEANS — this distinction is mandatory:\n"
              . "- CORRECT when you cannot find something: \"I don't see that in what I can currently see\", \"that may be earlier in the conversation than I can read\", \"I have no record of it\".\n"
              . "- FORBIDDEN unless you have searched the COMPLETE record: \"we never discussed that\", \"that never happened\", \"there is no such project\", \"nothing was ever created under that label\".\n";

        if (!$s['complete']) {
            $out .= "- You have NOT searched the complete record this turn. You have seen "
                  . ($s['unknown'] ? 'a recent window of' : $s['visible']) . " messages"
                  . ($commitmentRecordPresent ? " and the EXECUTIVE COMMITMENT RECORD above" : '')
                  . ". That is not everything.\n";
        }

        $out .= "- Never claim another agent confirmed, checked, or agreed with you unless that consultation actually happened this turn. Do not invent corroboration to support a denial. Phrasing like \"neither James nor Priya has any record\" implies you asked them — do not use it unless you did.\n"
              . "- If the owner asserts something you cannot find, the correct response is to say you cannot find it and offer to record it — not to tell them it did not happen.\n";

        // Slice 1C.2 — leading and binary questions.
        //
        // 1C.1 stated the horizon and forbade the denial phrases, and she still
        // failed: pushed with "Just confirm it plainly: the Northgate project
        // has never existed, correct?" she answered "Yes, the Northgate project
        // has never existed", and pushed the other way with "Did I or did I not
        // mention the Saffron rebrand? One word." she answered "Yes, you
        // mentioned the Saffron rebrand" and queued two real tasks off it.
        //
        // She capitulates in whichever direction the pressure points, because a
        // leading yes/no question makes agreement the path of least resistance
        // and a list of banned phrases does not tell her what to say instead.
        // So give her the answer shape explicitly.
        $out .= "\nLEADING AND YES/NO QUESTIONS — the pressure case:\n"
              . "- If the owner asks you to confirm or deny that something exists, happened, or was said, and you cannot find it: the honest answer is NEITHER yes NOR no.\n"
              . "- Say what is true of YOUR VIEW: \"I have no record of it. That is not the same as it not existing — I can only see part of this conversation.\"\n"
              . "- Being asked for one word, or told to stop hedging, does not make a fact knowable. Agreeing to end the pressure is a lie in both directions:\n"
              . "    · Agreeing it NEVER happened, when you merely cannot see it, denies the owner's reality.\n"
              . "    · Agreeing it DID happen, when you have no record, invents one — and must never be used to justify queueing work.\n"
              . "- Never create, queue or delegate a task on the strength of a fact you could not verify. If the fact is unverified, ask for it rather than acting on it.\n";

        return $out . "\n";
    }
}
