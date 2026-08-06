<?php

namespace App\Core\Engineer888\Chat;

/**
 * What did the administrator MEAN, and is that something free text may do?
 *
 * TWO SEPARATE QUESTIONS, DELIBERATELY. Recognising "approve it" is useful —
 * Engineer888 should answer with the right card rather than a shrug. Acting on
 * it is not. So high-risk intents are RECOGNISED and then REFUSED, and the
 * refusal carries the card that can do the thing properly.
 *
 * Silently misclassifying "approve" as a general question would be worse than
 * refusing it: the administrator would think they had approved something.
 *
 * NARROW AND EXPLICIT, NOT A MODEL. This is a keyword router, and it is meant
 * to be. A language model deciding whether a sentence authorises execution is
 * exactly the thing that must never exist here — its failure mode is to approve
 * something on a plausible-sounding sentence. Anything it cannot place with
 * confidence becomes a general question, which is safe.
 */
final class IntentRouter
{
    // What free text may do on its own.
    public const CREATE_TASK = 'CREATE_TASK';
    public const TASK_STATUS = 'TASK_STATUS';
    public const TASK_BLOCKER = 'TASK_BLOCKER';
    public const PROJECT_STATUS = 'PROJECT_STATUS';
    public const REVIEW_CANDIDATE = 'REVIEW_CANDIDATE';
    public const VIEW_VERIFICATION = 'VIEW_VERIFICATION';
    public const VIEW_RECOVERY = 'VIEW_RECOVERY';
    public const VIEW_MIGRATION_REHEARSAL = 'VIEW_MIGRATION_REHEARSAL';
    public const GENERAL_ENGINEERING_QUESTION = 'GENERAL_ENGINEERING_QUESTION';

    // Recognised, never performed from text.
    public const REQUIRES_ACTION_CARD = 'REQUIRES_ACTION_CARD';

    /**
     * Intents that a secure action card exists for, keyed by what the text
     * appeared to ask for. Recorded on the message so the refusal can name the
     * right card rather than a generic one.
     */
    private const HIGH_RISK = [
        'APPROVE_CANDIDATE' => ['approve', 'approved', 'approve it', 'lgtm', 'ship it', 'go ahead', 'do it', 'yes proceed', 'accept the candidate'],
        'EXECUTE_TASK' => ['execute', 'run it', 'apply it', 'install it', 'deploy it', 'make the change'],
        'APPROVE_RECOVERY' => ['approve recovery', 'restore it', 'roll it back', 'recover it'],
        'APPROVE_MIGRATION' => ['approve migration', 'run the migration', 'migrate it'],
        'GRANT_ACCESS' => ['grant access', 'give access', 'add me', 'revoke access', 'remove access'],
    ];

    /**
     * TIER 2 — EXPLICIT CONVERSATIONAL COMMANDS.
     *
     * These are REGEX, not bare keywords, because a bare keyword is a noun and
     * nouns appear inside the things people ask to have built. "Investigate why
     * the topbar STATUS widget is stuck" was routed to TASK_STATUS on the word
     * "status" alone, and answered a request for work with a list of tasks. It
     * created nothing, so it failed safe — but it was wrong, and a router that
     * is wrong safely is still wrong.
     *
     * Each pattern therefore requires the word to be ABOUT a task, not merely
     * present in the sentence.
     */
    private const COMMANDS = [
        self::TASK_STATUS => [
            '/\bstatus\s+(of|for|on)\b/i',
            '/\btask\s+status\b/i',
            '/\bwhat\s+stage\s+is\b/i',
            '/\bwhere\s+is\s+(the\s+)?task\b/i',
            '/\bhow\s+is\s+(the\s+)?task\b/i',
            '/\bwhere\s+are\s+we\b/i',
            '/\bprogress\s+(of|on)\b/i',
            '/\bupdate\s+on\s+(the\s+)?task\b/i',
        ],
        self::TASK_BLOCKER => [
            '/\bwhy\s+is\s+(the\s+)?task\b.*\bblocked\b/i',
            '/\btask\b.*\bblocked\b/i',
            '/\bwhat(\s+is|\'s)\s+blocking\b/i',
            '/\bshow\s+(the\s+)?blocker/i',
        ],
        self::REVIEW_CANDIDATE => [
            '/\b(open|show|review)\s+(the\s+)?candidate\b/i',
            '/\bshow\s+(me\s+)?the\s+diff\b/i',
        ],
        self::VIEW_VERIFICATION => [
            '/\bdid\s+the\s+tests?\s+pass\b/i',
            '/\bverification\s+(result|state|status)\b/i',
            '/\bdid\s+it\s+verify\b/i',
        ],
        self::VIEW_RECOVERY => [
            '/\b(open|show)\s+(the\s+)?recovery\b/i',
            '/\brecovery\s+(state|status)\b/i',
        ],
        self::VIEW_MIGRATION_REHEARSAL => [
            '/\bmigration\s+rehearsal\b/i',
            '/\brehearsal\s+(result|state|status)\b/i',
        ],
        self::PROJECT_STATUS => [
            '/\b(list|what|which)\s+projects\b/i',
            '/\bproject\s+status\b/i',
        ],
    ];

    /**
     * TIER 3 — ENGINEERING WORK REQUESTS.
     *
     * Verbs, checked AFTER the explicit commands above. A sentence that asks for
     * work is a task even when it happens to contain a status-shaped noun.
     */
    private const WORK = [
        '/\binvestigate\b/i', '/\bdiagnose\b/i', '/\baudit\b/i', '/\btrace\b/i',
        '/\b(review|find out|work out|figure out|check)\s+why\b/i',
        '/\blook\s+into\b/i',
        '/\b(fix|repair|build|implement|add|create|write)\b/i',
        '/\bwhy\s+(is|are|does|do|did)\b/i',
    ];

    /**
     * @return array{intent:string, blocked_action:?string, matched:?string}
     */
    public function route(string $body): array
    {
        $text = strtolower(trim($body));

        if ($text === '') {
            return $this->result(self::GENERAL_ENGINEERING_QUESTION);
        }

        // HIGH RISK FIRST. "approve the candidate" contains "candidate"; if the
        // safe patterns were tested first it would route to REVIEW_CANDIDATE and
        // the administrator would be shown a diff when they believed they had
        // approved one. Refusing is only useful if refusal wins ties.
        foreach (self::HIGH_RISK as $action => $phrases) {
            foreach ($phrases as $phrase) {
                if ($this->mentions($text, $phrase)) {
                    return $this->result(self::REQUIRES_ACTION_CARD, $action, $phrase);
                }
            }
        }

        // TIER 2 — explicit conversational commands.
        foreach (self::COMMANDS as $intent => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $text)) {
                    return $this->result($intent, null, $pattern);
                }
            }
        }

        // TIER 3 — engineering work requests.
        foreach (self::WORK as $pattern) {
            if (preg_match($pattern, $text)) {
                return $this->result(self::CREATE_TASK, null, $pattern);
            }
        }

        // TIER 4 — unrecognised is a question, never an instruction.
        return $this->result(self::GENERAL_ENGINEERING_QUESTION);
    }

    /** Does free text alone permit this intent to change anything? */
    public function isSafeForFreeText(string $intent): bool
    {
        return $intent !== self::REQUIRES_ACTION_CARD;
    }

    /**
     * Word-boundary match.
     *
     * Substring matching would fire "approve" inside "disapprove" and
     * "unapproved", turning a rejection into a refusal-to-approve message that
     * reads as though approval was attempted.
     */
    private function mentions(string $haystack, string $needle): bool
    {
        return (bool) preg_match('/(?<![a-z])' . preg_quote($needle, '/') . '(?![a-z])/i', $haystack);
    }

    private function result(string $intent, ?string $blocked = null, ?string $matched = null): array
    {
        return ['intent' => $intent, 'blocked_action' => $blocked, 'matched' => $matched];
    }
}
