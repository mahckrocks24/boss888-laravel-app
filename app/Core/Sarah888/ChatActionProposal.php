<?php

namespace App\Core\Sarah888;

use Illuminate\Support\Facades\DB;

/**
 * SARAH888 — ASK_FIRST proposals for chat-originated work.
 *
 * The enterprise contract says an ASK_FIRST turn creates zero task rows, zero
 * queue entries and zero credit reservations until a later explicit
 * authorization. Measured before this existed: a directive whose capability
 * required approval produced a task row carrying a 5-credit cost and an
 * approval row alongside it, before the owner had said anything. Holding the
 * row was never the same as not creating it — a held task still has an
 * idempotency key, a queue position and a cost, and it still shows up in every
 * "what is outstanding" answer Sarah gives.
 *
 * WHY THIS REUSES strategy_proposals RATHER THAN ADDING A TABLE.
 * The platform already implements exactly this lifecycle for the proactive
 * strategy engine: a proposal exists first with no task, the approval binds to
 * a specific proposal_id, credits are reserved at approval, and the task is
 * created only afterwards. ApprovalController already routes proposal-linked
 * approvals to it. Building a parallel proposal store would have duplicated a
 * working subsystem and created a second source of truth for "what has been
 * authorised" — so this writes into the same table and rides the same approval
 * path.
 *
 * The proposal carries its own conversation and execution id so a later "yes"
 * can be checked against the request it claims to authorise. Without that
 * binding an approval given in one conversation could satisfy a pending action
 * from another.
 *
 * WHAT THE 2026-08-09 BINDING WORK ADDED, AND WHY.
 * Conversation and execution were enough to stop an approval crossing between
 * two conversations, and not enough to say WHAT a later "yes" authorises.
 * authorize() below checks a candidate authorization against the offer that was
 * actually made: same workspace, same conversation, still pending, not expired,
 * same entity, same scope, same quoted cost. Each of those is a way consent can
 * be silently exceeded — a "yes" to publishing one page must not publish a
 * different one, and a "yes" to five credits must not spend fifty.
 */
class ChatActionProposal
{
    /** Proposal type prefix. Kept distinct so it can never collide with the
     *  proactive engine's daily_action_* / weekly_pivot_* dispatch. */
    public const TYPE = 'chat_action';

    /** A proposal older than this may not be authorised by a later "yes". */
    public const TTL_MINUTES = 60;

    /** Outcomes of authorize(). Typed so callers branch on a reason, not prose. */
    public const AUTH_OK               = 'OK';
    public const AUTH_NOT_FOUND        = 'NOT_FOUND';
    public const AUTH_WRONG_WORKSPACE  = 'WRONG_WORKSPACE';
    public const AUTH_WRONG_CONVERSATION = 'WRONG_CONVERSATION';
    public const AUTH_NOT_PENDING      = 'NOT_PENDING';
    public const AUTH_EXPIRED          = 'EXPIRED';
    public const AUTH_COST_CHANGED     = 'COST_CHANGED';
    public const AUTH_SCOPE_CHANGED    = 'SCOPE_CHANGED';
    public const AUTH_ENTITY_CHANGED   = 'ENTITY_CHANGED';

    /**
     * Record a proposed action. Creates NO task, NO queue entry, NO credit
     * reservation — that is the entire point.
     *
     * @param array $payload the exact TaskService::create() payload to run later
     * @return array{proposal_id:int, approval_id:int}
     */
    public function propose(int $wsId, array $payload, array $turn = []): array
    {
        $action = (string) ($payload['action'] ?? 'unknown');
        $engine = (string) ($payload['engine'] ?? '');
        $cost   = (int) ($payload['credit_cost'] ?? 0);

        $entity     = $this->entityOf($payload);
        $capability = $engine !== '' ? "{$engine}.{$action}" : $action;

        $proposalId = (int) DB::table('strategy_proposals')->insertGetId([
            'workspace_id'    => $wsId,
            'conversation_id' => $turn['conversation_id'] ?? null,
            'execution_id'    => $turn['execution_id'] ?? null,
            // Which user message asked for this. An authorisation that cannot
            // name its own request is not auditable after the fact.
            'source_message_id' => isset($turn['user_message_id'])
                                    ? (int) $turn['user_message_id'] : null,
            'capability'      => $capability,
            'entity_type'     => $entity['type'],
            'entity_id'       => $entity['id'],
            'scope_hash'      => $this->scopeHash($payload),
            'risk_tier'       => $this->riskTier($action, (string) ($payload['category'] ?? ''), $cost),
            // Explicit, not computed at read time. A TTL that lives only in PHP
            // cannot be queried or audited, and a stale row looks identical to
            // a live one in the table.
            'expires_at'      => now()->addMinutes(self::TTL_MINUTES),
            'type'            => self::TYPE,
            'title'           => 'Requires your approval: ' . $action,
            'description'     => (string) ($payload['description']
                                  ?? ($payload['payload']['title'] ?? $action)),
            'status'          => 'pending_approval',
            'total_credits'   => $cost,
            // The full creation payload travels with the proposal so approval
            // executes exactly what was proposed and nothing reconstructed.
            'cost_breakdown_json' => json_encode([[
                'agent'       => $payload['assigned_agents'][0] ?? 'sarah',
                'action'      => $action,
                'engine'      => $engine,
                'credits'     => $cost,
                'description' => $payload['description'] ?? $action,
                'create_payload' => $payload,
            ]]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Mirror it into approvals so it appears in the owner's existing queue.
        // task_id stays NULL deliberately: there is no task, and that is the
        // invariant. ApprovalController routes on proposal_id before it ever
        // reaches its orphan check.
        $approvalId = (int) DB::table('approvals')->insertGetId([
            'workspace_id'   => $wsId,
            'engine'         => $engine,
            'action'         => $action,
            'capability_key' => $capability,
            'task_id'        => null,
            'proposal_id'    => $proposalId,
            'status'         => 'pending',
            'data_json'      => json_encode(['create_payload' => $payload]),
            'created_at'     => now(), 'updated_at' => now(),
        ]);

        return ['proposal_id' => $proposalId, 'approval_id' => $approvalId];
    }

    /**
     * Resolve a bare authorization ("yes, proceed") to exactly one proposal.
     *
     * Returns null when the answer is ambiguous or nothing is pending. A bare
     * "yes" must never pick a proposal for the owner: with two outstanding
     * requests, guessing has a fifty per cent chance of spending their credits
     * on the one they did not mean.
     *
     * @return object|null the single unambiguous pending proposal
     */
    public function resolveAuthorization(int $wsId, ?string $conversationId): ?object
    {
        $rows = $this->pendingQuery($wsId, $conversationId)->orderByDesc('id')->get();

        return count($rows) === 1 ? $rows[0] : null;   // 0 = nothing, >1 = ambiguous
    }

    /** Pending proposals in this conversation, for disclosure when ambiguous. */
    public function pending(int $wsId, ?string $conversationId = null): array
    {
        return $this->pendingQuery($wsId, $conversationId)->orderByDesc('id')->get()->all();
    }

    /**
     * The live-proposal query, in one place.
     *
     * Liveness is checked TWO ways deliberately. expires_at is the explicit,
     * queryable boundary added on 2026-08-09; the created_at window is the
     * original rule and still governs the 1,783 proposals written before that
     * column existed, which carry NULL. A row is live only if it satisfies
     * both, so neither path can resurrect something the other retired.
     */
    private function pendingQuery(int $wsId, ?string $conversationId)
    {
        $q = DB::table('strategy_proposals')
            ->where('workspace_id', $wsId)
            ->where('type', self::TYPE)
            ->where('status', 'pending_approval')
            ->where('created_at', '>=', now()->subMinutes(self::TTL_MINUTES))
            ->where(function ($w) {
                $w->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });

        // Scope to the conversation that is speaking. An approval given here
        // must not reach a proposal made somewhere else.
        if ($conversationId !== null) {
            $q->where('conversation_id', $conversationId);
        }

        return $q;
    }

    /**
     * May THIS authorization, spoken here and now, execute THAT proposal?
     *
     * Every check below is a way consent can be silently exceeded between the
     * moment an offer is made and the moment it is accepted. They are checked
     * against the row rather than against the reply, because the reply is prose
     * and prose is not an authorisation record.
     *
     * $expect optionally carries what the caller believes it is approving —
     * currently cost, entity and scope. Where a value is absent from $expect it
     * is not checked, so a caller that only knows the proposal id still gets
     * workspace, conversation, status and expiry enforcement.
     *
     * @return array{ok:bool, reason:string, proposal:object|null}
     */
    public function authorize(int $wsId, ?string $conversationId, int $proposalId, array $expect = []): array
    {
        $no = fn(string $r, ?object $p = null) => ['ok' => false, 'reason' => $r, 'proposal' => $p];

        $p = DB::table('strategy_proposals')->where('id', $proposalId)
                ->where('type', self::TYPE)->first();

        if (!$p)                                    return $no(self::AUTH_NOT_FOUND);
        if ((int) $p->workspace_id !== $wsId)       return $no(self::AUTH_WRONG_WORKSPACE, $p);
        if ($conversationId !== null && $p->conversation_id !== null
            && $p->conversation_id !== $conversationId) {
            return $no(self::AUTH_WRONG_CONVERSATION, $p);
        }
        if ($p->status !== 'pending_approval')      return $no(self::AUTH_NOT_PENDING, $p);

        $expired = ($p->expires_at !== null && strtotime((string) $p->expires_at) <= time())
                || ($p->created_at !== null
                    && strtotime((string) $p->created_at) < time() - self::TTL_MINUTES * 60);
        if ($expired)                               return $no(self::AUTH_EXPIRED, $p);

        // The offer quoted a price. Approving it authorises that price, not
        // whatever the action happens to cost by the time the owner says yes.
        if (array_key_exists('credit_cost', $expect)
            && (int) $expect['credit_cost'] !== (int) $p->total_credits) {
            return $no(self::AUTH_COST_CHANGED, $p);
        }

        if (array_key_exists('entity_id', $expect)
            && (string) $expect['entity_id'] !== (string) ($p->entity_id ?? '')) {
            return $no(self::AUTH_ENTITY_CHANGED, $p);
        }

        if (array_key_exists('scope_hash', $expect)
            && (string) $expect['scope_hash'] !== (string) ($p->scope_hash ?? '')) {
            return $no(self::AUTH_SCOPE_CHANGED, $p);
        }

        return ['ok' => true, 'reason' => self::AUTH_OK, 'proposal' => $p];
    }

    /**
     * What the owner must be told before they approve.
     *
     * An approval given without knowing the cost is not informed consent. The
     * proposal has carried total_credits since it was built; this is what turns
     * that number into something the owner actually sees. Where several
     * proposals are pending, the total is stated too — approving three things
     * at five credits each is a fifteen-credit decision, and it should not
     * arrive as three separate five-credit surprises.
     *
     * Returns '' when nothing is pending, so a caller can append it
     * unconditionally.
     */
    public function disclosure(int $wsId, ?string $conversationId = null): string
    {
        $pending = $this->pending($wsId, $conversationId);
        if (!$pending) return '';

        $total = 0;
        foreach ($pending as $p) $total += (int) $p->total_credits;

        if (count($pending) === 1) {
            $p = $pending[0];
            $cost = (int) $p->total_credits;
            return "\n\nAwaiting your approval: " . $p->description
                 . ($cost > 0 ? " — {$cost} credit" . ($cost === 1 ? '' : 's') . '.'
                              : ' — no credit cost.')
                 . " Nothing has been created or charged yet. Say yes and I'll run it.";
        }

        $lines = '';
        foreach ($pending as $p) {
            $c = (int) $p->total_credits;
            $lines .= "\n  · " . $p->description . ($c > 0 ? " ({$c} credits)" : ' (free)');
        }
        return "\n\n" . count($pending) . " things are awaiting your approval"
             . ($total > 0 ? ", {$total} credits in total" : '') . ':' . $lines
             . "\n\nNothing has been created or charged yet. Tell me which one to run — "
             . "a plain \"yes\" is ambiguous while more than one is pending.";
    }

    /** The exact payload a proposal authorises. Never reconstructed from prose. */
    public function payloadFor(object $proposal): ?array
    {
        $b = json_decode((string) $proposal->cost_breakdown_json, true);
        return $b[0]['create_payload'] ?? null;
    }

    // ── binding helpers ────────────────────────────────────────────────────

    /**
     * The specific thing an action would act on.
     *
     * The id is what matters for cross-binding — "yes" must not publish a
     * different page than the one offered — so the id keys are checked in the
     * order the platform's task payloads actually use them. When no id is
     * present the type is still recorded: knowing the offer was about an
     * article, even an unresolved one, is what lets a later approval be
     * refused for naming a different noun.
     */
    private function entityOf(array $payload): array
    {
        $params = (array) ($payload['payload'] ?? $payload['params'] ?? []);

        foreach (['article_id' => 'article', 'page_id' => 'page', 'post_id' => 'post',
                  'lead_id' => 'lead', 'website_id' => 'website',
                  'builder_page_id' => 'builder_page', 'campaign_id' => 'campaign',
                  'domain_id' => 'domain'] as $key => $type) {
            if (!empty($params[$key])) {
                return ['type' => $type, 'id' => (string) $params[$key]];
            }
        }

        // No id — fall back to the noun the action itself names, so the offer
        // still records what kind of thing it was about.
        $action = strtolower((string) ($payload['action'] ?? ''));
        foreach (['article', 'page', 'post', 'lead', 'website', 'campaign', 'domain'] as $noun) {
            if (str_contains($action, $noun)) return ['type' => $noun, 'id' => null];
        }
        return ['type' => null, 'id' => null];
    }

    /**
     * A digest of everything the approval is consenting to.
     *
     * If any of it moves between the offer and the "yes", the consent no longer
     * covers what would run. Keys are sorted recursively so an equivalent
     * payload built in a different order hashes the same — otherwise the guard
     * would fire on key ordering, which is noise, and stop firing on real scope
     * changes because everything looked like a change.
     */
    public function scopeHash(array $payload): string
    {
        $canon = [
            'action'      => (string) ($payload['action'] ?? ''),
            'engine'      => (string) ($payload['engine'] ?? ''),
            'credit_cost' => (int) ($payload['credit_cost'] ?? 0),
            'params'      => (array) ($payload['payload'] ?? $payload['params'] ?? []),
        ];
        $sort = function (&$a) use (&$sort) {
            if (!is_array($a)) return;
            ksort($a);
            foreach ($a as &$v) $sort($v);
        };
        $sort($canon);

        return hash('sha256', json_encode($canon));
    }

    /**
     * The policy band the offer was made under.
     *
     * Recorded on the row rather than re-derived at approval time: the band
     * that governed the offer is the band the owner consented to, and policy
     * can change between the two turns.
     */
    private function riskTier(string $action, string $category, int $cost): string
    {
        $a = strtolower($action);
        if (str_contains($a, 'delete') || str_contains($a, 'remove')
            || str_contains($a, 'destroy')) return 'destructive';
        if ($category === 'publish' || str_contains($a, 'publish')) return 'publish';
        if (str_contains($a, 'send') || str_contains($a, 'email')) return 'outbound';
        if ($cost > 0) return 'spend';
        return 'standard';
    }
}
