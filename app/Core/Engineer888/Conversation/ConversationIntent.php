<?php

namespace App\Core\Engineer888\Conversation;

/**
 * The advisory intents a reply may name — the whole list, in one place.
 *
 * ── ADVISORY MEANS ADVISORY ─────────────────────────────────────────
 *
 * Nothing here is authority. An intent is the model saying what it thinks Boss
 * is asking for; the deterministic layer may act on it, ignore it or refuse it.
 * Approving, executing and recovering still require a card the server issued
 * against exact evidence, and no name in this list changes that. What the list
 * decides is only whether a recognised REQUEST was made.
 *
 * ── WHY THE SET IS CLOSED, AND WHY IT LIVES HERE ────────────────────
 *
 * It used to live nowhere. Four call sites in MessageService and two in
 * DecisionPresentationResolver each carried their own string literals, and the
 * parser did not consult any of them — it took whatever came before the first
 * '|', stripped every character that was not a letter or underscore, and
 * handed the result on.
 *
 * That is fine while the model punctuates perfectly. On 2026-08-14 it did not:
 *
 *     @@INTENT: SHOW_DECISIONS paste here one by one
 *
 * became SHOW_DECISIONSPASTEHEREONEBYONE, matched nothing, and Boss was told
 * "I'll display them here for you" above an empty space. Measured over four
 * live runs of that turn the separator was present twice and absent twice — so
 * the structured-presentation contract was a coin flip, and on a loss the model
 * fell back to writing the numbered list the contract exists to prevent.
 *
 * The repair is not a firmer instruction about punctuation. A contract that
 * depends on the model getting punctuation right is not a contract. The server
 * owns the vocabulary; the parser resolves against it; everything after the
 * matched name is a hint and nothing more.
 */
final class ConversationIntent
{
    /** Show everything currently waiting, here in the conversation. */
    public const SHOW_DECISIONS = 'SHOW_DECISIONS';

    /** Show one specific decision. The words after it say which. */
    public const SHOW_DECISION = 'SHOW_DECISION';

    /** Open the Decisions surface rather than drawing cards in chat. */
    public const OPEN_DECISIONS = 'OPEN_DECISIONS';

    /** Boss is asking for engineering work to be done. */
    public const CREATE_TASK = 'CREATE_TASK';

    /** Boss is asking for approved work to be run. Never causes it. */
    public const EXECUTE_REQUEST = 'EXECUTE_REQUEST';

    /** Boss is asking to approve a candidate. Never causes it. */
    public const APPROVE_REQUEST = 'APPROVE_REQUEST';

    /**
     * Every name, LONGEST FIRST.
     *
     * The ordering is load-bearing. Matched shortest-first, "SHOW_DECISIONS"
     * resolves as SHOW_DECISION with a subject of "S" — the plural silently
     * becomes the singular and Boss is shown one decision when he asked for
     * all of them. AdvisoryIntentParsingTest asserts this order directly.
     */
    public const ALL = [
        self::EXECUTE_REQUEST,   // 15
        self::APPROVE_REQUEST,   // 15
        self::SHOW_DECISIONS,    // 14
        self::OPEN_DECISIONS,    // 14
        self::SHOW_DECISION,     // 13
        self::CREATE_TASK,       // 11
    ];

    /**
     * Resolve a raw sentinel body into a known intent and a free-text hint.
     *
     * Accepts the separator and does not require it:
     *
     *     SHOW_DECISIONS | what needs my approval
     *     SHOW_DECISIONS what needs my approval
     *     SHOW_DECISIONS
     *
     * A name outside the set yields [null, null]. The caller still strips the
     * line from what Boss reads — an unrecognised sentinel is not a message,
     * and a leaked control token in a conversation about execution reads like a
     * command he was not meant to see.
     *
     * @return array{0:?string,1:?string} [name, subject]
     */
    public static function resolve(string $raw): array
    {
        $raw = trim($raw);

        if ($raw === '') { return [null, null]; }

        $upper = strtoupper($raw);

        foreach (self::ALL as $name) {
            if (! str_starts_with($upper, $name)) { continue; }

            // The remainder is a HINT. It is never an identity: the server
            // resolves what it refers to, and a hint matching nothing yields
            // nothing rather than something arbitrary.
            $subject = trim(substr($raw, strlen($name)));
            $subject = ltrim($subject, " \t|:-—–");
            $subject = trim($subject);

            return [$name, $subject === '' ? null : $subject];
        }

        return [null, null];
    }
}
