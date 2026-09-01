<?php

namespace App\Core\Sarah888;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * A slow reply must not land after the owner has moved on.
 *
 * Chef Red's thread, 2026-09-01: he said "Hi Sarah", then "how are you?", then "Publish now". The publish
 * router answered the third message instantly. The language model's replies to the FIRST two then arrived
 * and were written underneath it — so the same list of drafts appeared three times and there was no way to
 * tell which answer belonged to which question. Both stale rows carried a user_message_id older than the
 * message that had already been answered.
 *
 * The rule is deliberately conservative. A reply is dropped only when a NEWER owner message exists AND that
 * newer message already has its own final answer. A reply the owner is still waiting for is never discarded:
 * being slow is not the same as being obsolete, and silence would be a worse failure than a late answer.
 */
class SupersededTurnGuard
{
    /**
     * Has this turn been overtaken by a newer message that was already answered?
     *
     * @param  int  $answeringUserMessageId  the owner message this reply was written for
     */
    public function isSuperseded(int $wsId, int $answeringUserMessageId): bool
    {
        if ($answeringUserMessageId <= 0) {
            return false;
        }

        try {
            $newer = (int) (DB::table('agent_messages')
                ->where('workspace_id', $wsId)
                ->where('role', 'user')
                ->where('id', '>', $answeringUserMessageId)
                ->min('id') ?? 0);

            if ($newer <= 0) {
                return false;   // still the latest question
            }

            $answered = DB::table('agent_messages')
                ->where('workspace_id', $wsId)
                ->where('role', 'agent')
                ->where('id', '>', $newer)
                ->whereRaw("JSON_EXTRACT(metadata_json, '$.phase') = 'final'")
                ->exists();

            if ($answered) {
                Log::info('[Sarah888] dropped a reply whose question had already been overtaken and answered', [
                    'workspace_id' => $wsId,
                    'answering_message' => $answeringUserMessageId,
                    'overtaken_by' => $newer,
                ]);
            }

            return $answered;
        } catch (\Throwable $e) {
            // A failed check must never cost the owner a reply.
            Log::warning('[Sarah888] SupersededTurnGuard could not check the thread', [
                'workspace_id' => $wsId, 'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
