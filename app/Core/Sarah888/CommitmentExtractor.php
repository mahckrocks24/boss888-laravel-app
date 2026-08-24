<?php

namespace App\Core\Sarah888;

/**
 * SARAH888 Phase 1B slice 1B.2 — deterministic commitment extraction.
 *
 * Traces to F1-D01. In the F1 stress test the CEO dictated roughly seventy
 * commitments across 163 turns and exactly one task was created, because
 * nothing in the chat path ever converted a stated intention into durable
 * state. This is that missing step.
 *
 * WHY DETERMINISTIC RATHER THAN AN LLM CALL
 * An extraction pass runs on EVERY user turn. An LLM call there would add a
 * second provider round trip to a path whose latency is already the subject of
 * F1-D11, cost credits on every message the user types, and — worst — make the
 * executive record itself probabilistic. A missed commitment is recoverable;
 * a hallucinated one corrupts the record Sarah is supposed to be trusted for.
 *
 * So this layer only claims what it can prove from the sentence. It is
 * deliberately conservative: high precision, accepted recall loss. Anything it
 * is unsure of becomes a 'proposed' commitment requiring confirmation rather
 * than a silent fact. LLM-assisted extraction for genuinely ambiguous phrasing
 * is a later slice and will sit ON TOP of this, never replace it.
 */
class CommitmentExtractor
{
    /** Speculation. The CEO is thinking aloud, not committing. */
    private const SPECULATIVE = '/\b(maybe|perhaps|might|could we|what if|thinking about|considering|
                                    idea|brainstorm|not sure|possibly|one day|someday|eventually|
                                    wondering|hypothetically|in theory|down the line)\b/ix';

    /** Named people/agents the workspace can actually own work. */
    private const OWNER_WORD = '[A-Z][a-z]{1,19}';

    /**
     * Words that are capitalised at the start of a sentence but are not people.
     *
     * The owner patterns are matched case-insensitively so that "nora owns
     * outreach" still resolves, which meant OWNER_WORD's leading [A-Z] was not
     * actually enforcing anything: "tag leads by source" produced a commitment
     * titled "by source" owned by "tag". The capital is now verified against the
     * raw clause AND the token checked against this list, because an imperative
     * verb at the head of a clause is capitalised exactly like a first name.
     */
    private const NOT_A_PERSON = [
        'add','tag','log','move','push','cancel','drop','make','give','send','set','build','write',
        'plan','book','call','clean','train','film','edit','shoot','print','contact','follow','redo',
        'fix','back','install','migrate','reconcile','chase','replace','service','retrain','compress',
        'refresh','dedupe','stocktake','cost','park','hold','stop','keep','pull','show','list','put',
        'the','this','that','these','those','and','but','our','their','his','her','its','all','both',
        'wholesale','event','events','site','project','budget','hiring','investor','domain','supper',
        'twenty','thirty','first','second','third','next','last','every','each','more','also','then',
        'morning','today','tomorrow','yesterday','monday','tuesday','wednesday','thursday','friday',
        'saturday','sunday','january','february','march','april','june','july','august','september',
        'october','november','december','emergency','task','tasks','q1','q2','q3','q4',
    ];

    /** A reference that only means something relative to what was just said. */
    private const PRONOUN = '/^(that|this|it|them|those|these|the same|same|the one|one)$/i';

    public function extract(string $text): array
    {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > 4000) return [];

        // A question is a request for information, not an obligation. "What's my
        // top priority?" must never become a commitment.
        $isQuestion = (bool) preg_match('/^\s*(what|who|when|where|why|how|which|is|are|do|does|did|can|could|should|will|would|have|has)\b/i', $text)
                      || str_ends_with($text, '?');

        $out = [];
        foreach ($this->splitClauses($text) as $clause) {
            $c = trim($clause);
            if ($c === '' || mb_strlen($c) < 6) continue;
            $speculative = (bool) preg_match(self::SPECULATIVE, $c);

            // An enumerated list is the single most common way an executive
            // dictates work, and it was being dropped whole — see
            // expandEnumeration() for why that cost sixteen commitments in one
            // turn. Items are emitted directly because a bare noun phrase
            // ("seating plan") matches none of the sentence patterns below.
            $items = $this->expandEnumeration($c);
            if ($items !== null) {
                foreach ($items as $item) {
                    $hit = ['type' => 'commitment', 'title' => $item,
                            'deadline' => null, 'deadline_text' => null, 'confidence' => 0.8];
                    if ($speculative || $isQuestion) {
                        $hit['confidence'] = 0.4;
                        $hit['reason'] = $speculative ? 'speculative' : 'interrogative';
                    }
                    $hit['source_text'] = mb_substr($c, 0, 500);
                    $out[] = $hit;
                }
                continue;
            }

            foreach ([
                $this->matchCancellation($c),
                $this->matchRename($c),
                $this->matchOwnership($c),
                $this->matchDeadlineChange($c),
                $this->matchCommitment($c),
            ] as $hit) {
                if (!$hit) continue;
                // Speculation and questions never auto-record. They can still be
                // surfaced as proposals, but they must be confirmed first.
                if ($speculative || $isQuestion) {
                    $hit['confidence'] = min($hit['confidence'], 0.4);
                    $hit['reason'] = $speculative ? 'speculative' : 'interrogative';
                }
                $hit['source_text'] = mb_substr($c, 0, 500);
                $out[] = $hit;
                break; // one intent per clause — the most specific match wins
            }
        }
        return $this->dedupe($this->dropEmpty($out));
    }

    /** Split on sentence and list boundaries so one turn can carry many intents. */
    private function splitClauses(string $text): array
    {
        $parts = preg_split('/(?<=[.!?;])\s+|\n+|(?<=,)\s+(?=(?:and\s+)?(?:cancel|rename|move|push|assign)\b)/i', $text) ?: [];
        return array_filter(array_map('trim', $parts));
    }

    /**
     * Expand "run sheet, seating plan, menu costing, wine pairings, …" into one
     * commitment per item, or return null when the clause is not a list.
     *
     * WHY THIS EXISTS
     * splitClauses only broke on sentence terminators, so a comma-delimited
     * dictation arrived as a single clause that matched no pattern and was
     * discarded in full. In the Phase 1H run three turns of this shape carried
     * fifty-six items and produced zero records — and because the items were
     * never stored, the later "cancel the wine pairings" had nothing to resolve
     * against and was dropped too. One missed split silently took out both the
     * commitments and the cancellations that referred to them.
     *
     * WHY IT IS CONSERVATIVE
     * Prose contains commas. Shredding "I spoke to Nora, who says the stockists
     * are slipping, so we need a call" into three commitments would be worse
     * than missing it. An item therefore has to look like a piece of dictated
     * work: short, and free of the finite verbs and connectives that mark a
     * clause as a sentence rather than a list entry. Any single item failing
     * that test disqualifies the whole clause and it falls through to the normal
     * sentence matchers untouched.
     */
    private function expandEnumeration(string $clause): ?array
    {
        // "More for the list:", "Task dump:", "Twenty more:", "Add these —",
        // and — the case that got through — "Three fronts this quarter:".
        // Keying on five specific words left the introducer glued to the first
        // item, so Phase 1L recorded "Three fronts this quarter: the winter
        // market residency" as a commitment title (S1L-N01). Any short prefix
        // ending in a colon introduces what follows; that is what a colon is.
        $body = $clause;
        if (preg_match('/^.{0,60}?\b(?:list|dump|more|these|following)\b\s*[:—-]\s*(.+)$/is', $clause, $m)) {
            $body = $m[1];
        } elseif (preg_match('/^([^:]{3,60}):\s*(.+)$/s', $clause, $m2)
                  && !preg_match('/\b(?:https?|www)\b/i', $m2[1])) {
            $body = $m2[2];
        }

        // The Oxford comma matters here. Splitting on ",\s*" alone left the last
        // item as "and a post-event survey", which is then a different string
        // from the "post-event survey" the owner cancels two turns later — so
        // the cancellation resolves to nothing. The conjunction is consumed as
        // part of the separator, and stripped again as a leading word for the
        // comma-less "x, y and z" form.
        // "Clear the pallet invoice for 14,800 pounds" was shredded into
        // "Clear the pallet invoice for 14" and "800 pounds" — the splitter
        // treated a thousands separator as a list separator. A comma sitting
        // between two digits is never punctuation between list items, so it is
        // masked before the split and restored after. Purely structural: no
        // vocabulary, no false positives available to it.
        $body = preg_replace('/(?<=\d),(?=\d{3}(?!\d))/', "\x01", $body);
        $raw = preg_split('/\s*,\s*(?:and\s+|&\s*)?|\s+and\s+|\s*&\s*/i', $body) ?: [];
        $raw = array_map(static fn ($p) => str_replace("\x01", ',', $p), $raw);
        $items = [];
        foreach ($raw as $piece) {
            $piece = $this->clean(preg_replace('/^(?:and|a|an|the|also|plus|then)\s+/i', '', trim($piece)));
            if ($piece !== '') $items[] = $piece;
        }
        if (count($items) < 3) return null;

        // "Band them critical, high, medium, low." produced four commitments,
        // three of which were the bare words "high", "medium" and "low". Those
        // are not work — they are the arguments of the single verb in the head
        // clause. The discriminator is that the trailing members are bare,
        // single-word points on a scale rather than things that can be done.
        //
        // This IS a lexical list, deliberately. Deciding that "low" is a
        // magnitude while "stocktake" is a task is a fact about English, not a
        // policy about capability, so it cannot be derived structurally without
        // a part-of-speech tagger. It is scoped to grading adjectives only and
        // never consulted for governance — the typed capability registry in
        // ActionAuthority remains the only thing that decides what may be done.
        $scale = 0;
        foreach (array_slice($items, 1) as $tail) {
            if (preg_match('/^(?:critical|urgent|high|medium|moderate|low|minor|major|normal|standard|trivial|blocker|small|medium|large|p[0-4])$/i', trim($tail))) {
                $scale++;
            }
        }
        // Two or more is a scale being enumerated; one could be a real item.
        if ($scale >= 2) return null;

        foreach ($items as $it) {
            if (mb_strlen($it) > 60 || mb_strlen($it) < 3) return null;
            // A finite verb or a subordinator means this is a sentence.
            if (preg_match('/\b(is|are|was|were|be|owns|own|will|would|must|need|needs|should|can|could|has|have|had|because|which|who|whose|that|so|but|if|when|while|though)\b/i', $it)) {
                return null;
            }
        }
        return $items;
    }

    /** "cancel the audiobook", "drop the signing tour", "X is dead" */
    private function matchCancellation(string $c): ?array
    {
        // "as well" / "too" / "also" are how a second cancellation is phrased,
        // and they were being captured as part of the name: Certification Pass
        // A left "pottery podcast" live after "Kill the pottery podcast as
        // well", because the reference it tried to resolve was "pottery podcast
        // as well" (CERT-A-D05).
        if (preg_match('/\b(?:cancel|drop|kill|scrap|abandon|bin)\s+(?:the\s+|that\s+|our\s+)?([a-z0-9][\w\s\'-]{2,60}?)(?:\s+(?:completely|entirely|for good|now|as\s+well|too|also))?\s*[.!]?$/i', $c, $m)) {
            return $this->withPronounFlag(['type' => 'cancellation', 'target' => $this->clean($m[1]), 'confidence' => 0.95]);
        }
        if (preg_match('/\b(?:the\s+)?([a-z0-9][\w\s\'-]{2,60}?)\s+is\s+(?:dead|cancelled|canceled|off)\b/i', $c, $m)) {
            // "Torsten is off the rebuild" means the PERSON has left the work,
            // not that a commitment called Torsten is cancelled. Pass A read it
            // as a cancellation targeting a human being. A person is never the
            // subject of a cancellation — the handover matcher owns this shape.
            $target = $this->clean($m[1]);
            if ($this->isPersonName($target)) return null;
            return $this->withPronounFlag(['type' => 'cancellation', 'target' => $target, 'confidence' => 0.9]);
        }
        // "Cancel the post-event survey too." — a bare "cancel that too" carries
        // no target of its own and only means something against the previous
        // turn, so it is flagged for the sync layer to resolve.
        if (preg_match('/^\s*(?:also\s+)?cancel\s+(?:that|this|it)\b/i', $c)) {
            return ['type' => 'cancellation', 'target' => 'that', 'target_is_pronoun' => true, 'confidence' => 0.9];
        }
        return null;
    }

    /** "rename X to Y", "X is now Y", "it's Project Cardamom now" */
    private function matchRename(string $c): ?array
    {
        if (preg_match('/\brename\s+(?:the\s+)?([\w\s\'-]{2,60}?)\s+to\s+([\w\s\'-]{2,60})/i', $c, $m)) {
            return $this->withPronounFlag(['type' => 'rename', 'target' => $this->clean($m[1]), 'new_title' => $this->clean($m[2]), 'confidence' => 0.95]);
        }
        if (preg_match('/\b([\w\s\'-]{2,60}?)\s+is\s+now\s+(?:called\s+)?([\w\s\'-]{2,60})/i', $c, $m)) {
            return $this->withPronounFlag(['type' => 'rename', 'target' => $this->clean($m[1]), 'new_title' => $this->clean($m[2]), 'confidence' => 0.85]);
        }
        // "It's Project Kiln now." — the commonest way a project is renamed out
        // loud, and it was scored 0.6, below the 0.75 confirm threshold, so it
        // landed as a PROPOSED commitment and never applied. Phase 1L renamed
        // the residency twice this way and neither name was recallable.
        //
        // The pronoun is now flagged for CommitmentSync to resolve against the
        // last-touched commitment, exactly as ownership pronouns are, and the
        // confidence reflects that this is an explicit instruction rather than
        // a guess. The new title must LOOK like a name — a capitalised word —
        // so "it's Friday now" cannot rename anything.
        if (preg_match('/\bit(?:\'s| is)\s+([\w\s\'-]{2,60}?)\s+now\b/i', $c, $m)) {
            $newTitle = $this->clean($m[1]);
            // A capital letter alone is not a name: days and months are
            // capitalised too, and "It's Friday now" was being read as a
            // rename. A project name is not a calendar word.
            $isCalendarWord = (bool) preg_match('/^(?:monday|tuesday|wednesday|thursday|friday|saturday|'
                . 'sunday|january|february|march|april|may|june|july|august|september|october|november|'
                . 'december|today|tomorrow|tonight|christmas|easter)$/i', $newTitle);
            if (!$isCalendarWord && preg_match('/\b[A-Z][a-z]/', $newTitle)) {
                return ['type' => 'rename', 'target' => 'that', 'target_is_pronoun' => true,
                        'new_title' => $newTitle, 'confidence' => 0.85];
            }
            return ['type' => 'rename', 'target' => null, 'new_title' => $newTitle, 'confidence' => 0.6];
        }
        // "Rename the winter market residency." on its own names no replacement
        // — the new name arrives in the next clause as "It's Project Kiln now".
        // Emitting the target with no title lets CommitmentSync resolve it and
        // hold it as the antecedent, so the pronoun in the following clause has
        // something to point at instead of falling back to whatever happened to
        // be recorded most recently.
        if (preg_match('/\brename\s+(?:the\s+)?([\w\s\'-]{3,60}?)\s*[.!]?$/i', $c, $m)) {
            return ['type' => 'rename', 'target' => $this->clean($m[1]),
                    'new_title' => null, 'confidence' => 0.9];
        }
        return null;
    }

    /**
     * "Nora owns outreach", "Priya is on photography", "assign X to Y"
     *
     * A clause often carries an owner AND a date — "Nora owns the winter menu
     * rollout, due 15 December". Because only one intent is taken per clause,
     * matching ownership alone silently dropped the deadline, which is the more
     * executive-critical half. Any date in the clause is therefore carried onto
     * the ownership result.
     */
    private function matchOwnership(string $c): ?array
    {
        $hit = $this->matchOwnershipInner($c);
        if (!$hit) return null;
        $d = $this->sniffDeadline($c);
        if ($d) { $hit['deadline'] = $d['deadline']; $hit['deadline_text'] = $d['deadline_text']; }
        return $this->withPronounFlag($hit);
    }

    /** Any date in the clause, however it is introduced. */
    private function sniffDeadline(string $c): ?array
    {
        foreach ([
            '/\b(?:by|due|due\s+on|before|no later than)\s+(\d{1,2}(?:st|nd|rd|th)?\s+\w+|\w+\s+\d{1,2}(?:st|nd|rd|th)?)/i',
            '/\b(?:ship date|deadline|renews?|expires?)\s+(?:on\s+|is\s+)?(\d{1,2}(?:st|nd|rd|th)?\s+\w+|\w+\s+\d{1,2}(?:st|nd|rd|th)?)/i',
        ] as $re) {
            if (preg_match($re, $c, $m)) {
                $txt = $this->clean($m[1]);
                return ['deadline' => $this->parseDate($txt), 'deadline_text' => $txt];
            }
        }
        return null;
    }

    private function matchOwnershipInner(string $c): ?array
    {
        // HANDOVER FORMS ARE CHECKED FIRST, DELIBERATELY.
        //
        // "Callum takes over from Torsten on the studio rebuild" was being
        // caught by the generic "<Name> takes <thing>" pattern below, which
        // captured the target as "over from Torsten on the studio rebuild" and
        // created a commitment under that title while the real studio rebuild
        // stayed owned by Torsten. Certification Pass A then answered "who is
        // on the studio rebuild?" with the wrong person. The specific form has
        // to win over the general one.
        if ($hit = $this->matchHandover($c)) return $hit;

        if (preg_match('/\b(' . self::OWNER_WORD . ')\s+(?:owns|is responsible for|will handle|takes|is taking|leads)\s+(?:the\s+)?([\w\s\'-]{2,60})/i', $c, $m)
            && $this->isPersonName($m[1])) {
            return ['type' => 'ownership', 'owner' => $this->clean($m[1]), 'target' => $this->clean($m[2]), 'confidence' => 0.95];
        }
        if (preg_match('/\b(' . self::OWNER_WORD . ')\s+is\s+(?:on|covering|handling)\s+(?:the\s+)?([\w\s\'-]{2,60})/i', $c, $m)
            && $this->isPersonName($m[1])) {
            return ['type' => 'ownership', 'owner' => $this->clean($m[1]), 'target' => $this->clean($m[2]), 'confidence' => 0.9];
        }
        if (preg_match('/\bassign\s+(?:the\s+)?([\w\s\'-]{2,60}?)\s+to\s+(' . self::OWNER_WORD . ')/i', $c, $m)
            && $this->isPersonName($m[2])) {
            return ['type' => 'ownership', 'owner' => $this->clean($m[2]), 'target' => $this->clean($m[1]), 'confidence' => 0.95];
        }
        return null;
    }

    /**
     * Handover forms, checked before the generic ownership patterns.
     *
     * @see matchOwnershipInner() for why the ordering is load-bearing.
     */
    private function matchHandover(string $c): ?array
    {
        // ── HANDOVER FORMS ────────────────────────────────────────────────
        // Only "X's replacement on Y is Z" was recognised. Phase 1P said "Owen
        // replaces him on supplier sourcing" and ownership stayed with Rafa for
        // the rest of the conversation — two recall probes lost to one missing
        // sentence shape. Executives hand work over in all of these ways.
        //
        // Every form is anchored on "on"/"for" plus the work being handed over.
        // That anchor is what keeps "replace the till roll printer" and "we
        // need to replace the packaging supplier" out: they name a thing, not
        // a person taking over a piece of work.
        //
        // "X's replacement on Y is Z"
        if (preg_match('/\breplacement\s+on\s+([\w\s\'-]{2,60}?)\s+is\s+(' . self::OWNER_WORD . ')/i', $c, $m)
            && $this->isPersonName($m[2])) {
            return ['type' => 'ownership', 'owner' => $this->clean($m[2]), 'target' => $this->clean($m[1]), 'confidence' => 0.9];
        }
        // "Z replaces X on Y" / "Z is replacing X for Y"
        if (preg_match('/\b(' . self::OWNER_WORD . ')\s+(?:replaces|is\s+replacing|takes\s+over\s+from|'
            . 'is\s+taking\s+over\s+from|steps\s+in\s+for)\s+[\w\s\'-]{2,30}?\s+(?:on|for)\s+([\w\s\'-]{2,60})/i', $c, $m)
            && $this->isPersonName($m[1])) {
            return ['type' => 'ownership', 'owner' => $this->clean($m[1]), 'target' => $this->clean($m[2]), 'confidence' => 0.9];
        }
        // "X is being replaced by Z on/for Y"
        if (preg_match('/\b[\w\s\'-]{2,30}?\s+is\s+being\s+replaced\s+by\s+(' . self::OWNER_WORD . ')\s+'
            . '(?:on|for)\s+([\w\s\'-]{2,60})/i', $c, $m)
            && $this->isPersonName($m[1])) {
            return ['type' => 'ownership', 'owner' => $this->clean($m[1]), 'target' => $this->clean($m[2]), 'confidence' => 0.9];
        }
        // "Put Z in X's place on Y"
        if (preg_match('/\bput\s+(' . self::OWNER_WORD . ')\s+in\s+[\w\s\'’-]{2,30}?\s*place\s+'
            . '(?:on|for)\s+([\w\s\'-]{2,60})/i', $c, $m)
            && $this->isPersonName($m[1])) {
            return ['type' => 'ownership', 'owner' => $this->clean($m[1]), 'target' => $this->clean($m[2]), 'confidence' => 0.9];
        }
        // "Y moves from X to Z" / "Y passes from X to Z"
        if (preg_match('/\b(?:the\s+)?([\w\s\'-]{2,60}?)\s+(?:moves|passes|transfers|goes)\s+from\s+'
            . '[\w\s\'-]{2,30}?\s+to\s+(' . self::OWNER_WORD . ')\b/i', $c, $m)
            && $this->isPersonName($m[2])) {
            return ['type' => 'ownership', 'owner' => $this->clean($m[2]), 'target' => $this->clean($m[1]), 'confidence' => 0.85];
        }
        // "Hand Y over to Z" / "Hand Y to Z"
        if (preg_match('/\bhand\s+(?:over\s+)?(?:the\s+)?([\w\s\'-]{2,60}?)\s+(?:over\s+)?to\s+'
            . '(' . self::OWNER_WORD . ')\b/i', $c, $m)
            && $this->isPersonName($m[2])) {
            return ['type' => 'ownership', 'owner' => $this->clean($m[2]), 'target' => $this->clean($m[1]), 'confidence' => 0.85];
        }
        return null;
    }

    /**
     * A capitalised token is only a person if it is capitalised AS WRITTEN and
     * is not a word an imperative sentence would capitalise anyway.
     */
    private function isPersonName(string $word): bool
    {
        $w = trim($word);
        if (!preg_match('/^[A-Z][a-z]{1,19}$/', $w)) return false;
        return !in_array(mb_strtolower($w), self::NOT_A_PERSON, true);
    }

    /** Mark targets that only mean something relative to the previous turn. */
    private function withPronounFlag(array $hit): array
    {
        $t = trim((string) ($hit['target'] ?? ''));
        if ($t !== '' && preg_match(self::PRONOUN, $t)) {
            $hit['target_is_pronoun'] = true;
        }
        return $hit;
    }

    /** "move X to 15 January", "push the print quote out two weeks" */
    private function matchDeadlineChange(string $c): ?array
    {
        if (preg_match('/\b(?:move|shift|change)\s+(?:the\s+)?([\w\s\'-]{2,60}?)\s+(?:target\s+)?(?:from\s+[\w\s]{3,30}\s+)?to\s+(.{3,40}?)\s*[.!]?$/i', $c, $m)) {
            $d = $this->parseDate($m[2]);
            return $this->withPronounFlag(['type' => 'deadline_change', 'target' => $this->clean($m[1]),
                    'deadline' => $d, 'deadline_text' => $this->clean($m[2]), 'confidence' => $d ? 0.9 : 0.7]);
        }
        if (preg_match('/\bpush\s+(?:the\s+)?([\w\s\'-]{2,60}?)\s+(?:deadline\s+)?(?:out\s+|back\s+)?(.{3,40}?)\s*[.!]?$/i', $c, $m)) {
            return $this->withPronounFlag(['type' => 'deadline_change', 'target' => $this->clean($m[1]),
                    'deadline' => null, 'deadline_text' => $this->clean($m[2]), 'confidence' => 0.8]);
        }
        return null;
    }

    /** New obligations: "we need to X", "make sure X", "I promised X", "X by Friday" */
    /**
     * An imperative that asks for a CALCULATION is a question wearing the
     * clothes of an instruction.
     *
     * "Work out 90 days after 1 October for me." was recorded as a durable
     * executive commitment titled "Work out 90 days after 1 October for me",
     * live, on the forensic tenant. The question guard in extract() is purely
     * syntactic — a leading wh-word or a trailing question mark — and this
     * sentence has neither, so it was read as dictated work and written to the
     * permanent record. The owner asked what a date was; the record grew an
     * obligation nobody has.
     *
     * That is memory corruption rather than a cosmetic miscount: the commitment
     * record is the source of truth Sarah is instructed to trust over her own
     * recollection, so anything false in it is believed.
     *
     * THE TEST IS STRUCTURAL, NOT A LIST OF QUESTION WORDS. Take the clause,
     * remove the date expression the platform can already compute, then remove
     * the speech-act framing that requests it. If nothing of substance is left,
     * the whole sentence WAS the request and there is no deliverable in it:
     *
     *   "Work out 90 days after 1 October for me"  ->  ""            request
     *   "Ship the order 3 days before 17 September" ->  "ship order"  commitment
     *
     * The second keeps a verb and an object after the arithmetic is removed, so
     * it survives — which is the property that matters. Requesting a
     * computation and scheduling work relative to a date are different acts and
     * they are separated here by what remains of the sentence, not by which
     * verb opened it.
     */
    private function isComputationRequest(string $c): bool
    {
        // Only clauses that actually contain computable arithmetic qualify.
        if (!preg_match('/\b\d{1,4}\s+(?:day|week|month|year)s?\s+(?:before|after|from|prior\s+to|ahead\s+of)\b/i', $c)) {
            return false;
        }

        // Remove the expression together with the date it counts from.
        $r = preg_replace(
            '/\b\d{1,4}\s+(?:day|week|month|year)s?\s+(?:before|after|from|prior\s+to|ahead\s+of)\b.*$/i',
            ' ', $c);

        // Remove the framing that REQUESTS a value. These are speech-act verbs
        // — a small, closed category of ways to ask for information — not an
        // open class of things that can be done, so naming them does not
        // reintroduce the missing-synonym trap documented above.
        $r = preg_replace(
            '/\b(?:please|thanks|for\s+me|for\s+us|could\s+you|can\s+you|would\s+you|'
            . 'tell\s+me|show\s+me|give\s+me|remind\s+me|let\s+me\s+know|i\s+need|i\s+want|'
            . 'work(?:s)?\s+out|working\s+out|calculate|compute|figure\s+out|work\s+it\s+out|'
            . 'what|whats|when|which|the\s+date|date|is|it|as|to|me|us|out|now|again|hey|ok|okay)\b/i',
            ' ', $r);

        $r = trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9 ]+/i', ' ', $r)));
        if ($r === '') return true;

        foreach (preg_split('/\s+/', $r) as $w) {
            if (mb_strlen($w) >= 3) return false;   // real content survives -> real commitment
        }
        return true;
    }

    private function matchCommitment(string $c): ?array
    {
        if ($this->isComputationRequest($c)) return null;

        $deadline = null; $deadlineText = null;
        if (preg_match('/\bby\s+((?:the\s+)?\d{1,2}(?:st|nd|rd|th)?\s+\w+|\w+\s+\d{1,2}(?:st|nd|rd|th)?|monday|tuesday|wednesday|thursday|friday|saturday|sunday|next\s+\w+|end\s+of\s+\w+)/i', $c, $dm)) {
            $deadlineText = $this->clean($dm[1]);
            $deadline = $this->parseDate($deadlineText);
        }
        // Dates are not always introduced by "by". The F1 cookbook turn read
        // "hard ship date 14 November" and was missed entirely, so the single
        // most important commitment of the conversation never got recorded.
        // Past tense disqualifies a date from being a deadline, and it has to
        // be checked BEFORE the keyword branch, not only before the generic
        // shape branch. Widening the keyword list to include `audit` made
        // "Last month we finished the audit on 3 February" produce a deadline,
        // because the keyword matched and nothing asked whether the sentence
        // was describing something already done.
        $isPast = (bool) preg_match('/\b(?:was|were|did|had|went|met|happened|discussed|spoke|agreed|'
            . 'attended|finished|completed|already|yesterday|last\s+(?:week|month|year|night))\b/i', $c);

        if (!$deadlineText && !$isPast && preg_match('/\b(?:ship date|due|deadline|renews?|expires?|launch(?:es)?|'
            . 'open(?:s|ing)?|start(?:s|ing)?|kick.?off|go.?live|delivery|deliver(?:s)?|clos(?:e|es|ing)|'
            . 'review|meeting|call|session|handover|cut.?off|submission|filing|presentation|audit|'
            . 'inspection|visit|sitting|service|arrives?|lands?|ships?)\s+(?:on\s+|is\s+|at\s+)?'
            . '(\d{1,2}(?:st|nd|rd|th)?\s+\w+|\w+\s+\d{1,2}(?:st|nd|rd|th)?)/i', $c, $dm2)) {
            $deadlineText = $this->clean($dm2[1]);
            $deadline = $this->parseDate($deadlineText);
        }
        // A closed keyword list is a vocabulary trap. Phase 1L said "24
        // sittings, hard OPENING 6 May" where Phase 1H had said "hard LAUNCH
        // 9 March", and because `launch` was on the list and `opening` was not,
        // the single most important commitment of that conversation was never
        // recorded — five recall probes lost to one missing synonym. The list
        // above is widened, but widening a list only postpones the same defect,
        // so the real fix is to recognise the SHAPE of a date and stop
        // depending on the word that introduces it. Past-tense clauses are
        // excluded so "we met on 6 May" stays a memory rather than a deadline.
        if (!$deadlineText && !$isPast && mb_strlen($c) < 140
            && preg_match('/\b((?:the\s+)?\d{1,2}(?:st|nd|rd|th)?\s+'
                          . '(?:jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*'
                          . '|(?:jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\s+'
                          . '\d{1,2}(?:st|nd|rd|th)?)\b/i', $c, $dm4)) {
            $deadlineText = $this->clean($dm4[1]);
            $deadline = $this->parseDate($deadlineText);
        }
        // A scheduled event states its date with a plain copula and no keyword
        // at all — "Investor update is 11 March". Phase 1H asked when the
        // investor update was and Sarah had no record, because nothing here
        // recognised a bare "<thing> is <date>". Requiring a real month name
        // keeps this from firing on "Priya is off events".
        if (!$deadlineText && preg_match(
            '/^(.{3,70}?)\s+(?:is|will be|takes place|happens)\s+(?:on\s+)?'
            . '((?:the\s+)?\d{1,2}(?:st|nd|rd|th)?\s+(?:jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*'
            . '|(?:jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\s+\d{1,2}(?:st|nd|rd|th)?)\s*[.!]?$/i', $c, $dm3)) {
            $deadlineText = $this->clean($dm3[2]);
            $deadline = $this->parseDate($deadlineText);
        }

        $patterns = [
            ['/\b(?:we|i)\s+(?:need|have)\s+to\s+(.{4,120}?)\s*[.!]?$/i', 0.8],
            ['/\bmake sure\s+(?:that\s+)?(.{4,120}?)\s*[.!]?$/i', 0.85],
            ['/\bi\s+promised\s+(.{4,120}?)\s*[.!]?$/i', 0.9],
            ['/\b(?:remember|note|log|track)\s+(?:this|that)?\s*[:—-]?\s*(.{4,120}?)\s*[.!]?$/i', 0.85],
            ['/\b(?:i\s+want|we\s+want)\s+(.{4,120}?)\s*[.!]?$/i', 0.8],
            ['/\bdo not let\s+(.{4,120}?)\s*[.!]?$/i', 0.85],
            ['/\bmust\s+(?:be\s+)?(.{4,120}?)\s*[.!]?$/i', 0.8],
            // "Add the audiobook to the list", "add these: x, y, z". The F1 CEO
            // added twenty commitments this way in a single turn and every one
            // was missed.
            ['/\badd\s+(?:the\s+|these\s+|this\s+)?(.{3,120}?)(?:\s+to\s+(?:the\s+)?list)?\s*[.!:]?$/i', 0.85],

            // THE BARE IMPERATIVE — "Clear the pallet invoice", "Ship 40 boxes".
            //
            // Every pattern above matches a FRAMING ("we need to X", "make sure
            // X", "I promised X"). None matches the plainest way an executive
            // dictates work: the imperative itself. "Clear the pallet invoice
            // for 14,800 pounds" produced three commitments in Certification
            // Pass A — "Clear the pallet invoice for 14", "800 pounds" and
            // "mark it settled" — and it produced them ONLY because a
            // thousands separator was being read as a list separator. With that
            // parsing bug fixed, the sentence extracted to nothing at all: the
            // obligation had never had a matcher of its own, and the bug was
            // the only thing standing in for one.
            //
            // WHY THIS IS NOT ANOTHER VOCABULARY LIST. The file already records
            // what a closed keyword list costs: `launch` was listed, `opening`
            // was not, and five recall probes were lost to the missing synonym.
            // Listing imperative verbs would repeat that exactly, because verbs
            // are an OPEN class — there is always one more.
            //
            // So this keys on structure instead. A clause-initial word followed
            // immediately by a determiner or a numeral is a verb taking an
            // object; that is what an imperative is. The only enumerated list
            // here is of FUNCTION words — determiners, pronouns, auxiliaries,
            // conjunctions. Those are a CLOSED class: English has not gained a
            // new determiner in centuries, so this list cannot rot the way a
            // verb list does. "The invoice is due" opens with a determiner and
            // is excluded; "Nora owns the rebuild" has no determiner in second
            // position and is excluded; "Clear the invoice" matches.
            //
            // Stative verbs are excluded separately. "Love the new logo" is
            // structurally identical to "Clear the invoice" and is praise, not
            // work. Stative/psych verbs are a small closed category, unlike the
            // open class of action verbs, so naming them does not reintroduce
            // the synonym trap.
            //
            // Runs LAST so every framing above still wins its clause, and
            // matchCommitment itself runs after cancellation, rename, ownership
            // and deadline-change, so this cannot capture their intents.
            ['/^(?:please\s+|just\s+|also\s+)?'
             . '(?!(?:the|a|an|this|that|these|those|there|here|it|we|i|you|they|he|she|'
             . 'my|our|your|their|his|her|its|is|are|was|were|be|been|being|do|does|did|'
             . 'can|could|will|would|shall|should|may|might|must|have|has|had|no|not|never|'
             . 'maybe|perhaps|if|when|while|because|although|so|and|but|or|'
             . 'love|loves|like|likes|hate|hates|want|wants|need|needs|prefer|prefers|'
             . 'appreciate|enjoy|enjoys|trust|trusts|believe|believes|know|knows|'
             . 'think|thinks|feel|feels|see|sees|hear|hears|remember|remembers)\b)'
             . '([a-z][a-z\-]{1,}\s+'
             . '(?:the|a|an|this|that|these|those|all|both|our|your|their|its|his|her|my|'
             . 'every|each|\d[\d,]*)\s+'
             . '.{2,110}?)\s*[.!]?$/i', 0.8],
        ];
        foreach ($patterns as [$re, $conf]) {
            if (preg_match($re, $c, $m, PREG_OFFSET_CAPTURE)) {
                $title  = $this->clean($m[1][0]);
                $prefix = trim(mb_substr($c, 0, (int) $m[0][1]));

                // These patterns capture a PREDICATE. When the clause carries a
                // subject before the match, capturing only the predicate throws
                // away the thing the commitment is about:
                //
                //   "The ChefListed migration must finish by 30 September."
                //      -> "finish by 30 September"   <- unidentifiable
                //
                // That cost the Phase 1B gate a recall point: the deadline was
                // stored correctly and Sarah still could not say what it was
                // for. Same failure shape as a pronoun-leading title, so both
                // are handled the same way — keep the whole clause.
                $prefixHasSubject = $prefix !== '' && preg_match('/[a-z]{3}/i', $prefix)
                    && !preg_match('/^(and|but|so|then|also|now|ok|okay|right|actually|please)\b[\s,]*$/i', $prefix);
                $startsWithPronoun = (bool) preg_match('/^(it|that|this|them|those|these)\b/i', $title);

                if ($prefixHasSubject || $startsWithPronoun) {
                    $title = $this->clean($c);
                }
                return ['type' => 'commitment', 'title' => $title,
                        'deadline' => $deadline, 'deadline_text' => $deadlineText,
                        'confidence' => $conf];
            }
        }
        // ── DECLARATIVE CONSTRAINTS ────────────────────────────────────────
        // Certification Pass A planted five facts that produced NO record at
        // all, because none of them is phrased as "add X" or "we need to X":
        //
        //   "The trade portal cannot start until the price list is signed off."
        //   "It is tied to the lease break."
        //   "The Ravensworth investment talks stay confidential."
        //   "The courier needs the manifest 24 days before that date."
        //   "Workshop capacity is 24 places per weekend."
        //
        // Every one was asked about later and every one was lost. These are
        // constraints the owner has stated and will be held to — dependencies,
        // confidentiality, lead times, ceilings — and the executive record is
        // exactly where they belong. The whole clause becomes the title so the
        // constraint stays readable and retrievable by its own words.
        //
        // Deliberately narrow: each pattern names a specific constraint shape
        // rather than accepting any declarative sentence, because turning all
        // prose into commitments would bury the record in noise.
        foreach ([
            // dependency / blocking
            '/\b(?:cannot|can\'?t|must\s+not|should\s+not)\s+(?:start|begin|proceed|go\s+ahead|launch|ship)\b[^.!?]{0,60}\b(?:until|before|unless)\b/i',
            '/\b(?:depends\s+on|is\s+dependent\s+on|is\s+blocked\s+by|is\s+contingent\s+on|is\s+tied\s+to|are\s+tied\s+to)\b/i',
            // confidentiality
            '/\b(?:stays?|remains?|is|are)\s+confidential\b|\bkeep\s+(?:this|that|it|them)\s+confidential\b|\bnothing\s+in\s+shared\s+doc/i',
            // lead time
            '/\b\d{1,3}\s+(?:days?|weeks?|months?)\s+(?:before|ahead|in\s+advance|prior)\b/i',
            // ceiling / limit
            '/\b(?:capacity|ceiling|limit|cap|maximum|minimum)\s+(?:is|of)\s+\d/i',
            '/\bthat\s+is\s+the\s+(?:ceiling|limit|cap|maximum)\b/i',
            // recurring obligation
            '/\b(?:every|each)\s+(?:six\s+months|three\s+months|month|week|quarter|year|fortnight)\b/i',
        ] as $constraint) {
            if (!preg_match($constraint, $c)) continue;
            if (preg_match('/^\s*(?:what|when|who|which|why|how|is|are|do|does|did|can|could|should)\b/i', $c)) break;
            return ['type' => 'commitment', 'title' => $this->clean($c),
                    'deadline' => $deadline, 'deadline_text' => $deadlineText,
                    'confidence' => 0.8];
        }

        // A bare deadline attached to a noun phrase is still a commitment.
        if ($deadlineText && mb_strlen($c) < 160 && !preg_match('/^\s*(what|when|who|is|are)\b/i', $c)) {
            return ['type' => 'commitment', 'title' => $this->clean(preg_replace('/\bby\s+.*$/i', '', $c)),
                    'deadline' => $deadline, 'deadline_text' => $deadlineText, 'confidence' => 0.75];
        }
        return null;
    }

    /** Best-effort absolute date. Returns null rather than guessing wrong. */
    private function parseDate(string $s): ?string
    {
        $s = trim($s);
        if (preg_match('/\b(\d{1,2})\s*(?:st|nd|rd|th)?\s+(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\b/i', $s, $m)
         || preg_match('/\b(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\s+(\d{1,2})\b/i', $s, $m2)) {
            $day   = isset($m[1]) ? (int) $m[1] : (int) $m2[2];
            $month = isset($m[2]) ? $m[2] : $m2[1];
            $year  = (int) date('Y');
            $ts = strtotime("$day $month $year");
            if ($ts === false) return null;
            // A date already past this year almost always means next year.
            if ($ts < strtotime('-1 month')) $ts = strtotime("$day $month " . ($year + 1));
            return $ts ? date('Y-m-d', $ts) : null;
        }
        return null;
    }

    private function clean(string $s): string
    {
        $s = trim(preg_replace('/\s+/', ' ', $s));
        $s = trim($s, " \t\n\r\0\x0B.,;:!—-");
        return mb_substr($s, 0, 200);
    }

    /**
     * A record whose identity is a bare pronoun is worse than no record: it
     * occupies a slot in the executive prompt, matches nothing on lookup, and
     * reads to the owner as if Sarah has misunderstood them. The Phase 1H store
     * held a live commitment titled "that" and another with an empty title.
     * Pronoun targets are kept here only when flagged for later resolution.
     */
    private function dropEmpty(array $items): array
    {
        $out = [];
        foreach ($items as $i) {
            // A bare rename — "it's Project Ember now" — carries NO title and a
            // deliberately null target, because the thing being renamed is
            // whatever was last discussed. Its identity is the new name. Keying
            // identity off title/target alone silently discarded every rename of
            // that shape, which is the most common way a project gets renamed
            // out loud.
            $identity = trim((string) ($i['title'] ?? $i['target'] ?? $i['new_title'] ?? ''));
            if ($identity === '' || mb_strlen($identity) < 3) continue;
            if (preg_match(self::PRONOUN, $identity) && empty($i['target_is_pronoun'])) continue;
            $out[] = $i;
        }
        return $out;
    }

    /** One turn restating the same thing must not create two records. */
    private function dedupe(array $items): array
    {
        $seen = []; $out = [];
        foreach ($items as $i) {
            $key = $i['type'] . '|' . strtolower($i['target'] ?? $i['title'] ?? '');
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $out[] = $i;
        }
        return $out;
    }
}
