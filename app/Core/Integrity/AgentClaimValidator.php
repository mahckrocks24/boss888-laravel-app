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
        // RISK-0186 (2026-09-17): a SUBJECT-LESS claim slipped past every pattern above — "Queued both — the sourdough
        // piece … and the morning vinyasa article …" while every task creation had been refused (EV-1056). A sentence
        // that opens with the verb, or says both/all/everything are queued, is a queue claim like any other.
        '/^\W*(?:answering[^:]{0,120}:\s*)?(?:both\s+|all\s+|everything\s+|(?:the\s+)?(?:articles?|pieces?|drafts?|tasks?|jobs?|work)\s+)?(queued|scheduled|kicked off|launched|started|running|underway|in the queue|in progress|set up|lined up)\b/i',
        '/\b(both|all (?:two|three|four|of them|of these|of it)|everything|each one|the (?:two|three|four))\s+(?:is |are |now |already |as drafts )*(queued|scheduled|in the queue|running|underway|in progress|in flight|going out|set up|lined up)\b/i',
        '/\b(going out|goes out|is going|are going)\s+(?:as|to)\s+(?:a\s+)?(full job|draft|drafts|the queue|the team|priya|james)\b/i',
        // Named-specialist attribution: "queued/assigned/handed/asked/tasked <Specialist>" — a claim that a
        // named specialist is on the job. Verified per-specialist against who was actually engaged this turn.
        '/\b(queued|assigned|handed|asked|told|briefed|tasked|delegated|looped in|brought in|pulled in)\b[^.!?\n]{0,20}\b(james|priya|elena|marcus|max|nora|alex|diana|ryan|sofia|leo|maya|chris|zara|tyler|zoe|jordan|kai|vera)\b/i',
        // delegation-ENGAGEMENT: Sarah says she engaged a helper — "I've asked/told/had/got X to get
        // started/handle/work on it", "handed this off to James". A claim a specialist is on the job.
        '/\bI(\'?ve|\s+have|\s+already)?\s+(asked|told|had|got|looped in|brought in|pulled in|handed (this|that|it)?\s*(to|off)|assigned (this|that|it)?\s*to)\b[^.!?\n]*\b(get started|start(ed|ing)?|begin|handle|handling|take (it|this|that|care|over)|work(ing)? on|look (at|into)|jump on|pick (it|this|that) up|run with|sort|fix(ing)?|writ(e|ing)|draft(ing)?|creat(e|ing)|generat(e|ing)|build(ing)?|insert(ing)?|add(ing)?)\b/i',
        // third-person specialist-progress: "James is inserting the links now", "Priya will handle the
        // metas", "the team is on it" — a named specialist/team claimed to be working, whoever the subject.
        '/\b(james|priya|elena|marcus|alex|the team|my team|the (specialist|specialists|content team|seo team|design team|social team))\b[^.!?\n]*\b(is|are|will|\'ll|\'s|has|have|\'ve|getting|now|currently|already)\b[^.!?\n]*\b(get started|getting started|start(ed|ing)?|handle|handling|work(ing)? on|take|taking|on it|draft(ing)?|writ(e|ing)|generat(e|ing)|insert(ing)?|build(ing)?|fix(ing)?|begin)\b/i',
    ];

    /**
     * @param  string  $reply     the generated reply
     * @param  int     $wsId      workspace
     * @param  string  $slug      agent slug
     * @param  bool    $didQueue  did THIS turn actually create tasks?
     * @return array{reply:string, stripped:array}
     */
    /** Specialist first-names that may be attributed work in a delegation claim (excludes Sarah, the DMM). */
    private const SPECIALIST_NAMES = ['james','priya','elena','marcus','max','nora','alex','diana','ryan','sofia','leo','maya','chris','zara','tyler','zoe','jordan','kai','vera'];

    /** Lowercased specialist names a sentence attributes work to. */
    private function namedSpecialists(string $sentence): array
    {
        $found = [];
        foreach (self::SPECIALIST_NAMES as $n) {
            if (preg_match('/\\b' . $n . '\\b/i', $sentence)) { $found[] = $n; }
        }
        return array_values(array_unique($found));
    }

    /**
     * RISK-0186 (2026-09-17): a sentence that CORRECTS an earlier claim ("I told you I'd queued … the queue shows
     * neither", "no task was actually created", "that wasn't queued") is the truth arriving — it is never a claim
     * and is never stripped, whatever verbs it contains (EV-1056: the guard removed the correction and kept the claim).
     */
    private const CORRECTION_PATTERNS = [
        '/\b(correct myself|correction|I was wrong|that was wrong|I misspoke|I misstated|to be accurate|to correct|I have to correct|I need to correct|I must correct|I said .{0,80}\bbut\b|I told you .{0,120}\b(but|and)\b.{0,80}\b(not|neither|never|no\b|nothing|isn.?t|wasn.?t|aren.?t|weren.?t|hasn.?t|haven.?t|didn.?t|shows|show)\b)/i',
        '/\b(no task|no tasks|no work|no job|nothing|none of (?:it|them|that|those))\b[^.!?\n]{0,40}\b(was|were|has been|had been|have been|is|are|got)\s+(actually\s+|really\s+|ever\s+)?(queued|created|started|scheduled|kicked off|running|in flight|in progress|underway)\b/i',
        '/\b(queue|ledger|task list|tasks table|record)\s+shows?\s+(neither|nothing|no\b|none|zero|0)\b/i',
        '/\b(exist|exists|existed)\s+(only\s+)?as\s+(a\s+)?(commitments?|promises?|intentions?)\b/i',
        '/\b(it|that|this|they|these|those|neither|the (?:tasks?|articles?|pieces?|work|jobs?|drafts?))\s+(?:was|were|is|are|has|have|had)?\s*(?:not|never|n.t)\s+(?:actually\s+|really\s+|ever\s+)?(queued|created|started|scheduled|kicked off|running|in flight)\b/i',
    ];

    /**
     * RISK-0186 (2026-09-17): a figure of credits the model attaches to THIS turn's work ("3 credits apiece, so 6 off
     * your 24", "this will use 6 credits") is a claim about the ledger. It is kept only when work was queued this
     * turn AND the number is the ledger's own sum (the route appends the ledger line itself); otherwise it is stripped.
     */
    private const COST_CLAIM_PATTERNS = [
        '/\b\d+\s*(credits?|cr)\b[^.!?\n]{0,80}\b(apiece|each|per (?:article|piece|post|task|job)|off your|off the|in total|all told|to run|to start|to queue|for (?:both|all|these|those|this|the (?:two|three|four)))\b/i',
        '/\b(uses?|using|costs?|costing|charge[sd]?|charging|reserv(?:e|ed|ing)|debit(?:ed|ing)?|comes? to|call it|so that.?s|that.?s|total(?:s|ling)?)\b[^.!?\n]{0,40}\b\d+\s*(credits?|cr)\b/i',
        '/\b\d+\s*(credits?|cr)\b[^.!?\n]{0,40}\b(off your \d+|from your \d+|of your \d+)\b/i',
        // "These two come to about 6." — a spend figure with the unit left implicit is still a spend figure
        '/\b(comes?|came|adds? up|works? out|amounts?)\s+to\s+(?:about\s+|roughly\s+|around\s+)?\d+\b/i',
        '/\b(about|roughly|around|call it)\s+\d+\s+(?:credits?|cr|each|apiece|in total|all told)\b/i',
    ];

    public static function isCorrection(string $sentence): bool
    {
        foreach (self::CORRECTION_PATTERNS as $rx) { if (preg_match($rx, $sentence)) return true; }
        return false;
    }

    public static function isCostClaim(string $sentence): bool
    {
        foreach (self::COST_CLAIM_PATTERNS as $rx) { if (preg_match($rx, $sentence)) return true; }
        return false;
    }

    /**
     * @param  ?int  $queuedCost  ledger sum (tasks.credit_cost) of the tasks THIS turn created; null = unknown
     */
    public function validate(string $reply, int $wsId, string $slug, bool $didQueue = false, array $engagedAgents = [], array $recentActions = [], array $recentSpecialists = [], ?int $queuedCost = null): array
    {
        if (trim($reply) === '') {
            return ['reply' => $reply, 'stripped' => []];
        }

        $slug     = strtolower(trim($slug));
        $isDmm    = $this->isDelegator($slug);
        $stripped = []; $strippedClaims = 0;   // RISK-0186: the "not queued" footer belongs to a stripped QUEUE claim, never to a stripped cost figure
        $engaged  = array_values(array_filter(array_map(fn ($a) => strtolower(trim((string) $a)), $engagedAgents)));
        $recentSpec = array_values(array_filter(array_map(fn ($a) => strtolower(trim((string) $a)), $recentSpecialists)));
        // EV-1045 (2026-09-16): a WEBSITE called "Sofia Alvarez" is not the specialist Sofia. "Queued — Priya's writing
        // two pieces for Sofia Alvarez" was stripped as an unbacked claim about Sofia (twice on the QA workspace,
        // once on the Owner's). Website names are blanked before specialist names are read from a sentence.
        $siteNames = [];
        try {
            foreach (\Illuminate\Support\Facades\DB::table('websites')->where('workspace_id', $wsId)->whereNull('deleted_at')->pluck('name') as $__n) {
                $__n = trim((string) $__n); if (mb_strlen($__n) >= 3) $siteNames[] = $__n;
            }
        } catch (\Throwable) { $siteNames = []; }

        // Split into sentences; decide keep vs strip per claim.
        $sentences = preg_split('/(?<=[.!?])\s+|\n+/', $reply, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $kept = [];
        foreach ($sentences as $sentence) {
            // RISK-0186: a correction is the truth, not a claim — keep it whole.
            if ($isDmm && self::isCorrection($sentence)) { $kept[] = trim($sentence); continue; }
            // RISK-0186: a credit figure for this turn's work stands only on the ledger.
            if ($isDmm && self::isCostClaim($sentence)) {
                $nums = array_map('intval', preg_match_all('/\b(\d+)\b/', $sentence, $mm) ? $mm[1] : []);   // every figure in the sentence; the ledger sum must be one of them
                $backed = $didQueue && $queuedCost !== null && in_array((int) $queuedCost, $nums, true);
                if ($backed) { $kept[] = trim($sentence); } else { $stripped[] = trim($sentence); }
                continue;
            }
            $isClaim = false;
            foreach (self::CLAIM_PATTERNS as $rx) {
                if (preg_match($rx, $sentence)) { $isClaim = true; break; }
            }
            if (! $isClaim) { $kept[] = trim($sentence); continue; }

            // A delegation/completion claim. Sarah may report it ONLY with real backing this turn; a specialist
            // may never claim delegation at all. P2 precision: a claim that NAMES a specialist is kept only if
            // that specialist was actually engaged this turn; a generic queue claim (no name) is backed by
            // $didQueue; and with no engaged-agent evidence we do not over-strip.
            // F-U8-REVIEW (2026-09-02): a review turn reports work done EARLIER in the
            // conversation, so $didQueue (this turn) is false and a TRUE accomplishment was
            // being stripped. Back a claim with genuine THIS-CONVERSATION evidence too:
            //  - a named specialist is kept only if actually engaged (this turn OR this conversation);
            //  - a generic queue/completion claim is kept if this turn queued OR it references a real
            //    this-conversation task by topic (recentActions are conversation-scoped at the call site,
            //    so a fabricated claim with no matching real task is still stripped — RISK-0123 preserved).
            $keep = false;
            if ($isDmm) {
                $named   = $this->namedSpecialists($siteNames ? str_ireplace($siteNames, ' ', $sentence) : $sentence);
                $backers = array_values(array_unique(array_merge($engaged, $recentSpec)));
                if ($named !== []) {
                    if ($backers !== []) {
                        $keep = (array_diff($named, $backers) === []);
                    } else {
                        $keep = $didQueue; // no engagement evidence at all: old no-over-strip only when queued
                    }
                } else {
                    $keep = $didQueue || $this->matchesRecentAction($sentence, $recentActions);
                }
            }
            if ($keep) { $kept[] = trim($sentence); } else { $stripped[] = trim($sentence); $strippedClaims++; }
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

        // RISK-0186 (2026-09-17): say "not queued" only when a queue claim was removed AND nothing was queued this turn — EV-1056 saw this
        // footer land on a turn that had stripped a correction, and today on a turn that had only lost a cost figure while 10 tasks ran.
        // When work WAS queued this turn but a claim about it could not be backed (an unengaged specialist named), the
        // truthful line is that the unbacked part is not running — never that nothing was queued.
        $honestQueued = "That part isn't in hand — only the work I actually started this turn is running.";
        // Owner, 2026-09-18 (ws 2, "How's everything going?"): the model added "The two articles are queued for publishing…",
        // the strip removed it, and the footer "I haven't queued that yet" pointed at a sentence the customer never saw.
        // A removed false claim needs no replacement while the reply still says something; the footer stands in only
        // when the claim WAS the reply.
        if ($strippedClaims > 0 && $out === '') {
            $out = $isDmm ? ($didQueue ? $honestQueued : $honest) : $honest;
        }

        Log::warning('[AgentClaim] stripped unverified completion claim', [
            'workspace_id' => $wsId,
            'agent'        => $slug,
            'is_delegator' => $isDmm,
            'did_queue'    => $didQueue,
            'stripped'     => array_slice($stripped, 0, 3),
        ]);

        return ['reply' => $out, 'stripped' => $stripped];
    }

    /** True if the sentence references a real THIS-CONVERSATION task by a distinctive topic word. */
    private function matchesRecentAction(string $sentence, array $recentActions): bool
    {
        if ($recentActions === []) return false;
        $s = mb_strtolower($sentence);
        foreach ($recentActions as $title) {
            foreach ($this->distinctiveTokens((string) $title) as $tok) {
                if (mb_strpos($s, $tok) !== false) return true;
            }
        }
        return false;
    }

    /** Distinctive content words (>=5 chars, minus generic filler) from a real task title. */
    private function distinctiveTokens(string $t): array
    {
        static $stop = ['about','with','from','your','their','this','that','into','over','pages','page','tasks','currently','missing','address','generate','insert','create','update','them','none','have','been','which','start','using','article','draft','content'];
        $out = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($t)) as $w) {
            if (mb_strlen($w) >= 5 && !in_array($w, $stop, true)) $out[] = $w;
        }
        return array_values(array_unique($out));
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
