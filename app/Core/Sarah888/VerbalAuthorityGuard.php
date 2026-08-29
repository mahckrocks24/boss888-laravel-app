<?php

namespace App\Core\Sarah888;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SARAH888 — VERBAL AUTHORITY CONSISTENCY.
 *
 * THE GAP THIS CLOSES.
 * ConfirmationClaimGuard already catches Sarah PROMISING confirmation with no
 * pending authorization. It early-returns unless the reply solicits confirmation,
 * so the inverse never reached it:
 *
 *   "I'll publish the 24 drafts now without further confirmation."
 *
 * That sentence solicits nothing, so no guard looked at it. Governance still
 * held — nothing published, no approval created — but Sarah claimed an authority
 * she does not have, and what she says must match what the system will do.
 *
 * WHY THIS IS NOT A PROMPT RULE.
 * Two prompt attempts failed. The model is generating prose before it can know
 * the authorization outcome, so the claim has to be checked against the record
 * afterwards, deterministically, in the guard chain that already runs after
 * governance state is settled.
 *
 * THE INVARIANT.
 * Sarah may not state that she will, did, can, or is about to perform a mutating
 * action unless the authoritative record supports THAT action. Experience888 is
 * advisory and can never satisfy this: a learned preference like "the owner
 * usually approves publishing" is not an approval record, and this guard reads
 * only tasks, proposals and approvals.
 *
 * ── 2026-08-13, owner-beta ws 2. TWO measured defects, both closed here. ──
 *
 * (1) THE CLAIM WAS NEVER DETECTED. T036 said "I'll proceed with the publication
 *     now." and nothing ran. The guard returned claim=none: mutatingVerbs()
 *     matched each verb as a PREFIX ("publish" plus trailing word characters),
 *     and "publication" does not start with "publish" — it is public-, not
 *     publish-. The same stem bug hid "sent" behind the "send" prefix and
 *     "deletion" behind "delete". T008's "I'll proceed with this task now."
 *     had no listed verb at all, because the model commits to an action by
 *     naming it as a NOUN ("the publication") or by pure anaphora ("proceed with
 *     this task") at least as often as it uses the verb. Detection is now driven
 *     by explicit surface-form families plus an anaphoric-commitment path, not
 *     by verb-stem prefixes.
 *
 * (2) THE STATE WAS WORKSPACE-WIDE. authorityState() counted ANY task in the
 *     workspace, so a completed `deep_audit` licensed "I've published the 37
 *     drafts", and a pending `write_article` licensed "I'll publish them now"
 *     — both measured. ws 2 carries 1,934 completed and 133 pending tasks, of
 *     which exactly 2 are publish work, so almost anything Sarah claimed read as
 *     backed. State is now resolved AGAINST THE CLAIMED ACTION: the record must
 *     match on workspace + action family + entity before it may support a claim.
 *
 * Scoping only ever REMOVES support — it can never bless a claim the old
 * workspace-wide read would have refused — so the invariant is strengthened,
 * never weakened.
 */
final class VerbalAuthorityGuard
{
    public function __construct(private ChatActionProposal $proposals) {}

    /**
     * Mutating action families: canonical name => every surface form that means
     * it. Listed explicitly rather than stemmed because English morphology is
     * exactly where the 2026-08-13 miss happened — publish/publication and
     * send/sent share no usable prefix. Read-only talk stays untouched: this is
     * the same fourteen verbs the guard has always policed, no new families.
     */
    private const VERB_FAMILY = [
        'publish'  => ['publish', 'publishes', 'published', 'publishing', 'publication',
                       'publications', 'republish', 'republished', 'republishing'],
        'post'     => ['post', 'posts', 'posted', 'posting'],
        'send'     => ['send', 'sends', 'sent', 'sending', 'dispatch', 'dispatches',
                       'dispatched', 'dispatching'],
        'delete'   => ['delete', 'deletes', 'deleted', 'deleting', 'deletion', 'deletions',
                       'remove', 'removes', 'removed', 'removing', 'removal', 'removals'],
        'create'   => ['create', 'creates', 'created', 'creating', 'creation', 'generate',
                       'generates', 'generated', 'generating', 'generation', 'commission',
                       'commissions', 'commissioned', 'commissioning'],
        'schedule' => ['schedule', 'schedules', 'scheduled', 'scheduling'],
        'queue'    => ['queue', 'queues', 'queued', 'queueing', 'queuing'],
        'launch'   => ['launch', 'launches', 'launched', 'launching'],
        'run'      => ['run', 'runs', 'ran', 'running', 'execute', 'executes', 'executed',
                       'executing', 'execution', 'retry', 'retries', 'retried', 'retrying'],
    ];

    /**
     * The thing acted on. Task actions in this platform are verb_noun
     * (publish_article, generate_image, delete_lead), so the same table resolves
     * both the prose and the record and they can be compared directly.
     */
    private const ENTITY_FAMILY = [
        'article'  => ['article', 'articles', 'draft', 'drafts', 'blog', 'blogs', 'piece', 'pieces'],
        'page'     => ['page', 'pages', 'landing'],
        'website'  => ['website', 'websites', 'site', 'sites'],
        'campaign' => ['campaign', 'campaigns', 'newsletter', 'newsletters', 'blast'],
        'email'    => ['email', 'emails', 'mail'],
        'lead'     => ['lead', 'leads', 'contact', 'contacts'],
        'image'    => ['image', 'images', 'photo', 'photos', 'picture', 'pictures', 'visual', 'visuals'],
        'video'    => ['video', 'videos', 'clip', 'clips'],
        'post'     => ['post', 'posts'],
        'task'     => ['task', 'tasks', 'job', 'jobs'],
        'keyword'  => ['keyword', 'keywords'],
        'link'     => ['link', 'links', 'backlink', 'backlinks'],
    ];

    /** A task's category is a second, coarser witness of what it does. */
    private const CATEGORY_VERB = [
        'publish'  => ['publish', 'post', 'launch'],
        'create'   => ['create'],
        'campaign' => ['send', 'launch', 'schedule'],
    ];

    /**
     * Claims of bypassed authority. These are never acceptable regardless of
     * state: even WITH a pending proposal, "without your approval" is false.
     */
    private const BYPASS = [
        '/\bwithout (further |any |your )?(confirmation|approval|sign[- ]?off|asking|checking)\b/',
        '/\bno (further |additional )?(confirmation|approval|sign[- ]?off) (is )?(needed|required|necessary)\b/',
        '/\bdon\'?t need (your |an? )?(confirmation|approval)\b/',
        '/\b(pre[- ]?approved|already approved by you|treat(ing)? (this|everything) as approved)\b/',
        '/\bskip(ping)? (the )?(confirmation|approval)\b/',
        '/\bwon\'?t ask (you )?again\b/',
    ];

    /** Present/future commitment to act. */
    private const COMMITMENT = [
        '/\bi(\'| a)?m (going to|about to) \w+/',
        '/\bi\'?ll \w+/',
        '/\bi will \w+/',
        '/\blet me (just )?\w+/',
        '/\bi\'?m \w+ing (the|them|it|these|those|all)\b/',
    ];

    /**
     * Commitment carried by a verb of PROCEEDING rather than by the action verb.
     * "I'll proceed with the publication now" names no listed verb in a position
     * the old matcher could see, and it is how T036 and T008 were actually
     * phrased. These need an action in scope (below) before they count.
     */
    private const PROCEED = [
        '/\bi\'?ll (go ahead and |now )?proceed\b/',
        '/\bi\'?ll go ahead\b/',
        '/\bi\'?m proceeding\b/',
        '/\bi\'?ll (take care of|handle|get (started )?on|start on|move ahead with)\b/',
        '/\blet me proceed\b/',
        '/\bproceeding with\b/',
    ];

    /**
     * What a PROCEED commitment is proceeding WITH. Either an action named as a
     * noun in the same sentence, or an anaphor pointing at one.
     */
    private const PROCEED_OBJECT = [
        '/\b(this|that|the) (task|job|work|request|one)\b/',
        '/\bwith (the |this |that )?\w*(publication|publishing|deletion|deleting|removal|sending|send|creation|generation|launch|execution|scheduling)\b/',
        '/\b(it|them|those|these)\b/',
    ];

    /**
     * Completion asserted with no verb and no object of its own — "Consider it
     * done." names nothing, which is precisely why the verb+object gate below
     * never saw it. Self-contained, so they are tested before that gate.
     */
    private const COMPLETION_BARE = [
        '/\bconsider it (done|handled|published|sent)\b/',
        '/\b(the|that) (publication|deletion|send|launch) (is|has been) (done|complete|completed|finished)\b/',
        '/\bit\'?s (all )?(done|handled|published|sent|live)[.!]/',
    ];

    /**
     * Work asserted to be HAPPENING. Weaker than completion — genuinely queued
     * work makes it true — so it resolves as a commitment.
     */
    private const PROGRESS_BARE = [
        '/\b(the|that) (publication|deletion|send|launch|publishing) is (underway|in progress|running|going out)\b/',
        '/\bthat\'?s (underway|in progress|running) (now|already)?\b/',
    ];

    /**
     * Claims that something already happened.
     *
     * The object alternation names nouns as well as determiners: "I've published
     * article 408" was invisible to the original list, which required the verb to
     * be followed by the|them|it|a digit — and "article" is none of those. That
     * is also what let the entity-id check go unreached.
     */
    private const COMPLETION = [
        // The subject is REQUIRED. With it optional, "You have 187 published
        // articles" parsed as a completion claim and the guard replaced a true
        // status answer with "nothing has actually run" — measured 2026-08-13
        // against the live route. "published articles" is an adjective and a
        // noun; "I've published the articles" is a verb and its object, and only
        // the second is a claim.
        '/\b(i|we)\s*(\'ve|\'d| have| had)?\s*(published|deleted|sent|launched|posted|queued|created|scheduled|removed)\s+'
        . '(the|them|it|these|those|all|\d|articles?|drafts?|pages?|posts?|campaigns?|emails?|leads?|images?|videos?)\b/',
        '/\b(has|have|had) been (published|sent|deleted|removed|launched|posted|scheduled|created)\b/',
        '/\b(done|completed|finished)[.,!]/',
        '/\bthat\'?s (now )?(published|sent|live|done)\b/',
        '/\bi went ahead and \w+/',
        '/\bconsider it (done|handled|published|sent)\b/',
        '/\b(the|that) (publication|deletion|send|launch) (is|has been) (done|complete|completed|finished)\b/',
    ];

    /**
     * Sentences that REPORT history rather than claim this turn's work.
     * "The 187 articles were published over the last month" is a true statement
     * about the record, and correcting it to "nothing has actually run" would be
     * the guard lying to protect an invariant that was never at risk.
     */
    private const HISTORICAL = [
        '/\b(last (week|month|year|night)|yesterday|earlier (this|in the) \w+|previously|originally)\b/',
        '/\bover the (last|past) \w+/',
        '/\b\d+ (minutes?|hours?|days?|weeks?|months?) ago\b/',
        '/\bsince (january|february|march|april|may|june|july|august|september|october|november|december|then|launch)\b/',
        '/\bin (january|february|march|april|may|june|july|august|september|october|november|december)\b/',
    ];

    /**
     * A sentence that REFUSES, or merely discusses, is not a claim of authority.
     * "I can't publish without your confirmation" contains "publish" and would
     * otherwise be corrected into a refusal of itself.
     */
    private const REFUSAL = [
        '/\b(can\'?t|cannot|won\'?t|will not|unable to|don\'?t have|do not have|no (capability|access))\b/',
        '/\bneeds? (your|owner) (approval|confirmation|go[- ]ahead)\b/',
        '/\bwaiting on (your|the owner)\b/',
        '/\bhaven\'?t started\b/',
        '/\b(would|could|might|if you)\b.{0,24}\b(want|like|prefer|say)\b/',
    ];

    /**
     * An OFFER conditioned on the owner is the behaviour we want, not a claim.
     *
     * "Just say the word and I'll generate them" promises nothing until the
     * owner speaks — it is Sarah asking permission, which is the whole point of
     * the two-turn protocol. Without this the guard corrected the correct
     * behaviour, and worse: "tell me to go ahead and I'll set it up with the
     * cost first" is the guard's OWN correction text, so a second pass over its
     * own output would have flagged it.
     */
    private const CONDITIONAL = [
        '/\b(say the word|just say|tell me to|let me know|give me the (go[- ]ahead|word))\b/',
        '/\b(when|whenever|once|if) you(\'re| are|\'d| would)?\s*(want|like|ready|approve|say|confirm|give|tell)\b/',
        '/\b(want|would you like|shall|should) (me |i )\w*/',
        '/\bon your (say[- ]so|word|go[- ]ahead)\b/',
    ];

    /** Negates Sarah's own ability to act, as opposed to describing a rule. */
    private const REFUSAL_MODAL = [
        '/\b(i )?(can\'?t|cannot|won\'?t|will not|am unable to|don\'?t have|do not have)\b/',
        '/\bneeds? (your|owner) (approval|confirmation|go[- ]ahead)\b/',
        '/\bthat\'?s your call\b/',
    ];

    /** A claim needs something concrete to act on, not just a verb. */
    private const OBJECT = [
        '/\b(the|them|it|these|those|all|\d+)\s+\w*\s*(draft|article|post|page|campaign|email|lead|image|task|site|website)/',
        '/\b(draft|article|post|page|campaign|email|lead|image|task)s?\b/',
        '/\bthem\b|\bit\b|\ball of (them|it)\b/',
    ];

    /**
     * Natural phrasing for the floor reply (P1-2). Measured at T035: the owner
     * said "I want the 37 drafts published" and got back nothing but the guard
     * sentence, because the model's whole reply WAS the claim and replacing it
     * left only the correction. A factual correction must not reduce Sarah to a
     * robotic one-liner when a truthful, useful answer can be built from the
     * same state she was corrected against.
     */
    private const VERB_PHRASE = [
        'publish' => 'publish', 'post' => 'post', 'send' => 'send', 'delete' => 'delete',
        'create' => 'create', 'schedule' => 'schedule', 'queue' => 'queue',
        'launch' => 'launch', 'run' => 'run',
    ];

    private const ENTITY_PHRASE = [
        'article' => 'those drafts', 'page' => 'those pages', 'website' => 'that site',
        'campaign' => 'that campaign', 'email' => 'that email', 'lead' => 'those leads',
        'image' => 'those images', 'video' => 'that video', 'post' => 'those posts',
        'keyword' => 'those keywords', 'link' => 'those links', 'task' => 'that',
    ];

    /** Statuses that mean the record is live work rather than finished work. */
    private const LIVE_STATUSES = ['pending', 'queued', 'running', 'verifying'];

    /** How recent a record must be to speak for THIS turn. */
    private const TURN_MINUTES = 3;

    /**
     * @return array{reply:string, fired:bool, state:string, claim:string, evidence:array}
     */
    public function validate(string $reply, int $wsId, ?string $conversationId = null,
                             ?string $executionId = null): array
    {
        $out = ['reply' => $reply, 'fired' => false, 'state' => 'not_applicable',
                'claim' => 'none', 'evidence' => []];

        if (trim($reply) === '') return $out;

        // Evaluate per sentence. Whole-reply matching is what corrected a
        // greeting and a refusal: a mutating word anywhere plus "I'll" anywhere
        // is not a claim, and treating it as one made Sarah refuse herself.
        $bypass = $commitment = $completion = false;
        $sentences = preg_split('/(?<=[.!?])\s+/u', $reply, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        // An action named ANYWHERE in the reply is what a bare "I'll proceed"
        // proceeds with. T036 named it in the cost sentence one clause earlier.
        $replyHasAction = false;
        foreach ($sentences as $sent) {
            $s = $this->normalise($sent);
            if ($this->matches($s, self::REFUSAL)) continue;
            if ($this->verbsIn($s) && $this->matches($s, self::OBJECT)) { $replyHasAction = true; break; }
        }

        foreach ($sentences as $sent) {
            $s = $this->normalise($sent);

            // Bypass language is decisive on its own: claiming approval can be
            // skipped is never acceptable, and such a sentence often contains a
            // negation ("I won't ask you again") that the refusal filter would
            // otherwise mistake for Sarah declining.
            if ($this->matches($s, self::BYPASS) && $this->verbsIn($s)
                && (!$this->matches($s, self::REFUSAL_MODAL) || $this->matches($s, self::COMMITMENT))) {
                $bypass = true; continue;
            }

            if ($this->matches($s, self::REFUSAL)) continue;      // refusing is not claiming
            if ($this->matches($s, self::CONDITIONAL)) continue;  // offering is not claiming
            if ($this->matches($s, self::HISTORICAL)) continue;   // reporting history is not claiming

            // Asserted with no verb or object of their own.
            if ($this->matches($s, self::COMPLETION_BARE)) { $completion = true; continue; }
            if ($this->matches($s, self::PROGRESS_BARE))   { $commitment = true; continue; }

            // Anaphoric commitment: "I'll proceed with the publication now."
            // "this task" is itself an action noun, so it needs no other action
            // in scope — that phrasing IS the commitment (measured at T008).
            if ($this->matches($s, self::PROCEED) && $this->matches($s, self::PROCEED_OBJECT)
                && ($replyHasAction || $this->verbsIn($s)
                    || preg_match('/\b(this|that|the) (task|job|work|request)\b/', $s)
                    || preg_match('/\b(publication|deletion|removal|sending|creation|generation|execution)\b/', $s))) {
                $commitment = true; continue;
            }

            if (!$this->verbsIn($s)) continue;                    // no action in THIS sentence
            if (!$this->matches($s, self::OBJECT)) continue;       // no concrete thing to act on

            if ($this->matches($s, self::COMPLETION)) $completion = true;
            if ($this->matches($s, self::COMMITMENT)) $commitment = true;
        }

        if (!$bypass && !$commitment && !$completion) return $out;

        $claim = $bypass ? 'bypass' : ($completion ? 'completion' : 'commitment');
        $out['claim'] = $claim;

        // ── authoritative state, SCOPED TO WHAT WAS CLAIMED ───────────────
        $shape = $this->claimShape($reply);
        try {
            $state = $this->authorityState($wsId, $conversationId, $executionId, $shape);
        } catch (\Throwable $e) {
            // A guard that cannot read the record must not invent a verdict.
            Log::warning('[Sarah888] VerbalAuthorityGuard could not read state', [
                'ws' => $wsId, 'error' => $e->getMessage()]);
            return $out;
        }
        $out['state'] = $state['state'];
        $out['evidence'] = $state['evidence'];

        // A bypass claim is false in EVERY state: approval is either required or
        // it is not, and Sarah does not get to waive it either way.
        if ($bypass) {
            $out['reply'] = $this->correct($reply, self::BYPASS,
                $this->truthFor('bypass', $state), $this->floorFor('bypass', $state, $shape));
            $out['fired'] = true;
        } elseif ($claim === 'completion' && !$this->supportsCompletion($state['state'])) {
            $out['reply'] = $this->correct($reply, array_merge(self::COMPLETION, self::COMPLETION_BARE),
                $this->truthFor('completion', $state), $this->floorFor('completion', $state, $shape));
            $out['fired'] = true;
        } elseif ($claim === 'commitment' && !$this->supportsCommitment($state['state'])) {
            $out['reply'] = $this->correct($reply, array_merge(self::COMMITMENT, self::PROCEED),
                $this->truthFor('commitment', $state), $this->floorFor('commitment', $state, $shape));
            $out['fired'] = true;
        }

        if ($out['fired']) {
            Log::warning('[Sarah888] VerbalAuthorityGuard — claim exceeded authority', [
                'ws' => $wsId, 'conversation_id' => $conversationId,
                'claim' => $claim, 'state' => $state['state'],
                'claim_shape' => $shape, 'evidence' => $state['evidence'],
            ]);
        }
        return $out;
    }

    /** Only a matching, finished record may support "I did it". */
    private function supportsCompletion(string $state): bool
    {
        return in_array($state, ['EXECUTED', 'EXECUTED_MATCH'], true);
    }

    /** Executed or genuinely queued work may support "I'll do it". */
    private function supportsCommitment(string $state): bool
    {
        return in_array($state, ['EXECUTED', 'EXECUTED_MATCH', 'QUEUED', 'QUEUED_MATCH'], true);
    }

    /**
     * What the record actually supports, strongest first.
     * Reads ONLY authoritative tables — never Experience888.
     *
     * With $claim supplied the resolution is SCOPED: only records matching the
     * claimed action family and entity may support the claim, and the returned
     * states carry the _MATCH suffix to make that explicit at the call site.
     * Without it the original workspace-wide behaviour is preserved for callers
     * that only ask "is anything happening here at all".
     */
    public function authorityState(int $wsId, ?string $conversationId, ?string $executionId = null,
                                   ?array $claim = null): array
    {
        if ($claim !== null && ($claim['verbs'] ?? [])) {
            return $this->scopedAuthorityState($wsId, $conversationId, $claim);
        }

        // EXECUTED — something reached a terminal completed state this turn.
        $exec = DB::table('tasks')->where('workspace_id', $wsId)
            ->where('status', 'completed')
            ->where('updated_at', '>=', now()->subMinutes(self::TURN_MINUTES))
            ->count();
        if ($exec > 0) return ['state' => 'EXECUTED', 'evidence' => ['completed_tasks_3min' => $exec]];

        // QUEUED — work exists and is runnable.
        $queued = DB::table('tasks')->where('workspace_id', $wsId)
            ->whereIn('status', ['pending', 'running'])
            ->where('created_at', '>=', now()->subMinutes(self::TURN_MINUTES))
            ->count();
        if ($queued > 0) return ['state' => 'QUEUED', 'evidence' => ['queued_tasks_3min' => $queued]];

        // PENDING — a proposal or task is genuinely awaiting the owner.
        $pending = count($this->proposals->pending($wsId, $conversationId));
        $awaiting = DB::table('tasks')->where('workspace_id', $wsId)
            ->where('status', 'awaiting_approval')->count();
        if ($pending > 0 || $awaiting > 0)
            return ['state' => 'PENDING', 'evidence' => ['proposals' => $pending, 'awaiting_approval' => $awaiting]];

        return ['state' => 'NONE', 'evidence' => []];
    }

    /**
     * The claimed action, resolved against the record.
     *
     * An unrelated deep_audit may not support a publish claim; an SEO task may
     * not support an email claim; and a task for article A may not prove
     * execution for article B.
     */
    private function scopedAuthorityState(int $wsId, ?string $conversationId, array $claim): array
    {
        $since = now()->subMinutes(self::TURN_MINUTES);

        // Candidates: anything that could speak for this turn, plus work parked
        // on the owner (which has no recency bound — it waits indefinitely).
        $rows = DB::table('tasks')->where('workspace_id', $wsId)
            ->where(function ($w) use ($since) {
                $w->where('updated_at', '>=', $since)
                  ->orWhere('created_at', '>=', $since)
                  ->orWhere('status', 'awaiting_approval');
            })
            ->orderByDesc('id')->limit(300)
            ->get(['id', 'engine', 'action', 'category', 'status', 'payload_json',
                   'created_at', 'updated_at']);

        // RISK-0123 (2026-08-29) — work created in THIS execution is, by construction, the work the
        // reply is talking about. The verb/entity model could not see a builder edit
        // ("ai_builder_action" carries no queue/run verb), so the guard answered NO_MATCH and
        // rewrote "I've updated the hero subtitle" into "I haven't started anything yet" while the
        // task ran to completion and the live site changed.
        $__execId = null;
        try { $__execId = app(\App\Core\Sarah888\CorrelationContext::class)->executionId(); } catch (\Throwable $e) {}

        $matched = [];
        foreach ($rows as $r) {
            if ($__execId) {
                $__p = json_decode((string) ($r->payload_json ?? '{}'), true) ?: [];
                if (($__p['execution_id'] ?? null) === $__execId) { $matched[] = $r; continue; }
            }
            if ($this->taskMatchesClaim($r, $claim)) $matched[] = $r;
        }

        $ev = fn(string $why, $row) => ['matched_task' => (int) $row->id,
            'action' => (string) $row->action, 'status' => (string) $row->status, 'why' => $why];

        foreach ($matched as $r) {
            if ($r->status === 'completed' && strtotime((string) $r->updated_at) >= $since->getTimestamp())
                return ['state' => 'EXECUTED_MATCH', 'evidence' => $ev('completed this turn', $r)];
        }
        foreach ($matched as $r) {
            if (in_array($r->status, self::LIVE_STATUSES, true)
                && (strtotime((string) $r->created_at) >= $since->getTimestamp()
                    || ($r->status === 'running'
                        && strtotime((string) $r->updated_at) >= $since->getTimestamp())))
                return ['state' => 'QUEUED_MATCH', 'evidence' => $ev('live work for this claim', $r)];
        }
        foreach ($matched as $r) {
            if ($r->status === 'awaiting_approval')
                return ['state' => 'PENDING_APPROVAL_MATCH', 'evidence' => $ev('awaiting owner', $r)];
        }

        // A matching proposal is the owner-facing half of the same state.
        $props = $this->matchingProposals($wsId, $conversationId, $claim);
        if ($props) {
            return ['state' => 'PENDING_APPROVAL_MATCH',
                    'evidence' => ['matched_proposal' => (int) $props[0]->id,
                                   'capability' => (string) ($props[0]->capability ?? ''),
                                   'why' => 'proposal awaiting owner']];
        }

        foreach ($matched as $r) {
            if ($r->status === 'failed' && strtotime((string) $r->updated_at) >= $since->getTimestamp())
                return ['state' => 'FAILED_MATCH', 'evidence' => $ev('attempt failed', $r)];
        }

        return ['state' => 'NO_MATCH', 'evidence' => [
            'claimed_verbs' => array_values($claim['verbs'] ?? []),
            'claimed_entities' => array_values($claim['entities'] ?? []),
            'candidates_examined' => count($rows),
        ]];
    }

    /** Pending chat proposals whose capability matches the claim. */
    private function matchingProposals(int $wsId, ?string $conversationId, array $claim): array
    {
        $hits = [];
        foreach ($this->proposals->pending($wsId, $conversationId) as $p) {
            $verbs    = $this->verbsIn($this->normalise((string) ($p->capability ?? '')));
            $entities = $this->entitiesIn($this->normalise(
                (string) ($p->capability ?? '') . ' ' . (string) ($p->entity_type ?? '')));

            if (!array_intersect($verbs, $claim['verbs'])) continue;
            if ($claim['entities'] && $entities && !array_intersect($entities, $claim['entities'])) continue;

            // "yes" to publishing article A must not prove anything about B.
            $pid = (string) ($p->entity_id ?? '');
            if ($pid !== '' && $claim['ids'] && !in_array($pid, $claim['ids'], true)) continue;

            $hits[] = $p;
        }
        return $hits;
    }

    /**
     * Does this task row speak for the claimed action?
     *
     * Task actions are verb_noun, so the action string resolves through the same
     * families as the prose. Category is a fallback witness for rows whose verb
     * is not in the action name.
     */
    private function taskMatchesClaim(object $task, array $claim): bool
    {
        $action = $this->normalise((string) ($task->action ?? ''));
        $verbs  = $this->verbsIn(str_replace('_', ' ', $action));

        foreach (self::CATEGORY_VERB[(string) ($task->category ?? '')] ?? [] as $v) $verbs[] = $v;
        $verbs = array_unique($verbs);

        if (!array_intersect($verbs, $claim['verbs'])) return false;

        // Entity: only decisive when BOTH sides name one. A task whose noun is
        // unresolvable must not be credited with matching, but neither should a
        // vague claim ("I'll publish them") be refused a genuine publish task.
        $taskEntities = $this->entitiesIn(str_replace('_', ' ', $action));
        // RISK-0123 (2026-08-29) — a page IS part of a website: "update_page" queued for "the website"
        // was refused as NO_MATCH, so the guard told the owner "nothing is queued" in the same reply
        // that ended "✅ Queued 1 tasks". Compare at the site family level.
        $fam = static fn (array $es) => array_unique(array_map(static fn ($e) => in_array($e, ['page', 'website'], true) ? 'site' : $e, $es));
        if ($claim['entities'] && $taskEntities
            && !array_intersect($fam($taskEntities), $fam($claim['entities']))) return false;

        // A specific id on both sides must agree.
        if ($claim['ids']) {
            $payload = json_decode((string) ($task->payload_json ?? '{}'), true) ?: [];
            foreach (['article_id', 'page_id', 'post_id', 'lead_id', 'campaign_id', 'website_id'] as $k) {
                if (!empty($payload[$k]) && !in_array((string) $payload[$k], $claim['ids'], true)) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * What the reply claims to be doing: action families, entity types, and any
     * explicit entity ids. Refusal sentences are excluded — they describe what
     * Sarah is NOT doing and must not shape the lookup.
     */
    public function claimShape(string $reply): array
    {
        $verbs = $entities = $ids = [];
        foreach (preg_split('/(?<=[.!?])\s+/u', $reply, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $sent) {
            $s = $this->normalise($sent);
            if ($this->matches($s, self::REFUSAL) && !$this->matches($s, self::BYPASS)) continue;

            foreach ($this->verbsIn($s) as $v) $verbs[] = $v;
            foreach ($this->entitiesIn($s) as $e) $entities[] = $e;

            if (preg_match_all('/\b(?:article|page|post|lead|campaign|draft|website)\s*#?(\d{1,9})\b/', $s, $m)) {
                foreach ($m[1] as $id) $ids[] = $id;
            }
        }
        return ['verbs' => array_values(array_unique($verbs)),
                'entities' => array_values(array_unique($entities)),
                'ids' => array_values(array_unique($ids))];
    }

    /**
     * What Sarah says when the correction consumed her entire reply.
     *
     * Same facts as truthFor(), but shaped as an answer rather than a rebuttal:
     * it names what she CAN do, states the real gate, and gives the owner the
     * next move. Every branch keeps the phrase the regression suites assert on,
     * because those phrases are the factual core, not decoration.
     */
    private function floorFor(string $claim, array $state, array $shape): string
    {
        // "I'll publish them." names no noun, but the record we matched against
        // does — so say "those drafts" rather than "that" when the authoritative
        // row can supply the object.
        if (!($shape['entities'] ?? []) && !empty($state['evidence']['action'])) {
            $shape['entities'] = $this->entitiesIn(
                str_replace('_', ' ', $this->normalise((string) $state['evidence']['action'])));
        }
        $do = $this->actionPhrase($shape);
        $pendingish = in_array($state['state'], ['PENDING', 'PENDING_APPROVAL_MATCH'], true);

        if ($claim === 'bypass') {
            return $pendingish
                ? "I can't skip that confirmation — it's still waiting on your go-ahead."
                : "I can't {$do} without your confirmation, and past approvals don't cover this one. "
                . "Say the word and I'll put it in front of you properly, with the cost.";
        }

        if ($claim === 'completion') {
            return match ($state['state']) {
                'QUEUED', 'QUEUED_MATCH' => "It's queued, not finished yet — I'll tell you the moment it lands.",
                'PENDING', 'PENDING_APPROVAL_MATCH' => "Nothing has run yet — it's waiting on your approval.",
                'FAILED_MATCH' => "I tried to {$do} and it failed — nothing went live. Want me to retry it?",
                // Deliberately not "say the word and I'll {$do}": a floor that
                // re-commits to the action is the same claim in softer clothes.
                default => "To be exact, nothing has actually run yet — tell me to go ahead "
                         . "and I'll set it up with the cost first.",
            };
        }

        if ($state['state'] === 'FAILED_MATCH') {
            return "I tried to {$do} and it failed — nothing went through. Want me to retry it?";
        }
        return $pendingish
            ? "I can {$do} — it just needs your approval first, then I'll run it."
            : "I haven't started it — I can {$do} as soon as you give me the go-ahead, "
            . "and I'll show you the cost before anything is spent.";
    }

    /** "publish those drafts" — from what the claim was actually about. */
    private function actionPhrase(array $shape): string
    {
        $verb = null;
        foreach ($shape['verbs'] ?? [] as $v) {
            if (isset(self::VERB_PHRASE[$v])) { $verb = self::VERB_PHRASE[$v]; break; }
        }
        if ($verb === null) return 'do that';

        foreach ($shape['entities'] ?? [] as $e) {
            if (isset(self::ENTITY_PHRASE[$e])) return $verb . ' ' . self::ENTITY_PHRASE[$e];
        }
        return $verb . ' that';
    }

    /** The true sentence that replaces the false one. Conversational, not canned-sounding. */
    private function truthFor(string $claim, array $state): string
    {
        $pendingish = in_array($state['state'], ['PENDING', 'PENDING_APPROVAL_MATCH'], true);

        if ($claim === 'bypass') {
            return $pendingish
                ? "I can't skip that confirmation — it's still waiting on your go-ahead."
                : "I can't publish without your confirmation, and past approvals don't cover this one. "
                . "Say the word and I'll put it in front of you properly, with the cost.";
        }
        if ($claim === 'completion') {
            return match ($state['state']) {
                'QUEUED', 'QUEUED_MATCH' => "To be exact: it's queued, not finished yet.",
                'PENDING', 'PENDING_APPROVAL_MATCH' => "To be exact: nothing has run — it's waiting on your approval.",
                'FAILED_MATCH' => "To be exact: it was attempted and failed, so nothing went live.",
                default => "To be exact: nothing has actually run, and nothing is queued.",
            };
        }
        if ($state['state'] === 'FAILED_MATCH') {
            return "I tried, but it failed — nothing went through.";
        }
        return $pendingish
            ? "I haven't started it — it needs your approval first."
            : "I haven't started anything yet; tell me to go ahead and I'll set it up with the cost first.";
    }

    /**
     * Sentence-level replacement, matching ConfirmationClaimGuard: the offending
     * sentence goes, everything Sarah legitimately said survives. A caveat
     * appended below would leave the false claim readable above it.
     */
    private function correct(string $reply, array $patterns, string $truth, ?string $floor = null): string
    {
        $sentences = preg_split('/(?<=[.!?])\s+/u', $reply, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (!$sentences) return $floor ?? $truth;

        $kept = []; $replaced = false; $survivors = 0;
        foreach ($sentences as $s) {
            $ns = $this->normalise($s);
            $isBypass = $this->matches($ns, self::BYPASS)
                && (!$this->matches($ns, self::REFUSAL_MODAL) || $this->matches($ns, self::COMMITMENT));
            if (!$isBypass && ($this->matches($ns, self::REFUSAL)
                || $this->matches($ns, self::CONDITIONAL)
                || $this->matches($ns, self::HISTORICAL))) { $kept[] = $s; $survivors++; continue; }

            // A PROCEED commitment carries no listed verb of its own — that is
            // the whole point of it — so it cannot be required to have one.
            $isProceed = $this->matches($ns, self::PROCEED) && $this->matches($ns, self::PROCEED_OBJECT)
                && in_array('/\bi\'?ll go ahead\b/', $patterns, true);

            // Likewise "Consider it done." and a bare "Done." — they must be
            // REMOVED, not merely contradicted a sentence later, or the reply
            // asserts and retracts the same fact ("Done. To be exact: it failed").
            $isBare = $patterns !== self::BYPASS
                && ($this->matches($ns, self::COMPLETION_BARE)
                    || $this->matches($ns, self::PROGRESS_BARE)
                    || preg_match('/^\s*(done|completed|finished|all set)\b[.,!]?\s*$/', $ns));

            if (($this->matches($ns, $patterns) && $this->verbsIn($ns)) || $isProceed || $isBare) {
                if (!$replaced) { $kept[] = $truth; $replaced = true; }
                continue;
            }
            $kept[] = $s; $survivors++;
        }
        if (!$replaced) $kept[] = $truth;

        // Nothing of Sarah's own survived — the claim WAS the reply. Answer the
        // owner from the same state instead of handing back a bare correction.
        if ($survivors === 0 && $floor !== null) return $floor;

        return trim(preg_replace('/[ \t]+/', ' ', implode(' ', $kept)));
    }

    private function matches(string $norm, array $patterns): bool
    {
        foreach ($patterns as $re) if (preg_match($re, $norm)) return true;
        return false;
    }

    /** Canonical action families named in this text. */
    private function verbsIn(string $norm): array
    {
        $found = [];
        foreach (self::VERB_FAMILY as $canon => $forms) {
            foreach ($forms as $f) {
                if (preg_match('/\b' . preg_quote($f, '/') . '\b/', $norm)) { $found[] = $canon; break; }
            }
        }
        return $found;
    }

    /** Entity families named in this text. */
    private function entitiesIn(string $norm): array
    {
        $found = [];
        foreach (self::ENTITY_FAMILY as $canon => $forms) {
            foreach ($forms as $f) {
                if (preg_match('/\b' . preg_quote($f, '/') . '\b/', $norm)) { $found[] = $canon; break; }
            }
        }
        return $found;
    }

    private function normalise(string $s): string
    {
        return mb_strtolower(strtr($s, [
            "\u{2018}" => "'", "\u{2019}" => "'", "\u{201C}" => '"', "\u{201D}" => '"',
            "\u{2013}" => '-', "\u{2014}" => '-', "\u{00A0}" => ' ',
        ]));
    }
}
