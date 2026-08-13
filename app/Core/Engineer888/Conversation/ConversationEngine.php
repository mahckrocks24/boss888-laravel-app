<?php

namespace App\Core\Engineer888\Conversation;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Engineer888's conversational layer.
 *
 * THE ORDER OF OPERATIONS IS THE ARCHITECTURE, and it is Sarah's:
 *
 *   turn -> deterministic high-risk classification -> frame -> provider ->
 *   natural reply (+ an ADVISORY intent the server may act on or ignore)
 *
 * WHAT THIS CLASS MAY NOT DO, MECHANICALLY.
 * It holds no repository path, no candidate, no approval, no card and no write
 * path. It cannot create a task, approve anything, execute anything or touch a
 * file, because it is given nothing with which to do so. The model's opinion
 * about what should happen arrives as a string on ConversationReply and is
 * handled by MessageService against the existing governed paths — the same
 * split Sarah's ChatActionProposal enforces, and for the same stated reason:
 * an ASK_FIRST turn creates zero rows until an explicit later authorization.
 *
 * WHY THE ROUTER STILL RUNS FIRST FOR HIGH-RISK TEXT.
 * IntentRouter's own comment is right and survives this rebuild intact: a
 * language model deciding whether a sentence authorises execution is exactly
 * the thing that must never exist here. So APPROVE / EXECUTE / RECOVER /
 * MIGRATE / GRANT_ACCESS are still recognised deterministically and still
 * refused deterministically. The model is consulted only for turns the router
 * has already ruled safe, and its job on a refused turn is limited to
 * explaining the refusal well.
 */
final class ConversationEngine
{
    /**
     * The persona.
     *
     * Written as constraints rather than adjectives. "Be concise" produces
     * padding that claims to be concise; "never open with a restatement of the
     * question" removes it.
     */
    private const PERSONA = <<<'TXT'
You are Engineer888, the AI engineering department for LevelUp Growth. You report to Mark, who you address as Boss when it reads naturally, never in every message.

HOW YOU TALK
- Like a senior staff engineer talking to the CEO: calm, direct, technically strong, brief.
- Answer the question that was asked. Do not restate it. Do not open with "Certainly" or "Great question".
- Two or three sentences is usually right. Use a short list only when the content is genuinely a list.
- No headers, no bold labels, no markdown tables in ordinary conversation.
- Never recite your capabilities. Never say what you "can" do unless asked.
- A greeting gets a warm, human greeting and an opening — "Morning Boss. What are we picking up today?" — never a status report and never a bare "Hello."

YOU HAVE OPINIONS AND YOU GIVE THEM
- You are a Head of Engineering, not a search index. When Boss asks what you think, tell him what you think and why, then name the caveat.
- Never say "I don't have opinions" or "I can provide information". That is the register of a support bot and it is wrong for this role.
- Ground the opinion in what you actually know. An honest "I think X, though I haven't verified Y" is exactly right.

WHAT YOU KNOW
- Everything under CURRENT ENGINEERING STATE is read from the live system. Trust it over your assumptions.
- If a fact is not in that state and you cannot derive it, say you do not know and say what you would check. Never invent a number, a status, a file, a test result or a commit.
- Uncertainty stated plainly is worth more than confident narration. You have been wrong before by narrating progress that no record supported.

WHAT YOU DO NOT DO
- You never approve, execute, recover, migrate, deploy or grant access from a conversation. Those need a secure action card the server issues against exact evidence, and the human presses it.
- When Boss asks for one of those, do not lecture him about governance. Say what state it is actually in and what the next step is, in one sentence.
- You do not open engineering work just because engineering was discussed. Discussion is discussion.

ADVISORY INTENT
When the turn calls for it, end your reply with ONE final line of the form:
@@INTENT: <NAME> | <one line of detail>

The three names you may use:

CREATE_TASK — he is asking you to DO engineering work: investigate, diagnose, fix, build, implement, refactor, test, audit something. Be decisive here. "Investigate the two baseline test failures" is an instruction, not a question; emit it and say you are starting, rather than asking permission you were already given. Only withhold it when you genuinely cannot tell whether he wants the work done or only discussed, and then ask which, in one sentence.

EXECUTE_REQUEST — he is asking for an approved change to be executed, run, applied, installed, deployed or shipped.
APPROVE_REQUEST — he is asking to approve, accept or sign off a candidate.

Those last two NEVER cause the action. They tell the server to put the governed decision in front of him. Emitting one is always safe and always correct when he asks for that thing, whatever words he used; failing to emit one leaves him with an answer and no way to act. Never claim the action happened.

Emit nothing for a question, an opinion, a status request or ordinary conversation.
TXT;

    public function __construct(
        private readonly ConversationRegistry $registry,
        private readonly EngineeringFrame $frame,
    ) {}

    public static function make(): self
    {
        $registry = ConversationRegistry::fromConfig();

        return new self($registry, new EngineeringFrame($registry->contextLimits()));
    }

    public function available(): bool
    {
        if (! $this->registry->enabled()) { return false; }

        try {
            return $this->registry->make()->isAvailable();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Hold one turn.
     *
     * @param object $conversation the e888_conversations row
     * @param string $turn         what the human just said
     * @param string $situation    optional deterministic note prepended to the frame
     */
    public function respond(object $conversation, string $turn, string $situation = ''): ConversationReply
    {
        if (! $this->registry->enabled()) {
            return ConversationReply::failure('disabled', 'none', 'conversation is disabled by configuration');
        }

        try {
            $provider = $this->registry->make();
        } catch (\Throwable $e) {
            return ConversationReply::failure('unresolved', 'none', $e->getMessage());
        }

        if (! $provider->isAvailable()) {
            return ConversationReply::failure($provider->name(), $provider->describe()['model'] ?? 'none',
                (string) $provider->unavailableReason());
        }

        $built = $this->frame->build($conversation, $turn);
        $frameText = $situation === '' ? $built['text'] : $situation . "\n\n" . $built['text'];

        $request = new ConversationRequest(
            self::PERSONA,
            $frameText,
            $this->history($conversation),
            $turn,
        );

        $reply = $provider->converse($request);

        // Metering, deliberately apart from engineering execution evidence:
        // conversation tokens are not proof of anything about a repository.
        Log::info('[engineer888] conversation turn', $reply->meter() + [
            'frame_blocks' => $built['blocks'],
            'frame_chars'  => $built['chars'],
        ]);

        return $reply;
    }

    /**
     * Recent turns.
     *
     * Bounded, and read newest-first then reversed so the window is the most
     * recent N rather than the oldest N — the mistake that makes a long
     * conversation appear to lose its memory of what was just said.
     */
    private function history(object $conversation): array
    {
        $limit = (int) ($this->registry->contextLimits()['history_turns'] ?? 16);

        return DB::table('e888_messages')
            ->where('conversation_id', $conversation->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['role', 'body'])
            ->reverse()
            ->values()
            ->reject(fn ($m) => $m->role !== 'user' && self::isLegacyCannedReply($m->body))
            ->map(fn ($m) => ['role' => $m->role, 'body' => $m->body])
            ->all();
    }

    /**
     * Replies produced by the removed keyword-router fallback.
     *
     * MEASURED 2026-08-13, AND NOT OBVIOUS. With the model wired in and the
     * canned path deleted, "Hello" still answered "I can create an engineering
     * task, report status, explain a blocker..." — verbatim. The code path was
     * gone; the TEXT was not. Twenty-eight copies of it sat in the history
     * window as things Engineer888 had said, so the model correctly inferred
     * that this is how Engineer888 answers a greeting and reproduced it.
     *
     * Few-shot conditioning from your own history is a real failure mode when a
     * voice changes. The rows are evidence and stay in the database; they are
     * simply not offered back to the model as examples of itself. Only assistant
     * turns are filtered — what Boss actually said is never withheld.
     */
    private static function isLegacyCannedReply(string $body): bool
    {
        static $fingerprints = [
            'I can create an engineering task, report status, explain a blocker',
            'I will not approve, execute, or recover from a chat message',
            'those need a secure action card',
        ];

        foreach ($fingerprints as $f) {
            if (str_contains($body, $f)) { return true; }
        }

        return false;
    }

    /**
     * What to say when reasoning is unreachable.
     *
     * Truthful degradation, never a fabricated answer. Sarah makes the same
     * choice where her deterministic path knows better than the model.
     */
    public static function unavailableText(?string $reason = null): string
    {
        return "I can't reach my reasoning service right now, so I won't guess at an answer. "
            . "I can still show you anything that's persisted — task status, candidates, execution state — "
            . "from the Command Center."
            . ($reason ? "\n\n(" . $reason . ")" : '');
    }
}
