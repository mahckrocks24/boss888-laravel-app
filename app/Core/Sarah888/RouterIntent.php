<?php

namespace App\Core\Sarah888;

use Illuminate\Support\Facades\Log;

/**
 * SARAH888 — does this turn want a FACT, or does it want THINKING?
 *
 * WHAT WAS MEASURED, 2026-08-09.
 * Five of twenty-one executive scenarios never reached reasoning at all. A
 * hard-coded branch in the chat route matched the word "failing" and answered
 * with a list of failed tasks, so:
 *
 *   "A publish keeps failing. Walk me through which systems are involved
 *    and where it could be breaking."
 *
 * was answered with "Yes - 5 tasks failed in the last 7 days: ... Want me to
 * retry any of these?" — 317 characters, no systems named, no reasoning. All
 * five router-answered scenarios scored 0%.
 *
 * WHY THE BRANCH EXISTS, AND WHY IT MUST NOT SIMPLY BE DELETED.
 * It was added 2026-07-15 because the model confabulated about failures —
 * live-battery Case 7 described a COMPLETED fix_orphans as "the failure". The
 * deterministic answer is TRUE, and that truthfulness is not negotiable.
 *
 * The mistake was in what it did with the truth: it returned the facts INSTEAD
 * of reasoning, when it should have handed them TO reasoning. "What failed?"
 * wants a list. "Why did it fail?" wants a diagnosis grounded in that same
 * list. Both deserve the real numbers; only one is answered by them.
 *
 * WHY THIS IS NOT A KEYWORD TEST.
 * Keywords are what broke it. "failing" appears in both questions above, so no
 * vocabulary list can separate them — the difference is what the asker wants
 * DONE with the facts, which is a semantic property of the request. So the
 * judgement is semantic, and it is made only where a deterministic branch has
 * already matched, which bounds the cost to the handful of turns that would
 * otherwise have been intercepted.
 *
 * FAILURE POSTURE.
 * If the classifier cannot be reached or answers unusably, this returns
 * ANALYSIS — reasoning WITH the deterministic facts attached. That is the safe
 * direction in both senses: the reply is still grounded in real records, and
 * the owner still gets thought rather than a list. Cognition is never traded
 * for safety; it is grounded instead.
 */
class RouterIntent
{
    /** The answer IS the record: a list, a count, a state. */
    public const STATUS = 'status';

    /** The record is INPUT to an answer: cause, structure, judgement, plan. */
    public const ANALYSIS = 'analysis';

    /**
     * Memo, deliberately STATIC.
     *
     * The verdict is consulted twice in a turn — once to decide whether the
     * deterministic router answers, and once to decide the SHAPE the reply
     * should take. Those are the same question about the same message, so it is
     * asked once.
     *
     * It has to be static because this class is unbound in the container, so
     * app() hands back a NEW instance on every resolve and an instance property
     * would memoise nothing — the same trap CorrelationContext documents and
     * that cost slice 1E.2 a debugging cycle.
     *
     * Static means it also survives between requests in the same FPM worker.
     * That is safe and useful here: the verdict is a pure function of the
     * message text, so a repeat question gets the same answer for free. Capped
     * so a long-lived worker cannot accumulate unbounded entries.
     */
    private static array $memo = [];

    /**
     * @return array{mode:string, why:string, source:string}
     *         source: model | fallback_unreachable | fallback_unparseable | memo
     */
    public function classify(string $message, int $wsId = 0): array
    {
        $m = trim($message);
        if ($m === '') return ['mode' => self::STATUS, 'why' => 'empty turn', 'source' => 'model'];

        $key = md5($m);
        if (isset(self::$memo[$key])) return ['source' => 'memo'] + self::$memo[$key];

        // SF-04 (REPORT-0024, 2026-09-01): "Hi Sarah." was a model call. A greeting or a bare acknowledgement
        // asks for nothing that has to be derived, so it is STATUS by construction.
        if (self::isTrivialTurn($m)) { self::$domainMemo[$key] = []; return ['mode' => self::STATUS, 'why' => 'greeting or acknowledgement', 'source' => 'deterministic']; }

        // DEC-0029 A8 (2026-09-02): the classifier call measured 1.5-13s per turn (DeepSeek reasoning on a 800-token
        // prompt; one 707-token call timed out at 12s). Shapes that cannot be read two ways are decided here, with the
        // domains from keywords; anything ambiguous still goes to the model.
        $__det = self::deterministicMode($m);
        if ($__det !== null) {
            $__verdict = ['mode' => $__det, 'why' => 'unambiguous shape', 'source' => 'deterministic'];
            if (count(self::$memo) > 500) self::$memo = [];
            self::$memo[$key] = $__verdict;
            if (count(self::$domainMemo) > 500) self::$domainMemo = [];
            self::$domainMemo[$key] = MinimumPath::keywordDomains($m);
            return $__verdict;
        }

        $system = <<<'SYS'
You decide what a business owner wants from one message to their marketing director.

Answer STATUS when the answer IS the record — the owner wants to be told what is
there. Retrieving rows and reporting them answers the question completely.
  "What failed?"  "How many articles are published?"  "Any errors today?"
  "Show me the open tasks."  "What is pending my approval?"

Answer ANALYSIS when the record is only the INPUT to the answer — the owner wants
cause, consequence, structure, judgement, sequence, narrative, or a
recommendation. Listing the rows would not answer them.
  "Why did it fail?"            "What systems were involved?"
  "Walk me through the incident." "Give me the dependency chain."
  "What should we improve?"      "What do we do about it?"
  "Plan how we recover."         "Is the spend working?"

The deciding test is whether the answer can be READ or must be DERIVED.
STATUS means the answer already exists in a record and only has to be looked up
and reported. If producing the answer requires tracing, attributing, inferring,
or assembling several records into something none of them contains, it is
ANALYSIS — even when the question is short and sounds like a lookup.

"What systems were involved?" is ANALYSIS: nothing stores a list of the systems
involved in an event, so it has to be worked out.
"List the failed tasks" is STATUS: that list is a record.

A JUDGEMENT is not a lookup, however much it sounds like a report. No record
stores what is "healthy", "at risk", "worth worrying about", "material" or "most
important" — somebody has to decide, and deciding is analysis. So these are all
ANALYSIS:
  "Give me a portfolio health briefing: what is healthy, what is at risk?"
  "Report on the state of content production."
  "Summarise the workspace for someone back from two weeks away."
  "What do I need to know before a call with the CEO?"
  "Write the marketing section of the board update."
and these remain STATUS:
  "How many articles are published?"   "Show me the open tasks."
  "What is pending my approval?"       "List the failed tasks."
The first group asks what the numbers MEAN. The second asks what they ARE.

The same subject can be either. "What failed?" is STATUS. "Why does it keep
failing?" is ANALYSIS. Decide by what the owner wants DONE with the facts, not by
the words used or the topic.

If the message asks for both, answer ANALYSIS — the facts can be included inside
an analysis, but an analysis cannot be recovered from a list.

Also name which parts of the workspace the answer needs, from exactly these and only those
genuinely required: crm (leads, contacts, pipeline), seo (keywords, rankings, audits, links),
content (articles, drafts, publishing, images, meta), tasks (the work queue), incident (something
broken or failing), commercial (budget, credits, spend, pricing).

Reply with JSON only: {"mode":"status|analysis","why":"<six words or fewer>","domains":["..."]}
SYS;

        try {
            $res = app(\App\Connectors\RuntimeClient::class)
                    ->chatJson($system, "MESSAGE:\n{$m}", ['workspace_id' => $wsId], 120);
        } catch (\Throwable $e) {
            Log::warning('[Sarah888] RouterIntent unreachable, defaulting to grounded reasoning', [
                'ws' => $wsId, 'error' => $e->getMessage(),
            ]);
            return ['mode' => self::ANALYSIS, 'why' => 'classifier unreachable', 'source' => 'fallback_unreachable'];
        }

        if (!($res['success'] ?? false)) {
            return ['mode' => self::ANALYSIS, 'why' => 'classifier failed', 'source' => 'fallback_unreachable'];
        }

        $parsed = $res['parsed'] ?? null;
        if (!is_array($parsed)) {
            $txt = (string) ($res['text'] ?? '');
            if (preg_match('/\{.*\}/s', $txt, $mm)) $parsed = json_decode($mm[0], true);
        }
        $mode = is_array($parsed) ? strtolower(trim((string) ($parsed['mode'] ?? ''))) : '';

        if ($mode !== self::STATUS && $mode !== self::ANALYSIS) {
            return ['mode' => self::ANALYSIS, 'why' => 'unparseable verdict', 'source' => 'fallback_unparseable'];
        }

        $verdict = ['mode' => $mode,
                    'why' => mb_substr((string) ($parsed['why'] ?? ''), 0, 60),
                    'source' => 'model'];
        if (count(self::$memo) > 500) self::$memo = [];   // bounded, not clever
        self::$memo[$key] = $verdict;
        // DEC-0029 (2026-09-02): the same judgement also answers "what does this turn need?" — one call, two memos.
        // domains() finds this memo and makes no second model call.
        $__gotDomains = is_array($parsed['domains'] ?? null) ? $parsed['domains'] : null;
        if ($__gotDomains !== null) {
            $__clean = array_values(array_intersect(array_map(fn($d) => strtolower(trim((string) $d)), $__gotDomains), self::DOMAINS));
            if (count(self::$domainMemo) > 500) self::$domainMemo = [];
            self::$domainMemo[$key] = $__clean ?: self::DOMAINS;
        }
        return $verdict;
    }

    /** Convenience for callers that only need the branch. */
    /**
     * STATUS when the sentence opens as a lookup and asks for no judgement; ANALYSIS when it opens as a request for
     * cause, judgement or a plan. Null when the shape could be read either way ("Which website has the most
     * articles?" stays with the model — it was a lookup last time and a comparison the time before).
     */
    public static function deterministicMode(string $m): ?string
    {
        $m = trim($m);
        $judgement = '/\b(why|should|recommend|best|worst|improve|plan|strategy|explain|walk me|assess|priorit|compare|better|risk|worth|matter|mean)\b/iu';
        if (preg_match('/^(how many|how much|what is|what\'s|whats|what are|list|show( me)?|any |are there|is there|do we have|have we|did we|when (is|was|did)|who (is|are))\b/iu', $m)
            && !preg_match($judgement, $m)) {
            return self::STATUS;
        }
        if (preg_match('/^(why|how (do|can|could|should) (we|i)|what should|should (we|i)|explain|walk me through|assess|prioriti[sz]e|plan (for|how|out)|give me (a|the) (plan|strategy|breakdown|assessment))\b/iu', $m)) {
            return self::ANALYSIS;
        }
        return null;
    }

    /** A greeting/acknowledgement of at most four words with no question in it. */
    public static function isTrivialTurn(string $m): bool
    {
        $m = trim($m);
        if ($m === '' || str_contains($m, '?')) return false;
        if (str_word_count(preg_replace('/[^\p{L}\p{N}\s\']/u', ' ', $m)) > 4) return false;
        return (bool) preg_match('/^(hi|hiya|hello|hey|yo|good (morning|afternoon|evening|night)|morning|evening|thanks|thank you|cheers|ta|ok|okay|great|cool|nice|perfect|got it|understood|noted)\b/iu', $m);
    }

    public function isAnalysis(string $message, int $wsId = 0): bool
    {
        return $this->classify($message, $wsId)['mode'] === self::ANALYSIS;
    }

    /** Domains recognised for context selection. Kept to what the platform has. */
    public const DOMAINS = ['crm', 'seo', 'content', 'tasks', 'incident', 'commercial'];

    private static array $domainMemo = [];

    /** DEC-0029 (2026-09-02): a WORK turn never runs the mode classifier, so the route seeds keyword-derived domains
     *  here and ContextSelector's domains() call becomes a memo hit instead of a separate model call. */
    public static function seedDomains(string $message, array $domains): void
    {
        $clean = array_values(array_intersect(array_map(fn($d) => strtolower(trim((string) $d)), $domains), self::DOMAINS));
        if (count(self::$domainMemo) > 500) self::$domainMemo = [];
        self::$domainMemo[md5(trim($message))] = $clean ?: self::DOMAINS;
    }

    /**
     * Which parts of the workspace does answering this turn actually require?
     *
     * Used by ContextSelector to decide inclusion, never content. It lives here
     * rather than in a new classifier because the routing question ("what does
     * this turn want?") and the retrieval question ("what does it need?") are
     * the same judgement about the same message, and two classifiers would
     * eventually disagree with each other.
     *
     * FAILURE POSTURE: on any failure it returns EVERY domain, which reproduces
     * today's behaviour exactly. A selector that cannot classify must not start
     * withholding evidence - the cost of over-including is measured and bounded
     * (-42 points), the cost of wrongly withholding a fact is an answer built on
     * a gap the owner cannot see.
     *
     * @return array<int,string>
     */
    public function domains(string $message, int $wsId = 0): array
    {
        $m = trim($message);
        if ($m === '') return self::DOMAINS;

        $key = md5($m);
        if (isset(self::$domainMemo[$key])) return self::$domainMemo[$key];

        $system = <<<'SYS'
Decide which parts of a marketing workspace are needed to answer one message.

Choose from exactly these, and only those that are genuinely required:
  crm        - leads, contacts, pipeline, lead ids
  seo        - keywords, rankings, search visibility, audits, internal links
  content    - articles, drafts, publishing, images, meta
  tasks      - the work queue: what is running, pending, failed, blocked
  incident   - something is broken, degraded, failing, or down
  commercial - budget, credits, spend, revenue, pricing

Include a domain only if the answer would be WRONG or incomplete without it.
Asking how publishing works needs content and tasks; it does not need crm.
Asking who to chase for a lead needs crm; it does not need seo.
A broad "how are we doing" needs tasks and content.

Reply with JSON only: {"domains":["..."]}
SYS;

        try {
            $res = app(\App\Connectors\RuntimeClient::class)
                    ->chatJson($system, "MESSAGE:\n{$m}", ['workspace_id' => $wsId], 120);
        } catch (\Throwable $e) {
            Log::warning('[Sarah888] domain classify unreachable; including everything', ['ws' => $wsId]);
            return self::DOMAINS;
        }
        if (!($res['success'] ?? false)) return self::DOMAINS;

        $parsed = $res['parsed'] ?? null;
        if (!is_array($parsed)) {
            $txt = (string) ($res['text'] ?? '');
            if (preg_match('/\{.*\}/s', $txt, $mm)) $parsed = json_decode($mm[0], true);
        }
        $got = is_array($parsed['domains'] ?? null) ? $parsed['domains'] : null;
        if (!$got) return self::DOMAINS;

        $clean = array_values(array_intersect(
            array_map(fn($d) => strtolower(trim((string) $d)), $got), self::DOMAINS));
        if (!$clean) return self::DOMAINS;

        if (count(self::$domainMemo) > 500) self::$domainMemo = [];
        return self::$domainMemo[$key] = $clean;
    }
}

// SARAH-REMEDIATION-APPLIED-2026-09-01 (REPORT-0024)
