<?php

namespace App\Core\Sarah888;

use Illuminate\Support\Facades\Log;

/**
 * SARAH888 — THE BOUNDARY. Runtime asks; Laravel decides and executes.
 *
 * Architecture ruling 2026-08-13. Runtime owns reasoning, capability SELECTION and
 * interpretation of results. Laravel owns credentials, connectors, governance and
 * execution. This class is the only place a runtime-selected capability becomes real
 * work, and it is the only place that may report that work as done.
 *
 * ORDER IS LOAD-BEARING:
 *   1. does the capability exist          -> UNAVAILABLE
 *   2. is it active in the registry       -> UNAVAILABLE
 *   3. is its provider configured HERE    -> MISCONFIGURED   (per-workspace for GSC)
 *   4. are the required parameters present-> REFUSED
 *   5. does governance permit it NOW      -> REQUIRES_APPROVAL
 *   6. only then: run the real connector  -> SUCCEEDED / FAILED
 *
 * Steps 1-5 execute nothing. That is the point: the runtime is told the truth about
 * why nothing ran, in a typed form it can reason from, instead of inventing a result.
 *
 * SPEND FAILS CLOSED, matching TaskService's 1E.2 cost gate: a capability that costs
 * credits needs an authorised turn behind it, or it becomes an approval rather than an
 * execution. A read is not automatically free.
 */
final class ToolIntentGateway
{
    /**
     * Informational READ executors exempt from the launch-scope gate in
     * handle() — see the dated comment there and EV-0590 for the evidence.
     * Adding an entry here requires the same standard: a documented product
     * rationale and proof the policy's denial targets a different tool or an
     * outbound action this executor does not perform.
     */
    private const SCOPE_EXEMPT_READS = ['get_queue', 'list_campaigns'];

    public function __construct(
        private CapabilityManifest $manifest,
        private ChatActionProposal $proposals,
    ) {}

    /**
     * @param bool $shadow when true a MUTATING capability is never executed: Laravel
     *                     computes what it WOULD decide and returns a WOULD_* status.
     *                     Reads still run for real, because the entire point of the
     *                     shadow is that Runtime reasons over REAL evidence.
     */
    public function handle(ToolIntent $intent, bool $shadow = false): ToolResult
    {
        $cap = $this->manifest->capability($intent->workspaceId, $intent->capabilityId);

        // Shadow reports the same decision in the simulated vocabulary, so a dry-run
        // outcome can never be read as something that actually happened.
        $sim = function (ToolResult $r) use ($shadow): ToolResult {
            if (!$shadow) return $r;
            return new ToolResult(
                ToolResult::toSimulated($r->status), $r->capabilityId, null, null,
                $r->creditCost, 0, $r->errorClass, $r->message, null, null,
                $r->missingConfiguration
            );
        };

        if ($cap === null) {
            return $sim(ToolResult::unavailable($intent->capabilityId,
                'No such capability in the registry.'));
        }
        if (!$cap['registry_active']) {
            return $sim(ToolResult::unavailable($intent->capabilityId,
                'That capability is registered but not active.'));
        }

        // MISSION-018 WS-1 (2026-08-24, RISK-0035): this gateway consulted no
        // launch-scope predicate anywhere, so a removed capability wired here
        // would execute while four other layers existed to stop it. The policy
        // is now consulted on every call, keyed on the MANIFEST's engine —
        // never on caller input (RISK-0038's lesson).
        //
        // Two named exceptions, deliberately, with the evidence:
        //   - get_queue: Sarah's TASK-queue read. It collides by NAME with the
        //     removed standalone-social queue tool id in REMOVED_TOOLS; the
        //     policy's denial is about the other tool. Same-name-two-meanings
        //     is the RISK-0038 class, recorded in EV-0590.
        //   - list_campaigns: a read of existing workspace data. Refusing it
        //     is the exact defect the ChefRed transcript exposed (turn 26):
        //     Sarah told the owner the capability "isn't wired up" when the
        //     true answer was "you have no campaigns". Reads of what exists
        //     are platform intelligence (DEC-0021), not the removed outbound
        //     capability; create/send/schedule stay removed and unwired.
        // When the activation registry (MISSION-018 §C4) flips capabilities
        // back on, this gate follows the policy automatically.
        if (!in_array($intent->capabilityId, self::SCOPE_EXEMPT_READS, true)) {
            $scopeDenial = \App\Core\LaunchScope\LaunchScopePolicy::isRemovedTool($intent->capabilityId)
                ? 'LAUNCH_SCOPE_REMOVED_TOOL'
                : \App\Core\LaunchScope\LaunchScopePolicy::deniedReason(
                    (string) ($cap['engine'] ?? ''), $intent->capabilityId, $intent->parameters, []);
            if ($scopeDenial !== null) {
                return $sim(ToolResult::unavailable($intent->capabilityId,
                    'That capability is outside the current launch scope (' . $scopeDenial . ').'));
            }
        }
        if (!$cap['provider_ready']) {
            return $sim(ToolResult::misconfigured($intent->capabilityId,
                $cap['provider'] === 'gsc'
                    ? 'Search Console is not connected for this workspace.'
                    : "The {$cap['provider']} connector is not configured.",
                $cap['missing_configuration']));
        }

        $missing = array_values(array_diff($cap['required_parameters'],
            array_keys(array_filter($intent->parameters, fn ($v) => $v !== null && $v !== ''))));
        if ($missing) {
            return $sim(ToolResult::refused($intent->capabilityId,
                'Missing required parameter(s): ' . implode(', ', $missing) . '.'));
        }

        // ── 4b. WHERE DID THE PARAMETERS COME FROM? ─────────────────────────
        // Presence is not correctness. Asked "can you check where we rank?" — no keyword in
        // the question — Sarah billed a live SERP for a keyword carried from an earlier
        // turn in 2 of 4 runs, and asked in the other 2. A paid or world-changing action
        // may not run on a guess, so an unsafely-inferred parameter is refused with a
        // question rather than executed. Free local reads are untouched: asking about
        // everything would be insufferable and guessing a table costs nothing.
        if ($this->costsOrChanges($cap, $intent)) {
            $pp = app(ParameterProvenance::class);
            // P2-U2 (2026-08-30): an article the owner named in this turn overrides a carried-over id.
            $bound = $pp->bindFromOwnerWords($intent, $cap['required_parameters']);
            foreach ($bound as $bp => $bv) {
                if ((string) ($intent->parameters[$bp] ?? '') !== (string) $bv) {
                    \Illuminate\Support\Facades\Log::info('sarah888.provenance.bound_from_owner_words', ['param' => $bp, 'model' => $intent->parameters[$bp] ?? null, 'bound' => $bv, 'ws' => $intent->workspaceId]);
                    $intent = new ToolIntent($intent->capabilityId, $intent->workspaceId, array_merge($intent->parameters, [$bp => $bv]),
                        $intent->objective, $intent->conversationId, $intent->executionId, $intent->entityType, $intent->entityId, $intent->requestedBy, $intent->ownerMessage);
                }
            }
            $seen = $pp->classify($intent, $cap['required_parameters']);
            if ($seen['unsafe'] !== []) {
                return $sim(ToolResult::refused($intent->capabilityId,
                    $pp->question($intent->capabilityId, $seen['provenance'])));
            }
        }

        $gate = $this->governs($cap, $intent);

        if ($shadow && $cap['operation_type'] === 'mutation') {
            // Never executed on the shadow path. Report what governance WOULD say.
            $would = ToolResult::toSimulated($gate?->status ?? ToolResult::SUCCEEDED);
            return new ToolResult($would, $intent->capabilityId, null, null,
                (int) $cap['approval_inputs']['observed_max_credits'], 0, null,
                $gate?->message ?? 'Governance would permit this; shadow did not run it.');
        }

        if ($gate !== null) return $sim($gate);

        return $this->executeAndCharge($cap, $intent, $shadow);
    }

    /**
     * Does getting this wrong cost money or change the world?
     *
     * Those are the only two cases where a guessed parameter is worth interrupting the
     * owner over. A free local read that picks the wrong filter is a bad answer, not a
     * loss — and a Sarah who asks a clarifying question before every lookup is not a
     * colleague, she is a form.
     */
    private function costsOrChanges(array $cap, ToolIntent $intent): bool
    {
        // ONLY THE MODEL'S GUESSES ARE POLICED.
        //
        // The defect is a language model filling in a parameter the owner never said. An
        // intent built directly in PHP — a test, an internal caller, a scheduled job — is
        // code STATING a value, not a model inferring one, and there is no owner message
        // against which "did they say this?" could even be asked. Applying the check there
        // would refuse every programmatic caller for lacking a conversation it never had.
        //
        // `requestedBy` already draws exactly this line: `fromRuntime()` sets 'runtime',
        // direct construction does not. A runtime intent that arrives WITHOUT the owner's
        // message still gets checked, and still fails closed — that is the fail-safe.
        if ($intent->requestedBy !== 'runtime') return false;

        if ($cap['operation_type'] === 'mutation') return true;
        return app(CapabilityPricing::class)
            ->isChargeable($intent->capabilityId, $cap['engine'] ?? null);
    }

    /**
     * Execute, and bill the workspace for it if the platform prices it.
     *
     * THE GAP THIS CLOSES. The gateway calls connectors directly — that is the point of
     * the boundary, and why a SERP takes 2.3s instead of 40s — so it never passed the task
     * pipeline that charges credits. Measured 2026-08-14: a real DataForSEO query executed
     * and both `credits.balance` and `credit_transactions` were unchanged. A vendor query
     * was purchased and nobody was billed.
     *
     * LIFECYCLE, and every branch of it is deliberate:
     *
     *   quote        canonical blueprint price only — see CapabilityPricing
     *   eligibility  refuse BEFORE the vendor is called, so an unaffordable read does not
     *                spend real money and then fail
     *   reserve      before execution, so concurrent turns cannot both spend the last credit
     *   commit       ONLY on TOOL_SUCCEEDED
     *   release      on failure or exception — a DataForSEO call that returned nothing
     *                must not bill the owner
     *
     * NOT CHARGED, per platform policy: shadow (this is our testing, not the owner's
     * usage — LevelUp absorbs the vendor cost of validating its own system), anything
     * refused before execution, unavailable, misconfigured, or failing validation. After
     * cutover the shadow flag is false and the same code bills normally.
     *
     * Reservation is released in a `finally`, so a throw between reserve and commit cannot
     * strand credits in a pending reservation.
     */
    private function executeAndCharge(array $cap, ToolIntent $intent, bool $shadow): ToolResult
    {
        $quote = app(CapabilityPricing::class)
            ->quote($intent->capabilityId, $cap['engine'] ?? null);
        $credits = (int) $quote['credits'];

        // Free, or our own shadow validation: execute with no ledger involvement at all.
        if ($credits <= 0 || $shadow) {
            return $this->execute($cap, $intent);
        }

        $billing = app(\App\Core\Billing\CreditService::class);
        $wsId = $intent->workspaceId;

        if (!$billing->hasBalance($wsId, $credits)) {
            // Refused before the vendor is called: no spend, nothing to unwind.
            return ToolResult::requiresApproval($intent->capabilityId,
                "That costs {$credits} credit(s) and this workspace doesn't have them.",
                null, $credits);
        }

        $ref = $billing->reserve($wsId, $credits,
            "sarah888:{$intent->capabilityId}");

        $committed = false;
        try {
            $result = $this->execute($cap, $intent);

            if ($result->status === ToolResult::SUCCEEDED) {
                $billing->commitReservedCredits($ref);
                $committed = true;
            }
            return $result;
        } finally {
            // Anything that is not a committed success releases the hold — including an
            // exception thrown past this point.
            if (!$committed) {
                try { $billing->releaseReservedCredits($ref); }
                catch (\Throwable $e) {
                    Log::error('[Sarah888] could not release a credit reservation', [
                        'ws' => $wsId, 'ref' => $ref, 'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * Governance. Returns a non-executed result when the owner must decide first,
     * or null when the work may run now.
     *
     * Experience888 can never reach this method: a learned preference is not an
     * authorisation, and this reads only capability shape and spend authorisation.
     */
    private function governs(array $cap, ToolIntent $intent): ?ToolResult
    {
        $risk = $cap['approval_inputs']['risk_class'];
        $cost = (int) $cap['approval_inputs']['observed_max_credits'];

        // Risk classes describe how an action changes the world, so they gate
        // MUTATIONS only. Applying them to reads blocked `email_readiness` —
        // a report on whether email is configured — because riskClass() sees the
        // substring "email" and returns 'outbound'.
        if ($cap['operation_type'] === 'mutation') {
            return ToolResult::requiresApproval($intent->capabilityId,
                "That changes things, so it needs your approval first.", null, $cost);
        }

        if ($cost > 0) {
            $authorised = false;
            try { $authorised = app(SpendContext::class)->isAuthorized(); }
            catch (\Throwable $e) { $authorised = false; }   // fail closed
            if (!$authorised) {
                return ToolResult::requiresApproval($intent->capabilityId,
                    "That costs credits, so it needs your go-ahead first.", null, $cost);
            }
        }

        return null;
    }

    /**
     * Run the REAL connector. Every branch returns evidence or a typed failure —
     * never a promise, and never a success without data.
     */
    private function execute(array $cap, ToolIntent $intent): ToolResult
    {
        $t0 = microtime(true);
        $ms = fn () => (int) round((microtime(true) - $t0) * 1000);

        try {
            switch ($intent->capabilityId) {
                case 'serp_analysis':
                case 'competitor_serp':
                    $c   = app(\App\Connectors\DataForSeoConnector::class);
                    $kw  = (string) $intent->parameters['keyword'];
                    $loc = $intent->parameters['location'] ?? 'United States';
                    $code = is_numeric($loc) ? (int) $loc : (int) $c->locationCodeFromText((string) $loc);
                    $data = $c->serpAnalysis($kw, $code);

                    if (!is_array($data) || ($data['success'] ?? false) !== true) {
                        return ToolResult::failed($intent->capabilityId, 'provider_error',
                            'DataForSEO returned no usable result.', $ms());
                    }
                    return ToolResult::succeeded($intent->capabilityId, $data,
                        'DataForSeoConnector::serpAnalysis', $ms());

                case 'gsc_performance':
                    return $this->executeGscPerformance($intent, $ms);

                case 'content_state':
                    return $this->executeContentState($intent, $ms);

                case 'list_articles':
                    return $this->executeListArticles($intent, $ms);

                case 'list_campaigns':
                    return $this->executeListCampaigns($intent, $ms);

                case 'get_lead':
                    return $this->executeGetLead($intent, $ms);

                case 'list_goals':
                    return $this->executeListGoals($intent, $ms);

                case 'recent_tasks':
                    return $this->executeRecentTasks($intent, $ms);

                case 'email_readiness':
                    return $this->executeEmailReadiness($intent, $ms);

                case 'list_leads':
                    return $this->executeListLeads($intent, $ms);

                case 'get_queue':
                    return $this->executeGetQueue($intent, $ms);

                default:
                    // Registered, permitted, but this gateway has no executor wired yet.
                    // Deliberately NOT a failure the runtime can describe as an attempt.
                    return ToolResult::unavailable($intent->capabilityId,
                        'No Laravel executor is wired for this capability yet.');
            }
        } catch (\Throwable $e) {
            Log::warning('[Sarah888] ToolIntentGateway execution threw', [
                'ws' => $intent->workspaceId, 'capability' => $intent->capabilityId,
                'error' => $e->getMessage(),
            ]);
            return ToolResult::failed($intent->capabilityId, 'exception',
                'The tool failed to run.', $ms());
        }
    }
    /**
     * Search Console performance for this workspace, from the synced store.
     *
     * Provenance names the table AND the sync time, because "what does GSC say"
     * and "what did GSC say when we last synced" are different claims and the
     * owner is entitled to know which one they are getting.
     *
     * @param callable():int $ms
     */
    private function executeGscPerformance(ToolIntent $intent, callable $ms): ToolResult
    {
        $wsId = $intent->workspaceId;
        $days = (int) ($intent->parameters['days'] ?? 28);
        $days = max(1, min($days, 365));
        $limit = (int) ($intent->parameters['limit'] ?? 10);
        $limit = max(1, min($limit, 50));

        $conn = \Illuminate\Support\Facades\DB::table('gsc_connections')
            ->where('workspace_id', $wsId)->first();
        $since = now()->subDays($days)->toDateString();

        $base = fn () => \Illuminate\Support\Facades\DB::table('gsc_metrics')
            ->where('workspace_id', $wsId)->where('date', '>=', $since);

        $totals = (clone $base())->selectRaw(
            'SUM(clicks) clicks, SUM(impressions) impressions, AVG(position) position, COUNT(*) row_count'
        )->first();

        if ($totals === null || (int) $totals->row_count === 0) {
            // Connected but nothing synced for the window. That is a real answer,
            // not a failure, and it must not be dressed up as data.
            return ToolResult::succeeded($intent->capabilityId, [
                'window_days' => $days, 'since' => $since, 'rows' => 0,
                'totals' => ['clicks' => 0, 'impressions' => 0, 'ctr' => null, 'position' => null],
                'top_queries' => [], 'top_pages' => [],
                'site_url' => $conn->site_url ?? null,
                'last_sync_at' => $conn->last_sync_at ?? null,
                'note' => 'Search Console is connected but no data is synced for this window.',
            ], 'gsc_metrics (synced store)', $ms());
        }

        $clicks = (int) $totals->clicks;
        $impr   = (int) $totals->impressions;

        $queries = (clone $base())
            ->selectRaw('query, SUM(clicks) clicks, SUM(impressions) impressions, AVG(position) position')
            ->whereNotNull('query')->where('query', '!=', '')
            ->groupBy('query')->orderByDesc('impressions')->limit($limit)->get()
            ->map(fn ($r) => [
                'query' => (string) $r->query,
                'clicks' => (int) $r->clicks,
                'impressions' => (int) $r->impressions,
                'position' => round((float) $r->position, 1),
            ])->all();

        $pages = (clone $base())
            ->selectRaw('page, SUM(clicks) clicks, SUM(impressions) impressions, AVG(position) position')
            ->whereNotNull('page')->where('page', '!=', '')
            ->groupBy('page')->orderByDesc('impressions')->limit($limit)->get()
            ->map(fn ($r) => [
                'page' => (string) $r->page,
                'clicks' => (int) $r->clicks,
                'impressions' => (int) $r->impressions,
                'position' => round((float) $r->position, 1),
            ])->all();

        $range = (clone $base())->selectRaw('MIN(date) mind, MAX(date) maxd')->first();

        return ToolResult::succeeded($intent->capabilityId, [
            'window_days'  => $days,
            'since'        => $since,
            'data_range'   => ['from' => $range->mind ?? null, 'to' => $range->maxd ?? null],
            'rows'         => (int) $totals->row_count,
            'totals'       => [
                'clicks'      => $clicks,
                'impressions' => $impr,
                // CTR is DERIVED here, never read from a per-row average, because
                // averaging per-row ctr across queries is not the site ctr.
                'ctr'         => $impr > 0 ? round($clicks / $impr * 100, 2) : null,
                'position'    => round((float) $totals->position, 1),
            ],
            'top_queries'  => $queries,
            'top_pages'    => $pages,
            'site_url'     => $conn->site_url ?? null,
            'last_sync_at' => $conn->last_sync_at ?? null,
            'measurement'  => 'Search Console AVERAGE position, not a live SERP rank.',
        ], 'gsc_metrics (synced store, last sync ' . ($conn->last_sync_at ?? 'unknown') . ')', $ms());
    }

    /** Content inventory, straight from the articles table. */
    private function executeContentState(ToolIntent $intent, callable $ms): ToolResult
    {
        $q = fn () => \Illuminate\Support\Facades\DB::table('articles')
            ->where('workspace_id', $intent->workspaceId);

        $byStatus = [];
        foreach ((clone $q())->selectRaw('status, COUNT(*) c')->groupBy('status')->get() as $r) {
            $byStatus[(string) $r->status] = (int) $r->c;
        }
        $missingImages = (clone $q())->whereIn('status', ['published', 'draft'])
            ->where(function ($w) {
                $w->whereNull('featured_image_url')->orWhere('featured_image_url', '');
            })->count();

        return ToolResult::succeeded($intent->capabilityId, [
            'by_status'      => $byStatus,
            'published'      => $byStatus['published'] ?? 0,
            'drafts'         => $byStatus['draft'] ?? 0,
            'scheduled'      => $byStatus['scheduled'] ?? 0,
            'total'          => array_sum($byStatus),
            'missing_featured_image' => $missingImages,
        ], 'articles table', $ms());
    }

    /**
     * The articles themselves, not a count of them.
     *
     * `content_state` answers "how much content is there"; publishing needs "which one",
     * because `publish_article` takes an article_id. Without this, Runtime could see 37
     * drafts and still have no way to name one — which is how it ended up inventing a
     * `list_posts` capability and then telling the owner publishing was not connected.
     *
     * Soft-deleted rows are excluded: a deleted draft is not something the owner can
     * publish, and offering its id would produce an approval for work that cannot run.
     *
     * @param callable():int $ms
     */
    private function executeListArticles(ToolIntent $intent, callable $ms): ToolResult
    {
        $status = trim((string) ($intent->parameters['status'] ?? 'draft'));
        $limit  = (int) ($intent->parameters['limit'] ?? 20);
        $limit  = max(1, min($limit, 50));

        $q = \Illuminate\Support\Facades\DB::table('articles')
            ->where('workspace_id', $intent->workspaceId)
            ->whereNull('deleted_at');

        // 'any' is the explicit way to ask across statuses; anything else is a filter.
        if ($status !== '' && strtolower($status) !== 'any' && strtolower($status) !== 'all') {
            $q->where('status', $status);
        }

        $total = (clone $q)->count();
        $rows  = $q->orderByDesc('updated_at')->limit($limit)
            ->get(['id', 'title', 'status', 'updated_at', 'word_count', 'featured_image_url'])
            ->map(fn ($r) => [
                'article_id' => (int) $r->id,
                'title'      => (string) $r->title,
                'status'     => (string) $r->status,
                'word_count' => $r->word_count === null ? null : (int) $r->word_count,
                'has_featured_image' => !empty($r->featured_image_url),
                'updated_at' => (string) $r->updated_at,
            ])->all();

        return ToolResult::succeeded($intent->capabilityId, [
            'status_filter' => $status === '' ? 'draft' : $status,
            'matching'      => $total,
            'returned'      => count($rows),
            'articles'      => $rows,
        ], 'articles table', $ms());
    }

    /**
     * What actually ran, and what happened to it.
     *
     * From the P5 matrix: asked *"Did that SERP analysis actually run or not?"* the shadow
     * had no way to consult the execution record, so it emitted `serp_analysis` — an
     * intent to run a NEW one — which was refused for missing parameters, and Sarah then
     * reported her own refusal as if it answered a question about the past. Legacy did
     * answer it, by listing tasks, and then queued a task the owner never asked for.
     *
     * "Did it run?" is a question about history. Answering it by re-running the thing is
     * both wrong and expensive, so this reads the authoritative record instead.
     *
     * `error_text` is included because "it failed" without "why" sends the owner back to
     * ask a second question; it is truncated, not summarised, so nothing is invented.
     *
     * @param callable():int $ms
     */
    private function executeRecentTasks(ToolIntent $intent, callable $ms): ToolResult
    {
        $limit  = max(1, min((int) ($intent->parameters['limit'] ?? 15), 50));
        $action = trim((string) ($intent->parameters['action'] ?? ''));
        $status = trim((string) ($intent->parameters['status'] ?? ''));
        $days   = (int) ($intent->parameters['days'] ?? 30);
        $days   = max(1, min($days, 365));

        $q = \Illuminate\Support\Facades\DB::table('tasks')
            ->where('workspace_id', $intent->workspaceId)
            ->where('created_at', '>=', now()->subDays($days));
        if ($action !== '') $q->where('action', $action);
        if ($status !== '' && strtolower($status) !== 'any') $q->where('status', $status);

        $byStatus = [];
        foreach ((clone $q)->selectRaw('status, COUNT(*) c')->groupBy('status')
            ->orderByDesc('c')->get() as $r) {
            $byStatus[(string) $r->status] = (int) $r->c;
        }
        $byAction = [];
        foreach ((clone $q)->selectRaw('action, COUNT(*) c')->groupBy('action')
            ->orderByDesc('c')->limit(10)->get() as $r) {
            $byAction[(string) $r->action] = (int) $r->c;
        }

        $rows = (clone $q)->orderByDesc('id')->limit($limit)
            ->get(['id', 'action', 'status', 'engine', 'created_at', 'started_at',
                   'completed_at', 'failed_at', 'error_text', 'credit_cost',
                   'requires_approval', 'approval_status'])
            ->map(fn ($t) => [
                'task_id'   => (int) $t->id,
                'action'    => (string) $t->action,
                'status'    => (string) $t->status,
                'engine'    => (string) ($t->engine ?? ''),
                'created_at'   => (string) $t->created_at,
                'started_at'   => $t->started_at,
                'completed_at' => $t->completed_at,
                'failed_at'    => $t->failed_at,
                'error'        => $t->error_text === null ? null
                    : mb_substr((string) $t->error_text, 0, 200),
                'credit_cost'  => (int) ($t->credit_cost ?? 0),
                'requires_approval' => (bool) ($t->requires_approval ?? false),
                'approval_status'   => $t->approval_status,
            ])->all();

        return ToolResult::succeeded($intent->capabilityId, [
            'window_days'   => $days,
            'action_filter' => $action === '' ? 'any' : $action,
            'status_filter' => $status === '' ? 'any' : $status,
            'total'         => array_sum($byStatus),
            'by_status'     => $byStatus,
            'by_action'     => $byAction,
            'tasks'         => $rows,
            'note'          => $rows === []
                ? ($action !== ''
                    ? "No {$action} task has run in this workspace in the last {$days} days."
                    : "No tasks in the last {$days} days.")
                : null,
        ], 'tasks table', $ms());
    }

    /**
     * The owner's standing goals — the thing the whole conversation keeps orbiting.
     *
     * The Chef Red transcript returns to it again and again: "the ranking goal is off track
     * at 0% progress with the 22 August deadline nine days out". Legacy Sarah could say
     * that; Runtime-native Sarah could NOT, because goals appear in neither ExecutiveFacts
     * nor any wired executor, and `list_goals` was advertised with nothing behind it. That
     * is a straight parity gap against the path this one is meant to replace.
     *
     * `workspace_goals` is the live store (ws 2 holds "Rank top 10 for 10 chef NJ
     * keywords", deadline 2026-08-22, status off_track). `seo_goals` exists but is empty
     * everywhere, so it is not read: an empty legacy table is not a second source of truth.
     *
     * Progress is reported EXACTLY as stored. `progress_pct` comes from
     * `current_state_json`; if the platform has not measured it, this returns null rather
     * than a computed stand-in. Days-to-deadline is arithmetic on a stored date, which is
     * the one derivation that cannot say anything the data does not.
     *
     * @param callable():int $ms
     */
    private function executeListGoals(ToolIntent $intent, callable $ms): ToolResult
    {
        $db = \Illuminate\Support\Facades\DB::getSchemaBuilder();
        if (!$db->hasTable('workspace_goals')) {
            return ToolResult::unavailable($intent->capabilityId,
                'This platform has no goals table.');
        }

        $q = \Illuminate\Support\Facades\DB::table('workspace_goals')
            ->where('workspace_id', $intent->workspaceId);
        if ($db->hasColumn('workspace_goals', 'deleted_at')) $q->whereNull('deleted_at');

        $status = trim((string) ($intent->parameters['status'] ?? ''));
        if ($status !== '' && strtolower($status) !== 'any') $q->where('status', $status);

        $today = now()->startOfDay();
        $rows = $q->orderByDesc('priority')->orderBy('target_deadline')->limit(25)->get()
            ->map(function ($g) use ($today) {
                $state  = json_decode((string) ($g->current_state_json ?? ''), true);
                $target = json_decode((string) ($g->target_json ?? ''), true);
                $days = null;
                if (!empty($g->target_deadline)) {
                    $days = (int) $today->diffInDays(
                        \Illuminate\Support\Carbon::parse($g->target_deadline)->startOfDay(), false);
                }
                return [
                    'goal_id'    => (int) $g->id,
                    'title'      => (string) $g->title,
                    'goal_type'  => (string) $g->goal_type,
                    'status'     => (string) $g->status,
                    'priority'   => $g->priority === null ? null : (int) $g->priority,
                    'started_at' => $g->started_at,
                    'target_deadline'   => $g->target_deadline,
                    'days_to_deadline'  => $days,          // negative = overdue
                    'progress_pct'      => is_array($state) ? ($state['progress_pct'] ?? null) : null,
                    'current_state'     => is_array($state) ? $state : null,
                    'target'            => is_array($target) ? $target : null,
                    'last_progress_check_at' => $g->last_progress_check_at,
                ];
            })->all();

        $byStatus = [];
        foreach ($rows as $r) { $byStatus[$r['status']] = ($byStatus[$r['status']] ?? 0) + 1; }

        return ToolResult::succeeded($intent->capabilityId, [
            'total'     => count($rows),
            'by_status' => $byStatus,
            'goals'     => $rows,
            'note'      => $rows === []
                ? 'This workspace has no goals on record.' : null,
        ], 'workspace_goals table', $ms());
    }

    /**
     * One lead in detail. The owner asked "can you look at our leads?" (transcript turn
     * 18); `list_leads` answers the roll-up, and the natural next question is about one
     * of them. It was in the registry, read, available — with no executor.
     *
     * CONTACT DETAILS ARE NOT SENT TO RUNTIME. `executeListLeads` already establishes
     * that policy: it selects `email` and deliberately omits it from the payload. Runtime
     * is an external service, and an owner deciding what to do about a lead needs its
     * state, value and provenance — not the person's email address and phone number. This
     * follows that precedent rather than widening PII exposure; `email_present` reports
     * whether we can contact them without disclosing how.
     *
     * @param callable():int $ms
     */
    private function executeGetLead(ToolIntent $intent, callable $ms): ToolResult
    {
        $id = (int) ($intent->parameters['lead_id'] ?? 0);
        $lead = \Illuminate\Support\Facades\DB::table('leads')
            ->where('workspace_id', $intent->workspaceId)   // tenancy first, always
            ->where('id', $id)->whereNull('deleted_at')->first();

        if ($lead === null) {
            // A lead that is absent, deleted, or belongs to someone else is the same
            // answer from here: this workspace has no such lead. Never disclose that an
            // id exists elsewhere.
            return ToolResult::succeeded($intent->capabilityId, [
                'found' => false, 'lead_id' => $id,
                'note' => "No lead {$id} in this workspace.",
            ], 'leads table', $ms());
        }

        $tags = json_decode((string) ($lead->tags_json ?? ''), true);

        return ToolResult::succeeded($intent->capabilityId, [
            'found'      => true,
            'lead_id'    => (int) $lead->id,
            'name'       => (string) $lead->name,
            'company'    => (string) ($lead->company ?? ''),
            'status'     => (string) $lead->status,
            'source'     => (string) ($lead->source ?? ''),
            'score'      => $lead->score === null ? null : (int) $lead->score,
            'deal_value' => $lead->deal_value === null ? null : (float) $lead->deal_value,
            'city'       => (string) ($lead->city ?? ''),
            'country'    => (string) ($lead->country ?? ''),
            'tags'       => is_array($tags) ? $tags : [],
            'created_at' => (string) $lead->created_at,
            'last_contacted_at' => $lead->last_contacted_at,
            'converted_at'      => $lead->converted_at,
            // Contactability without the contact details.
            'email_present' => $lead->email !== null && $lead->email !== '',
            'phone_present' => $lead->phone !== null && $lead->phone !== '',
            'email_unsubscribed' => (bool) ($lead->email_unsubscribed ?? false),
        ], 'leads table', $ms());
    }

    /**
     * The workspace's campaigns — including, importantly, when there are none.
     *
     * Chef Red asked "since we already launched the summer campaign, how did that
     * perform?" (transcript turn 26). `list_campaigns` was in the registry, marked read
     * and workspace-available, but had no executor, so the gateway answered
     * TOOL_UNAVAILABLE and Sarah said "the campaign capability isn't wired up in this
     * workspace". That is the wrong answer to the wrong question: ws 2 has **zero**
     * campaigns, so the true answer is "you don't have any campaigns", which is a fact,
     * not a missing capability. The owner hears "our tooling is broken" instead of
     * "your premise is wrong" - and the premise being wrong is the thing they needed
     * to know.
     *
     * An empty result is therefore a SUCCESS carrying a count of zero, never a refusal.
     *
     * @param callable():int $ms
     */
    private function executeListCampaigns(ToolIntent $intent, callable $ms): ToolResult
    {
        $db = \Illuminate\Support\Facades\DB::getSchemaBuilder();
        if (!$db->hasTable('campaigns')) {
            return ToolResult::unavailable($intent->capabilityId,
                'This platform has no campaigns table.');
        }

        $limit = (int) ($intent->parameters['limit'] ?? 20);
        $limit = max(1, min($limit, 50));
        $status = trim((string) ($intent->parameters['status'] ?? ''));

        $q = \Illuminate\Support\Facades\DB::table('campaigns')
            ->where('workspace_id', $intent->workspaceId);
        if ($db->hasColumn('campaigns', 'deleted_at')) $q->whereNull('deleted_at');
        if ($status !== '' && strtolower($status) !== 'any') $q->where('status', $status);

        $total = (clone $q)->count();

        $byStatus = [];
        foreach ((clone $q)->selectRaw('status, COUNT(*) c')->groupBy('status')->get() as $r) {
            $byStatus[(string) $r->status] = (int) $r->c;
        }

        $rows = $q->orderByDesc('id')->limit($limit)
            ->get(['id', 'name', 'type', 'status', 'subject', 'scheduled_at', 'sent_at', 'stats_json'])
            ->map(function ($r) {
                $stats = json_decode((string) ($r->stats_json ?? ''), true);
                return [
                    'campaign_id' => (int) $r->id,
                    'name'        => (string) $r->name,
                    'type'        => (string) $r->type,
                    'status'      => (string) $r->status,
                    'subject'     => (string) $r->subject,
                    'scheduled_at' => $r->scheduled_at,
                    'sent_at'      => $r->sent_at,
                    // Only real recorded stats. No derived or estimated performance.
                    'stats'       => is_array($stats) && $stats !== [] ? $stats : null,
                ];
            })->all();

        return ToolResult::succeeded($intent->capabilityId, [
            'status_filter' => $status === '' ? 'any' : $status,
            'total'         => $total,
            'returned'      => count($rows),
            'by_status'     => $byStatus,
            'campaigns'     => $rows,
            'note'          => $total === 0
                ? 'This workspace has no campaigns. Nothing has been launched here.'
                : null,
        ], 'campaigns table', $ms());
    }

    /**
     * Can this workspace send email?
     *
     * TWO LEVELS, never merged. The platform transport can be fully configured while
     * the workspace has no sender identity — which is exactly the ws 2 situation, and
     * exactly why "no email sending service is configured" was the wrong answer.
     */
    private function executeEmailReadiness(ToolIntent $intent, callable $ms): ToolResult
    {
        $mailer   = (string) config('mail.default');
        $platform = $mailer !== '' && $mailer !== 'log' && $mailer !== 'array';
        $token    = (bool) env('POSTMARK_TOKEN');

        $senderTable = null;
        foreach (['workspace_email_settings', 'email_settings', 'email_senders', 'email_identities'] as $t) {
            if (\Illuminate\Support\Facades\DB::getSchemaBuilder()->hasTable($t)) { $senderTable = $t; break; }
        }
        $wsSender = null;
        if ($senderTable !== null) {
            try {
                $wsSender = \Illuminate\Support\Facades\DB::table($senderTable)
                    ->where('workspace_id', $intent->workspaceId)->first();
            } catch (\Throwable $e) { $wsSender = null; }
        }

        return ToolResult::succeeded($intent->capabilityId, [
            'platform_transport'   => $mailer,
            'platform_can_send'    => $platform && $token,
            'platform_from'        => (string) config('mail.from.address'),
            'workspace_sender_configured' => $wsSender !== null,
            'workspace_sender_store'      => $senderTable,
            'campaigns_in_workspace'      => \Illuminate\Support\Facades\DB::table('campaigns')
                ->where('workspace_id', $intent->workspaceId)->count(),
            'summary' => ($platform && $token)
                ? ($wsSender !== null
                    ? 'Platform can send and this workspace has a sender identity.'
                    : 'The platform can send, but this workspace has no sender identity configured yet.')
                : 'No email transport is configured at the platform level.',
        ], 'mail config + ' . ($senderTable ?? 'no workspace sender store exists'), $ms());
    }

    /** CRM read: counts, pipeline and the most recent leads. */
    private function executeListLeads(ToolIntent $intent, callable $ms): ToolResult
    {
        $limit = max(1, min((int) ($intent->parameters['limit'] ?? 10), 50));
        $q = fn () => \Illuminate\Support\Facades\DB::table('leads')
            ->where('workspace_id', $intent->workspaceId)->whereNull('deleted_at');

        $byStatus = [];
        foreach ((clone $q())->selectRaw('status, COUNT(*) c')->groupBy('status')
            ->orderByDesc('c')->get() as $r) {
            $byStatus[(string) $r->status] = (int) $r->c;
        }

        $recent = (clone $q())->orderByDesc('id')->limit($limit)
            ->get(['id', 'name', 'email', 'status', 'source', 'created_at'])
            ->map(fn ($r) => [
                'id' => (int) $r->id, 'name' => (string) $r->name,
                'status' => (string) $r->status, 'source' => (string) $r->source,
                'created_at' => (string) $r->created_at,
            ])->all();

        return ToolResult::succeeded($intent->capabilityId, [
            'total' => array_sum($byStatus),
            'by_status' => $byStatus,
            'recent' => $recent,
        ], 'leads table', $ms());
    }

    /** What work is outstanding, counted by MEANING rather than status string. */
    private function executeGetQueue(ToolIntent $intent, callable $ms): ToolResult
    {
        $wsId = $intent->workspaceId;
        $q = fn () => \Illuminate\Support\Facades\DB::table('tasks')->where('workspace_id', $wsId);

        $byStatus = [];
        foreach ((clone $q())->selectRaw('status, COUNT(*) c')->groupBy('status')->get() as $r) {
            $byStatus[(string) $r->status] = (int) $r->c;
        }

        $awaitingOwner = (clone $q())->where(function ($w) {
            $w->where('status', 'awaiting_approval')
              ->orWhere(function ($x) { $x->where('status', 'pending')->where('requires_approval', 1); });
        })->count();

        $runnable = (clone $q())->where('status', 'pending')
            ->where(function ($w) { $w->where('requires_approval', 0)->orWhereNull('requires_approval'); })
            ->count();

        $recentFailures = (clone $q())->where('status', 'failed')
            ->where('created_at', '>=', now()->subDays(7))
            ->selectRaw('action, COUNT(*) c')->groupBy('action')
            ->orderByDesc('c')->limit(5)->get()
            ->map(fn ($r) => ['action' => (string) $r->action, 'count' => (int) $r->c])->all();

        return ToolResult::succeeded($intent->capabilityId, [
            'by_status' => $byStatus,
            'awaiting_owner_approval' => $awaitingOwner,
            'runnable_without_approval' => $runnable,
            'failed_last_7_days' => (clone $q())->where('status', 'failed')
                ->where('created_at', '>=', now()->subDays(7))->count(),
            'top_recent_failures' => $recentFailures,
        ], 'tasks table', $ms());
    }}
