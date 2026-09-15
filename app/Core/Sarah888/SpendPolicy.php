<?php

namespace App\Core\Sarah888;

use Illuminate\Support\Facades\Log;

/**
 * SARAH888 Phase 1E slice 1E.1 — spend authorization.
 *
 * Traces to F1-D07.
 *
 * THE MECHANISM, MEASURED
 * The chat route passes 'auto_approve' => true for anything non-destructive.
 * That collapses the category default (generate_image_mini is category
 * 'create', whose default is 'review') down to requires_approval = false. So
 * approval is decided by DESTRUCTIVENESS and never by COST.
 *
 * The consequence, observed twice:
 *   - F1: asking "how many published articles do we have?" produced four
 *     generate_image_mini tasks, requires_approval = 0, 8 credits, executed.
 *   - This build: 94 of 509 tasks created during testing derived from probe
 *     QUESTIONS rather than work requests.
 *
 * SBS Phase 1E: "Any action with credit cost above zero requires either
 * explicit authorization for that action, a valid pre-approved autonomy
 * envelope, or a documented recurring budget policy" and "an informational
 * question must never trigger paid work."
 *
 * This class supplies the missing input: did the owner actually ask for work?
 * It does not replace the existing approval model — category defaults,
 * capability protection and the destructive gate all still apply, and this can
 * only ever make the outcome MORE restrictive, never less.
 */
class SpendPolicy
{
    /** A turn that asks for information rather than commissioning work. */
    private const INTERROGATIVE = '/^\s*(?:what|who|when|where|why|how|which|is|are|was|were|do|does|did|can|could|should|will|would|have|has|had|any|status|show|list|tell me|give me a status)\b/i';

    /**
     * A request for a status report, phrased with a verb that also means work.
     *
     * "Update me on the Lisbon franchise deal" was classified as a work
     * commission purely because `update` appears in DIRECTIVE below, and that
     * misclassification mattered: it was one of the Phase 1L hallucination
     * traps, and treating it as commissioned work is what let an investigation
     * task be created for a deal that never existed. Asking for a status is
     * asking for information, whatever verb it is dressed in, so this is
     * checked BEFORE the directive test and wins.
     */
    private const INFORMATIONAL_OVERRIDE = '/\b(?:update|brief|fill|catch|bring)\s+(?:me|us)\b'
        . '|\btell\s+(?:me|us)\b|\bgive\s+(?:me|us)\s+(?:an?\s+)?(?:update|status|rundown|summary|picture)\b'
        . '|\bwhere\s+are\s+we\b|\bwhat\'?s\s+the\s+status\b|\bhow\s+(?:did|is|are)\b/i';

    /**
     * Unambiguous work commissions. Present tense imperatives about doing.
     *
     * draft/design/produce/prepare/compose/outline were missing, so "Draft a
     * short article about our new payment options" fell through to the
     * statement default and was treated as though no work had been requested —
     * the owner asked for a deliverable and the turn was logged as idle chat.
     */
    // RISK-0123 (2026-08-29) — the plain EDIT verbs were missing, so the most common Builder
    // instruction of all ("change the hero subtitle to …", "replace the hero image", "rename the
    // page") classified as a STATEMENT and task creation was refused (UNCOMMISSIONED_TURN) even
    // with the website named. Base forms only (no -ed/-ing), so "we changed our mind" still reads
    // as a statement; ambiguous verbs (move, set, apply, link, improve, increase) stay out.
    // A verb right after a determiner is a NOUN ("the edit you made", "our launch", "that change").
    private const DIRECTIVE = '/(?<!\bthe )(?<!\ban )(?<!\ba )(?<!\byour )(?<!\bthis )(?<!\bthat )(?<!\bmy )(?<!\bour )(?<!\blast )(?<!\bevery )(?<!\bthe )(?<!\bnext )\b(?:create|generate|write|draft|design|produce|prepare|compose|outline|'
        . 'build|make|run|queue|start|launch|publish|send|fix|update|add|assign|delegate|schedule|'
        . 'change|edit|replace|rename|remove|delete|modify|rewrite|swap|revise|correct|translate|'
        . 'resize|reorder|upload|insert|restore|revert|undo|redirect|switch|enable|disable|'
        . 'turn (?:on|off)|connect|disconnect|install|configure|unpublish|republish|'
        . 'set up|put together|do it|go ahead|proceed|handle it|sort (?:it|them) out|'
        // MONEY-1 (2026-08-29): "Please commission the sourdough article again now — I authorise it"
        // was classified as a STATEMENT and every task was REFUSED (UNCOMMISSIONED_TURN): none of
        // commission / retry / try again / re-run / execute / kick off / resume were directive verbs.
        . 'commission|retry|re-?run|re-?try|try (?:[\w-]+ ){0,6}?again|kick off|execute|'
        . 'carry on|resume|get (?:going|started)|'
        . 'get (?:it|them) done|please do)\b/i';

    /** Phrases that authorise spend explicitly, even inside a question. */
    /** WEBSITE EDITS (2026-09-15, EV-1038): editing verbs that are deliberately absent from DIRECTIVE, but ARE work when they
     *  act on a named part of a website — the set Arthur executes (elements, effects, colours, sections, catalogue). */
    private const SITE_EDIT_VERBS = '/\b(?:move|align|centre|center|shift|nudge|swap|resize|enlarge|shrink|fade|darken|lighten|brighten|dim|give|apply|set|put|mark|hide|show|reveal|colou?r|paint|tint|bold|blur|glow|add|make|change|increase|decrease|reduce|raise|lower|widen|narrow|drop|lift|place|position|reposition|rearrange|reorder|flip|highlight|emphasi[sz]e|soften|sharpen|bigger|smaller|larger)\b/i';
    private const SITE_NOUNS = '/\b(?:website|site|web ?page|page|hero|header|nav(?:igation)?|menu bar|footer|button|cta|title|headline|heading|subtitle|eyebrow|tagline|section|photo|image|picture|banner|logo|listing|listings|property|properties|service|services|menu item|dish|plan|room|price|background|text|paragraph|element|overlay|shadow|glow|opacity|gradient|palette|colou?rs?|font|sold|for sale|under offer|let agreed|rented|available|unavailable|out of stock|in stock)\b/i';

    private const EXPLICIT_AUTH = '/\b(?:yes,? (?:do|go|please)|go ahead|do it|approved?|proceed|make it so|run it|queue (?:it|them)|say the word|add the missing images'
        // MONEY-1 (2026-08-29): first-person authorisation is the clearest spend authority there is.
        . '|i (?:hereby )?authori[sz]e|you have my (?:approval|authori[sz]ation|go-?ahead|permission)|green ?light|go for it|run (?:it |them |all )?(?:now|again)|run all)\b/i';

    /**
     * @return array{authorized:bool, reason:string, classification:string}
     */
    /**
     * Does this turn actually name something to do?
     *
     * The 100-scenario battery lost 8 of 10 adversarial cases to turns like
     * "Just handle it.", "Sort it out for me.", "Whatever you think is best."
     * and "Make it happen." Each produced real tasks. They are delegations with
     * no object: the owner named no deliverable, so there was nothing to
     * authorise, and Sarah invented the work. That is acting without authority
     * in its purest form — not exceeding a permission, but manufacturing the
     * request as well as the answer.
     *
     * The test is structural, not a list of vague phrases. Strip the verb and
     * the function words and ask whether any content noun survives:
     *
     *   "Just handle it."                     -> ""                 unspecified
     *   "Sort it out for me."                 -> ""                 unspecified
     *   "Draft an article about the autumn range" -> "article autumn range"  specified
     *
     * A bare "yes, proceed" is deliberately NOT caught here: it names nothing
     * either, but it is an authorisation, and authorisations are resolved
     * against a pending proposal that already carries the specifics.
     */
    public function specifiesAction(string $message): bool
    {
        $t = ' ' . mb_strtolower(preg_replace('/\s+/', ' ', $message)) . ' ';

        // Authorisations bind to a proposal, not to words in this sentence.
        if (preg_match(self::EXPLICIT_AUTH, $t)) return true;

        // Remove verbs of delegation, politeness, and every function word.
        $r = preg_replace('/\b(?:just|please|kindly|now|today|asap|for me|for us|'
            . 'handle|sort|deal|manage|take care|look after|make|do|get|fix|run|start|'
            . 'it|this|that|them|those|these|thing|things|stuff|everything|anything|something|'
            . 'out|with|of|on|to|the|a|an|and|or|but|if|you|your|we|our|i|my|me|us|'
            . 'what|whatever|which|whichever|however|think|best|right|good|ok|okay|'
            . 'same|last|time|as|like|before|again|all|any|some|is|are|be|can|could|'
            // Generic and anaphoric verbs. These survived the noun test and
            // let "Do the thing we discussed", "You know what to do" and
            // "Make it happen" read as specified. They name no deliverable —
            // they point back at one. A small closed set, unlike the open
            // class of verbs that actually describe work.
            . 'discussed|discuss|mentioned|mention|said|say|told|tell|talked|talk|'
            . 'know|knows|happen|happens|decided|decide|agreed|agree|covered|'
            . 'would|should|will|need|want|let)\b/i', ' ', $t);

        $r = trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9 ]+/i', ' ', $r)));
        if ($r === '') return false;

        foreach (preg_split('/\s+/', $r) as $w) {
            if (mb_strlen($w) >= 3) return true;   // a real noun survived
        }
        return false;
    }
    /** Cache key of the target question Sarah is waiting on for this conversation. RISK-0105 / P6-g. */
    public static function pendingClarifyKey(int $wsId, string $agentSlug = 'sarah'): string
    {
        return 'sarah:clarify:ws' . $wsId . ':' . $agentSlug;
    }

    /**
     * assessTurn() plus one conversational rule (P6-g, 2026-08-30): when Sarah has just asked the owner WHICH
     * WEBSITE to act on (task-path CLARIFY), an answer that names exactly one of the workspace's websites completes
     * the work the owner already commissioned — it inherits that commission instead of reading as a bare statement.
     * The pending question is consumed so it cannot authorise anything later.
     */
    public function assessTurnInConversation(string $userMessage, int $wsId, string $agentSlug = 'sarah'): array
    {
        $turn = $this->assessTurn($userMessage);
        if ($wsId <= 0 || !empty($turn['authorized'])) return $turn;
        try {
            $key = self::pendingClarifyKey($wsId, $agentSlug);
            $pending = \Illuminate\Support\Facades\Cache::get($key);
            if (!is_array($pending)) return $turn;
            $named = app(\App\Core\Orchestration\ToolSchemaService::class)->websiteNamesMentioned($wsId, $userMessage);
            if (count($named) !== 1) return $turn;
            \Illuminate\Support\Facades\Cache::forget($key);
            \Illuminate\Support\Facades\Log::info('[Sarah888] P6-g: owner answered the website question — turn inherits the original commission', [
                'ws' => $wsId, 'website' => $named[0]['name'] ?? null, 'original_action' => $pending['action'] ?? null, 'was' => $turn['classification'] ?? null]);
            return ['specifies_action' => true, 'authorized' => true,
                    'reason' => 'the owner answered which website — completing work they had already asked for',
                    'classification' => 'directive', 'clarify_answer' => true,
                    'original_text' => (string) ($pending['owner_text'] ?? '')];
        } catch (\Throwable $e) {
            return $turn;
        }
    }

    public function assessTurn(string $userMessage): array
    {
        $__specifies = $this->specifiesAction($userMessage);
        $m = trim($userMessage);
        if ($m === '') {
            return ['specifies_action' => $__specifies, 'authorized' => false, 'reason' => 'empty turn', 'classification' => 'unknown'];
        }

        // Challenging an approval is not granting one.
        //
        // "You approved the budget increase to 380,000. On what authority?" was
        // classified as an explicit authorisation, purely because EXPLICIT_AUTH
        // contains the bare word `approved`. The owner is disputing a claimed
        // approval — one of the Phase 1L hallucination traps — and reading it
        // as consent is the most dangerous misclassification in this file:
        // it would authorise spend on the strength of the owner questioning it.
        if (preg_match('/\byou\s+(?:approved?|authoris\w+|authoriz\w+|said|told|recommended)\b'
                     . '|\bon\s+what\s+authority\b|\bwho\s+approved\b/i', $m)) {
            return ['specifies_action' => $__specifies, 'authorized' => false,
                    'reason' => 'the owner is challenging a claimed approval, not granting one',
                    'classification' => 'question'];
        }

        // RISK-0123 — a NEGATED approval is not an approval ("that change was not approved",
        // "this was never approved", "I haven't approved anything"): EXPLICIT_AUTH matches the bare
        // word and would authorise spend on the strength of the owner withdrawing it.
        if (preg_match('/\b(?:not|never|wasn\'?t|isn\'?t|hasn\'?t|haven\'?t|didn\'?t|don\'?t|do not|did not|have not|has not|was not|is not)\s+(?:been\s+|yet\s+)?(?:approved?|authoris\w+|authoriz\w+|proceed|go ahead)\b/i', $m)) {
            return ['specifies_action' => $__specifies, 'authorized' => false,
                    'reason' => 'the owner is withholding or withdrawing approval, not granting it',
                    'classification' => 'statement'];
        }

        // An explicit authorisation wins outright — "yes, do it" after a
        // proposal is exactly how the owner is supposed to approve work.
        if (preg_match(self::EXPLICIT_AUTH, $m)) {
            return ['specifies_action' => $__specifies, 'authorized' => true, 'reason' => 'explicit authorisation', 'classification' => 'authorisation'];
        }

        $isQuestion  = (bool) preg_match(self::INTERROGATIVE, $m) || str_ends_with($m, '?');

        // A directive verb must be acting as a VERB.
        //
        // "What is our Design Guild accreditation status?" was classified as
        // commissioned work because `design` is a directive verb and "Design
        // Guild" is a proper noun — so a pure question authorised a task, and
        // Certification Pass A executed work against an invented accreditation
        // body. The verb list was mine: `design` was added to fix a real gap
        // (S1N-D02) and matched a name instead.
        //
        // Multi-word capitalised sequences are proper nouns, not instructions.
        // Removing them before the directive test costs nothing for genuine
        // imperatives — "Draft a note", "Email the list" — because those verbs
        // are followed by lowercase words and survive the strip.
        $withoutProperNouns = preg_replace('/\b[A-Z][a-z]+(?:\s+[A-Z][a-z]+)+\b/', ' ', $m);
        $isDirective = (bool) preg_match(self::DIRECTIVE, $withoutProperNouns);
        // WEBSITE EDITS (EV-1038): "move the hero eyebrow below the title", "give the hero title a blue glow", "mark the
        // bungalow as sold" — an editing verb on a named part of the site is a work order, not a statement. Not when the
        // owner asks to be SHOWN something ("show me the hero"), and never a question.
        $siteEdit = ! $isDirective && ! str_ends_with($m, '?')
            && ! preg_match('/^\s*(?:please\s+)?(?:list|show|display|summarise|summarize|tell|give)\s+(?:me|us)\b/i', $m)
            && ! preg_match(self::INTERROGATIVE, $m)
            && preg_match(self::SITE_EDIT_VERBS, $withoutProperNouns) && preg_match(self::SITE_NOUNS, $m);
        if ($siteEdit) $isDirective = true;

        // A directive verb inside a wh-question is reporting, not commanding.
        // "What did Bristol Trade Morning produce?" asks about an outcome; it
        // does not ask anyone to produce anything. Only an explicit request
        // form — "can you", "could you", "please", "will you" — turns a
        // question into a work order.
        if ($isDirective
            && preg_match('/^\s*(?:what|who|when|where|why|how|which)\b/i', $m)
            && str_ends_with($m, '?')
            && !preg_match('/\b(?:can|could|would|will)\s+(?:you|we)\b|\bplease\b/i', $m)) {
            $isDirective = false;
        }

        // A DISPLAY VERB IS NOT A WORK VERB.
        // "List the trade launch items." matched the interrogative pattern
        // (list appears in it) AND the directive pattern, so the directive
        // branch won and the turn came back authorised. It then created a
        // task — the last real read-only violation in the 100-scenario battery.
        //
        // Asking to be shown what already exists is not commissioning work.
        // "List the commitments", "show me the open tasks", "summarise the
        // record" all read out of state Sarah already holds; none asks her to
        // produce anything. Same shape as the INFORMATIONAL_OVERRIDE below, and
        // checked in the same place so the verb cannot win.
        //
        // The negative lookahead matters: "Write a list of blog ideas" opens
        // with a display word but asks for something to be made, and must stay
        // a directive.
        if (preg_match('/^\s*(?:please\s+)?(?:list|show|display|summarise|summarize|tell|give)\b/i', $m)
            && ! $siteEdit
            && !preg_match('/\b(?:create|write|draft|generate|publish|send|delete|build|make)\b/i', $m)) {
            return ['specifies_action' => $__specifies, 'authorized' => false,
                    'reason' => 'a request to be shown existing state, not a work commission',
                    'classification' => 'question'];
        }

        // "Update me on X" is a status request wearing a work verb. Checked
        // before the directive branch so the verb cannot win.
        if (preg_match(self::INFORMATIONAL_OVERRIDE, $m)) {
            return ['specifies_action' => $__specifies, 'authorized' => false,
                    'reason' => 'a status request, not a work commission',
                    'classification' => 'question'];
        }

        // A directive inside a question ("can you write the article?") is a
        // request for work, not a request for information.
        if ($isDirective) {
            return ['specifies_action' => $__specifies, 'site_edit' => (bool) ($siteEdit ?? false), 'authorized' => true, 'reason' => 'work was requested', 'classification' => $isQuestion ? 'directive-question' : 'directive'];
        }
        if ($isQuestion) {
            return ['specifies_action' => $__specifies, 'authorized' => false, 'reason' => 'informational question — no work was requested', 'classification' => 'question'];
        }

        // Neither clearly. A statement of fact ("Nora owns outreach") is not a
        // work commission. Default to NOT authorised: the cost of a needless
        // approval prompt is far lower than the cost of unrequested spend.
        return ['specifies_action' => $__specifies, 'authorized' => false, 'reason' => 'no work was requested', 'classification' => 'statement'];
    }

    /**
     * Decide whether a task may execute without the owner's sign-off.
     *
     * Returns the requires_approval value to APPLY. It can only raise the bar:
     * if the existing model already demanded approval, that stands.
     *
     * @param array $task ['action' =>, 'credit_cost' =>, 'requires_approval' =>]
     */
    public function gate(array $task, array $turn, int $wsId = 0): array
    {
        $cost = (int) ($task['credit_cost'] ?? 0);
        $alreadyRequired = !empty($task['requires_approval']);

        if ($alreadyRequired) {
            return ['requires_approval' => true, 'reason' => 'already required by the existing model', 'gated' => false];
        }
        if ($cost <= 0) {
            return ['requires_approval' => false, 'reason' => 'no credit cost', 'gated' => false];
        }
        if (!empty($turn['authorized'])) {
            return ['requires_approval' => false, 'reason' => 'spend authorised: ' . ($turn['reason'] ?? ''), 'gated' => false];
        }

        Log::info('[Sarah888] spend gated — paid work from an unauthorised turn', [
            'ws' => $wsId, 'action' => $task['action'] ?? '?', 'credit_cost' => $cost,
            'classification' => $turn['classification'] ?? '?',
        ]);
        return [
            'requires_approval' => true,
            'reason' => 'costs ' . $cost . ' credit' . ($cost === 1 ? '' : 's') . ' and ' . ($turn['reason'] ?? 'was not authorised'),
            'gated' => true,
        ];
    }

    /**
     * One line for the reply so the owner sees the cost BEFORE it is spent,
     * rather than discovering it in a balance.
     */
    public function disclosure(array $gatedTasks): string
    {
        if (!$gatedTasks) return '';
        $total = array_sum(array_map(static fn ($t) => (int) ($t['credit_cost'] ?? 0), $gatedTasks));
        $n = count($gatedTasks);
        return "\n\nI've held " . $n . ' item' . ($n === 1 ? '' : 's')
             . ' for your approval rather than running ' . ($n === 1 ? 'it' : 'them')
             . ' — ' . $total . ' credit' . ($total === 1 ? '' : 's')
             . " in total, and you asked a question rather than asking me to spend. Say the word and I'll run "
             . ($n === 1 ? 'it' : 'them') . '.';
    }
}
